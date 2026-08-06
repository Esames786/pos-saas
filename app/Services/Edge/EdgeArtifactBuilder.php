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
            'sync_protocol'           => (string) config('edge.sync_protocol'),
            'min_php'                 => (string) config('edge.min_php'),
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

        $manifest = $this->manifest($dest, $plan, $meta);
        file_put_contents($dest . '/edge-build-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Re-audit the physical output as a belt-and-suspenders check.
        $writtenForbidden = $this->forbidden($this->plan($dest));
        if ($writtenForbidden !== []) {
            throw new RuntimeException('Edge artifact audit FAILED after copy: ' . implode(', ', $writtenForbidden));
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
