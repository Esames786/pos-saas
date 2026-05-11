<?php

namespace App\Services\Tenancy;

use App\Models\Master\Plan;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Master\TenantDatabase;
use App\Models\Master\TenantDomain;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class TenantProvisioner
{
    public function __construct(
        protected TenancyManager $tenancyManager
    ) {}

    public function provisionDemoTenant(): Tenant
    {
        $tenant = Tenant::firstOrCreate(
            ['tenant_code' => 'demo'],
            [
                'business_name' => 'Demo Store',
                'owner_name' => 'Demo Owner',
                'owner_email' => 'owner@demo.com',
                'currency_code' => 'PKR',
                'status' => 'active',
                'trial_ends_at' => now()->addMonths(2),
                'activated_at' => now(),
            ]
        );

        TenantDomain::updateOrCreate(
            ['domain' => 'demo.' . config('tenancy.tenant_base_domain')],
            [
                'tenant_id' => $tenant->id,
                'is_primary' => true,
                'status' => 'active',
            ]
        );

        $dbName = 'pos_tenant_demo';

        TenantDatabase::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'db_connection' => 'tenant',
                'db_host' => env('TENANT_DB_HOST', '127.0.0.1'),
                'db_port' => env('TENANT_DB_PORT', 3306),
                'db_database' => $dbName,
                'db_username' => env('TENANT_DB_USERNAME', 'root'),
                'db_password' => env('TENANT_DB_PASSWORD'),
                'migration_status' => 'pending',
            ]
        );

        DB::connection('master')->statement(
            "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );

        $tenant->refresh()->load('database');

        $tenant->database->update(['migration_status' => 'running']);

        $this->tenancyManager->activate($tenant);

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        $tenant->database->update(['migration_status' => 'completed']);

        DB::connection('tenant')->table('languages')->updateOrInsert(
            ['code' => 'en'],
            ['name' => 'English', 'is_rtl' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        DB::connection('tenant')->table('languages')->updateOrInsert(
            ['code' => 'ar'],
            ['name' => 'Arabic', 'is_rtl' => true, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]
        );

        $branch = Branch::firstOrCreate(
            ['name' => 'Main Branch'],
            [
                'business_type' => 'hybrid',
                'address' => 'Demo Address',
                'status' => 'active',
            ]
        );

        $owner = User::firstOrCreate(
            ['email' => 'owner@demo.com'],
            [
                'name' => 'Demo Owner',
                'password' => Hash::make('password'),
                'status' => 'active',
                'locale' => 'en',
            ]
        );

        $owner->branches()->syncWithoutDetaching([$branch->id]);

        Permission::findOrCreate('tenant.dashboard', 'tenant');
        $role = Role::findOrCreate('Owner', 'tenant');
        $role->givePermissionTo('tenant.dashboard');
        $owner->assignRole($role);

        $plan = Plan::where('code', 'standard')->first();

        if ($plan) {
            Subscription::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'plan_id' => $plan->id,
                    'status' => 'trial',
                    'trial_ends_at' => now()->addMonths(2),
                ]
            );
        }

        return $tenant;
    }
}
