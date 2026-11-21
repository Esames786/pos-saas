<?php

namespace Tests\MySql;

use App\Http\Controllers\Edge\EdgeLocalAuthorityControlController;
use App\Models\Master\EdgeDevice;
use App\Models\Tenant\Branch;
use App\Services\Edge\AutoTakeover\AutoTakeoverEvaluator;
use App\Services\Edge\BranchOperatingModeService;
use App\Services\Edge\EdgeAuthorityLeaseService;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeApplianceHealthService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE 0.9 (stream B) — OPTIONAL AUTOMATIC OFFLINE TAKEOVER, Cloud + appliance in ONE process on the test DBs:
 *
 *   Cloud     = the REAL EdgeAuthorityLeaseService (lease, expiry on the Cloud clock) + BranchOperatingModeService (fence);
 *   Appliance = the REAL EdgeAuthorityService::heartbeat() whose HTTP call is routed (Http::fake) to that Cloud service while the
 *               "internet" is up and fails while it is down; the REAL gates + takeOver; AutoTakeoverEvaluator on top;
 *   Clock     = Carbon::setTestNow (both sides read it), so TTL / margin / sustained windows are exact.
 *
 * Proven: disabled (Cloud setting off, or appliance kill switch off) never acts; enabled + valid lease refuses (and the manual
 * path refuses too); enabled + expired lease + gates pass → the EXISTING takeOver runs (Cloud already fenced BEFORE that moment;
 * holder EDGE after the next beat); flapping resets the timer; unknown / large clock skew refuses; a held lock / repeated
 * evaluation → exactly one takeover; reconnect keeps EDGE (no automatic handback); a recent handback and the 60 s retry
 * window refuse. The setting itself is replicated from the Cloud (restrictions[0].auto_takeover in the bootstrap / refresh);
 * the appliance has no UI to enable it.
 */
class EdgeAutoTakeoverMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const DEVICE = 'auto-takeover-device-0001';
    private const HEARTBEAT_URL = 'https://cloud.test/api/edge/authority/heartbeat';

    private int $tenantId;
    private int $branchId;
    private bool $online = true;
    private int $cloudSkew = 0;
    private ?string $lastServerTime = null;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->cleanTenant(['edge_branch_authority_leases', 'edge_local_connection_transitions', 'edge_operational_stock_movements', 'edge_operational_stock_balances',
            'edge_operational_stock_baselines', 'edge_local_user_credentials', 'edge_local_meta', 'products', 'categories', 'terminals', 'branches', 'users']);
        config(['app.role' => 'branch_server']);
        Carbon::setTestNow(Carbon::now()->startOfSecond());

        $this->branchId = $this->makeBranch(['name' => 'Auto Takeover Branch']);
        $user = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'AT' . Str::random(4)]);
        $this->makeTerminal($this->branchId);
        $product = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1]);

        $m = DB::connection('master');
        $m->table('edge_devices')->where('public_uuid', self::DEVICE)->delete();
        $m->table('tenants')->where('tenant_code', 'edgeautotk')->delete();
        $this->tenantId = $m->table('tenants')->insertGetId(['tenant_code' => 'edgeautotk', 'business_name' => 'Auto Takeover', 'owner_name' => 'Owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $m->table('edge_devices')->insert([
            'public_uuid' => self::DEVICE, 'tenant_id' => $this->tenantId, 'branch_id' => $this->branchId, 'installation_uuid' => (string) Str::uuid(),
            'device_name' => 'auto-takeover-box', 'device_secret_hash' => hash('sha256', 'device-secret'), 'status' => 'active', 'active_slot' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->bindEdgeLocalMeta($this->branchId, 1, $this->tenantId, self::DEVICE);
        DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_state' => 'standby']);
        $this->acceptTestBaseline([['product_id' => $product, 'product_variant_id' => null, 'quantity' => 10]]);
        DB::connection('tenant')->table('edge_operational_stock_baselines')->where('status', 'accepted')->update(['stock_watermark' => 'sw:cloud-1', 'cloud_as_of' => now()]);
        $this->seedEdgeCredential($user, $this->branchId, 1);

        config([
            'edge.authority.heartbeat_url' => self::HEARTBEAT_URL,
            'edge.sync.device_id' => self::DEVICE,
            'edge.sync.device_secret' => 'device-secret',
            'edge.authority.ttl_seconds' => 60,
            'edge.authority.skew_margin_seconds' => 30,
            'edge.authority.auto_takeover_allowed' => true,
            'cache.default' => 'array',
        ]);
        $this->setCloudSetting(true, 180);

        // The wire: the appliance's heartbeat reaches the REAL Cloud lease service while online; fails while offline.
        Http::fake([self::HEARTBEAT_URL => function (HttpRequest $request) {
            if (! $this->online) {
                return Http::failedConnection('the shop internet is down');
            }
            $role = config('app.role');
            config(['app.role' => 'cloud']);
            try {
                $view = app(EdgeAuthorityLeaseService::class)->heartbeat(EdgeDevice::where('public_uuid', self::DEVICE)->firstOrFail(), (int) $request['seq'], (string) $request['edge_state']);
            } finally {
                config(['app.role' => $role]);
            }
            $view['server_time'] = Carbon::now()->addSeconds($this->cloudSkew)->toIso8601String();
            $this->lastServerTime = $view['server_time'];

            return Http::response(['status' => 'ok', 'cloud_config_revision' => 1, 'stock_watermark' => 'sw:cloud-1'] + $view);
        }]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        try {

            $m = DB::connection('master');
            $m->table('edge_devices')->where('public_uuid', self::DEVICE)->delete();
            $m->table('tenants')->where('tenant_code', 'edgeautotk')->delete();
        } catch (\Throwable) {
        }
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    /** What the bootstrap / config refresh delivers (stream C: restrictions[0].auto_takeover), through the importer hook. */
    private function setCloudSetting(bool $enabled, ?int $after = null): void
    {
        app(AutoTakeoverEvaluator::class)->recordCloudSetting([[
            'phase' => 'offline-v1', 'blocked_payment_types' => ['card'],
            'auto_takeover' => ['enabled' => $enabled, 'after_seconds' => $after ?? 300, 'set_by' => 7, 'set_at' => '2026-11-21T10:00:00+00:00'],
        ]]);
    }

    /** One appliance heartbeat through the REAL engine + the skew hook the lead wires after an accepted ack. */
    private function beat(): array
    {
        $r = app(EdgeAuthorityService::class)->heartbeat();
        if (($r['ok'] ?? false) && $this->lastServerTime !== null) {
            app(AutoTakeoverEvaluator::class)->recordCloudSkew($this->lastServerTime);
        }

        return $r;
    }

    private function at(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
    }

    private function cloudFenced(): bool
    {
        config(['app.role' => 'cloud']);
        try {
            app(BranchOperatingModeService::class)->assertSaleMutationAllowed(Branch::on('tenant')->findOrFail($this->branchId));

            return false;
        } catch (\App\Exceptions\BranchLocalEdgeException) {
            return true;
        } finally {
            config(['app.role' => 'branch_server']);
        }
    }

    private function state(): string
    {
        return (string) DB::connection('tenant')->table('edge_local_meta')->value('authority_state');
    }

    private function evaluator(): AutoTakeoverEvaluator
    {
        return app(AutoTakeoverEvaluator::class);
    }

    /** Online beat at t, then the internet goes down and the appliance misses beats every 20 s up to t+$seconds. */
    private function loseConnectionFor(int $seconds): void
    {
        $this->assertTrue($this->beat()['ok']);
        $this->online = false;
        for ($t = 20; $t <= $seconds; $t += 20) {
            $this->at(20);
            $this->assertFalse($this->beat()['ok']);
        }
        $rest = $seconds % 20;
        if ($rest > 0) {
            $this->at($rest);
        }
    }

    private function codes(array $d): array
    {
        return array_column($d['reasons'], 'code');
    }

    // ── the proofs ────────────────────────────────────────────────────────────────────────────

    public function test_disabled_never_acts_cloud_setting_or_appliance_kill_switch(): void
    {
        $this->setCloudSetting(false, 180);
        $this->loseConnectionFor(200);
        $d = $this->evaluator()->evaluateAndAct();
        $this->assertSame(AutoTakeoverEvaluator::DISABLED, $d['decision']);
        $this->assertContains('CLOUD_SETTING_OFF', $this->codes($d));
        $this->assertSame('standby', $this->state());

        // An older Cloud whose restrictions carry no auto_takeover key = OFF.
        $this->setCloudSetting(true, 180);
        $this->assertTrue($this->evaluator()->settings()['cloud_enabled']);
        $this->assertNull($this->evaluator()->recordCloudSetting([['phase' => 'offline-v1']]));
        $this->assertSame(AutoTakeoverEvaluator::DISABLED, $this->evaluator()->evaluate()['decision']);
        $this->setCloudSetting(true, 180);
        $this->assertSame(7, $this->evaluator()->settings()['set_by']);
        config(['edge.authority.auto_takeover_allowed' => false]);
        $d = $this->evaluator()->evaluateAndAct();
        $this->assertSame(AutoTakeoverEvaluator::DISABLED, $d['decision']);
        $this->assertSame(['APPLIANCE_NOT_ALLOWED'], $this->codes($d));
        $this->assertSame('standby', $this->state());
        $this->assertFalse(app(EdgeApplianceHealthService::class)->report()['auto_failover_enabled']);
    }

    public function test_enabled_with_a_valid_cloud_lease_refuses_like_the_manual_path(): void
    {
        $this->assertTrue($this->beat()['ok']);
        $this->at(10);
        $d = $this->evaluator()->evaluateAndAct();
        $this->assertSame(AutoTakeoverEvaluator::WAIT, $d['decision']);
        $this->assertContains('CONNECTION_NOT_LOST', $this->codes($d));
        $this->assertSame('standby', $this->state());
        $this->assertFalse($this->cloudFenced(), 'the Cloud still sells');
        // Even if the counters were forced, the engine's own gate keeps a valid lease safe (manual path refused the same way).
        DB::connection('tenant')->table('edge_local_meta')->update(['heartbeat_consecutive_failures' => 5]);
        $this->assertFalse(app(EdgeAuthorityService::class)->gates()['AUTHORITY_TAKEOVER_SAFE']);
        try {
            app(EdgeAuthorityService::class)->takeOver(true, 'manual', false, null);
            $this->fail('manual takeover must be refused while the lease is valid');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('AUTHORITY_TAKEOVER_SAFE', $e->getMessage());
        }
        $this->assertSame('standby', $this->state());
    }

    public function test_enabled_expired_lease_and_passing_gates_take_over_through_the_existing_engine(): void
    {
        $this->assertTrue(app(EdgeApplianceHealthService::class)->report()['auto_failover_enabled'], 'effective ON (Cloud setting AND kill switch)');
        $this->loseConnectionFor(61);
        $this->assertTrue($this->cloudFenced(), 'the Cloud fenced ITSELF at lease expiry — before any takeover');
        $this->assertSame('standby', $this->state());
        $this->at(39); // t+100: lease lapsed locally (60 + 30) but the sustained window (180 s) is not reached
        $d = $this->evaluator()->evaluateAndAct();
        $this->assertSame(AutoTakeoverEvaluator::WAIT, $d['decision']);
        $this->assertSame(['SUSTAINED_LOSS_NOT_REACHED'], $this->codes($d));

        for ($i = 0; $i < 4; $i++) {
            $this->at(20);
            $this->beat(); // still offline: failures keep counting
        }
        $this->at(1); // t+181
        $d = $this->evaluator()->evaluateAndAct();
        $this->assertSame(AutoTakeoverEvaluator::TAKE_OVER, $d['decision'], json_encode($d['reasons']));
        $this->assertSame('taken_over', $d['result'], (string) $d['message']);
        $this->assertSame('local_active', $this->state());
        $meta = DB::connection('tenant')->table('edge_local_meta')->first();
        $this->assertStringContainsString('auto-takeover', (string) $meta->authority_state_reason, 'the existing engine recorded who confirmed it');
        $this->assertSame('taken_over', $meta->auto_takeover_last_decision);

        // Reconnect: the next beat says local_active → the Cloud holder is EDGE; nothing hands back automatically.
        $this->online = true;
        $this->at(20);
        $r = $this->beat();
        $this->assertTrue($r['ok']);
        $this->assertSame('edge', $r['holder']);
        $this->assertTrue($this->cloudFenced(), 'the Cloud stays fenced while the Edge holds the branch');
        $this->at(60);
        $d = $this->evaluator()->evaluateAndAct();
        $this->assertSame(AutoTakeoverEvaluator::NOOP, $d['decision']);
        $this->assertSame('local_active', $this->state(), 'no automatic handback — the owner presses Return to Online');
    }

    public function test_flapping_resets_the_sustained_timer(): void
    {
        $this->loseConnectionFor(150);
        $this->online = true;
        $this->assertTrue($this->beat()['ok'], 'one beat gets through');
        $this->online = false;
        for ($i = 0; $i < 3; $i++) {
            $this->at(20);
            $this->beat();
        }
        $d = $this->evaluator()->evaluate();
        $this->assertSame(AutoTakeoverEvaluator::WAIT, $d['decision']);
        $this->assertContains('SUSTAINED_LOSS_NOT_REACHED', $this->codes($d), 'the window restarted at the last ack (60 s of 180 s)');
        $this->assertSame(60, $d['facts']['lost_for_seconds']);
    }

    public function test_unknown_or_large_clock_skew_refuses(): void
    {
        $this->loseConnectionFor(200);
        DB::connection('tenant')->table('edge_local_meta')->update(['authority_cloud_skew_seconds' => null]);
        $d = $this->evaluator()->evaluateAndAct();
        $this->assertSame(AutoTakeoverEvaluator::REFUSED, $d['decision']);
        $this->assertContains('CLOCK_SKEW_UNKNOWN', $this->codes($d));
        $this->assertSame('standby', $this->state());

        // The Cloud clock 30 s ahead of this PC (measured at the last ack).
        DB::connection('tenant')->table('edge_local_meta')->update(['authority_cloud_skew_seconds' => 30]);
        $d = $this->evaluator()->evaluateAndAct();
        $this->assertContains('CLOCK_SKEW_TOO_LARGE', $this->codes($d));
        $this->assertSame('standby', $this->state());

        // The measurement itself: server_time − local now at the ack.
        $this->assertSame(-12 * 3600, $this->evaluator()->recordCloudSkew(Carbon::now()->subHours(12)->toIso8601String()));
        $this->assertSame(-43200, (int) DB::connection('tenant')->table('edge_local_meta')->value('authority_cloud_skew_seconds'));
    }

    public function test_a_held_lock_or_repeated_attempts_take_over_once(): void
    {
        $this->loseConnectionFor(200);
        $lock = Cache::lock(EdgeLocalAuthorityControlController::LOCK, 30);
        $this->assertTrue($lock->get());
        try {
            $d = $this->evaluator()->evaluateAndAct();
            $this->assertSame('busy', $d['decision'], 'a Switch-to-Offline click in progress: the automatic path waits');
            $this->assertSame('standby', $this->state());
        } finally {
            $lock->release();
        }
        $this->assertSame('taken_over', $this->evaluator()->evaluateAndAct()['result']);
        $at = DB::connection('tenant')->table('edge_local_meta')->value('authority_takeover_at');
        $this->at(5);
        $again = $this->evaluator()->evaluateAndAct();
        $this->assertSame(AutoTakeoverEvaluator::NOOP, $again['decision']);
        $this->assertSame($at, DB::connection('tenant')->table('edge_local_meta')->value('authority_takeover_at'), 'exactly one takeover');
    }

    public function test_recent_handback_and_the_retry_window_refuse(): void
    {
        $this->loseConnectionFor(200);
        DB::connection('tenant')->table('edge_local_connection_transitions')->insert(['from_state' => 'handing_back', 'to_state' => 'online', 'authority_state' => 'standby', 'reason' => 'test', 'occurred_at' => Carbon::now()->subMinutes(5)]);
        $d = $this->evaluator()->evaluate();
        $this->assertContains('RECENT_HANDBACK', $this->codes($d));
        DB::connection('tenant')->table('edge_local_connection_transitions')->delete();

        DB::connection('tenant')->table('edge_local_meta')->update(['auto_takeover_last_attempt_at' => Carbon::now()->subSeconds(30)]);
        $d = $this->evaluator()->evaluateAndAct();
        $this->assertContains('RATE_LIMITED', $this->codes($d));
        $this->assertSame('standby', $this->state());
        $this->at(31);
        $this->assertSame('taken_over', $this->evaluator()->evaluateAndAct()['result']);
    }
}
