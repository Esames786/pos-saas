<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Services\Edge\EdgeBackupService;
use App\Services\Edge\EdgeRestoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE PRODUCTIZATION — encrypted local backup + guarded restore (§K/§L/§M).
 *
 * Proves: a backup is encrypted (no plaintext), integrity-checksummed, audit-logged, and pruned to the
 * retention window; a tampered/partial backup is refused; restore replaces the recoverable state and the
 * UN-SYNCED outbox sale survives intact; and restore fails closed on a wrong-branch or tampered backup
 * BEFORE touching the live database.
 */
class EdgeBackupRestoreMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId = 11;
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_backups', 'edge_sync_outbox', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_meta']);
        config(['app.role' => 'branch_server']);
        $this->backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-backup-test-' . Str::lower(Str::random(8));
        config(['edge.backup.path' => $this->backupDir]);
        $this->bindEdgeLocalMeta($this->branchId, 1);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->backupDir)) {
            foreach (glob($this->backupDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->backupDir);
        }
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function backups(): EdgeBackupService
    {
        return app(EdgeBackupService::class);
    }

    private function restore(): EdgeRestoreService
    {
        return app(EdgeRestoreService::class);
    }

    private function seedOutboxSale(string $state = EdgeSyncOutbox::STATE_PENDING): EdgeSyncOutbox
    {
        $u = (string) Str::ulid();
        $env = ['sale_uuid' => $u, 'sale_no' => 'LOCAL-1', 'lines' => []];
        $json = json_encode($env);

        return EdgeSyncOutbox::create([
            'sale_uuid' => $u, 'envelope_schema_version' => 'edge-sale-envelope-v1',
            'config_revision' => 5, 'activation_epoch' => 1, 'envelope' => $json,
            'content_hash' => hash('sha256', $json), 'state' => $state,
        ]);
    }

    public function test_backup_is_encrypted_verifiable_and_audit_logged(): void
    {
        $row = $this->seedOutboxSale();
        $backup = $this->backups()->backup();

        $this->assertFileExists($backup->path);
        // Encrypted at rest: the sale_uuid must not appear in plaintext in the file.
        $raw = (string) file_get_contents($backup->path);
        $this->assertStringNotContainsString($row->sale_uuid, $raw);

        // It decrypts + verifies, and the manifest carries the binding + counts.
        $manifest = $this->backups()->inspect($backup->path);
        $this->assertSame(EdgeBackupService::FORMAT, $manifest['format_version']);
        $this->assertSame($this->branchId, $manifest['binding']['branch_id']);
        $this->assertSame(1, $manifest['table_counts']['edge_sync_outbox']);
        $this->assertArrayNotHasKey('tables', $manifest);

        $this->assertSame(1, DB::connection('tenant')->table('edge_local_backups')->where('backup_uuid', $backup->backup_uuid)->count());
    }

    public function test_a_tampered_backup_is_refused(): void
    {
        $this->seedOutboxSale();
        $backup = $this->backups()->backup();
        file_put_contents($backup->path, 'CORRUPTED' . file_get_contents($backup->path));

        $this->expectExceptionMessage('BACKUP_CORRUPT');
        $this->backups()->decodeAndVerify($backup->path);
    }

    public function test_retention_prunes_old_backups(): void
    {
        config(['edge.backup.retention' => 2]);
        $this->seedOutboxSale();
        $this->backups()->backup();
        $this->backups()->backup();
        $this->backups()->backup();

        $this->assertSame(2, DB::connection('tenant')->table('edge_local_backups')->count());
        $this->assertCount(2, glob($this->backupDir . DIRECTORY_SEPARATOR . '*.enc') ?: []);
    }

    public function test_restore_replaces_state_and_the_unsynced_sale_survives(): void
    {
        $row = $this->seedOutboxSale(EdgeSyncOutbox::STATE_PENDING);
        $backup = $this->backups()->backup();

        // Simulate appliance loss: the operational tables are gone.
        DB::connection('tenant')->table('edge_sync_outbox')->delete();
        $this->assertSame(0, EdgeSyncOutbox::count());

        $result = $this->restore()->restore($backup->path, $this->branchId);
        $this->assertSame(1, $result['restored']['edge_sync_outbox']);

        // The un-synced sale is back, byte-for-byte, still pending.
        $restored = EdgeSyncOutbox::where('sale_uuid', $row->sale_uuid)->first();
        $this->assertNotNull($restored);
        $this->assertSame($row->content_hash, $restored->content_hash);
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, $restored->state);
    }

    public function test_restore_refuses_a_wrong_branch_before_mutating(): void
    {
        $this->seedOutboxSale();
        $backup = $this->backups()->backup();
        $liveRow = $this->seedOutboxSale(); // a live row that must remain untouched

        try {
            $this->restore()->restore($backup->path, $this->branchId + 999);
            $this->fail('a wrong-branch restore must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RESTORE_WRONG_IDENTITY', $e->getMessage());
        }
        // The guard ran before any mutation — the live row is still present.
        $this->assertNotNull(EdgeSyncOutbox::where('sale_uuid', $liveRow->sale_uuid)->first());
    }

    public function test_restore_refuses_a_tampered_backup(): void
    {
        $this->seedOutboxSale();
        $backup = $this->backups()->backup();
        file_put_contents($backup->path, substr((string) file_get_contents($backup->path), 0, -20));

        $this->expectExceptionMessage('BACKUP_CORRUPT');
        $this->restore()->restore($backup->path, $this->branchId);
    }
}
