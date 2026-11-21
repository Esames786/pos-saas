<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Edge\Continuity\ContinuityAdoptionService;
use App\Services\Edge\Continuity\LiveStateMirror;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPosService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE 0.9 CONTINUITY (stream A) — ADOPTION on the real Edge-local schema, through the real cashier routes and services.
 *
 * After a supervised Switch to Offline the FIRST POS touch adopts the mirrored live state, once per local-authority segment:
 * every counter's online shift continues as an Edge shift (opening cash = mirrored expected cash, no shift to open by hand),
 * held bills and whole tables become Edge held sales with the SAME Cloud identities and fired lines marked sent (a fired line
 * never re-prints; a new line fires). Idempotent (fast path bypassed or not), race-safe (two OS processes), and STALE or
 * UNTRUSTED mirrored state FAILS CLOSED: nothing adopted, the cashier sees the reason and the normal "open shift" flow.
 */
class EdgeContinuityAdoptionMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $t1;
    private int $t2;
    private int $cashierId;
    private int $openerId;
    private int $cat;
    private int $biryani;
    private int $raita;
    private int $naan;
    private int $tableId;
    private int $waiterId;
    private array $pkg = [];

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();
        config(['database.connections.edge_local' => array_merge(config('database.connections.edge_local', []), [
            'host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
            'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'), 'password' => config('database.connections.tenant.password'),
        ])]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_mirror_adoptions', 'edge_mirror_order_lines', 'edge_mirror_held_sales', 'edge_mirror_table_orders', 'edge_mirror_table_sessions', 'edge_mirror_shifts', 'edge_mirror_meta',
            'edge_local_print_deliveries', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_local_connection_transitions', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions',
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_line_cancellations', 'category_printer_mappings', 'terminal_printer_settings', 'printers',
            'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['name' => 'Khatri-like Branch', 'timezone' => 'Asia/Karachi']);
        $this->t1 = $this->makeTerminal($this->branchId, ['code' => 'T1', 'name' => '1-Counter']);
        $this->t2 = $this->makeTerminal($this->branchId, ['code' => 'T2', 'name' => '2-Counter']);
        $this->cat = $this->makeCategory(['name' => 'Kitchen']);
        $p = ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active'];
        $this->biryani = $this->makeProduct($this->cat, $p + ['name' => 'Biryani', 'default_selling_price' => 150]);
        $this->raita = $this->makeProduct($this->cat, $p + ['name' => 'Raita', 'default_selling_price' => 50]);
        $this->naan = $this->makeProduct($this->cat, $p + ['name' => 'Naan', 'default_selling_price' => 30]);
        $this->makePaymentMethod(['method_type' => 'cash']);
        $printer = $this->makePrinter(['code' => 'P-KOT', 'print_role' => 'kot', 'branch_id' => $this->branchId, 'ip_address' => '127.0.0.1', 'port' => 9813]);
        foreach ([$this->t1, $this->t2] as $term) {
            DB::table('category_printer_mappings')->insert(['branch_id' => $this->branchId, 'terminal_id' => $term, 'category_id' => $this->cat, 'printer_id' => $printer,
                'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T12', 'status' => 'available']);
        $this->waiterId = $this->makeWaiter($this->branchId);
        $this->cashierId = $this->makeUser(['name' => 'Counter Cashier', 'default_branch_id' => $this->branchId, 'employee_code' => 'CC' . Str::random(4)]);
        $this->openerId = $this->makeUser(['name' => 'Morning Cashier', 'default_branch_id' => $this->branchId, 'employee_code' => 'MC' . Str::random(4)]);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline(array_map(fn ($p) => ['product_id' => $p, 'product_variant_id' => null, 'quantity' => 500], [$this->biryani, $this->raita, $this->naan]));
        $this->seedEdgeCredential($this->cashierId, $this->branchId, 1);
        config([
            'edge.authority.heartbeat_url' => 'https://cloud.continuity.test/api/edge/authority/heartbeat',   // lease mode (never called here)
            'edge.continuity.max_mirror_age_seconds' => 120,
        ]);
        Carbon::setTestNow(Carbon::now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function login(): User
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::on('tenant')->find($this->cashierId);
        $this->actingAs($user, 'tenant');
        Auth::shouldUse('tenant');

        return $user;
    }

    /**
     * The Cloud's live state as K1 serves it: T1 + T2 shifts open; table T12 with one order (Biryani ×2 FIRED, Raita ×1 not yet
     * sent); one held takeaway bill on T1; one held bill whose money is incoherent (never adoptable).
     */
    private function mirrorCloudState(): void
    {
        $this->pkg = [
            't1_shift' => (string) Str::ulid(), 't2_shift' => (string) Str::ulid(), 'session' => (string) Str::ulid(),
            'table_order' => (string) Str::ulid(), 'held' => (string) Str::ulid(), 'bad_held' => (string) Str::ulid(),
            'l_biryani' => (string) Str::ulid(), 'l_raita' => (string) Str::ulid(), 'l_held' => (string) Str::ulid(),
        ];
        $k = $this->pkg;
        $line = fn (string $uuid, int $product, string $qty, string $price, string $kot) => ['line_uuid' => $uuid, 'product_id' => $product, 'variant_id' => null,
            'qty' => $qty, 'unit_price' => $price, 'notes' => null, 'modifiers' => [], 'kot_state' => $kot, 'kot_batch_uuid' => $kot === 'fired' ? (string) Str::uuid() : null];
        app(LiveStateMirror::class)->apply([
            'revision' => 'rev-42', 'generated_at' => now()->toIso8601String(), 'branch_id' => $this->branchId,
            'shifts' => [
                ['shift_uuid' => $k['t1_shift'], 'terminal_id' => $this->t1, 'status' => 'open', 'opened_at' => now()->subHours(4)->toIso8601String(), 'opened_by_user_id' => $this->openerId,
                    'opening_cash' => '1500.00', 'business_date' => '2026-11-21', 'running' => ['cash_sales' => '4200.00', 'card_sales' => '0', 'refunds' => '0', 'cash_in' => '0', 'cash_out' => '0', 'expected_cash' => '5700.00']],
                ['shift_uuid' => $k['t2_shift'], 'terminal_id' => $this->t2, 'status' => 'open', 'opened_at' => now()->subHours(2)->toIso8601String(), 'opened_by_user_id' => 999999,
                    'opening_cash' => '500.00', 'running' => ['expected_cash' => '900.00']],
            ],
            'table_sessions' => [['session_uuid' => $k['session'], 'table_id' => $this->tableId, 'opened_at' => now()->subHour()->toIso8601String(), 'covers' => 4, 'waiter_user_id' => $this->waiterId,
                'orders' => [['order_uuid' => $k['table_order'], 'order_no' => 'SO-CLOUD-77', 'status' => 'open', 'shift_uuid' => $k['t2_shift'],
                    'subtotal' => '350.00', 'discount' => '0.00', 'tax' => '0.00', 'total' => '350.00', 'payments' => [],
                    'lines' => [$line($k['l_biryani'], $this->biryani, '2', '150.00', 'fired'), $line($k['l_raita'], $this->raita, '1', '50.00', 'pending')]]]]],
            'held_sales' => [
                ['order_uuid' => $k['held'], 'order_no' => 'SO-CLOUD-78', 'order_type' => 'takeaway', 'terminal_id' => $this->t1, 'shift_uuid' => $k['t1_shift'], 'total' => '150.00',
                    'lines' => [$line($k['l_held'], $this->biryani, '1', '150.00', 'fired')]],
                ['order_uuid' => $k['bad_held'], 'order_no' => 'SO-CLOUD-79', 'terminal_id' => $this->t1, 'total' => '999.00',
                    'lines' => [$line((string) Str::ulid(), $this->biryani, '1', '150.00', 'fired')]],
            ],
        ]);
    }

    /** The Cloud acknowledged the last heartbeat at $lastAckAgo seconds before the supervised takeover happening now. */
    private function switchToOffline(int $lastAckSecondsBeforeTakeover = 160): void
    {
        DB::table('edge_local_meta')->update(['authority_last_ack_at' => now(), 'authority_lease_ttl_seconds' => 120]);
        Carbon::setTestNow(now()->addSeconds($lastAckSecondsBeforeTakeover));
        DB::table('edge_local_meta')->update(['authority_state' => 'local_active', 'authority_takeover_at' => now()]);
        app()->forgetInstance(EdgeBranchContext::class);
    }

    private function adoption(): ContinuityAdoptionService
    {
        return app()->make(ContinuityAdoptionService::class);
    }

    private function counts(): array
    {
        return [
            'open_shifts' => DB::table('shifts')->where('status', 'open')->count(),
            'held' => SalesOrder::on('tenant')->where('status', 'held')->count(),
            'sessions' => DB::table('restaurant_table_sessions')->whereIn('status', ['open', 'bill_requested'])->count(),
            'lines' => DB::table('sales_order_lines')->count(),
            'adoptions' => DB::table('edge_mirror_adoptions')->count(),
        ];
    }

    public function test_the_first_touch_adopts_every_counter_shift_held_bills_and_tables_with_the_cloud_identities(): void
    {
        $this->mirrorCloudState();
        $this->switchToOffline();
        $this->login();

        // The cashier opens the POS on T1: the badge poll IS the first touch — the online shift is already continuing.
        $badge = $this->getJson('/edge/local/pos/shift?terminal_id=' . $this->t1)->assertOk();
        $this->assertTrue($badge->json('open'), 'no shift to open by hand');
        $this->assertStringStartsWith('Continuing online shift (mirrored ', (string) $badge->json('continuity.banner'));
        $this->assertSame($this->pkg['t1_shift'], $badge->json('continuity.cloud_shift_uuid'));
        $this->assertSame('2026-11-21', $badge->json('business_date'), 'one shift, one day: the online operating date is kept');

        $s1 = DB::table('shifts')->where('terminal_id', $this->t1)->where('status', 'open')->first();
        $s2 = DB::table('shifts')->where('terminal_id', $this->t2)->where('status', 'open')->first();
        $this->assertSame('5700.00', (string) $s1->opening_cash, 'opening cash = the mirrored running expected cash');
        $this->assertSame('5700.00', (string) $s1->expected_cash);
        $this->assertSame($this->openerId, (int) $s1->opened_by_user_id, 'the online opener stays the opener');
        $this->assertSame($this->pkg['t2_shift'], $s2->continues_cloud_shift_uuid, 'EVERY counter continues, not only the one that touched');
        $this->assertSame('900.00', (string) $s2->opening_cash);
        $this->assertSame($this->cashierId, (int) $s2->opened_by_user_id, 'an opener unknown on the appliance → the operator who triggered the adoption');

        // Held bill: same identity, same money, on its counter's continuation; the incoherent one is NOT adopted.
        $held = SalesOrder::on('tenant')->where('sale_uuid', $this->pkg['held'])->firstOrFail();
        $this->assertSame(['SO-CLOUD-78', 'held', 'takeaway', (int) $s1->id, '150.00', 'pending'], [$held->sale_no, $held->status, $held->order_type, (int) $held->shift_id, (string) $held->grand_total, $held->edge_sync_state]);
        $this->assertSame($this->pkg['held'], $held->adopted_from_order_uuid);
        $this->assertSame('rev-42', $held->adopted_mirror_revision);
        $this->assertFalse(SalesOrder::on('tenant')->where('sale_uuid', $this->pkg['bad_held'])->exists());
        $this->assertSame('MONEY_INCOHERENT', DB::table('edge_mirror_adoptions')->where('source_uuid', $this->pkg['bad_held'])->value('reason'));

        // Table: the SAME session identity, occupied; its order on the T2 continuation (the order's own online shift).
        $session = DB::table('restaurant_table_sessions')->where('session_uuid', $this->pkg['session'])->first();
        $this->assertSame(['open', 4, $this->waiterId, $this->pkg['session']], [$session->status, (int) $session->guest_count, (int) $session->restaurant_waiter_id, $session->adopted_from_session_uuid]);
        $this->assertSame('occupied', DB::table('restaurant_tables')->where('id', $this->tableId)->value('status'));
        $order = SalesOrder::on('tenant')->with('lines')->where('sale_uuid', $this->pkg['table_order'])->firstOrFail();
        $this->assertSame(['dine_in', (int) $session->id, (int) $s2->id, (int) $this->t2, '350.00'], [$order->order_type, (int) $order->restaurant_table_session_id, (int) $order->shift_id, (int) $order->terminal_id, (string) $order->grand_total]);
        $byUuid = $order->lines->keyBy('line_uuid');
        $this->assertSame([2.0, true], [(float) $byUuid[$this->pkg['l_biryani']]->kot_sent_quantity, (bool) $byUuid[$this->pkg['l_biryani']]->kot_sent], 'fired on the Cloud = sent here');
        $this->assertSame([0.0, false], [(float) $byUuid[$this->pkg['l_raita']]->kot_sent_quantity, (bool) $byUuid[$this->pkg['l_raita']]->kot_sent], 'not yet sent = fires normally');

        // The held list carries the "mirrored draft" badge data for adopted orders (and only for them).
        $list = $this->getJson('/edge/local/pos/held-sales')->assertOk()->json('sales');
        $adoptedRow = collect($list)->firstWhere('sale_no', 'SO-CLOUD-78');
        $this->assertStringStartsWith('Mirrored draft · up to ', (string) $adoptedRow['mirrored']['badge']);

        // Nothing financial happened: no payment, no outbox row, no stock movement, no KOT batch.
        $this->assertSame(0, DB::table('sale_payments')->count());
        $this->assertSame(0, DB::table('edge_sync_outbox')->count());
        $this->assertSame(0, DB::table('kot_batches')->count());
        $this->assertSame(0, DB::table('edge_operational_stock_movements')->count());

        // Every later touch is the fast path: nothing new.
        $before = $this->counts();
        $this->getJson('/edge/local/pos/shift?terminal_id=' . $this->t2)->assertOk()->assertJsonPath('open', true);
        $this->getJson('/edge/local/pos/held-sales')->assertOk();
        $this->assertSame('already', $this->adoption()->ensureAdopted(User::on('tenant')->find($this->cashierId), $this->t1)['status']);
        $this->assertSame($before, $this->counts());
        $this->assertSame(['open_shifts' => 2, 'held' => 2, 'sessions' => 1, 'lines' => 3, 'adoptions' => 6], $before);
    }

    public function test_adoption_is_idempotent_even_with_the_fast_path_bypassed_and_the_unique_guards_hold(): void
    {
        $this->mirrorCloudState();
        $this->switchToOffline();
        $user = User::on('tenant')->find($this->cashierId);
        $first = $this->adoption()->ensureAdopted($user, $this->t1);
        $this->assertSame(['adopted', 2, 1, 1, 1], [$first['status'], $first['shifts'], $first['held_sales'], $first['table_sessions'], $first['table_orders']]);
        $before = $this->counts();

        // A crashed process after the item commits but before the segment flag: re-running adopts nothing twice.
        DB::table('edge_mirror_meta')->update(['adopted_segment' => null]);
        $again = $this->adoption()->ensureAdopted($user, $this->t2);
        $this->assertSame(['adopted', 0, 0, 0], [$again['status'], $again['shifts'], $again['held_sales'], $again['table_sessions']]);
        $this->assertSame($before, $this->counts());

        // The database guards, independently of the service: one continuation per counter + Cloud shift + segment; one Edge
        // row per Cloud order identity.
        $s1 = (array) DB::table('shifts')->where('terminal_id', $this->t1)->where('status', 'open')->first();
        try {
            DB::table('shifts')->insert(array_merge(array_diff_key($s1, ['id' => 1]), ['shift_uuid' => (string) Str::ulid(), 'status' => 'closed']));
            $this->fail('a second continuation of the same online shift in the same segment must be impossible');
        } catch (QueryException $e) {
            $this->assertStringContainsString('shifts_continuation_unique', $e->getMessage());
        }
        $held = (array) DB::table('sales_orders')->where('sale_uuid', $this->pkg['held'])->first();
        try {
            DB::table('sales_orders')->insert(array_merge(array_diff_key($held, ['id' => 1]), ['client_uuid' => (string) Str::uuid(), 'sale_no' => 'X-' . Str::random(6)]));
            $this->fail('a second Edge row for one Cloud order must be impossible');
        } catch (QueryException $e) {
            $this->assertSame(1062, (int) ($e->errorInfo[1] ?? 0));
        }
    }

    public function test_two_counters_touching_the_pos_at_the_same_instant_adopt_exactly_once(): void
    {
        $this->mirrorCloudState();
        $this->switchToOffline();
        $locker = $this->independentTenantPdo();
        $locker->beginTransaction();
        $locker->query('SELECT id FROM edge_mirror_meta WHERE singleton_guard = 1 FOR UPDATE')->fetchAll();

        $env = array_merge(getenv() ?: [], ['EDGE_TEST_TENANT_DB' => $this->tenantDb, 'APP_ENV' => 'testing']);
        $procs = $pipes = [];
        foreach ([$this->t1, $this->t2] as $i => $tid) {
            $procs[$i] = proc_open([PHP_BINARY, base_path('tests/MySql/Support/edge_continuity_adopt_worker.php'), (string) $this->cashierId, (string) $tid],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path(), $env);
        }
        $watcher = $this->independentTenantPdo();
        $waiting = $watcher->prepare("SELECT COUNT(*) FROM information_schema.innodb_trx t JOIN information_schema.processlist p ON p.id = t.trx_mysql_thread_id WHERE t.trx_state = 'LOCK WAIT' AND p.db = ?");
        $queued = 0;
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline && $queued < 2) {
            $waiting->execute([$this->tenantDb]);
            $queued = max($queued, (int) $waiting->fetchColumn());
            usleep(100_000);
        }
        $locker->commit();
        $out = [];
        foreach (array_keys($procs) as $i) {
            $out[] = trim(stream_get_contents($pipes[$i][1]) . ' ' . stream_get_contents($pipes[$i][2]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($procs[$i]);
        }
        $this->assertSame(2, $queued, 'both counters were in flight at the same moment: ' . json_encode($out));
        sort($out);
        $this->assertSame(['RESULT:adopted', 'RESULT:already'], $out);
        $this->assertSame(1, DB::table('shifts')->where('terminal_id', $this->t1)->count());
        $this->assertSame(1, DB::table('shifts')->where('terminal_id', $this->t2)->count());
        $this->assertSame(1, DB::table('sales_orders')->where('sale_uuid', $this->pkg['held'])->count());
        $this->assertSame(1, DB::table('restaurant_table_sessions')->where('session_uuid', $this->pkg['session'])->count());
    }

    public function test_a_stale_mirror_fails_closed_and_the_cashier_gets_the_reason_and_the_normal_open_shift_flow(): void
    {
        $this->mirrorCloudState();
        // The mirror was last refreshed 5 minutes BEFORE the last acknowledged heartbeat (polling broke while the beat lived on).
        Carbon::setTestNow(now()->addSeconds(300));
        $this->switchToOffline();
        $this->login();

        $badge = $this->getJson('/edge/local/pos/shift?terminal_id=' . $this->t1)->assertOk();
        $this->assertFalse($badge->json('open'), 'nothing adopted: the counter sees "No open shift"');
        $this->assertSame(ContinuityAdoptionService::STATUS_REFUSED_STALE, $badge->json('continuity.status'));
        $this->assertStringContainsString('300 s older than the last contact with the Online service (limit 120 s)', (string) $badge->json('continuity.banner'));
        $this->assertSame(['open_shifts' => 0, 'held' => 0, 'sessions' => 0, 'lines' => 0, 'adoptions' => 0], $this->counts());

        // The decision is final for this segment (the mirror cannot become fresher while the appliance is the writer).
        $this->assertSame('already', $this->adoption()->ensureAdopted(User::on('tenant')->find($this->cashierId), $this->t1)['status']);
        // …and the normal flow works: the cashier opens a shift by hand.
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 1000, 'terminal_id' => $this->t1])->assertStatus(201);
        $open = $this->getJson('/edge/local/pos/shift?terminal_id=' . $this->t1)->assertOk();
        $this->assertTrue($open->json('open'));
        $this->assertNull($open->json('continuity'), 'a hand-opened shift carries no continuation');
        $this->assertNull(DB::table('shifts')->where('terminal_id', $this->t1)->value('continues_cloud_shift_uuid'));
    }

    public function test_an_untrusted_mirror_fails_closed(): void
    {
        $this->mirrorCloudState();
        // A later refresh was refused for integrity (the Cloud answered unchanged for a revision we do not hold).
        DB::table('edge_mirror_meta')->update(['last_attempt_at' => now()->addSeconds(5), 'last_error' => 'LIVE_STATE_REVISION_MISMATCH: …']);
        $this->switchToOffline();
        $r = $this->adoption()->ensureAdopted(User::on('tenant')->find($this->cashierId), $this->t1);
        $this->assertSame(ContinuityAdoptionService::STATUS_REFUSED_UNTRUSTED, $r['status']);
        $this->assertStringContainsString('LIVE_STATE_REVISION_MISMATCH', $r['reason']);
        $this->assertSame(0, DB::table('shifts')->count());

        // A plain transport failure during the outage does NOT taint the mirror (that is what an outage looks like).
        $this->assertNull($this->adoption()->freshnessRefusal(
            (object) ['mirrored_at' => now(), 'revision' => 'r', 'branch_id' => $this->branchId, 'last_error' => 'LIVE_STATE_UNREACHABLE: x', 'last_attempt_at' => now(), 'last_success_at' => now()->subSecond()],
            (object) ['branch_id' => $this->branchId, 'authority_last_ack_at' => now()]
        ));
    }

    public function test_a_counter_that_opened_by_hand_is_never_doubled_and_fired_lines_never_reprint(): void
    {
        $this->mirrorCloudState();
        $this->switchToOffline();
        $user = $this->login();
        // T1's cashier opened a fresh drawer by hand before anything touched the POS (pre-0.9 habit).
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->t1])->assertOk();
        $handOpened = app(\App\Services\Sales\ShiftService::class)->open(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId), \App\Models\Tenant\Terminal::on('tenant')->find($this->t1), $this->cashierId, 200.0);
        $r = $this->adoption()->ensureAdopted($user, $this->t1);
        $this->assertSame(1, $r['shifts'], 'only T2 continues');
        $this->assertContains(['kind' => 'shift', 'uuid' => $this->pkg['t1_shift'], 'reason' => 'TERMINAL_ALREADY_OPEN'], $r['skipped']);
        $this->assertSame(1, DB::table('shifts')->where('terminal_id', $this->t1)->where('status', 'open')->count());
        $this->assertSame((int) $handOpened->id, (int) SalesOrder::on('tenant')->where('sale_uuid', $this->pkg['held'])->value('shift_id'), 'the held bill joins the counter\'s open shift');

        // Add Round on the adopted table order (+ Naan) and send the KOT: only Raita (never sent) and Naan (new) fire.
        $order = SalesOrder::on('tenant')->with('lines')->where('sale_uuid', $this->pkg['table_order'])->firstOrFail();
        $lines = $order->lines->keyBy('line_uuid');
        $pos = app(EdgeLocalPosService::class);
        $revised = $pos->holdOrReviseSale([
            'held_sale_id' => $order->id, 'restaurant_table_session_id' => $order->restaurant_table_session_id, 'order_type' => 'dine_in',
            'lines' => [
                ['sales_order_line_id' => $lines[$this->pkg['l_biryani']]->id, 'product_id' => $this->biryani, 'quantity' => 2],
                ['sales_order_line_id' => $lines[$this->pkg['l_raita']]->id, 'product_id' => $this->raita, 'quantity' => 1],
                ['product_id' => $this->naan, 'quantity' => 1],
            ],
        ], $user, $this->t2);
        $pos->queueKotEvents((int) $revised->id, $user, $this->t2);
        $fired = DB::table('kot_batch_lines')->join('kot_batches', 'kot_batches.id', '=', 'kot_batch_lines.kot_batch_id')
            ->where('kot_batches.sales_order_id', $revised->id)->pluck('kot_batch_lines.quantity', 'kot_batch_lines.product_id')->map(fn ($q) => (float) $q)->all();
        ksort($fired);
        $expected = [$this->raita => 1.0, $this->naan => 1.0];
        ksort($expected);
        $this->assertSame($expected, $fired, 'the fired Biryani is NOT re-printed; the unsent Raita and the new Naan fire');
        $this->assertSame($this->pkg['table_order'], SalesOrder::on('tenant')->find($revised->id)->adopted_from_order_uuid, 'the adoption survives an Add Round');
    }

    public function test_untouched_adopted_drafts_are_released_before_handback_and_touched_ones_stay(): void
    {
        $this->mirrorCloudState();
        $this->switchToOffline();
        $user = $this->login();
        $this->adoption()->ensureAdopted($user, $this->t1);
        // The cashier touched the table order (Add Round); the held takeaway bill stayed untouched.
        $order = SalesOrder::on('tenant')->with('lines')->where('sale_uuid', $this->pkg['table_order'])->firstOrFail();
        app(EdgeLocalPosService::class)->holdOrReviseSale([
            'held_sale_id' => $order->id, 'restaurant_table_session_id' => $order->restaurant_table_session_id, 'order_type' => 'dine_in',
            'lines' => array_merge($order->lines->map(fn ($l) => ['sales_order_line_id' => $l->id, 'product_id' => $l->product_id, 'quantity' => (float) $l->quantity])->all(), [['product_id' => $this->naan, 'quantity' => 2]]),
        ], $user, $this->t2);

        $r = $this->adoption()->releaseUntouched();
        $this->assertSame(['released_orders' => 1, 'released_sessions' => 0, 'kept' => 2], $r, 'touched table order + its session stay');
        $this->assertFalse(SalesOrder::on('tenant')->where('sale_uuid', $this->pkg['held'])->exists(), 'the untouched bill never became an Edge fact');
        $this->assertTrue(SalesOrder::on('tenant')->where('sale_uuid', $this->pkg['table_order'])->where('status', 'held')->exists());
        $this->assertSame('released', DB::table('edge_mirror_adoptions')->where('source_uuid', $this->pkg['held'])->value('status'));
        $this->assertSame(0, DB::table('edge_sync_outbox')->count());
    }
}
