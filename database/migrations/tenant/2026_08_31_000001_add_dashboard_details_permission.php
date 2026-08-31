<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DASHBOARD-DETAILS-1 — `tenant.dashboard.details` (synthetic, non-route).
 *
 * The dashboard's two bottom cards — "Top 5 Products Today" and "Last 7 Days — Net Sales" — read
 * the whole branch: what sells, and how every day of the week compared. A counter operator has no
 * business with either; he needs the tiles for his own shift and nothing more.
 *
 * Nothing on the dashboard was gated before this: `tenant.dashboard` is a BASELINE permission that
 * every role holds (migration 2026_08_10_000004 made it so, because a role without it hit 403 on
 * its own landing page). So the split had to be a new permission rather than a trim of that one.
 *
 * Back-granted to EVERY existing role, so no tenant changes behaviour on deploy — Khatri's owner
 * and manager keep the cards they have today. The hide only happens when an admin deliberately
 * revokes it from a role, which is how Kashif Food's Delivery role loses it.
 */
return new class extends Migration
{
    private const PERMISSION = 'tenant.dashboard.details';

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

        // EVERY role keeps what it can see today. Granting only to "report" roles would silently
        // take these cards away from managers on tenants nobody asked us to change.
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
