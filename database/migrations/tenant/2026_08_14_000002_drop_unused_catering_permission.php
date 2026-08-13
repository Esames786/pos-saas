<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-RELEASE-ACCEPTANCE-1: `tenant.catering.material-issues.show` was
 * seeded (2026_08_14_000001) but no route of that name exists — issue details
 * render on the production-release page. A permission that gates nothing only
 * confuses the Permission Center, so remove it everywhere it was seeded.
 * (Fresh installs no longer create it; see the updated seed list.)
 */
return new class extends Migration
{
    private const NAME = 'tenant.catering.material-issues.show';

    public function up(): void
    {
        $ids = DB::connection('tenant')->table('permissions')
            ->where('name', self::NAME)->where('guard_name', 'tenant')->pluck('id');
        DB::connection('tenant')->table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::connection('tenant')->table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::connection('tenant')->table('permissions')->whereIn('id', $ids)->delete();
        DB::connection('tenant')->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }

    public function down(): void
    {
        DB::connection('tenant')->table('permissions')->insert([
            'name' => self::NAME, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
