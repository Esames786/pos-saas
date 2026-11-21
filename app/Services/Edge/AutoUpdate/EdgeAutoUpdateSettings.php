<?php

namespace App\Services\Edge\AutoUpdate;

use App\Support\EdgeApplianceLayout;

/**
 * EDGE 0.9 (stream B) — where the auto-updater and the wizard reporters find their endpoints and their working directory.
 *
 * Every Cloud endpoint defaults to the central Cloud base URL persisted by `edge:local:pair` (EDGE_CLOUD_BASE_URL) + the
 * contract path; an explicit env URL wins. The work dir lives OUTSIDE the versioned runtime (default <data_root>/updates), so
 * the state, a staged package and a pre-update DB snapshot survive the runtime switch they are about.
 */
final class EdgeAutoUpdateSettings
{
    public const PATH_LATEST = '/api/edge/updates/latest';
    public const PATH_STATUS = '/api/edge/device/status';
    public const PATH_LAN_PROFILE = '/api/edge/device/lan-profile';
    public const PATH_ENROLLMENT_ASSERTIONS = '/api/edge/setup/enrollment-assertions';

    public static function enabled(): bool
    {
        return (bool) config('edge.auto_update.enabled', true);
    }

    public static function cloudBaseUrl(): ?string
    {
        $base = (string) (config('edge.auto_update.cloud_base_url') ?: env('EDGE_CLOUD_BASE_URL', ''));

        return $base !== '' ? rtrim($base, '/') : null;
    }

    /** The configured URL for $key (edge.auto_update.<key>) or the Cloud base URL + the contract path; null when neither exists. */
    public static function endpoint(string $key, string $path): ?string
    {
        $explicit = trim((string) config('edge.auto_update.' . $key, ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $base = self::cloudBaseUrl();

        return $base !== null ? $base . $path : null;
    }

    public static function latestUrl(): ?string
    {
        return self::endpoint('latest_url', self::PATH_LATEST);
    }

    public static function statusUrl(): ?string
    {
        return self::endpoint('status_url', self::PATH_STATUS);
    }

    public static function lanProfileUrl(): ?string
    {
        return self::endpoint('lan_profile_url', self::PATH_LAN_PROFILE);
    }

    public static function enrollmentAssertionsUrl(): ?string
    {
        return self::endpoint('enrollment_assertions_url', self::PATH_ENROLLMENT_ASSERTIONS);
    }

    public static function workDir(): string
    {
        $dir = trim((string) config('edge.auto_update.work_dir', ''));
        if ($dir === '') {
            $root = EdgeApplianceLayout::dataRoot();
            $dir = $root !== null ? $root . DIRECTORY_SEPARATOR . 'updates' : storage_path('app' . DIRECTORY_SEPARATOR . 'edge-auto-update');
        }
        $dir = rtrim($dir, "/\\");
        if (! is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        return $dir;
    }

    /** The installed appliance root (launcher + appliance.json): the launcher exports it; a dev tree has none. */
    public static function installRoot(): ?string
    {
        $root = (string) (getenv('BINGOO_EDGE_INSTALL_ROOT') ?: ($_SERVER['BINGOO_EDGE_INSTALL_ROOT'] ?? ''));
        if ($root === '') {
            $runtime = EdgeApplianceLayout::runtimeRoot();
            if ($runtime !== null && is_file(dirname($runtime) . DIRECTORY_SEPARATOR . 'artisan')) {
                $root = dirname($runtime);
            }
        }

        return $root !== '' ? rtrim($root, "/\\") : null;
    }

    /** Plain HTTP to the Cloud only on an explicitly flagged LAB (the same switch edge:local:pair honours). */
    public static function allowHttpCloud(): bool
    {
        return filter_var(env('EDGE_CLOUD_ALLOW_HTTP', false), FILTER_VALIDATE_BOOL);
    }
}
