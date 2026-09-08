<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HIDE-AMOUNTS-1 (b) — `tenant.shifts.view-amounts` (synthetic, non-route).
 *
 * Who may read the money on the closing screens and the dashboard tiles once a branch has
 * `hide_amounts_from_operators` on. No route carries this name; it is asked for in the
 * controllers, exactly like `tenant.dashboard.details` (2026_08_31_000001).
 *
 * GRANTED TO EVERY EXISTING ROLE. That reads backwards against "only the admin should see it",
 * so it is worth being plain about: the FLAG is what hides the figures, and it ships off
 * everywhere. If this permission went to the Owner alone, then the moment it deployed every
 * manager on every tenant would lose figures nobody asked us to take away — and on a branch with
 * the flag off they would lose them for no reason at all.
 *
 * So the shape is the same two-step that took Report Center off Kashif Food's operator roles on
 * 31 August: the migration changes nothing, and then an owner deliberately
 *   (1) turns the flag on for a branch, and
 *   (2) revokes this permission from the roles that should count blind.
 * Both steps are visible, both are reversible, and neither is a side effect of shipping code.
 */
return new class extends Migration
{
    private const PERMISSION = 'tenant.shifts.view-amounts';

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

        foreach ($conn->table('roles')->where('guard_name', 'tenant')->pluck('id') as $roleId) {
            $exists = $conn->table('role_has_permissions')
                ->where('permission_id', $permissionId)->where('role_id', $roleId)->exists();
            if (! $exists) {
                $conn->table('role_has_permissions')->insert([
                    'permission_id' => $permissionId, 'role_id' => $roleId,
                ]);
            }
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
            $conn->table('permissions')->where('id', $permissionId)->delete();
        }

        $conn->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }
};
