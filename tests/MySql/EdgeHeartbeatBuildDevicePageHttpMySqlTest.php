<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeAuthorityLeaseService;
use App\Services\Edge\EdgeCanonicalJson;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * W-G1 (W-F VERSION REPORTING, display) — the Cloud "Offline Branch Edge" settings page shows, per paired device, the build
 * the appliance reported on its authority heartbeat (EdgeAuthorityLeaseService::recordBuildReport → master edge_devices):
 * app version, git commit, bootstrap schema, applied edge schema, capabilities and when it was reported. Read-only, in the
 * page's existing device cell; a device that never reported says so and nothing is invented.
 *
 * Over the REAL tenant HTTP stack (IdentifyTenant → auth:tenant → route.permission) on the permission-only SECURITY page
 * (`/settings/offline-edge/security` — reachable without the module entitlement, same Blade and the same device cell as the
 * setup page).
 */
class EdgeHeartbeatBuildDevicePageHttpMySqlTest extends MySqlTenantTestCase
{
    private const TENANT_CODE = 'edgebuildpage';
    private const PERM = 'tenant.offline-edge.security';

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $reportingBranchId;
    private int $silentBranchId;
    private string $reportingUuid;
    private string $silentUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = self::TENANT_CODE . '.' . config('tenancy.tenant_base_domain');
        $this->reportingUuid = (string) Str::uuid();
        $this->silentUuid = (string) Str::uuid();

        // ── master: a live tenant + domain so IdentifyTenant resolves to THIS test's tenant DB ──
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');
        $this->cleanupMaster();
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => self::TENANT_CODE, 'business_name' => 'Edge Build Page', 'owner_name' => 'Owner',
            'owner_email' => 'owner@edgebuildpage.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'), 'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb, 'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── tenant DB: an Owner holding the security permission + two active branches ──
        $this->cleanTenant(['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users', 'branches']);
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');
        $this->reportingBranchId = $c->table('branches')->insertGetId(['name' => 'Reporting Branch', 'code' => 'RB', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'created_at' => now(), 'updated_at' => now()]);
        $this->silentBranchId = $c->table('branches')->insertGetId(['name' => 'Silent Branch', 'code' => 'SB', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'created_at' => now(), 'updated_at' => now()]);
        $permId = $c->table('permissions')->insertGetId(['name' => self::PERM, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'owner@edgebuildpage.test', 'password' => bcrypt('x'), 'employee_code' => 'OWN1',
            'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_permissions')->insert(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $this->ownerId]);

        // ── master: two paired devices — one with a recorded heartbeat build, one that never reported ──
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        foreach ([[$this->reportingUuid, $this->reportingBranchId, 'counter-box'], [$this->silentUuid, $this->silentBranchId, 'silent-box']] as [$uuid, $branchId, $name]) {
            $m->table('edge_devices')->insert([
                'public_uuid' => $uuid, 'tenant_id' => $this->tenantId, 'branch_id' => $branchId, 'installation_uuid' => (string) Str::uuid(),
                'device_name' => $name, 'device_secret_hash' => hash('sha256', 'secret-' . $uuid), 'status' => 'active', 'active_slot' => 1,
                'paired_at' => now()->subDays(3), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        try {
            $this->cleanupMaster();
        } catch (\Throwable $e) {
        }
        parent::tearDown();
    }

    private function cleanupMaster(): void
    {
        $m = DB::connection('master');
        $m->table('edge_devices')->whereIn('public_uuid', [$this->reportingUuid, $this->silentUuid])->delete();
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();
    }

    private function page(): \Illuminate\Testing\TestResponse
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->get('http://' . $this->host . '/settings/offline-edge/security');
    }

    public function test_the_device_page_shows_the_build_the_appliance_reported_and_says_so_when_none_was(): void
    {
        // The build block exactly as the heartbeat records it (the same service the heartbeat endpoint calls).
        $reportedAt = Carbon::parse('2026-10-01 09:30:00');
        Carbon::setTestNow($reportedAt);
        $device = \App\Models\Master\EdgeDevice::where('public_uuid', $this->reportingUuid)->firstOrFail();
        $build = [
            'edge_app_version' => '0.7.0-edge', 'git_commit' => '7b8f886', 'artifact_version' => '0.7.0-edge',
            'bootstrap_schema' => (string) config('edge.bootstrap_schema'), 'config_schema' => (string) config('edge.config_schema'),
            'edge_schema_version' => 'edge-local-schema@2026_09_27_000001_widen', 'applied_edge_schema_version' => 'edge-local-schema@2026_09_20_000001_applied',
            'envelope_versions' => ['edge-sale-v1', 'edge-sale-v2'], 'capabilities' => ['local_auth', 'local_pos_cash_sales', 'config_refresh'],
        ];
        $this->assertTrue(app(EdgeAuthorityLeaseService::class)->recordBuildReport($device, $build));
        $this->assertSame(EdgeCanonicalJson::hash(app(EdgeAuthorityLeaseService::class)->normalizeBuild($build)),
            DB::connection('master')->table('edge_devices')->where('public_uuid', $this->reportingUuid)->value('build_reported_hash'));
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00'));

        $res = $this->page()->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('Offline Branch Edge', $html);

        // The reporting device's cell carries every fact, read-only, inside the existing device cell.
        $this->assertSame(1, preg_match('/<div class="text-muted mt-1 edge-device-build" data-device="' . preg_quote($this->reportingUuid, '/') . '">(.*?)<\/div>\s*<\/div>/s', $html, $m), 'the build block renders for the reporting device');
        $cell = $m[1];
        $this->assertStringContainsString('counter-box', $html);
        $this->assertStringContainsString('App version', $cell);
        $this->assertStringContainsString('0.7.0-edge', $cell);
        $this->assertStringContainsString('7b8f886', $cell);
        $this->assertStringContainsString('Bootstrap schema', $cell);
        $this->assertStringContainsString((string) config('edge.bootstrap_schema'), $cell);
        $this->assertStringContainsString('Applied edge schema', $cell);
        $this->assertStringContainsString('edge-local-schema@2026_09_20_000001_applied', $cell);
        $this->assertStringContainsString('Capabilities: local_auth, local_pos_cash_sales, config_refresh', $cell);
        $this->assertStringContainsString('Reported 30 minutes ago (01 Oct 2026, 09:30)', $cell);
        $this->assertStringNotContainsString('Version not reported yet', $cell);

        // The device that never reported: an honest "not reported yet", none of the other device's facts.
        $this->assertSame(1, preg_match('/<div class="text-muted mt-1 edge-device-build" data-device="' . preg_quote($this->silentUuid, '/') . '">(.*?)<\/div>/s', $html, $s));
        $this->assertSame('Version not reported yet', trim($s[1]));
        $this->assertSame(1, substr_count($html, '7b8f886'), 'the commit appears exactly once — for the device that reported it');

        // Security page: read-only facts, no new controls — the existing revoke form is the only action for a paired device.
        $this->assertSame(2, substr_count($html, 'Revoke device'));
        $this->assertStringNotContainsString('Generate pairing code', $html);
    }

    public function test_the_page_falls_back_to_the_pairing_era_columns_and_the_compatibility_report_aliases(): void
    {
        // An appliance that reported through the COMPATIBILITY REPORT endpoint (the *_version vocabulary) and the pairing-era
        // app_version column, but never a heartbeat build block.
        DB::connection('master')->table('edge_devices')->where('public_uuid', $this->silentUuid)->update([
            'app_version' => '0.6.0-edge', 'schema_version' => 'bootstrap-v6',
            'compatibility_manifest' => json_encode(['bootstrap_schema_version' => 'bootstrap-v6', 'config_schema_version' => 'edge-config-v1', 'capabilities' => ['local_auth']]),
            'compatibility_reported_at' => '2026-09-14 08:00:00',
        ]);

        $html = $this->page()->assertOk()->getContent();
        $this->assertSame(1, preg_match('/data-device="' . preg_quote($this->silentUuid, '/') . '">(.*?)<\/div>\s*<\/div>/s', $html, $m));
        $cell = $m[1];
        $this->assertStringContainsString('0.6.0-edge', $cell);
        $this->assertStringContainsString('bootstrap-v6', $cell);
        $this->assertStringContainsString('Capabilities: local_auth', $cell);
        $this->assertStringContainsString('14 Sep 2026, 08:00', $cell);
        // Facts it did not report are dashes — never invented.
        $this->assertMatchesRegularExpression('/Commit <span class="font-monospace">—<\/span>/', $cell);
        $this->assertMatchesRegularExpression('/Applied edge schema <span class="font-monospace">—<\/span>/', $cell);
    }

    /**
     * PHASE 3 (owner §8) — the SAME paired device heartbeats a newer build over the REAL device-authenticated endpoint:
     * version A → the page shows A; a later beat with version B → the page shows B, with NO re-pairing (the same device row —
     * id, installation uuid, secret hash, paired_at — and no new row), and the build block never touches authority (the same
     * lease row, holder and state; only the heartbeat sequence/timestamps advance). The heartbeat's replay / stale rules are
     * intact with a build attached: a re-sent sequence is re-acknowledged and writes nothing; a stale sequence carrying a
     * different build is refused and records nothing — the page keeps showing B.
     */
    public function test_a_newer_build_on_the_same_paired_device_updates_the_page_without_re_pairing_or_moving_authority(): void
    {
        $m = DB::connection('master');
        DB::connection('tenant')->table('edge_branch_authority_leases')->whereIn('branch_id', [$this->reportingBranchId, $this->silentBranchId])->delete();
        config(['edge.authority.ttl_seconds' => 60]);
        $heartbeatUri = 'http://' . config('tenancy.central_domain') . '/api/edge/authority/heartbeat';
        $headers = ['X-Edge-Device-ID' => $this->reportingUuid, 'Authorization' => 'Bearer secret-' . $this->reportingUuid];
        $build = fn (string $version, string $commit) => [
            'edge_app_version' => $version, 'git_commit' => $commit, 'artifact_version' => $version,
            'bootstrap_schema' => (string) config('edge.bootstrap_schema'), 'config_schema' => (string) config('edge.config_schema'),
            'edge_schema_version' => 'edge-local-schema@2026_09_27_000001_widen', 'applied_edge_schema_version' => 'edge-local-schema@2026_09_27_000001_widen',
            'envelope_versions' => ['edge-sale-v1', 'edge-sale-v2'], 'capabilities' => ['local_auth', 'local_pos_cash_sales', 'config_refresh'],
        ];
        // One process plays both machines: a page request leaves the tenant bound in the container (IdentifyTenant), which the
        // central-only heartbeat route refuses — release it as the end of a real request does (TenancyManager::deactivate).
        $beat = function (int $seq, array $b) use ($heartbeatUri, $headers) {
            app(\App\Services\Tenancy\TenancyManager::class)->deactivate();

            return $this->postJson($heartbeatUri, ['seq' => $seq, 'edge_state' => 'standby', 'build' => $b], $headers);
        };
        $deviceRow = fn () => (array) $m->table('edge_devices')->where('public_uuid', $this->reportingUuid)->first();
        $leaseRow = fn () => (array) DB::connection('tenant')->table('edge_branch_authority_leases')->where('branch_id', $this->reportingBranchId)->first();
        $identity = fn (array $row) => Arr::only($row, ['id', 'public_uuid', 'tenant_id', 'branch_id', 'installation_uuid', 'device_name', 'device_secret_hash', 'status', 'active_slot', 'paired_at']);
        $authority = fn (array $row) => Arr::except($row, ['heartbeat_seq', 'last_heartbeat_at', 'expires_at', 'updated_at']);
        $devicesBefore = (int) $m->table('edge_devices')->where('tenant_id', $this->tenantId)->count();
        $pairedIdentity = $identity($deviceRow());
        $this->assertNotNull($pairedIdentity['paired_at']);

        // Version A on the appliance's heartbeat → the page shows A.
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00'));
        $beat(1, $build('0.7.0-edge', 'aaa7000'))->assertOk()->assertJsonPath('holder', 'cloud')->assertJsonPath('edge_state', 'standby')->assertJsonPath('seq', 1);
        $leaseA = $leaseRow();
        $this->assertSame('cloud', $leaseA['holder']);
        $this->assertSame('standby', $leaseA['edge_state']);
        $this->assertSame((string) $this->reportingUuid, (string) $leaseA['device_public_uuid']);
        $cell = $this->buildCell($this->reportingUuid);
        $this->assertStringContainsString('0.7.0-edge', $cell);
        $this->assertStringContainsString('aaa7000', $cell);
        $this->assertStringContainsString('02 Oct 2026, 09:00', $cell);
        $this->assertSame('0.7.0-edge', $deviceRow()['app_version']);

        // Version B on the SAME device (the appliance was updated; nobody re-paired) → the page shows B and A is gone.
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:30'));
        $beat(2, $build('0.8.0-edge', 'bbb8000'))->assertOk()->assertJsonPath('holder', 'cloud')->assertJsonPath('edge_state', 'standby')->assertJsonPath('seq', 2);
        $cell = $this->buildCell($this->reportingUuid);
        $this->assertStringContainsString('0.8.0-edge', $cell);
        $this->assertStringContainsString('bbb8000', $cell);
        $this->assertStringNotContainsString('0.7.0-edge', $cell);
        $this->assertStringNotContainsString('aaa7000', $cell);
        $html = $this->page()->getContent();
        $this->assertSame(0, substr_count($html, 'aaa7000'), 'the superseded commit is nowhere on the page');
        $this->assertSame(1, substr_count($html, 'bbb8000'), 'the new commit appears once — for this device');
        $this->assertSame(1, preg_match('/data-device="' . preg_quote($this->silentUuid, '/') . '">(.*?)<\/div>/s', $html, $s));
        $this->assertSame('Version not reported yet', trim($s[1]), 'the other device is untouched');
        $deviceB = $deviceRow();
        $this->assertSame('0.8.0-edge', $deviceB['app_version']);
        $this->assertSame('2026-10-02 09:00:30', Carbon::parse($deviceB['build_reported_at'])->format('Y-m-d H:i:s'));

        // NO re-pairing: the same device row identity (id, installation uuid, secret, paired_at…) and no new device row.
        $this->assertSame($pairedIdentity, $identity($deviceB));
        $this->assertSame($devicesBefore, (int) $m->table('edge_devices')->where('tenant_id', $this->tenantId)->count());

        // Authority untouched by the build block: the same lease row, holder and state — only seq / heartbeat timestamps moved.
        $leaseB = $leaseRow();
        $this->assertSame($leaseA['id'], $leaseB['id']);
        $this->assertSame($authority($leaseA), $authority($leaseB));
        $this->assertSame(2, (int) $leaseB['heartbeat_seq']);

        // Replay rule intact with a build attached: the same sequence re-sent is re-acknowledged and writes NOTHING.
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:40'));
        $beat(2, $build('0.8.0-edge', 'bbb8000'))->assertOk()->assertJsonPath('holder', 'cloud')->assertJsonPath('seq', 2);
        $this->assertSame($deviceB, $deviceRow(), 'a replayed beat re-writes no build');
        $this->assertSame($leaseB, $leaseRow(), 'a replayed beat moves no authority');

        // Stale rule intact: a lower sequence carrying a DIFFERENT build is refused and records nothing — the page still shows B.
        $beat(1, $build('9.9.9-evil', 'eee9999'))->assertStatus(409)->assertJsonPath('status', 'refused')->assertJsonPath('failure_code', 'STALE_HEARTBEAT');
        $this->assertSame($deviceB, $deviceRow(), 'a refused beat records no build');
        $this->assertSame($leaseB, $leaseRow());
        $cell = $this->buildCell($this->reportingUuid);
        $this->assertStringContainsString('0.8.0-edge', $cell);
        $this->assertStringContainsString('bbb8000', $cell);
        $this->assertStringNotContainsString('9.9.9-evil', $cell);
    }

    /** The reporting device's build cell on the (re-rendered) security page. */
    private function buildCell(string $uuid): string
    {
        $html = $this->page()->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<div class="text-muted mt-1 edge-device-build" data-device="' . preg_quote($uuid, '/') . '">(.*?)<\/div>\s*<\/div>/s', $html, $m), 'the build block renders for ' . $uuid);

        return $m[1];
    }
}
