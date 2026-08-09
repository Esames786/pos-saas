<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Khatri #6 + #7 (2026-08-10):
 *  - tenant.dashboard becomes a BASELINE permission attached to EVERY role — a role built in
 *    the Permission Center without the Reports group was 403'd off its own landing page.
 *  - Per-section Report Center permissions (synthetic, non-route keys) so a role can be given
 *    only specific report sections. Back-granted to every role that already had Report Center
 *    access, so nothing changes for existing users until an admin trims them.
 */
return new class extends Migration
{
    private const SECTIONS = ['overview', 'categories', 'items', 'waiters', 'order-types', 'order-type-combos', 'cancellations', 'detailed', 'cash-bank'];

    public function up(): void
    {
        $permId = function (string $name): int {
            $id = DB::connection('tenant')->table('permissions')->where('name', $name)->where('guard_name', 'tenant')->value('id');

            return $id ?: DB::connection('tenant')->table('permissions')->insertGetId([
                'name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $attach = function (int $permissionId, int $roleId): void {
            $exists = DB::connection('tenant')->table('role_has_permissions')
                ->where('permission_id', $permissionId)->where('role_id', $roleId)->exists();
            if (! $exists) {
                DB::connection('tenant')->table('role_has_permissions')->insert([
                    'permission_id' => $permissionId, 'role_id' => $roleId,
                ]);
            }
        };

        $allRoleIds = DB::connection('tenant')->table('roles')->where('guard_name', 'tenant')->pluck('id');

        // 1) dashboard baseline for every role.
        $dashboardId = $permId('tenant.dashboard');
        foreach ($allRoleIds as $roleId) {
            $attach($dashboardId, $roleId);
        }

        // 2) section permissions, back-granted to roles that can open the Report Center.
        $centerIndexId = DB::connection('tenant')->table('permissions')
            ->where('name', 'tenant.reports.center.index')->where('guard_name', 'tenant')->value('id');
        $centerRoleIds = $centerIndexId
            ? DB::connection('tenant')->table('role_has_permissions')->where('permission_id', $centerIndexId)->pluck('role_id')
            : collect();
        foreach (self::SECTIONS as $section) {
            $sectionId = $permId('tenant.reports.center.sections.' . $section);
            foreach ($centerRoleIds as $roleId) {
                $attach($sectionId, $roleId);
            }
        }

        // stale spatie cache rows 403 new permissions while old ones work (PROD-READINESS-1).
        DB::connection('tenant')->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
    }

    public function down(): void
    {
        $names = array_map(fn ($s) => 'tenant.reports.center.sections.' . $s, self::SECTIONS);
        $ids = DB::connection('tenant')->table('permissions')->whereIn('name', $names)->where('guard_name', 'tenant')->pluck('id');
        DB::connection('tenant')->table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::connection('tenant')->table('permissions')->whereIn('id', $ids)->delete();
    }
};
