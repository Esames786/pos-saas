<?php

namespace App\Services\Edge;

use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OFFLINE EDGE PRODUCTIZATION — the appliance's encrypted local BACKUP authority.
 *
 * Captures a CONSISTENT logical snapshot (one REPEATABLE-READ transaction) of the appliance's RECOVERABLE
 * local state, integrity-checksums it, encrypts it, writes it temp-first, verifies it reads back, atomically
 * promotes it, records an audit row, and prunes to a rolling window. Local selling never depends on this —
 * it is a read-only snapshot that never blocks a sale.
 *
 * What is captured (recoverable, NOT re-derivable from Cloud): the binding, local users, local sales, the
 * sync OUTBOX (un-synced sales — the whole point), the operational baseline, and the cutover audit. The
 * config catalog (products/categories/…) is deliberately excluded: a replacement box re-derives it from the
 * Cloud bootstrap before restore. device_secret_hash is a hash, not a secret; no plaintext secret is stored.
 */
class EdgeBackupService
{
    public const FORMAT = 'edge-backup-v1';
    public const CONN = 'tenant';

    /** Recoverable local-state tables, parents-before-children for a clean FK-coherent restore. */
    public const TABLES = [
        'edge_local_meta',
        'edge_local_user_credentials',
        'sales_orders',
        'sales_order_lines',
        'sale_payments',
        'edge_operational_stock_baselines',
        'edge_operational_stock_balances',
        'edge_operational_stock_movements',
        'edge_baseline_cutovers',
        'edge_sync_outbox',
    ];

    /** Create an encrypted backup and return its audit row. */
    public function backup(): object
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('BACKUP_NOT_BRANCH_SERVER: appliance backup runs only on a Branch Server.');
        }

        [$tables, $counts] = $this->snapshot();
        $binding = $this->binding();
        $tablesJson = json_encode($tables);
        $checksum = hash('sha256', $tablesJson);

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

        $dir = $this->dir();
        $backupUuid = (string) Str::ulid();
        $final = $dir . DIRECTORY_SEPARATOR . 'edge-backup-' . now()->format('Ymd_His') . '-' . Str::lower(Str::random(6)) . '.enc';
        $tmp = $final . '.tmp';

        // Write temp-first, then verify it reads back and its checksum matches — never promote a partial file.
        file_put_contents($tmp, Crypt::encryptString(json_encode($payload)));
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
        DB::connection(self::CONN)->table('edge_local_backups')->insert((array) array_merge((array) $row, [
            'table_counts' => json_encode($counts), 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->prune();

        return $row;
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

    /** Decrypt, parse, and verify a backup's integrity (format + checksum). Throws on any tampering/partial. */
    public function decodeAndVerify(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('BACKUP_NOT_FOUND: ' . $path);
        }
        try {
            $decrypted = Crypt::decryptString((string) file_get_contents($path));
        } catch (\Throwable $e) {
            // A tampered or truncated ciphertext fails the authenticated-encryption MAC.
            throw new RuntimeException('BACKUP_CORRUPT: the backup could not be decrypted (tampered or partial).');
        }
        $payload = json_decode($decrypted, true);
        if (! is_array($payload) || ($payload['format_version'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('BACKUP_UNSUPPORTED: unrecognised backup format.');
        }
        $tables = $payload['tables'] ?? null;
        if (! is_array($tables) || ! hash_equals((string) ($payload['checksum'] ?? ''), hash('sha256', json_encode($tables)))) {
            throw new RuntimeException('BACKUP_INTEGRITY: the backup checksum does not match its contents (corrupt or partial).');
        }

        return $payload;
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

    /** Keep the most recent N backups; delete older files and their audit rows. */
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
