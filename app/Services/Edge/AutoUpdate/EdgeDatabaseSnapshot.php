<?php

namespace App\Services\Edge\AutoUpdate;

use App\Support\EdgeLocalDatabase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EDGE 0.9 (stream B) — a FULL, encrypted logical snapshot of the Edge-local database, taken right before an update whose
 * migration plan changes the schema, and restored when that update fails its health check (K6 rollback rule: a pointer
 * rollback alone is only safe when the plan had no schema change).
 *
 * Unlike the hourly EdgeBackupService (recoverable state only, portable to a replacement machine), this captures EVERY base
 * table — DDL (SHOW CREATE TABLE) and rows — so a restore returns the database byte-for-byte to the pre-update schema + data
 * the previous runtime expects. It is local and short-lived (deleted after a healthy update), so it is encrypted with the
 * appliance key (Crypt / EDGE_LOCAL_APP_KEY) rather than the recovery key. Only an Edge-local database name is ever
 * snapshotted or restored (EdgeLocalDatabase naming guard), and restore refuses a snapshot of another database.
 *
 * File format (edge-db-snapshot-v1, one record per line): a plaintext header line (non-secret metadata), then encrypted
 * lines each holding a gzip+JSON batch {t, create} / {t, rows}, then an encrypted trailer {end, tables, rows}.
 */
class EdgeDatabaseSnapshot
{
    public const FORMAT = 'edge-db-snapshot-v1';
    private const BATCH = 500;

    public function __construct(private readonly string $connection = 'tenant')
    {
    }

    /** @return array{file:string,tables:int,rows:int,bytes:int,database:string} */
    public function snapshot(string $file): array
    {
        $conn = $this->conn();
        $db = $this->guardedDatabase($conn);
        $dir = dirname($file);
        if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            throw new RuntimeException('AUTO_UPDATE_SNAPSHOT_FAILED: cannot create ' . $dir);
        }
        $tmp = $file . '.part';
        $fh = @fopen($tmp, 'wb');
        if ($fh === false) {
            throw new RuntimeException('AUTO_UPDATE_SNAPSHOT_FAILED: cannot write ' . $tmp);
        }
        $tables = $this->baseTables($conn);
        $rows = 0;
        try {
            fwrite($fh, json_encode(['format' => self::FORMAT, 'database' => $db, 'created_at' => now()->toIso8601String(), 'tables' => count($tables)]) . "\n");
            foreach ($tables as $t) {
                $create = (array) $conn->selectOne('SHOW CREATE TABLE `' . str_replace('`', '``', $t) . '`');
                $ddl = (string) ($create['Create Table'] ?? array_values($create)[1] ?? '');
                if ($ddl === '') {
                    throw new RuntimeException("no DDL for table {$t}");
                }
                $this->writeRecord($fh, ['t' => $t, 'create' => $ddl]);
                $batch = [];
                foreach ($conn->table($t)->cursor() as $row) {
                    $batch[] = array_map([self::class, 'encodeValue'], (array) $row);
                    if (count($batch) >= self::BATCH) {
                        $this->writeRecord($fh, ['t' => $t, 'rows' => $batch]);
                        $rows += count($batch);
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    $this->writeRecord($fh, ['t' => $t, 'rows' => $batch]);
                    $rows += count($batch);
                }
            }
            $this->writeRecord($fh, ['end' => true, 'tables' => count($tables), 'rows' => $rows]);
        } catch (\Throwable $e) {
            fclose($fh);
            @unlink($tmp);
            throw new RuntimeException('AUTO_UPDATE_SNAPSHOT_FAILED: ' . mb_substr($e->getMessage(), 0, 300), 0, $e);
        }
        fclose($fh);
        // Verify it reads back completely before it is trusted as the rollback point.
        $check = $this->inspect($tmp);
        if ($check['tables'] !== count($tables) || $check['rows'] !== $rows) {
            @unlink($tmp);
            throw new RuntimeException('AUTO_UPDATE_SNAPSHOT_FAILED: read-back mismatch.');
        }
        @unlink($file);
        if (! @rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('AUTO_UPDATE_SNAPSHOT_FAILED: cannot move the snapshot into place.');
        }

        return ['file' => $file, 'tables' => count($tables), 'rows' => $rows, 'bytes' => (int) filesize($file), 'database' => $db];
    }

    /** Read + decrypt every record without touching the database. @return array{database:string,tables:int,rows:int} */
    public function inspect(string $file): array
    {
        $tables = 0;
        $rows = 0;
        $header = null;
        $ended = false;
        $this->readRecords($file, function (array $rec) use (&$tables, &$rows, &$ended) {
            if (isset($rec['create'])) {
                $tables++;
            } elseif (isset($rec['rows'])) {
                $rows += count($rec['rows']);
            } elseif (! empty($rec['end'])) {
                $ended = (int) $rec['tables'] === $tables && (int) $rec['rows'] === $rows;
            }
        }, $header);
        if (! $ended) {
            throw new RuntimeException('AUTO_UPDATE_SNAPSHOT_INVALID: the snapshot is truncated or inconsistent.');
        }

        return ['database' => (string) ($header['database'] ?? ''), 'tables' => $tables, 'rows' => $rows];
    }

    /**
     * Replace the database's contents with the snapshot: every current base table is dropped, every snapshotted table is
     * re-created from its DDL and refilled. Foreign-key checks are off for the duration. The snapshot is fully verified first.
     *
     * @return array{tables:int,rows:int}
     */
    public function restore(string $file): array
    {
        $conn = $this->conn();
        $db = $this->guardedDatabase($conn);
        $info = $this->inspect($file);
        if (strcasecmp($info['database'], $db) !== 0) {
            throw new RuntimeException("AUTO_UPDATE_RESTORE_REFUSED: the snapshot is of [{$info['database']}], not [{$db}].");
        }
        $tables = 0;
        $rows = 0;
        $conn->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->baseTables($conn) as $t) {
                $conn->statement('DROP TABLE IF EXISTS `' . str_replace('`', '``', $t) . '`');
            }
            $header = null;
            $this->readRecords($file, function (array $rec) use ($conn, &$tables, &$rows) {
                if (isset($rec['create'])) {
                    $conn->statement((string) $rec['create']);
                    $tables++;
                } elseif (isset($rec['rows']) && $rec['rows'] !== []) {
                    $decoded = array_map(fn ($r) => array_map([self::class, 'decodeValue'], $r), $rec['rows']);
                    foreach (array_chunk($decoded, 200) as $chunk) {
                        $conn->table((string) $rec['t'])->insert($chunk);
                    }
                    $rows += count($decoded);
                }
            }, $header);
        } finally {
            $conn->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return ['tables' => $tables, 'rows' => $rows];
    }

    public static function encodeValue(mixed $v): mixed
    {
        if (is_string($v) && ! mb_check_encoding($v, 'UTF-8')) {
            return ['__b64' => base64_encode($v)];
        }

        return $v;
    }

    public static function decodeValue(mixed $v): mixed
    {
        if (is_array($v) && array_keys($v) === ['__b64']) {
            return base64_decode((string) $v['__b64'], true);
        }

        return $v;
    }

    private function conn(): Connection
    {
        return DB::connection($this->connection);
    }

    private function guardedDatabase(Connection $conn): string
    {
        $db = (string) $conn->getDatabaseName();
        if ($conn->getDriverName() !== 'mysql') {
            throw new RuntimeException('AUTO_UPDATE_SNAPSHOT_UNSUPPORTED: only the MySQL/MariaDB Edge-local database can be snapshotted.');
        }
        if (! EdgeLocalDatabase::isEdgeDatabaseName($db)) {
            throw new RuntimeException("AUTO_UPDATE_SNAPSHOT_REFUSED: [{$db}] is not a dedicated Edge-local database.");
        }

        return $db;
    }

    /** @return array<int,string> */
    private function baseTables(Connection $conn): array
    {
        $out = [];
        foreach ($conn->select('SHOW FULL TABLES') as $row) {
            $vals = array_values((array) $row);
            if (strtoupper((string) ($vals[1] ?? '')) === 'BASE TABLE') {
                $out[] = (string) $vals[0];
            }
        }
        sort($out);

        return $out;
    }

    /** @param resource $fh */
    private function writeRecord($fh, array $record): void
    {
        $payload = base64_encode((string) gzcompress(json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 6));
        fwrite($fh, Crypt::encryptString($payload) . "\n");
    }

    private function readRecords(string $file, callable $each, ?array &$header): void
    {
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            throw new RuntimeException('AUTO_UPDATE_SNAPSHOT_INVALID: cannot read ' . $file);
        }
        try {
            $first = fgets($fh);
            $header = json_decode((string) $first, true);
            if (! is_array($header) || ($header['format'] ?? null) !== self::FORMAT) {
                throw new RuntimeException('AUTO_UPDATE_SNAPSHOT_INVALID: not an ' . self::FORMAT . ' file.');
            }
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    $json = gzuncompress((string) base64_decode(Crypt::decryptString($line), true));
                } catch (\Throwable $e) {
                    throw new RuntimeException('AUTO_UPDATE_SNAPSHOT_INVALID: a record does not decrypt (wrong appliance key or corrupted file).');
                }
                $rec = json_decode((string) $json, true);
                if (! is_array($rec)) {
                    throw new RuntimeException('AUTO_UPDATE_SNAPSHOT_INVALID: a record is not JSON.');
                }
                $each($rec);
            }
        } finally {
            fclose($fh);
        }
    }
}
