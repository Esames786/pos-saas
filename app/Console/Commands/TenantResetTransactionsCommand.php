<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT-RESET-1 (Khatri #5, 2026-08-10): wipe a tenant's TRANSACTIONS while keeping every
 * piece of master data — the "demo today, start real business tomorrow" reset.
 *
 * KEEPS: products/categories/combos/modifiers/recipes, customers + address book, suppliers,
 * users/roles/permissions, branches/terminals/floors/tables/waiters, printers + routing +
 * layouts + paired agents, payment methods, delivery channels/riders, chart of accounts,
 * departments + mappings, promotions, report schedules, settings.
 *
 * WIPES (truncate, ids restart at 1): sales + payments + returns + KOTs + cancellations,
 * shifts + daily closings, GL journals, cash/bank + customer/supplier ledgers + payments,
 * expenses, opening balances, purchasing (PO/GRN/bills/returns), all stock movement +
 * balances + counts + transfers + batches, kitchen/manufacturing transactions, department
 * stock/handovers, print jobs, schedule runs. Restaurant tables reset to 'available'.
 *
 * Triple-guarded: --yes, --confirm=<tenant_code> must match, refuses on a Branch Server.
 * ALWAYS take a DB backup before running this on a real tenant.
 */
class TenantResetTransactionsCommand extends Command
{
    protected $signature = 'tenant:reset-transactions {tenant_code} {--yes} {--confirm=}';

    protected $description = 'Wipe a tenant\'s transactional data (sales/finance/stock/shifts) keeping ALL master data. Guarded + destructive.';

    /** Transaction tables in FK-safe wipe order (children first; FK checks are off anyway). */
    private const WIPE_TABLES = [
        // sales + printing
        'sale_payments', 'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches',
        'sales_return_lines', 'sales_returns', 'sales_order_lines', 'sales_orders',
        'sales_ledgers', 'manager_approvals', 'print_jobs', 'restaurant_table_sessions',
        // shifts + closings
        'cash_count_lines', 'daily_closings', 'shifts',
        // finance transactions
        'journal_lines', 'journal_entries', 'cash_bank_account_transactions',
        'customer_ledgers', 'customer_payments', 'supplier_ledgers', 'supplier_payments',
        'expense_voucher_lines', 'expense_vouchers', 'opening_balance_lines', 'opening_balance_batches',
        'department_handovers',
        // purchasing
        'purchase_bill_lines', 'purchase_bills', 'goods_receipt_lines', 'goods_receipts',
        'purchase_return_lines', 'purchase_returns', 'purchase_order_lines', 'purchase_orders',
        // stock transactions
        'stock_ledgers', 'stock_balances', 'inventory_batches',
        'stock_adjustment_lines', 'stock_adjustments', 'stock_count_lines', 'stock_count_sessions',
        'stock_transfer_lines', 'stock_transfers', 'recipe_consumptions',
        'kitchen_production_ingredients', 'kitchen_productions', 'kitchen_wastages',
        // department stock
        'department_stock_ledgers', 'department_stock_balances',
        'department_stock_transfer_lines', 'department_stock_transfers',
        'department_count_lines', 'department_count_sessions', 'department_count_adjustments',
        'department_consumption_exceptions',
        // manufacturing transactions
        'manufacturing_consumption_lines', 'manufacturing_consumption_records',
        'manufacturing_scrap_lines', 'manufacturing_scrap_records',
        'manufacturing_rejection_lines', 'manufacturing_rejection_records',
        'finished_good_receipt_lines', 'finished_good_receipts',
        'material_requisition_lines', 'material_requisitions', 'wip_job_lines', 'wip_jobs',
        'production_orders',
        // report runs (schedule CONFIG is kept)
        'report_schedule_runs',
    ];

    /** Master-data sanity tables: their counts must be IDENTICAL before and after. */
    private const KEEP_SENTINELS = ['products', 'categories', 'customers', 'customer_addresses', 'users', 'printers', 'category_printer_mappings', 'accounts', 'payment_methods', 'terminals', 'branches', 'suppliers', 'report_schedules'];

    public function handle(TenancyManager $tenancy): int
    {
        if (\App\Support\EdgeRuntime::isBranchServer()) {
            $this->error('Refusing: transactional reset never runs on a Branch Server.');

            return self::FAILURE;
        }

        $code = (string) $this->argument('tenant_code');
        if (! $this->option('yes') || $this->option('confirm') !== $code) {
            $this->error("Refusing: pass --yes and --confirm={$code} (typed confirmation) to reset '{$code}'. TAKE A BACKUP FIRST.");

            return self::FAILURE;
        }

        $tenant = \App\Models\Master\Tenant::where('tenant_code', $code)->first();
        if (! $tenant) {
            $this->error("Tenant '{$code}' not found.");

            return self::FAILURE;
        }

        $tenancy->activate($tenant);

        $before = [];
        foreach (self::KEEP_SENTINELS as $table) {
            if (Schema::connection('tenant')->hasTable($table)) {
                $before[$table] = DB::connection('tenant')->table($table)->count();
            }
        }

        $wiped = [];
        DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::WIPE_TABLES as $table) {
                if (! Schema::connection('tenant')->hasTable($table)) {
                    continue;
                }
                $count = DB::connection('tenant')->table($table)->count();
                DB::connection('tenant')->statement("TRUNCATE TABLE `{$table}`");
                if ($count > 0) {
                    $wiped[$table] = $count;
                }
            }
        } finally {
            DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // operational state derived from transactions
        DB::connection('tenant')->table('restaurant_tables')->update(['status' => 'available']);

        // verify: every sentinel unchanged, every wiped table empty.
        $failures = [];
        foreach ($before as $table => $count) {
            $after = DB::connection('tenant')->table($table)->count();
            if ($after !== $count) {
                $failures[] = "{$table}: {$count} -> {$after}";
            }
        }
        foreach (array_keys($wiped) as $table) {
            if (DB::connection('tenant')->table($table)->count() !== 0) {
                $failures[] = "{$table}: not empty after truncate";
            }
        }

        $this->info("reset '{$code}': " . count($wiped) . ' transactional tables wiped (' . array_sum($wiped) . ' rows): '
            . collect($wiped)->map(fn ($n, $t) => "{$t}={$n}")->implode(', '));
        $this->info('master data kept: ' . collect($before)->map(fn ($n, $t) => "{$t}={$n}")->implode(', '));

        if ($failures) {
            $this->error('INTEGRITY FAILURES: ' . implode(' | ', $failures));

            return self::FAILURE;
        }
        $this->info('integrity: all master-data counts unchanged; all transaction tables empty.');

        return self::SUCCESS;
    }
}
