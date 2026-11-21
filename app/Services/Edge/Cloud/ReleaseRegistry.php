<?php

namespace App\Services\Edge\Cloud;

use App\Models\Master\EdgeDevice;
use App\Models\Master\EdgeRelease;
use App\Services\Edge\EdgeArtifactBuilder;
use App\Services\Edge\EdgePackageBuilder;
use App\Services\Edge\EdgeSigningKeyStore;
use App\Services\Edge\EdgeUpdatePackageService;
use App\Services\Edge\EdgeUpdateVerifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * EDGE 0.9 (K6) — the Cloud registry of SIGNED Branch Server packages.
 *
 * publish(): a package directory (EdgePackageBuilder layout, edge-package-v1) is accepted ONLY when
 *   1. EdgePackageBuilder::verify passes — every listed file present and unchanged, nothing unlisted, package_hash intact,
 *      boundary audit clean (no secrets / keys / tests / Cloud-only modules; branch_server marker present);
 *   2. it carries ONE signed update package (update/edge-update-<v>.json) that EdgeUpdateVerifier accepts against the
 *      configured update PUBLIC key (Ed25519 signature, format, target runtime, artifact bytes == signed manifest hash);
 *   3. the signed payload names the same version and artifact manifest hash as the package manifest, and is NOT pinned to
 *      a tenant/branch/device (a channel release is generic).
 * The Cloud then zips the verified directory (package-manifest.json at the zip root) into storage/app/edge-releases/ and
 * records version, hashes, size and path. Downloads re-check size + SHA-256 of the zip before streaming a byte.
 */
class ReleaseRegistry
{
    public const STORAGE_DIR = 'edge-releases';
    public const DOWNLOAD_TTL_MINUTES = 15;

    public function __construct(
        private readonly EdgeUpdatePackageService $updates,
        private readonly EdgeUpdateVerifier $verifier,
    ) {}

    /* ── publish ────────────────────────────────────────────────────────── */

    /**
     * @return array{status:string, release:?EdgeRelease, version:string, manifest_sha256:string, package_hash:string, signing_key_id:?string, zip_sha256?:string, size_bytes?:int}
     * @throws RuntimeException with a stable RELEASE_* code
     */
    public function publish(string $dir, string $channel = EdgeRelease::CHANNEL_STABLE, ?string $publishedBy = null, bool $dryRun = false): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (! preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $channel)) {
            throw new RuntimeException('RELEASE_CHANNEL_INVALID: ' . $channel);
        }
        if (! is_dir($dir)) {
            throw new RuntimeException('RELEASE_PACKAGE_MISSING: ' . $dir);
        }

        $publicKey = (string) config('edge.update.public_key');
        if ($publicKey === '') {
            throw new RuntimeException('RELEASE_NO_PUBLIC_KEY: EDGE_UPDATE_PUBLIC_KEY is not configured on this Cloud.');
        }

        // 1. Integrity + boundary (the existing package audit). Stream E contract (edge09/e-packaging): verify() also checks the
        //    package + checksums signatures against the update PUBLIC key when asked to — every flag must then be true. A builder
        //    without that support (pre-E) reports neither flag; the signed update package is verified below in every case.
        $packages = new EdgePackageBuilder(EdgeArtifactBuilder::fromConfig(), $this->updates);
        $check = $packages->verify($dir, ['public_key' => $publicKey, 'require_signature' => true]);
        if (! ($check['ok'] ?? false)) {
            throw new RuntimeException('RELEASE_PACKAGE_INVALID: ' . json_encode(array_intersect_key($check, array_flip(['error', 'missing', 'unlisted', 'tampered', 'package_hash_ok', 'boundary_audit', 'signature_ok', 'checksums_signature_ok']))));
        }
        if ((array_key_exists('signature_ok', $check) || array_key_exists('checksums_signature_ok', $check))
            && (($check['signature_ok'] ?? false) !== true || ($check['checksums_signature_ok'] ?? false) !== true)) {
            throw new RuntimeException('RELEASE_SIGNATURE_INVALID: ' . json_encode(['signature_ok' => $check['signature_ok'] ?? null, 'checksums_signature_ok' => $check['checksums_signature_ok'] ?? null]));
        }
        $manifest = json_decode((string) file_get_contents($dir . '/package-manifest.json'), true);
        if (is_dir($dir . '/app/vendor') && self::isLinkedDir($dir . '/app/vendor')) {
            // A dev/test package junctions app/vendor to a shared closure that the package hash does not cover.
            throw new RuntimeException('RELEASE_DEV_PACKAGE: app/vendor is a junction/symlink — only release builds (real vendor) can be published.');
        }
        $version = (string) ($manifest['edge_app_version'] ?? '');
        if ($version === '' || ! preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/', $version)) {
            throw new RuntimeException('RELEASE_VERSION_INVALID: the package has no usable edge_app_version.');
        }

        // 2. The signed update package.
        $updateRel = (string) ($manifest['components']['update']['file'] ?? '');
        if ($updateRel === '' || ! ($manifest['components']['update']['signed'] ?? false) || ! isset($manifest['files'][$updateRel])) {
            throw new RuntimeException('RELEASE_UNSIGNED: the package carries no signed update package (release builds must be signed).');
        }
        $pkg = json_decode((string) file_get_contents($dir . '/' . $updateRel), true);
        if (! is_array($pkg) || ! is_array($pkg['payload'] ?? null)) {
            throw new RuntimeException('RELEASE_UPDATE_PACKAGE_INVALID: ' . $updateRel);
        }
        $payload = $pkg['payload'];
        foreach (['target_tenant_code', 'target_branch_id', 'target_device_uuid'] as $pin) {
            if (isset($payload[$pin]) && $payload[$pin] !== null && $payload[$pin] !== '') {
                throw new RuntimeException('RELEASE_PINNED_PACKAGE: a package pinned to a tenant/branch/device cannot be published to a channel.');
            }
        }
        $minFrom = (string) ($payload['min_previous_edge_version'] ?? '0.0.0');
        try {
            // currentVersion = the package's own floor (a Cloud has no installed version); schema '' = no transition check.
            $this->verifier->verify($pkg, $dir . '/app', $minFrom !== '' ? $minFrom : '0.0.0', '');
        } catch (RuntimeException $e) {
            throw new RuntimeException('RELEASE_VERIFY_FAILED: ' . $e->getMessage(), 0, $e);
        }
        if ((string) ($payload['edge_app_version'] ?? '') !== $version
            || ! hash_equals((string) ($manifest['artifact_manifest_hash'] ?? ''), (string) ($payload['artifact_manifest_hash'] ?? ''))) {
            throw new RuntimeException('RELEASE_MANIFEST_MISMATCH: the signed update package does not describe this package.');
        }

        $keyId = EdgeSigningKeyStore::keyId((string) config('edge.update.public_key'));
        $packageHash = (string) ($manifest['package_hash'] ?? '');
        $manifestSha = (string) $payload['artifact_manifest_hash'];

        $existing = EdgeRelease::where('channel', $channel)->where('version', $version)->first();
        if ($existing) {
            if (hash_equals((string) $existing->package_hash, $packageHash) && $this->isServable($existing)) {
                return ['status' => 'already_published', 'release' => $existing, 'version' => $version, 'manifest_sha256' => $manifestSha,
                    'package_hash' => $packageHash, 'signing_key_id' => $keyId, 'zip_sha256' => $existing->zip_sha256, 'size_bytes' => $existing->size_bytes];
            }
            throw new RuntimeException("RELEASE_VERSION_EXISTS: {$channel} {$version} is already registered with different contents.");
        }

        $summary = ['version' => $version, 'manifest_sha256' => $manifestSha, 'package_hash' => $packageHash, 'signing_key_id' => $keyId];
        if ($dryRun) {
            return ['status' => 'verified', 'release' => null] + $summary;
        }

        // 3. Zip the VERIFIED directory, then register.
        $rel = self::STORAGE_DIR . '/' . $channel . '/BingooEdge-' . preg_replace('/[^0-9A-Za-z._+-]/', '_', $version) . '.zip';
        $abs = storage_path('app/' . $rel);
        @mkdir(dirname($abs), 0755, true);
        $tmp = $abs . '.part-' . Str::random(8);
        // Exactly the VERIFIED set: every file the package manifest lists (hash-checked above) + the manifest itself.
        $count = $this->zipFiles($dir, array_merge(array_keys($manifest['files']), ['package-manifest.json']), $tmp);
        if (! @rename($tmp, $abs)) {
            @unlink($tmp);
            throw new RuntimeException('RELEASE_STORAGE: cannot move the zip into ' . $abs);
        }
        $zipSha = hash_file('sha256', $abs);
        $size = (int) filesize($abs);

        $release = EdgeRelease::create([
            'public_uuid'         => (string) Str::uuid(),
            'channel'             => $channel,
            'version'             => $version,
            'status'              => EdgeRelease::STATUS_PUBLISHED,
            'manifest_sha256'     => $manifestSha,
            'package_hash'        => $packageHash,
            'zip_sha256'          => $zipSha,
            'size_bytes'          => $size,
            'signing_key_id'      => $keyId,
            'min_from_version'     => $minFrom !== '' && $minFrom !== '0.0.0' ? $minFrom : null,
            'schema_generation'   => ($payload['schema_generation'] ?? '') !== '' ? (string) $payload['schema_generation'] : null,
            'source_revision'     => isset($payload['source_revision']) ? Str::limit((string) $payload['source_revision'], 64, '') : null,
            'update_package_file' => $updateRel,
            'storage_path'        => $rel,
            'file_count'          => $count,
            'published_by'        => $publishedBy ? Str::limit($publishedBy, 190, '') : null,
            'published_at'        => now(),
        ]);
        Log::info('[edge-release-audit] edge.release.published', [
            'release' => $release->public_uuid, 'channel' => $channel, 'version' => $version, 'zip_sha256' => $zipSha,
            'signing_key_id' => $keyId, 'size_bytes' => $size,
        ]);

        return ['status' => 'published', 'release' => $release, 'zip_sha256' => $zipSha, 'size_bytes' => $size] + $summary;
    }

    /** @param list<string> $rels */
    private function zipFiles(string $dir, array $rels, string $zipPath): int
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('RELEASE_STORAGE: cannot create ' . $zipPath);
        }
        $count = 0;
        sort($rels);
        foreach ($rels as $rel) {
            $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
            if ($rel === '' || str_contains($rel, '..') || ! is_file($dir . '/' . $rel)) {
                $zip->close();
                @unlink($zipPath);
                throw new RuntimeException('RELEASE_PACKAGE_INVALID: listed file missing or unsafe: ' . $rel);
            }
            $zip->addFile($dir . '/' . $rel, $rel);
            $count++;
        }
        if (! $zip->close()) {
            @unlink($zipPath);
            throw new RuntimeException('RELEASE_STORAGE: writing the zip failed.');
        }

        return $count;
    }

    private static function isLinkedDir(string $path): bool
    {
        if (is_link($path)) {
            return true;
        }
        $real = realpath($path);
        $parent = realpath(dirname($path));
        $norm = fn (string $p) => strtolower(rtrim(str_replace('\\', '/', $p), '/'));

        return $real === false || $parent === false || $norm($real) !== $norm($parent . '/' . basename($path));
    }

    /* ── queries ────────────────────────────────────────────────────────── */

    /** The highest published version of a channel (version_compare order), or null. */
    public function latestPublished(string $channel = EdgeRelease::CHANNEL_STABLE): ?EdgeRelease
    {
        $rows = EdgeRelease::published()->channel($channel)->get();

        return $rows->sort(fn (EdgeRelease $a, EdgeRelease $b) => version_compare($b->version, $a->version))->first();
    }

    /** Gate 3 — a published stable release whose zip is on disk with the registered size. */
    public function latestServable(string $channel = EdgeRelease::CHANNEL_STABLE): ?EdgeRelease
    {
        $r = $this->latestPublished($channel);

        return $r && $this->isServable($r) ? $r : null;
    }

    public function absolutePath(EdgeRelease $release): ?string
    {
        $rel = str_replace('\\', '/', (string) $release->storage_path);
        if ($rel === '' || str_contains($rel, '..') || ! str_starts_with($rel, self::STORAGE_DIR . '/')) {
            return null;
        }

        return storage_path('app/' . $rel);
    }

    public function isServable(EdgeRelease $release): bool
    {
        $abs = $this->absolutePath($release);

        return $release->status === EdgeRelease::STATUS_PUBLISHED && $abs !== null && is_file($abs) && is_readable($abs)
            && (int) @filesize($abs) === (int) $release->size_bytes;
    }

    /** Size + SHA-256 of the zip on disk equal the registered values (checked before every download). */
    public function integrityOk(EdgeRelease $release): bool
    {
        if (! $this->isServable($release)) {
            return false;
        }

        return hash_equals((string) $release->zip_sha256, (string) hash_file('sha256', (string) $this->absolutePath($release)));
    }

    /* ── K6 update offer ────────────────────────────────────────────────── */

    /** The device's current version: what it last reported (K4), else what it reported at pairing / on the heartbeat. */
    public function deviceVersion(EdgeDevice $device): ?string
    {
        foreach ([$device->reported_version, $device->app_version] as $v) {
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    /**
     * @return array{available:bool, reason?:string, release?:EdgeRelease, current_version:?string}
     */
    public function offerFor(EdgeDevice $device, string $channel = EdgeRelease::CHANNEL_STABLE): array
    {
        $current = $this->deviceVersion($device);
        $latest = $this->latestServable($channel);
        if (! $latest) {
            return ['available' => false, 'reason' => 'no_release', 'current_version' => $current];
        }
        if ($current !== null && version_compare($latest->version, $current, '<=')) {
            return ['available' => false, 'reason' => 'up_to_date', 'current_version' => $current, 'latest_version' => $latest->version];
        }
        if ($current !== null && $latest->min_from_version && version_compare($current, $latest->min_from_version, '<')) {
            return ['available' => false, 'reason' => 'requires_min_version', 'current_version' => $current, 'latest_version' => $latest->version,
                'min_from_version' => $latest->min_from_version];
        }

        return ['available' => true, 'release' => $latest, 'current_version' => $current];
    }

    public function signedDownloadUrl(EdgeRelease $release, EdgeDevice $device): string
    {
        return URL::temporarySignedRoute('edge.api.updates.download', now()->addMinutes(self::DOWNLOAD_TTL_MINUTES), [
            'release' => $release->public_uuid,
            'd'       => $device->public_uuid,
        ]);
    }

    public static function downloadName(EdgeRelease $release): string
    {
        return 'BingooEdgeSetup-' . preg_replace('/[^0-9A-Za-z._+-]/', '_', (string) $release->version) . '.zip';
    }
}
