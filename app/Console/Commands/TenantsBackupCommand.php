<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Multi-tenant backup foundation (PRD-3).
 *
 * Dumps the master DB and every tenant DB (database-per-tenant) plus an optional
 * storage/app archive, into a timestamped folder with a manifest. Read-only with
 * respect to the databases — it never creates, drops, or restores anything.
 */
class TenantsBackupCommand extends Command
{
    protected $signature = 'tenants:backup
        {--tenant= : Backup only one tenant (code or id)}
        {--master-only : Backup the master DB only}
        {--tenants-only : Backup tenant DBs only}
        {--no-storage : Skip the storage/app archive}
        {--dry-run : Show what would be backed up without writing anything}
        {--prune : Delete backup folders older than the retention period}';

    protected $description = 'Back up master + tenant databases and storage files (no restore, no DB drops).';

    public function handle(): int
    {
        if ($this->option('master-only') && $this->option('tenants-only')) {
            $this->error('Use either --master-only or --tenants-only, not both.');
            return self::FAILURE;
        }

        $dryRun   = (bool) $this->option('dry-run');
        $diskName = config('backup.disk', 'local');
        $basePath = trim((string) config('backup.path', 'backups'), '/');
        $disk     = Storage::disk($diskName);

        $timestamp = Carbon::now()->format('Ymd_His');
        $relDir    = $basePath . '/' . $timestamp;

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Backup target: {$diskName}:{$relDir}");

        // ── Resolve which tenants to back up ────────────────────────────────
        $tenants = collect();
        if (! $this->option('master-only')) {
            $query = Tenant::with('database');
            if ($code = $this->option('tenant')) {
                $query->where('tenant_code', $code)->orWhere('id', is_numeric($code) ? (int) $code : 0);
            }
            $tenants = $query->get();

            if ($this->option('tenant') && $tenants->isEmpty()) {
                $this->error("Tenant [{$this->option('tenant')}] not found.");
                return self::FAILURE;
            }
        }

        // Storage is app-wide: include it unless explicitly skipped.
        $includeStorage = config('backup.include_storage', true) && ! $this->option('no-storage');

        // ── Plan ────────────────────────────────────────────────────────────
        $plan = [];
        if (! $this->option('tenants-only')) {
            $plan[] = ['master', config('database.connections.master.database'), 'master.sql'];
        }
        foreach ($tenants as $tenant) {
            $dbName = $tenant->database?->db_database;
            $plan[] = [
                'tenant:' . $tenant->tenant_code,
                $dbName ?: '(missing — will skip)',
                'tenant_' . $tenant->tenant_code . '.sql',
            ];
        }
        if ($includeStorage) {
            $plan[] = ['storage', 'storage/app', 'storage.tar.gz'];
        }

        $this->table(['Target', 'Source', 'File'], $plan);

        if ($dryRun) {
            $this->info('[DRY RUN] No files written. Re-run without --dry-run to create the backup.');
            if ($this->option('prune')) {
                $this->line('[DRY RUN] Would prune backups older than ' . config('backup.retention_days') . ' days.');
            }
            return self::SUCCESS;
        }

        // ── Verify mysqldump is available before writing anything ───────────
        if (! $this->ensureBinary(config('backup.mysql_dump_binary', 'mysqldump'))) {
            $this->error('mysqldump not found. Set MYSQLDUMP_BINARY in .env to its absolute path.');
            return self::FAILURE;
        }

        $disk->makeDirectory($relDir);
        $absDir = $disk->path($relDir);

        $results  = [];
        $hadError = false;

        // ── Master DB ───────────────────────────────────────────────────────
        if (! $this->option('tenants-only')) {
            $master = config('database.connections.master');
            $res = $this->dumpDatabase([
                'host'     => $master['host'] ?? '127.0.0.1',
                'port'     => $master['port'] ?? 3306,
                'username' => $master['username'] ?? 'root',
                'password' => $master['password'] ?? '',
                'database' => $master['database'] ?? '',
            ], $absDir . DIRECTORY_SEPARATOR . 'master.sql');
            $results[] = ['master', $master['database'] ?? '—', 'master.sql', $this->statusLabel($res)];
            $hadError = $hadError || ! $res['ok'];
        }

        // ── Tenant DBs ──────────────────────────────────────────────────────
        $tenantManifest = [];
        foreach ($tenants as $tenant) {
            $db = $tenant->database;
            $file = 'tenant_' . $tenant->tenant_code . '.sql';
            if (! $db || ! $db->db_database) {
                $results[] = ['tenant:' . $tenant->tenant_code, '—', $file, 'SKIPPED (no db)'];
                $tenantManifest[] = ['code' => $tenant->tenant_code, 'id' => $tenant->id, 'db' => null, 'file' => null, 'status' => 'skipped'];
                continue;
            }
            // PROD-FIX: config fallbacks, not env() — runtime env() is null
            // under `config:cache` and would fall back to root/no-password.
            $template = config('database.connections.tenant');
            $res = $this->dumpDatabase([
                'host'     => $db->db_host ?: ($template['host'] ?? '127.0.0.1'),
                'port'     => $db->db_port ?: ($template['port'] ?? 3306),
                'username' => $db->db_username ?: ($template['username'] ?? 'root'),
                'password' => $db->db_password ?? ($template['password'] ?? ''),
                'database' => $db->db_database,
            ], $absDir . DIRECTORY_SEPARATOR . $file);
            $results[] = ['tenant:' . $tenant->tenant_code, $db->db_database, $file, $this->statusLabel($res)];
            $tenantManifest[] = [
                'code'   => $tenant->tenant_code,
                'id'     => $tenant->id,
                'db'     => $db->db_database,
                'file'   => $res['ok'] ? $file : null,
                'status' => $res['ok'] ? 'ok' : 'failed',
            ];
            $hadError = $hadError || ! $res['ok'];
        }

        // ── Storage archive ─────────────────────────────────────────────────
        $storageStatus = 'skipped';
        if ($includeStorage) {
            $res = $this->archiveStorage($absDir . DIRECTORY_SEPARATOR . 'storage.tar.gz', $basePath);
            $storageStatus = $res['ok'] ? 'ok' : 'failed';
            $results[] = ['storage', 'storage/app', 'storage.tar.gz', $res['ok'] ? 'OK' : ('WARN: ' . ($res['error'] ?? 'archive failed'))];
            // A failed storage archive is a warning, not a hard failure.
        }

        // ── Manifest ────────────────────────────────────────────────────────
        $manifest = [
            'app_name'        => config('app.name'),
            'app_env'         => config('app.env'),
            'created_at'      => Carbon::now()->toIso8601String(),
            'git_commit'      => $this->gitCommit(),
            'master_db'       => $this->option('tenants-only') ? null : (config('database.connections.master.database')),
            'tenants'         => $tenantManifest,
            'storage_included'=> $includeStorage ? $storageStatus : false,
            'options'         => [
                'tenant'       => $this->option('tenant'),
                'master_only'  => (bool) $this->option('master-only'),
                'tenants_only' => (bool) $this->option('tenants-only'),
                'no_storage'   => (bool) $this->option('no-storage'),
            ],
        ];
        file_put_contents($absDir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->table(['Target', 'Source', 'File', 'Status'], $results);
        $this->info('Manifest: ' . $relDir . '/manifest.json');

        if ($this->option('prune')) {
            $this->prune($disk, $basePath);
        }

        if ($hadError) {
            $this->error('Backup completed WITH ERRORS — review the status table above.');
            return self::FAILURE;
        }

        $this->info('Backup completed successfully: ' . $absDir);
        return self::SUCCESS;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** Dump one database to $targetFile using a temp option file (no password on CLI). */
    private function dumpDatabase(array $conn, string $targetFile): array
    {
        if (empty($conn['database'])) {
            return ['ok' => false, 'error' => 'no database name'];
        }

        $cnf = tempnam(sys_get_temp_dir(), 'bkp');
        file_put_contents(
            $cnf,
            "[client]\nhost=\"{$conn['host']}\"\nport={$conn['port']}\nuser=\"{$conn['username']}\"\npassword=\"{$conn['password']}\"\n"
        );

        $fh = false;
        try {
            $fh = fopen($targetFile, 'w');
            // --defaults-extra-file MUST be the first argument.
            $process = new Process([
                config('backup.mysql_dump_binary', 'mysqldump'),
                '--defaults-extra-file=' . $cnf,
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--routines',
                '--no-tablespaces',
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

            if ($fh) {
                fclose($fh);
                $fh = false;
            }

            if (! $process->isSuccessful()) {
                @unlink($targetFile);
                return ['ok' => false, 'error' => trim($err) ?: 'mysqldump failed'];
            }

            return ['ok' => true, 'bytes' => @filesize($targetFile) ?: 0];
        } catch (Throwable $e) {
            if ($fh) {
                fclose($fh);
            }
            @unlink($targetFile);
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            @unlink($cnf);
        }
    }

    /** Archive storage/app (excluding the backups dir + .env never lives here) via tar. */
    private function archiveStorage(string $targetFile, string $excludeRel): array
    {
        try {
            $process = new Process([
                'tar', '-czf', $targetFile,
                '-C', storage_path('app'),
                '--exclude=./' . trim($excludeRel, '/'),
                '.',
            ]);
            $process->setTimeout(null);
            $process->run();

            if (! $process->isSuccessful()) {
                @unlink($targetFile);
                return ['ok' => false, 'error' => trim($process->getErrorOutput()) ?: 'tar unavailable (storage archive skipped)'];
            }
            return ['ok' => true, 'bytes' => @filesize($targetFile) ?: 0];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function ensureBinary(string $binary): bool
    {
        try {
            $p = new Process([$binary, '--version']);
            $p->setTimeout(15);
            $p->run();
            return $p->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    private function gitCommit(): ?string
    {
        try {
            $p = new Process(['git', 'rev-parse', '--short', 'HEAD'], base_path());
            $p->setTimeout(10);
            $p->run();
            return $p->isSuccessful() ? trim($p->getOutput()) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function statusLabel(array $res): string
    {
        return $res['ok']
            ? 'OK (' . $this->humanBytes($res['bytes'] ?? 0) . ')'
            : 'FAILED: ' . ($res['error'] ?? 'unknown');
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    /** Delete backup folders older than retention_days. Never touches anything else. */
    private function prune($disk, string $basePath): void
    {
        $days   = (int) config('backup.retention_days', 14);
        $cutoff = Carbon::now()->subDays($days);
        $pruned = 0;

        foreach ($disk->directories($basePath) as $dir) {
            $name = basename($dir); // expected: Ymd_His
            $date = null;
            try {
                $date = Carbon::createFromFormat('Ymd_His', $name);
            } catch (Throwable) {
                continue; // not a backup folder — skip
            }
            if ($date && $date->lt($cutoff)) {
                $disk->deleteDirectory($dir);
                $pruned++;
            }
        }

        $this->info("Prune: removed {$pruned} backup folder(s) older than {$days} days.");
    }
}
