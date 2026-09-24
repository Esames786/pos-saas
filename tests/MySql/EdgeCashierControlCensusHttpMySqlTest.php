<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W0 CONTROL CENSUS GATE (owner directive 20 Sep 2026, after the 149-record audit).
 *
 * The old parity register said "zero gaps" because nothing compared the Online POS SCREEN with the Edge page — proofs
 * were API-only and the register was a curated workflow list (audit §9). This test closes that hole mechanically:
 *
 *  1. every `id="…"` in the Online POS view must be registered in tests/Fixtures/edge/online-pos-control-census.json
 *     (a new Online control fails the build until it is classified), and nothing stale may linger in the fixture;
 *  2. the Online view hash is pinned — a canonical reconcile that moves the reference fails here until the census is
 *     re-run (the reconcile gate of audit §I.5);
 *  3. every control classified present / equivalent / partial must have its Edge counterpart on the REAL rendered
 *     page (GET /edge/local/pos over the branch_server route → middleware → controller → Blade), and every control
 *     classified planned must NOT have its counterpart yet (a landed feature flips the row on purpose, never silently);
 *  4. every "not yet available / later milestone / needs the Online POS" deferral string in the Edge code must be
 *     registered against a record (deferrals can no longer hide in comments);
 *  5. the page's composed script (W0 concatenates one Blade fragment per feature area) must PARSE — node --check when a
 *     Node binary is available (EDGE_NODE_BIN or `node` on PATH); otherwise the check is reported as skipped, never as
 *     passed.
 *
 * Counts are written with their denominators to storage/framework/testing/edge-pos-census-summary.json for the
 * register regeneration (W7). No percentage is ever asserted.
 */
class EdgeCashierControlCensusHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const FIXTURE = 'tests/Fixtures/edge/online-pos-control-census.json';
    private const STATES = ['present', 'equivalent', 'partial', 'planned', 'online_required'];

    private int $branchId;
    private int $terminalId;
    private int $userId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');

        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions', 'combo_components', 'combos', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter One']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'default_terminal_id' => $this->terminalId, 'employee_code' => 'CENS' . Str::random(4)]);
        $categoryId = $this->makeCategory(['name' => 'Grills']);
        $productId = $this->makeProduct($categoryId, ['name' => 'Chicken Tikka', 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 250]);
        $this->makePaymentMethod(['method_type' => 'cash', 'name' => 'Cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $productId, 'product_variant_id' => null, 'quantity' => 10]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        // The census renders the page as an operator who may use every entry point (finance links included) so the
        // FULL Edge surface is on the page under test.
        foreach (['tenant.pos.index', 'tenant.pos.store', \App\Services\Edge\EdgeLocalSupplierFinanceService::PERM_LEDGER,
            \App\Services\Edge\EdgeLocalSupplierFinanceService::PERM_JOURNAL, \App\Services\Edge\EdgeLocalPurchaseReturnService::PERM_STORE] as $perm) {
            $this->grantEdgePermission($this->userId, $perm);
        }
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    // ───────────────────────────── 1 + 2: inventory completeness + pinned reference ─────────────────────────────

    public function test_every_online_pos_control_is_registered_and_the_reference_is_pinned(): void
    {
        $fixture = $this->fixture();
        $onlinePath = base_path($fixture['online_view']);
        $this->assertFileExists($onlinePath);
        $this->assertSame($fixture['online_view_sha1'], sha1_file($onlinePath),
            'The Online POS view changed since the census was taken (canonical reconcile?). Re-run the inventory: ' .
            'diff the id list, classify every new/removed control in ' . self::FIXTURE . ', then update online_view_sha1.');

        $onlineIds = $this->onlineIds(file_get_contents($onlinePath));
        $registered = array_keys($this->flatten($fixture));

        $unregistered = array_values(array_diff($onlineIds, $registered));
        $stale = array_values(array_diff($registered, $onlineIds));

        $this->assertSame([], $unregistered, 'Online POS controls with NO census row (classify them): ' . implode(', ', $unregistered));
        $this->assertSame([], $stale, 'Census rows whose Online control no longer exists (remove or re-point them): ' . implode(', ', $stale));
        $this->assertGreaterThan(200, count($onlineIds), 'The Online id inventory collapsed — the extractor or the view is broken.');
    }

    // ───────────────────────────── 3: every classification is TRUE on the rendered Edge page ─────────────────────────────

    public function test_every_census_row_matches_the_rendered_edge_page(): void
    {
        $fixture = $this->fixture();
        $rows = $this->flatten($fixture);
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();

        $failures = [];
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($rows as $id => $row) {
            $state = $row['state'] ?? null;
            if (! in_array($state, self::STATES, true)) {
                $failures[] = "{$id}: unknown state '{$state}'";
                continue;
            }
            $counts[$state]++;
            if (empty($row['record'])) {
                $failures[] = "{$id}: no audit record";
            }
            if (in_array($state, ['present', 'equivalent', 'partial'], true)) {
                $selectors = $row['edge'] ?? [];
                if ($selectors === []) {
                    $failures[] = "{$id}: state {$state} needs at least one Edge selector";
                }
                foreach ($selectors as $sel) {
                    if (! $this->pageHas($html, $sel)) {
                        $failures[] = "{$id}: Edge counterpart '{$sel}' is NOT on the rendered page (state {$state})";
                    }
                }
            } elseif ($state === 'planned') {
                if (empty($row['workstream'])) {
                    $failures[] = "{$id}: planned rows must name a workstream";
                }
                foreach ($row['edge'] ?? [] as $sel) {
                    if ($this->pageHas($html, $sel)) {
                        $failures[] = "{$id}: classified planned but '{$sel}' IS on the page — flip the row to present/equivalent/partial";
                    }
                }
            } elseif ($state === 'online_required' && empty($row['decision'])) {
                $failures[] = "{$id}: online_required needs the owner-accepted decision reference";
            }
        }

        $this->assertSame([], $failures, "Census mismatches:\n - " . implode("\n - ", $failures));

        // Counts WITH denominators — never a bare zero, never a percentage (audit §9 / §I.7).
        $summary = ['online_controls' => count($rows), 'by_state' => $counts, 'online_view_sha1' => $fixture['online_view_sha1'], 'taken_at' => now()->toIso8601String()];
        @mkdir(storage_path('framework/testing'), 0777, true);
        file_put_contents(storage_path('framework/testing/edge-pos-census-summary.json'), json_encode($summary, JSON_PRETTY_PRINT));
        $this->assertGreaterThan(0, $counts['planned'] + $counts['partial'], 'The census reports no open rows — verify the fixture was not blanked.');
    }

    // ───────────────────────────── 4: deferral strings must be registered ─────────────────────────────

    public function test_every_edge_deferral_string_is_registered(): void
    {
        $fixture = $this->fixture();
        $matches = array_map(fn ($d) => $d['match'], $fixture['deferrals']);
        $pattern = '/not yet available|later milestone|needs the Online POS|awaiting owner decision/i';
        $unregistered = [];
        foreach (['app/Services/Edge', 'app/Http/Controllers/Edge', 'resources/views/edge'] as $dir) {
            foreach ($this->phpFiles(base_path($dir)) as $file) {
                foreach (file($file) as $n => $line) {
                    if (! preg_match($pattern, $line)) {
                        continue;
                    }
                    $registered = false;
                    foreach ($matches as $m) {
                        if (str_contains($line, $m)) {
                            $registered = true;
                            break;
                        }
                    }
                    if (! $registered) {
                        $unregistered[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file) . ':' . ($n + 1) . ' ' . trim($line);
                    }
                }
            }
        }
        $this->assertSame([], $unregistered, "Deferral strings not registered in the census `deferrals` list:\n - " . implode("\n - ", $unregistered));
    }

    // ───────────────────────────── 5: the composed page script parses ─────────────────────────────

    public function test_composed_cashier_script_parses_under_node(): void
    {
        $node = $this->nodeBinary();
        if ($node === null) {
            $this->markTestSkipped('No Node binary (set EDGE_NODE_BIN or put node on PATH) — the composed script was NOT syntax-checked.');
        }
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        preg_match_all('#<script(?![^>]*application/json)[^>]*>(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1], 'No inline page script found.');
        $js = implode("\n;\n", $m[1]);
        $tmp = tempnam(sys_get_temp_dir(), 'edgepos') . '.js';
        file_put_contents($tmp, $js);
        try {
            $out = [];
            $code = 1;
            exec('"' . $node . '" --check "' . $tmp . '" 2>&1', $out, $code);
            $this->assertSame(0, $code, "node --check failed on the composed cashier script:\n" . implode("\n", $out));
        } finally {
            @unlink($tmp);
        }
    }

    // ───────────────────────────── helpers ─────────────────────────────

    private function fixture(): array
    {
        $json = json_decode(file_get_contents(base_path(self::FIXTURE)), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json['groups'] ?? null);

        return $json;
    }

    /** id => row (group defaults merged with per-id overrides). */
    private function flatten(array $fixture): array
    {
        $rows = [];
        foreach ($fixture['groups'] as $group => $g) {
            $defaults = ['group' => $group, 'state' => $g['state'] ?? null, 'record' => $g['record'] ?? null,
                'workstream' => $g['workstream'] ?? null, 'edge' => $g['edge'] ?? [], 'decision' => $g['decision'] ?? null];
            foreach ($g['ids'] as $id) {
                if (isset($rows[$id])) {
                    $this->fail("Census id '{$id}' is listed twice (groups '{$rows[$id]['group']}' and '{$group}').");
                }
                $override = $g['overrides'][$id] ?? [];
                $row = array_merge($defaults, $override);
                // A row that leaves the group's state (planned / online_required) does not inherit the group's Edge
                // selectors — those describe the counterpart of the group's OWN state.
                if (in_array($row['state'], ['planned', 'online_required'], true) && ! array_key_exists('edge', $override)) {
                    $row['edge'] = [];
                }
                $rows[$id] = $row;
            }
        }

        return $rows;
    }

    private function onlineIds(string $blade): array
    {
        preg_match_all('/<[a-z][a-z0-9]*\b[^>]*\sid="([A-Za-z0-9_-]+)"/', $blade, $m);

        return array_values(array_unique($m[1]));
    }

    private function pageHas(string $html, string $sel): bool
    {
        if (str_starts_with($sel, '#')) {
            $id = substr($sel, 1);

            return str_contains($html, 'id="' . $id . '"') || str_contains($html, "'" . $id . "'") || str_contains($html, '\"' . $id . '\"');
        }
        if (str_starts_with($sel, 'text:')) {
            return str_contains($html, substr($sel, 5));
        }
        if (str_starts_with($sel, 'js:')) {
            return str_contains($html, substr($sel, 3));
        }

        return str_contains($html, $sel);
    }

    /** @return string[] */
    private function phpFiles(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    private function nodeBinary(): ?string
    {
        $env = getenv('EDGE_NODE_BIN');
        if ($env && is_file($env)) {
            return $env;
        }
        $which = PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null';
        $found = trim((string) shell_exec($which));
        $first = strtok($found, "\r\n");

        return $first && is_file($first) ? $first : null;
    }
}
