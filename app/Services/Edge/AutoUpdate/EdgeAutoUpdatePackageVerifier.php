<?php

namespace App\Services\Edge\AutoUpdate;

use App\Services\Edge\EdgePackageBuilder;
use App\Services\Edge\EdgeSigningKeyStore;
use App\Services\Edge\EdgeUpdateVerifier;
use RuntimeException;

/**
 * EDGE 0.9 (stream B) — verify a downloaded + extracted release package against what the Cloud ADVERTISED (K6) and against
 * the appliance's own trust root, BEFORE anything is staged into the runtime. Fail closed, stable AUTO_UPDATE_* / UPDATE_* codes:
 *
 *   1. package-manifest.json bytes hash to the advertised manifest_sha256 (binds the advertisement to these bytes);
 *   2. the manifest names the advertised version and package_hash;
 *   3. every listed file is present and unchanged, nothing unlisted, the package hash recomputes, the boundary audit is clean
 *      (EdgePackageBuilder::verify — the same check the build and the operator update use);
 *   4. the signed update file for that version exists; the appliance's update public key has the advertised signing_key_id;
 *   5. EdgeUpdateVerifier: Ed25519 signature with edge.update.public_key, artifact manifest hash recomputed from the bytes,
 *      version transition (no downgrade), schema generation, tenant/branch/device pin;
 *   6. the release's min_from_version admits the installed version.
 */
class EdgeAutoUpdatePackageVerifier
{
    public function __construct(private readonly EdgeUpdateVerifier $updateVerifier)
    {
    }

    /**
     * @return array{package:array,manifest:array,update_file:string,version:string,signing_key_id:string}
     */
    public function verify(string $packageRoot, array $release, string $currentVersion, string $currentSchema): array
    {
        $root = rtrim($packageRoot, "/\\");
        $manifestPath = $root . DIRECTORY_SEPARATOR . 'package-manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException('AUTO_UPDATE_NOT_A_PACKAGE: package-manifest.json is missing.');
        }
        $version = trim((string) ($release['version'] ?? ''));
        $advertisedManifestSha = strtolower(trim((string) ($release['manifest_sha256'] ?? '')));
        $advertisedPackageHash = strtolower(trim((string) ($release['package_hash'] ?? '')));
        if ($version === '' || $advertisedManifestSha === '' || $advertisedPackageHash === '') {
            throw new RuntimeException('AUTO_UPDATE_RELEASE_INCOMPLETE: the release advertisement lacks version / manifest_sha256 / package_hash.');
        }
        // 1.
        if (! hash_equals($advertisedManifestSha, hash_file('sha256', $manifestPath))) {
            throw new RuntimeException('AUTO_UPDATE_MANIFEST_MISMATCH: package-manifest.json does not hash to the advertised manifest_sha256.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            throw new RuntimeException('AUTO_UPDATE_NOT_A_PACKAGE: package-manifest.json is not valid JSON.');
        }
        // 2.
        if ((string) ($manifest['edge_app_version'] ?? '') !== $version) {
            throw new RuntimeException('AUTO_UPDATE_VERSION_MISMATCH: the package is ' . (string) ($manifest['edge_app_version'] ?? '?') . ', the Cloud advertised ' . $version . '.');
        }
        if (! hash_equals($advertisedPackageHash, strtolower((string) ($manifest['package_hash'] ?? '')))) {
            throw new RuntimeException('AUTO_UPDATE_PACKAGE_HASH_MISMATCH: the manifest package_hash differs from the advertised one.');
        }
        if (($manifest['runtime_mode_supported'] ?? null) !== 'branch_server') {
            throw new RuntimeException('UPDATE_WRONG_PRODUCT: the package is not a Branch Server package.');
        }
        // 3.
        $tree = $this->verifyTree($root);
        if (! ($tree['ok'] ?? false)) {
            $detail = array_filter([
                ! empty($tree['missing']) ? 'missing ' . count($tree['missing']) : null,
                ! empty($tree['tampered']) ? 'tampered ' . count($tree['tampered']) . ' (' . implode(', ', array_slice((array) $tree['tampered'], 0, 3)) . ')' : null,
                ! empty($tree['unlisted']) ? 'unlisted ' . count($tree['unlisted']) : null,
                array_key_exists('package_hash_ok', $tree) && ! $tree['package_hash_ok'] ? 'package hash' : null,
                isset($tree['boundary_audit']['ok']) && ! $tree['boundary_audit']['ok'] ? 'boundary audit' : null,
                isset($tree['error']) ? (string) $tree['error'] : null,
            ]);
            throw new RuntimeException('AUTO_UPDATE_PACKAGE_TAMPERED: ' . (implode('; ', $detail) ?: 'package verification failed') . '.');
        }
        // 3b. stream E contract: SHA256SUMS + its Ed25519 signature (SHA256SUMS.sig) over the whole package with the SAME update key —
        //     covers the files outside app/ (scripts\, php\, gateway\) BEFORE anything from the package runs.
        $this->verifyChecksums($root);
        // 4.
        $updateFile = $this->updateFileFor($root, $version, isset($release['update_package_file']) ? (string) $release['update_package_file'] : null);
        $package = json_decode((string) file_get_contents($updateFile), true);
        if (! is_array($package) || ! isset($package['payload'], $package['signature'])) {
            throw new RuntimeException('AUTO_UPDATE_NO_SIGNED_UPDATE: the signed update file is not a {payload, signature} document.');
        }
        $publicKey = trim((string) config('edge.update.public_key', ''));
        if ($publicKey === '') {
            throw new RuntimeException('UPDATE_NO_PUBLIC_KEY: the appliance has no update verification key.');
        }
        $keyId = EdgeSigningKeyStore::keyId($publicKey);
        $advertisedKeyId = strtolower(trim((string) ($release['signing_key_id'] ?? '')));
        if ($advertisedKeyId !== '' && ! hash_equals($keyId, $advertisedKeyId)) {
            throw new RuntimeException("AUTO_UPDATE_SIGNING_KEY_MISMATCH: the release is signed with key {$advertisedKeyId}; this appliance trusts {$keyId}.");
        }
        $manifestKeyId = strtolower(trim((string) (($manifest['components']['update'] ?? [])['signing_key_id'] ?? '')));
        if ($manifestKeyId !== '' && ! hash_equals($keyId, $manifestKeyId)) {
            throw new RuntimeException("AUTO_UPDATE_SIGNING_KEY_MISMATCH: the package manifest names signing key {$manifestKeyId}; this appliance trusts {$keyId}.");
        }
        // 5. (signature, artifact bytes, version transition, schema, pin) — throws UPDATE_* codes.
        $this->updateVerifier->verify($package, $root . DIRECTORY_SEPARATOR . 'app', $currentVersion, $currentSchema);
        if ((string) ($package['payload']['edge_app_version'] ?? '') !== $version) {
            throw new RuntimeException('AUTO_UPDATE_VERSION_MISMATCH: the signed update names ' . (string) ($package['payload']['edge_app_version'] ?? '?') . '.');
        }
        // 6.
        $minFrom = trim((string) ($release['min_from_version'] ?? ''));
        if ($minFrom !== '' && version_compare($currentVersion, $minFrom, '<')) {
            throw new RuntimeException("UPDATE_PREVIOUS_TOO_OLD: installed [{$currentVersion}] is below the release minimum [{$minFrom}] — use the setup wizard (update mode).");
        }

        return ['package' => $package, 'manifest' => array_diff_key($manifest, ['files' => true]), 'update_file' => $updateFile, 'version' => $version, 'signing_key_id' => $keyId];
    }

    /**
     * Stream E: App\Services\Edge\Packaging\PackageChecksums::verify($dir, $publicKey) (SHA256SUMS + SHA256SUMS.sig). Resolved at
     * integration when the class is not on this branch yet: absent class = notice only (the manifest + Ed25519 update chain above
     * and below still applies); present = fail closed on a throw, false, or {ok:false}.
     */
    protected function verifyChecksums(string $root): void
    {
        $class = 'App\\Services\\Edge\\Packaging\\PackageChecksums';
        if (! class_exists($class)) {
            \Illuminate\Support\Facades\Log::notice('[edge-auto-update] PackageChecksums not available in this build - SHA256SUMS signature not checked');

            return;
        }
        try {
            $r = $class::verify($root, (string) config('edge.update.public_key'));
        } catch (\Throwable $e) {
            throw new RuntimeException('AUTO_UPDATE_CHECKSUMS_INVALID: ' . mb_substr($e->getMessage(), 0, 300), 0, $e);
        }
        if ($r === false || (is_array($r) && ! ($r['ok'] ?? false))) {
            throw new RuntimeException('AUTO_UPDATE_CHECKSUMS_INVALID: SHA256SUMS / SHA256SUMS.sig do not verify' . (is_array($r) && isset($r['error']) ? ' (' . $r['error'] . ')' : '') . '.');
        }
    }

    /** Every file + package hash + boundary audit. Seam: the gating tests use mini packages without a full Edge runtime. */
    protected function verifyTree(string $root): array
    {
        return app(EdgePackageBuilder::class)->verify($root);
    }

    private function updateFileFor(string $root, string $version, ?string $advertised = null): string
    {
        $dir = $root . DIRECTORY_SEPARATOR . 'update';
        if ($advertised !== null && $advertised !== '') {
            $rel = str_replace('\\', '/', $advertised);
            if (! preg_match('#^update/edge-update-[A-Za-z0-9._+-]+\.json$#', $rel)) {
                throw new RuntimeException('AUTO_UPDATE_NO_SIGNED_UPDATE: the advertised update_package_file [' . mb_substr($advertised, 0, 120) . '] is not an update/edge-update-*.json path.');
            }
            $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (! is_file($full)) {
                throw new RuntimeException('AUTO_UPDATE_NO_SIGNED_UPDATE: the package carries no ' . $rel . '.');
            }

            return $full;
        }
        $safe = preg_replace('/[^A-Za-z0-9._+-]/', '_', $version);
        $exact = $dir . DIRECTORY_SEPARATOR . 'edge-update-' . $safe . '.json';
        if (is_file($exact)) {
            return $exact;
        }
        throw new RuntimeException('AUTO_UPDATE_NO_SIGNED_UPDATE: the package carries no update' . DIRECTORY_SEPARATOR . 'edge-update-' . $safe . '.json.');
    }
}
