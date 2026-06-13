<?php

namespace App\Services\Saas;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Builds a restricted "Demo" role inside the CURRENTLY ACTIVATED tenant DB.
 * Grants only browse + safe POS/restaurant/KDS workflow permissions — never
 * destructive, billing, password, user/role admin, or settings permissions.
 *
 * Must be called after TenancyManager::activate($tenant).
 */
class DemoRoleService
{
    /** Workflow route-name prefixes whose writes are safe to demo. */
    private const ALLOW_PREFIXES = [
        'tenant.pos.',
        'tenant.api.pos.',
        'tenant.kitchen-display.',
        'tenant.api.kitchen-display.',
        'tenant.held-sales.',
        'tenant.restaurant.',
        'tenant.sales-orders.',
        'tenant.api.catalog.',
        'tenant.api.manager-approvals.',
        'tenant.reports.',
    ];

    /** Never grant if the permission name contains any of these. */
    private const BLOCK_KEYWORDS = [
        'destroy', 'delete', 'remove',
        'reset-password', 'password',
        'billing', 'subscription', 'upgrade',
        'payment', 'proof',
        'settings', 'roles', 'permissions',
        'users', 'import', 'export',
        'regenerate', 'manager-pin',
        'activate', 'deactivate',
    ];

    public function createOrUpdateDemoRole(): Role
    {
        $role = Role::findOrCreate('Demo', 'tenant');

        $safe = Permission::where('guard_name', 'tenant')
            ->pluck('name')
            ->filter(fn (string $name) => $this->isSafe($name))
            ->values()
            ->all();

        $role->syncPermissions($safe);

        return $role->fresh();
    }

    private function isSafe(string $name): bool
    {
        // Hard block on anything sensitive/destructive.
        foreach (self::BLOCK_KEYWORDS as $keyword) {
            if (str_contains($name, $keyword)) {
                return false;
            }
        }

        if ($name === 'tenant.dashboard') {
            return true;
        }

        // Safe workflow writes (POS sale, KDS, tables, held sales, reports).
        foreach (self::ALLOW_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        // Browse/read routes everywhere else.
        return str_ends_with($name, '.index') || str_ends_with($name, '.show');
    }
}
