<?php

namespace Tests\Feature\Edge;

use App\Support\Pos\PosPermissionCatalog;
use Tests\TestCase;

/**
 * W-E (owner decision A6) — the POS permission catalogue can never drift from the code again.
 *
 * Scans every Edge permission check site (denyUnlessCan / ->can / abort_unless(can) / @can / requireAny / PERM_* and
 * *PERMISSION* constants, class-constant references resolved by reflection, plus every 'tenant.*' string literal in
 * the Edge PHP code) and fails when a checked permission is missing from PosPermissionCatalog — naming the string and
 * the file. Also: every catalogue name must be a permission a tenant can actually have (provisioner list, a tenant
 * migration that seeds it, or an Online tenant route name), the shared POS view's @can gates must be catalogued or
 * declared Cloud-only, the provisioner must build the cashier template from the catalogue, and the Edge MySQL fixture
 * must seed exactly the template.
 */
class EdgePermissionCatalogTest extends TestCase
{
    /** Code paths the Edge runtime executes that check permissions (dirs are scanned recursively). */
    private const SCAN_PATHS = [
        'app/Http/Controllers/Edge',
        'app/Services/Edge',
        'app/Services/Sales/KotCancellationService.php',
        'app/Services/Security/UserDataScope.php',   // CHANGE_TERMINAL_PERMISSION (terminal pin, used by the Edge controllers)
        'app/Support/AmountVisibility.php',          // tenant.shifts.view-amounts (Edge shift screens)
        'resources/views/edge',
    ];

    /** The ONE shared POS view Edge renders from the next release (W-A/W-B). */
    private const SHARED_VIEW_PATH = 'resources/views/tenant/pos';

    /**
     * 'tenant.*' string literals in the scanned PHP that are NOT user permissions (view names are excluded
     * automatically when resources/views/<name>.blade.php exists).
     */
    private const NON_PERMISSION_LITERALS = [
        'tenant.offline-edge.index' => 'OfflineEdgeEntitlementService::ROUTE_KEY — a plan-entitlement route key (SubscriptionAccess), Cloud-side, not a user permission',
    ];

    public function test_every_permission_the_edge_code_checks_is_in_the_catalogue(): void
    {
        $checked = $this->scanChecks();
        $this->assertNotEmpty($checked, 'the scanner found no permission check at all — the regexes are broken');

        $missing = [];
        foreach ($checked as $permission => $sites) {
            if (! PosPermissionCatalog::has($permission)) {
                $missing[] = "{$permission} (checked in " . implode(', ', array_unique($sites)) . ')';
            }
        }

        $this->assertSame([], $missing, "Edge checks permissions that are NOT in App\\Support\\Pos\\PosPermissionCatalog:\n  - "
            . implode("\n  - ", $missing) . "\nAdd each to PosPermissionCatalog::ENTRIES with its group, Edge check site and Online route.");

        // Sanity: the known check sites are really seen (guards against a scanner that silently finds too little).
        foreach (['tenant.pos.void-kot-item', 'tenant.pos.change-terminal', 'tenant.held-sales.reattach-table', 'tenant.suppliers.ledger',
            'tenant.purchase-returns.post', 'tenant.shifts.view-amounts', 'tenant.pos.quick-report-send'] as $expected) {
            $this->assertArrayHasKey($expected, $checked, "the scanner no longer sees the Edge check for {$expected}");
        }
    }

    public function test_shared_pos_view_gates_are_catalogued_or_declared_cloud_only(): void
    {
        // The shared POS view directory + every Online `tenant.*` view an Edge controller renders (W-B shared pages).
        $views = $this->files(self::SHARED_VIEW_PATH);
        foreach ($this->edgeRenderedOnlineViews() as $view) {
            $views[] = $view;
        }
        $views = array_values(array_unique(array_map(fn ($f) => (string) realpath($f), $views)));
        $this->assertContains('resources/views/tenant/pos/index.blade.php', array_map(fn ($f) => $this->rel($f), $views));

        $missing = [];
        foreach ($views as $file) {
            foreach ($this->bladeChecks((string) file_get_contents($file)) as $permission) {
                if (! PosPermissionCatalog::has($permission) && ! isset(PosPermissionCatalog::SHARED_VIEW_CLOUD_ONLY_CHECKS[$permission])) {
                    $missing[] = "{$permission} (@can in " . $this->rel($file) . ')';
                }
            }
        }

        $this->assertSame([], $missing, "The shared POS view gates on permissions that are neither catalogued nor declared Cloud-only:\n  - "
            . implode("\n  - ", $missing) . "\nAdd each to PosPermissionCatalog::ENTRIES (cashier-facing) or SHARED_VIEW_CLOUD_ONLY_CHECKS (Cloud management).");
    }

    public function test_every_catalogue_permission_is_a_known_tenant_permission(): void
    {
        $provisioner = $this->provisionerPermissions();
        $this->assertGreaterThan(100, count($provisioner), 'could not parse TenantProvisioner::$tenantPermissions');
        $migrations = $this->migrationSeededPermissions();
        $routes = $this->tenantRouteNames();

        $unknown = [];
        foreach (PosPermissionCatalog::all() as $permission) {
            if (! isset($provisioner[$permission]) && ! isset($migrations[$permission]) && ! isset($routes[$permission])) {
                $unknown[] = $permission;
            }
        }

        $this->assertSame([], $unknown, "PosPermissionCatalog references permissions no tenant can have — not in "
            . "app/Services/Tenancy/TenantProvisioner.php \$tenantPermissions, not seeded by a database/migrations/tenant migration, "
            . "not an Online route name in routes/tenant.php:\n  - " . implode("\n  - ", $unknown));

        foreach (array_keys(PosPermissionCatalog::SHARED_VIEW_CLOUD_ONLY_CHECKS) as $permission) {
            $this->assertTrue(isset($provisioner[$permission]) || isset($routes[$permission]), "Cloud-only view check {$permission} is not a known permission");
        }
    }

    public function test_catalogue_entries_are_documented_and_the_groups_are_consistent(): void
    {
        foreach (PosPermissionCatalog::ENTRIES as $name => $entry) {
            $this->assertMatchesRegularExpression('/^tenant\.[a-z0-9._-]+$/', $name);
            $this->assertNotEmpty($entry['groups'], "{$name}: no group");
            foreach ($entry['groups'] as $group) {
                $this->assertContains($group, [PosPermissionCatalog::GROUP_CASHIER, PosPermissionCatalog::GROUP_MANAGER, PosPermissionCatalog::GROUP_FINANCE], "{$name}: unknown group {$group}");
            }
            $this->assertNotSame('', trim($entry['edge']), "{$name}: the Edge check site is not documented");
            $this->assertNotSame('', trim($entry['online']), "{$name}: the owning Online route is not documented");
        }

        $union = array_values(array_unique(array_merge(PosPermissionCatalog::cashier(), PosPermissionCatalog::manager(), PosPermissionCatalog::finance())));
        sort($union);
        $all = PosPermissionCatalog::all();
        sort($all);
        $this->assertSame($all, $union, 'all() must be exactly the union of cashier/manager/finance');

        // The owner-approved cashier set (A6) — the explicit list, so a silent removal fails loudly.
        foreach ([
            'tenant.pos.index', 'tenant.pos.store', 'tenant.pos.change-terminal', 'tenant.pos.customers.quick-store', 'tenant.pos.quick-report-send',
            'tenant.pos.void-kot-item', 'tenant.held-sales.store', 'tenant.held-sales.cancel', 'tenant.held-sales.reattach-table',
            'tenant.sales-orders.split-bill.store', 'tenant.api.manager-approvals.verify',
            'tenant.restaurant.table-sessions.open', 'tenant.restaurant.table-sessions.close', 'tenant.restaurant.table-sessions.show',
            'tenant.restaurant.table-sessions.move', 'tenant.restaurant.table-sessions.merge', 'tenant.restaurant.table-sessions.bill-preview',
            'tenant.restaurant.table-sessions.bill-requested', 'tenant.shifts.store', 'tenant.shifts.close', 'tenant.shifts.index', 'tenant.shifts.show',
            'tenant.shifts.create', 'tenant.shifts.close-form',
            'tenant.sales-returns.index', 'tenant.sales-returns.show', 'tenant.sales-returns.store', 'tenant.sales-returns.create',
        ] as $required) {
            $this->assertContains($required, PosPermissionCatalog::cashier(), "the cashier template lost {$required}");
        }
        // Phase 3 (approver eligibility): NO permission is an approver marker — eligibility is the bootstrap flag may_approve_pos.
        $this->assertNotContains('tenant.pos.void-kot-item', PosPermissionCatalog::manager(), 'void-kot-item is the requester void permission, never the approver marker');
        $this->assertStringContainsString('may_approve_pos', (string) file_get_contents(base_path('app/Support/Pos/PosPermissionCatalog.php')), 'the catalogue documents the non-permission approver contract');
        $this->assertNotContains('tenant.shifts.view-amounts', PosPermissionCatalog::cashier(), 'a counter cashier must stay subject to blind count');
        foreach (PosPermissionCatalog::finance() as $finance) {
            $this->assertNotContains($finance, PosPermissionCatalog::cashier(), "finance permission {$finance} leaked into the cashier template");
        }
    }

    public function test_the_provisioner_builds_the_cashier_template_from_the_catalogue_for_new_tenants_only(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Tenancy/TenantProvisioner.php'));
        $this->assertStringContainsString('PosPermissionCatalog::cashier()', $src);
        $this->assertStringContainsString('PosPermissionCatalog::CASHIER_ROLE_TEMPLATE', $src);
        $this->assertStringContainsString('$this->provisionCashierRoleTemplate($isNewTenant)', $src);
        $this->assertSame('Cashier (Counter)', PosPermissionCatalog::CASHIER_ROLE_TEMPLATE);
        // The template is never assigned to a user and never synced over an existing role.
        $method = substr($src, (int) strpos($src, 'public function provisionCashierRoleTemplate'));
        $method = substr($method, 0, (int) strpos($method, 'protected function makeDatabaseName'));
        $this->assertStringNotContainsString('assignRole', $method);
        $this->assertStringNotContainsString('syncRoles', $method);
        $this->assertStringNotContainsString('syncPermissions', $method);
    }

    public function test_the_edge_mysql_fixture_seeds_exactly_the_cashier_template(): void
    {
        $probe = new class {
            use \Tests\MySql\Support\EdgeLocalRuntimeFixture;

            public function parity(): array
            {
                return $this->onlinePosParityPermissions();
            }
        };

        $this->assertSame(PosPermissionCatalog::cashier(), $probe->parity());
    }

    // ── scanner ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, list<string>> permission => files that check it */
    private function scanChecks(): array
    {
        $found = [];
        $add = function (string $permission, string $file) use (&$found): void {
            $found[$permission][] = $this->rel($file);
        };

        foreach (self::SCAN_PATHS as $path) {
            foreach ($this->files($path) as $file) {
                $src = (string) file_get_contents($file);
                if (str_ends_with($file, '.blade.php')) {
                    foreach ($this->bladeChecks($src) as $p) {
                        $add($p, $file);
                    }

                    continue;
                }
                foreach ($this->phpChecks($src, $file) as $p) {
                    $add($p, $file);
                }
            }
        }
        ksort($found);

        return $found;
    }

    /** @return list<string> */
    private function bladeChecks(string $src): array
    {
        $out = [];
        // @can / @cannot / @canany and ->can('…') inside Blade expressions.
        preg_match_all("/@(?:can|cannot)\\(\\s*'(tenant\\.[a-z0-9._-]+)'/", $src, $m);
        $out = array_merge($out, $m[1]);
        preg_match_all('/@canany\(\s*\[([^\]]*)\]/', $src, $m);
        foreach ($m[1] as $list) {
            $out = array_merge($out, $this->literals($list));
        }
        preg_match_all("/(?:->|\\?->)can\\(\\s*'(tenant\\.[a-z0-9._-]+)'/", $src, $m);

        return array_values(array_unique(array_merge($out, $m[1])));
    }

    /** @return list<string> */
    private function phpChecks(string $src, string $file): array
    {
        $out = [];

        // (a) ->can('…') / ?->can('…') (incl. abort_unless(... can('…'))) and denyUnlessCan('…').
        preg_match_all("/(?:->|\\?->)(?:can|cannot)\\(\\s*'(tenant\\.[a-z0-9._-]+)'/", $src, $m);
        $out = array_merge($out, $m[1]);
        preg_match_all("/denyUnlessCan\\(\\s*'(tenant\\.[a-z0-9._-]+)'/", $src, $m);
        $out = array_merge($out, $m[1]);

        // (b) requireAny($request, ['…', Class::CONST]).
        preg_match_all('/requireAny\(\s*\$request\s*,\s*\[([^\]]*)\]/', $src, $m);
        foreach ($m[1] as $list) {
            $out = array_merge($out, $this->literals($list), $this->resolveConstRefs($list, $src, $file));
        }

        // (c) permission constants: PERM_*, *PERMISSION*, *PERMISSIONS (strings or arrays).
        preg_match_all('/const\s+(\w*PERM\w*)\s*=\s*(\[[^;]*\]|\'[^\']*\')\s*;/s', $src, $m);
        foreach ($m[2] as $value) {
            $out = array_merge($out, $this->literals($value));
        }

        // (d) class-constant references passed to a check: can(Foo::BAR), denyUnlessCan(Foo::BAR, …).
        preg_match_all('/(?:(?:->|\?->)(?:can|cannot)|denyUnlessCan)\(\s*(\\\\?[A-Za-z_][\w\\\\]*::[A-Z_][A-Z0-9_]*)/', $src, $m);
        foreach ($m[1] as $ref) {
            $out = array_merge($out, $this->resolveConstRefs($ref, $src, $file));
        }

        // (e) catch-all: every 'tenant.*' string literal in real code (comments are skipped by the tokenizer).
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value = substr($token[1], 1, -1);
                if (preg_match('/^tenant\.[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', $value) && ! $this->isNonPermissionLiteral($value)) {
                    $out[] = $value;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function isNonPermissionLiteral(string $value): bool
    {
        if (isset(self::NON_PERMISSION_LITERALS[$value])) {
            return true;
        }

        // A Blade view name (view('tenant.printing.documents.receipt', …)) is not a permission.
        return is_file(resource_path('views/' . str_replace('.', '/', $value) . '.blade.php'));
    }

    /** @return list<string> */
    private function literals(string $code): array
    {
        preg_match_all("/'(tenant\\.[a-z0-9._-]+)'/", $code, $m);

        return $m[1];
    }

    /** Resolve `Foo::BAR` / `self::BAR` references in $code to their permission string(s). @return list<string> */
    private function resolveConstRefs(string $code, string $src, string $file): array
    {
        preg_match_all('/(\\\\?[A-Za-z_][\w\\\\]*)::([A-Z_][A-Z0-9_]*)/', $code, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $class, $const]) {
            $fqcn = $this->resolveClass($class, $src);
            $this->assertNotNull($fqcn, "cannot resolve {$class}::{$const} referenced in a permission check in " . $this->rel($file));
            $value = (new \ReflectionClassConstant($fqcn, $const))->getValue(); // private constants too
            foreach ((array) $value as $v) {
                if (is_string($v) && str_starts_with($v, 'tenant.')) {
                    $out[] = $v;
                }
            }
        }

        return $out;
    }

    private function resolveClass(string $class, string $src): ?string
    {
        preg_match('/^namespace\s+([^;]+);/m', $src, $ns);
        $namespace = $ns[1] ?? '';
        if (in_array($class, ['self', 'static'], true)) {
            preg_match('/^(?:final\s+|abstract\s+)?(?:class|trait)\s+(\w+)/m', $src, $c);

            return isset($c[1]) ? $namespace . '\\' . $c[1] : null;
        }
        if (str_starts_with($class, '\\')) {
            return ltrim($class, '\\');
        }
        $first = explode('\\', $class)[0];
        if (preg_match('/^use\s+([\w\\\\]+\\\\' . preg_quote($first, '/') . ')\s*;/m', $src, $u)) {
            return $u[1] . substr($class, strlen($first));
        }
        $candidate = $namespace . '\\' . $class;

        return class_exists($candidate) || interface_exists($candidate) ? $candidate : null;
    }

    /** Blade files of the Online `tenant.*` views the Edge controllers render (`view('tenant.…')`). @return list<string> */
    private function edgeRenderedOnlineViews(): array
    {
        $out = [];
        foreach ($this->files('app/Http/Controllers/Edge') as $file) {
            preg_match_all("/view\\(\\s*'(tenant\\.[a-z0-9._-]+)'/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $name) {
                $path = resource_path('views/' . str_replace('.', '/', $name) . '.blade.php');
                if (is_file($path)) {
                    $out[] = $path;
                }
            }
        }

        return array_values(array_unique($out));
    }

    // ── known-permission sources ────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, true> */
    private function provisionerPermissions(): array
    {
        $src = (string) file_get_contents(base_path('app/Services/Tenancy/TenantProvisioner.php'));
        $this->assertSame(1, preg_match('/\$tenantPermissions\s*=\s*\[(.*?)\n\s*\];/s', $src, $m), 'TenantProvisioner::$tenantPermissions not found');

        return array_fill_keys($this->literals($m[1]), true);
    }

    /** Synthetic permissions a tenant migration seeds into `permissions`. @return array<string, true> */
    private function migrationSeededPermissions(): array
    {
        $out = [];
        foreach (glob(base_path('database/migrations/tenant/*.php')) as $file) {
            $src = (string) file_get_contents($file);
            if (! str_contains($src, "table('permissions')") && ! str_contains($src, 'Permission::')) {
                continue;
            }
            foreach ($this->literals($src) as $p) {
                $out[$p] = true;
            }
        }

        return $out;
    }

    /** Online tenant route names (route_catalogs → deploy.sh grants them as permissions). @return array<string, true> */
    private function tenantRouteNames(): array
    {
        $src = (string) file_get_contents(base_path('routes/tenant.php'));
        preg_match_all("/name\\(\\s*'(tenant\\.[a-z0-9._-]+)'\\s*\\)/", $src, $a);
        preg_match_all("/=>\\s*'(tenant\\.[a-z0-9._-]+)'/", $src, $b);

        return array_fill_keys(array_merge($a[1], $b[1]), true);
    }

    // ── files ───────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function files(string $relative): array
    {
        $path = base_path($relative);
        if (is_file($path)) {
            return [$path];
        }
        $this->assertDirectoryExists($path, "scan path {$relative} is gone — update EdgePermissionCatalogTest::SCAN_PATHS");
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    private function rel(string $file): string
    {
        return str_replace('\\', '/', ltrim(substr($file, strlen(base_path())), '\\/'));
    }
}
