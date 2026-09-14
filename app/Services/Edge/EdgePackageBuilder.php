<?php

namespace App\Services\Edge;

use RuntimeException;

/**
 * P4 §5 — build the restricted WINDOWS APPLIANCE PACKAGE from the accepted artifact, and verify one.
 *
 * Package layout (edge-package-v1):
 *   package-manifest.json      identity + every file's sha256 + the boundary audit result (written LAST)
 *   app/                        the restricted Edge artifact (EdgeArtifactBuilder; includes edge-build-manifest.json)
 *   php/                        the PHP runtime (bundled when a runtime dir is given; otherwise the installer's -PhpPath)
 *   gateway/nginx.exe           the TLS gateway binary (vendored when given; otherwise the installer's -GatewayPath)
 *   scripts/                    the reviewed appliance PowerShell scripts + launcher (copied from app/scripts/edge)
 *   templates/                  appliance.env.template (no values), nginx mime.types, README-INSTALL.md
 *   update/edge-update-<v>.json the SIGNED update package for this same artifact (when a signing key is given)
 *
 * BOUNDARY GATE (mandatory): after assembly the whole tree is walked; any artifact-forbidden pattern (.env, keys,
 * certs, dumps, tests, .git, logs…), any Cloud-only module the artifact excludes, node_modules or a dev scratch file
 * fails the build and the partial package is removed. Production secrets never exist in the package: the env
 * template carries KEYS ONLY and the installer provisions values into <data-root>/config/appliance.env.
 */
class EdgePackageBuilder
{
    public const FORMAT = 'edge-package-v1';

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

    public function __construct(
        private readonly EdgeArtifactBuilder $artifacts,
        private readonly EdgeUpdatePackageService $updates,
    ) {
    }

    /**
     * @param array{
     *   source_root:string, artifact_meta:array, php_runtime?:?string, gateway_binary?:?string, signing_key?:?string,
     *   runtime_dirs?:array
     * } $opts
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
            // 2. php/ — bundled runtime (optional).
            $php = (string) ($opts['php_runtime'] ?? '');
            if ($php !== '') {
                if (! is_file(rtrim($php, "/\\") . DIRECTORY_SEPARATOR . 'php.exe')) {
                    throw new RuntimeException('PACKAGE_PHP_RUNTIME_INVALID: no php.exe under ' . $php);
                }
                $n = $this->copyTree($php, $dest . '/php');
                $components['php'] = ['bundled' => true, 'source' => $php, 'file_count' => $n, 'version' => $this->phpVersionOf(rtrim($php, "/\\") . DIRECTORY_SEPARATOR . 'php.exe')];
            } else {
                $components['php'] = ['bundled' => false, 'note' => 'installer -PhpPath must point at a PHP ' . config('edge.min_php') . '+ runtime with pdo_mysql, openssl, sodium, mbstring, gd'];
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
            $components['scripts'] = ['file_count' => count($this->walk($dest . '/scripts'))];
            // 5. update/ — the signed update package for this artifact.
            $key = (string) ($opts['signing_key'] ?? '');
            if ($key !== '') {
                $pkg = $this->updates->build($dest . '/app', $key, (array) ($opts['update_overrides'] ?? []));
                @mkdir($dest . '/update', 0755, true);
                $file = 'edge-update-' . preg_replace('/[^A-Za-z0-9._+-]/', '_', (string) $pkg['payload']['edge_app_version']) . '.json';
                file_put_contents($dest . '/update/' . $file, json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $components['update'] = ['signed' => true, 'signing_key_id' => ($opts['signing_key_id'] ?? null), 'file' => 'update/' . $file, 'edge_app_version' => $pkg['payload']['edge_app_version'], 'artifact_manifest_hash' => $pkg['payload']['artifact_manifest_hash']];
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

    /** Verify a package on disk: every listed file present + unchanged, no unlisted file, boundary still clean. */
    public function verify(string $dir): array
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
        $ok = $missing === [] && $unlisted === [] && $tampered === [] && $hashOk && $audit['ok'];

        return [
            'ok' => $ok,
            'edge_app_version' => $manifest['edge_app_version'] ?? null,
            'artifact_manifest_hash' => $manifest['artifact_manifest_hash'] ?? null,
            'file_count' => count($listed),
            'missing' => $missing,
            'unlisted' => $unlisted,
            'tampered' => $tampered,
            'package_hash_ok' => $hashOk,
            'boundary_audit' => $audit,
        ];
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

        return [
            'ok' => $hits === [] && $cloudPresent === [] && $edgeMissing === [] && $markerOk,
            'forbidden_hits' => array_slice(array_values(array_unique($hits)), 0, 50),
            'cloud_only_present' => $cloudPresent,
            'edge_runtime_missing' => $edgeMissing,
            'artifact_marker_branch_server' => $markerOk,
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
