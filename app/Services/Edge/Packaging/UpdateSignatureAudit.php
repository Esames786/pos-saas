<?php

namespace App\Services\Edge\Packaging;

use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeSigningKeyStore;
use App\Services\Edge\EdgeUpdatePackageService;

/**
 * Edge 0.9 (stream E) — verify a package's SIGNED update manifest (update/edge-update-<v>.json) against a given Ed25519
 * PUBLIC key, with the product's own crypto (EdgeEnrollmentCrypto::verifySignature — the same check EdgeUpdateVerifier
 * runs on the appliance). Before 0.9 edge:audit-package never looked at the signature: the 0.8.4 verification needed a
 * manual sodium replay.
 *
 * Checks: the update file named by the package manifest exists and is {payload, signature}; the signature is valid for
 * the key; the key id (fingerprint) equals the manifest's signing_key_id; the signed payload names THIS package (version,
 * artifact manifest hash = the package manifest's AND the hash recomputed from the app/ bytes on disk, branch_server).
 */
final class UpdateSignatureAudit
{
    public function __construct(private readonly EdgeUpdatePackageService $updates)
    {
    }

    /**
     * @param  array  $manifest  the decoded package-manifest.json
     * @return array{checked:bool, signature_ok:?bool, ok:bool, key_id:?string, manifest_signing_key_id:?string,
     *               key_id_matches:?bool, payload_version:?string, version_matches:?bool, artifact_hash_matches:?bool,
     *               artifact_bytes_match:?bool, target_branch_server:?bool, problems:list<string>, note?:string}
     */
    public function audit(string $pkgDir, array $manifest, ?string $publicKeyBase64): array
    {
        $dir = rtrim(str_replace('\\', '/', $pkgDir), '/');
        $update = (array) ($manifest['components']['update'] ?? []);
        $base = [
            'checked' => false, 'signature_ok' => null, 'ok' => true, 'key_id' => null,
            'manifest_signing_key_id' => $update['signing_key_id'] ?? null, 'key_id_matches' => null, 'payload_version' => null,
            'version_matches' => null, 'artifact_hash_matches' => null, 'artifact_bytes_match' => null, 'target_branch_server' => null,
            'problems' => [],
        ];
        $pk = trim((string) $publicKeyBase64);
        if ($pk === '') {
            return $base + ['note' => 'no public key given (--public-key / edge.update.public_key) — signature not checked'];
        }
        $raw = base64_decode($pk, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return array_merge($base, ['checked' => true, 'signature_ok' => false, 'ok' => false, 'problems' => ['PUBLIC_KEY_INVALID: not a base64 Ed25519 public key']]);
        }
        $keyId = EdgeSigningKeyStore::keyId($pk);
        $base['key_id'] = $keyId;
        $base['checked'] = true;
        if (($update['signed'] ?? false) !== true || ! is_string($update['file'] ?? null)) {
            return array_merge($base, ['signature_ok' => false, 'ok' => false, 'problems' => ['UPDATE_UNSIGNED: the package carries no signed update manifest']]);
        }
        $file = $dir . '/' . ltrim((string) $update['file'], '/');
        $pkg = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($pkg) || ! is_array($pkg['payload'] ?? null) || ! is_string($pkg['signature'] ?? null)) {
            return array_merge($base, ['signature_ok' => false, 'ok' => false, 'problems' => ['UPDATE_MANIFEST_UNREADABLE: ' . $update['file']]]);
        }
        $problems = [];
        $sigOk = EdgeEnrollmentCrypto::verifySignature($pkg, $pk);
        if (! $sigOk) {
            $problems[] = 'UPDATE_SIGNATURE_INVALID: the update manifest is not signed by key ' . $keyId;
        }
        $payload = $pkg['payload'];
        $keyMatches = isset($update['signing_key_id']) ? hash_equals((string) $update['signing_key_id'], $keyId) : null;
        if ($keyMatches === false) {
            $problems[] = 'SIGNING_KEY_ID_MISMATCH: manifest records ' . $update['signing_key_id'] . ', the given key is ' . $keyId;
        }
        $version = (string) ($payload['edge_app_version'] ?? '');
        $versionMatches = $version !== '' && $version === (string) ($manifest['edge_app_version'] ?? '');
        if (! $versionMatches) {
            $problems[] = 'UPDATE_VERSION_MISMATCH: signed ' . $version . ' vs package ' . ($manifest['edge_app_version'] ?? '?');
        }
        $signedHash = (string) ($payload['artifact_manifest_hash'] ?? '');
        $hashMatches = $signedHash !== '' && hash_equals((string) ($manifest['artifact_manifest_hash'] ?? ''), $signedHash);
        if (! $hashMatches) {
            $problems[] = 'UPDATE_ARTIFACT_HASH_MISMATCH: the signed artifact hash is not the package artifact hash';
        }
        $bytesMatch = null;
        try {
            $bytesMatch = hash_equals($signedHash, $this->updates->recomputeManifestHash($dir . '/app'));
        } catch (\Throwable $e) {
            $bytesMatch = false;
        }
        if (! $bytesMatch) {
            $problems[] = 'UPDATE_ARTIFACT_TAMPERED: the app/ bytes on disk do not match the signed artifact hash';
        }
        $target = ($payload['target_runtime'] ?? null) === 'branch_server';
        if (! $target) {
            $problems[] = 'UPDATE_WRONG_PRODUCT: the signed payload does not target a Branch Server';
        }

        return array_merge($base, [
            'signature_ok' => $sigOk,
            'ok' => $problems === [],
            'key_id_matches' => $keyMatches,
            'payload_version' => $version,
            'version_matches' => $versionMatches,
            'artifact_hash_matches' => $hashMatches,
            'artifact_bytes_match' => $bytesMatch,
            'target_branch_server' => $target,
            'problems' => $problems,
        ]);
    }
}
