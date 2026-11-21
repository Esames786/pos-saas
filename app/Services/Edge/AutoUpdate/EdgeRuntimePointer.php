<?php

namespace App\Services\Edge\AutoUpdate;

use RuntimeException;

/**
 * EDGE 0.9 (stream B) — the atomic `current` runtime pointer (the same file the launcher reads and EdgeUpdateInstaller
 * switches): read it, and switch it BACK on a failed post-update health check (temp file + rename, never a partial write).
 */
final class EdgeRuntimePointer
{
    public static function root(): string
    {
        $root = rtrim((string) config('edge.update.install_root', ''), "/\\");
        if ($root === '') {
            throw new RuntimeException('UPDATE_NO_INSTALL_ROOT: edge.update.install_root is not configured.');
        }

        return $root;
    }

    public static function current(string $root): ?string
    {
        $file = $root . DIRECTORY_SEPARATOR . 'current';
        if (! is_file($file)) {
            return null;
        }
        $v = trim((string) file_get_contents($file));

        return $v !== '' ? $v : null;
    }

    public static function versionDir(string $root, string $version): string
    {
        return $root . DIRECTORY_SEPARATOR . 'versions' . DIRECTORY_SEPARATOR . self::safe($version);
    }

    public static function switchTo(string $root, string $version): void
    {
        $safe = self::safe($version);
        if (! is_dir(self::versionDir($root, $safe))) {
            throw new RuntimeException("UPDATE_SWITCH_FAILED: runtime version [{$safe}] is not installed.");
        }
        $file = $root . DIRECTORY_SEPARATOR . 'current';
        $tmp = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        file_put_contents($tmp, $safe);
        if (! @rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('UPDATE_SWITCH_FAILED: could not atomically switch the active version.');
        }
    }

    public static function safe(string $version): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._+-]/', '_', $version);
    }
}
