<?php

namespace Tests\MySql;

use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Services\Edge\EdgeBackupRecoveryAuthority;
use App\Services\Edge\EdgeBackupService;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\EdgeRecoveryKeyProvisioner;
use App\Services\Edge\EdgeRestoreService;
use App\Support\EdgeApplianceEnvFile;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * P5B §3 — the CLOUD BACKUP RECOVERY AUTHORITY and the dead-appliance replacement restore.
 *
 *   Cloud (master DB): per-branch escrowed key, device-authenticated retrieval scoped by the device row, rotation on
 *   device revocation, audit of every issue / retrieval / rotation / refusal, no material in logs or audits.
 *   Appliance: backup sealed under the Cloud-issued key → the appliance DIES (local state wiped, its device revoked)
 *   → a replacement pairs → pulls the branch material (current + retired) → the restore opens the old backup → the
 *   pending outbox event is byte-identical. A device of another branch never receives this branch's keys and cannot
 *   open its backup.
 */
class EdgeBackupRecoveryAuthorityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const TENANT_CODE = 'edgerecov';

    private string $uri;
    private string $backupDir;
    private string $envFile;
    private int $tenantId;
    private int $branchId;
    private int $otherBranchId;
    private int $userId;
    private string $deviceUuid;
    private string $secret = 'recovery-device-secret-A';
    private string $otherUuid;
    private string $otherSecret = 'recovery-device-secret-B';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureEdgeSchema(); // the Edge-local tables (outbox, meta, credentials) on the tenant test connection
        $this->uri = 'http://' . config('tenancy.central_domain') . '/api/edge/backup/recovery-keys';
        $this->backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-recov-' . Str::lower(Str::random(8));
        mkdir($this->backupDir, 0775, true);
        $this->envFile = $this->backupDir . DIRECTORY_SEPARATOR . 'appliance.env';
        file_put_contents($this->envFile, "APP_ROLE=branch_server\nEDGE_BACKUP_RECOVERY_KEY=\nEDGE_BACKUP_RECOVERY_KEY_ID=k1\nEDGE_BACKUP_RETIRED_KEYS={}\n");
        config(['edge.backup.path' => $this->backupDir, 'edge.backup.recovery_key' => null, 'edge.backup.recovery_key_id' => 'k1', 'edge.backup.retired_keys' => []]);

        $this->cleanTenant(['edge_branch_authority_leases', 'edge_sync_outbox', 'edge_local_user_credentials', 'edge_local_meta', 'branches', 'users', 'accounts']);
        (new DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch(['name' => 'Recovery A']);
        $this->otherBranchId = $this->makeBranch(['name' => 'Recovery B']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId]);
        $this->deviceUuid = (string) Str::uuid();
        $this->otherUuid = (string) Str::uuid();

        $m = DB::connection('master');
        $m->table('edge_devices')->whereIn('public_uuid', [$this->deviceUuid, $this->otherUuid])->delete();
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenants')->where('tenant_code', self::TENANT_CODE)->delete();
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => self::TENANT_CODE, 'business_name' => 'Edge Recovery', 'owner_name' => 'Owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c = config('database.connections.tenant');
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant', 'db_host' => $c['host'], 'db_port' => (int) $c['port'], 'db_database' => $this->tenantDb,
            'db_username' => $c['username'], 'db_password' => null, 'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('edge_backup_recovery_audits')->where('tenant_id', $this->tenantId)->delete();
        $m->table('edge_backup_recovery_keys')->where('tenant_id', $this->tenantId)->delete();
        // The master test DB is migrated, never refreshed, while the tenant DB IS refreshed — so a branch id repeats across runs
        // under a new tenant id. The assertions below count by branch, so clean by branch too (rows from earlier runs otherwise
        // accumulate: 30 keys were found on 25 Sep 2026 from runs since 14 Sep).
        foreach ([$this->branchId, $this->otherBranchId] as $branchId) {
            $m->table('edge_backup_recovery_audits')->where('branch_id', $branchId)->delete();
            $m->table('edge_backup_recovery_keys')->where('branch_id', $branchId)->delete();
        }
        $this->seedDevice($this->deviceUuid, $this->secret, $this->branchId);
        $this->seedDevice($this->otherUuid, $this->otherSecret, $this->otherBranchId);
    }

    protected function tearDown(): void
    {
        config(['app.role' => null]);
        foreach (glob($this->backupDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->backupDir);
        parent::tearDown();
    }

    public function test_the_cloud_issues_escrows_and_releases_the_branch_material_only_to_its_active_device(): void
    {
        $r = $this->postJson($this->uri, [], $this->headers($this->deviceUuid, $this->secret));
        $r->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('branch_id', $this->branchId)->assertJsonPath('tenant_id', $this->tenantId);
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $keyId = (string) $r->json('current.key_id');
        $key = (string) $r->json('current.key');
        $this->assertMatchesRegularExpression('/^brk_[0-9a-z]{26}$/', $keyId);
        $this->assertSame(32, strlen((string) base64_decode($key, true)));
        $this->assertSame([], (array) $r->json('retired'));

        // Escrowed encrypted under the Cloud APP_KEY — never plaintext in the row.
        $row = DB::connection('master')->table('edge_backup_recovery_keys')->where('key_id', $keyId)->first();
        $this->assertSame([$this->tenantId, $this->branchId, 'active'], [(int) $row->tenant_id, (int) $row->branch_id, $row->status]);
        $this->assertStringNotContainsString($key, (string) $row->key_ciphertext);
        $this->assertSame($key, Crypt::decryptString($row->key_ciphertext));

        // Stable across calls; every step audited without material.
        $this->postJson($this->uri, [], $this->headers($this->deviceUuid, $this->secret))->assertOk()->assertJsonPath('current.key_id', $keyId);
        $audits = DB::connection('master')->table('edge_backup_recovery_audits')->where('tenant_id', $this->tenantId)->orderBy('id')->get();
        $this->assertSame(['issued', 'retrieved', 'retrieved'], $audits->pluck('action')->all());
        $this->assertSame('device:' . $this->deviceUuid, $audits[1]->actor);
        $this->assertStringNotContainsString($key, json_encode($audits->all()));

        // Unauthenticated / wrong secret / unknown device → 401 (device auth), nothing issued for them.
        $this->postJson($this->uri, [])->assertStatus(401);
        $this->postJson($this->uri, [], $this->headers($this->deviceUuid, 'wrong'))->assertStatus(401);
        $this->postJson($this->uri, [], $this->headers((string) Str::uuid(), $this->secret))->assertStatus(401);
    }

    public function test_another_branch_device_never_receives_this_branch_material_and_scope_requests_are_refused(): void
    {
        $a = $this->postJson($this->uri, [], $this->headers($this->deviceUuid, $this->secret))->assertOk();
        $b = $this->postJson($this->uri, [], $this->headers($this->otherUuid, $this->otherSecret))->assertOk();
        $this->assertNotSame($a->json('current.key_id'), $b->json('current.key_id'));
        $this->assertNotSame($a->json('current.key'), $b->json('current.key'));
        $this->assertSame($this->otherBranchId, $b->json('branch_id'));

        // Device B asks for A's key id → refused without disclosure, audited under B's scope.
        $this->postJson($this->uri, ['key_id' => $a->json('current.key_id')], $this->headers($this->otherUuid, $this->otherSecret))
            ->assertStatus(404)->assertJsonPath('code', 'RECOVERY_KEY_NOT_FOUND')->assertJsonMissingPath('current');
        $refused = DB::connection('master')->table('edge_backup_recovery_audits')->where('branch_id', $this->otherBranchId)->where('action', 'refused')->first();
        $this->assertNotNull($refused);
        $this->assertSame('RECOVERY_KEY_SCOPE', $refused->detail);
        // Device A asking for its own key id is fine.
        $this->postJson($this->uri, ['key_id' => $a->json('current.key_id')], $this->headers($this->deviceUuid, $this->secret))->assertOk();
    }

    public function test_revoking_a_device_rotates_the_branch_key_and_the_revoked_device_gets_nothing(): void
    {
        $before = $this->postJson($this->uri, [], $this->headers($this->deviceUuid, $this->secret))->assertOk()->json('current.key_id');
        $tenant = Tenant::find($this->tenantId);
        $device = EdgeDevice::where('public_uuid', $this->deviceUuid)->firstOrFail();
        app(EdgePairingService::class)->revokeDevice($tenant, $device, $this->userId, 'appliance died');

        $this->postJson($this->uri, [], $this->headers($this->deviceUuid, $this->secret))->assertStatus(401);
        $rows = DB::connection('master')->table('edge_backup_recovery_keys')->where('branch_id', $this->branchId)->orderBy('id')->get();
        $this->assertSame(['retired', 'active'], $rows->pluck('status')->all());
        $this->assertSame($before, $rows[0]->key_id);
        $this->assertStringStartsWith('device_revoked:', (string) $rows[0]->retire_reason);
        $this->assertSame(1, DB::connection('master')->table('edge_backup_recovery_audits')->where('branch_id', $this->branchId)->where('action', 'rotated')->count());

        // The admin authority: status shows both ids (no material); a rotation with a reason is audited.
        $authority = app(EdgeBackupRecoveryAuthority::class);
        $status = $authority->status($this->tenantId, $this->branchId);
        $this->assertSame($rows[1]->key_id, $status['active_key_id']);
        $this->assertSame([$before], $status['retired_key_ids']);
        $this->assertStringNotContainsString('"key":', json_encode($status));
        $rotated = $authority->rotate($this->tenantId, $this->branchId, 'admin:test', 'admin_rotate:test');
        $this->assertNotSame($status['active_key_id'], $rotated['key_id']);
    }

    public function test_a_dead_appliance_is_replaced_and_its_pending_outbox_restores_byte_identical_via_the_cloud_authority(): void
    {
        // ── APPLIANCE A: provisioned from the Cloud authority, seals a backup with a pending outbox event ──
        $material = $this->postJson($this->uri, [], $this->headers($this->deviceUuid, $this->secret))->assertOk()->json();
        $keyA = (string) $material['current']['key_id'];
        $envelope = ['sale_uuid' => strtolower((string) Str::ulid()), 'branch_id' => $this->branchId, 'lines' => [['sku' => 'BURGER', 'qty' => 2, 'note' => 'کم مرچ']], 'total' => '1234.50'];
        $backupPath = $this->asEdge(function () use ($material, $envelope) {
            app(EdgeRecoveryKeyProvisioner::class)->apply(['key_id' => $material['current']['key_id'], 'key' => $material['current']['key'], 'retired' => (array) $material['retired']], $this->envFile);
            $this->bindEdgeLocalMeta($this->branchId, 1, $this->tenantId, $this->deviceUuid);
            DB::connection('tenant')->table('edge_sync_outbox')->insert([
                'sale_uuid' => $envelope['sale_uuid'], 'envelope_schema_version' => '1', 'config_revision' => 1, 'activation_epoch' => 1,
                'envelope' => json_encode($envelope, JSON_UNESCAPED_UNICODE), 'content_hash' => hash('sha256', json_encode($envelope, JSON_UNESCAPED_UNICODE)),
                'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return (string) app(EdgeBackupService::class)->backup()->path;
        });
        $this->assertSame($keyA, EdgeApplianceEnvFile::get($this->envFile, 'EDGE_BACKUP_RECOVERY_KEY_ID'));
        $this->assertSame($keyA, json_decode((string) file_get_contents($backupPath), true)['key_id'] ?? null, 'the backup stamps the Cloud-issued key id');
        $before = DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $envelope['sale_uuid'])->first(['envelope', 'content_hash', 'state']);

        // ── THE APPLIANCE DIES: local state wiped, its env (with the key) gone, the owner revokes its device ──
        $this->asEdge(function () {
            DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach (array_reverse(EdgeBackupService::TABLES) as $t) {
                    DB::connection('tenant')->table($t)->delete();
                }
            } finally {
                DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });
        @unlink($this->envFile);
        file_put_contents($this->envFile, "APP_ROLE=branch_server\nEDGE_BACKUP_RECOVERY_KEY=\nEDGE_BACKUP_RECOVERY_KEY_ID=k1\nEDGE_BACKUP_RETIRED_KEYS={}\n");
        config(['edge.backup.recovery_key' => null, 'edge.backup.recovery_key_id' => 'k1', 'edge.backup.retired_keys' => []]);
        app(EdgePairingService::class)->revokeDevice(Tenant::find($this->tenantId), EdgeDevice::where('public_uuid', $this->deviceUuid)->firstOrFail(), $this->userId, 'appliance died');
        $this->postJson($this->uri, [], $this->headers($this->deviceUuid, $this->secret))->assertStatus(401);

        // Without the material the restore fails closed (the replacement machine has NO local copy of the key).
        $this->asEdge(function () use ($backupPath) {
            try {
                app(EdgeRestoreService::class)->restore($backupPath, $this->branchId);
                $this->fail('a replacement without recovery material must refuse');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('BACKUP_KEY_UNKNOWN', $e->getMessage());
            }
        });

        // ── REPLACEMENT: a supervised pairing (new device row for the same branch) → Cloud material → restore ──
        $replacementUuid = (string) Str::uuid();
        $replacementSecret = 'replacement-secret-' . Str::random(8);
        $this->seedDevice($replacementUuid, $replacementSecret, $this->branchId);
        $r = $this->postJson($this->uri, ['key_id' => $keyA], $this->headers($replacementUuid, $replacementSecret))->assertOk();
        $this->assertNotSame($keyA, $r->json('current.key_id'), 'the revocation rotated the branch key');
        $this->assertArrayHasKey($keyA, (array) $r->json('retired'), 'the key that sealed the old backup is released as a RETIRED key of this branch');
        $this->bindEdgeLocalMeta($this->branchId, 1, $this->tenantId, $replacementUuid);
        config(['edge.sync.device_id' => $replacementUuid, 'edge.sync.device_secret' => $replacementSecret]);
        $restored = $this->asEdge(function () use ($r, $backupPath) {
            $applied = app(EdgeRecoveryKeyProvisioner::class)->apply(['key_id' => $r->json('current.key_id'), 'key' => $r->json('current.key'), 'retired' => (array) $r->json('retired')], $this->envFile);
            $this->assertContains($r->json('current.key_id'), [EdgeApplianceEnvFile::get($this->envFile, 'EDGE_BACKUP_RECOVERY_KEY_ID')]);
            $this->assertNotEmpty($applied['retired_key_ids']);
            try {
                app(EdgeRestoreService::class)->restore($backupPath, $this->otherBranchId);
                $this->fail('wrong branch must refuse');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('RESTORE_WRONG_IDENTITY', $e->getMessage());
            }

            return app(EdgeRestoreService::class)->restore($backupPath, $this->branchId);
        });
        $this->assertGreaterThan(0, $restored['restored']['edge_sync_outbox'] ?? 0);
        // Identity follows the PAIRED device, state follows the backup: the local binding names the replacement device.
        $this->assertTrue($restored['device_rebound']);
        $this->assertSame($replacementUuid, (string) DB::connection('tenant')->table('edge_local_meta')->where('singleton_guard', 1)->value('device_uuid'));
        $after = DB::connection('tenant')->table('edge_sync_outbox')->where('sale_uuid', $envelope['sale_uuid'])->first(['envelope', 'content_hash', 'state']);
        $this->assertSame((array) $before, (array) $after, 'the pending outbox event is byte-identical after the replacement restore');
        $this->assertSame('pending', $after->state);

        // ── WRONG BRANCH: device B (another branch) only ever gets B's keys and cannot open A's backup ──
        $b = $this->postJson($this->uri, [], $this->headers($this->otherUuid, $this->otherSecret))->assertOk();
        $this->assertArrayNotHasKey($keyA, (array) $b->json('retired'));
        $this->asEdge(function () use ($b, $backupPath) {
            config(['edge.backup.recovery_key' => $b->json('current.key'), 'edge.backup.recovery_key_id' => $b->json('current.key_id'), 'edge.backup.retired_keys' => (array) $b->json('retired')]);
            try {
                app(EdgeRestoreService::class)->restore($backupPath, $this->otherBranchId);
                $this->fail('another branch must not open this backup');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('BACKUP_KEY_UNKNOWN', $e->getMessage());
            }
        });
        $audits = DB::connection('master')->table('edge_backup_recovery_audits')->where('branch_id', $this->branchId)->pluck('action')->all();
        $this->assertContains('rotated', $audits);
        $this->assertGreaterThanOrEqual(2, count(array_keys($audits, 'retrieved', true)));
    }

    private function seedDevice(string $uuid, string $secret, int $branchId): void
    {
        DB::connection('master')->table('edge_devices')->insert([
            'public_uuid' => $uuid, 'tenant_id' => $this->tenantId, 'branch_id' => $branchId, 'installation_uuid' => (string) Str::uuid(),
            'device_name' => 'recovery-' . substr($uuid, 0, 8), 'device_secret_hash' => hash('sha256', $secret), 'status' => 'active', 'active_slot' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function headers(string $uuid, string $secret): array
    {
        return ['X-Edge-Device-ID' => $uuid, 'Authorization' => 'Bearer ' . $secret];
    }

    private function asEdge(callable $fn): mixed
    {
        config(['app.role' => 'branch_server']);
        app()->forgetInstance(EdgeBranchContext::class);
        try {
            return $fn();
        } finally {
            config(['app.role' => null]);
        }
    }
}
