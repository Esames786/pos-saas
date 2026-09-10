<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-REFUND-BEYOND-CREDIT-1 — `tenant.catering.refunds.beyond-credit`
 * (synthetic, non-route).
 *
 * Who may hand back money that is COVERING A BILL, not merely money the
 * business was holding. Doing so is legitimate — a deposit on a booking that is
 * still going ahead is the owner's to return — but it puts the balance due back
 * up, so it is a decision rather than a bigger number.
 *
 * Deliberately NOT folded into `tenant.catering.advances.overpay`. That one is
 * the authority to CREATE a liability; this is the authority to return money
 * already earmarked against a bill. The codebase already draws this line:
 * "Money OUT is its own grant. Whoever may take a payment does not
 * automatically get to hand one back."
 *
 * GRANTED TO THE OWNER HERE, and that is deliberate — see 000003, which had to
 * be written because 000002 granted this kind of permission to nobody on the
 * belief that `deploy.sh` would cover the Owner. It does not: both `deploy.sh`
 * step [5] and `TenantOpsService::syncTenant()` build the Owner's grant from
 * the master `route_catalogs` table, and a permission with no route behind it
 * is invisible to them. Granting it here is the only thing that makes the
 * feature reachable on a live tenant.
 *
 * Every OTHER role stays a deliberate act:
 *
 *   php artisan tinker
 *   >>> Role::findByName('Manager', 'tenant')->givePermissionTo('tenant.catering.refunds.beyond-credit');
 *   php artisan system:clear-tenant-permission-cache
 *
 * givePermissionTo, never syncPermissions — the second would silently strip
 * everything else that role has.
 */
return new class extends Migration
{
    private const PERMISSION = 'tenant.catering.refunds.beyond-credit';

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

        // Stale spatie cache rows 403 a brand-new permission while old ones keep
        // working, which reads as "the button is broken for some people".
        $conn->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }

    public function down(): void
    {
        $conn = DB::connection('tenant');

        $permissionId = $conn->table('permissions')
            ->where('name', self::PERMISSION)->where('guard_name', 'tenant')->value('id');

        if ($permissionId) {
            $conn->table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            $conn->table('permissions')->where('id', $permissionId)->delete();
        }

        $conn->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }
};
