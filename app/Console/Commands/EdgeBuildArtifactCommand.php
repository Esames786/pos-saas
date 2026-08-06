<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeArtifactBuilder;
use Illuminate\Console\Command;

/**
 * EDGE-RUNTIME-BOUNDARY-1 — build a restricted Bingoo Edge artifact from the current working tree.
 *
 *   php artisan edge:build-artifact {dest} [--git-commit=abc123]
 *
 * Copies ONLY the allowlisted paths (config/edge.php), refuses if any forbidden file survives, and
 * writes edge-build-manifest.json with per-file SHA-256 hashes. Never deploys anything.
 */
class EdgeBuildArtifactCommand extends Command
{
    protected $signature = 'edge:build-artifact {dest : Destination directory for the artifact}
                            {--git-commit= : Source commit to stamp into the manifest}
                            {--force : Overwrite a non-empty destination}';

    protected $description = 'Build a restricted Bingoo Edge artifact (allowlisted files + integrity manifest).';

    public function handle(): int
    {
        $dest = (string) $this->argument('dest');

        if (is_dir($dest) && (glob($dest . '/*') ?: []) !== [] && ! $this->option('force')) {
            $this->error("Destination [$dest] is not empty. Use --force to overwrite.");

            return self::FAILURE;
        }

        $commit = (string) ($this->option('git-commit') ?: $this->detectCommit());

        $meta = [
            'git_commit'      => $commit ?: 'unknown',
            'build_timestamp' => now()->toIso8601String(),
            'artifact_version' => (string) config('edge.app_version') . '+' . substr($commit ?: 'nocommit', 0, 12),
        ];

        try {
            $summary = EdgeArtifactBuilder::fromConfig()->build(base_path(), $dest, $meta);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Edge artifact built at: ' . $dest);
        $this->table(['field', 'value'], collect($summary)->map(fn ($v, $k) => [$k, is_scalar($v) ? $v : json_encode($v)])->values()->all());

        return self::SUCCESS;
    }

    private function detectCommit(): string
    {
        $head = @exec('git rev-parse HEAD 2>/dev/null');

        return is_string($head) ? trim($head) : '';
    }
}
