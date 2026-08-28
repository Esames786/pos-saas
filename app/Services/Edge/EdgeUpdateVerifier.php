<?php

namespace App\Services\Edge;

use RuntimeException;

/**
 * OFFLINE EDGE PRODUCTIZATION (O) — verify a signed update package BEFORE any runtime/DB mutation.
 *
 * Fail-closed order (all before the installer touches anything): supported format; targets a Branch Server;
 * valid Ed25519 signature against the appliance's PUBLIC key; the artifact on disk matches the signed
 * manifest hash (tamper); the version transition is permitted (no downgrade unless explicitly allowed; the
 * current version is new enough); the schema generation is compatible (equal or forward, never older/unknown).
 */
class EdgeUpdateVerifier
{
    public function __construct(private readonly EdgeUpdatePackageService $packages)
    {
    }

    /**
     * @throws RuntimeException on any verification failure (with a stable UPDATE_* code).
     */
    public function verify(array $package, string $artifactDir, string $currentVersion, string $currentSchema): void
    {
        $payload = $package['payload'] ?? null;
        if (! is_array($payload) || ($payload['package_format_version'] ?? null) !== EdgeUpdatePackageService::FORMAT) {
            throw new RuntimeException('UPDATE_FORMAT_UNSUPPORTED: unrecognised update package format.');
        }
        if (($payload['target_runtime'] ?? null) !== 'branch_server') {
            throw new RuntimeException('UPDATE_WRONG_PRODUCT: the package does not target a Branch Server.');
        }

        $publicKey = (string) config('edge.update.public_key');
        if ($publicKey === '') {
            throw new RuntimeException('UPDATE_NO_PUBLIC_KEY: the appliance has no update verification key.');
        }
        if (! EdgeEnrollmentCrypto::verifySignature($package, $publicKey)) {
            throw new RuntimeException('UPDATE_SIGNATURE_INVALID: the package signature is not valid.');
        }

        // The bytes on disk must match the SIGNED manifest hash — any tampering fails here.
        $recomputed = $this->packages->recomputeManifestHash($artifactDir);
        if (! hash_equals((string) ($payload['artifact_manifest_hash'] ?? ''), $recomputed)) {
            throw new RuntimeException('UPDATE_ARTIFACT_TAMPERED: the staged artifact does not match the signed manifest.');
        }

        // Version transition.
        $to = (string) ($payload['edge_app_version'] ?? '');
        if ($to === '') {
            throw new RuntimeException('UPDATE_VERSION_INVALID: the package has no Edge version.');
        }
        $allowDowngrade = (bool) config('edge.update.allow_downgrade', false);
        if (! $allowDowngrade && version_compare($to, $currentVersion, '<')) {
            throw new RuntimeException("UPDATE_DOWNGRADE_REFUSED: [{$to}] is older than the installed [{$currentVersion}].");
        }
        $minPrev = (string) ($payload['min_previous_edge_version'] ?? '0.0.0');
        if (version_compare($currentVersion, $minPrev, '<')) {
            throw new RuntimeException("UPDATE_PREVIOUS_TOO_OLD: installed [{$currentVersion}] is below the required minimum [{$minPrev}].");
        }

        // Schema compatibility — equal or a forward generation only (forward-only migration contract).
        $pkgSchema = (string) ($payload['schema_generation'] ?? '');
        if ($pkgSchema !== '' && $currentSchema !== '' && ! $this->schemaCompatible($currentSchema, $pkgSchema)) {
            throw new RuntimeException("UPDATE_SCHEMA_INCOMPATIBLE: package schema [{$pkgSchema}] is not a forward transition from [{$currentSchema}].");
        }
    }

    /** Equal, or a strictly-forward generation (edge-config-vN, N_pkg >= N_current). Never older/unknown. */
    private function schemaCompatible(string $current, string $package): bool
    {
        if ($current === $package) {
            return true;
        }
        if (preg_match('/-v(\d+)$/', $current, $c) && preg_match('/-v(\d+)$/', $package, $p)
            && preg_replace('/-v\d+$/', '', $current) === preg_replace('/-v\d+$/', '', $package)) {
            return (int) $p[1] > (int) $c[1];
        }

        return false;
    }
}
