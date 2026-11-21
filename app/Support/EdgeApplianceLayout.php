<?php

namespace App\Support;

/**
 * P4 WINDOWS APPLIANCE — where the appliance keeps its state OUTSIDE the versioned runtime.
 *
 *   <data_root>/config/appliance.env   the ONLY secrets file (DB credentials, EDGE_LOCAL_APP_KEY, device id/secret,
 *                                      backup recovery key, update/enrollment public keys). ACL: service account + admins.
 *   <data_root>/certs/                 LAN TLS server certificate + key (PEM) for the gateway; the branch CA public cert.
 *   <data_root>/gateway/               rendered nginx.conf + gateway logs/temp.
 *   <data_root>/logs/                  application logs (LOG_CHANNEL=edge → daily files).
 *   <data_root>/backups/               encrypted appliance backups (EDGE_BACKUP_PATH).
 *   <data_root>/runtime/               update install root: versions/<v> + the atomic `current` pointer.
 *
 * The data root is discovered from the environment variable BINGOO_EDGE_ENV_DIR (set by the launcher / the serve
 * command for its children) or from EDGE_DATA_ROOT inside the loaded env; a dev tree has neither and falls back to
 * the Laravel defaults (base_path('.env'), storage/…). Nothing here reads or prints a secret value.
 */
final class EdgeApplianceLayout
{
    public const ENV_DIR_VAR = 'BINGOO_EDGE_ENV_DIR';
    public const ENV_FILE = 'appliance.env';
    public const LAYOUT_FILE = 'appliance.json'; // non-secret, at the install root: data_root + php + gateway paths

    public static function dataRoot(): ?string
    {
        $root = (string) (config('edge.appliance.data_root') ?? '');
        if ($root === '') {
            $envDir = (string) (getenv(self::ENV_DIR_VAR) ?: '');
            if ($envDir !== '') {
                $root = dirname(rtrim($envDir, "/\\"));
            }
        }

        return $root !== '' ? rtrim($root, "/\\") : null;
    }

    /** The env file the running process loaded (or would load): the appliance file when installed, else base .env. */
    public static function envFilePath(): string
    {
        $envDir = (string) (getenv(self::ENV_DIR_VAR) ?: '');
        if ($envDir !== '') {
            return rtrim($envDir, "/\\") . DIRECTORY_SEPARATOR . self::ENV_FILE;
        }
        $root = self::dataRoot();
        if ($root !== null && is_file($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . self::ENV_FILE)) {
            return $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . self::ENV_FILE;
        }

        return base_path('.env');
    }

    public static function certsDir(): ?string
    {
        return self::sub('certs');
    }

    public static function gatewayDir(): ?string
    {
        return self::sub('gateway');
    }

    public static function logsDir(): string
    {
        return self::sub('logs') ?? storage_path('logs');
    }

    public static function backupsDir(): string
    {
        return (string) config('edge.backup.path');
    }

    public static function runtimeRoot(): ?string
    {
        $root = (string) (config('edge.update.install_root') ?? '');
        if ($root !== '') {
            return rtrim($root, "/\\");
        }

        return self::sub('runtime');
    }

    /** Non-secret description of the layout for the health report (paths only, never contents). */
    public static function describe(): array
    {
        return [
            'data_root' => self::dataRoot(),
            'env_file' => self::envFilePath(),
            'env_file_present' => is_file(self::envFilePath()),
            'certs_dir' => self::certsDir(),
            'gateway_dir' => self::gatewayDir(),
            'logs_dir' => self::logsDir(),
            'backups_dir' => self::backupsDir(),
            'runtime_root' => self::runtimeRoot(),
            'app_root' => base_path(),
        ];
    }

    private static function sub(string $name): ?string
    {
        $root = self::dataRoot();

        return $root === null ? null : $root . DIRECTORY_SEPARATOR . $name;
    }
}
