<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchasingSetting;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierCreditAllocation;
use App\Models\Tenant\SupplierPayment;
use App\Services\Purchasing\SupplierRunningAccountService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SUPPLIER-RUNNING-ACCOUNT-1 — turn "pay suppliers on account" on or off for one tenant.
 *
 * Turning it ON is one transaction, because the switch alone would be wrong: payments made bill by
 * bill before today have no allocation rows, and the engine would take them for unused credit and
 * spend them again on the next bill. So --enable first records them (each up to what its bill
 * owed; anything above becomes credit), then lets general payments and returns settle the oldest
 * open bills, then switches on.
 *
 * Writes ONLY allocation rows, bills' amount_paid / balance_due / status, and the switch. Journals,
 * supplier ledgers and cash/bank are checked before and after and must not move.
 *
 * Default DRY RUN: the same work runs and is rolled back. --yes commits it.
 */
class FinanceSupplierRunningAccountCommand extends Command
{
    protected $signature = 'finance:supplier-running-account {tenant_code} {--enable} {--disable} {--yes}';

    private const DRY_RUN = "__supplier_running_account_dry_run__";

    protected $description = 'Let suppliers be paid on account (beyond what is owed), settling bills oldest-first. Dry run unless --yes.';

    public function handle(TenancyManager $tenancy, SupplierRunningAccountService $running): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        $tenancy->activate($tenant);

        $on = PurchasingSetting::supplierRunningAccount();
        $this->line('Supplier running account is <comment>' . ($on ? 'ON' : 'OFF') . '</comment> for ' . $tenant->tenant_code . '.');

        if ($this->option('enable') === $this->option('disable')) {
            $this->line('Pass --enable or --disable (dry run), and --yes to write.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('yes');
        $this->line($apply ? '<comment>APPLYING</comment>' : '<info>DRY RUN</info> — nothing is written; pass --yes to write.');

        if ($this->option('disable')) {
            $advances = Supplier::where('current_balance', '<', 0)->count();
            $this->line("Turning OFF. Allocations stay; {$advances} supplier(s) now in advance stay in advance, and paying beyond the balance is refused again.");
            if ($apply) {
                PurchasingSetting::tenantDefault()->update([
                    'supplier_running_account' => false, 'supplier_running_account_changed_at' => now(),
                ]);
                $this->info('Done — OFF.');
            }

            return self::SUCCESS;
        }

        if ($on) {
            $this->info('Already ON — nothing to do.');

            return self::SUCCESS;
        }

        $conn = DB::connection('tenant');
        $books = fn () => [
            'journal_lines'   => $conn->table('journal_lines')->selectRaw('COUNT(*) n, ROUND(SUM(debit),4) dr, ROUND(SUM(credit),4) cr')->first(),
            'supplier_ledger' => $conn->table('supplier_ledgers')->selectRaw('COUNT(*) n, ROUND(SUM(amount),4) amt')->first(),
            'suppliers'       => $conn->table('suppliers')->selectRaw('ROUND(SUM(current_balance),4) bal')->first(),
            'cash_bank'       => $conn->table('cash_bank_account_transactions')->selectRaw('COUNT(*) n')->first(),
        ];
        $before = json_encode($books());
        $billsBefore = PurchaseBill::query()->pluck('balance_due', 'id')->map(fn ($v) => (float) $v)->all();

        try {
            $conn->transaction(function () use ($running, $books, $before, $billsBefore, $apply) {
                // 1) Payments made against a bill before today: record them as that bill's allocations,
                //    up to what the bill owed. A payment above its bill keeps the rest as credit.
                $recorded = 0;
                foreach (SupplierPayment::whereNotNull('purchase_bill_id')->orderBy('payment_date')->orderBy('id')->get() as $payment) {
                    $bill = PurchaseBill::find($payment->purchase_bill_id);
                    if (! $bill) {
                        continue;
                    }
                    $room = round((float) $bill->grand_total - (float) SupplierCreditAllocation::where('purchase_bill_id', $bill->id)->sum('amount'), 4);
                    $take = round(min((float) $payment->amount, max(0.0, $room)), 4);
                    if ($take > 0) {
                        SupplierCreditAllocation::create([
                            'supplier_id' => $payment->supplier_id, 'source_type' => SupplierCreditAllocation::SOURCE_PAYMENT,
                            'source_id' => $payment->id, 'purchase_bill_id' => $bill->id, 'amount' => $take,
                        ]);
                        $recorded++;
                    }
                }
                foreach (PurchaseBill::all() as $bill) {
                    $running->recomputeBill($bill);
                }

                // 2) Everything still unallocated — general payments, returns, the part of a payment above
                //    its bill — settles the supplier's oldest open bills; what is left is the advance.
                $supplierIds = SupplierPayment::query()->distinct()->pluck('supplier_id')
                    ->merge(DB::connection('tenant')->table('purchase_returns')->where('status', 'posted')->distinct()->pluck('supplier_id'))
                    ->unique()->values();
                foreach ($supplierIds as $supplierId) {
                    foreach ($running->unallocatedCredits((int) $supplierId) as $credit) {
                        $running->settleCredit((int) $supplierId, $credit['type'], $credit['id'], $credit['remaining']);
                    }
                }

                PurchasingSetting::tenantDefault()->update([
                    'supplier_running_account' => true, 'supplier_running_account_changed_at' => now(),
                ]);

                if (json_encode($books()) !== $before) {
                    throw new RuntimeException('Journals, supplier ledgers or cash/bank changed — rolled back, nothing written.');
                }

                $this->printPlan($billsBefore, $running, $recorded);

                if (! $apply) {
                    throw new RuntimeException(self::DRY_RUN);
                }
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== self::DRY_RUN) {
                throw $e;
            }
            $this->info('Dry run — rolled back. Run again with --yes to write.');

            return self::SUCCESS;
        }

        $this->info('Done — ON. Journals, supplier ledgers and cash/bank unchanged.');

        return self::SUCCESS;
    }

    private function printPlan(array $billsBefore, SupplierRunningAccountService $running, int $recorded): void
    {
        $this->line("Bill-wise payments recorded as allocations: {$recorded}");
        $rows = [];
        foreach (Supplier::orderBy('name')->get() as $supplier) {
            $bills = PurchaseBill::where('supplier_id', $supplier->id)->get(['id', 'balance_due']);
            $dueBefore = round(array_sum(array_map(fn ($id) => $billsBefore[$id] ?? 0.0, $bills->pluck('id')->all())), 2);
            $dueAfter = round((float) $bills->sum('balance_due'), 2);
            $credit = round(array_sum(array_column($running->unallocatedCredits($supplier->id), 'remaining')), 2);
            $ledger = round((float) $supplier->current_balance, 2);
            if ($dueBefore == 0.0 && $dueAfter == 0.0 && $credit == 0.0 && $ledger == 0.0) {
                continue;
            }
            // Bills minus unused credit must equal the ledger; an opening or a manual journal line has no bill.
            $matches = abs(($dueAfter - $credit) - $ledger) < 0.01;
            $rows[] = [$supplier->name, number_format($ledger, 2), number_format($dueBefore, 2), number_format($dueAfter, 2),
                number_format($credit, 2), $matches ? 'yes' : 'NO — opening / journal entries'];
        }
        $this->table(['Supplier', 'Ledger', 'Bills due before', 'Bills due after', 'Advance (unused credit)', 'Bills − advance = ledger'], $rows);
    }
}
