<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeArtifactBuilder;
use App\Services\Edge\EdgePackageBuilder;
use App\Services\Edge\EdgeUpdatePackageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * P4 §5 — build the restricted Windows appliance package (BUILD HOST / Cloud tree only; never allowlisted on an
 * appliance). Same provenance rules as edge:build-artifact: a release build refuses a dirty tree and stamps the real
 * HEAD; --allow-dirty produces a DEV package that can never be mistaken for a release.
 *
 *   php artisan edge:build-package D:\out\BingooEdge-0.1.0 --php-runtime="D:\laragon2\bin\php\php-8.3.16-Win32-vs16-x64" \
 *        --gateway="D:\laragon2\bin\nginx\nginx-1.22.0\nginx.exe" --signing-key-file=D:\secure\edge-update-signing.key
 *
 * The signing key is read from a FILE (or EDGE_UPDATE_SIGNING_KEY on the build host) — never from argv.
 */
class EdgeBuildPackageCommand extends Command
{
    protected $signature = 'edge:build-package {dest : Destination directory (must be empty or absent)}
        {--php-runtime= : PHP runtime directory to bundle (php.exe inside); omit to rely on the installer -PhpPath}
        {--gateway= : nginx.exe to bundle; omit to rely on the installer -GatewayPath}
        {--signing-key-file= : File holding the base64 Ed25519 update signing key (else EDGE_UPDATE_SIGNING_KEY)}
        {--no-sign : Build without a signed update package (dev only)}
        {--allow-dirty : DEV/TEST only — permit a dirty tree + --git-commit override}
        {--git-commit= : (dev only) override the stamped commit}
        {--vendor-junction= : (dev/test only) build app/ without vendor and junction this vendor dir into it}
        {--force : Delete a non-empty prior PACKAGE at dest first}';

    protected $description = 'Build the restricted Bingoo Edge Windows appliance package from the accepted artifact (boundary-audited).';

    public function handle(EdgeUpdatePackageService $updates): int
    {
        $dest = rtrim(str_replace('\\', '/', (string) $this->argument('dest')), '/');
        $release = ! $this->option('allow-dirty');
        $head = trim((string) (Process::run('git rev-parse HEAD')->output() ?? ''));
        $dirty = trim((string) (Process::run('git status --porcelain --untracked-files=no')->output() ?? '')) !== '';
        if ($release) {
            if ($head === '' || $dirty || $this->option('git-commit')) {
                $this->error('Release package REFUSED — needs a clean committed tree and no --git-commit override. Use --allow-dirty for a dev/test package.');

                return self::FAILURE;
            }
            $commit = $head;
            $sourceDirty = false;
        } else {
            $commit = (string) ($this->option('git-commit') ?: $head ?: 'unknown');
            $sourceDirty = $dirty || (bool) $this->option('git-commit');
        }
        if (is_dir($dest) && (glob($dest . '/*') ?: []) !== []) {
            if (! $this->option('force') || ! is_file($dest . '/package-manifest.json')) {
                $this->error("Destination [$dest] is not empty (use --force only on a previous package with package-manifest.json).");

                return self::FAILURE;
            }
            $this->recursiveDelete($dest);
        }
        $key = '';
        if (! $this->option('no-sign')) {
            $file = (string) ($this->option('signing-key-file') ?? '');
            $key = $file !== '' ? trim((string) @file_get_contents($file)) : trim((string) config('edge.update.signing_key', ''));
            if ($key === '') {
                if ($release) {
                    $this->error('A release package must be signed: --signing-key-file or EDGE_UPDATE_SIGNING_KEY (build host only).');

                    return self::FAILURE;
                }
                $this->warn('No signing key — building an UNSIGNED dev package.');
            }
        }
        $artifactConfig = (array) config('edge.artifact');
        $artifactConfig['runtime_dirs'] = ['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'storage/app'];
        if ($this->option('vendor-junction')) {
            $artifactConfig['include'] = array_values(array_diff((array) $artifactConfig['include'], ['vendor']));
        }
        $meta = [
            'git_commit' => $commit,
            'source_dirty' => $sourceDirty,
            'build_mode' => $release ? 'release' : 'dev',
            'build_timestamp' => now()->toIso8601String(),
            'artifact_version' => (string) config('edge.app_version') . '+' . substr($commit ?: 'nocommit', 0, 12) . ($sourceDirty ? '-dirty' : ''),
        ];
        $builder = new EdgePackageBuilder(new EdgeArtifactBuilder($artifactConfig), $updates);
        try {
            $summary = $builder->build($dest, [
                'source_root' => base_path(),
                'artifact_meta' => $meta,
                'php_runtime' => $this->option('php-runtime') ?: null,
                'gateway_binary' => $this->option('gateway') ?: null,
                'signing_key' => $key,
                'vendor_junction' => $this->option('vendor-junction') ?: null,
            ]);
        } catch (\Throwable $e) {
            $this->error('Package build FAILED: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->info(($release ? 'RELEASE' : 'DEV') . ' Edge appliance package built at: ' . $dest);
        $this->table(['field', 'value'], collect($summary)->map(fn ($v, $k) => [$k, is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)])->values()->all());

        return self::SUCCESS;
    }

    private function recursiveDelete(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
