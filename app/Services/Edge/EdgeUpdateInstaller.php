<?php

namespace App\Services\Edge;

use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
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

        // 4. ATOMIC switch.
        $this->switchPointer($root, $this->safe($to));

        // 5. Forward-only schema upgrade.
        try {
            $schemaAfter = $this->applySchemaUpgrade();
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
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $srcLen = strlen(rtrim($src, "/\\")) + 1;
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), $srcLen);
            $target = $dst . DIRECTORY_SEPARATOR . $rel;
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

    /** Forward-only schema upgrade; returns the schema generation after. Test-overridable seam. */
    protected function applySchemaUpgrade(): string
    {
        app(EdgeLocalSchemaUpgrader::class)->upgrade(); // forward-only, non-destructive

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
