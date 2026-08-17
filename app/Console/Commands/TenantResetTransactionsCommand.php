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
 * users/roles/permissions (including Owner email/password), branches/terminals/floors/tables/waiters, printers + routing +
 * layouts + paired agents, payment methods, delivery channels/riders, chart of accounts,
 * departments + mappings, promotions, report schedules, settings.
 *
 * WIPES (truncate, ids restart at 1): sales + payments + returns + KOTs + cancellations,
 * shifts + daily closings, GL journals, cash/bank + customer/supplier ledgers + payments,
 * expenses, opening balances, purchasing (PO/GRN/bills/returns), all stock movement +
 * balances + counts + transfers + batches, kitchen/manufacturing transactions, department
 * stock/handovers, print jobs, schedule runs. Restaurant tables reset to 'available'.
 *
 * KASHIF-CATERING-UAT-RESET-1 also wipes catering DOCUMENTS — bookings, estimates,
 * advances, refunds, final invoices, production releases, material/store issues —
 * and keeps catering CONFIGURATION: profiles, cost blocks, the Material Rate Book,
 * printer mappings, settings. Until this was added the command was not merely
 * incomplete for a catering tenant but unsafe: it truncated the journals and stock
 * ledgers those documents referenced and left the documents behind.
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
        'sales_return_lines', 'sales_returns', 'sales_order_rider_assignments', 'sales_order_lines', 'sales_orders',
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
        // KASHIF-CATERING-UAT-RESET-1: catering business documents.
        //
        // These were unclassified until now, which made this command UNSAFE for a
        // catering tenant rather than merely incomplete: it truncates journals and
        // stock ledgers, so a reset would have left every booking, advance, refund
        // and material issue in place pointing at accounting and stock rows that
        // no longer existed. The command warned about the unclassified tables and
        // carried on.
        //
        // Ordered children-first like everything above. Catering CONFIG — profiles,
        // cost blocks, printer mappings, settings, the Material Rate Book — is
        // master data and is kept, exactly as recipes and products are.
        'catering_email_logs', 'catering_event_reminders',
        'catering_material_issue_events', 'catering_material_issue_lines', 'catering_material_issues',
        'catering_production_release_lines', 'catering_production_releases',
        'catering_refunds', 'catering_final_invoices', 'catering_advances',
        'catering_cost_snapshots', 'catering_estimate_lines', 'catering_estimates',
        'catering_events',
    ];

    /** Master-data sanity tables: their counts must be IDENTICAL before and after. */
    private const KEEP_SENTINELS = [
        'products', 'categories', 'customers', 'customer_addresses', 'users', 'suppliers',
        // printing setup must survive a reset untouched: devices, kitchen routing, per-terminal
        // bindings/auto-print and the receipt/KOT layouts.
        'printers', 'category_printer_mappings', 'terminal_printer_settings', 'receipt_layout_settings',
        // operational configuration
        'accounts', 'payment_methods', 'terminals', 'branches', 'report_schedules',
        'void_reasons', 'manager_pins', 'delivery_channels', 'delivery_riders',
        'restaurant_tables', 'restaurant_floors', 'restaurant_waiters', 'roles', 'permissions',
    ];

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

        // COVERAGE AUDIT: every tenant table must be a deliberate decision — wiped, or knowingly
        // kept. A table added later (a new document, a new setting) would otherwise drift out of
        // this command silently and either survive a reset it should not, or be missed here.
        $unclassified = $this->unclassifiedTables();
        if ($unclassified !== []) {
            $this->warn('Tables not classified as wipe/keep (verify before trusting this reset): '
                .implode(', ', $unclassified));
        }

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

        $this->info("reset '{$code}': ".count($wiped).' transactional tables wiped ('.array_sum($wiped).' rows): '
            .collect($wiped)->map(fn ($n, $t) => "{$t}={$n}")->implode(', '));
        $this->info('master data kept: '.collect($before)->map(fn ($n, $t) => "{$t}={$n}")->implode(', '));

        if ($failures) {
            $this->error('INTEGRITY FAILURES: '.implode(' | ', $failures));

            return self::FAILURE;
        }
        $this->info('integrity: all master-data counts unchanged; all transaction tables empty.');

        return self::SUCCESS;
    }

    /**
     * Master/config tables that are deliberately KEPT but are not worth asserting a count on
     * (the sentinels above are the ones we prove). Anything outside these three lists is
     * reported so a newly added table can never silently escape this command's contract.
     */
    private const KNOWN_KEPT = [
        'migrations', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens', 'languages',
        'currencies', 'currency_denominations', 'units', 'unit_conversions',
        'product_variants', 'product_barcodes', 'product_branch_prices', 'product_translations',
        'product_variant_translations', 'category_translations', 'products_translations',
        'modifier_groups', 'modifiers', 'product_modifier_group', 'combos', 'combo_components',
        'recipes', 'recipe_ingredients', 'promotions', 'promotion_targets',
        'departments', 'department_category_maps', 'department_product_overrides',
        'service_charge_settings', 'manufacturing_posting_settings',
        'manufacturing_boms', 'manufacturing_bom_lines', 'manufacturing_customers',
        'expense_categories', 'cash_bank_accounts', 'branch_user', 'terminal_user',
        'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'user_printer_settings', 'print_agents', 'customer_addresses', 'report_schedules',
        'edge_local_meta', 'edge_local_user_credentials',
        // Catering CONFIGURATION, kept for the same reason recipes are: it
        // describes how the business quotes and costs, not what it has sold.
        // The Material Rate Book in particular is a caterer's price list — losing
        // it to a transaction reset would be losing master data.
        'catering_product_profiles', 'catering_product_cost_blocks',
        'catering_material_rates', 'catering_printer_mappings', 'catering_settings',
    ];

    /** Tenant tables this command has no opinion about — surfaced as a warning. */
    private function unclassifiedTables(): array
    {
        $all = array_map(
            fn ($row) => array_values((array) $row)[0],
            DB::connection('tenant')->select('SHOW TABLES')
        );
        $classified = array_merge(self::WIPE_TABLES, self::KEEP_SENTINELS, self::KNOWN_KEPT);

        return array_values(array_diff($all, $classified));
    }
}
