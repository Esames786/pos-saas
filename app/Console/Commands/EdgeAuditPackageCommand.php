<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgePackageBuilder;
use Illuminate\Console\Command;

/**
 * P4 §5 — verify an appliance package on disk: every listed file present and unchanged, no unlisted file, the
 * boundary audit still clean (no secrets/keys/tests/Cloud-only modules; the branch_server artifact marker present).
 *
 *   php artisan edge:audit-package D:\out\BingooEdge-0.1.0 [--json]
 */
class EdgeAuditPackageCommand extends Command
{
    protected $signature = 'edge:audit-package {dir : Package directory} {--json : Emit JSON}';

    protected $description = 'Verify a built Bingoo Edge appliance package (integrity + boundary).';

    public function handle(): int
    {
        $packages = new EdgePackageBuilder(\App\Services\Edge\EdgeArtifactBuilder::fromConfig(), app(\App\Services\Edge\EdgeUpdatePackageService::class));
        $result = $packages->verify((string) $this->argument('dir'));
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(($result['ok'] ? '<info>PACKAGE OK</info>' : '<error>PACKAGE FAILED</error>') . ' — ' . ($result['edge_app_version'] ?? '?') . ' · files ' . ($result['file_count'] ?? 0));
            foreach (['missing', 'unlisted', 'tampered'] as $k) {
                if (! empty($result[$k])) {
                    $this->warn(strtoupper($k) . ': ' . implode(', ', array_slice($result[$k], 0, 20)));
                }
            }
            if (isset($result['boundary_audit']) && ! $result['boundary_audit']['ok']) {
                $this->warn('BOUNDARY: ' . json_encode($result['boundary_audit']));
            }
            if (isset($result['error'])) {
                $this->error($result['error']);
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
