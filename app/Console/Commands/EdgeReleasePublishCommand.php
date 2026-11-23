<?php

namespace App\Console\Commands;

use App\Models\Master\EdgeRelease;
use App\Services\Edge\Cloud\ReleaseRegistry;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * EDGE 0.9 (K6) — register a SIGNED Branch Server package on the Cloud (setup download + auto-update channel).
 *
 *   php artisan edge:release:publish D:\out\BingooEdge-0.9.1-edge [--channel=stable] [--dry-run] [--json]
 *       [--approve-plan=<from_version>=sha256:<hex> …]   (repeatable: the OWNER-APPROVED migration plan per upgrade path, K6)
 *
 * Refuses (non-zero exit, nothing registered) unless the package directory passes the existing integrity + boundary audit
 * (EdgePackageBuilder::verify) AND its signed update package verifies against the configured EDGE_UPDATE_PUBLIC_KEY
 * (EdgeUpdateVerifier: Ed25519 signature + artifact manifest hash), is unpinned and describes this very package. Only then
 * is the directory zipped into storage/app/edge-releases/<channel>/ and recorded in edge_releases. Never reads a private key.
 */
class EdgeReleasePublishCommand extends Command
{
    protected $signature = 'edge:release:publish
        {dir : the built package directory (edge-package-v1, with update/edge-update-<version>.json)}
        {--channel=stable : release channel}
        {--dry-run : verify only — register nothing}
        {--approve-plan=* : <from_version>=<plan_hash> — the owner-approved migration plan hash for that upgrade path (repeatable)}
        {--json : emit JSON}';

    protected $description = 'Verify a signed Branch Server package and register it as a Cloud release (setup download + auto-update).';

    public function handle(ReleaseRegistry $registry): int
    {
        if (EdgeRuntime::isBranchServer()) {
            $this->error('edge:release:publish is a Cloud command — it never runs on a Branch Server.');

            return self::FAILURE;
        }

        try {
            $result = $registry->publish(
                (string) $this->argument('dir'),
                (string) ($this->option('channel') ?: EdgeRelease::CHANNEL_STABLE),
                'cli:' . (get_current_user() ?: 'unknown'),
                (bool) $this->option('dry-run'),
                (array) $this->option('approve-plan'),
            );
        } catch (RuntimeException $e) {
            if ($this->option('json')) {
                $this->line(json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('RELEASE REFUSED — ' . $e->getMessage());
            }

            return self::FAILURE;
        }

        /** @var EdgeRelease|null $release */
        $release = $result['release'];
        $out = [
            'ok'              => true,
            'status'          => $result['status'],
            'channel'         => (string) ($this->option('channel') ?: EdgeRelease::CHANNEL_STABLE),
            'version'         => $result['version'],
            'manifest_sha256' => $result['manifest_sha256'],
            'package_hash'    => $result['package_hash'],
            'signing_key_id'  => $result['signing_key_id'],
            'zip_sha256'      => $result['zip_sha256'] ?? null,
            'size_bytes'      => $result['size_bytes'] ?? null,
            'release_id'      => $release?->public_uuid,
            'storage_path'    => $release?->storage_path,
            'approved_plan_hashes' => $release?->approved_plan_hashes ?: (object) [],
        ];
        if ($this->option('json')) {
            $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(strtoupper($result['status']) . " — {$out['channel']} {$out['version']} (key {$out['signing_key_id']})");
            foreach (['manifest_sha256', 'package_hash', 'zip_sha256', 'size_bytes', 'release_id', 'storage_path'] as $k) {
                if ($out[$k] !== null) {
                    $this->line(str_pad($k, 16) . ' ' . $out[$k]);
                }
            }
        }

        return self::SUCCESS;
    }
}
