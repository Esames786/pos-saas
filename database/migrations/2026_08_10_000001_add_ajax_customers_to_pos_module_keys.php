<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// CUSTOMER-UX-1: the POS customer search route (tenant.ajax.customers) must belong to the
// pos module so (a) entitlement maps it instead of relying on fail-open, and (b) the
// data-driven Manager-role sync (prefix match on module route keys) grants it to cashiers.
// Master data migration so deploy.sh applies it; MasterSeeder carries it for fresh installs.
return new class extends Migration
{
    public function up(): void
    {
        $this->toggleKey(true);
    }

    public function down(): void
    {
        $this->toggleKey(false);
    }

    private function toggleKey(bool $add): void
    {
        $row = DB::connection('master')->table('modules')->where('key', 'pos')->first();
        if (! $row) {
            return;
        }
        $keys = collect(json_decode($row->route_module_keys, true) ?: [])
            ->reject(fn ($k) => $k === 'tenant.ajax.customers');
        if ($add) {
            $keys->push('tenant.ajax.customers');
        }
        DB::connection('master')->table('modules')->where('key', 'pos')
            ->update(['route_module_keys' => $keys->values()->toJson(), 'updated_at' => now()]);
    }
};
