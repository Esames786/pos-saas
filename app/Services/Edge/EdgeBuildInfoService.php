<?php

namespace App\Services\Edge;

use App\Support\EdgeRuntime;

/**
 * EDGE-RUNTIME-BOUNDARY-1 — the single place that answers "what build am I, and what can I consume".
 *
 * The future signed updater and the health endpoint both read from here. It exposes ONLY non-secret
 * version/compatibility facts. When running from a packaged artifact it reads the shipped
 * edge-build-manifest.json (git commit / build timestamp / artifact version); in a dev/cloud tree it
 * falls back to config + 'unknown' so the endpoint never leaks or crashes.
 */
class EdgeBuildInfoService
{
    /** Non-secret build/runtime facts. Safe to expose on the health endpoint. */
    public function info(): array
    {
        $manifest = $this->packagedManifest();

        return [
            'product'                 => 'Bingoo POS Edge',
            'runtime_mode'            => EdgeRuntime::mode(),
            'edge_app_version'        => (string) config('edge.app_version'),
            'artifact_format_version' => (string) config('edge.artifact_format_version'),
            'artifact_version'        => $manifest['artifact_version'] ?? null,
            'git_commit'              => $manifest['git_commit'] ?? (string) config('edge.git_commit', 'unknown'),
            'build_timestamp'         => $manifest['build_timestamp'] ?? null,
            'bootstrap_schema'        => (string) config('edge.bootstrap_schema'),
            'config_schema'           => (string) config('edge.config_schema'),
            'edge_schema_version'     => $this->edgeSchemaVersion(),
            'capabilities'            => array_values((array) config('edge.capabilities', [])),
            'sync_protocol'           => (string) config('edge.sync_protocol'),
            'min_php'                 => (string) config('edge.min_php'),
            'php_version'             => PHP_VERSION,
        ];
    }

    /**
     * EDGE-COMPATIBILITY-CONTRACT-1 — the Edge-local DDL generation this build ships, derived from the
     * newest migration in database/migrations/edge (grounded and self-maintaining: adding an edge
     * migration IS the schema bump; no manually-updated constant to drift).
     */
    public function edgeSchemaVersion(): string
    {
        $names = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/edge/*.php')) ?: []);
        sort($names);

        return $names !== [] ? 'edge-local-schema@' . end($names) : 'edge-local-schema@none';
    }

    /** Can this build consume a given bootstrap-snapshot schema? (exact match this sprint). */
    public function supportsBootstrapSchema(string $schema): bool
    {
        return $schema !== '' && $schema === (string) config('edge.bootstrap_schema');
    }

    /** Can this build speak a given sync protocol? (placeholder — sync is not built yet). */
    public function supportsSyncProtocol(string $protocol): bool
    {
        return $protocol !== '' && $protocol === (string) config('edge.sync_protocol');
    }

    /** The packaged build manifest, if this instance is running from a built artifact. */
    private function packagedManifest(): array
    {
        $path = base_path('edge-build-manifest.json');

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
