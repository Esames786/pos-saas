<?php

namespace App\Services\Edge\Packaging;

use RuntimeException;

/**
 * Edge 0.9 (stream E) — bundle a PHP runtime into a package as a PORTABLE runtime (php/).
 *
 *   1. copy the runtime tree (CA files *.pem/*.crt/*.cer, php.ini backups and logs are left behind — the CA bundle is
 *      re-shipped as cacert.txt, the boundary forbids the others);
 *   2. WRITE php/php.ini from the runtime's php.ini through PhpIniValidator::sanitize();
 *   3. bundle the CA bundle as php/cacert.txt (an explicit --ca-bundle, the configured path, the runtime's own cacert.txt /
 *      extras/ssl/cacert.pem / cacert.pem, or the file the source ini's curl.cainfo / openssl.cafile named);
 *   4. VALIDATE the written ini (fail closed: absolute host path, non-relative extension_dir, CA not the placeholder,
 *      a required extension disabled or its DLL missing);
 *   5. SMOKE: run the packaged php.exe with ONLY its packaged php.ini from a foreign working directory and require every
 *      required extension to load without a startup warning (skipped only when the caller says so — fake runtimes in tests).
 *
 * Any failure throws a RuntimeException with a stable PACKAGE_PHP_* code; the builder removes the partial package.
 */
final class PhpRuntimePackager
{
    public const MIN_CA_CERTIFICATES = 10;

    /** Runtime files never copied into the package (relative to the runtime root, forward slashes). */
    private const SKIP = [
        '#\.(pem|crt|cer)$#i',          // CA material: re-shipped as php/cacert.txt (the boundary forbids *.pem / *.crt)
        '#(^|/)php\.ini\.[^/]+$#i',      // php.ini.bak / php.ini.orig — a machine-bound copy of the source ini
        '#\.(log|err)$#i',
        '#(^|/)(tmp|logs|sessions)/#i',
    ];

    public function __construct(private readonly ?PhpIniValidator $validator = null)
    {
    }

    /**
     * @param  array{ca_bundle?:?string, smoke?:bool, host_markers?:string[]}  $opts
     * @return array the components.php manifest entry
     */
    public function package(string $runtimeDir, string $destPhpDir, array $opts = []): array
    {
        $src = rtrim(str_replace('\\', '/', $runtimeDir), '/');
        $dst = rtrim(str_replace('\\', '/', $destPhpDir), '/');
        if (! is_file($src . '/php.exe')) {
            throw new RuntimeException('PACKAGE_PHP_RUNTIME_INVALID: no php.exe under ' . $runtimeDir);
        }
        if (! is_file($src . '/php.ini')) {
            throw new RuntimeException('PACKAGE_PHP_INI_MISSING: the runtime has no php.ini (PHP would run on its compiled-in build paths) — ' . $runtimeDir);
        }
        $markers = array_values(array_filter(array_merge([$src, (string) getenv('USERPROFILE')], (array) ($opts['host_markers'] ?? []))));
        $validator = $this->validator ?? new PhpIniValidator($markers);

        // 1. copy
        $copied = 0;
        $skipped = [];
        foreach ($this->files($src) as $rel) {
            if ($rel === 'php.ini') {
                continue; // written below
            }
            if ($this->skipped($rel)) {
                $skipped[] = $rel;

                continue;
            }
            $to = $dst . '/' . $rel;
            if (! is_dir(dirname($to))) {
                mkdir(dirname($to), 0755, true);
            }
            copy($src . '/' . $rel, $to);
            $copied++;
        }

        // 2. php.ini
        $sourceIni = (string) file_get_contents($src . '/php.ini');
        $sanitised = $validator->sanitize($sourceIni);
        if (! is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        file_put_contents($dst . '/php.ini', $sanitised['ini']);

        // 3. CA bundle
        [$caSource, $caKind] = $this->resolveCaBundle($src, $sourceIni, $opts['ca_bundle'] ?? null);
        $ca = self::inspectCaBundle($caSource);
        copy($caSource, $dst . '/' . PhpIniValidator::CA_FILE);

        // 4. validate (fail closed)
        $check = $validator->validate((string) file_get_contents($dst . '/php.ini'), $dst);
        if (! $check['ok']) {
            throw new RuntimeException('PACKAGE_PHP_INI_INVALID: the packaged php/php.ini is not portable — ' . implode(' | ', $check['problems']));
        }

        // 5. smoke
        $smoke = ['ran' => false];
        if ($opts['smoke'] ?? true) {
            $smoke = $this->smoke($dst);
        }

        return [
            'bundled' => true,
            'source' => $runtimeDir,
            'file_count' => $copied + 2, // + php.ini + cacert.txt
            'version' => $smoke['version'] ?? null,
            'php_ini' => [
                'written_by_builder' => true,
                'validator' => 'ok',
                'extension_dir' => $check['extension_dir'],
                'include_path' => $check['include_path'],
                'ca_placeholder' => PhpIniValidator::CA_VALUE,
                'placeholder_token' => PhpIniValidator::PHP_DIR_PLACEHOLDER,
                'extensions' => $check['extensions'],
                // keys + actions only: the source values are build-host paths and never enter the package
                'changes' => array_map(fn ($c) => $c['key'] . ':' . $c['action'], $sanitised['changes']),
            ],
            'ca_bundle' => ['file' => 'php/' . PhpIniValidator::CA_FILE, 'source_kind' => $caKind, 'sha256' => $ca['sha256'], 'certificates' => $ca['certificates'], 'bytes' => $ca['bytes']],
            'skipped' => array_slice($skipped, 0, 50),
            'smoke' => $smoke,
        ];
    }

    /** @return array{sha256:string, certificates:int, bytes:int} */
    public static function inspectCaBundle(string $file): array
    {
        $body = (string) @file_get_contents($file);
        if (preg_match('/PRIVATE KEY-----/', $body) === 1) {
            throw new RuntimeException('PACKAGE_PHP_CA_BUNDLE_INVALID: the CA bundle contains a private key — ' . $file);
        }
        $count = preg_match_all('/-----BEGIN CERTIFICATE-----/', $body);
        if ($count < self::MIN_CA_CERTIFICATES) {
            throw new RuntimeException('PACKAGE_PHP_CA_BUNDLE_INVALID: ' . $file . ' holds ' . (int) $count . ' certificate(s); a public CA bundle (Mozilla cacert) is expected (>= ' . self::MIN_CA_CERTIFICATES . ')');
        }

        return ['sha256' => hash('sha256', $body), 'certificates' => (int) $count, 'bytes' => strlen($body)];
    }

    /** @return array{0:string, 1:string} [absolute file, kind] */
    private function resolveCaBundle(string $src, string $sourceIni, ?string $explicit): array
    {
        if ($explicit !== null && $explicit !== '') {
            if (! is_file($explicit)) {
                throw new RuntimeException('PACKAGE_PHP_CA_BUNDLE_MISSING: --ca-bundle ' . $explicit . ' does not exist');
            }

            return [$explicit, 'option'];
        }
        $configured = (string) (function_exists('config') ? (config('edge.package.ca_bundle') ?? '') : '');
        if ($configured !== '' && is_file($configured)) {
            return [$configured, 'config'];
        }
        foreach (['cacert.txt', 'extras/ssl/cacert.pem', 'cacert.pem', 'extras/ssl/cacert.txt'] as $rel) {
            if (is_file($src . '/' . $rel)) {
                return [$src . '/' . $rel, 'runtime:' . $rel];
            }
        }
        // The file the build host's ini already trusted (e.g. Laragon's etc\ssl\cacert.pem) — its CONTENT is portable.
        foreach (['curl.cainfo', 'openssl.cafile'] as $key) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*"?([^";\r\n]+)"?/mi', $sourceIni, $m) === 1) {
                $path = trim($m[1]);
                if ($path !== '' && is_file($path)) {
                    return [$path, 'source_ini:' . $key];
                }
            }
        }

        throw new RuntimeException('PACKAGE_PHP_CA_BUNDLE_MISSING: no CA bundle found for the runtime — pass --ca-bundle=<Mozilla cacert file> (it ships as php/cacert.txt)');
    }

    /** @return array{ran:bool, ok:bool, version:?string, extension_dir:?string, modules_missing:list<string>} */
    private function smoke(string $dst): array
    {
        $php = str_replace('/', DIRECTORY_SEPARATOR, $dst . '/php.exe');
        $ini = str_replace('/', DIRECTORY_SEPARATOR, $dst . '/php.ini');
        $code = 'echo json_encode(["v"=>PHP_VERSION,"ext_dir"=>ini_get("extension_dir"),"loaded"=>array_map("strtolower",get_loaded_extensions())]);';
        $env = getenv();
        unset($env['PHPRC']);
        $env['PHP_INI_SCAN_DIR'] = ''; // only the packaged ini
        $cwd = sys_get_temp_dir();       // a FOREIGN working directory: extension_dir="ext" must resolve from php.exe, not the cwd
        $proc = @proc_open([$php, '-c', $ini, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (! is_resource($proc)) {
            throw new RuntimeException('PACKAGE_PHP_RUNTIME_SMOKE_FAILED: the packaged php.exe could not be started');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $json = null;
        if (preg_match('/\{.*\}\s*$/s', $stdout, $m) === 1) {
            $json = json_decode($m[0], true);
        }
        $warnings = trim(preg_replace('/\{.*\}\s*$/s', '', $stdout) . "\n" . $stderr);
        if ($exit !== 0 || ! is_array($json)) {
            throw new RuntimeException('PACKAGE_PHP_RUNTIME_SMOKE_FAILED: packaged php.exe exit ' . $exit . ' — ' . substr($warnings, 0, 400));
        }
        if (preg_match('/(Warning|Fatal|Unable to load dynamic library)/i', $warnings) === 1) {
            throw new RuntimeException('PACKAGE_PHP_RUNTIME_SMOKE_FAILED: startup warnings with the packaged php.ini — ' . substr($warnings, 0, 400));
        }
        $missing = array_values(array_diff(PhpIniValidator::REQUIRED_EXTENSIONS, (array) ($json['loaded'] ?? [])));
        if ($missing !== []) {
            throw new RuntimeException('PACKAGE_PHP_RUNTIME_SMOKE_FAILED: required extensions not loaded by the packaged runtime: ' . implode(', ', $missing));
        }

        return ['ran' => true, 'ok' => true, 'version' => (string) ($json['v'] ?? ''), 'extension_dir' => (string) ($json['ext_dir'] ?? ''), 'modules_missing' => []];
    }

    private function skipped(string $rel): bool
    {
        foreach (self::SKIP as $p) {
            if (preg_match($p, $rel) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> relative file paths (forward slashes) */
    private function files(string $root): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
            }
        }
        sort($out);

        return $out;
    }
}
