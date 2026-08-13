<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// BINGOO-CATERING-PREFLIGHT-1: register the Catering & Events module so
// EnsureTenantSubscriptionAccess maps tenant.catering.* routes to a real module
// (fail-closed) instead of relying on the unmapped-key fail-open path.
// Master data migration so deploy.sh applies it to existing installs;
// MasterSeeder carries the same entry for fresh installs. No plan is enabled
// here — plan attachment stays explicit plan-module administration (the
// enterprise plan pulls all active modules on reseed).
return new class extends Migration
{
    public function up(): void
    {
        $master = DB::connection('master');
        $existing = $master->table('modules')->where('key', 'catering')->first();

        $attributes = [
            'name' => 'Catering & Events',
            'category' => 'Operations',
            'description' => 'Catering events, estimates/quotations, material rate book, recipe costing, production releases, and event documents.',
            'route_module_keys' => json_encode(['tenant.catering']),
            'sort_order' => 145,
            'is_core' => false,
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($existing) {
            $master->table('modules')->where('key', 'catering')->update($attributes);

            return;
        }

        $master->table('modules')->insert($attributes + ['key' => 'catering', 'created_at' => now()]);
    }

    public function down(): void
    {
        $master = DB::connection('master');
        $moduleId = $master->table('modules')->where('key', 'catering')->value('id');
        if ($moduleId) {
            $master->table('plan_modules')->where('module_id', $moduleId)->delete();
            $master->table('modules')->where('id', $moduleId)->delete();
        }
    }
};
