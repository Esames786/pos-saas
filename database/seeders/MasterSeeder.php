<?php

namespace Database\Seeders;

use App\Models\Master\CentralUser;
use App\Models\Master\Plan;
use App\Models\Master\PlanFeature;
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
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'central');
        }

        $role = Role::findOrCreate('Super Admin', 'central');
        $role->syncPermissions($permissions);

        $admin->syncRoles([$role]);
    }
}
