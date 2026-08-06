<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeArtifactBuilder;
use Illuminate\Console\Command;

/**
 * EDGE-RUNTIME-BUILD-RELEASE-CLOSURE-1 — build a restricted Bingoo Edge artifact.
 *
 *   php artisan edge:build-artifact {dest} [--force] [--allow-dirty] [--git-commit=]
 *
 * RELEASE (default): refuses a dirty working tree, stamps the manifest with the ACTUAL git HEAD, and
 * sets source_dirty=false — so a release artifact always describes the exact committed bytes it was
 * built from. --git-commit is NOT accepted in release mode.
 *
 * DEV/TEST (--allow-dirty): permits a dirty tree and a --git-commit override for fast local testing;
 * the manifest records source_dirty=true / build_mode=dev so it can never be mistaken for a release.
 *
 * --force wipes a NON-EMPTY destination first (recreated empty), so a prior artifact's stale files
 * (a leftover .env / .pem / dump) can never survive into a new build. Guards against unsafe targets.
 */
class EdgeBuildArtifactCommand extends Command
{
    protected $signature = 'edge:build-artifact {dest : Destination directory for the artifact}
                            {--force : Delete a non-empty (prior-artifact) destination before building}
                            {--allow-dirty : DEV/TEST only — permit a dirty tree + --git-commit override}
                            {--git-commit= : (dev only) override the stamped commit}';

    protected $description = 'Build a restricted Bingoo Edge artifact (release = clean-HEAD provenance; physically audited).';

    public function handle(): int
    {
        $dest = rtrim(str_replace('\\', '/', (string) $this->argument('dest')), '/');
        $release = ! $this->option('allow-dirty');

        // ── Provenance ────────────────────────────────────────────────────────────────────────
        $head = trim((string) @exec('git rev-parse HEAD 2>/dev/null'));
        $dirty = trim((string) @exec('git status --porcelain --untracked-files=no 2>/dev/null')) !== '';

        if ($release) {
            if ($head === '') {
                $this->error('Release build needs a git HEAD to stamp provenance. Use --allow-dirty for a dev build.');

                return self::FAILURE;
            }
            if ($dirty) {
                $this->error('Release build REFUSED — the working tree has uncommitted tracked changes. Commit first, or use --allow-dirty for a dev/test build.');

                return self::FAILURE;
            }
            if ($this->option('git-commit')) {
                $this->error('--git-commit is not allowed for a release build (provenance = actual HEAD).');

                return self::FAILURE;
            }
            $commit = $head;
            $sourceDirty = false;
        } else {
            $commit = (string) ($this->option('git-commit') ?: $head ?: 'unknown');
            $sourceDirty = $dirty || (bool) $this->option('git-commit');
        }

        // ── Destination safety + --force wipe ─────────────────────────────────────────────────
        if (is_dir($dest) && (glob($dest . '/*') ?: []) !== []) {
            if (! $this->option('force')) {
                $this->error("Destination [$dest] is not empty. Use --force to rebuild it.");

                return self::FAILURE;
            }
            if (($reason = $this->unsafeDestination($dest)) !== null) {
                $this->error("Refusing to --force-delete [$dest]: $reason");

                return self::FAILURE;
            }
            // Only ever wipe a directory that is itself a prior artifact.
            if (! is_file($dest . '/edge-build-manifest.json')) {
                $this->error("Refusing to --force-delete [$dest]: it is not a previous artifact (no edge-build-manifest.json). Point --force at a fresh path or an existing artifact.");

                return self::FAILURE;
            }
            $this->recursiveDelete($dest);
        }
        @mkdir($dest, 0755, true);

        $meta = [
            'git_commit'       => $commit,
            'source_dirty'     => $sourceDirty,
            'build_mode'       => $release ? 'release' : 'dev',
            'build_timestamp'  => now()->toIso8601String(),
            'artifact_version' => (string) config('edge.app_version') . '+' . substr($commit ?: 'nocommit', 0, 12) . ($sourceDirty ? '-dirty' : ''),
        ];

        try {
            $summary = EdgeArtifactBuilder::fromConfig()->build(base_path(), $dest, $meta);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($release ? 'RELEASE' : 'DEV') . ' Edge artifact built at: ' . $dest);
        $this->table(['field', 'value'], collect($summary)->map(fn ($v, $k) => [$k, is_scalar($v) ? var_export($v, true) : json_encode($v)])->values()->all());

        return self::SUCCESS;
    }

    /** Return a reason string if $dest is an unsafe delete target, else null. */
    private function unsafeDestination(string $dest): ?string
    {
        $abs = rtrim(str_replace('\\', '/', realpath($dest) ?: $dest), '/');
        $base = rtrim(str_replace('\\', '/', base_path()), '/');
        $home = rtrim(str_replace('\\', '/', (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: '')), '/');

        if ($abs === '' || preg_match('#^[a-zA-Z]:?$#', $abs) || $abs === '/') {
            return 'a filesystem/drive root';
        }
        if (substr_count($abs, '/') < 2) {
            return 'too shallow a path';
        }
        if ($abs === $base || str_starts_with($base . '/', $abs . '/')) {
            return 'the project directory (or an ancestor of it)';
        }
        if ($home !== '' && $abs === $home) {
            return 'the home directory';
        }

        return null;
    }

    private function recursiveDelete(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
