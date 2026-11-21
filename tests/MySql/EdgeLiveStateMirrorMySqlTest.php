<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Services\Edge\Continuity\LiveStateMirror;
use App\Services\Edge\EdgeAuthorityTick;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE 0.9 CONTINUITY (stream A) — the appliance's READ-ONLY live-state mirror (contract K1) on the real Edge-local schema.
 *
 *  - a package REPLACES the whole mirror atomically (replace-by-revision) with one revision + mirrored_at on every row;
 *  - adoptability is decided at mirror time (paid-in-part, deal lines, incoherent money → not adoptable, with the reason);
 *  - wrong branch / malformed / out-of-order / arriving while the appliance is the writer → REFUSED, the previous mirror intact;
 *  - the authority tick's standby hook polls it (rate-limited, never throwing) and a failing live-state endpoint changes
 *    NOTHING about the heartbeat, the lease or the authority state.
 */
class EdgeLiveStateMirrorMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const MIRROR_TABLES = ['edge_mirror_adoptions', 'edge_mirror_order_lines', 'edge_mirror_held_sales', 'edge_mirror_table_orders', 'edge_mirror_table_sessions', 'edge_mirror_shifts', 'edge_mirror_meta'];

    private int $branchId;
    private int $terminalId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(array_merge(self::MIRROR_TABLES, ['edge_local_connection_transitions', 'edge_local_user_credentials', 'edge_local_meta', 'products', 'categories', 'terminals', 'branches', 'users']));
        $this->branchId = $this->makeBranch(['name' => 'Mirror Branch']);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['name' => 'Biryani']);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'mirror-device');
        $this->asBranchServerRuntime();
        config([
            'edge.standby.config_refresh_url' => 'https://cloud.mirror.test/api/edge/config/refresh',
            'edge.continuity.live_state_url' => null,
            'edge.continuity.poll_seconds' => 30,
            'edge.sync.device_id' => 'mirror-device', 'edge.sync.device_secret' => 'mirror-secret',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function mirror(): LiveStateMirror
    {
        return app()->make(LiveStateMirror::class);
    }

    /** A K1 package: one open shift, one table (two orders: an adoptable one + a paid-in-part one), three held bills. */
    public function package(string $revision, array $over = []): array
    {
        $line = fn (string $uuid, $qty = '2', $price = '150.00', array $extra = []) => array_merge(['line_uuid' => $uuid, 'product_id' => $this->productId,
            'variant_id' => null, 'qty' => $qty, 'unit_price' => $price, 'notes' => 'less spicy', 'modifiers' => [], 'kot_state' => 'fired', 'kot_batch_uuid' => (string) Str::uuid()], $extra);

        return array_merge([
            'revision' => $revision, 'generated_at' => now()->toIso8601String(), 'branch_id' => $this->branchId,
            'shifts' => [['shift_uuid' => (string) Str::ulid(), 'terminal_id' => $this->terminalId, 'status' => 'open', 'opened_at' => now()->subHours(3)->toIso8601String(),
                'opened_by_user_id' => 5, 'opening_cash' => '1500.00', 'business_date' => now()->toDateString(),
                'running' => ['cash_sales' => '4200.00', 'card_sales' => '0.00', 'refunds' => '0.00', 'cash_in' => '0.00', 'cash_out' => '0.00', 'expected_cash' => '5700.00']]],
            'table_sessions' => [['session_uuid' => (string) Str::ulid(), 'table_id' => 12, 'opened_at' => now()->subHour()->toIso8601String(), 'covers' => 4, 'waiter_user_id' => 3,
                'orders' => [
                    ['order_uuid' => (string) Str::ulid(), 'order_no' => 'SO-1001', 'status' => 'open', 'shift_uuid' => null, 'subtotal' => '300.00', 'discount' => '0.00', 'tax' => '0.00', 'total' => '300.00',
                        'lines' => [$line((string) Str::ulid())], 'payments' => []],
                    ['order_uuid' => (string) Str::ulid(), 'order_no' => 'SO-1002', 'status' => 'open', 'subtotal' => '150.00', 'discount' => '0.00', 'tax' => '0.00', 'total' => '150.00',
                        'lines' => [$line((string) Str::ulid(), '1')], 'payments' => [['payment_uuid' => (string) Str::ulid(), 'method' => 'cash', 'amount' => '50.00']]],
                ]]],
            'held_sales' => [
                ['order_uuid' => (string) Str::ulid(), 'order_no' => 'SO-1003', 'held_at' => now()->toIso8601String(), 'terminal_id' => $this->terminalId, 'customer_id' => null, 'total' => '300.00',
                    'lines' => [$line((string) Str::ulid())]],
                ['order_uuid' => (string) Str::ulid(), 'order_no' => 'SO-1004', 'terminal_id' => $this->terminalId, 'total' => '999.00',          // incoherent money
                    'lines' => [$line((string) Str::ulid())]],
                ['order_uuid' => (string) Str::ulid(), 'order_no' => 'SO-1005', 'terminal_id' => $this->terminalId, 'total' => '300.00',          // a deal row
                    'lines' => [$line((string) Str::ulid(), '2', '150.00', ['line_kind' => 'combo_header', 'combo_id' => 4])]],
            ],
        ], $over);
    }

    private function counts(): array
    {
        $c = DB::connection('tenant');

        return ['shifts' => $c->table('edge_mirror_shifts')->count(), 'sessions' => $c->table('edge_mirror_table_sessions')->count(),
            'orders' => $c->table('edge_mirror_table_orders')->count(), 'held' => $c->table('edge_mirror_held_sales')->count(), 'lines' => $c->table('edge_mirror_order_lines')->count()];
    }

    public function test_a_package_replaces_the_whole_mirror_atomically_and_decides_adoptability(): void
    {
        $first = $this->package('rev-1');
        $r = $this->mirror()->apply($first);
        $this->assertSame(['revision' => 'rev-1', 'shifts' => 1, 'table_sessions' => 1, 'table_orders' => 2, 'held_sales' => 3, 'not_adoptable' => 3], $r);
        $this->assertSame(['shifts' => 1, 'sessions' => 1, 'orders' => 2, 'held' => 3, 'lines' => 5], $this->counts());
        $c = DB::connection('tenant');
        $this->assertSame(1, $c->table('edge_mirror_order_lines')->distinct()->count('revision'), 'one revision on every row');
        $this->assertSame('5700.00', (string) $c->table('edge_mirror_shifts')->value('expected_cash'));
        $reasons = $c->table('edge_mirror_held_sales')->orderBy('order_no')->pluck('not_adoptable_reason', 'order_no')->all();
        $this->assertSame(['SO-1003' => null, 'SO-1004' => 'MONEY_INCOHERENT', 'SO-1005' => 'DEAL_LINES_NOT_MIRRORED'], $reasons);
        $this->assertSame('PAID_IN_PART_ONLINE', $c->table('edge_mirror_table_orders')->where('order_no', 'SO-1002')->value('not_adoptable_reason'));
        $this->assertSame(1, (int) $c->table('edge_mirror_table_orders')->where('order_no', 'SO-1001')->value('adoptable'));
        $this->assertSame(64, strlen((string) $c->table('edge_mirror_table_orders')->where('order_no', 'SO-1001')->value('fingerprint')));
        $meta = $c->table('edge_mirror_meta')->first();
        $this->assertSame('rev-1', $meta->revision);
        $this->assertSame([1, 1, 3], [(int) $meta->shifts_count, (int) $meta->table_sessions_count, (int) $meta->held_sales_count]);

        // The next revision REPLACES the set: the closed table and settled bills disappear, nothing of rev-1 remains.
        Carbon::setTestNow(now()->addSeconds(40));
        $second = $this->package('rev-2', ['table_sessions' => [], 'held_sales' => [$first['held_sales'][0]]]);
        $this->mirror()->apply($second);
        $this->assertSame(['shifts' => 1, 'sessions' => 0, 'orders' => 0, 'held' => 1, 'lines' => 1], $this->counts());
        $this->assertSame(0, $c->table('edge_mirror_shifts')->where('revision', 'rev-1')->count() + $c->table('edge_mirror_order_lines')->where('revision', 'rev-1')->count());
        $this->assertSame('rev-2', $c->table('edge_mirror_meta')->value('revision'));
        $this->assertTrue($this->mirror()->status()['available']);
        $this->assertSame(1, $this->mirror()->status()['held_sales']);
    }

    public function test_refused_packages_leave_the_previous_mirror_intact(): void
    {
        $this->mirror()->apply($this->package('rev-1'));
        $before = $this->counts();
        $bad = [
            'LIVE_STATE_WRONG_BRANCH' => $this->package('rev-x', ['branch_id' => $this->branchId + 99]),
            'LIVE_STATE_MALFORMED' => $this->package('rev-x', ['shifts' => [['shift_uuid' => 'not-a-ulid', 'terminal_id' => 1]]]),
            'LIVE_STATE_OUT_OF_ORDER' => $this->package('rev-0', ['generated_at' => now()->subMinutes(10)->toIso8601String()]),
        ];
        $dupLine = $this->package('rev-x');
        $dupLine['held_sales'][0]['lines'][] = $dupLine['held_sales'][0]['lines'][0];
        $bad['LIVE_STATE_MALFORMED: duplicate'] = $dupLine;
        foreach ($bad as $code => $pkg) {
            try {
                $this->mirror()->apply($pkg);
                $this->fail("{$code} expected");
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith($code, $e->getMessage());
            }
            $this->assertSame($before, $this->counts(), "{$code}: nothing half-applied");
            $this->assertSame('rev-1', DB::connection('tenant')->table('edge_mirror_meta')->value('revision'));
        }

        // The writer's mirror is FROZEN: once the appliance is local_active a package is refused (adoption reads a fixed set).
        DB::connection('tenant')->table('edge_local_meta')->update(['authority_state' => 'local_active', 'authority_takeover_at' => now()]);
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);
        try {
            $this->mirror()->apply($this->package('rev-2'));
            $this->fail('a local_active appliance must refuse a mirror package');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('LIVE_STATE_NOT_STANDBY', $e->getMessage());
        }
        $this->assertSame('rev-1', DB::connection('tenant')->table('edge_mirror_meta')->value('revision'));
        $this->assertSame('skipped:not-standby', $this->mirror()->refreshIfDue());
    }

    public function test_refresh_if_due_polls_with_since_is_rate_limited_and_never_throws(): void
    {
        $answers = [];
        $requests = [];
        Http::fake(function (ClientRequest $r) use (&$answers, &$requests) {
            $requests[] = $r->url();

            return array_shift($answers);
        });
        $pkg = $this->package('rev-7');
        $answers[] = Http::response($pkg);
        $this->assertSame('applied:rev-7', $this->mirror()->refreshIfDue());
        $this->assertSame('https://cloud.mirror.test/api/edge/live-state', $requests[0]);
        $this->assertSame('skipped:not-due', $this->mirror()->refreshIfDue(), 'rate-limited (poll_seconds)');
        $this->assertCount(1, $requests);

        Carbon::setTestNow(now()->addSeconds(31));
        $answers[] = Http::response(['unchanged' => true, 'revision' => 'rev-7']);
        $this->assertSame('unchanged:rev-7', $this->mirror()->refreshIfDue());
        $this->assertSame('https://cloud.mirror.test/api/edge/live-state?since=rev-7', $requests[1]);
        $this->assertSame(now()->format('Y-m-d H:i:s'), Carbon::parse(DB::connection('tenant')->table('edge_mirror_meta')->value('mirrored_at'))->format('Y-m-d H:i:s'), 'unchanged confirms the mirror as current');

        Carbon::setTestNow(now()->addSeconds(31));
        $answers[] = Http::response(['status' => 'error'], 500);
        $out = $this->mirror()->refreshIfDue();
        $this->assertStringStartsWith('error:LIVE_STATE_REFUSED', $out);
        $meta = DB::connection('tenant')->table('edge_mirror_meta')->first();
        $this->assertStringStartsWith('LIVE_STATE_REFUSED', (string) $meta->last_error);
        $this->assertSame('rev-7', $meta->revision, 'a failed refresh keeps the last good mirror');
        $this->assertSame(['shifts' => 1, 'sessions' => 1, 'orders' => 2, 'held' => 3, 'lines' => 5], $this->counts());

        config(['edge.standby.config_refresh_url' => null]);
        $this->assertSame('skipped:not-provisioned', $this->mirror()->refreshIfDue());
    }

    public function test_the_tick_standby_hook_never_changes_heartbeat_lease_or_authority(): void
    {
        config([
            'edge.authority.heartbeat_url' => 'https://cloud.mirror.test/api/edge/authority/heartbeat',
            'edge.authority.handback_url' => 'https://cloud.mirror.test/api/edge/authority/handback',
            'edge.sync.baseline_url' => 'https://cloud.mirror.test/api/edge/sync/baseline',
            'edge.sync.connect_timeout' => 1, 'edge.sync.timeout' => 2,
        ]);
        $mode = 'ok';
        $pkg = $this->package('rev-9');
        Http::fake(function (ClientRequest $r) use (&$mode, $pkg) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/authority/heartbeat')) {
                return Http::response(['status' => 'ok', 'holder' => 'cloud', 'edge_state' => 'standby', 'seq' => (int) $r['seq'], 'lease_ttl_seconds' => 120]);
            }
            if (str_ends_with($path, '/live-state')) {
                if ($mode === 'down') {
                    throw new \Illuminate\Http\Client\ConnectionException('simulated WAN failure on live-state');
                }

                return $mode === 'ok' ? Http::response($pkg) : Http::response(['message' => 'Not Found'], 404);
            }

            return Http::response(['status' => 'error'], 503);   // baseline / refresh endpoints: not this test's concern
        });

        $t = app()->make(EdgeAuthorityTick::class)->run('test-worker');
        $this->assertTrue($t['heartbeat']['ok']);
        $this->assertSame('applied:rev-9', $t['work']['live_state']);
        $this->assertSame('standby_freshness', $t['work']['kind']);
        $snapshot = fn () => collect((array) DB::connection('tenant')->table('edge_local_meta')->first())
            ->only(['authority_state', 'authority_heartbeat_seq', 'authority_last_ack_at', 'authority_lease_ttl_seconds', 'heartbeat_consecutive_failures', 'heartbeat_consecutive_acks', 'authority_takeover_at', 'connection_state'])->all();

        // A live-state endpoint that fails (or is absent on an older Cloud) is just a string in the report — and the ONLY
        // difference between a tick with it failing and one with it working is that string.
        Carbon::setTestNow(now()->addSeconds(31));
        $mode = '404';
        $a = app()->make(EdgeAuthorityTick::class)->run('test-worker');
        $afterFailing = $snapshot();
        $this->assertTrue($a['heartbeat']['ok']);
        $this->assertStringStartsWith('error:LIVE_STATE_REFUSED: HTTP 404', $a['work']['live_state']);
        $this->assertSame('standby', $afterFailing['authority_state']);
        $this->assertSame(0, (int) $afterFailing['heartbeat_consecutive_failures'], 'a failing mirror never counts as a heartbeat failure');
        $this->assertSame((int) $t['heartbeat']['seq'] + 1, (int) $afterFailing['authority_heartbeat_seq']);
        $this->assertSame('rev-9', DB::connection('tenant')->table('edge_mirror_meta')->value('revision'));

        // Same beat again with the endpoint throwing at the transport level: still only a string.
        Carbon::setTestNow(now()->addSeconds(31));
        $mode = 'down';
        $b = app()->make(EdgeAuthorityTick::class)->run('test-worker');
        $this->assertTrue($b['heartbeat']['ok']);
        $this->assertStringStartsWith('error:LIVE_STATE_UNREACHABLE', $b['work']['live_state']);
        $this->assertSame('standby', EdgeLocalMeta::on('tenant')->value('authority_state'));
        $this->assertSame(0, (int) EdgeLocalMeta::on('tenant')->value('heartbeat_consecutive_failures'));

        // LOCAL_ACTIVE: the hook does not run at all (the writer never pulls Cloud state).
        DB::connection('tenant')->table('edge_local_meta')->update(['authority_state' => 'local_active', 'authority_takeover_at' => now()]);
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);
        $c = app()->make(EdgeAuthorityTick::class)->run('test-worker');
        $this->assertArrayNotHasKey('live_state', $c['work']);
    }
}
