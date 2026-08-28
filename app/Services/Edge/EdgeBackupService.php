<?php

namespace App\Services\Edge;

use App\Support\EdgeRuntime;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OFFLINE EDGE PRODUCTIZATION — the appliance's encrypted local BACKUP authority (portable across machine loss).
 *
 * Captures a CONSISTENT logical snapshot (one REPEATABLE-READ transaction) of the appliance's RECOVERABLE
 * local state, checksums it, encrypts it, writes it temp-first, verifies it reads back, atomically promotes
 * it, audit-logs it, and prunes to a rolling window (never below the last known-good). A single-writer lock
 * means two overlapping runs never corrupt a backup. Read-only; never blocks a sale.
 *
 * PORTABILITY: a backup is NOT encrypted with APP_KEY. Each backup carries a random data-encryption key (DEK)
 * that encrypts the payload; the DEK is wrapped by the recovery key resolved through EdgeBackupKeyProvider —
 * a key provisioned per branch from the Cloud recovery authority and recoverable on a REPLACEMENT machine
 * independently of the dead appliance. The backup stamps the key_id it was wrapped under, so keys rotate and
 * older backups stay recoverable; an unknown/revoked key_id fails closed (never a plaintext fallback).
 *
 * What is captured (recoverable, NOT re-derivable from Cloud): the binding, local users, local sales, the
 * sync OUTBOX (un-synced sales), the operational baseline, and the cutover audit. The config catalog
 * (products/…) is excluded — a replacement box re-derives it from the Cloud bootstrap BEFORE restore.
 */
class EdgeBackupService
{
    public const FORMAT = 'edge-backup-v2';
    public const CONN = 'tenant';
    private const CIPHER = 'aes-256-gcm';

    /**
     * Recoverable local-state tables, PARENTS-BEFORE-CHILDREN for a clean FK-coherent restore.
     * Coverage decided by the state census (docs/design/EDGE_BACKUP_STATE_CENSUS.md): anything whose loss
     * could change money, stock, shift, dine-in/table, KOT, printing correctness, or exactly-once behaviour
     * is captured. Config catalog (products/tables/printers/…) is excluded — re-derivable from Cloud
     * bootstrap. Ephemeral worker/audit state (print worker lease, auth audit, consumed assertions, the
     * backup log itself) is excluded — safely rebuilt.
     */
    public const TABLES = [
        'edge_local_meta',
        'edge_local_user_credentials',
        'shifts',                          // money: open shift + cash reconciliation
        'restaurant_table_sessions',       // dine-in: active table sessions
        'edge_local_table_reservations',   // dine-in: table reservations (+ customer carry-over)
        'sales_orders',                    // sales incl. held/draft (status='held', is_draft)
        'sales_order_lines',
        'sale_payments',
        'kot_batches',                     // KOT state (reprint correctness)
        'kot_batch_lines',
        'print_jobs',                      // local print queue + printed history (no duplicate print)
        'edge_local_print_deliveries',     // Edge print delivery state
        'edge_operational_stock_baselines',
        'edge_operational_stock_balances',
        'edge_operational_stock_movements',
        'edge_baseline_cutovers',
        'edge_sync_outbox',                // pending/leased/acknowledged/failed_permanent
    ];

    public function __construct(private readonly EdgeBackupKeyProvider $keys)
    {
    }

    /** Create an encrypted backup and return its audit row. */
    public function backup(): object
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('BACKUP_NOT_BRANCH_SERVER: appliance backup runs only on a Branch Server.');
        }

        $dir = $this->dir();
        $lock = $this->acquireLock($dir); // single writer — a concurrent run defers rather than corrupts
        try {
            [$tables, $counts] = $this->snapshot();
            $binding = $this->binding();
            $checksum = hash('sha256', json_encode($tables));

            $payload = [
                'format_version' => self::FORMAT,
                'software_version' => (string) config('edge.app_version'),
                'schema_generation' => (string) config('edge.config_schema'),
                'created_at' => now()->toIso8601String(),
                'binding' => $binding,
                'checksum' => $checksum,
                'table_counts' => $counts,
                'tables' => $tables,
            ];

            $backupUuid = (string) Str::ulid();
            $final = $dir . DIRECTORY_SEPARATOR . 'edge-backup-' . now()->format('Ymd_His') . '-' . Str::lower(Str::random(6)) . '.enc';
            $tmp = $final . '.tmp';

            // Write temp-first, then verify it reads back and its checksum matches — never promote a partial file.
            file_put_contents($tmp, $this->seal($payload));
            $this->verifyFile($tmp, $checksum);
            if (! @rename($tmp, $final)) {              // atomic promote on the same filesystem
                @unlink($tmp);
                throw new RuntimeException('BACKUP_PROMOTE_FAILED: could not atomically promote the verified backup.');
            }

            $row = (object) [
                'backup_uuid' => $backupUuid,
                'path' => $final,
                'format_version' => self::FORMAT,
                'software_version' => $payload['software_version'],
                'schema_generation' => $payload['schema_generation'],
                'tenant_id' => $binding['tenant_id'] ?? null,
                'branch_id' => $binding['branch_id'] ?? null,
                'device_uuid' => $binding['device_uuid'] ?? null,
                'activation_epoch' => $binding['activation_epoch'] ?? null,
                'checksum' => $checksum,
                'size_bytes' => (int) filesize($final),
                'table_counts' => $counts,
                'status' => 'completed',
            ];
            DB::connection(self::CONN)->table('edge_local_backups')->insert(array_merge((array) $row, [
                'table_counts' => json_encode($counts), 'created_at' => now(), 'updated_at' => now(),
            ]));

            $this->prune();

            return $row;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** Decrypt + integrity-verify a backup file, returning its manifest (WITHOUT the table data). */
    public function inspect(string $path): array
    {
        $payload = $this->decodeAndVerify($path);
        unset($payload['tables']);

        return $payload;
    }

    /** Recent backup audit rows, newest first. */
    public function recent(int $limit = 20): array
    {
        return DB::connection(self::CONN)->table('edge_local_backups')
            ->orderByDesc('id')->limit(max(1, $limit))->get()->all();
    }

    /**
     * Decrypt, parse, and verify a backup's integrity. Resolves the wrapping key by the backup's key_id
     * through EdgeBackupKeyProvider (NOT APP_KEY), unwraps the DEK, decrypts, then checks the content
     * checksum. Any tampering, truncation, unknown key, or checksum mismatch fails closed.
     */
    public function decodeAndVerify(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('BACKUP_NOT_FOUND: ' . $path);
        }
        $envelope = json_decode((string) file_get_contents($path), true);
        if (! is_array($envelope) || ($envelope['format_version'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('BACKUP_UNSUPPORTED: unrecognised backup format.');
        }

        // Resolve the recovery wrapping key for the key_id this backup was sealed under (throws if unknown).
        $wrappingKey = $this->keys->wrappingKey((string) ($envelope['key_id'] ?? ''));

        try {
            $dek = base64_decode((new Encrypter($wrappingKey, self::CIPHER))->decryptString((string) $envelope['wrapped_dek']), true);
            if ($dek === false || strlen($dek) !== 32) {
                throw new RuntimeException('bad dek');
            }
            $plain = (new Encrypter($dek, self::CIPHER))->decryptString((string) $envelope['payload']);
        } catch (\Throwable $e) {
            // A tampered/truncated ciphertext, or the wrong recovery key, fails the authenticated-encryption MAC.
            throw new RuntimeException('BACKUP_CORRUPT: the backup could not be decrypted (tampered, partial, or wrong recovery key).');
        }

        $payload = json_decode($plain, true);
        $tables = $payload['tables'] ?? null;
        if (! is_array($payload) || ! is_array($tables)
            || ! hash_equals((string) ($payload['checksum'] ?? ''), hash('sha256', json_encode($tables)))) {
            throw new RuntimeException('BACKUP_INTEGRITY: the backup checksum does not match its contents (corrupt or partial).');
        }

        return $payload;
    }

    /** Encrypt the payload: random DEK encrypts the data; the recovery key wraps the DEK. */
    private function seal(array $payload): string
    {
        $dek = random_bytes(32);
        $cipherPayload = (new Encrypter($dek, self::CIPHER))->encryptString(json_encode($payload));

        $keyId = $this->keys->currentKeyId();
        $wrappingKey = $this->keys->wrappingKey($keyId);
        $wrappedDek = (new Encrypter($wrappingKey, self::CIPHER))->encryptString(base64_encode($dek));

        return json_encode([
            'format_version' => self::FORMAT,
            'key_id' => $keyId,
            'wrapped_dek' => $wrappedDek,
            'payload' => $cipherPayload,
        ]);
    }

    private function verifyFile(string $path, string $expectedChecksum): void
    {
        $payload = $this->decodeAndVerify($path);
        if (! hash_equals($expectedChecksum, (string) ($payload['checksum'] ?? ''))) {
            @unlink($path);
            throw new RuntimeException('BACKUP_VERIFY_FAILED: the written backup did not verify.');
        }
    }

    /** @return array{0: array<string,array>, 1: array<string,int>} */
    private function snapshot(): array
    {
        $conn = DB::connection(self::CONN);

        return $conn->transaction(function () use ($conn) {   // consistent REPEATABLE-READ snapshot
            $tables = [];
            $counts = [];
            foreach (self::TABLES as $table) {
                if (! Schema::connection(self::CONN)->hasTable($table)) {
                    continue;
                }
                $query = $conn->table($table);
                if (Schema::connection(self::CONN)->hasColumn($table, 'id')) {
                    $query->orderBy('id');
                }
                $rows = $query->get()->map(fn ($r) => (array) $r)->all();
                $tables[$table] = $rows;
                $counts[$table] = count($rows);
            }

            return [$tables, $counts];
        });
    }

    private function binding(): array
    {
        $meta = DB::connection(self::CONN)->table('edge_local_meta')->first();
        if ($meta === null) {
            return [];
        }

        return [
            'tenant_id' => $meta->tenant_id !== null ? (int) $meta->tenant_id : null,
            'branch_id' => $meta->branch_id !== null ? (int) $meta->branch_id : null,
            'device_uuid' => $meta->device_uuid ?? null,
            'activation_epoch' => $meta->activation_epoch !== null ? (int) $meta->activation_epoch : null,
            'source_revision' => $meta->source_revision ?? null,
        ];
    }

    private function dir(): string
    {
        $dir = (string) config('edge.backup.path');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('BACKUP_DIR: could not create the backup directory.');
        }

        return rtrim($dir, DIRECTORY_SEPARATOR);
    }

    /** @return resource */
    private function acquireLock(string $dir)
    {
        $handle = fopen($dir . DIRECTORY_SEPARATOR . '.backup.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('BACKUP_LOCK: could not open the backup lock.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('BACKUP_IN_PROGRESS: another backup is running; this run deferred.');
        }

        return $handle;
    }

    /** @param resource $handle */
    private function releaseLock($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    /** Keep the most recent N backups; never delete the last known-good. */
    private function prune(): void
    {
        $keep = max(1, (int) config('edge.backup.retention', 24));
        $stale = DB::connection(self::CONN)->table('edge_local_backups')->orderByDesc('id')->skip($keep)->take(1000)->get();
        foreach ($stale as $row) {
            if (is_file($row->path)) {
                @unlink($row->path);
            }
            DB::connection(self::CONN)->table('edge_local_backups')->where('id', $row->id)->delete();
        }
    }
}
