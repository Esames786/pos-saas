<?php

namespace Database\Seeders;

use App\Models\Master\CentralUser;
use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanFeature;
use App\Models\Master\PlanModule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class MasterSeeder extends Seeder
{
    public function run(): void
    {
        DB::connection('master')->table('languages')->updateOrInsert(
            ['code' => 'en'],
            ['name' => 'English', 'is_rtl' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        DB::connection('master')->table('languages')->updateOrInsert(
            ['code' => 'ar'],
            ['name' => 'Arabic', 'is_rtl' => true, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]
        );

        foreach ([
                     ['code' => '2checkout', 'name' => '2Checkout / Verifone', 'type' => 'global'],
                     ['code' => 'payfast', 'name' => 'PayFast Pakistan', 'type' => 'local'],
                     ['code' => 'paypro', 'name' => 'PayPro Pakistan', 'type' => 'local'],
                     ['code' => 'payoneer', 'name' => 'Payoneer', 'type' => 'global'],
                     ['code' => 'manual_bank', 'name' => 'Manual Bank Transfer', 'type' => 'manual'],
                 ] as $gateway) {
            DB::connection('master')->table('payment_gateways')->updateOrInsert(
                ['code' => $gateway['code']],
                [
                    'name' => $gateway['name'],
                    'type' => $gateway['type'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $quickSale = Plan::updateOrCreate(
            ['code' => 'quick_sale'],
            [
                'name' => 'Quick Sale POS',
                'price' => 2500,
                'currency_code' => 'PKR',
                'billing_period' => 'monthly',
                'is_active' => true,
            ]
        );

        $standard = Plan::updateOrCreate(
            ['code' => 'standard'],
            [
                'name' => 'Standard Restaurant & Inventory',
                'price' => 7500,
                'currency_code' => 'PKR',
                'billing_period' => 'monthly',
                'is_active' => true,
            ]
        );

        foreach ([
                     'quick_sale',
                     'payments',
                     'basic_reports',
                 ] as $feature) {
            PlanFeature::updateOrCreate(
                ['plan_id' => $quickSale->id, 'feature_key' => $feature],
                ['feature_value' => 'enabled']
            );
        }

        foreach ([
                     'quick_sale',
                     'inventory',
                     'recipes',
                     'restaurant_tables',
                     'kot_printing',
                     'supplier_accounting',
                     'reports',
                 ] as $feature) {
            PlanFeature::updateOrCreate(
                ['plan_id' => $standard->id, 'feature_key' => $feature],
                ['feature_value' => 'enabled']
            );
        }

        $admin = CentralUser::updateOrCreate(
            ['email' => 'superadmin@mywebsite.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'status' => 'active',
            ]
        );

        $permissions = [
            'central.dashboard',
            'central.routes.index',
            'central.routes.sync',
            'central.routes.publish',
            'central.routes.unpublish',
            'central.routes.publish-all',
            'central.routes.sync-permissions',

            'central.tenants.index',
            'central.tenants.create',
            'central.tenants.store',
            'central.tenants.show',
            'central.tenants.edit',
            'central.tenants.update',
            'central.tenants.provision',
            'central.tenants.activate',
            'central.tenants.suspend',
            'central.tenants.cancel',

            'central.tenant-domains.store',
            'central.tenant-domains.primary',
            'central.tenant-domains.activate',
            'central.tenant-domains.deactivate',
            'central.tenant-domains.destroy',

            'central.plans.index',
            'central.plans.edit',
            'central.plans.update',
            'central.modules.index',
            'central.modules.edit',
            'central.modules.update',
            'central.tenants.subscription.update',

            'central.invoices.index',
            'central.tenants.invoices.create',
            'central.tenants.invoices.store',
            'central.invoices.show',
            'central.invoices.payments.store',
            'central.invoices.void',
            'central.invoices.payments.proof',
            'central.invoices.payments.verify',
            'central.invoices.payments.reject',

            'central.subscription-requests.index',
            'central.subscription-requests.show',
            'central.subscription-requests.approve',
            'central.subscription-requests.reject',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'central');
        }

        $role = Role::findOrCreate('Super Admin', 'central');
        $role->syncPermissions($permissions);

        $admin->syncRoles([$role]);

        $this->seedCommercialModules();
    }

    private function seedCommercialModules(): void
    {
        $modules = [
            [
                'key' => 'pos',
                'name' => 'POS',
                'category' => 'Sales',
                'description' => 'Quick sale, cart, checkout, payments, held sales and POS APIs.',
                'route_module_keys' => ['tenant.pos', 'tenant.held-sales', 'tenant.sales-orders', 'tenant.sales-returns', 'tenant.sales-ledger', 'tenant.customers', 'tenant.payment-methods'],
                'sort_order' => 10,
                'is_core' => true,
            ],
            [
                'key' => 'catalog',
                'name' => 'Catalog',
                'category' => 'Core',
                'description' => 'Products, categories, units, barcodes and product pricing.',
                'route_module_keys' => ['tenant.products', 'tenant.product-variants', 'tenant.product-barcodes', 'tenant.product-branch-prices', 'tenant.categories', 'tenant.units', 'tenant.unit-conversions'],
                'sort_order' => 20,
                'is_core' => true,
            ],
            [
                'key' => 'inventory',
                'name' => 'Inventory',
                'category' => 'Inventory',
                'description' => 'Stock balances, ledgers, adjustments and transfers.',
                'route_module_keys' => ['tenant.inventory', 'tenant.stock-adjustments', 'tenant.stock-transfers'],
                'sort_order' => 30,
                'is_core' => false,
            ],
            [
                'key' => 'stock_count',
                'name' => 'Stock Count',
                'category' => 'Inventory',
                'description' => 'Physical inventory counting and variance posting.',
                'route_module_keys' => ['tenant.stock-counts'],
                'sort_order' => 40,
                'is_core' => false,
            ],
            [
                'key' => 'purchasing',
                'name' => 'Purchasing',
                'category' => 'Purchasing',
                'description' => 'Suppliers, purchase orders, goods receipts and purchase bills.',
                'route_module_keys' => ['tenant.suppliers', 'tenant.supplier-payments', 'tenant.purchase-orders', 'tenant.goods-receipts', 'tenant.purchase-bills'],
                'sort_order' => 50,
                'is_core' => false,
            ],
            [
                'key' => 'restaurant',
                'name' => 'Restaurant',
                'category' => 'Restaurant',
                'description' => 'Tables, floors, waiters, dine-in orders and restaurant board.',
                'route_module_keys' => ['tenant.restaurant', 'tenant.restaurant-floors', 'tenant.restaurant-tables', 'tenant.restaurant-table-sessions', 'tenant.restaurant-waiters'],
                'sort_order' => 60,
                'is_core' => false,
            ],
            [
                'key' => 'kitchen_display',
                'name' => 'Kitchen Display',
                'category' => 'Restaurant',
                'description' => 'Kitchen display board with line/order cooking status.',
                'route_module_keys' => ['tenant.kitchen-display'],
                'sort_order' => 70,
                'is_core' => false,
            ],
            [
                'key' => 'kitchen_inventory',
                'name' => 'Kitchen Inventory',
                'category' => 'Restaurant',
                'description' => 'Recipes, kitchen production and wastage tracking.',
                'route_module_keys' => ['tenant.kitchen', 'tenant.recipes'],
                'sort_order' => 80,
                'is_core' => false,
            ],
            [
                'key' => 'printing',
                'name' => 'Printing',
                'category' => 'Operations',
                'description' => 'Printers, KOT/receipt layouts, print jobs and local print agent.',
                'route_module_keys' => ['tenant.printing', 'tenant.print-agents'],
                'sort_order' => 90,
                'is_core' => false,
            ],
            [
                'key' => 'reports',
                'name' => 'Reports',
                'category' => 'Analytics',
                'description' => 'Sales, inventory, purchasing, restaurant and kitchen reports.',
                'route_module_keys' => ['tenant.reports', 'tenant.dashboard'],
                'sort_order' => 100,
                'is_core' => false,
            ],
            [
                'key' => 'sales_controls',
                'name' => 'Sales Controls',
                'category' => 'Controls',
                'description' => 'Promotions, void reasons, manager approvals, service charge and tips.',
                'route_module_keys' => ['tenant.promotions', 'tenant.void-reasons', 'tenant.service-charge-settings'],
                'sort_order' => 110,
                'is_core' => false,
            ],
            [
                'key' => 'multi_branch',
                'name' => 'Multi Branch',
                'category' => 'Core',
                'description' => 'Branches, terminals, shifts and branch-level configuration.',
                'route_module_keys' => ['tenant.branches', 'tenant.terminals', 'tenant.shifts', 'tenant.daily-closings', 'tenant.currencies', 'tenant.currency-denominations'],
                'sort_order' => 120,
                'is_core' => false,
            ],
            [
                'key' => 'users_roles',
                'name' => 'Users & Roles',
                'category' => 'Administration',
                'description' => 'Tenant users, roles and permission management.',
                'route_module_keys' => ['tenant.users', 'tenant.roles', 'tenant.permissions'],
                'sort_order' => 130,
                'is_core' => true,
            ],
        ];

        foreach ($modules as $module) {
            Module::updateOrCreate(
                ['key' => $module['key']],
                [
                    'name' => $module['name'],
                    'category' => $module['category'],
                    'description' => $module['description'],
                    'route_module_keys' => $module['route_module_keys'],
                    'sort_order' => $module['sort_order'],
                    'is_core' => $module['is_core'],
                    'is_active' => true,
                ]
            );
        }

        $quickSaleEnabled = [
            'pos',
            'catalog',
            'printing',
            'reports',
            'users_roles',
        ];

        $standardEnabled = [
            'pos',
            'catalog',
            'inventory',
            'stock_count',
            'purchasing',
            'restaurant',
            'kitchen_display',
            'kitchen_inventory',
            'printing',
            'reports',
            'sales_controls',
            'multi_branch',
            'users_roles',
        ];

        $planModuleMap = [
            'quick_sale' => $quickSaleEnabled,
            'standard' => $standardEnabled,
        ];

        foreach ($planModuleMap as $planCode => $enabledKeys) {
            $plan = Plan::where('code', $planCode)->first();

            if (!$plan) {
                continue;
            }

            foreach (Module::orderBy('sort_order')->get() as $module) {
                $enabled = in_array($module->key, $enabledKeys, true);

                $limits = null;

                if ($planCode === 'quick_sale' && $module->key === 'reports') {
                    $limits = ['level' => 'basic'];
                }

                if ($planCode === 'quick_sale' && $module->key === 'multi_branch') {
                    $limits = ['branch_limit' => 1];
                }

                if ($planCode === 'standard' && $module->key === 'multi_branch') {
                    $limits = ['branch_limit' => 3];
                }

                PlanModule::updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'module_id' => $module->id,
                    ],
                    [
                        'is_enabled' => $enabled,
                        'limits' => $limits,
                    ]
                );
            }
        }

        // Keep plan_features as the legacy/flexible limits bag for now.
        // Enforcement in 14A-2 can read plan_modules for modules and plan_features for numeric limits.
        $limitFeatures = [
            'quick_sale' => [
                'branch_limit' => '1',
                'user_limit' => '2',
                'terminal_limit' => '1',
            ],
            'standard' => [
                'branch_limit' => '3',
                'user_limit' => '10',
                'terminal_limit' => '4',
            ],
        ];

        foreach ($limitFeatures as $planCode => $features) {
            $plan = Plan::where('code', $planCode)->first();

            if (!$plan) {
                continue;
            }

            foreach ($features as $key => $value) {
                PlanFeature::updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'feature_key' => $key,
                    ],
                    [
                        'feature_value' => $value,
                    ]
                );
            }
        }
    }
}
