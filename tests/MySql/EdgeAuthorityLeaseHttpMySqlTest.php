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

        // A stale / replayed beat can never extend or move authority.
        $this->postJson($this->heartbeatUri, ['seq' => 1, 'edge_state' => 'standby'], $this->headers())->assertStatus(409)->assertJsonPath('failure_code', 'STALE_HEARTBEAT');

        // The appliance took over (after ITS safe lapse): holder → edge; the Cloud is fenced for THIS branch only.
        $this->postJson($this->heartbeatUri, ['seq' => 2, 'edge_state' => 'local_active'], $this->headers())->assertOk()->assertJsonPath('holder', 'edge')->assertJsonPath('fenced', true);
        $this->assertTrue($this->cloudFenced($this->branchId), 'a mobile/other Internet client using the Cloud POS for this branch is refused');
        $this->assertFalse($this->cloudFenced($this->otherBranchId), 'the other branch of the same tenant continues normally');

        // NETWORK FLAP: heartbeats resume while the appliance still holds — authority does not bounce.
        $this->postJson($this->heartbeatUri, ['seq' => 3, 'edge_state' => 'local_active'], $this->headers())->assertOk()->assertJsonPath('holder', 'edge');
        $this->postJson($this->heartbeatUri, ['seq' => 4, 'edge_state' => 'standby'], $this->headers())->assertOk()->assertJsonPath('holder', 'edge');
        $this->assertTrue($this->cloudFenced($this->branchId));

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
