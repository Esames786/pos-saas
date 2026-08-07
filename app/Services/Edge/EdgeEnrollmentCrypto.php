<?php

namespace App\Services\Edge;

use RuntimeException;

/**
 * EDGE-LOCAL-AUTH-1 (Sections 4/5) — asymmetric signing of the Cloud-authorised one-time enrollment
 * assertion that breaks the circular first-login problem.
 *
 * Ed25519 via ext-sodium. The Cloud holds the PRIVATE signing key ONLY; the Branch Server holds the
 * PUBLIC verification key ONLY — so a compromised appliance can never forge a Cloud assertion. There
 * is deliberately NO shared HMAC secret (that would let the Edge mint its own assertions), and NO
 * reuse of the Cloud APP_KEY / DB secret / device bearer token as a signing key.
 *
 * Fails closed: if ext-sodium is unavailable the whole path refuses rather than downgrading to an
 * insecure scheme.
 */
class EdgeEnrollmentCrypto
{
    public const PURPOSE = 'edge-user-enrollment';
    public const ASSERTION_VERSION = 'edge-enroll-v1';

    public static function available(): bool
    {
        return extension_loaded('sodium') && defined('SODIUM_CRYPTO_SIGN_BYTES');
    }

    private static function assertAvailable(): void
    {
        if (! self::available()) {
            throw new RuntimeException('Edge enrollment crypto requires ext-sodium (Ed25519); refusing to downgrade to an insecure scheme.');
        }
    }

    /** Generate an Ed25519 keypair (base64 secret + public). Used by tooling/tests — never at runtime. */
    public static function generateKeypair(): array
    {
        self::assertAvailable();
        $kp = sodium_crypto_sign_keypair();

        return [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($kp)),
            'public' => base64_encode(sodium_crypto_sign_publickey($kp)),
        ];
    }

    /**
     * Cloud side: sign a payload, returning the self-contained assertion {payload, signature}.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function sign(array $payload, string $secretKeyBase64): array
    {
        self::assertAvailable();
        $sk = base64_decode($secretKeyBase64, true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Invalid Ed25519 secret key.');
        }
        $sig = sodium_crypto_sign_detached(self::canonicalJson($payload), $sk);

        return ['payload' => $payload, 'signature' => base64_encode($sig)];
    }

    /**
     * Edge side: verify an assertion's signature against the public key. Returns true only if the
     * detached Ed25519 signature is valid for the canonical payload. Does NOT check business claims
     * (tenant/branch/device/epoch/expiry/replay) — that is the consumer's job.
     */
    public static function verifySignature(array $assertion, string $publicKeyBase64): bool
    {
        if (! self::available()) {
            return false; // fail closed
        }
        $payload = $assertion['payload'] ?? null;
        $sigB64 = $assertion['signature'] ?? null;
        if (! is_array($payload) || ! is_string($sigB64)) {
            return false;
        }
        $pk = base64_decode($publicKeyBase64, true);
        $sig = base64_decode($sigB64, true);
        if ($pk === false || $sig === false
            || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, self::canonicalJson($payload), $pk);
    }

    /** Deterministic JSON (recursively key-sorted) so signer and verifier hash identical bytes. */
    public static function canonicalJson(mixed $data): string
    {
        return json_encode(self::canonicalize($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function canonicalize(mixed $v): mixed
    {
        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map(fn ($x) => self::canonicalize($x), $v);
            }
            ksort($v);

            return array_map(fn ($x) => self::canonicalize($x), $v);
        }

        return $v;
    }
}
