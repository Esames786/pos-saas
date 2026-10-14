<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PARTNER-SHARE-1 — `tenant.reports.center.sections.partner-share` (synthetic, non-route).
 *
 * The Report Center shows a section only to a user who holds its permission. This one is about
 * money owed between the owner and a partner, so it goes to the OWNER role only — not to every role
 * that can open the Report Center, the way the older sections were back-granted. Anyone else gets
 * it from the owner, additively.
 *
 * A brand-new tenant has no Owner role yet when its migrations run (roles are created after); for
 * those, TenantProvisioner carries this name in its synthetic-permission list.
 */
return new class extends Migration
{
    private const PERMISSION = 'tenant.reports.center.sections.partner-share';

    public function up(): void
    {
        $conn = DB::connection('tenant');

        $permissionId = $conn->table('permissions')
            ->where('name', self::PERMISSION)->where('guard_name', 'tenant')->value('id');
        if (! $permissionId) {
            $permissionId = $conn->table('permissions')->insertGetId([
                'name' => self::PERMISSION, 'guard_name' => 'tenant',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $ownerId = $conn->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id');
        if ($ownerId && ! $conn->table('role_has_permissions')
            ->where('permission_id', $permissionId)->where('role_id', $ownerId)->exists()) {
            $conn->table('role_has_permissions')->insert(['permission_id' => $permissionId, 'role_id' => $ownerId]);
        }

        // Stale spatie cache rows 403 a brand-new permission while old ones keep working.
        $conn->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }

    public function down(): void
    {
        $conn = DB::connection('tenant');
        $permissionId = $conn->table('permissions')
            ->where('name', self::PERMISSION)->where('guard_name', 'tenant')->value('id');

        if ($permissionId) {
            $conn->table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            $conn->table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            $conn->table('permissions')->where('id', $permissionId)->delete();
        }

        $conn->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }
};
