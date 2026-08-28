<?php

namespace App\Services\Edge;

use RuntimeException;

/**
 * OFFLINE EDGE PRODUCTIZATION (O) — build a signed appliance UPDATE package from a built artifact.
 *
 * The package payload identifies the update (format, target runtime, Edge version, source revision, schema
 * generation, min-previous version, PHP/DB floors) and cryptographically binds the installed BYTES via the
 * artifact's manifest hash (a SHA-256 over every shipped file's SHA-256). It is signed with the Cloud/build
 * private Ed25519 key — reusing the enrollment crypto primitive; the appliance holds only the public key.
 * A tampered artifact changes the manifest hash and breaks the signature.
 */
class EdgeUpdatePackageService
{
    public const FORMAT = 'edge-update-v1';

    /**
     * Build a signed package from a built artifact directory (which carries edge-build-manifest.json).
     * $signingKeyBase64 is the Cloud/build private key — never present on an appliance.
     */
    public function build(string $artifactDir, string $signingKeyBase64, array $overrides = []): array
    {
        $manifest = $this->readArtifactManifest($artifactDir);

        $payload = array_merge([
            'package_format_version' => self::FORMAT,
            'target_runtime' => 'branch_server',
            'edge_app_version' => (string) ($manifest['edge_app_version'] ?? ''),
            'source_revision' => $manifest['git_commit'] ?? ($manifest['source_revision'] ?? null),
            'artifact_manifest_hash' => (string) ($manifest['manifest_hash'] ?? ''),
            'schema_generation' => (string) ($manifest['config_schema'] ?? $manifest['schema_generation'] ?? ''),
            'min_previous_edge_version' => '0.0.0',
            'min_php' => (string) ($manifest['min_php'] ?? ''),
            'min_db' => (string) ($manifest['min_db'] ?? ''),
        ], $overrides);

        return EdgeEnrollmentCrypto::sign($payload, $signingKeyBase64); // {payload, signature}
    }

    /** Read + validate the artifact's build manifest. */
    public function readArtifactManifest(string $artifactDir): array
    {
        $path = rtrim($artifactDir, "/\\") . DIRECTORY_SEPARATOR . 'edge-build-manifest.json';
        if (! is_file($path)) {
            throw new RuntimeException('UPDATE_ARTIFACT_NO_MANIFEST: ' . $path);
        }
        $manifest = json_decode((string) file_get_contents($path), true);
        if (! is_array($manifest) || ! isset($manifest['files']) || ! is_array($manifest['files'])) {
            throw new RuntimeException('UPDATE_ARTIFACT_MANIFEST_INVALID');
        }

        return $manifest;
    }

    /**
     * Recompute the artifact manifest hash from the files ACTUALLY on disk (the same construction the builder
     * uses: per-file SHA-256, ksorted, hashed). Any tampered/missing listed file changes the result.
     */
    public function recomputeManifestHash(string $artifactDir): string
    {
        $manifest = $this->readArtifactManifest($artifactDir);
        $root = rtrim(str_replace('\\', '/', $artifactDir), '/');
        $fileHashes = [];
        foreach (array_keys($manifest['files']) as $rel) {
            $abs = $root . '/' . $rel;
            if (is_file($abs)) {
                $fileHashes[$rel] = hash_file('sha256', $abs);
            }
        }
        ksort($fileHashes);

        return hash('sha256', json_encode($fileHashes, JSON_UNESCAPED_SLASHES));
    }
}
