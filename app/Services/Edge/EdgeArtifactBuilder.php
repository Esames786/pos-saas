<?php

namespace App\Services\Edge;

use RuntimeException;

/**
 * EDGE-RUNTIME-BOUNDARY-1 (G/H/I/O) — deterministic restricted artifact builder.
 *
 * Ships ONLY an explicit allowlist of paths, prunes an exclude list, and FAILS the build if any
 * forbidden pattern (secrets / VCS / dumps / dev files) survives the plan. Produces a machine-
 * readable build manifest with per-file SHA-256 integrity hashes — the input to the future signed
 * update channel. It never reads or logs secret CONTENTS; the audit reports paths only.
 *
 * Logic (plan / audit / manifest) is separated from the physical copy so it can be tested against a
 * tiny synthetic tree without copying the whole vendor closure.
 */
class EdgeArtifactBuilder
{
    /** @param array{include:array,exclude:array,forbidden:array} $config */
    public function __construct(private array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('edge.artifact'));
    }

    /** Relative file paths (POSIX slashes) the artifact would contain, deterministic (sorted). */
    public function plan(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $files = [];

        foreach ((array) ($this->config['include'] ?? []) as $entry) {
            $abs = $root . '/' . $entry;
            if (is_file($abs)) {
                if (! $this->isExcluded($entry)) {
                    $files[$entry] = true;
                }
                continue;
            }
            if (is_dir($abs)) {
                foreach ($this->walk($abs) as $absFile) {
                    $rel = ltrim(substr(str_replace('\\', '/', $absFile), strlen($root) + 1), '/');
                    if (! $this->isExcluded($rel)) {
                        $files[$rel] = true;
                    }
                }
            }
        }

        $list = array_keys($files);
        sort($list);

        return $list;
    }

    /** Forbidden paths present in a plan (empty = clean). Paths only, never contents. */
    public function forbidden(array $relPaths): array
    {
        $hits = [];
        foreach ($relPaths as $rel) {
            foreach ((array) ($this->config['forbidden'] ?? []) as $pattern) {
                if (preg_match($pattern, $rel)) {
                    $hits[] = $rel;
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * PHYSICAL audit — recursively scan EVERY file/dir under $dir for forbidden patterns, INDEPENDENT
     * of the include allowlist. This is the authoritative audit of a BUILT artifact: a stale file that
     * was never in the plan (e.g. a `.env` left in the destination) is still caught here, whereas the
     * plan-based forbidden() would never see it. Paths only, never contents.
     *
     * @return array<int,string> sorted relative paths that are forbidden (empty = clean)
     */
    public function physicalForbidden(string $dir): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (! is_dir($dir)) {
            return [];
        }

        $patterns = (array) ($this->config['forbidden'] ?? []);
        $hits = [];
        foreach ($this->walk($dir) as $abs) {
            $rel = ltrim(substr(str_replace('\\', '/', $abs), strlen($dir) + 1), '/');
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $rel)) {
                    $hits[] = $rel;
                    break;
                }
            }
        }
        sort($hits);

        return $hits;
    }

    /** Build the manifest (per-file SHA-256 + a manifest hash over the sorted map). */
    public function manifest(string $root, array $relPaths, array $meta = []): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $fileHashes = [];
        foreach ($relPaths as $rel) {
            $abs = $root . '/' . $rel;
            if (is_file($abs)) {
                $fileHashes[$rel] = hash_file('sha256', $abs);
            }
        }
        ksort($fileHashes);

        $manifestHash = hash('sha256', json_encode($fileHashes, JSON_UNESCAPED_SLASHES));

        return array_merge([
            'product'                 => 'Bingoo POS Edge',
            'edge_app_version'        => (string) config('edge.app_version'),
            'artifact_format_version' => (string) config('edge.artifact_format_version'),
            'bootstrap_schema'        => (string) config('edge.bootstrap_schema'),
            'config_schema'           => (string) config('edge.config_schema'),
            'sync_protocol'           => (string) config('edge.sync_protocol'),
            'min_php'                 => (string) config('edge.min_php'),
            'min_db'                  => (string) config('edge.min_db'),
            'capabilities'            => array_values((array) config('edge.capabilities', [])),
            'runtime_mode_supported'  => 'branch_server',
            'file_count'              => count($fileHashes),
            'manifest_hash'           => $manifestHash,
            'files'                   => $fileHashes,
        ], $meta);
    }

    /**
     * Physically build the artifact into $dest. Plans, AUDITS (throws on any forbidden path — the
     * build never produces a leaking artifact), copies, then writes edge-build-manifest.json.
     *
     * @return array the manifest (without the huge files map echoed to callers)
     */
    public function build(string $root, string $dest, array $meta = []): array
    {
        $plan = $this->plan($root);

        $forbidden = $this->forbidden($plan);
        if ($forbidden !== []) {
            throw new RuntimeException(
                'Edge artifact build REFUSED — forbidden files in plan: ' . implode(', ', array_slice($forbidden, 0, 20))
                . (count($forbidden) > 20 ? ' …(+' . (count($forbidden) - 20) . ' more)' : '')
            );
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        $dest = rtrim(str_replace('\\', '/', $dest), '/');
        foreach ($plan as $rel) {
            $src = $root . '/' . $rel;
            $out = $dest . '/' . $rel;
            if (! is_file($src)) {
                continue;
            }
            $dir = dirname($out);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            copy($src, $out);
        }

        // Create EMPTY writable runtime directories the appliance needs to boot (a file-copy omits
        // empty dirs). Never copies dev logs/cache — these are created fresh and empty.
        foreach ((array) ($this->config['runtime_dirs'] ?? []) as $runtimeDir) {
            $abs = $dest . '/' . $runtimeDir;
            if (! is_dir($abs)) {
                mkdir($abs, 0755, true);
            }
            // .gitkeep so the empty dir is real on disk / survives packaging.
            @file_put_contents($abs . '/.gitkeep', '');
        }

        // Integrity manifest LAST — after every file + runtime dir is finalized, nothing mutates the
        // artifact afterwards. (Its own edge-build-manifest.json is written after and is not a hashed
        // source file.)
        $manifest = $this->manifest($dest, $plan, $meta);
        file_put_contents($dest . '/edge-build-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Authoritative PHYSICAL re-audit: walk the ENTIRE built tree (not just the plan) so any
        // stray forbidden/secret file that slipped into the destination fails the build.
        $writtenForbidden = $this->physicalForbidden($dest);
        if ($writtenForbidden !== []) {
            throw new RuntimeException('Edge artifact PHYSICAL audit FAILED after build: '
                . implode(', ', array_slice($writtenForbidden, 0, 20)));
        }

        $summary = $manifest;
        unset($summary['files']);

        return $summary;
    }

    private function isExcluded(string $rel): bool
    {
        foreach ((array) ($this->config['exclude'] ?? []) as $pat) {
            if (fnmatch($pat, $rel)) {
                return true;
            }
            $dir = rtrim($pat, '/*');
            if ($dir !== '' && ($rel === $dir || str_starts_with($rel, $dir . '/'))) {
                return true;
            }
            if (str_contains($pat, '*') && fnmatch($pat, basename($rel))) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<string> absolute file paths under $dir */
    private function walk(string $dir): iterable
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }
}
