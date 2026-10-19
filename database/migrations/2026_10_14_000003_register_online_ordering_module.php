<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ONLINE-ORDERING-1 — "Online Ordering" plan module (tenant ki apni ordering website).
 *
 * Sirf REGISTER: kisi plan par on nahi. Ye rollout ka faisla hai (pehle Kashif Food), deploy ka
 * nateeja nahi — "feature jo bina maange aaye utna hi ghalat hai jitna jo gayab ho jaye". Isi liye
 * MasterSeeder me ye `ROLLOUT_GATED_MODULE_KEYS` me hai: seeder ise kisi plan par na on karta hai na off.
 *
 * Routes `tenant.online-ordering.*` ka module key khud `tenant.online-ordering` banta hai
 * (PermissionSyncService::moduleKey), to override ki zaroorat nahi.
 */
return new class extends Migration
{
    private const KEY = 'online_ordering';

    public function up(): void
    {
        $master = DB::connection('master');

        $attributes = [
            'name' => 'Online Ordering',
            'category' => 'Sales',
            'description' => 'The restaurant\'s own ordering website: delivery areas and fees, website prices, website hours, and orders landing on the POS.',
            'route_module_keys' => json_encode(['tenant.online-ordering']),
            'sort_order' => 155,
            'is_core' => false,
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($master->table('modules')->where('key', self::KEY)->exists()) {
            $master->table('modules')->where('key', self::KEY)->update($attributes);

            return;
        }

        $master->table('modules')->insert($attributes + ['key' => self::KEY, 'created_at' => now()]);
    }

    public function down(): void
    {
        $master = DB::connection('master');
        $moduleId = $master->table('modules')->where('key', self::KEY)->value('id');
        if ($moduleId) {
            $master->table('plan_modules')->where('module_id', $moduleId)->delete();
            $master->table('modules')->where('id', $moduleId)->delete();
        }
    }
};
