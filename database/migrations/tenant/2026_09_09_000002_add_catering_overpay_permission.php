<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-OVERPAYMENT-1 (step 4) — `tenant.catering.advances.overpay`
 * (synthetic, non-route).
 *
 * Who may take money the business has not billed for. No route carries this
 * name; the controller asks for it, exactly like `tenant.shifts.view-amounts`
 * and `tenant.dashboard.details`.
 *
 * GRANTED TO NO ROLE HERE, and that is the deliberate part.
 *
 * This is unlike HIDE-AMOUNTS-1, which granted its permission to every existing
 * role because taking a visibility away from everyone on deploy would have been
 * the surprise. Here the surprise runs the other way: this permission is the
 * authority to create a LIABILITY — money held that no invoice accounts for —
 * and handing that to every role the moment it deploys is not a default anybody
 * chose.
 *
 * NOT EVEN THE OWNER, and that part was a mistake — see 000003, which fixes it.
 * This docblock originally claimed "deploy.sh step [5] grants the Owner every
 * tenant permission, so the Owner will have it after the next deploy". That is
 * false for a routeless permission: deploy.sh AND TenantOpsService::syncTenant()
 * both build the Owner's grant from `route_catalogs`, which only ever contains
 * real routes. Deployed on 2026-09-09 the live Owner reported `can=no` and the
 * checkbox rendered for nobody. 000003 grants the Owner; every OTHER role must
 * still be granted it deliberately, by someone who decided:
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

        $exists = $conn->table('permissions')
            ->where('name', self::PERMISSION)->where('guard_name', 'tenant')->exists();

        if (! $exists) {
            $conn->table('permissions')->insert([
                'name' => self::PERMISSION, 'guard_name' => 'tenant',
                'created_at' => now(), 'updated_at' => now(),
            ]);
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
