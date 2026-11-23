<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\EdgeBranchAuthorityLease;
use App\Services\Edge\BranchOperatingModeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * P0 BRANCH AUTHORITY LEASE — the Cloud side over REAL device-authenticated HTTP.
 *
 * The Cloud owns the branch's mutation lease while the appliance's heartbeats arrive; a stale beat is refused; the
 * appliance asserting local_active moves the holder to EDGE and the shared fence refuses Cloud POS writes for THAT
 * branch only; a resumed heartbeat never bounces authority back; handback is accepted only when the appliance
 * certifies a clean sync; an expired lease fences the Cloud by itself; an operator release is the only way past a
 * dead appliance. Another branch of the same tenant is never touched.
 */
class EdgeAuthorityLeaseHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const TENANT_CODE = 'edgelease';

    private string $heartbeatUri;
    private string $handbackUri;
    private string $secret = 'lease-device-secret';
    private int $tenantId;
    private string $deviceUuid;
    private int $branchId;
    private int $otherBranchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->heartbeatUri = 'http://' . config('tenancy.central_domain') . '/api/edge/authority/heartbeat';
        $this->handbackUri = 'http://' . config('tenancy.central_domain') . '/api/edge/authority/handback';
        $this->deviceUuid = (string) Str::uuid();

        $m = DB::connection('master');
        $m->table('edge_devices')->where('public_uuid', $this->deviceUuid)->delete();
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => self::TENANT_CODE, 'business_name' => 'Edge Lease', 'owner_name' => 'Owner',
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
        // Normal Cloud branches (mode cloud): the manual switch is OFF — the lease alone decides.
        $this->branchId = $this->makeBranch(['name' => 'Leased Branch']);
        $this->otherBranchId = $this->makeBranch(['name' => 'Other Branch']);
        DB::setDefaultConnection('master');

        $m->table('edge_devices')->insert([
            'public_uuid' => $this->deviceUuid, 'tenant_id' => $this->tenantId, 'branch_id' => $this->branchId,
            'installation_uuid' => (string) Str::uuid(), 'device_name' => 'lease-box', 'device_secret_hash' => hash('sha256', $this->secret),
            'status' => 'active', 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['edge.authority.ttl_seconds' => 60]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        try {
            $m = DB::connection('master');
            $m->table('edge_devices')->where('public_uuid', $this->deviceUuid)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
            $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();
        } catch (\Throwable $e) {
        }
        parent::tearDown();
    }

    private function headers(?string $secret = null): array
    {
        return ['X-Edge-Device-ID' => $this->deviceUuid, 'Authorization' => 'Bearer ' . ($secret ?? $this->secret)];
    }

    private function cloudFenced(int $branchId): bool
    {
        DB::setDefaultConnection('tenant');
        try {
            app(BranchOperatingModeService::class)->assertSaleMutationAllowed(Branch::on('tenant')->findOrFail($branchId));

            return false;
        } catch (\App\Exceptions\BranchLocalEdgeException $e) {
            return true;
        } finally {
            DB::setDefaultConnection('master');
        }
    }

    public function test_lease_lifecycle_heartbeat_takeover_flap_handback_expiry_release(): void
    {
        // CONTROLLED CLOCK: every `now()` below (a heartbeat's `expires_at`, the fence check) reads this frozen instant, so the
        // proof never depends on how long the server takes between two requests (the whole-second `expires_at` column rounds
        // fractions, and a slow full-suite run once pushed a real-time base past the +59 s boundary). Starting on an exact
        // second keeps +59 / +61 unambiguous. Production lease semantics are untouched — only the test owns the clock.
        Carbon::setTestNow(Carbon::now()->startOfSecond());

        // Unauthenticated / wrong secret → refused before anything.
        $this->postJson($this->heartbeatUri, ['seq' => 1, 'edge_state' => 'standby'])->assertStatus(401);
        $this->postJson($this->heartbeatUri, ['seq' => 1, 'edge_state' => 'standby'], $this->headers('wrong'))->assertStatus(401);

        // ONLINE: first heartbeat grants the Cloud its lease; Cloud POS writes are allowed; the other branch too.
        $hb = $this->postJson($this->heartbeatUri, ['seq' => 1, 'edge_state' => 'standby'], $this->headers())->assertOk();
        $this->assertSame('cloud', $hb->json('holder'));
        $this->assertFalse($hb->json('fenced'));
        $this->assertSame(60, (int) $hb->json('lease_ttl_seconds'));
        $this->assertFalse($this->cloudFenced($this->branchId));
        $this->assertFalse($this->cloudFenced($this->otherBranchId));

        // LOST ACK (P5C): re-sending the SAME beat is re-acknowledged idempotently — but it can never extend or move authority
        // (the lease row is byte-identical afterwards). A LOWER sequence is stale and the refusal names the Cloud's sequence.
        $before = (array) DB::connection('tenant')->table('edge_branch_authority_leases')->where('branch_id', $this->branchId)->first();
        Carbon::setTestNow(Carbon::getTestNow()->copy()->addSeconds(5));
        $this->postJson($this->heartbeatUri, ['seq' => 1, 'edge_state' => 'standby'], $this->headers())->assertOk()->assertJsonPath('holder', 'cloud')->assertJsonPath('seq', 1);
        $this->assertSame($before, (array) DB::connection('tenant')->table('edge_branch_authority_leases')->where('branch_id', $this->branchId)->first(), 'a replayed beat extends nothing and moves nothing');
        Carbon::setTestNow(Carbon::getTestNow()->copy()->subSeconds(5));

        // The appliance took over (after ITS safe lapse): holder → edge; the Cloud is fenced for THIS branch only.
        $this->postJson($this->heartbeatUri, ['seq' => 2, 'edge_state' => 'local_active'], $this->headers())->assertOk()->assertJsonPath('holder', 'edge')->assertJsonPath('fenced', true);
        $this->assertTrue($this->cloudFenced($this->branchId), 'a mobile/other Internet client using the Cloud POS for this branch is refused');
        $this->assertFalse($this->cloudFenced($this->otherBranchId), 'the other branch of the same tenant continues normally');

        // NETWORK FLAP: heartbeats resume while the appliance still holds — authority does not bounce.
        $this->postJson($this->heartbeatUri, ['seq' => 3, 'edge_state' => 'local_active'], $this->headers())->assertOk()->assertJsonPath('holder', 'edge');
        $this->postJson($this->heartbeatUri, ['seq' => 4, 'edge_state' => 'standby'], $this->headers())->assertOk()->assertJsonPath('holder', 'edge');
        $this->assertTrue($this->cloudFenced($this->branchId));
        // A LOWER (out-of-order) beat is refused and the refusal carries the Cloud's sequence; authority untouched.
        $this->postJson($this->heartbeatUri, ['seq' => 2, 'edge_state' => 'standby'], $this->headers())->assertStatus(409)->assertJsonPath('failure_code', 'STALE_HEARTBEAT')->assertJsonPath('seq', 4);
        $this->assertTrue($this->cloudFenced($this->branchId), 'a stale beat never moves authority');

        // HANDBACK: refused unless the appliance certifies a clean sync; then the Cloud is the writer again.
        $this->postJson($this->handbackUri, ['outbox_pending' => 2, 'failed_permanent' => 0], $this->headers())->assertStatus(422)->assertJsonPath('failure_code', 'HANDBACK_NOT_CLEAN');
        $this->assertTrue($this->cloudFenced($this->branchId));
        $this->postJson($this->handbackUri, ['outbox_pending' => 0, 'failed_permanent' => 0], $this->headers())->assertOk()->assertJsonPath('holder', 'cloud');
        $this->assertFalse($this->cloudFenced($this->branchId));
        // a second handback has nothing to hand back.
        $this->postJson($this->handbackUri, ['outbox_pending' => 0, 'failed_permanent' => 0], $this->headers())->assertStatus(422)->assertJsonPath('failure_code', 'HANDBACK_NOT_HOLDER');

        // EXPIRY: heartbeats stop; once the lease lapses on the Cloud clock the Cloud fences ITSELF (no Edge call needed).
        $this->postJson($this->heartbeatUri, ['seq' => 5, 'edge_state' => 'standby'], $this->headers())->assertOk()->assertJsonPath('holder', 'cloud');
        Carbon::setTestNow(Carbon::getTestNow()->copy()->addSeconds(59));
        $this->assertFalse($this->cloudFenced($this->branchId), 'still inside the lease');
        Carbon::setTestNow(Carbon::getTestNow()->copy()->addSeconds(2));
        $this->assertTrue($this->cloudFenced($this->branchId), 'lease lapsed → Cloud refuses this branch');
        $this->assertFalse($this->cloudFenced($this->otherBranchId));
        DB::setDefaultConnection('tenant');
        $this->assertNotNull(EdgeBranchAuthorityLease::on('tenant')->where('branch_id', $this->branchId)->value('fenced_at'), 'the first refusal is audited');
        DB::setDefaultConnection('master');

        // A resumed heartbeat with the appliance still in standby re-grants the Cloud (nothing was written locally).
        $this->postJson($this->heartbeatUri, ['seq' => 6, 'edge_state' => 'standby'], $this->headers())->assertOk()->assertJsonPath('holder', 'cloud')->assertJsonPath('fenced', false);
        $this->assertFalse($this->cloudFenced($this->branchId));

        // DEAD APPLIANCE: lapse again, then an operator releases the lease (audited) — the Cloud writes again.
        Carbon::setTestNow(Carbon::getTestNow()->copy()->addSeconds(120));
        $this->assertTrue($this->cloudFenced($this->branchId));
        DB::setDefaultConnection('tenant');
        $released = app(\App\Services\Edge\EdgeAuthorityLeaseService::class)->release($this->branchId, 'appliance destroyed in fire', 'owner');
        DB::setDefaultConnection('master');
        $this->assertSame('cloud', $released['holder']);
        $this->assertTrue($released['released']);
        $this->assertFalse($this->cloudFenced($this->branchId));
    }

    /** The operator's real recovery path is the ARTISAN command (not the service): a dead appliance's lapsed lease is released by tenant code. */
    public function test_the_release_command_finds_the_tenant_by_code_and_unfences_a_dead_appliances_branch(): void
    {
        Carbon::setTestNow(Carbon::now()->startOfSecond());
        $this->postJson($this->heartbeatUri, ['seq' => 1, 'edge_state' => 'standby'], $this->headers())->assertOk()->assertJsonPath('holder', 'cloud');
        Carbon::setTestNow(Carbon::getTestNow()->copy()->addSeconds(61));
        $this->assertTrue($this->cloudFenced($this->branchId), 'lease lapsed → Cloud refuses this branch');

        $this->assertSame(1, Artisan::call('edge:authority:release', ['tenant' => self::TENANT_CODE, 'branch' => $this->branchId]), 'a reason is required');
        $this->assertSame(1, Artisan::call('edge:authority:release', ['tenant' => self::TENANT_CODE, 'branch' => $this->branchId, '--reason' => 'x']), 'a named operator is required');
        $this->assertSame(1, Artisan::call('edge:authority:release', ['tenant' => 'no-such-tenant', 'branch' => $this->branchId, '--reason' => 'x', '--by' => 'owner']), 'an unknown tenant is refused');
        $this->assertStringContainsString('Tenant not found', Artisan::output());
        $rc = Artisan::call('edge:authority:release', ['tenant' => self::TENANT_CODE, 'branch' => $this->branchId, '--reason' => 'appliance dead after a failed update', '--by' => 'owner']);
        $this->assertSame(0, $rc, Artisan::output());
        DB::setDefaultConnection('master');

        $row = DB::connection('tenant')->table('edge_branch_authority_leases')->where('branch_id', $this->branchId)->first();
        $this->assertSame('cloud', $row->holder);
        $this->assertNotNull($row->released_at);
        $this->assertStringContainsString('(by owner)', (string) $row->release_reason);
        $this->assertFalse($this->cloudFenced($this->branchId), 'released → the Cloud writes again');
    }

    /**
     * SAFE-RELEASE PRECONDITIONS: active or unsafe authority can never be released by accident. A live lease (standby OR the
     * appliance holding authority) is refused with no override; a silent appliance that holds authority needs --edge-stopped;
     * --dry-run never writes; every refusal leaves the lease row byte-identical.
     */
    public function test_the_release_command_never_releases_a_live_or_appliance_held_lease_by_accident(): void
    {
        Carbon::setTestNow(Carbon::now()->startOfSecond());
        $row = fn () => (array) DB::connection('tenant')->table('edge_branch_authority_leases')->where('branch_id', $this->branchId)->first();
        $release = function (array $extra = []): int {
            $rc = Artisan::call('edge:authority:release', ['tenant' => self::TENANT_CODE, 'branch' => $this->branchId, '--reason' => 'test', '--by' => 'owner'] + $extra);
            DB::setDefaultConnection('master');

            return $rc;
        };

        // LIVE STANDBY lease (the Cloud already writes): refused, dry run refused, nothing written.
        $this->postJson($this->heartbeatUri, ['seq' => 1, 'edge_state' => 'standby'], $this->headers())->assertOk()->assertJsonPath('holder', 'cloud');
        $before = $row();
        Carbon::setTestNow(Carbon::getTestNow()->copy()->addSeconds(59));
        $this->assertSame(1, $release(['--dry-run' => true]));
        $this->assertStringContainsString('DRY RUN: REFUSED LEASE_LIVE', Artisan::output());
        $this->assertSame(1, $release());
        $this->assertStringContainsString('REFUSED LEASE_LIVE', Artisan::output());
        $this->assertSame(1, $release(['--edge-stopped' => true]), 'a live lease is never overridable');
        $this->assertSame($before, $row(), 'a refused release writes nothing');
        $this->assertFalse($this->cloudFenced($this->branchId));

        // LIVE APPLIANCE AUTHORITY (it took over and keeps heartbeating): refused even with --edge-stopped — no split brain.
        $this->postJson($this->heartbeatUri, ['seq' => 2, 'edge_state' => 'local_active'], $this->headers())->assertOk()->assertJsonPath('holder', 'edge');
        $before = $row();
        $this->assertSame(1, $release());
        $this->assertSame(1, $release(['--edge-stopped' => true]));
        $this->assertStringContainsString('REFUSED LEASE_LIVE', Artisan::output());
        $this->assertSame($before, $row());
        $this->assertTrue($this->cloudFenced($this->branchId), 'the appliance stays the writer');

        // SILENT APPLIANCE THAT HOLDS AUTHORITY: refused without the operator's --edge-stopped statement; dry run writes nothing.
        Carbon::setTestNow(Carbon::getTestNow()->copy()->addSeconds(61));
        $before = $row();
        $this->assertSame(1, $release());
        $this->assertStringContainsString('REFUSED EDGE_HOLDS_AUTHORITY', Artisan::output());
        $this->assertSame(0, $release(['--edge-stopped' => true, '--dry-run' => true]));
        $this->assertStringContainsString('DRY RUN: this lease WOULD be released', Artisan::output());
        $this->assertSame($before, $row(), 'refusal and dry run write nothing');
        $this->assertTrue($this->cloudFenced($this->branchId));

        // With the statement: released, audited, the Cloud writes again; a second release has nothing to release.
        $this->assertSame(0, $release(['--edge-stopped' => true]), Artisan::output());
        $after = $row();
        $this->assertSame('cloud', $after['holder']);
        $this->assertNotNull($after['released_at']);
        $this->assertStringContainsString('(by owner)', (string) $after['release_reason']);
        $this->assertFalse($this->cloudFenced($this->branchId));
        $this->assertSame(1, $release(['--edge-stopped' => true]));
        $this->assertStringContainsString('REFUSED ALREADY_RELEASED', Artisan::output());

        // A branch with no lease: nothing to release, nothing created; the other branch is never touched.
        $rc = Artisan::call('edge:authority:release', ['tenant' => self::TENANT_CODE, 'branch' => $this->otherBranchId, '--reason' => 'test', '--by' => 'owner']);
        DB::setDefaultConnection('master');
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('No lease exists', Artisan::output());
        $this->assertSame(1, DB::connection('tenant')->table('edge_branch_authority_leases')->count());
    }

    public function test_the_appliance_reports_only_its_own_branch(): void
    {
        // The device is bound to branchId; a heartbeat can never create or move a lease for another branch —
        // the endpoint takes the branch from the authenticated device, never from the body.
        $this->postJson($this->heartbeatUri, ['seq' => 1, 'edge_state' => 'local_active', 'branch_id' => $this->otherBranchId], $this->headers())->assertOk();
        DB::setDefaultConnection('tenant');
        $this->assertSame(1, EdgeBranchAuthorityLease::on('tenant')->count());
        $this->assertSame($this->branchId, (int) EdgeBranchAuthorityLease::on('tenant')->value('branch_id'));
        DB::setDefaultConnection('master');
        $this->assertFalse($this->cloudFenced($this->otherBranchId));
    }
}
