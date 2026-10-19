<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurchaseBill;
use App\Services\Finance\JournalPostingService;
use App\Services\Finance\JournalService;
use App\Services\Finance\NonStockCostAccounts;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PNL-COST-BY-CATEGORY-1 (R5) — move OLD bills' purchase-only cost onto today's category accounts.
 *
 * Mapping a category (say Soft Drinks → 5110 Beverages) changes only bills posted from then on. For a
 * bill already posted, this works out what postPurchaseBill() would post today and, where the bill's
 * 5… lines differ, posts ONE reclass journal (Dr the new account / Cr the old) dated the bill date.
 * The original journal is never edited or deleted. Each bill is reclassed at most once
 * (source purchase_bill_recost / bill id); a bill whose journal was reversed is left alone.
 *
 * Default is a DRY RUN that writes nothing and prints every bill's change and the totals.
 * --yes posts. Run as www-data on prod: `sudo -u www-data php artisan finance:recost-nonstock-bills …`.
 */
class FinanceRecostNonStockBillsCommand extends Command
{
    protected $signature = 'finance:recost-nonstock-bills {tenant_code} {--from= : bill date from (Y-m-d)} {--to= : bill date to (Y-m-d)} {--yes : post the reclass journals}';

    protected $description = 'Reclass posted bills\' purchase-only cost to their category cost accounts. Dry run unless --yes.';

    public function handle(TenancyManager $tenancy, JournalService $journal): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        $tenancy->activate($tenant);

        $apply = (bool) $this->option('yes');
        $this->line($apply ? '<comment>APPLYING</comment>' : '<info>DRY RUN</info> — nothing is written; pass --yes to post.');

        $c = DB::connection('tenant');
        $codes = $c->table('accounts')->pluck('code', 'id')->all();
        $costAccounts = app(NonStockCostAccounts::class);
        $is5 = fn ($accountId) => str_starts_with((string) ($codes[$accountId] ?? ''), '5');

        $bills = PurchaseBill::query()
            ->when($this->option('from'), fn ($q, $d) => $q->whereDate('bill_date', '>=', $d))
            ->when($this->option('to'), fn ($q, $d) => $q->whereDate('bill_date', '<=', $d))
            ->with('goodsReceipt.lines')->orderBy('bill_date')->orderBy('id')->get();

        $totals = [];
        $changed = 0;
        foreach ($bills as $bill) {
            $entry = JournalEntry::where('source_type', 'purchase_bill')->where('source_id', $bill->id)
                ->where('is_reversal', false)->first();
            if (! $entry || JournalEntry::where('reversed_entry_id', $entry->id)->exists()) {
                continue;
            }
            if (JournalEntry::where('source_type', 'purchase_bill_recost')->where('source_id', $bill->id)->exists()) {
                $this->line("  {$bill->bill_no}: already reclassed — skipped");

                continue;
            }

            // What the bill's journal holds on 5… accounts today.
            $existing = [];
            foreach ($entry->lines()->get(['account_id', 'debit', 'credit']) as $l) {
                if ($is5((int) $l->account_id)) {
                    $existing[(int) $l->account_id] = round(($existing[(int) $l->account_id] ?? 0) + (float) $l->debit - (float) $l->credit, 4);
                }
            }

            // What postPurchaseBill() would post today — the same two numbers it uses.
            $grnLines = $bill->goodsReceipt?->lines ?? collect();
            $rows = $grnLines->map(fn ($l) => [
                'id'      => $l->id,
                'value'   => (float) $l->quantity_received * (float) $l->unit_cost,
                'stock'   => $l->affects_stock !== false,
                'account' => $l->affects_stock !== false ? null : $costAccounts->forProduct($l->product_id ? (int) $l->product_id : null),
            ])->values()->all();
            $toCost = JournalPostingService::nonStockShare($rows, round((float) $bill->grand_total, 4));
            $wanted = NonStockCostAccounts::split($rows, $toCost);
            if (isset($wanted[0])) {
                $this->warn("  {$bill->bill_no}: this chart has no 5100 account — skipped");

                continue;
            }

            $diff = [];
            foreach (array_unique(array_merge(array_keys($existing), array_keys($wanted))) as $accountId) {
                $d = round(($wanted[$accountId] ?? 0) - ($existing[$accountId] ?? 0), 4);
                if (abs($d) >= 0.005) {
                    $diff[$accountId] = $d;
                }
            }
            if (! $diff || abs(round(array_sum($diff), 4)) >= 0.005) {
                if ($diff) {
                    $this->warn("  {$bill->bill_no}: the 5… total itself differs from today's split — left for a person to look at");
                }

                continue;
            }

            $changed++;
            $this->line('  ' . $bill->bill_no . ' (' . $bill->bill_date?->toDateString() . '): '
                . collect($diff)->map(fn ($d, $id) => ($d > 0 ? 'Dr ' : 'Cr ') . $codes[$id] . ' ' . number_format(abs($d), 2))->implode(' · '));
            foreach ($diff as $id => $d) {
                $totals[$codes[$id]] = round(($totals[$codes[$id]] ?? 0) + $d, 4);
            }

            if ($apply) {
                $c->transaction(function () use ($journal, $bill, $diff, $rows) {
                    $journal->post(
                        'purchase_bill_recost',
                        $bill->id,
                        $bill->bill_no,
                        'Reclass purchase-only cost to category accounts — ' . $bill->bill_no,
                        $bill->bill_date?->toDateString() ?? now()->toDateString(),
                        collect($diff)->map(fn ($d, $id) => [
                            'account_id' => $id, 'branch_id' => $bill->branch_id,
                            'description' => 'Recost, bought without stock ' . $bill->bill_no,
                            'debit' => $d > 0 ? $d : 0, 'credit' => $d < 0 ? -$d : 0,
                        ])->values()->all(),
                        null
                    );
                    // The bill's lines now sit where today's mapping put them — a later return follows.
                    foreach ($rows as $r) {
                        if (! $r['stock'] && $r['account']) {
                            GoodsReceiptLine::whereKey($r['id'])->update(['cost_account_id' => $r['account']]);
                        }
                    }
                });
            }
        }

        $this->newLine();
        $this->line("Bills to reclass: {$changed}");
        foreach ($totals as $code => $t) {
            $this->line(sprintf('  %s  %s %s', $code, $t > 0 ? 'Dr' : 'Cr', number_format(abs($t), 2)));
        }
        $tb = (float) $c->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')->selectRaw('ROUND(COALESCE(SUM(l.debit) - SUM(l.credit), 0), 2) v')->value('v');
        $this->line('tb_diff: ' . number_format($tb, 2));

        return self::SUCCESS;
    }
}
