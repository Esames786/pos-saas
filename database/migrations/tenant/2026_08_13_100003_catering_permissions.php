<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-SLICE-1: seed tenant-guard permissions for the Catering module
 * (permission name == route name, the platform convention) and grant them to
 * the Owner role only — delegation happens through the Permission Center,
 * which hides the whole group for non-entitled plans (PermissionCatalogService
 * is entitlement-aware). Pattern: 2026_08_10_000004 report section permissions.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'tenant.catering.events.index',
        'tenant.catering.events.create',
        'tenant.catering.events.store',
        'tenant.catering.events.show',
        'tenant.catering.events.edit',
        'tenant.catering.events.update',
        'tenant.catering.events.confirm',
        'tenant.catering.events.cancel',
        'tenant.catering.estimates.update',
        'tenant.catering.estimates.send',
        'tenant.catering.estimates.accept',
        'tenant.catering.estimates.revise',
        'tenant.catering.estimates.reprice',
        'tenant.catering.profiles.index',
        'tenant.catering.profiles.store',
        'tenant.catering.profiles.update',
        'tenant.catering.material-rates.index',
        'tenant.catering.material-rates.store',
        'tenant.catering.rate-impact.index',
        'tenant.catering.rate-impact.apply',
        'tenant.catering.advances.store',
        'tenant.catering.production-releases.store',
        'tenant.catering.production-releases.show',
        'tenant.catering.documents.estimate',
        'tenant.catering.documents.kitchen-sheet',
        'tenant.catering.printer-mappings.index',
        'tenant.catering.printer-mappings.store',
        'tenant.catering.printer-mappings.destroy',
        'tenant.catering.printer-mappings.copy-from-pos',
        'tenant.catering.settings.index',
        'tenant.catering.settings.update',
    ];

    public function up(): void
    {
        $permId = function (string $name): int {
            $id = DB::connection('tenant')->table('permissions')->where('name', $name)->where('guard_name', 'tenant')->value('id');

            return $id ?: DB::connection('tenant')->table('permissions')->insertGetId([
                'name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $ownerRoleIds = DB::connection('tenant')->table('roles')
            ->where('guard_name', 'tenant')->where('name', 'Owner')->pluck('id');

        foreach (self::PERMISSIONS as $name) {
            $permissionId = $permId($name);
            foreach ($ownerRoleIds as $roleId) {
                $exists = DB::connection('tenant')->table('role_has_permissions')
                    ->where('permission_id', $permissionId)->where('role_id', $roleId)->exists();
                if (! $exists) {
                    DB::connection('tenant')->table('role_has_permissions')->insert([
                        'permission_id' => $permissionId, 'role_id' => $roleId,
                    ]);
                }
            }
        }

        // Stale spatie cache rows 403 new permissions while old ones work (PROD-READINESS-1).
        DB::connection('tenant')->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }

    public function down(): void
    {
        $ids = DB::connection('tenant')->table('permissions')
            ->whereIn('name', self::PERMISSIONS)->where('guard_name', 'tenant')->pluck('id');
        DB::connection('tenant')->table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::connection('tenant')->table('permissions')->whereIn('id', $ids)->delete();
    }
};
