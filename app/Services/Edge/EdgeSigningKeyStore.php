<?php

namespace App\Services\Edge;

use RuntimeException;

/**
 * P5B §2 — RELEASE-SIGNING KEY CUSTODY (shape B: an offline, encrypted keystore held by the release authority).
 *
 * The Ed25519 update-signing PRIVATE key is never at rest in plaintext. It is sealed with libsodium secretbox
 * (XSalsa20-Poly1305) under a key derived from the release passphrase with Argon2id (sodium_crypto_pwhash, MODERATE
 * limits). The keystore file carries ONLY the public key, the key id (fingerprint), the KDF parameters, the nonce and
 * the ciphertext — a copied keystore without its passphrase signs nothing.
 *
 * Custody rules enforced here and by the callers:
 *   - minted on the release authority only (edge:update:keygen refuses on a Branch Server and inside the source tree);
 *   - the plaintext secret exists only in the memory of the signing process (edge:build-package) and is zeroed after use;
 *   - keystore + passphrase files are PACKAGE_FORBIDDEN and artifact-forbidden patterns, so they can never ride in a package;
 *   - nothing here is logged; the key id and the public key are the only facts that leave this class.
 *
 * This is NOT an HSM/KMS. It is the documented pilot custody: keystore on the release workstation, passphrase held by
 * the release manager, both required to sign. See docs/status/edge-p5b-release-operations.md for the custody register.
 */
final class EdgeSigningKeyStore
{
    public const FORMAT = 'bingoo-edge-signing-keystore';
    public const VERSION = 1;
    public const PURPOSE = 'edge-update-signing';
    public const MIN_PASSPHRASE = 16;

    /** Mint a keypair and seal it into a NEW keystore file. Returns public facts only (public_key, key_id, path). */
    public static function create(string $path, string $passphrase, array $meta = []): array
    {
        self::assertSodium();
        self::assertPassphrase($passphrase);
        if (file_exists($path)) {
            throw new RuntimeException('SIGNING_KEYSTORE_EXISTS: refusing to overwrite ' . $path);
        }
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('SIGNING_KEYSTORE_DIR: cannot create ' . $dir);
        }

        $kp = EdgeEnrollmentCrypto::generateKeypair();
        $keyId = self::keyId($kp['public']);
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
        $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
        $kek = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $secretRaw = (string) base64_decode($kp['secret'], true);
        $box = sodium_crypto_secretbox($secretRaw, $nonce, $kek);
        sodium_memzero($kek);
        sodium_memzero($secretRaw);
        unset($kp['secret']);

        $store = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'purpose' => self::PURPOSE,
            'algorithm' => 'ed25519',
            'key_id' => $keyId,
            'public_key' => $kp['public'],
            'kdf' => ['alg' => 'argon2id13', 'salt' => base64_encode($salt), 'opslimit' => $ops, 'memlimit' => $mem],
            'cipher' => 'xsalsa20poly1305',
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($box),
            'created_at' => gmdate('c'),
            'authority' => (string) ($meta['authority'] ?? (gethostname() ?: 'unknown-host')),
            'label' => (string) ($meta['label'] ?? ''),
        ];
        $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('SIGNING_KEYSTORE_WRITE: cannot write ' . $path);
        }
        @chmod($path, 0600);

        return ['public_key' => $kp['public'], 'key_id' => $keyId, 'path' => $path];
    }

    /** Public facts about a keystore — never the secret. */
    public static function describe(string $path): array
    {
        $store = self::read($path);

        return [
            'key_id' => (string) $store['key_id'],
            'public_key' => (string) $store['public_key'],
            'algorithm' => (string) ($store['algorithm'] ?? 'ed25519'),
            'kdf' => (string) ($store['kdf']['alg'] ?? ''),
            'cipher' => (string) ($store['cipher'] ?? ''),
            'created_at' => (string) ($store['created_at'] ?? ''),
            'authority' => (string) ($store['authority'] ?? ''),
            'label' => (string) ($store['label'] ?? ''),
        ];
    }

    /** Open the keystore: the base64 Ed25519 SECRET key, for in-memory signing only. Wrong passphrase → refused. */
    public static function open(string $path, string $passphrase): string
    {
        self::assertSodium();
        $store = self::read($path);
        $salt = base64_decode((string) ($store['kdf']['salt'] ?? ''), true);
        $nonce = base64_decode((string) ($store['nonce'] ?? ''), true);
        $box = base64_decode((string) ($store['ciphertext'] ?? ''), true);
        $ops = (int) ($store['kdf']['opslimit'] ?? 0);
        $mem = (int) ($store['kdf']['memlimit'] ?? 0);
        if (($store['kdf']['alg'] ?? '') !== 'argon2id13' || ! is_string($salt) || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES
            || ! is_string($nonce) || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || ! is_string($box) || $box === ''
            || $ops < 1 || $mem < 8192) { // libsodium minimums (PHP exposes no *_MIN constants)
            throw new RuntimeException('SIGNING_KEYSTORE_CORRUPT: the keystore parameters are not valid.');
        }
        $kek = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $plain = sodium_crypto_secretbox_open($box, $nonce, $kek);
        sodium_memzero($kek);
        if ($plain === false) {
            throw new RuntimeException('SIGNING_KEYSTORE_PASSPHRASE_INVALID: the passphrase does not open this keystore.');
        }
        if (strlen($plain) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            sodium_memzero($plain);
            throw new RuntimeException('SIGNING_KEYSTORE_CORRUPT: the sealed key has the wrong length.');
        }
        // The opened secret must be the private half of the public key on the label (keystore integrity).
        $derivedPublic = sodium_crypto_sign_publickey_from_secretkey($plain);
        if (! hash_equals($derivedPublic, (string) base64_decode((string) $store['public_key'], true))) {
            sodium_memzero($plain);
            throw new RuntimeException('SIGNING_KEYSTORE_MISMATCH: the sealed key does not match the keystore public key.');
        }
        $b64 = base64_encode($plain);
        sodium_memzero($plain);

        return $b64;
    }

    /** Key id = the first 16 hex chars of sha256(raw public key) — the same fingerprint appliances and docs record. */
    public static function keyId(string $publicKeyBase64): string
    {
        $raw = base64_decode($publicKeyBase64, true);

        return substr(hash('sha256', $raw === false ? $publicKeyBase64 : $raw), 0, 16);
    }

    /** Read a passphrase from a FILE (never argv, never an env var): the trailing newline is stripped, nothing else. */
    public static function readPassphraseFile(string $file): string
    {
        if ($file === '' || ! is_file($file)) {
            throw new RuntimeException('SIGNING_PASSPHRASE_FILE_MISSING: ' . ($file === '' ? '(none given)' : $file));
        }
        $p = rtrim((string) file_get_contents($file), "\r\n");
        self::assertPassphrase($p);

        return $p;
    }

    private static function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('SIGNING_KEYSTORE_MISSING: ' . $path);
        }
        $store = json_decode((string) file_get_contents($path), true);
        if (! is_array($store) || ($store['format'] ?? '') !== self::FORMAT || (int) ($store['version'] ?? 0) !== self::VERSION
            || ($store['purpose'] ?? '') !== self::PURPOSE || ! is_string($store['public_key'] ?? null) || ! is_string($store['key_id'] ?? null)) {
            throw new RuntimeException('SIGNING_KEYSTORE_INVALID: not a ' . self::FORMAT . ' v' . self::VERSION . ' file.');
        }
        if (! hash_equals(self::keyId($store['public_key']), $store['key_id'])) {
            throw new RuntimeException('SIGNING_KEYSTORE_INVALID: the key id does not match the public key.');
        }

        return $store;
    }

    private static function assertPassphrase(string $p): void
    {
        if (strlen($p) < self::MIN_PASSPHRASE) {
            throw new RuntimeException('SIGNING_PASSPHRASE_WEAK: the keystore passphrase must be at least ' . self::MIN_PASSPHRASE . ' characters.');
        }
    }

    private static function assertSodium(): void
    {
        if (! EdgeEnrollmentCrypto::available() || ! function_exists('sodium_crypto_pwhash') || ! function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('SIGNING_KEYSTORE_UNSUPPORTED: libsodium with Argon2id + secretbox is required.');
        }
    }
}
