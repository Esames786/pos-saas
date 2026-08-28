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
        $this->cleanTenant([
            'edge_local_backups', 'edge_sync_outbox', 'edge_baseline_cutovers', 'edge_operational_stock_movements',
            'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_meta',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'products', 'categories', 'branches', 'users',
        ]);
        config(['app.role' => 'branch_server']);
        $this->backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-backup-test-' . Str::lower(Str::random(8));
        config([
            'edge.backup.path' => $this->backupDir,
            // A dedicated recovery key (provisioned from Cloud per branch) — NOT the APP_KEY.
            'edge.backup.recovery_key' => base64_encode(random_bytes(32)),
            'edge.backup.recovery_key_id' => 'k1',
            'edge.backup.retired_keys' => [],
        ]);
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
        // Tamper with the encrypted payload — the authenticated-encryption MAC catches it.
        $env = json_decode((string) file_get_contents($backup->path), true);
        $env['payload'] = substr((string) $env['payload'], 0, -12) . 'ZZZZ';
        file_put_contents($backup->path, json_encode($env));

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
        // Corrupt the encrypted payload (the wrapper JSON stays valid, the ciphertext does not).
        $env = json_decode((string) file_get_contents($backup->path), true);
        $env['payload'] = substr((string) $env['payload'], 0, -20) . 'XXXX';
        file_put_contents($backup->path, json_encode($env));

        $this->expectExceptionMessage('BACKUP_CORRUPT');
        $this->restore()->restore($backup->path, $this->branchId);
    }

    // ── portability across machine loss (the core disaster-recovery invariant) ────

    public function test_backup_is_recoverable_after_the_app_key_changes(): void
    {
        $row = $this->seedOutboxSale();
        $backup = $this->backups()->backup();

        // The original machine (and its APP_KEY) is gone; the replacement box has a DIFFERENT APP_KEY but the
        // SAME provisioned recovery key. The backup must still decrypt and restore.
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        DB::connection('tenant')->table('edge_sync_outbox')->delete();

        $this->restore()->restore($backup->path, $this->branchId);
        $this->assertNotNull(EdgeSyncOutbox::where('sale_uuid', $row->sale_uuid)->first(), 'recovered without the old APP_KEY');
    }

    public function test_a_wrong_recovery_key_is_refused(): void
    {
        $this->seedOutboxSale();
        $backup = $this->backups()->backup();

        // A replacement box provisioned with the WRONG recovery key cannot decrypt the backup.
        config(['edge.backup.recovery_key' => base64_encode(random_bytes(32))]);
        $this->expectExceptionMessage('BACKUP_CORRUPT');
        $this->backups()->decodeAndVerify($backup->path);
    }

    public function test_an_unknown_key_id_is_refused(): void
    {
        $this->seedOutboxSale();
        $backup = $this->backups()->backup(); // sealed under key_id k1

        // The current key rotates to k2 and k1 is not retained -> the k1 backup fails closed.
        config(['edge.backup.recovery_key_id' => 'k2', 'edge.backup.recovery_key' => base64_encode(random_bytes(32)), 'edge.backup.retired_keys' => []]);
        $this->expectExceptionMessage('BACKUP_KEY_UNKNOWN');
        $this->backups()->decodeAndVerify($backup->path);
    }

    public function test_a_rotated_retired_key_still_recovers_an_old_backup(): void
    {
        $k1 = base64_encode(random_bytes(32));
        config(['edge.backup.recovery_key' => $k1, 'edge.backup.recovery_key_id' => 'k1']);
        $this->seedOutboxSale();
        $backup = $this->backups()->backup();

        // Rotate to k2 but RETAIN k1 -> the old backup still recovers with its retained key.
        config([
            'edge.backup.recovery_key_id' => 'k2',
            'edge.backup.recovery_key' => base64_encode(random_bytes(32)),
            'edge.backup.retired_keys' => ['k1' => $k1],
        ]);
        $manifest = $this->backups()->inspect($backup->path);
        $this->assertSame(EdgeBackupService::FORMAT, $manifest['format_version']);
    }

    // ── reference-integrity + fresh-recovery ordering (config before operational restore) ──

    public function test_restore_refuses_and_then_succeeds_around_config_bootstrap_order(): void
    {
        // A sale that references a product — the recoverable state depends on config being present.
        $branch = $this->makeBranch();
        $user = $this->makeUser(['default_branch_id' => $branch]);
        $product = $this->makeProduct($this->makeCategory());
        $order = \App\Models\Tenant\SalesOrder::on('tenant')->create([
            'sale_no' => 'SO-REF-' . Str::random(5), 'branch_id' => $branch, 'sale_date' => now(),
            'order_type' => 'takeaway', 'created_by_user_id' => $user,
        ]);
        DB::connection('tenant')->table('sales_order_lines')->insert([
            'sales_order_id' => $order->id, 'line_uuid' => (string) Str::ulid(), 'product_id' => $product, 'product_name' => 'Widget',
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindEdgeLocalMeta($branch, 1);
        $backup = $this->backups()->backup();

        // Capture the real product row (its stable Cloud id) so bootstrap can recreate it verbatim.
        $productRow = (array) DB::connection('tenant')->table('products')->where('id', $product)->first();

        // Fresh box BEFORE bootstrap: the product config does not exist -> restore is refused (fail closed).
        DB::connection('tenant')->table('sales_order_lines')->delete();
        DB::connection('tenant')->table('sales_orders')->delete();
        DB::connection('tenant')->table('products')->where('id', $product)->delete();
        try {
            $this->restore()->restore($backup->path, $branch);
            $this->fail('restore must refuse when required config is missing');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RESTORE_REFERENCE_UNRESOLVED', $e->getMessage());
        }

        // After Cloud bootstrap re-creates the config at its stable id, the restore succeeds.
        DB::connection('tenant')->table('products')->insert($productRow);
        $result = $this->restore()->restore($backup->path, $branch);
        $this->assertSame(1, $result['restored']['sales_orders']);
    }

    public function test_a_mid_restore_failure_rolls_back_and_preserves_live_state(): void
    {
        $inBackup = $this->seedOutboxSale();       // captured by the backup (so the failing insert is reached)
        $backup = $this->backups()->backup();
        $liveExtra = $this->seedOutboxSale();      // a live row NOT in the backup

        // A restore service whose edge_sync_outbox insert throws mid-apply.
        $failing = new class(app(EdgeBackupService::class)) extends EdgeRestoreService {
            protected function applyInsert($conn, string $table, array $chunk): void
            {
                if ($table === 'edge_sync_outbox') {
                    throw new \RuntimeException('forced mid-restore failure');
                }
                $conn->table($table)->insert($chunk);
            }
        };

        try {
            $failing->restore($backup->path, $this->branchId);
            $this->fail('the forced failure should abort the restore');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('forced mid-restore failure', $e->getMessage());
        }
        // Rollback preserved the ENTIRE pre-restore state — nothing half-restored.
        $this->assertNotNull(EdgeSyncOutbox::where('sale_uuid', $inBackup->sale_uuid)->first());
        $this->assertNotNull(EdgeSyncOutbox::where('sale_uuid', $liveExtra->sale_uuid)->first(), 'the live row survived the aborted restore');
    }

    public function test_sequential_backups_release_the_single_writer_lock(): void
    {
        $this->seedOutboxSale();
        $this->backups()->backup();
        $this->backups()->backup(); // would throw BACKUP_IN_PROGRESS if the lock were not released
        $this->assertSame(2, DB::connection('tenant')->table('edge_local_backups')->count());
    }
}
