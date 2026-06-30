<?php

namespace App\Services\Central;

use App\Models\Master\Tenant;
use App\Models\Master\TenantBackup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * MASTER-TENANT-OPS-1 — per-tenant SQL backup + restore.
 *
 * Files are written to a PRIVATE disk (storage/app/backups/tenants/{code}/...), never
 * the public web root. Restore always takes a fresh pre-restore backup first and is
 * blocked from importing one tenant's dump into a different tenant. DB credentials are
 * passed via a temp option file — never on the command line / in logs.
 */
class TenantBackupService
{
    private string $disk;

    public function __construct()
    {
        $this->disk = (string) config('backup.disk', 'local');
    }

    /** Create a SQL backup of one tenant DB and record metadata. */
    public function backup(Tenant $tenant, string $type = 'manual', ?int $userId = null, ?string $notes = null): TenantBackup
    {
        $conn = $this->connectionFor($tenant);

        if (empty($conn['database'])) {
            throw new RuntimeException("Tenant [{$tenant->tenant_code}] has no database to back up.");
        }

        if (! $this->ensureBinary((string) config('backup.mysql_dump_binary', 'mysqldump'))) {
            throw new RuntimeException('mysqldump not found. Set MYSQLDUMP_BINARY in .env to its absolute path.');
        }

        $timestamp = Carbon::now()->format('Ymd_His');
        $fileName  = "{$timestamp}_{$tenant->tenant_code}.sql";
        $relPath   = "backups/tenants/{$tenant->tenant_code}/{$fileName}";

        Storage::disk($this->disk)->makeDirectory("backups/tenants/{$tenant->tenant_code}");
        $absPath = Storage::disk($this->disk)->path($relPath);

        $res = $this->dumpDatabase($conn, $absPath);

        $backup = TenantBackup::create([
            'tenant_id'     => $tenant->id,
            'tenant_code'   => $tenant->tenant_code,
            'database_name' => $conn['database'],
            'disk'          => $this->disk,
            'path'          => $relPath,
            'file_name'     => $fileName,
            'file_size'     => $res['ok'] ? ($res['bytes'] ?? 0) : 0,
            'backup_type'   => $type,
            'status'        => $res['ok'] ? 'completed' : 'failed',
            'created_by'    => $userId,
            'notes'         => $res['ok'] ? $notes : trim(($notes ? $notes . ' — ' : '') . 'ERROR: ' . ($res['error'] ?? 'dump failed')),
        ]);

        Log::info('tenant_backup.created', [
            'tenant' => $tenant->tenant_code, 'type' => $type, 'status' => $backup->status, 'by' => $userId,
        ]);

        if (! $res['ok']) {
            throw new RuntimeException("Backup failed for [{$tenant->tenant_code}]: " . ($res['error'] ?? 'mysqldump error'));
        }

        return $backup;
    }

    /**
     * Restore a tenant from a backup. Takes a pre-restore safety backup first, verifies
     * the dump belongs to THIS tenant (no cross-tenant restore), imports it, and records
     * the restore. Caller is responsible for the post-restore sync (migrate + permissions).
     */
    public function restore(TenantBackup $backup, Tenant $tenant, ?int $userId = null): TenantBackup
    {
        // Cross-tenant guard — a backup may only be restored into its own tenant.
        if ((int) $backup->tenant_id !== (int) $tenant->id || $backup->tenant_code !== $tenant->tenant_code) {
            throw new RuntimeException('Refusing cross-tenant restore: this backup belongs to a different tenant.');
        }

        if (! $backup->fileExists()) {
            throw new RuntimeException('Backup file is missing on disk — cannot restore.');
        }

        $conn = $this->connectionFor($tenant);
        if (empty($conn['database'])) {
            throw new RuntimeException("Tenant [{$tenant->tenant_code}] has no database to restore into.");
        }

        if (! $this->ensureBinary((string) config('backup.mysql_binary', 'mysql'))) {
            throw new RuntimeException('mysql client not found. Set MYSQL_BINARY in .env to its absolute path.');
        }

        // 1) Pre-restore safety backup (best-effort but required to succeed).
        $this->backup($tenant, 'pre_restore', $userId, "Auto pre-restore backup before restoring #{$backup->id}");

        // 2) Import the chosen dump into the tenant DB.
        $absSource = Storage::disk($backup->disk)->path($backup->path);
        $res = $this->importDatabase($conn, $absSource);

        if (! $res['ok']) {
            throw new RuntimeException("Restore failed for [{$tenant->tenant_code}]: " . ($res['error'] ?? 'mysql import error'));
        }

        $backup->update(['restored_at' => now(), 'restored_by' => $userId]);

        Log::warning('tenant_backup.restored', [
            'tenant' => $tenant->tenant_code, 'backup' => $backup->id, 'by' => $userId,
        ]);

        return $backup;
    }

    public function deleteBackup(TenantBackup $backup): void
    {
        if ($backup->path && Storage::disk($backup->disk)->exists($backup->path)) {
            Storage::disk($backup->disk)->delete($backup->path);
        }
        $backup->delete();
    }

    /** Resolve tenant DB credentials (mirrors TenantsBackupCommand). */
    private function connectionFor(Tenant $tenant): array
    {
        $db = $tenant->database;

        return [
            'host'     => $db?->db_host ?: env('TENANT_DB_HOST', '127.0.0.1'),
            'port'     => $db?->db_port ?: env('TENANT_DB_PORT', 3306),
            'username' => $db?->db_username ?: env('TENANT_DB_USERNAME', 'root'),
            'password' => $db?->db_password ?? env('TENANT_DB_PASSWORD', ''),
            'database' => $db?->db_database,
        ];
    }

    /** Dump one DB to $targetFile via a temp option file (password never on CLI). */
    private function dumpDatabase(array $conn, string $targetFile): array
    {
        $cnf = $this->writeOptionFile($conn);
        $fh  = false;
        try {
            $fh = fopen($targetFile, 'w');
            $process = new Process([
                config('backup.mysql_dump_binary', 'mysqldump'),
                '--defaults-extra-file=' . $cnf,
                '--single-transaction', '--quick', '--skip-lock-tables',
                '--routines', '--no-tablespaces',
                $conn['database'],
            ]);
            $process->setTimeout(null);

            $err = '';
            $process->run(function ($type, $buffer) use ($fh, &$err) {
                if ($type === Process::OUT) {
                    fwrite($fh, $buffer);
                } else {
                    $err .= $buffer;
                }
            });

            if ($fh) { fclose($fh); $fh = false; }

            if (! $process->isSuccessful()) {
                @unlink($targetFile);
                return ['ok' => false, 'error' => trim($err) ?: 'mysqldump failed'];
            }

            return ['ok' => true, 'bytes' => @filesize($targetFile) ?: 0];
        } catch (Throwable $e) {
            if ($fh) { fclose($fh); }
            @unlink($targetFile);
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            @unlink($cnf);
        }
    }

    /** Import a SQL file into a DB by streaming it to the mysql client's stdin. */
    private function importDatabase(array $conn, string $sourceFile): array
    {
        if (! is_file($sourceFile)) {
            return ['ok' => false, 'error' => 'backup file not found'];
        }

        $cnf = $this->writeOptionFile($conn);
        $in  = false;
        try {
            $in = fopen($sourceFile, 'r');
            $process = new Process([
                config('backup.mysql_binary', 'mysql'),
                '--defaults-extra-file=' . $cnf,
                $conn['database'],
            ]);
            $process->setTimeout(null);
            $process->setInput($in);
            $process->run();

            if (! $process->isSuccessful()) {
                return ['ok' => false, 'error' => trim($process->getErrorOutput()) ?: 'mysql import failed'];
            }

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if ($in) { fclose($in); }
            @unlink($cnf);
        }
    }

    private function writeOptionFile(array $conn): string
    {
        $cnf = tempnam(sys_get_temp_dir(), 'tbk');
        file_put_contents(
            $cnf,
            "[client]\nhost=\"{$conn['host']}\"\nport={$conn['port']}\nuser=\"{$conn['username']}\"\npassword=\"{$conn['password']}\"\n"
        );

        return $cnf;
    }

    private function ensureBinary(string $binary): bool
    {
        try {
            $probe = new Process([$binary, '--version']);
            $probe->setTimeout(15);
            $probe->run();

            return $probe->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }
}
