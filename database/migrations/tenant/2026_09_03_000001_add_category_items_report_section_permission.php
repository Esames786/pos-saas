<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ITEMS-BY-CATEGORY-1 — `tenant.reports.center.sections.category-items` (synthetic, non-route).
 *
 * The Report Center filters its sections through `allowedSections()`, which asks
 * `$user->can('tenant.reports.center.sections.{section}')`. Without this row nobody holds the new
 * permission — not even the Owner — and the section would never render, its checkbox would never
 * appear, and the work would look like it had not deployed.
 *
 * GRANTED TO WHOEVER ALREADY HAS THE *ITEMS* SECTION, and only to them. This section shows exactly
 * the rows Items shows, arranged under their category heads; a role that can read Items can
 * already see every figure in it. A role WITHOUT Items has deliberately never seen those numbers
 * and does not start seeing them now — granting to every role would hand a restricted operator a
 * section it was never meant to have. Same rule as the Deals section a day earlier.
 *
 * Same shape as `2026_08_10_000004`, which created the original nine section permissions, and
 * `2026_09_02_000001`, which added Deals.
 */
return new class extends Migration
{
    private const PERMISSION = 'tenant.reports.center.sections.category-items';
    private const ITEMS      = 'tenant.reports.center.sections.items';

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

        $itemsId = $conn->table('permissions')
            ->where('name', self::ITEMS)->where('guard_name', 'tenant')->value('id');

        // A tenant that has not been through 2026_08_10_000004 has no Items section either; there
        // is nothing to mirror, and the section permission set will be built when that runs.
        if (! $itemsId) {
            return;
        }

        $roleIds = $conn->table('role_has_permissions')->where('permission_id', $itemsId)->pluck('role_id');

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
