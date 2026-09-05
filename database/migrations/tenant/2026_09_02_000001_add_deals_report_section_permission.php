<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DEAL-CATEGORY-1 — `tenant.reports.center.sections.deals` (synthetic, non-route).
 *
 * The Report Center filters its sections through `allowedSections()`, which asks
 * `$user->can('tenant.reports.center.sections.{section}')`. Without this row nobody holds the
 * new permission — not even the Owner — and the Deals section would simply never render. Deals
 * having just been taken OUT of Items, that would leave a report whose item total is short by
 * every deal sold, with nowhere for the difference to appear.
 *
 * GRANTED TO WHOEVER ALREADY HAS THE *ITEMS* SECTION, and only to them. Deals used to be part of
 * Items, so a role that could read Items could already see this money; giving it the new section
 * keeps its report whole. A role WITHOUT Items never saw those figures and does not start now —
 * granting to every role would hand a restricted operator a section it was never meant to have.
 *
 * Same shape as `2026_08_10_000004`, which created the other nine section permissions.
 */
return new class extends Migration
{
    private const PERMISSION = 'tenant.reports.center.sections.deals';
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
