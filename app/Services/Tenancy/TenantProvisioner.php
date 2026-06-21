<?php

namespace App\Services\Tenancy;

use App\Models\Master\Plan;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Master\TenantDatabase;
use App\Models\Master\TenantDomain;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Currency;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

class TenantProvisioner
{
    public function __construct(
        protected TenancyManager $tenancyManager
    ) {}

    public function provisionTenant(Tenant $tenant, string $ownerPassword): Tenant
    {
        $dbName = $this->makeDatabaseName($tenant);

        TenantDatabase::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'db_connection' => 'tenant',
                'db_host'       => env('TENANT_DB_HOST', '127.0.0.1'),
                'db_port'       => env('TENANT_DB_PORT', 3306),
                'db_database'   => $dbName,
                'db_username'   => env('TENANT_DB_USERNAME', 'root'),
                'db_password'   => env('TENANT_DB_PASSWORD'),
                'migration_status' => 'pending',
            ]
        );

        DB::connection('master')->statement(
            "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );

        $tenant->refresh()->load('database');

        try {
            $tenant->database->update(['migration_status' => 'running']);

            $this->tenancyManager->activate($tenant);

            Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path'     => 'database/migrations/tenant',
                '--force'    => true,
            ]);

            $this->seedTenantBaseData($tenant, $ownerPassword);

            $tenant->database->update(['migration_status' => 'completed']);

            $tenant->update([
                'status'       => 'active',
                'activated_at' => $tenant->activated_at ?: now(),
            ]);

            TenantDomain::where('tenant_id', $tenant->id)
                ->where('is_primary', true)
                ->update(['status' => 'active']);

            $this->tenancyManager->deactivate();

            return $tenant->fresh(['domains', 'database', 'subscription']);
        } catch (Throwable $e) {
            if ($tenant->database) {
                $tenant->database->update(['migration_status' => 'failed']);
            }

            $this->tenancyManager->deactivate();

            throw $e;
        }
    }

    public function provisionDemoTenant(): Tenant
    {
        $tenant = Tenant::firstOrCreate(
            ['tenant_code' => 'demo'],
            [
                'business_name' => 'Demo Store',
                'owner_name'    => 'Demo Owner',
                'owner_email'   => 'owner@demo.com',
                'currency_code' => 'PKR',
                'status'        => 'active',
                'trial_ends_at' => now()->addMonths(2),
                'activated_at'  => now(),
            ]
        );

        TenantDomain::updateOrCreate(
            ['domain' => 'demo.' . config('tenancy.tenant_base_domain')],
            [
                'tenant_id'  => $tenant->id,
                'is_primary' => true,
                'status'     => 'active',
            ]
        );

        $plan = Plan::where('code', 'standard')->first();

        if ($plan) {
            Subscription::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'plan_id'      => $plan->id,
                    'status'       => 'trial',
                    'trial_ends_at' => now()->addMonths(2),
                ]
            );
        }

        // Always drop and recreate the demo DB so provision-demo is idempotent
        $dbName = $this->makeDatabaseName($tenant);
        DB::connection('master')->statement("DROP DATABASE IF EXISTS `{$dbName}`");

        return $this->provisionTenant($tenant, 'password');
    }

    protected function seedTenantBaseData(Tenant $tenant, string $ownerPassword): void
    {
        DB::connection('tenant')->table('languages')->updateOrInsert(
            ['code' => 'en'],
            ['name' => 'English', 'is_rtl' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        DB::connection('tenant')->table('languages')->updateOrInsert(
            ['code' => 'ar'],
            ['name' => 'Arabic', 'is_rtl' => true, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]
        );

        $currency = Currency::updateOrCreate(
            ['code' => $tenant->currency_code ?: 'PKR'],
            [
                'name'           => $tenant->currency_code === 'PKR' ? 'Pakistani Rupee' : $tenant->currency_code,
                'symbol'         => $tenant->currency_code === 'PKR' ? 'Rs' : $tenant->currency_code,
                'decimal_places' => 2,
                'is_default'     => true,
                'is_active'      => true,
            ]
        );

        foreach ([5000, 1000, 500, 100, 50, 20, 10, 5, 2, 1] as $value) {
            $currency->denominations()->updateOrCreate(
                ['denomination_value' => $value],
                [
                    'denomination_type' => $value >= 10 ? 'note' : 'coin',
                    'is_active'         => true,
                ]
            );
        }

        $branch = Branch::firstOrCreate(
            ['name' => 'Main Branch'],
            [
                'code'          => 'MAIN',
                'business_type' => 'hybrid',
                'address'       => 'Main Branch Address',
                'phone'         => null,
                'email'         => null,
                'timezone'      => 'Asia/Karachi',
                'status'        => 'active',
            ]
        );

        $owner = User::updateOrCreate(
            ['email' => $tenant->owner_email ?: 'owner@' . $tenant->tenant_code . '.local'],
            [
                'name'     => $tenant->owner_name ?: 'Owner',
                'password' => Hash::make($ownerPassword),
                'status'   => 'active',
                'locale'   => 'en',
            ]
        );

        $owner->branches()->syncWithoutDetaching([$branch->id]);

        $floor = \App\Models\Tenant\RestaurantFloor::firstOrCreate(
            ['branch_id' => $branch->id, 'name' => 'Ground Floor'],
            ['sort_order' => 1, 'status' => 'active']
        );

        foreach (['T1', 'T2', 'T3', 'T4', 'T5', 'T6'] as $tableNo) {
            \App\Models\Tenant\RestaurantTable::firstOrCreate(
                ['branch_id' => $branch->id, 'restaurant_floor_id' => $floor->id, 'table_no' => $tableNo],
                ['capacity' => 4, 'status' => 'available', 'sort_order' => 0]
            );
        }

        \App\Models\Tenant\RestaurantWaiter::firstOrCreate(
            ['branch_id' => $branch->id, 'code' => 'W-001'],
            ['name' => 'Default Waiter', 'status' => 'active']
        );

        $tenantPermissions = [
            'tenant.dashboard',

            'tenant.users.index',
            'tenant.users.create',
            'tenant.users.store',
            'tenant.users.show',
            'tenant.users.edit',
            'tenant.users.update',
            'tenant.users.reset-password',
            'tenant.users.activate',
            'tenant.users.destroy',

            'tenant.roles.index',
            'tenant.roles.create',
            'tenant.roles.store',
            'tenant.roles.edit',
            'tenant.roles.update',
            'tenant.roles.destroy',
            'tenant.roles.permissions.edit',
            'tenant.roles.permissions.update',
            'tenant.permissions.sync',

            'tenant.branches.index',
            'tenant.branches.create',
            'tenant.branches.store',
            'tenant.branches.edit',
            'tenant.branches.update',
            'tenant.branches.destroy',

            'tenant.terminals.index',
            'tenant.terminals.create',
            'tenant.terminals.store',
            'tenant.terminals.edit',
            'tenant.terminals.update',
            'tenant.terminals.destroy',

            'tenant.currencies.index',
            'tenant.currencies.store',
            'tenant.currencies.default',
            'tenant.currency-denominations.store',
            'tenant.currency-denominations.destroy',

            'tenant.shifts.index',
            'tenant.shifts.create',
            'tenant.shifts.store',
            'tenant.shifts.show',
            'tenant.shifts.close-form',
            'tenant.shifts.close',

            'tenant.daily-closings.index',
            'tenant.daily-closings.create',
            'tenant.daily-closings.store',
            'tenant.daily-closings.show',
            'tenant.daily-closings.approve',

            'tenant.units.index',
            'tenant.units.create',
            'tenant.units.store',
            'tenant.units.edit',
            'tenant.units.update',
            'tenant.units.destroy',

            'tenant.categories.index',
            'tenant.categories.create',
            'tenant.categories.store',
            'tenant.categories.edit',
            'tenant.categories.update',
            'tenant.categories.destroy',

            'tenant.products.index',
            'tenant.products.create',
            'tenant.products.store',
            'tenant.products.show',
            'tenant.products.edit',
            'tenant.products.update',
            'tenant.products.destroy',

            'tenant.product-variants.store',
            'tenant.product-variants.update',
            'tenant.product-variants.destroy',

            'tenant.product-barcodes.store',
            'tenant.product-barcodes.generate',
            'tenant.product-barcodes.destroy',

            'tenant.product-branch-prices.update',

            'tenant.products.bulk-import.create',
            'tenant.products.bulk-import.store',

            'tenant.inventory.index',
            'tenant.inventory.movements',
            'tenant.inventory.batches',
            'tenant.inventory.low-stock',
            'tenant.inventory.expiry-alerts',

            'tenant.stock-adjustments.index',
            'tenant.stock-adjustments.create',
            'tenant.stock-adjustments.store',
            'tenant.stock-adjustments.show',

            'tenant.stock-transfers.index',
            'tenant.stock-transfers.create',
            'tenant.stock-transfers.store',
            'tenant.stock-transfers.show',

            'tenant.stock-counts.index',
            'tenant.stock-counts.create',
            'tenant.stock-counts.store',
            'tenant.stock-counts.show',
            'tenant.stock-counts.lines.store',
            'tenant.stock-counts.lines.update',
            'tenant.stock-counts.lines.destroy',
            'tenant.stock-counts.post',
            'tenant.stock-counts.cancel',

            'tenant.suppliers.index',
            'tenant.suppliers.create',
            'tenant.suppliers.store',
            'tenant.suppliers.show',
            'tenant.suppliers.edit',
            'tenant.suppliers.update',
            'tenant.suppliers.destroy',
            'tenant.suppliers.ledger',

            'tenant.purchase-orders.index',
            'tenant.purchase-orders.create',
            'tenant.purchase-orders.store',
            'tenant.purchase-orders.show',
            'tenant.purchase-orders.approve',
            'tenant.purchase-orders.cancel',

            'tenant.goods-receipts.index',
            'tenant.goods-receipts.create',
            'tenant.goods-receipts.store',
            'tenant.goods-receipts.show',

            'tenant.purchase-bills.index',
            'tenant.purchase-bills.create',
            'tenant.purchase-bills.store',
            'tenant.purchase-bills.show',

            'tenant.supplier-payments.index',
            'tenant.supplier-payments.create',
            'tenant.supplier-payments.store',
            'tenant.supplier-payments.show',

            // Customers
            'tenant.customers.index',
            'tenant.customers.create',
            'tenant.customers.store',
            'tenant.customers.show',
            'tenant.customers.edit',
            'tenant.customers.update',
            'tenant.customers.destroy',
            'tenant.customers.ledger',

            // Payment Methods
            'tenant.payment-methods.index',
            'tenant.payment-methods.store',
            'tenant.payment-methods.update',
            'tenant.payment-methods.destroy',

            // POS
            'tenant.pos.index',
            'tenant.pos.store',
            'tenant.pos.customers.quick-store',

            // Sales Orders
            'tenant.sales-orders.index',
            'tenant.sales-orders.create',
            'tenant.sales-orders.store',
            'tenant.sales-orders.show',
            'tenant.sales-orders.cancel',

            // Sales Ledger
            'tenant.sales-ledger.index',

            // Sales Returns
            'tenant.sales-returns.index',
            'tenant.sales-returns.create',
            'tenant.sales-returns.store',
            'tenant.sales-returns.show',

            // Restaurant Floors
            'tenant.restaurant.floors.index',
            'tenant.restaurant.floors.store',
            'tenant.restaurant.floors.update',
            'tenant.restaurant.floors.destroy',

            // Restaurant Tables
            'tenant.restaurant.tables.index',
            'tenant.restaurant.tables.store',
            'tenant.restaurant.tables.update',
            'tenant.restaurant.tables.destroy',

            // Restaurant Waiters
            'tenant.restaurant.waiters.index',
            'tenant.restaurant.waiters.store',
            'tenant.restaurant.waiters.update',
            'tenant.restaurant.waiters.destroy',

            // Restaurant Board & Sessions
            'tenant.restaurant.board',
            'tenant.restaurant.table-sessions.open',
            'tenant.restaurant.table-sessions.bill-requested',
            'tenant.restaurant.table-sessions.close',
            'tenant.restaurant.table-sessions.show',

            // Held Sales
            'tenant.held-sales.index',
            'tenant.held-sales.create',
            'tenant.held-sales.store',
            'tenant.held-sales.cancel',

            // Split Bill
            'tenant.sales-orders.split-bill',
            'tenant.sales-orders.split-bill.store',

            // Table Session Extra
            'tenant.restaurant.table-sessions.bill-preview',
            'tenant.restaurant.table-sessions.move',
            'tenant.restaurant.table-sessions.merge',

            // Unit Conversions
            'tenant.unit-conversions.index',
            'tenant.unit-conversions.store',
            'tenant.unit-conversions.update',
            'tenant.unit-conversions.destroy',

            // Recipes / BOM
            'tenant.recipes.index',
            'tenant.recipes.create',
            'tenant.recipes.store',
            'tenant.recipes.show',
            'tenant.recipes.edit',
            'tenant.recipes.update',
            'tenant.recipes.destroy',

            // Kitchen Productions
            'tenant.kitchen.productions.index',
            'tenant.kitchen.productions.create',
            'tenant.kitchen.productions.store',
            'tenant.kitchen.productions.show',
            'tenant.kitchen.productions.complete',

            // Kitchen Wastages
            'tenant.kitchen.wastages.index',
            'tenant.kitchen.wastages.create',
            'tenant.kitchen.wastages.store',
            'tenant.kitchen.wastages.show',

            // Printing — Printers
            'tenant.printing.printers.index',
            'tenant.printing.printers.store',
            'tenant.printing.printers.update',
            'tenant.printing.printers.destroy',
            'tenant.printing.terminal-settings.save',

            // Printing — Category Mappings
            'tenant.printing.category-mappings.index',
            'tenant.printing.category-mappings.store',
            'tenant.printing.category-mappings.destroy',

            // Printing — Layouts
            'tenant.printing.layouts.index',
            'tenant.printing.layouts.store',
            'tenant.printing.layouts.preview',

            // Printing — Jobs
            'tenant.printing.jobs.index',
            'tenant.printing.jobs.queue-receipt',
            'tenant.printing.jobs.queue-kot',
            'tenant.printing.jobs.mark-printed',
            'tenant.printing.jobs.retry',

            // Printing — Documents
            'tenant.printing.documents.preview',

            // Printing — Print Agents
            'tenant.print-agents.index',
            'tenant.print-agents.store',
            'tenant.print-agents.regenerate-token',
            'tenant.print-agents.deactivate',

            // Sales Controls — Promotions
            'tenant.promotions.index',
            'tenant.promotions.create',
            'tenant.promotions.store',
            'tenant.promotions.edit',
            'tenant.promotions.update',
            'tenant.promotions.destroy',

            // Sales Controls — Service Charge
            'tenant.service-charge-settings.index',
            'tenant.service-charge-settings.store',

            // Sales Controls — Void Reasons
            'tenant.void-reasons.index',
            'tenant.void-reasons.create',
            'tenant.void-reasons.store',
            'tenant.void-reasons.edit',
            'tenant.void-reasons.update',
            'tenant.void-reasons.destroy',

            // Manager Approvals
            'tenant.api.manager-approvals.verify',
            'tenant.api.pos.promotions.quote',
            'tenant.api.pos.totals.quote',
            'tenant.api.pos.table-sessions.open-orders',
            'tenant.api.catalog.barcode.lookup',

            'tenant.kitchen-display.index',
            'tenant.api.kitchen-display.orders',
            'tenant.api.kitchen-display.lines.status',
            'tenant.api.kitchen-display.orders.status',

            // Billing portal
            'tenant.billing.index',
            'tenant.billing.invoices.show',
            'tenant.billing.invoices.payments.store',
            'tenant.billing.invoices.payments.proof',

            // Plan upgrade requests
            'tenant.billing.upgrade.create',
            'tenant.billing.upgrade.store',
            'tenant.billing.upgrade.show',
            'tenant.billing.upgrade.cancel',

            // Manager PIN
            'tenant.users.manager-pin',
            'tenant.users.manager-pin.store',

            // Reports — Phase 1
            'tenant.reports.sales.summary',
            'tenant.reports.sales.items',
            'tenant.reports.sales.payments',
            'tenant.reports.shifts',
            'tenant.reports.inventory.valuation',

            // Reports — Phase 2
            'tenant.reports.daily-closings',
            'tenant.reports.inventory.movements',
            'tenant.reports.inventory.low-stock',
            'tenant.reports.inventory.expiry',
            'tenant.reports.purchases.summary',
            'tenant.reports.purchases.suppliers',
            'tenant.reports.purchases.payables',
            'tenant.reports.restaurant.tables',
            'tenant.reports.restaurant.waiters',
            'tenant.reports.restaurant.order-types',
            'tenant.reports.kitchen.recipe-consumption',
            'tenant.reports.kitchen.wastage',
            'tenant.reports.kitchen.production',
            'tenant.reports.audit.manager-approvals',
            'tenant.reports.printing.jobs',

            // Finance — Chart of Accounts (FIN-2)
            'tenant.finance.accounts.index',
            'tenant.finance.accounts.create',
            'tenant.finance.accounts.store',
            'tenant.finance.accounts.edit',
            'tenant.finance.accounts.update',
            'tenant.finance.accounts.destroy',

            // Finance — Cash & Bank Accounts (FIN-3)
            'tenant.finance.cash-bank-accounts.index',
            'tenant.finance.cash-bank-accounts.create',
            'tenant.finance.cash-bank-accounts.store',
            'tenant.finance.cash-bank-accounts.edit',
            'tenant.finance.cash-bank-accounts.update',
            'tenant.finance.cash-bank-accounts.destroy',

            // Finance — Expense Categories (FIN-4)
            'tenant.finance.expense-categories.index',
            'tenant.finance.expense-categories.create',
            'tenant.finance.expense-categories.store',
            'tenant.finance.expense-categories.edit',
            'tenant.finance.expense-categories.update',
            'tenant.finance.expense-categories.destroy',

            // Finance — Expense Vouchers (FIN-4)
            'tenant.finance.expenses.index',
            'tenant.finance.expenses.create',
            'tenant.finance.expenses.store',
            'tenant.finance.expenses.show',
            'tenant.finance.expenses.edit',
            'tenant.finance.expenses.update',
            'tenant.finance.expenses.destroy',
            'tenant.finance.expenses.post',
            'tenant.finance.expenses.void',

            // Finance — Customer Payments + Receivables (FIN-6)
            'tenant.finance.customer-payments.index',
            'tenant.finance.customer-payments.create',
            'tenant.finance.customer-payments.store',
            'tenant.finance.customer-payments.show',
            'tenant.reports.sales.receivables',

            // Finance — General Ledger (FIN-7)
            'tenant.finance.journal-entries.index',
            'tenant.finance.journal-entries.show',

            // Finance — Opening Balances / Owner Capital (FIN-13)
            'tenant.finance.opening-balances.index',
            'tenant.finance.opening-balances.create',
            'tenant.finance.opening-balances.store',
            'tenant.finance.opening-balances.show',
            'tenant.finance.opening-balances.edit',
            'tenant.finance.opening-balances.update',
            'tenant.finance.opening-balances.post',
            'tenant.finance.opening-balances.void',

            'tenant.finance.general-ledger.index',
            'tenant.finance.trial-balance.index',

            // Finance — Profit & Loss (FIN-9)
            'tenant.finance.profit-loss.index',

            // Finance — Branch-wise P&L (FIN-11)
            'tenant.finance.branch-profit-loss.index',

            // Finance — Balance Sheet (FIN-10)
            'tenant.finance.balance-sheet.index',

            // Finance — Accounting Export (FIN-12)
            'tenant.finance.export.index',

            // Coming Soon ERP extensions (ERP-SOON-1) — read-only roadmap pages.
            'tenant.finance.bank-reconciliation.index',
            'tenant.quotations.index',
            'tenant.purchase-requisitions.index',
            'tenant.purchase-returns.index',
            // Manufacturing Customers CRUD (MANUF-1)
            'tenant.manufacturing.customers.index',
            'tenant.manufacturing.customers.create',
            'tenant.manufacturing.customers.store',
            'tenant.manufacturing.customers.show',
            'tenant.manufacturing.customers.edit',
            'tenant.manufacturing.customers.update',
            'tenant.manufacturing.customers.destroy',
            // BOM CRUD (MANUF-3)
            'tenant.manufacturing.bom.index',
            'tenant.manufacturing.bom.create',
            'tenant.manufacturing.bom.store',
            'tenant.manufacturing.bom.show',
            'tenant.manufacturing.bom.edit',
            'tenant.manufacturing.bom.update',
            'tenant.manufacturing.bom.destroy',
            // Material Requisition / MRC CRUD (MANUF-4)
            'tenant.manufacturing.material-requisitions.index',
            'tenant.manufacturing.material-requisitions.create',
            'tenant.manufacturing.material-requisitions.store',
            'tenant.manufacturing.material-requisitions.show',
            'tenant.manufacturing.material-requisitions.edit',
            'tenant.manufacturing.material-requisitions.update',
            'tenant.manufacturing.material-requisitions.destroy',
            // Production Orders CRUD (MANUF-2)
            'tenant.manufacturing.production-orders.index',
            'tenant.manufacturing.production-orders.create',
            'tenant.manufacturing.production-orders.store',
            'tenant.manufacturing.production-orders.show',
            'tenant.manufacturing.production-orders.edit',
            'tenant.manufacturing.production-orders.update',
            'tenant.manufacturing.production-orders.destroy',
            // WIP job tracking CRUD (MANUF-5)
            'tenant.manufacturing.wip.index',
            'tenant.manufacturing.wip.create',
            'tenant.manufacturing.wip.store',
            'tenant.manufacturing.wip.show',
            'tenant.manufacturing.wip.edit',
            'tenant.manufacturing.wip.update',
            'tenant.manufacturing.wip.destroy',
            'tenant.manufacturing.finished-goods.index',
            'tenant.manufacturing.scrap.index',
            'tenant.manufacturing.rejections.index',
            'tenant.manufacturing.consumption.index',
            'tenant.manufacturing.reports.index',
        ];

        \App\Models\Tenant\Customer::updateOrCreate(
            ['code' => 'WALK-IN'],
            [
                'name'   => 'Walk-in Customer',
                'status' => 'active',
            ]
        );

        \App\Models\Tenant\PaymentMethod::updateOrCreate(
            ['code' => 'CASH'],
            ['name' => 'Cash', 'method_type' => 'cash', 'is_cash_drawer' => true, 'is_active' => true]
        );

        \App\Models\Tenant\PaymentMethod::updateOrCreate(
            ['code' => 'CARD'],
            ['name' => 'Card', 'method_type' => 'card', 'requires_reference' => true, 'is_active' => true]
        );

        \App\Models\Tenant\PaymentMethod::updateOrCreate(
            ['code' => 'BANK'],
            ['name' => 'Bank Transfer', 'method_type' => 'bank_transfer', 'requires_reference' => true, 'is_active' => true]
        );

        \App\Models\Tenant\Supplier::updateOrCreate(
            ['code' => 'SUP-001'],
            [
                'name'                => 'Default Supplier',
                'contact_person'      => null,
                'phone'               => null,
                'email'               => null,
                'address'             => null,
                'tax_number'          => null,
                'payment_terms_days'  => 0,
                'opening_balance'     => 0,
                'current_balance'     => 0,
                'status'              => 'active',
            ]
        );

        foreach ($tenantPermissions as $permission) {
            Permission::findOrCreate($permission, 'tenant');
        }

        $role = Role::findOrCreate('Owner', 'tenant');
        $role->syncPermissions($tenantPermissions);
        $owner->syncRoles([$role]);

        // Seed the default Chart of Accounts (FIN-2). Idempotent; tenant DB is active here.
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();

        // Seed default Cash & Bank accounts (FIN-3) — must run after the CoA seeder
        // (links to CoA codes 1110/1120/1210/1500).
        (new \Database\Seeders\Tenant\DefaultCashBankAccountsSeeder())->run();

        // Seed default expense categories (FIN-4) — must run after the CoA seeder
        // (links to expense CoA codes 6100-6800).
        (new \Database\Seeders\Tenant\DefaultExpenseCategoriesSeeder())->run();

        // Map payment methods → cash/bank accounts (FIN-7B) — after cash/bank seeder.
        (new \Database\Seeders\Tenant\DefaultPaymentMethodCashBankMappingSeeder())->run();
    }

    protected function makeDatabaseName(Tenant $tenant): string
    {
        $safeCode = Str::of($tenant->tenant_code)
            ->lower()
            ->replaceMatches('/[^a-z0-9_]/', '_')
            ->trim('_');

        return 'pos_tenant_' . $safeCode;
    }
}
