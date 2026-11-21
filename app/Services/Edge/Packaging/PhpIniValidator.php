<?php

namespace App\Services\Edge\Packaging;

/**
 * Edge 0.9 (stream E) — the PORTABLE php.ini contract of a packaged PHP runtime.
 *
 * Khatri 21 Nov 2026: the 0.8.4 package shipped the build laptop's Laragon php.ini verbatim (extension_dir, include_path,
 * error_log, sendmail_path, session.save_path and curl.cainfo all pointed at D:\laragon2\...), so every extension failed
 * to load on the shop PC. The builder therefore WRITES the packaged php/php.ini itself (sanitize) and REFUSES the build
 * when the result is still machine-bound (validate).
 *
 * Contract of a packaged php/php.ini:
 *   - extension_dir = "ext"                      (relative: php.exe resolves it from its own directory on Windows)
 *   - include_path  = "."
 *   - error_log, sendmail_path, session.save_path, openssl.capath and EVERY other directive whose value is an absolute
 *     host path (drive letter, UNC, POSIX-absolute on a path key, "laragon", a given build-host marker) are commented out
 *     — the PHP defaults (stderr / none / system temp) are right on an appliance;
 *   - curl.cainfo = openssl.cafile = "__BINGOO_PHP_DIR__\cacert.txt" — a PLACEHOLDER the installer/updater replaces with
 *     the real installed php directory (stream B contract); the CA bundle itself ships as php/cacert.txt because the
 *     package boundary forbids *.pem / *.crt;
 *   - the extensions the appliance needs (REQUIRED_EXTENSIONS) are enabled.
 *
 * Pure string functions: no filesystem access except the optional DLL presence check in validate().
 */
final class PhpIniValidator
{
    public const PHP_DIR_PLACEHOLDER = '__BINGOO_PHP_DIR__';

    public const CA_FILE = 'cacert.txt';

    /** The exact value curl.cainfo / openssl.cafile carry inside a package (before the installer substitutes the placeholder). */
    public const CA_VALUE = self::PHP_DIR_PLACEHOLDER . '\\' . self::CA_FILE;

    /** The same list the installer probes with `php -m` (Install-EdgeAppliance.ps1 / Update-EdgeAppliance.ps1). */
    public const REQUIRED_EXTENSIONS = ['curl', 'fileinfo', 'gd', 'mbstring', 'openssl', 'pdo_mysql', 'sodium'];

    /** Directives the sanitiser sets itself (the value written, already quoted). */
    private const PINNED = [
        'extension_dir' => '"ext"',
        'include_path' => '"."',
        'curl.cainfo' => '"' . self::CA_VALUE . '"',
        'openssl.cafile' => '"' . self::CA_VALUE . '"',
    ];

    /** Machine-specific directives that are always commented out (the PHP default is the appliance's right value). */
    private const DROPPED = ['error_log', 'sendmail_path', 'session.save_path', 'openssl.capath'];

    /** Directives whose value is a FILESYSTEM path: a leading "/" or "\" is an absolute (root-relative) path there. */
    private const PATH_KEYS = [
        'extension_dir', 'include_path', 'error_log', 'sendmail_path', 'session.save_path', 'curl.cainfo', 'openssl.cafile',
        'openssl.capath', 'upload_tmp_dir', 'sys_temp_dir', 'opcache.file_cache', 'opcache.error_log', 'opcache.lockfile_path',
        'opcache.preload', 'soap.wsdl_cache_dir', 'doc_root', 'user_dir', 'auto_prepend_file', 'auto_append_file', 'browscap',
        'mail.log', 'open_basedir', 'sqlite3.extension_dir', 'ffi.preload', 'xdebug.output_dir', 'xdebug.log', 'extension',
        'zend_extension', 'pdo_mysql.default_socket', 'mysqli.default_socket', 'phar.cache_list', 'session.upload_progress.name_dir',
    ];

    /** Marker that tags every line the sanitiser commented out (greppable in the packaged ini). */
    public const COMMENT_TAG = ';[bingoo-edge-package] ';

    /** @param string[] $hostMarkers extra case-insensitive substrings that identify the build host (its profile dir, the runtime source dir…) */
    public function __construct(private readonly array $hostMarkers = [])
    {
    }

    /**
     * Rewrite a runtime's php.ini into the packaged, portable form.
     *
     * @return array{ini:string, changes:list<array{line:int|null, key:string, action:string}>}
     */
    public function sanitize(string $ini): array
    {
        $eol = str_contains($ini, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n|\r/', $ini) ?: [];
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines); // the trailing newline is re-added below
        }
        $out = [];
        $changes = [];
        $seen = [];
        $phpHeaderIndex = null;
        $scopedDrop = false;
        foreach ($lines as $i => $line) {
            $n = $i + 1;
            if ($i === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line; // a UTF-8 BOM would be read as part of the first token
            }
            $parsed = $this->parseLine($line);
            if ($parsed['type'] === 'section') {
                $scopedDrop = false;
                if (preg_match('/^(PATH|HOST)\s*=/i', $parsed['name']) === 1 && $this->isHostPath('section', (string) preg_replace('/^(PATH|HOST)\s*=\s*/i', '', $parsed['name']))) {
                    // A [PATH=D:\laragon\www] scope names a build-host folder: drop the header AND every directive in it
                    // (commenting only the header would turn its directives global).
                    $scopedDrop = true;
                    $out[] = self::COMMENT_TAG . $line;
                    $changes[] = ['line' => $n, 'key' => '[' . strtoupper(strtok($parsed['name'], '=')) . '=…]', 'action' => 'commented_scope'];

                    continue;
                }
                if (strcasecmp($parsed['name'], 'PHP') === 0 && $phpHeaderIndex === null) {
                    $phpHeaderIndex = count($out);
                }
                $out[] = $line;

                continue;
            }
            if ($parsed['type'] !== 'directive') {
                $out[] = $line;

                continue;
            }
            $key = $parsed['key'];
            $lk = strtolower($key);
            if ($scopedDrop) {
                $out[] = self::COMMENT_TAG . $line;
                $changes[] = ['line' => $n, 'key' => $key, 'action' => 'commented_in_scope'];

                continue;
            }
            if (isset(self::PINNED[$lk])) {
                $new = $key . ' = ' . self::PINNED[$lk];
                if (isset($seen[$lk])) {
                    // A second occurrence would override the pinned value: comment it out.
                    $out[] = self::COMMENT_TAG . $line;
                    $changes[] = ['line' => $n, 'key' => $key, 'action' => 'commented_duplicate'];

                    continue;
                }
                $seen[$lk] = true;
                $out[] = $new;
                if (trim($line) !== $new) {
                    $changes[] = ['line' => $n, 'key' => $key, 'action' => 'set'];
                }

                continue;
            }
            if (in_array($lk, self::DROPPED, true)) {
                $out[] = self::COMMENT_TAG . $line;
                $changes[] = ['line' => $n, 'key' => $key, 'action' => 'commented_machine_specific'];

                continue;
            }
            if (($lk === 'extension' || $lk === 'zend_extension') && preg_match('#[\\\\/:]#', $parsed['value']) === 1) {
                // Loading an extension by PATH binds the runtime to the build host; a packaged runtime loads by name from ext\.
                $out[] = self::COMMENT_TAG . $line;
                $changes[] = ['line' => $n, 'key' => $key, 'action' => 'commented_extension_by_path'];

                continue;
            }
            if ($this->isHostPath($lk, $parsed['value'])) {
                $out[] = self::COMMENT_TAG . $line;
                $changes[] = ['line' => $n, 'key' => $key, 'action' => 'commented_absolute_path'];

                continue;
            }
            $out[] = $line;
        }
        // Pinned directives the source did not carry at all are inserted right after [PHP] (or at the top): sections are
        // cosmetic in php.ini except [PATH=]/[HOST=], so appending at the end could land inside a scoped section.
        $missing = [];
        foreach (self::PINNED as $k => $v) {
            if (! isset($seen[$k])) {
                $missing[] = $k . ' = ' . $v;
                $changes[] = ['line' => null, 'key' => $k, 'action' => 'added'];
            }
        }
        if ($missing !== []) {
            $block = array_merge([';[bingoo-edge-package] portable runtime directives (EdgePackageBuilder)'], $missing);
            $at = $phpHeaderIndex === null ? 0 : $phpHeaderIndex + 1;
            array_splice($out, $at, 0, $block);
        }

        return ['ini' => implode($eol, $out) . $eol, 'changes' => $changes];
    }

    /**
     * Validate a (packaged) php.ini. With $phpDir, every enabled extension's DLL must exist under that runtime's ext dir.
     *
     * @return array{ok:bool, problems:list<string>, extension_dir:?string, include_path:?string, curl_cainfo:?string,
     *               openssl_cafile:?string, extensions:list<string>, directives:int}
     */
    public function validate(string $ini, ?string $phpDir = null): array
    {
        $problems = [];
        $active = [];
        $extensions = [];
        $scoped = null;
        $lines = preg_split('/\r\n|\n|\r/', $ini) ?: [];
        foreach ($lines as $i => $line) {
            $n = $i + 1;
            if ($i === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            }
            $parsed = $this->parseLine($line);
            if ($parsed['type'] === 'section') {
                $scoped = preg_match('/^(PATH|HOST)\s*=\s*(.*)$/i', $parsed['name'], $m) === 1 ? $m[2] : null;
                if ($scoped !== null && $this->isHostPath('section', $scoped)) {
                    $problems[] = "ABSOLUTE_HOST_PATH: section [{$parsed['name']}] (line {$n}) scopes directives to a build-host folder";
                }

                continue;
            }
            if ($parsed['type'] !== 'directive') {
                continue;
            }
            $lk = strtolower($parsed['key']);
            $value = $parsed['value'];
            if ($lk === 'extension') {
                $extensions[] = $this->extensionName($value);
            }
            if (($lk === 'extension' || $lk === 'zend_extension') && preg_match('#[\\\\/:]#', $value) === 1) {
                $problems[] = "EXTENSION_BY_PATH: {$parsed['key']}={$value} (line {$n}) loads a DLL from a host path";

                continue;
            }
            if ($scoped === null) {
                $active[$lk] = ['value' => $value, 'line' => $n];
            }
            if ($this->isHostPath($lk, $value)) {
                $problems[] = "ABSOLUTE_HOST_PATH: {$parsed['key']} = \"{$value}\" (line {$n})";
            }
        }
        $extDir = $active['extension_dir']['value'] ?? null;
        if ($extDir === null || $extDir === '') {
            $problems[] = 'EXTENSION_DIR_MISSING: php.ini sets no extension_dir (PHP would fall back to its compiled-in build path)';
        } elseif (! $this->isRelativePath($extDir)) {
            $problems[] = "EXTENSION_DIR_NOT_RELATIVE: extension_dir = \"{$extDir}\" (must be relative to php.exe, e.g. \"ext\")";
        }
        foreach (['curl.cainfo', 'openssl.cafile'] as $k) {
            $v = $active[$k]['value'] ?? null;
            if ($v !== self::CA_VALUE) {
                $problems[] = 'CA_BUNDLE_NOT_PLACEHOLDER: ' . $k . ' = ' . ($v === null ? '(unset)' : '"' . $v . '"') . ' (must be "' . self::CA_VALUE . '")';
            }
        }
        $extensions = array_values(array_unique(array_filter($extensions)));
        foreach (self::REQUIRED_EXTENSIONS as $req) {
            if (! in_array($req, $extensions, true)) {
                $problems[] = "EXTENSION_NOT_ENABLED: {$req} (the appliance needs " . implode(', ', self::REQUIRED_EXTENSIONS) . ')';
            }
        }
        if ($phpDir !== null && $extDir !== null && $extDir !== '' && $this->isRelativePath($extDir)) {
            $base = rtrim(str_replace('\\', '/', $phpDir), '/') . '/' . trim(str_replace('\\', '/', $extDir), '/');
            foreach ($extensions as $ext) {
                if (! is_file($base . '/php_' . $ext . '.dll') && ! is_file($base . '/' . $ext . '.dll')) {
                    $problems[] = "EXTENSION_DLL_MISSING: {$ext} is enabled but {$extDir}/php_{$ext}.dll is not in the runtime";
                }
            }
        }

        return [
            'ok' => $problems === [],
            'problems' => $problems,
            'extension_dir' => $extDir,
            'include_path' => $active['include_path']['value'] ?? null,
            'curl_cainfo' => $active['curl.cainfo']['value'] ?? null,
            'openssl_cafile' => $active['openssl.cafile']['value'] ?? null,
            'extensions' => $extensions,
            'directives' => count($active),
        ];
    }

    /**
     * True when $value names a build-host location: a drive-letter path anywhere in it, a UNC path, "laragon", a given
     * host marker, a home-relative path, or — on a filesystem-path directive — a root-relative path ("/tmp").
     * The package placeholder (__BINGOO_PHP_DIR__\…) is never a host path.
     */
    public function isHostPath(string $key, string $value): bool
    {
        $v = trim($value);
        if ($v === '' || str_starts_with($v, self::PHP_DIR_PLACEHOLDER)) {
            return false;
        }
        if (preg_match('#(?<![A-Za-z0-9])[A-Za-z]:[\\\\/]#', $v) === 1) {
            return true;
        }
        if (preg_match('#^(\\\\\\\\|//)[^/\\\\]#', $v) === 1 || preg_match('#^~[\\\\/]#', $v) === 1) {
            return true;
        }
        if (stripos($v, 'laragon') !== false) {
            return true;
        }
        foreach ($this->hostMarkers as $marker) {
            $m = trim(str_replace('\\', '/', (string) $marker), '/');
            if ($m !== '' && stripos(str_replace('\\', '/', $v), $m) !== false) {
                return true;
            }
        }
        $key = strtolower($key);
        if ($key === 'section' || in_array($key, self::PATH_KEYS, true)) {
            foreach (explode(';', $v) as $part) {
                $part = trim($part);
                if ($part !== '' && ($part[0] === '/' || $part[0] === '\\')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isRelativePath(string $path): bool
    {
        $p = trim($path);

        return $p !== '' && ! $this->isHostPath('extension_dir', $p) && ! str_contains($p, '..') && ! str_contains($p, ':');
    }

    private function extensionName(string $value): string
    {
        $name = strtolower(basename(str_replace('\\', '/', trim($value))));
        $name = (string) preg_replace('/\.(dll|so)$/', '', $name);

        return (string) preg_replace('/^php_/', '', $name);
    }

    /**
     * One php.ini line → comment | blank | section | directive (key + unquoted value, inline comment stripped) | other.
     *
     * @return array{type:string, name?:string, key?:string, value?:string}
     */
    private function parseLine(string $line): array
    {
        $t = trim($line);
        if ($t === '') {
            return ['type' => 'blank'];
        }
        if ($t[0] === ';' || $t[0] === '#') {
            return ['type' => 'comment'];
        }
        if (preg_match('/^\[([^\]]*)\]\s*(;.*)?$/', $t, $m) === 1) {
            return ['type' => 'section', 'name' => trim($m[1])];
        }
        $eq = strpos($t, '=');
        if ($eq === false) {
            return ['type' => 'other'];
        }
        $key = trim(substr($t, 0, $eq));
        $raw = ltrim(substr($t, $eq + 1));
        if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
            $q = $raw[0];
            $end = strpos($raw, $q, 1);
            $value = $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
        } else {
            $semi = strpos($raw, ';');
            $value = trim($semi === false ? $raw : substr($raw, 0, $semi));
        }

        return ['type' => 'directive', 'key' => $key, 'value' => $value];
    }
}
