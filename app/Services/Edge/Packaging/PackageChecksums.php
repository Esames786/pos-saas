<?php

namespace App\Services\Edge\Packaging;

use App\Services\Edge\EdgeSigningKeyStore;
use RuntimeException;

/**
 * Edge 0.9 (stream E) — signed SHA256SUMS for every DISTRIBUTABLE script/executable of a package: the fallback while no
 * Authenticode code-signing certificate exists (21 Nov 2026: none in CurrentUser\My or LocalMachine\My), so Windows
 * SmartScreen / AllSigned cannot vouch for Setup-BingooEdge.cmd, the PowerShell scripts, php.exe, mysqld.exe or nginx.exe.
 *
 *   SHA256SUMS      "<sha256>  <relative/path>" per line (sha256sum text format, sorted, LF) — every *.ps1 / *.psm1 /
 *                   *.psd1 / *.cmd / *.bat / *.vbs / *.exe / *.dll / *.phar, and *.php launchers outside the application
 *                   code (app/ itself is covered by the signed artifact manifest; app/scripts/** is listed here too);
 *                   app/vendor is never listed.
 *   SHA256SUMS.sig  JSON {format, algorithm: ed25519, key_id, file, file_sha256, signature} — an Ed25519 DETACHED
 *                   signature over the exact bytes of SHA256SUMS with the package (update) signing key: the dev key for
 *                   rehearsals, the custody release key for releases. Any holder of the public key (the appliance's
 *                   EDGE_UPDATE_PUBLIC_KEY, the Cloud, an operator) can verify it; PowerShell users check single files
 *                   against SHA256SUMS with Get-FileHash.
 */
final class PackageChecksums
{
    public const FILE = 'SHA256SUMS';

    public const SIG_FILE = 'SHA256SUMS.sig';

    public const SIG_FORMAT = 'bingoo-edge-sha256sums-sig-v1';

    private const EXTENSIONS = ['ps1', 'psm1', 'psd1', 'cmd', 'bat', 'vbs', 'exe', 'dll', 'phar'];

    /** Is $rel (package-relative, forward slashes) a distributable script/executable that SHA256SUMS must list? */
    public static function isDistributable(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === self::FILE || $rel === self::SIG_FILE || str_starts_with($rel, 'app/vendor/')) {
            return false;
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (in_array($ext, self::EXTENSIONS, true)) {
            return true;
        }
        // A PHP file is a distributable launcher/probe outside the application code (templates/edge-launcher.php,
        // app/scripts/edge/appliance/*.php); app/app, app/config, app/routes… are bound by the signed artifact manifest.
        return $ext === 'php' && (! str_starts_with($rel, 'app/') || str_starts_with($rel, 'app/scripts/'));
    }

    /**
     * Write SHA256SUMS (+ SHA256SUMS.sig when a signing key is given) into an assembled package.
     *
     * @return array the components.checksums manifest entry
     */
    public function write(string $pkgDir, ?string $secretKeyBase64 = null, ?string $keyId = null): array
    {
        $dir = rtrim(str_replace('\\', '/', $pkgDir), '/');
        $lines = [];
        foreach ($this->distributableFiles($dir) as $rel) {
            $lines[] = hash_file('sha256', $dir . '/' . $rel) . '  ' . $rel;
        }
        $body = implode("\n", $lines) . "\n";
        file_put_contents($dir . '/' . self::FILE, $body);
        $entry = ['file' => self::FILE, 'entries' => count($lines), 'sha256' => hash('sha256', $body), 'signed' => false];
        $key = (string) $secretKeyBase64;
        if ($key !== '') {
            $sk = base64_decode($key, true);
            if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new RuntimeException('PACKAGE_CHECKSUMS_KEY_INVALID: not a base64 Ed25519 secret key');
            }
            $publicB64 = base64_encode(sodium_crypto_sign_publickey_from_secretkey($sk));
            $sig = [
                'format' => self::SIG_FORMAT,
                'algorithm' => 'ed25519',
                'key_id' => $keyId ?? EdgeSigningKeyStore::keyId($publicB64),
                'file' => self::FILE,
                'file_sha256' => $entry['sha256'],
                'signature' => base64_encode(sodium_crypto_sign_detached($body, $sk)),
            ];
            sodium_memzero($sk);
            file_put_contents($dir . '/' . self::SIG_FILE, json_encode($sig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $entry = array_merge($entry, ['signed' => true, 'signature_file' => self::SIG_FILE, 'signing_key_id' => $sig['key_id'], 'algorithm' => 'ed25519']);
        }

        return $entry;
    }

    /**
     * Verify SHA256SUMS against the bytes on disk (+ the detached signature when a public key is given).
     *
     * @return array{present:bool, signed:bool, ok:bool, entries:int, mismatched:list<string>, missing:list<string>,
     *               unlisted:list<string>, signature_checked:bool, signature_ok:?bool, key_id:?string, problems:list<string>}
     */
    public function verify(string $pkgDir, ?string $publicKeyBase64 = null): array
    {
        $dir = rtrim(str_replace('\\', '/', $pkgDir), '/');
        $out = ['present' => false, 'signed' => false, 'ok' => false, 'entries' => 0, 'mismatched' => [], 'missing' => [], 'unlisted' => [],
            'signature_checked' => false, 'signature_ok' => null, 'key_id' => null, 'problems' => []];
        if (! is_file($dir . '/' . self::FILE)) {
            $out['problems'][] = 'SHA256SUMS_MISSING: the package carries no ' . self::FILE;

            return $out;
        }
        $out['present'] = true;
        $body = (string) file_get_contents($dir . '/' . self::FILE);
        $listed = [];
        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^([0-9a-f]{64}) [ *](.+)$/', $line, $m) !== 1) {
                $out['problems'][] = 'SHA256SUMS_MALFORMED: line ' . ($n + 1);

                continue;
            }
            $listed[$m[2]] = $m[1];
        }
        $out['entries'] = count($listed);
        foreach ($listed as $rel => $hash) {
            if (! is_file($dir . '/' . $rel)) {
                $out['missing'][] = $rel;
            } elseif (! hash_equals($hash, (string) hash_file('sha256', $dir . '/' . $rel))) {
                $out['mismatched'][] = $rel;
            }
        }
        $out['unlisted'] = array_values(array_diff($this->distributableFiles($dir), array_keys($listed)));
        foreach (['missing' => 'SHA256SUMS_FILE_MISSING', 'mismatched' => 'SHA256SUMS_MISMATCH', 'unlisted' => 'SHA256SUMS_UNLISTED'] as $k => $code) {
            if ($out[$k] !== []) {
                $out['problems'][] = $code . ': ' . implode(', ', array_slice($out[$k], 0, 10));
            }
        }
        $sigPath = $dir . '/' . self::SIG_FILE;
        $sig = is_file($sigPath) ? json_decode((string) file_get_contents($sigPath), true) : null;
        $out['signed'] = is_array($sig);
        $out['key_id'] = is_array($sig) ? ($sig['key_id'] ?? null) : null;
        $pk = trim((string) $publicKeyBase64);
        if ($pk !== '') {
            $out['signature_checked'] = true;
            $raw = base64_decode($pk, true);
            $sigRaw = is_array($sig) ? base64_decode((string) ($sig['signature'] ?? ''), true) : false;
            if (! is_array($sig)) {
                $out['signature_ok'] = false;
                $out['problems'][] = 'SHA256SUMS_UNSIGNED: no ' . self::SIG_FILE;
            } elseif ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sigRaw === false || strlen($sigRaw) !== SODIUM_CRYPTO_SIGN_BYTES
                || ($sig['format'] ?? null) !== self::SIG_FORMAT || ($sig['algorithm'] ?? null) !== 'ed25519') {
                $out['signature_ok'] = false;
                $out['problems'][] = 'SHA256SUMS_SIGNATURE_INVALID: malformed key or signature file';
            } else {
                $out['signature_ok'] = sodium_crypto_sign_verify_detached($sigRaw, $body, $raw);
                if (! $out['signature_ok']) {
                    $out['problems'][] = 'SHA256SUMS_SIGNATURE_INVALID: not signed by key ' . EdgeSigningKeyStore::keyId($pk);
                }
                if (! hash_equals((string) ($sig['file_sha256'] ?? ''), hash('sha256', $body))) {
                    $out['problems'][] = 'SHA256SUMS_SIGNATURE_STALE: the signature names another SHA256SUMS';
                }
            }
        }
        $out['ok'] = $out['problems'] === [];

        return $out;
    }

    /** @return list<string> */
    private function distributableFiles(string $dir): array
    {
        $out = [];
        $vendor = $dir . '/app/vendor';
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            fn (\SplFileInfo $f) => ! ($f->isDir() && rtrim(str_replace('\\', '/', $f->getPathname()), '/') === $vendor)
        ));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/');
                if (self::isDistributable($rel)) {
                    $out[] = $rel;
                }
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }
}
