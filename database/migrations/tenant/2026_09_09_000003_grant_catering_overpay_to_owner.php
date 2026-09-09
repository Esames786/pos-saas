<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-OVERPAYMENT-1 (step 4, correction) — give the Owner the permission
 * that 000002 created and gave to nobody.
 *
 * 000002 deliberately granted the new authority to no role, on the reasoning
 * that handing "may take money the business has not billed for" to every role
 * the moment it deploys is not a default anybody chose. That reasoning still
 * holds. What was wrong was the assumption written beside it: that `deploy.sh`
 * would give the Owner its own copy.
 *
 * It does not. Both `deploy.sh` step [5] and `TenantOpsService::syncTenant()`
 * build the Owner's grant from the master `route_catalogs` table, so a
 * permission with no route behind it is invisible to them. Deployed as-is on
 * 2026-09-09, the live Owner of kashifkitchen answered `can=no` to
 * `tenant.catering.advances.overpay`: the checkbox rendered for nobody, and the
 * controller would have dropped the flag from anyone who posted it anyway. The
 * feature was live and unreachable.
 *
 * So the grant belongs here, in the migration, which is how both synthetic
 * permissions before it work (`tenant.dashboard.details` 2026_08_31_000001,
 * `tenant.shifts.view-amounts` 2026_09_01_000004). The difference is the
 * audience: those two grant every existing role, because taking a visibility
 * AWAY from everyone on deploy would have been the surprise. This one grants
 * the Owner alone — the person whose money it is.
 *
 * Any other role stays a deliberate act:
 *
 *   php artisan tinker
 *   >>> Role::findByName('Manager', 'tenant')->givePermissionTo('tenant.catering.advances.overpay');
 *   php artisan system:clear-tenant-permission-cache
 *
 * givePermissionTo, never syncPermissions — the second would silently strip
 * everything else that role has.
 */
return new class extends Migration
{
    private const PERMISSION = 'tenant.catering.advances.overpay';

    public function up(): void
    {
        $conn = DB::connection('tenant');

        // 000002 runs first and creates the row. Created here too, so this
        // migration is correct on its own rather than only in the order it
        // happens to run.
        $permissionId = $conn->table('permissions')
            ->where('name', self::PERMISSION)->where('guard_name', 'tenant')->value('id');

        if (! $permissionId) {
            $permissionId = $conn->table('permissions')->insertGetId([
                'name' => self::PERMISSION, 'guard_name' => 'tenant',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $ownerId = $conn->table('roles')
            ->where('name', 'Owner')->where('guard_name', 'tenant')->value('id');

        // A tenant being provisioned has no Owner role yet — its roles are built
        // after the migrations run. TenantProvisioner carries this permission in
        // its own list for exactly that case, so neither path is missed.
        if ($ownerId) {
            $held = $conn->table('role_has_permissions')
                ->where('permission_id', $permissionId)->where('role_id', $ownerId)->exists();

            if (! $held) {
                $conn->table('role_has_permissions')->insert([
                    'permission_id' => $permissionId, 'role_id' => $ownerId,
                ]);
            }
        }

        // Stale spatie cache rows 403 a brand-new grant while old ones keep
        // working, which reads as "the button is broken for some people".
        $conn->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }

    /**
     * Only the GRANT is undone. The permission row belongs to 000002 and is left
     * for 000002 to remove, so rolling this back cannot delete an authority that
     * someone has since granted to another role.
     */
    public function down(): void
    {
        $conn = DB::connection('tenant');

        $permissionId = $conn->table('permissions')
            ->where('name', self::PERMISSION)->where('guard_name', 'tenant')->value('id');
        $ownerId = $conn->table('roles')
            ->where('name', 'Owner')->where('guard_name', 'tenant')->value('id');

        if ($permissionId && $ownerId) {
            $conn->table('role_has_permissions')
                ->where('permission_id', $permissionId)->where('role_id', $ownerId)->delete();
        }

        $conn->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }
};
