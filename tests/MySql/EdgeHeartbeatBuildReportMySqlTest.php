<?php

namespace Tests\MySql;

use App\Services\Edge\EdgeAuthorityLeaseService;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeCanonicalJson;
use App\Services\Edge\EdgeCompatibilityService;
use App\Services\Edge\EdgeSaleEnvelopeBuilder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W-F VERSION REPORTING — the heartbeat's informational `build` block, over REAL device-authenticated HTTP.
 *
 *  - the Cloud records the reported build on the master device row ONCE, writes nothing for an identical beat, and writes
 *    again only when the build changed (sha256 of the canonical block);
 *  - an old appliance (no block) is served exactly as before; an invalid block is dropped, never a refused beat;
 *  - a replayed or stale beat carrying a build is acknowledged/refused exactly as before — the lease row (holder,
 *    edge_state, seq, timestamps) is byte-identical and a refused beat records nothing;
 *  - every accepted beat advertises the Cloud's capabilities (customer_create = false for now);
 *  - end to end: the appliance's own heartbeat tick sends its build and the Cloud records it.
 */
class EdgeHeartbeatBuildReportMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const TENANT_CODE = 'edgebuildrep';

    private string $heartbeatUri;
    private string $secret = 'build-report-device-secret';
    private int $tenantId;
    private string $deviceUuid;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->heartbeatUri = 'http://' . config('tenancy.central_domain') . '/api/edge/authority/heartbeat';
        $this->deviceUuid = (string) Str::uuid();

        $m = DB::connection('master');
        $m->table('edge_devices')->where('public_uuid', $this->deviceUuid)->delete();
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => self::TENANT_CODE, 'business_name' => 'Edge Build Report', 'owner_name' => 'Owner',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c = config('database.connections.tenant');
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant', 'db_host' => $c['host'], 'db_port' => (int) $c['port'],
            'db_database' => $this->tenantDb, 'db_username' => $c['username'], 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::setDefaultConnection('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
        $this->cleanTenant(['edge_branch_authority_leases', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['name' => 'Build Report Branch']);
        DB::setDefaultConnection('master');

        $m->table('edge_devices')->insert([
            'public_uuid' => $this->deviceUuid, 'tenant_id' => $this->tenantId, 'branch_id' => $this->branchId,
            'installation_uuid' => (string) Str::uuid(), 'device_name' => 'build-box', 'device_secret_hash' => hash('sha256', $this->secret),
            'status' => 'active', 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['edge.authority.ttl_seconds' => 60]);
        // Every beat below reads this frozen instant (whole second) — a row that "did not change" is byte-identical.
        Carbon::setTestNow(Carbon::now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        config(['app.role' => 'cloud']);
        try {
            $m = DB::connection('master');
            $m->table('edge_devices')->where('public_uuid', $this->deviceUuid)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
            $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();
        } catch (\Throwable $e) {
        }
        parent::tearDown();
    }

    private function headers(): array
    {
        return ['X-Edge-Device-ID' => $this->deviceUuid, 'Authorization' => 'Bearer ' . $this->secret];
    }

    private function build(array $overrides = []): array
    {
        return array_merge([
            'edge_app_version' => '0.7.0-edge',
            'git_commit' => '599c5d0',
            'artifact_version' => '0.7.0-edge',
            'bootstrap_schema' => (string) config('edge.bootstrap_schema'),
            'config_schema' => (string) config('edge.config_schema'),
            'edge_schema_version' => 'edge-local-schema@2026_09_20_000001_example',
            'applied_edge_schema_version' => 'edge-local-schema@2026_09_20_000001_example',
            'envelope_versions' => [EdgeSaleEnvelopeBuilder::SCHEMA_VERSION, EdgeSaleEnvelopeBuilder::SCHEMA_VERSION_V2],
            'capabilities' => ['local_auth', 'local_pos_cash_sales', 'config_refresh'],
        ], $overrides);
    }

    private function beat(int $seq, string $state = 'standby', ?array $build = null, bool $withBuildKey = true)
    {
        $body = ['seq' => $seq, 'edge_state' => $state];
        if ($withBuildKey) {
            $body['build'] = $build;
        }

        return $this->postJson($this->heartbeatUri, $body, $this->headers());
    }

    private function deviceRow(): array
    {
        return (array) DB::connection('master')->table('edge_devices')->where('public_uuid', $this->deviceUuid)->first();
    }

    private function leaseRow(): array
    {
        return (array) DB::connection('tenant')->table('edge_branch_authority_leases')->where('branch_id', $this->branchId)->first();
    }

    private function later(int $seconds): void
    {
        Carbon::setTestNow(Carbon::getTestNow()->copy()->addSeconds($seconds));
    }

    public function test_build_is_recorded_once_then_only_when_it_changes(): void
    {
        $b1 = $this->build();
        $this->beat(1, 'standby', $b1)->assertOk()->assertJsonPath('holder', 'cloud');

        $row = $this->deviceRow();
        $expectedHash = EdgeCanonicalJson::hash(app(EdgeAuthorityLeaseService::class)->normalizeBuild($b1));
        $this->assertSame($expectedHash, $row['build_reported_hash']);
        $this->assertSame('0.7.0-edge', $row['app_version']);
        $this->assertSame((string) config('edge.bootstrap_schema'), $row['schema_version']);
        $this->assertNotNull($row['build_reported_at']);
        $this->assertNotNull($row['compatibility_reported_at']);
        $manifest = json_decode((string) $row['compatibility_manifest'], true);
        $this->assertSame('heartbeat', $manifest['reported_via']);
        $this->assertSame('599c5d0', $manifest['git_commit']);
        $this->assertSame([EdgeSaleEnvelopeBuilder::SCHEMA_VERSION, EdgeSaleEnvelopeBuilder::SCHEMA_VERSION_V2], $manifest['envelope_versions']);
        // The stored manifest also speaks the compatibility-report vocabulary: the Cloud classifier reads it as-is.
        $this->assertSame(EdgeCompatibilityService::COMPATIBLE, app(EdgeCompatibilityService::class)->classify($manifest)['overall']);

        // IDENTICAL build (keys in another order — the hash is over the CANONICAL block): no write at all.
        $this->later(5);
        $this->beat(2, 'standby', array_reverse($b1, true))->assertOk();
        $this->assertSame($row, $this->deviceRow(), 'an unchanged build writes nothing (updated_at, build_reported_at untouched)');

        // CHANGED build (the appliance was updated): recorded again, once.
        $this->later(5);
        $b2 = $this->build(['edge_app_version' => '0.8.0-edge', 'artifact_version' => '0.8.0-edge', 'applied_edge_schema_version' => 'edge-local-schema@2026_09_27_000009_next']);
        $this->beat(3, 'standby', $b2)->assertOk();
        $after = $this->deviceRow();
        $this->assertSame('0.8.0-edge', $after['app_version']);
        $this->assertNotSame($row['build_reported_hash'], $after['build_reported_hash']);
        $this->assertSame(Carbon::getTestNow()->format('Y-m-d H:i:s'), Carbon::parse($after['build_reported_at'])->format('Y-m-d H:i:s'));
        $this->assertNotSame($row['updated_at'], $after['updated_at']);
        $this->assertSame('edge-local-schema@2026_09_27_000009_next', json_decode((string) $after['compatibility_manifest'], true)['applied_edge_schema_version']);

        // …and the same updated build again is once more a no-op.
        $this->later(5);
        $this->beat(4, 'standby', $b2)->assertOk();
        $this->assertSame($after, $this->deviceRow());
    }

    public function test_an_old_appliance_without_build_is_served_exactly_as_before(): void
    {
        $before = $this->deviceRow();
        $res = $this->beat(1, 'standby', null, withBuildKey: false)->assertOk();
        $this->assertSame('cloud', $res->json('holder'));
        $this->assertFalse($res->json('fenced'));
        $this->assertSame(1, (int) $res->json('seq'));
        // The response is the pre-W-F contract plus the additive `capabilities` key — nothing removed or renamed.
        $this->assertSame([
            'status', 'branch_id', 'holder', 'edge_state', 'lease_ttl_seconds', 'expires_in_seconds', 'seq', 'server_time', 'fenced', 'released',
            'cloud_config_revision', 'cloud_config_watermark', 'stock_watermark', 'stock_as_of', 'returnable_watermark', 'returnable_as_of',
            'supplier_finance_watermark', 'supplier_finance_as_of', 'purchase_return_watermark', 'purchase_return_as_of', 'capabilities',
        ], array_keys($res->json()));

        // An explicit `build: null` is the same thing.
        $this->beat(2, 'standby', null)->assertOk()->assertJsonPath('holder', 'cloud')->assertJsonPath('seq', 2);

        $after = $this->deviceRow();
        foreach (['app_version', 'schema_version', 'compatibility_manifest', 'compatibility_reported_at', 'build_reported_hash', 'build_reported_at', 'updated_at'] as $col) {
            $this->assertSame($before[$col], $after[$col], "{$col} must be untouched by a beat without a build");
        }
        $this->assertSame(2, (int) $this->leaseRow()['heartbeat_seq']);
    }

    public function test_replayed_and_stale_beats_with_build_leave_authority_untouched(): void
    {
        $b1 = $this->build();
        $this->beat(1, 'standby', $b1)->assertOk()->assertJsonPath('holder', 'cloud');
        $this->beat(2, 'local_active', $b1)->assertOk()->assertJsonPath('holder', 'edge')->assertJsonPath('fenced', true);
        $lease = $this->leaseRow();
        $device = $this->deviceRow();

        // LOST ACK: the SAME sequence re-sent (with its build) is re-acknowledged idempotently — nothing extended, nothing moved.
        $this->later(5);
        $this->beat(2, 'local_active', $b1)->assertOk()->assertJsonPath('holder', 'edge')->assertJsonPath('edge_state', 'local_active')->assertJsonPath('seq', 2);
        $this->assertSame($lease, $this->leaseRow(), 'a replayed beat with a build extends nothing and moves nothing');
        $this->assertSame($device, $this->deviceRow(), 'an unchanged build is not re-written by a replay');

        // A replay that claims STANDBY cannot bounce authority either (the lease is untouched by the same-seq re-ack).
        $this->beat(2, 'standby', $b1)->assertOk()->assertJsonPath('holder', 'edge')->assertJsonPath('edge_state', 'local_active');
        $this->assertSame($lease, $this->leaseRow());

        // STALE (lower) sequence carrying a CHANGED build: refused exactly as before; authority AND the device row untouched.
        $this->beat(1, 'standby', $this->build(['edge_app_version' => '9.9.9-evil']))
            ->assertStatus(409)
            ->assertExactJson(['status' => 'refused', 'failure_code' => 'STALE_HEARTBEAT', 'seq' => 2]);
        $this->assertSame($lease, $this->leaseRow(), 'a stale beat never moves authority');
        $this->assertSame($device, $this->deviceRow(), 'a refused beat records no build');
        $this->assertSame('edge', $this->leaseRow()['holder']);
        $this->assertSame('local_active', $this->leaseRow()['edge_state']);
    }

    public function test_every_accepted_beat_advertises_cloud_capabilities(): void
    {
        $res = $this->beat(1, 'standby', $this->build())->assertOk();
        $this->assertSame(['customer_create' => false], $res->json('capabilities'));
        $this->assertFalse($res->json('capabilities.customer_create'));
        // Also without a build (an old appliance simply ignores the key).
        $this->beat(2, 'standby', null, withBuildKey: false)->assertOk()->assertJsonPath('capabilities.customer_create', false);
    }

    public function test_an_invalid_build_is_dropped_and_never_fails_the_heartbeat(): void
    {
        $before = $this->deviceRow();
        $this->beat(1, 'standby', $this->build(['git_commit' => str_repeat('a', 500)]))->assertOk()->assertJsonPath('holder', 'cloud');
        $this->beat(2, 'standby', $this->build(['capabilities' => ['ok', ['nested']]]))->assertOk()->assertJsonPath('seq', 2);
        $this->postJson($this->heartbeatUri, ['seq' => 3, 'edge_state' => 'standby', 'build' => 'not-an-object'], $this->headers())->assertOk()->assertJsonPath('seq', 3);
        $after = $this->deviceRow();
        foreach (['app_version', 'schema_version', 'compatibility_manifest', 'build_reported_hash', 'build_reported_at'] as $col) {
            $this->assertSame($before[$col], $after[$col], "{$col}: an invalid build is not recorded");
        }
        // Unknown keys from a NEWER appliance are ignored, the known ones recorded.
        $this->beat(4, 'standby', $this->build(['future_fact' => 'x']))->assertOk();
        $this->assertSame('0.7.0-edge', $this->deviceRow()['app_version']);
        $this->assertArrayNotHasKey('future_fact', json_decode((string) $this->deviceRow()['compatibility_manifest'], true));
    }

    public function test_the_appliance_heartbeat_tick_reports_its_build_end_to_end(): void
    {
        // APPLIANCE side on the same test database (a bound edge_local_meta with an APPLIED Edge schema), its HTTP client
        // relayed into the REAL Cloud endpoint of this app (role switched per side, like two machines).
        DB::setDefaultConnection('tenant');
        $this->bindEdgeLocalMeta($this->branchId, 1, $this->tenantId, $this->deviceUuid);
        DB::connection('tenant')->table('edge_local_meta')->update(['edge_schema_version' => 'edge-local-schema@applied-on-box', 'authority_heartbeat_seq' => 0]);
        config([
            'app.role' => 'branch_server',
            'edge.authority.heartbeat_url' => 'https://cloud.test/api/edge/authority/heartbeat',
            'edge.sync.device_id' => $this->deviceUuid,
            'edge.sync.device_secret' => $this->secret,
        ]);
        $sent = [];
        Http::fake(function (ClientRequest $request) use (&$sent) {
            $sent[] = $request->data();
            config(['app.role' => 'cloud']);
            try {
                $res = $this->postJson($this->heartbeatUri, $request->data(), [
                    'X-Edge-Device-ID' => $request->header('X-Edge-Device-ID')[0] ?? '',
                    'Authorization' => $request->header('Authorization')[0] ?? '',
                ]);
            } finally {
                config(['app.role' => 'branch_server']);
                DB::setDefaultConnection('tenant');
            }

            return Http::response($res->json(), $res->status());
        });

        $tick = app(EdgeAuthorityService::class)->heartbeat();
        $this->assertTrue($tick['ok'], json_encode($tick));
        $this->assertSame(1, $tick['seq']);

        $this->assertCount(1, $sent);
        $build = $sent[0]['build'];
        $this->assertSame((string) config('edge.app_version'), $build['edge_app_version']);
        $this->assertSame('edge-local-schema@applied-on-box', $build['applied_edge_schema_version']);
        $this->assertContains(EdgeSaleEnvelopeBuilder::SCHEMA_VERSION_V2, $build['envelope_versions']);
        $this->assertSame(array_values((array) config('edge.capabilities')), $build['capabilities']);

        config(['app.role' => 'cloud']);
        $row = $this->deviceRow();
        $this->assertSame((string) config('edge.app_version'), $row['app_version']);
        $this->assertSame('edge-local-schema@applied-on-box', json_decode((string) $row['compatibility_manifest'], true)['applied_edge_schema_version']);
        $this->assertSame('cloud', $this->leaseRow()['holder'], 'reporting a build never changes who holds the branch');
    }
}
