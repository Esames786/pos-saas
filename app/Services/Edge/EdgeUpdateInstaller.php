<?php

namespace App\Services\Edge;

use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OFFLINE EDGE PRODUCTIZATION (O) — install a verified update, atomically, with rollback.
 *
 * Sequence (fail closed, evidence recorded to edge_local_updates):
 *   1. preflight (branch server) + VERIFY the signed package — before touching anything;
 *   2. take + verify an encrypted PRE-UPDATE backup (refuse the update if it fails);
 *   3. stage the new artifact into a NEW versioned directory (the active runtime is never overwritten in
 *      place) and re-verify its manifest hash;
 *   4. ATOMIC switch of the `current` pointer (temp file + rename) to the new version;
 *   5. forward-only schema upgrade;
 *   6. record success.
 *
 * Rollback: a failure BEFORE the switch leaves the previous version active (nothing changed). A failure
 * AFTER the switch but before/at the schema upgrade reverts the pointer to the previous version; because the
 * schema contract is forward-only, if the previous runtime is not schema-compatible the recorded outcome is
 * `restore_required` (recover from the verified pre-update backup) — never a blind down-migration. The DB
 * itself is only touched by the forward schema upgrade, so a code switch never loses the outbox / held /
 * shift / baseline state.
 */
class EdgeUpdateInstaller
{
    public const CONN = 'tenant';

    public function __construct(
        private readonly EdgeUpdateVerifier $verifier,
        private readonly EdgeUpdatePackageService $packages,
        private readonly EdgeBackupService $backups,
    ) {
    }

    public function install(array $package, string $stagedArtifactDir, string $performedBy): array
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('UPDATE_NOT_BRANCH_SERVER: appliance update runs only on a Branch Server.');
        }
        $currentVersion = (string) config('edge.app_version');
        $currentSchema = (string) config('edge.config_schema');
        $to = (string) (($package['payload'] ?? [])['edge_app_version'] ?? '');
        $audit = [
            'update_uuid' => (string) Str::ulid(),
            'from_version' => $currentVersion,
            'to_version' => $to,
            'package_hash' => (string) ($package['signature'] ?? ''),
            'artifact_manifest_hash' => (string) (($package['payload'] ?? [])['artifact_manifest_hash'] ?? ''),
            'schema_before' => $currentSchema,
            'performed_by' => mb_substr($performedBy, 0, 191),
            'started_at' => now(),
        ];

        // 1. VERIFY — zero mutation before this passes.
        try {
            $this->verifier->verify($package, $stagedArtifactDir, $currentVersion, $currentSchema);
        } catch (\Throwable $e) {
            $this->record($audit, 'refused', $this->code($e), null, $currentSchema);
            throw $e;
        }

        // 2. Pre-update backup (and verify it) — refuse the update if we cannot protect the current state.
        try {
            $backup = $this->backups->backup();
            $this->backups->decodeAndVerify($backup->path);
        } catch (\Throwable $e) {
            $this->record($audit, 'refused', 'UPDATE_PREUPDATE_BACKUP_FAILED', null, $currentSchema);
            throw new RuntimeException('UPDATE_PREUPDATE_BACKUP_FAILED: ' . $e->getMessage());
        }

        $root = $this->installRoot();
        $previous = $this->currentPointer($root);
        $versionDir = $root . DIRECTORY_SEPARATOR . 'versions' . DIRECTORY_SEPARATOR . $this->safe($to);

        // 3. Stage into a NEW directory (never overwrite the active runtime in place) + re-verify.
        try {
            $this->stopWorkers();
            $this->stage($stagedArtifactDir, $versionDir);
            if (! hash_equals($audit['artifact_manifest_hash'], $this->packages->recomputeManifestHash($versionDir))) {
                throw new RuntimeException('UPDATE_STAGE_MISMATCH: staged bytes do not match the signed manifest.');
            }
        } catch (\Throwable $e) {
            // Failure before the switch — the previous version stays active, nothing was pointed at the new one.
            $this->record($audit, 'failed', $this->code($e) ?: 'UPDATE_STAGE_FAILED', 'none', $currentSchema);
            throw $e;
        }

        // 3b. The staged runtime must be able to BOOT on its own before the switch: writable runtime dirs and, for a dev/test
        //     package whose app\vendor is a junction to the shared closure, the same junction (a release ships real files).
        $this->prepareStagedRuntime($stagedArtifactDir, $versionDir);
        // 4. ATOMIC switch.
        $this->switchPointer($root, $this->safe($to));

        // 5. Forward-only schema upgrade — run by the NEW runtime (its own migration files), never by this process,
        //    which was booted from the OLD version (EDGE-UPDATE-SCHEMA-IN-NEW-RUNTIME-1, LAB 0.6.0→0.7.0, 25 Sep 2026).
        try {
            $schemaAfter = $this->applySchemaUpgrade($versionDir);
        } catch (\Throwable $e) {
            if ($previous !== null) {
                $this->switchPointer($root, $previous);                 // revert the runtime pointer
                $rollback = 'reverted_runtime';
            } else {
                $rollback = 'restore_required';                          // recover from the pre-update backup
            }
            $this->record($audit, 'rolled_back', 'UPDATE_SCHEMA_UPGRADE_FAILED', $rollback, $currentSchema);
            throw new RuntimeException('UPDATE_SCHEMA_UPGRADE_FAILED (rollback=' . $rollback . '): ' . $e->getMessage());
        }

        // 6. Success.
        $this->startWorkers();
        $this->record($audit, 'applied', null, 'none', $schemaAfter);

        return [
            'result' => 'applied',
            'from_version' => $currentVersion,
            'to_version' => $to,
            'active_version' => $this->currentPointer($root),
            'pre_update_backup' => $backup->path,
            'schema_after' => $schemaAfter,
        ];
    }

    /** The currently active version, or null if none is set yet. */
    public function currentPointer(string $root): ?string
    {
        $file = $root . DIRECTORY_SEPARATOR . 'current';
        if (! is_file($file)) {
            return null;
        }
        $v = trim((string) file_get_contents($file));

        return $v !== '' ? $v : null;
    }

    /** Atomic pointer switch: write a temp file then rename over `current`. */
    private function switchPointer(string $root, string $version): void
    {
        $file = $root . DIRECTORY_SEPARATOR . 'current';
        $tmp = $file . '.' . Str::lower(Str::random(6)) . '.tmp';
        file_put_contents($tmp, $version);
        if (! @rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('UPDATE_SWITCH_FAILED: could not atomically switch the active version.');
        }
    }

    private function stage(string $src, string $dst): void
    {
        if (! is_dir($src)) {
            throw new RuntimeException('UPDATE_STAGE_SOURCE_MISSING: ' . $src);
        }
        if (! is_dir($dst) && ! @mkdir($dst, 0775, true) && ! is_dir($dst)) {
            throw new RuntimeException('UPDATE_STAGE_DIR: could not create ' . $dst);
        }
        // P5: a release artifact is ~10k files; PHP copy() manages ~20 files/s on a Windows appliance disk. Windows ships
        // robocopy — use it for the bulk copy (never following junctions: /XJ) and fall back to the PHP loop elsewhere.
        // The signed-manifest check after staging still verifies every listed byte, whichever path copied them.
        if (DIRECTORY_SEPARATOR === '\\' && $this->robocopy($src, $dst)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $srcLen = strlen(rtrim($src, "/\\")) + 1;
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), $srcLen);
            $target = $dst . DIRECTORY_SEPARATOR . $rel;
            // A staged runtime never carries a link: a symlink / Windows junction (a dev package's shared vendor closure) is
            // neither followed nor copied — the operator tooling re-links it; a release artifact ships real files only.
            if ($item->isLink() || $this->isReparsePoint($item->getPathname())) {
                continue;
            }
            if ($item->isDir()) {
                if (! is_dir($target)) {
                    @mkdir($target, 0775, true);
                }
            } else {
                $dir = dirname($target);
                if (! is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                if (! @copy($item->getPathname(), $target)) {
                    throw new RuntimeException('UPDATE_STAGE_COPY_FAILED: ' . $rel);
                }
            }
        }
    }

    /** robocopy /E /XJ; exit codes 0-7 = success. Returns false when robocopy is unavailable so the PHP loop runs. */
    private function robocopy(string $src, string $dst): bool
    {
        $exe = getenv('SystemRoot') ? getenv('SystemRoot') . '\\System32\\robocopy.exe' : 'robocopy.exe';
        if (! is_file($exe)) {
            return false;
        }
        $cmd = '"' . $exe . '" "' . rtrim($src, '/\\') . '" "' . rtrim($dst, '/\\') . '" /E /XJ /NFL /NDL /NJH /NJS /NP /R:2 /W:1';
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        if ($code >= 8) {
            throw new RuntimeException('UPDATE_STAGE_COPY_FAILED: robocopy exit ' . $code . ' ' . mb_substr(implode(' ', $out), 0, 200));
        }

        return true;
    }

    /**
     * Make the staged version bootable as a separate process (the schema upgrade runs there): create the runtime
     * directories a fresh copy lacks, and re-link a dev/test package's vendor junction (stage() never copies links;
     * the operator tooling used to re-link it only AFTER the update, too late for the schema step).
     */
    private function prepareStagedRuntime(string $stagedArtifactDir, string $versionDir): void
    {
        foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'storage/app'] as $dir) {
            $path = $versionDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
            if (! is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
        $srcVendor = $stagedArtifactDir . DIRECTORY_SEPARATOR . 'vendor';
        $dstVendor = $versionDir . DIRECTORY_SEPARATOR . 'vendor';
        if (DIRECTORY_SEPARATOR !== '\\' || is_dir($dstVendor) || ! is_dir($srcVendor) || ! $this->isReparsePoint($srcVendor)) {
            return;
        }
        $target = @realpath($srcVendor);
        if ($target === false) {
            return;
        }
        $out = [];
        $code = 1;
        @exec('cmd /c mklink /J "' . $dstVendor . '" "' . $target . '" 2>&1', $out, $code);
        if ($code !== 0 || ! is_dir($dstVendor)) {
            throw new RuntimeException('UPDATE_STAGE_FAILED: could not re-link the dev vendor junction for the staged runtime: ' . mb_substr(implode(' ', $out), 0, 200));
        }
    }
    /** Windows junction / mount point detection (PHP reports some reparse points as directories, not links). */
    private function isReparsePoint(string $path): bool
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return false;
        }
        $real = @realpath($path);
        if ($real === false) {
            return false;
        }
        $norm = fn (string $p) => strtolower(rtrim(str_replace('/', '\\', $p), '\\'));

        return $norm($real) !== $norm($path);
    }

    /**
     * Forward-only schema upgrade; returns the schema generation after. Test-overridable seam.
     *
     * EDGE-UPDATE-SCHEMA-IN-NEW-RUNTIME-1 — the LAB 0.6.0→0.7.0 update (25 Sep 2026) proved that running the upgrader
     * IN THIS PROCESS silently skips every migration the new version ships: `edge:local:update` is launched through the
     * appliance launcher, which resolved the OLD runtime before the pointer switch, so database_path() pointed at the
     * OLD version's migration files, "pending" was empty and the update still recorded "applied". The upgrade therefore
     * runs as a CHILD PROCESS of the NEW version's own artisan (`<versions>/<to>/artisan edge:local:schema-upgrade`),
     * with the same PHP binary and the inherited appliance environment (BINGOO_EDGE_ENV_DIR). Any non-zero exit fails
     * closed → the caller reverts the pointer (reverted_runtime) or reports restore_required, exactly as before.
     */
    protected function applySchemaUpgrade(string $versionDir): string
    {
        $artisan = $versionDir . DIRECTORY_SEPARATOR . 'artisan';
        if (! is_file($artisan)) {
            throw new RuntimeException("UPDATE_SCHEMA_UPGRADE_FAILED: the staged runtime has no artisan launcher at [{$artisan}].");
        }
        $cmd = [PHP_BINARY, $artisan, 'edge:local:schema-upgrade', '--no-interaction'];
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = array_merge(getenv(), array_filter(['BINGOO_EDGE_ENV_DIR' => getenv('BINGOO_EDGE_ENV_DIR') ?: null]));
        $proc = proc_open($cmd, $spec, $pipes, $versionDir, $env);   // array form: no shell, no quoting ambiguity (Windows-safe)
        if (! is_resource($proc)) {
            throw new RuntimeException('UPDATE_SCHEMA_UPGRADE_FAILED: could not start the new runtime for the schema upgrade.');
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        Log::info('[edge-update] schema upgrade in the new runtime', ['version_dir' => $versionDir, 'exit' => $code, 'output' => mb_substr($out . $err, 0, 4000)]);
        if ($code !== 0) {
            throw new RuntimeException('UPDATE_SCHEMA_UPGRADE_FAILED: the new runtime exited ' . $code . ': ' . mb_substr(trim($out . "\n" . $err), 0, 1500));
        }

        return (string) config('edge.config_schema');
    }

    /** Cooperative worker stop/start around the switch window. Overridable; a real appliance drives the tasks. */
    protected function stopWorkers(): void {}

    protected function startWorkers(): void {}

    private function installRoot(): string
    {
        $root = (string) config('edge.update.install_root');
        if ($root === '') {
            throw new RuntimeException('UPDATE_NO_INSTALL_ROOT: edge.update.install_root is not configured.');
        }
        if (! is_dir($root) && ! @mkdir($root, 0775, true) && ! is_dir($root)) {
            throw new RuntimeException('UPDATE_INSTALL_ROOT: could not create ' . $root);
        }

        return rtrim($root, "/\\");
    }

    private function record(array $audit, string $result, ?string $failureCode, ?string $rollback, ?string $schemaAfter): void
    {
        DB::connection(self::CONN)->table('edge_local_updates')->insert(array_merge($audit, [
            'result' => $result,
            'failure_code' => $failureCode,
            'rollback_result' => $rollback,
            'schema_after' => $schemaAfter,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function code(\Throwable $e): ?string
    {
        return preg_match('/^([A-Z_]+):/', $e->getMessage(), $m) ? $m[1] : null;
    }

    private function safe(string $version): string
    {
        return preg_replace('/[^A-Za-z0-9._+-]/', '_', $version);
    }
}
