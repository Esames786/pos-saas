<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use App\Support\Pos\PosPermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W-E (owner decision A6) — READ-ONLY audit of the roles that act as POS cashiers.
 *
 * For every active tenant (or the one given by --tenant), every role that holds `tenant.pos.index` or
 * `tenant.pos.store` is compared with PosPermissionCatalog::cashier() and the catalogue permissions it LACKS are
 * reported, with how many users hold the role. Existing tenants are NEVER granted anything: no grant-all, no
 * overwrite of a custom role, no silent expansion — the tenant edits its own roles in the Permission Center.
 *
 * The command only SELECTs (roles, permissions, role_has_permissions, model_has_roles); it has no write path.
 */
class PermissionsAuditCashierRolesCommand extends Command
{
    protected $signature = 'permissions:audit-cashier-roles
        {--tenant= : Only this tenant_code}
        {--json : Machine-readable report}';

    protected $description = 'Report (read-only) which POS cashier catalogue permissions each cashier-like role lacks, per active tenant.';

    /** A role is "cashier-like" when it may open the POS or complete a sale. */
    private const CASHIER_MARKERS = ['tenant.pos.index', 'tenant.pos.store'];

    public function handle(TenancyManager $tenancy): int
    {
        $catalogue = PosPermissionCatalog::cashier();
        $tenants = Tenant::where('status', 'active')
            ->when($this->option('tenant'), fn ($q) => $q->where('tenant_code', $this->option('tenant')))
            ->orderBy('tenant_code')
            ->get();

        if ($this->option('tenant') && $tenants->isEmpty()) {
            $this->error('No active tenant with tenant_code [' . $this->option('tenant') . '].');

            return self::FAILURE;
        }

        $report = [
            'catalogue' => ['role_template' => PosPermissionCatalog::CASHIER_ROLE_TEMPLATE, 'cashier_permissions' => $catalogue],
            'read_only' => true,
            'tenants' => [],
        ];

        foreach ($tenants as $tenant) {
            try {
                $tenancy->activate($tenant);
                $report['tenants'][] = ['tenant_code' => $tenant->tenant_code] + $this->auditTenant($catalogue);
            } catch (\Throwable $e) {
                $report['tenants'][] = ['tenant_code' => $tenant->tenant_code, 'error' => $e->getMessage(), 'roles' => []];
            } finally {
                $tenancy->deactivate();
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('POS cashier role audit (read-only; nothing is granted). Catalogue: ' . count($catalogue) . ' permissions.');
        foreach ($report['tenants'] as $t) {
            $this->line('');
            $this->line("[{$t['tenant_code']}]");
            if (isset($t['error'])) {
                $this->warn('  error: ' . $t['error']);

                continue;
            }
            if ($t['permissions_unknown_to_tenant'] !== []) {
                $this->line('  catalogue permissions not present in this tenant: ' . implode(', ', $t['permissions_unknown_to_tenant']));
            }
            if ($t['roles'] === []) {
                $this->line('  no role holds tenant.pos.index / tenant.pos.store');

                continue;
            }
            foreach ($t['roles'] as $r) {
                $status = $r['missing'] === [] ? 'COMPLETE' : count($r['missing']) . ' missing';
                $this->line("  role \"{$r['role']}\" ({$r['users']} user(s)): {$status}");
                foreach ($r['missing'] as $p) {
                    $this->line("    - {$p}");
                }
            }
        }

        return self::SUCCESS;
    }

    /** @return array{permissions_unknown_to_tenant: list<string>, roles: list<array{role: string, role_id: int, users: int, missing: list<string>}>} */
    private function auditTenant(array $catalogue): array
    {
        $conn = DB::connection('tenant');
        if (! Schema::connection('tenant')->hasTable('roles') || ! Schema::connection('tenant')->hasTable('role_has_permissions')) {
            return ['permissions_unknown_to_tenant' => [], 'roles' => []];
        }

        $known = $conn->table('permissions')->where('guard_name', PosPermissionCatalog::GUARD)
            ->whereIn('name', $catalogue)->pluck('name')->all();

        $roleIds = $conn->table('role_has_permissions as rhp')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->where('p.guard_name', PosPermissionCatalog::GUARD)
            ->whereIn('p.name', self::CASHIER_MARKERS)
            ->distinct()->pluck('rhp.role_id')->all();

        $roles = [];
        foreach ($conn->table('roles')->whereIn('id', $roleIds)->where('guard_name', PosPermissionCatalog::GUARD)->orderBy('name')->get(['id', 'name']) as $role) {
            $held = $conn->table('role_has_permissions as rhp')
                ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
                ->where('rhp.role_id', $role->id)
                ->pluck('p.name')->all();
            $roles[] = [
                'role' => (string) $role->name,
                'role_id' => (int) $role->id,
                'users' => (int) $conn->table('model_has_roles')->where('role_id', $role->id)->count(),
                'missing' => array_values(array_diff($catalogue, $held)),
            ];
        }

        return [
            'permissions_unknown_to_tenant' => array_values(array_diff($catalogue, $known)),
            'roles' => $roles,
        ];
    }
}
