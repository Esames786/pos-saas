<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POS-TERMINAL-PIN-1 — `tenant.pos.change-terminal` (synthetic, non-route).
 *
 * A floor operator can be BOUND to several terminals so he may recall and reprint the counters'
 * orders, yet must keep SELLING on his own — otherwise his orders stamp another counter, print at
 * its printer and land in its shift. This permission is what separates the two.
 *
 * Back-granted to every role that can already open the POS, so no existing tenant changes
 * behaviour on deploy: the pin only bites once an admin deliberately trims the permission off a
 * role (and only for a user who has a default terminal to be pinned to).
 */
return new class extends Migration
{
    private const PERMISSION = 'tenant.pos.change-terminal';

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

        // Every role that can open the POS keeps the reach it has today.
        $posIndexId = $conn->table('permissions')
            ->where('name', 'tenant.pos.index')->where('guard_name', 'tenant')->value('id');

        $roleIds = $posIndexId
            ? $conn->table('role_has_permissions')->where('permission_id', $posIndexId)->pluck('role_id')
            : collect();

        foreach ($roleIds as $roleId) {
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
