<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * CANONICAL REHEARSAL chunk 2 of the 15 Nov 2026 catch-up (owner Priority 2): counter-wise shift isolation + handover rendering.
 *
 * SHIFT-LIST-COUNTER-1 twin (canonical 4769655e, ShiftListCounterScopeMySqlTest on the Cloud): on the Branch Server a cashier tied
 * to certain counters sees only those counters' shifts — the shared list / detail / close pages (tenant/shifts/*) AND the
 * Edge-styled history / detail screens (edge.finance.*) — and another counter's shift by id answers 403 with Online's words,
 * never its drawer. Owner / manager (unbound) keep every counter. Over real HTTP on a branch_server-booted app.
 *
 * SHIFT-HANDOVER-1 (canonical 2814f44d): the shared close view now reads `$handover` unguarded — the Edge close page must still
 * render (the Master switch is never carried by a Branch Server, so the block is null, as Online with the switch off).
 */
class EdgeShiftCounterScopeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $takeawayTerminal;
    private int $quickTerminal;
    private int $takeawayCashier;
    private int $quickCashier;
    private int $managerId;
    private int $takeawayShift;
    private int $quickShift;

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
        $this->cleanTenant([
            'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta',
            'cash_count_lines', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories',
            'shifts', 'terminal_user', 'branch_user', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->takeawayTerminal = $this->makeTerminal($this->branchId, ['name' => 'Takeaway']);
        $this->quickTerminal = $this->makeTerminal($this->branchId, ['name' => 'Quick Sale']);
        $this->takeawayCashier = $this->makeUser(['default_branch_id' => $this->branchId, 'default_terminal_id' => $this->takeawayTerminal, 'employee_code' => 'TA' . Str::random(4)]);
        $this->quickCashier = $this->makeUser(['default_branch_id' => $this->branchId, 'default_terminal_id' => $this->quickTerminal, 'employee_code' => 'QS' . Str::random(4)]);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'MG' . Str::random(4)]);
        // The binding that scopes the dashboard, reports and the shift one may open or close (UserDataScope::terminalIds).
        DB::connection('tenant')->table('terminal_user')->insert([
            ['terminal_id' => $this->takeawayTerminal, 'user_id' => $this->takeawayCashier, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['terminal_id' => $this->quickTerminal, 'user_id' => $this->quickCashier, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->makePaymentMethod(['method_type' => 'cash', 'name' => 'Cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([]);
        foreach ([$this->takeawayCashier, $this->quickCashier, $this->managerId] as $userId) {
            $this->seedEdgeCredential($userId, $this->branchId, 1);
        }

        $this->takeawayShift = $this->openShiftAs($this->takeawayCashier, $this->takeawayTerminal, 11111);
        $this->quickShift = $this->openShiftAs($this->quickCashier, $this->quickTerminal, 22222);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    private function as(int $userId): static
    {
        $this->actingAs(User::on('tenant')->find($userId), 'tenant');
        Auth::shouldUse('tenant');

        return $this;
    }

    private function openShiftAs(int $userId, int $terminalId, float $openingCash): int
    {
        $this->as($userId);
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $terminalId])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => $openingCash])->assertStatus(201);

        return (int) DB::connection('tenant')->table('shifts')->where('terminal_id', $terminalId)->where('status', 'open')->value('id');
    }

    /** A link to the shift's own page / close screen (shared or Edge-styled): `…shifts/{id}"`, `…shifts/{id}/close"`. */
    private function assertLinksShift(string $html, int $shiftId, bool $expected, string $why): void
    {
        $pattern = '#shifts/' . $shiftId . '(["/?])#';
        if ($expected) {
            $this->assertMatchesRegularExpression($pattern, $html, $why);
        } else {
            $this->assertDoesNotMatchRegularExpression($pattern, $html, $why);
        }
    }

    /* ── 1. the lists — shared page and the Edge-styled history screen ───────────────────────── */

    public function test_a_counter_cashier_sees_only_his_own_counters_shifts(): void
    {
        foreach (['/edge/local/pos/shared/shifts', '/edge/local/pos/shifts'] as $url) {
            $html = $this->as($this->takeawayCashier)->get($url)->assertOk()->getContent();
            $this->assertLinksShift($html, $this->takeawayShift, true, "{$url}: his own shift is listed");
            $this->assertLinksShift($html, $this->quickShift, false, "{$url}: the Quick Sale shift must not be listed");
            $this->assertStringNotContainsString('22,222.00', $html, "{$url}: the Quick Sale drawer's cash must not reach the page");

            $html = $this->as($this->quickCashier)->get($url)->assertOk()->getContent();
            $this->assertLinksShift($html, $this->quickShift, true, "{$url}: the Quick Sale cashier sees his own");
            $this->assertLinksShift($html, $this->takeawayShift, false, "{$url}: the Takeaway shift must not be listed");
            $this->assertStringNotContainsString('11,111.00', $html);
        }
    }

    /** The manager is bound to no counter: the whole list, both open drawers counted. */
    public function test_an_unbound_manager_still_sees_every_counter(): void
    {
        foreach (['/edge/local/pos/shared/shifts', '/edge/local/pos/shifts'] as $url) {
            $html = $this->as($this->managerId)->get($url)->assertOk()->getContent();
            $this->assertLinksShift($html, $this->takeawayShift, true, "{$url}: the manager sees the Takeaway shift");
            $this->assertLinksShift($html, $this->quickShift, true, "{$url}: the manager sees the Quick Sale shift");
        }
        $this->as($this->managerId)->get('/edge/local/pos/shared/shifts/' . $this->quickShift)->assertOk();
        $this->as($this->managerId)->get('/edge/local/pos/shifts/' . $this->quickShift)->assertOk();
        $this->as($this->managerId)->get('/edge/local/pos/shifts/' . $this->quickShift . '/close')->assertOk();
    }

    /* ── 2. by id: the detail pages and the close screen ─────────────────────────────────────── */

    public function test_a_counter_cashier_cannot_open_another_counters_shift_by_id(): void
    {
        foreach ([
            '/edge/local/pos/shared/shifts/' . $this->quickShift,
            '/edge/local/pos/shifts/' . $this->quickShift,
            '/edge/local/pos/shifts/' . $this->quickShift . '/close',
        ] as $url) {
            $res = $this->as($this->takeawayCashier)->get($url);
            $this->assertSame(403, $res->getStatusCode(), "{$url} must be refused to the Takeaway cashier");
            $this->assertStringContainsString('This shift belongs to another counter.', $res->getContent(), "{$url}: Online's words");
            $this->assertStringNotContainsString('22,222.00', $res->getContent(), "{$url} must not leak the drawer");
        }

        // The rule, not a broken route: his own shift opens on every screen.
        $this->as($this->takeawayCashier)->get('/edge/local/pos/shared/shifts/' . $this->takeawayShift)->assertOk();
        $this->as($this->takeawayCashier)->get('/edge/local/pos/shifts/' . $this->takeawayShift)->assertOk();
        $this->as($this->takeawayCashier)->get('/edge/local/pos/shifts/' . $this->takeawayShift . '/close')->assertOk();
    }

    /* ── 3. SHIFT-HANDOVER-1 rendering compatibility ─────────────────────────────────────────── */

    /** The shared close view reads `$handover`; on a Branch Server the switch is never on, so the page renders without the block. */
    public function test_the_shared_close_page_renders_without_the_handover_block(): void
    {
        $res = $this->as($this->takeawayCashier)->get('/edge/local/pos/shifts/' . $this->takeawayShift . '/close')->assertOk();
        $res->assertViewIs('tenant.shifts.close');
        $this->assertNull($res->viewData('handover'), 'SHIFT-HANDOVER-1 is behind a Master switch a Branch Server never carries');
        $html = $res->getContent();
        $this->assertStringNotContainsString('hand the counter over', $html);
        $this->assertStringNotContainsString('Undefined variable', $html);
        $this->assertStringContainsString('window.POS_RUNTIME = {"mode":"edge"', $html);
    }
}
