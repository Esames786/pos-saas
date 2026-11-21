<?php

namespace App\Support;

use RuntimeException;

/**
 * P4 — atomic, minimal editor for the appliance env file (KEY=value lines). Used by first-boot commands that must
 * PERSIST a provisioned secret (device secret after pairing, keys) without ever printing it or passing it on a
 * command line. Values are quoted when they contain whitespace/# and written via temp-file + rename. It never
 * logs values, and never rewrites keys it was not asked to set (comments and order are preserved).
 */
final class EdgeApplianceEnvFile
{
    public static function set(string $path, array $values): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('ENV_FILE_DIR: cannot create ' . $dir);
        }
        $lines = is_file($path) ? preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) : [];
        $seen = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=/', $line, $m) && array_key_exists($m[1], $values)) {
                $lines[$i] = $m[1] . '=' . self::quote((string) $values[$m[1]]);
                $seen[$m[1]] = true;
            }
        }
        foreach ($values as $k => $v) {
            if (! isset($seen[$k])) {
                if (! preg_match('/^[A-Z0-9_]+$/', (string) $k)) {
                    throw new RuntimeException('ENV_FILE_KEY: invalid key ' . $k);
                }
                $lines[] = $k . '=' . self::quote((string) $v);
            }
        }
        // Drop a trailing empty line duplication, keep a single newline at EOF.
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('ENV_FILE_WRITE: cannot write ' . $tmp);
        }
        @chmod($tmp, 0600);
        if (! @rename($tmp, $path)) {
            // Windows: rename over an existing file can fail if it is open — fall back to copy+unlink.
            if (! @copy($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('ENV_FILE_REPLACE: cannot replace ' . $path);
            }
            @unlink($tmp);
        }
    }

    /** Read one key (for non-secret keys only — callers must never echo a secret). */
    public static function get(string $path, string $key): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) as $line) {
            if (preg_match('/^\s*(?:export\s+)?' . preg_quote($key, '/') . '\s*=(.*)$/', $line, $m)) {
                return self::unquote(trim($m[1]));
            }
        }

        return null;
    }

    private static function quote(string $v): string
    {
        if ($v === '' || preg_match('/[\s#"\'\\\\]/', $v)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
        }

        return $v;
    }

    private static function unquote(string $v): string
    {
        if (strlen($v) >= 2 && $v[0] === '"' && str_ends_with($v, '"')) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($v, 1, -1));
        }

        return $v;
    }
}
