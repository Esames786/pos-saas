<?php

namespace Tests\MySql\Support;

use App\Models\Tenant\Branch;
use App\Services\Edge\EdgeSupplierFinanceCacheService;
use App\Services\Edge\EdgeSupplierFinanceProjectionService;
use Illuminate\Support\Facades\DB;

/**
 * OFFLINE EDGE — F2 test seed: the Cloud's supplier finance (chart, cash/bank accounts mapped to the chart, suppliers with
 * an authoritative payable, an open Purchase Bill) on the CURRENT tenant connection, and the projection → appliance step.
 */
trait EdgeSupplierFinanceFixture
{
    protected int $supplierAId;      // payable 10,000 — the §18 race supplier
    protected int $supplierBId;      // payable 5,000
    protected int $supplierInactiveId;
    protected int $billAId;          // SUP-A open bill 4,000
    protected int $tillCbId;         // cash, mapped to 1110
    protected int $bankCbId;         // bank, mapped to 1210
    protected int $unmappedCbId;     // active but no chart account → unusable
    protected int $inactiveCbId;

    protected const SF_TABLES = [
        'edge_inbound_supplier_finance_ingestions', 'supplier_payments', 'supplier_ledgers', 'purchase_bills', 'suppliers',
        'cash_bank_account_transactions', 'cash_bank_accounts', 'journal_lines', 'journal_entries', 'accounts',
    ];

    protected const SF_EDGE_TABLES = [
        'edge_local_supplier_finance_effects', 'edge_local_supplier_finance_events', 'edge_supplier_finance_applied_events', 'edge_supplier_finance_ledger_entries',
        'edge_supplier_finance_accounts', 'edge_supplier_finance_cash_bank_accounts', 'edge_supplier_finance_bills', 'edge_supplier_finance_suppliers',
    ];

    protected function seedCloudSupplierFinance(int $branchId): void
    {
        $conn = DB::connection('tenant');
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $acc = fn (string $code) => (int) $conn->table('accounts')->where('code', $code)->value('id');
        $now = now();
        $this->tillCbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'TILL', 'name' => 'Main Till', 'account_type' => 'cash', 'account_id' => $acc('1110'), 'branch_id' => $branchId, 'current_balance' => 50000, 'is_default' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->bankCbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'BANK', 'name' => 'Main Bank', 'account_type' => 'bank', 'account_id' => $acc('1210'), 'bank_name' => 'HBL', 'current_balance' => 200000, 'is_default' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->unmappedCbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'ORPHAN', 'name' => 'Unmapped Cash', 'account_type' => 'cash', 'account_id' => null, 'current_balance' => 0, 'is_default' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->inactiveCbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'OLDBANK', 'name' => 'Closed Bank', 'account_type' => 'bank', 'account_id' => $acc('1210'), 'current_balance' => 0, 'is_default' => 0, 'is_active' => 0, 'created_at' => $now, 'updated_at' => $now]);

        $supplier = function (string $code, string $name, float $balance, string $status = 'active') use ($conn, $now): int {
            $id = $conn->table('suppliers')->insertGetId(['code' => $code, 'name' => $name, 'opening_balance' => $balance, 'current_balance' => $balance, 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
            if ($balance > 0) {
                $conn->table('supplier_ledgers')->insert(['supplier_id' => $id, 'entry_type' => 'opening_balance', 'direction' => 'debit', 'amount' => $balance, 'balance_after' => $balance, 'reference_type' => 'opening_balance', 'reference_id' => $id, 'reference_no' => 'OB-' . $code, 'notes' => 'Opening balance', 'created_at' => $now->copy()->subDay(), 'updated_at' => $now->copy()->subDay()]);
            }

            return $id;
        };
        $this->supplierAId = $supplier('SUP-A', 'Alpha Foods', 10000);
        $this->supplierBId = $supplier('SUP-B', 'Beta Packaging', 5000);
        $this->supplierInactiveId = $supplier('SUP-X', 'Closed Vendor', 800, 'inactive');
        $this->billAId = $conn->table('purchase_bills')->insertGetId(['bill_no' => 'PB-A-1', 'supplier_invoice_no' => 'INV-77', 'supplier_id' => $this->supplierAId, 'branch_id' => $branchId, 'bill_date' => $now->copy()->subDays(3)->toDateString(), 'due_date' => $now->copy()->addDays(10)->toDateString(), 'status' => 'posted', 'subtotal' => 4000, 'grand_total' => 4000, 'amount_paid' => 0, 'balance_due' => 4000, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** Single-database tests: build the Cloud projection from the same DB and apply it as the appliance's FRESH cache. */
    protected function projectSupplierFinanceToAppliance(int $branchId): array
    {
        $package = app(EdgeSupplierFinanceProjectionService::class)->package(Branch::on('tenant')->findOrFail($branchId));
        app(EdgeSupplierFinanceCacheService::class)->apply($package);
        DB::connection('tenant')->table('edge_local_meta')->update([
            'standby_supplier_finance_watermark_seen' => $package['watermark'],
            'standby_supplier_finance_as_of_seen' => now(),
        ]);
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);

        return $package;
    }

    protected function accountId(string $code): int
    {
        return (int) DB::connection('tenant')->table('accounts')->where('code', $code)->value('id');
    }
}
