<?php

namespace App\Services\Edge;

use App\Services\Edge\Packaging\MySqlRuntimeBundler;
use App\Services\Edge\Packaging\PackageLayoutInspector;
use App\Services\Edge\Packaging\PhpIniValidator;
use App\Services\Edge\Packaging\PhpRuntimePackager;
use App\Services\Edge\Packaging\UpdateSignatureAudit;
use RuntimeException;

/**
 * P4 §5 — build the restricted WINDOWS APPLIANCE PACKAGE from the accepted artifact, and verify one.
 *
 * Package layout (edge-package-v1; Edge 0.9 K8 additions marked *):
 *   package-manifest.json      identity + every file's sha256 + the boundary audit result (written LAST)
 *   Setup-BingooEdge.cmd     * the setup wizard launcher (copy of app/scripts/edge/Setup-BingooEdge.cmd, when present)
 *   app/                        the restricted Edge artifact (EdgeArtifactBuilder; includes edge-build-manifest.json)
 *   app/scripts/edge/wizard/ * stream B's setup wizard (optional in a dev build, REQUIRED in a 0.9+ release build)
 *   php/                        the PHP runtime (bundled when a runtime dir is given; otherwise the installer's -PhpPath)
 *                            * php/php.ini is WRITTEN by the builder (PhpIniValidator: relative extension_dir, no host
 *                              path, curl.cainfo/openssl.cafile = "__BINGOO_PHP_DIR__\cacert.txt") + php/cacert.txt
 *   mysql/                   * the MySQL 8.0 server runtime (bin/share/lib only; when --mysql-runtime is given)
 *   gateway/nginx.exe           the TLS gateway binary (vendored when given; otherwise the installer's -GatewayPath)
 *   scripts/                    the reviewed appliance PowerShell scripts + launcher (copied from app/scripts/edge)
 *   templates/                  appliance.env.template (no values), nginx mime.types, README-INSTALL.md
 *   update/edge-update-<v>.json the SIGNED update package for this same artifact (when a signing key is given)
 *
 * BOUNDARY GATE (mandatory): after assembly the whole tree is walked; any artifact-forbidden pattern (.env, keys,
 * certs, dumps, tests, .git, logs…), any Cloud-only module the artifact excludes, node_modules or a dev scratch file
 * fails the build and the partial package is removed. Production secrets never exist in the package: the env
 * template carries KEYS ONLY and the installer provisions values into <data-root>/config/appliance.env.
 *
 * LAYOUT GATES (Edge 0.9): a non-portable php.ini and a Windows-10-incompatible task duration ([TimeSpan]::MaxValue,
 * > 3650 days) in any packaged script always fail the build; a missing wizard / root Setup-BingooEdge.cmd and a
 * non-central -CloudUrl default or example fail a 0.9+ RELEASE build and are warnings in a dev build.
 */
class EdgePackageBuilder
{
    public const FORMAT = 'edge-package-v1';

    /** Fingerprint of the P5B custody release key v1 (public); any other signing key marks the package as DEV-signed. */
    public const RELEASE_SIGNING_KEY_ID = 'a2eaf8a3d3e66a48';

    /** Package-level forbidden patterns on top of the artifact's own list. */
    private const PACKAGE_FORBIDDEN = [
        '#(^|/)\.env(\..+)?$#',
        '#(^|/)\.git(/|$)#',
        '#(^|/)node_modules(/|$)#',
        '#(^|/)tests(/|$)#',
        '#\.(pem|key|pfx|p12)$#i',
        '#(^|/)id_(rsa|ed25519)(\..+)?$#',
        '#\.(sql|dump|sqlite)$#i',
        '#(^|/)storage/logs/(?!\.gitkeep$).#',
        '#(^|/)\.psysh_history$#',
        '#(^|/)appliance\.env$#',      // a provisioned env file must never ride in a package
        '#(^|/)appliance\.json$#',     // the per-install layout file is written by the installer only
        '#keystore[^/]*\.json$#i',      // P5B §2: a release-signing keystore never rides in a package
        '#\.(keystore|passphrase)$#i',  // P5B §2: nor its passphrase file
    ];

    /** Cloud-only sentinels that must be physically ABSENT from app/ (mirror of the artifact exclude analysis). */
    private const CLOUD_ONLY_SENTINELS = [
        'app/Services/Catering',
        'app/Services/Finance/ManualJournalService.php',
        'app/Services/Edge/EdgeInboundSaleIngestionService.php',
        'app/Services/Edge/EdgeInboundReturnIngestionService.php',
        'app/Services/Edge/EdgeInboundSupplierFinanceIngestionService.php',
        'app/Services/Edge/EdgeInboundPurchaseReturnIngestionService.php',
        'app/Services/Edge/EdgeBackupRecoveryAuthority.php',
        'app/Http/Controllers/Tenant/SupplierPaymentController.php',
        'app/Http/Controllers/Tenant/Finance/ManualJournalController.php',
    ];

    /** Edge runtime sentinels that must be PRESENT in app/. */
    private const EDGE_RUNTIME_SENTINELS = [
        'app/edge-build-manifest.json',
        'app/artisan',
        'app/public/index.php',
        'app/routes/edge_runtime.php',
        'app/app/Services/Edge/EdgeSupervisionPlan.php',
        'app/app/Services/Edge/EdgeLocalPrintDeliveryService.php',
        'app/app/Services/Edge/EdgeUpdateInstaller.php',
        'app/app/Services/Edge/EdgeBackupService.php',
        'app/app/Services/Edge/EdgeRestoreService.php',
        'app/app/Services/Edge/EdgeApplianceHealthService.php',
        'app/app/Console/Commands/EdgeLocalServeCommand.php',
        'app/app/Console/Commands/EdgeLocalPairCommand.php',
        'app/app/Console/Commands/EdgeLocalBootstrapPullCommand.php',
        'app/scripts/edge/Install-EdgeAppliance.ps1',
        'app/scripts/edge/Register-EdgeServices.ps1',
        'app/scripts/edge/Update-EdgeAppliance.ps1',
        'app/scripts/edge/Uninstall-EdgeAppliance.ps1',
        'app/scripts/edge/Backup-EdgeAppliance.ps1',
        'app/scripts/edge/Restore-EdgeAppliance.ps1',
        'app/scripts/edge/Get-EdgeHealth.ps1',
        'app/scripts/edge/appliance/edge-launcher.php',
        'app/scripts/edge/appliance/appliance.env.template',
        'app/scripts/edge/appliance/mime.types',
    ];

    private readonly PhpRuntimePackager $phpRuntime;

    private readonly MySqlRuntimeBundler $mysqlRuntime;

    private readonly PackageLayoutInspector $layout;

    public function __construct(
        private readonly EdgeArtifactBuilder $artifacts,
        private readonly EdgeUpdatePackageService $updates,
        ?PhpRuntimePackager $phpRuntime = null,
        ?MySqlRuntimeBundler $mysqlRuntime = null,
        ?PackageLayoutInspector $layout = null,
    ) {
        $this->phpRuntime = $phpRuntime ?? new PhpRuntimePackager();
        $this->mysqlRuntime = $mysqlRuntime ?? new MySqlRuntimeBundler();
        $this->layout = $layout ?? new PackageLayoutInspector();
    }

    /**
     * @param array{
     *   source_root:string, artifact_meta:array, php_runtime?:?string, gateway_binary?:?string, signing_key?:?string,
     *   runtime_dirs?:array, ca_bundle?:?string, php_smoke?:bool, mysql_runtime?:?string, mysql_probe?:bool,
     *   strict_layout?:?bool, signing_key_id?:?string
     * } $opts
     *   php_smoke    run the packaged php.exe with its packaged php.ini (default true; false only for fake test runtimes)
     *   mysql_probe  run `mysqld --version` on the bundled MySQL (default true; false only for fake test runtimes)
     *   strict_layout force (true) / waive (false) the 0.9 release layout gates; null = derive from build_mode + version
     */
    public function build(string $dest, array $opts): array
    {
        $dest = rtrim(str_replace('\\', '/', $dest), '/');
        if (is_dir($dest) && (glob($dest . '/*') ?: []) !== []) {
            throw new RuntimeException('PACKAGE_DEST_NOT_EMPTY: ' . $dest);
        }
        if (! is_dir($dest) && ! @mkdir($dest, 0755, true) && ! is_dir($dest)) {
            throw new RuntimeException('PACKAGE_DEST: cannot create ' . $dest);
        }
        try {
            $components = [];
            // 1. app/ — the restricted artifact (its own forbidden scan + physical audit run inside).
            $artifact = $this->artifacts->build($opts['source_root'], $dest . '/app', $opts['artifact_meta'] ?? []);
            $components['app'] = ['manifest_hash' => $artifact['manifest_hash'], 'edge_app_version' => $artifact['edge_app_version'] ?? config('edge.app_version'), 'git_commit' => $artifact['git_commit'] ?? null, 'build_mode' => $artifact['build_mode'] ?? null, 'source_dirty' => $artifact['source_dirty'] ?? null, 'file_count' => (int) ($artifact['file_count'] ?? 0)];
            if (isset($opts['vendor_junction']) && $opts['vendor_junction'] !== '' && ! is_dir($dest . '/app/vendor')) {
                // Test/dev aid: the artifact was built WITHOUT vendor; point the package at a shared vendor closure.
                @exec('cmd /c mklink /J "' . str_replace('/', '\\', $dest . '/app/vendor') . '" "' . $opts['vendor_junction'] . '" 2>&1');
            }
            // 2. php/ — bundled runtime (optional), made PORTABLE: the builder writes php/php.ini + php/cacert.txt itself
            //    and refuses a runtime whose packaged ini would still point at the build host (Khatri 0.8.4 finding).
            $php = (string) ($opts['php_runtime'] ?? '');
            if ($php !== '') {
                $components['php'] = $this->phpRuntime->package($php, $dest . '/php', [
                    'ca_bundle' => $opts['ca_bundle'] ?? null,
                    'smoke' => (bool) ($opts['php_smoke'] ?? true),
                ]);
                $components['php']['version'] ??= $this->phpVersionOf(rtrim($php, "/\\") . DIRECTORY_SEPARATOR . 'php.exe');
            } else {
                $components['php'] = ['bundled' => false, 'note' => 'installer -PhpPath must point at a PHP ' . config('edge.min_php') . '+ runtime with ' . implode(', ', PhpIniValidator::REQUIRED_EXTENSIONS)];
            }
            // 2b. mysql/ — the MySQL 8.0 server runtime (optional, Edge 0.9 wizard installs the BingooEdgeMySQL service from it).
            $mysql = (string) ($opts['mysql_runtime'] ?? '');
            if ($mysql !== '') {
                $components['mysql'] = $this->mysqlRuntime->bundle($mysql, $dest . '/mysql', ['probe' => (bool) ($opts['mysql_probe'] ?? true)]);
            } else {
                $components['mysql'] = ['bundled' => false, 'note' => 'no MySQL runtime bundled — the server needs a local MySQL 8.0 (installer -DbHost/-DbPort)'];
            }
            // 3. gateway/ — nginx binary (optional).
            $gw = (string) ($opts['gateway_binary'] ?? '');
            if ($gw !== '') {
                if (! is_file($gw)) {
                    throw new RuntimeException('PACKAGE_GATEWAY_BINARY_MISSING: ' . $gw);
                }
                @mkdir($dest . '/gateway', 0755, true);
                copy($gw, $dest . '/gateway/nginx.exe');
                $components['gateway'] = ['bundled' => true, 'kind' => 'nginx', 'sha256' => hash_file('sha256', $gw), 'source' => $gw];
            } else {
                $components['gateway'] = ['bundled' => false, 'kind' => 'nginx', 'note' => 'installer -GatewayPath must point at nginx.exe (1.22+)'];
            }
            // 4. scripts/ + templates/ — copied from the artifact's reviewed scripts (operator convenience at top level).
            $this->copyTree($dest . '/app/scripts/edge', $dest . '/scripts', ['appliance']);
            @mkdir($dest . '/templates', 0755, true);
            foreach (['appliance.env.template', 'mime.types', 'README-INSTALL.md', 'edge-launcher.php'] as $t) {
                $src = $dest . '/app/scripts/edge/appliance/' . $t;
                if (is_file($src)) {
                    copy($src, $dest . '/templates/' . $t);
                }
            }
            // 4b. K8 — the wizard launcher at the package ROOT (the wizard itself ships inside app/scripts/edge/wizard/).
            $setup = $dest . '/app/scripts/edge/' . PackageLayoutInspector::SETUP_CMD;
            if (is_file($setup)) {
                copy($setup, $dest . '/' . PackageLayoutInspector::SETUP_CMD);
            }
            $components['scripts'] = ['file_count' => count($this->walk($dest . '/scripts'))];
            // 4c. LAYOUT GATES (Edge 0.9).
            $strict = PackageLayoutInspector::isStrict($components['app']['build_mode'] ?? null, (string) $components['app']['edge_app_version'], $opts['strict_layout'] ?? null);
            $layout = $this->layoutChecks($dest, $strict);
            if ($layout['errors'] !== []) {
                throw new RuntimeException('PACKAGE_LAYOUT_FAILED: ' . implode(' | ', $layout['errors']));
            }
            // 5. update/ — the signed update package for this artifact.
            $key = (string) ($opts['signing_key'] ?? '');
            if ($key !== '') {
                $pkg = $this->updates->build($dest . '/app', $key, (array) ($opts['update_overrides'] ?? []));
                @mkdir($dest . '/update', 0755, true);
                $file = 'edge-update-' . preg_replace('/[^A-Za-z0-9._+-]/', '_', (string) $pkg['payload']['edge_app_version']) . '.json';
                file_put_contents($dest . '/update/' . $file, json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $keyId = $opts['signing_key_id'] ?? null;
                $components['update'] = ['signed' => true, 'signing_key_id' => $keyId, 'signing_key_role' => $keyId === null ? null : ($keyId === self::RELEASE_SIGNING_KEY_ID ? 'release' : 'dev'), 'file' => 'update/' . $file, 'edge_app_version' => $pkg['payload']['edge_app_version'], 'artifact_manifest_hash' => $pkg['payload']['artifact_manifest_hash']];
            } else {
                $components['update'] = ['signed' => false, 'note' => 'no signing key given — release builds must be signed (EDGE_UPDATE_SIGNING_KEY on the build host only)'];
            }
            // 6. BOUNDARY GATE over the whole tree, then the manifest LAST.
            $audit = $this->audit($dest);
            if (! $audit['ok']) {
                throw new RuntimeException('PACKAGE_BOUNDARY_FAILED: ' . json_encode($audit));
            }
            $files = $this->hashTree($dest, ['package-manifest.json']);
            ksort($files);
            $manifest = [
                'package_format_version' => self::FORMAT,
                'product' => 'Bingoo Edge — Branch Server appliance (Windows)',
                'runtime_mode_supported' => 'branch_server',
                'edge_app_version' => (string) $components['app']['edge_app_version'],
                'artifact_manifest_hash' => (string) $components['app']['manifest_hash'],
                'git_commit' => $components['app']['git_commit'],
                'build_mode' => $components['app']['build_mode'],
                'source_dirty' => $components['app']['source_dirty'],
                'built_at' => now()->toIso8601String(),
                'min_php' => (string) config('edge.min_php'),
                'min_db' => (string) config('edge.min_db'),
                'components' => $components,
                'boundary_audit' => $audit,
                'layout_checks' => $layout['checks'],
                'warnings' => $layout['warnings'],
                'file_count' => count($files),
                'package_hash' => hash('sha256', json_encode($files)),
                'files' => $files,
            ];
            file_put_contents($dest . '/package-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $summary = $manifest;
            unset($summary['files']);

            return $summary;
        } catch (\Throwable $e) {
            $this->removeTree($dest); // never leave a half-built package behind
            throw $e;
        }
    }

    /**
     * Verify a package on disk: every listed file present + unchanged, no unlisted file, boundary still clean — and
     * (Edge 0.9) the packaged php.ini still portable, the layout gates, the MySQL runtime entry and, when a public key is
     * given, the Ed25519 signature of the update manifest.
     *
     * @param array{public_key?:?string, require_signature?:bool, strict?:?bool} $opts
     */
    public function verify(string $dir, array $opts = []): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $path = $dir . '/package-manifest.json';
        if (! is_file($path)) {
            return ['ok' => false, 'error' => 'PACKAGE_MANIFEST_MISSING'];
        }
        $manifest = json_decode((string) file_get_contents($path), true);
        if (! is_array($manifest) || ($manifest['package_format_version'] ?? null) !== self::FORMAT || ! is_array($manifest['files'] ?? null)) {
            return ['ok' => false, 'error' => 'PACKAGE_MANIFEST_INVALID'];
        }
        $extended = $this->verifyExtended($dir, $manifest, $opts);
        $listed = $manifest['files'];
        $actual = $this->hashTree($dir, ['package-manifest.json']);
        $missing = array_values(array_diff(array_keys($listed), array_keys($actual)));
        $unlisted = array_values(array_filter(array_diff(array_keys($actual), array_keys($listed)), fn ($rel) => ! str_starts_with($rel, 'app/vendor/')));
        $tampered = [];
        foreach ($listed as $rel => $hash) {
            if (isset($actual[$rel]) && ! hash_equals((string) $hash, (string) $actual[$rel])) {
                $tampered[] = $rel;
            }
        }
        ksort($listed);
        $hashOk = hash_equals((string) ($manifest['package_hash'] ?? ''), hash('sha256', json_encode($listed)));
        $audit = $this->audit($dir);
        $ok = $missing === [] && $unlisted === [] && $tampered === [] && $hashOk && $audit['ok'] && $extended['errors'] === [];

        return [
            'ok' => $ok,
            'edge_app_version' => $manifest['edge_app_version'] ?? null,
            'build_mode' => $manifest['build_mode'] ?? null,
            'artifact_manifest_hash' => $manifest['artifact_manifest_hash'] ?? null,
            'package_hash' => $manifest['package_hash'] ?? null,
            'file_count' => count($listed),
            'missing' => $missing,
            'unlisted' => $unlisted,
            'tampered' => $tampered,
            'package_hash_ok' => $hashOk,
            'boundary_audit' => $audit,
            'strict_layout' => $extended['strict'],
            'php_ini' => $extended['php_ini'],
            'wizard' => $extended['wizard'],
            'mysql_runtime' => $extended['mysql_runtime'],
            'script_compat' => $extended['script_compat'],
            'cloud_url' => $extended['cloud_url'],
            'signature' => $extended['signature'],
            'signature_ok' => $extended['signature']['signature_ok'],
            'signing_key_id' => $manifest['components']['update']['signing_key_id'] ?? null,
            'signing_key_role' => $manifest['components']['update']['signing_key_role'] ?? null,
            'errors' => $extended['errors'],
            'warnings' => $extended['warnings'],
        ];
    }

    /**
     * The Edge 0.9 layout gates over an ASSEMBLED package directory (build time and audit time share them).
     *
     * @return array{checks:array, errors:list<string>, warnings:list<string>}
     */
    private function layoutChecks(string $dir, bool $strict): array
    {
        $wizard = $this->layout->wizard($dir, $strict);
        $compat = $this->layout->scriptCompatibility($dir);
        $cloud = $this->layout->cloudUrl($dir, $strict);
        $errors = [];
        $warnings = [];
        if (! $compat['ok']) {
            $errors[] = 'SCRIPT_WIN10_INCOMPATIBLE: ' . implode(', ', array_merge($compat['timespan_maxvalue'], $compat['long_durations'])) . ' (Windows 10 Task Scheduler rejects [TimeSpan]::MaxValue / durations over ' . PackageLayoutInspector::MAX_TASK_DAYS . ' days)';
        }
        if ($wizard['severity'] === 'error') {
            $errors[] = 'WIZARD_MISSING: ' . $wizard['message'] . ' (required in a ' . PackageLayoutInspector::STRICT_FROM_VERSION . '+ release package)';
        } elseif ($wizard['severity'] === 'warning') {
            $warnings[] = 'WIZARD_ABSENT: ' . $wizard['message'] . ' (dev build: allowed; a ' . PackageLayoutInspector::STRICT_FROM_VERSION . '+ release build refuses)';
        }
        if ($cloud['severity'] === 'error') {
            $errors[] = 'CLOUD_URL_NOT_CENTRAL: ' . $cloud['message'];
        } elseif ($cloud['severity'] === 'warning') {
            $warnings[] = 'CLOUD_URL_NOT_CENTRAL: ' . $cloud['message'] . ' (dev build: allowed; a release build refuses)';
        }

        return ['checks' => ['strict' => $strict, 'wizard' => $wizard, 'script_compat' => $compat, 'cloud_url' => $cloud], 'errors' => $errors, 'warnings' => $warnings];
    }

    /** @return array{strict:bool, php_ini:array, wizard:array, mysql_runtime:array, script_compat:array, cloud_url:array, signature:array, errors:list<string>, warnings:list<string>} */
    private function verifyExtended(string $dir, array $manifest, array $opts): array
    {
        $strict = PackageLayoutInspector::isStrict($manifest['build_mode'] ?? null, (string) ($manifest['edge_app_version'] ?? ''), $opts['strict'] ?? null);
        $layout = $this->layoutChecks($dir, $strict);
        $errors = $layout['errors'];
        $warnings = $layout['warnings'];
        // php.ini — re-validated from the bytes on disk (a package built before 0.9 reports its Laragon paths here).
        $phpBundled = (bool) ($manifest['components']['php']['bundled'] ?? false) || is_file($dir . '/php/php.exe');
        if ($phpBundled) {
            $iniPath = $dir . '/php/php.ini';
            $check = is_file($iniPath) ? (new PhpIniValidator())->validate((string) file_get_contents($iniPath), $dir . '/php')
                : ['ok' => false, 'problems' => ['PHP_INI_MISSING: php/php.ini is not in the package']];
            $caOk = is_file($dir . '/php/' . PhpIniValidator::CA_FILE);
            if (! $caOk) {
                $check['problems'][] = 'CA_BUNDLE_MISSING: php/' . PhpIniValidator::CA_FILE . ' is not in the package';
            }
            $phpIni = ['bundled' => true, 'ok' => $check['ok'] && $caOk, 'problems' => $check['problems'], 'extension_dir' => $check['extension_dir'] ?? null,
                'curl_cainfo' => $check['curl_cainfo'] ?? null, 'openssl_cafile' => $check['openssl_cafile'] ?? null, 'cacert_present' => $caOk];
            if (! $phpIni['ok']) {
                $errors[] = 'PHP_INI_INVALID: ' . implode(' | ', array_slice($phpIni['problems'], 0, 10));
            }
        } else {
            $phpIni = ['bundled' => false, 'ok' => true, 'problems' => []];
        }
        // MySQL runtime — the manifest entry must describe what is on disk.
        $entry = (array) ($manifest['components']['mysql'] ?? []);
        $listedMysql = array_values(array_filter(array_keys((array) $manifest['files']), fn ($rel) => str_starts_with($rel, 'mysql/')));
        if (($entry['bundled'] ?? false) === true || $listedMysql !== []) {
            $problems = [];
            if (($entry['bundled'] ?? false) !== true) {
                $problems[] = 'MYSQL_MANIFEST_ENTRY_MISSING: mysql/ files are listed but components.mysql does not describe them';
            }
            foreach (MySqlRuntimeBundler::REQUIRED as $req) {
                if (! is_file($dir . '/mysql/' . $req)) {
                    $problems[] = 'MYSQL_FILE_MISSING: mysql/' . $req;
                }
            }
            if ((int) ($entry['file_count'] ?? -1) !== count($listedMysql)) {
                $problems[] = 'MYSQL_FILE_COUNT_MISMATCH: manifest entry ' . ($entry['file_count'] ?? '?') . ' vs ' . count($listedMysql) . ' listed';
            }
            $excludedPresent = array_values(array_filter($listedMysql, fn ($rel) => MySqlRuntimeBundler::isExcluded(substr($rel, 6))));
            if ($excludedPresent !== []) {
                $problems[] = 'MYSQL_EXCLUDED_FILES_PRESENT: ' . implode(', ', array_slice($excludedPresent, 0, 10));
            }
            if (isset($entry['mysqld_sha256']) && is_file($dir . '/mysql/bin/mysqld.exe') && ! hash_equals((string) $entry['mysqld_sha256'], (string) hash_file('sha256', $dir . '/mysql/bin/mysqld.exe'))) {
                $problems[] = 'MYSQL_MYSQLD_TAMPERED: mysql/bin/mysqld.exe does not match the manifest entry';
            }
            $mysqlRuntime = ['bundled' => true, 'ok' => $problems === [], 'version' => $entry['version'] ?? null, 'file_count' => count($listedMysql), 'size_bytes' => $entry['size_bytes'] ?? null, 'problems' => $problems];
            if ($problems !== []) {
                $errors[] = 'MYSQL_RUNTIME_INVALID: ' . implode(' | ', $problems);
            }
        } else {
            $mysqlRuntime = ['bundled' => false, 'ok' => true, 'problems' => []];
        }
        // Signature of the update manifest (Ed25519, the appliance's own verification primitive).
        $publicKey = array_key_exists('public_key', $opts) ? $opts['public_key'] : null;
        if (($opts['public_key_source'] ?? 'option') === 'config' && ($manifest['components']['update']['signed'] ?? false) !== true) {
            $publicKey = null; // the ambient configured key never fails an intentionally unsigned dev package; an explicit key does
        }
        $signature = (new UpdateSignatureAudit($this->updates))->audit($dir, $manifest, $publicKey);
        if ($signature['checked'] && ! $signature['ok']) {
            $errors[] = 'SIGNATURE: ' . implode(' | ', $signature['problems']);
        }
        if (! $signature['checked'] && ($opts['require_signature'] ?? false)) {
            $errors[] = 'SIGNATURE_NOT_CHECKED: a public key is required (--public-key) to audit this package';
        }
        if (($manifest['components']['update']['signing_key_role'] ?? null) === 'dev') {
            $warnings[] = 'DEV_SIGNED: the update manifest is signed with a DEV key (' . ($manifest['components']['update']['signing_key_id'] ?? '?') . ') — never install at a live branch';
        }

        return ['strict' => $strict, 'php_ini' => $phpIni, 'wizard' => $layout['checks']['wizard'], 'mysql_runtime' => $mysqlRuntime,
            'script_compat' => $layout['checks']['script_compat'], 'cloud_url' => $layout['checks']['cloud_url'], 'signature' => $signature,
            'errors' => $errors, 'warnings' => $warnings];
    }

    /** The boundary audit: forbidden patterns, Cloud-only sentinels absent, Edge runtime sentinels present. */
    public function audit(string $dir): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $hits = [];
        $patterns = array_merge(self::PACKAGE_FORBIDDEN, (array) config('edge.artifact.forbidden', []));
        $vendorJunction = $this->isJunction($dir . '/app/vendor');
        foreach ($this->walk($dir) as $rel) {
            if ($vendorJunction && str_starts_with($rel, 'app/vendor/')) {
                continue; // shared dev closure — audited by the artifact's own forbidden scan
            }
            // The artifact's own forbidden list applies to app/ (relative to app/); the package list to everything.
            foreach ($patterns as $p) {
                if (@preg_match($p, $rel) === 1 || (str_starts_with($rel, 'app/') && @preg_match($p, substr($rel, 4)) === 1)) {
                    $hits[] = $rel;
                    break;
                }
            }
        }
        $cloudPresent = [];
        foreach (self::CLOUD_ONLY_SENTINELS as $s) {
            if (file_exists($dir . '/app/' . $s)) {
                $cloudPresent[] = $s;
            }
        }
        $edgeMissing = [];
        foreach (self::EDGE_RUNTIME_SENTINELS as $s) {
            if (! file_exists($dir . '/' . $s)) {
                $edgeMissing[] = $s;
            }
        }
        $marker = json_decode((string) @file_get_contents($dir . '/app/edge-build-manifest.json'), true) ?: [];
        $markerOk = ($marker['runtime_mode_supported'] ?? null) === 'branch_server';
        // OWNER DECISION D7 — every tenant migration in the package must be classified (manifest shipped with the package);
        // a CLOUD_ONLY one must not be there at all. Audited against the PACKAGE's own manifest (the runtime that will run them).
        $migrationProblems = [];
        $tenantMigrations = array_values(array_filter(glob($dir . '/app/database/migrations/tenant/*.php') ?: [], 'is_file'));
        if ($tenantMigrations !== []) {
            if (! EdgeTenantMigrationManifest::existsIn($dir . '/app')) {
                $migrationProblems[] = 'the package ships tenant migrations without ' . EdgeTenantMigrationManifest::FILE . ' (RELEASE BLOCKER)';
            } else {
                try {
                    $migrationProblems = EdgeTenantMigrationManifest::load($dir . '/app')
                        ->artifactProblems(array_map(fn ($p) => 'database/migrations/tenant/' . basename($p), $tenantMigrations));
                } catch (\Throwable $e) {
                    $migrationProblems[] = $e->getMessage();
                }
            }
        }

        return [
            'ok' => $hits === [] && $cloudPresent === [] && $edgeMissing === [] && $markerOk && $migrationProblems === [],
            'forbidden_hits' => array_slice(array_values(array_unique($hits)), 0, 50),
            'cloud_only_present' => $cloudPresent,
            'edge_runtime_missing' => $edgeMissing,
            'artifact_marker_branch_server' => $markerOk,
            'tenant_migrations_audited' => count($tenantMigrations),
            'tenant_migration_problems' => array_slice($migrationProblems, 0, 50),
        ];
    }

    // ── filesystem helpers ──────────────────────────────────────────────────

    /** @return array<string,string> rel => sha256 (junction targets are walked as files — a junctioned vendor is hashed too) */
    private function hashTree(string $dir, array $skip = []): array
    {
        $out = [];
        // A dev/test package junctions app/vendor to a shared closure: its files are not part of THIS package's identity
        // (the artifact manifest never lists vendor either); a release package carries a real vendor and hashes it.
        $vendorJunction = $this->isJunction($dir . '/app/vendor');
        foreach ($this->walk($dir) as $rel) {
            if (in_array($rel, $skip, true)) {
                continue;
            }
            if ($vendorJunction && str_starts_with($rel, 'app/vendor/')) {
                continue;
            }
            $out[$rel] = hash_file('sha256', $dir . '/' . $rel);
        }

        return $out;
    }

    /**
     * @return string[] relative paths (forward slashes), sorted. A junctioned app/vendor (dev/test packages only) is
     *                  NOT descended — its tens of thousands of files belong to the shared closure, not to the package.
     */
    private function walk(string $dir): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (! is_dir($dir)) {
            return [];
        }
        $skip = [];
        if ($this->isJunction($dir . '/app/vendor')) {
            $skip[] = $dir . '/app/vendor';
        }
        $out = [];
        $this->walkInto($dir, $dir, $skip, $out);
        sort($out);

        return $out;
    }

    private function walkInto(string $root, string $dir, array $skip, array &$out): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = $dir . '/' . $e;
            if (is_dir($path)) {
                if (in_array($path, $skip, true)) {
                    continue;
                }
                $this->walkInto($root, $path, $skip, $out);
            } elseif (is_file($path)) {
                $out[] = ltrim(substr($path, strlen($root)), '/');
            }
        }
    }

    private function copyTree(string $src, string $dst, array $skipTopLevel = []): int
    {
        $src = rtrim(str_replace('\\', '/', $src), '/');
        $dst = rtrim(str_replace('\\', '/', $dst), '/');
        $n = 0;
        foreach ($this->walk($src) as $rel) {
            $top = explode('/', $rel)[0];
            if (in_array($top, $skipTopLevel, true)) {
                continue;
            }
            $to = $dst . '/' . $rel;
            if (! is_dir(dirname($to))) {
                mkdir(dirname($to), 0755, true);
            }
            copy($src . '/' . $rel, $to);
            $n++;
        }

        return $n;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        // Junctions (vendor) are removed as links, never followed.
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isLink() || ($f->isDir() && ! $f->isLink() && $this->isJunction($f->getPathname()))) {
                @rmdir($f->getPathname());
            } elseif ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function isJunction(string $path): bool
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return false;
        }
        $stat = @lstat($path);
        $real = @realpath($path);

        return $stat !== false && $real !== false && rtrim(str_replace('\\', '/', $real), '/') !== rtrim(str_replace('\\', '/', $path), '/');
    }

    private function phpVersionOf(string $php): ?string
    {
        $out = [];
        @exec('"' . $php . '" -r "echo PHP_VERSION;" 2>&1', $out, $code);

        return $code === 0 ? trim((string) ($out[0] ?? '')) : null;
    }
}
