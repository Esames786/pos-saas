<?php

namespace App\Services\Edge\AutoUpdate;

use RuntimeException;

/**
 * EDGE 0.9 (stream B) — the auto-updater's durable, NON-SECRET state, kept as files in the work dir (never in the DB:
 * a schema rollback restores the database to its pre-update snapshot, and the record of that rollback must survive it).
 *
 *   auto-update-state.json   last check, the advertised release, the staged package, the last result, a short history
 *   update-now.request.json  an explicit "Update now" (status page / Cloud flag / CLI): who, when, from where
 *   auto-update.lock         single-instance lock (flock) — a second updater run exits at once
 *
 * Writes are atomic (temp file + rename). Nothing here ever holds a password, token, key or device secret.
 */
class EdgeAutoUpdateStateStore
{
    public const STATE_FILE = 'auto-update-state.json';
    public const REQUEST_FILE = 'update-now.request.json';
    public const LOCK_FILE = 'auto-update.lock';
    private const HISTORY = 20;

    /** @var resource|null */
    private $lock = null;

    public function __construct(private readonly ?string $dir = null)
    {
    }

    public function dir(): string
    {
        $dir = $this->dir ?? EdgeAutoUpdateSettings::workDir();
        if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            throw new RuntimeException('AUTO_UPDATE_WORK_DIR: cannot create ' . $dir);
        }

        return rtrim($dir, "/\\");
    }

    public function read(): array
    {
        return $this->readJson($this->dir() . DIRECTORY_SEPARATOR . self::STATE_FILE) ?? [];
    }

    /** Merge $patch into the state (top-level keys) and persist atomically; returns the new state. */
    public function merge(array $patch): array
    {
        $state = array_merge($this->read(), $patch);
        $this->writeJson($this->dir() . DIRECTORY_SEPARATOR . self::STATE_FILE, $state);

        return $state;
    }

    /** Record a terminal/notable event as last_result and in the bounded history. */
    public function recordResult(array $result): array
    {
        $result['at'] = $result['at'] ?? now()->toIso8601String();
        $state = $this->read();
        $history = array_values((array) ($state['history'] ?? []));
        array_unshift($history, $result);
        $state['history'] = array_slice($history, 0, self::HISTORY);
        $state['last_result'] = $result;
        $this->writeJson($this->dir() . DIRECTORY_SEPARATOR . self::STATE_FILE, $state);

        return $state;
    }

    public function requestUpdateNow(string $by, string $source): array
    {
        $request = ['requested_by' => mb_substr($by, 0, 191), 'source' => $source, 'requested_at' => now()->toIso8601String()];
        $this->writeJson($this->dir() . DIRECTORY_SEPARATOR . self::REQUEST_FILE, $request);

        return $request;
    }

    /** The pending "Update now" request, or null when there is none or it is older than the TTL (then it is dropped). */
    public function pendingUpdateNow(?int $ttlMinutes = null): ?array
    {
        $file = $this->dir() . DIRECTORY_SEPARATOR . self::REQUEST_FILE;
        $request = $this->readJson($file);
        if ($request === null) {
            return null;
        }
        $ttl = $ttlMinutes ?? (int) config('edge.auto_update.update_now_ttl_minutes', 240);
        try {
            $at = \Carbon\CarbonImmutable::parse((string) ($request['requested_at'] ?? ''));
        } catch (\Throwable) {
            @unlink($file);

            return null;
        }
        if ($at->addMinutes($ttl)->isPast()) {
            @unlink($file);

            return null;
        }

        return $request;
    }

    public function clearUpdateNow(): void
    {
        @unlink($this->dir() . DIRECTORY_SEPARATOR . self::REQUEST_FILE);
    }

    /** Non-blocking single-instance lock; false when another updater run holds it. */
    public function acquireLock(): bool
    {
        if ($this->lock !== null) {
            return true;
        }
        $handle = @fopen($this->dir() . DIRECTORY_SEPARATOR . self::LOCK_FILE, 'c');
        if ($handle === false) {
            throw new RuntimeException('AUTO_UPDATE_LOCK: cannot open the lock file in ' . $this->dir());
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        $this->lock = $handle;

        return true;
    }

    public function releaseLock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    private function readJson(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    private function writeJson(string $file, array $data): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new RuntimeException('AUTO_UPDATE_STATE_WRITE: cannot write ' . $file);
        }
        if (! @rename($tmp, $file)) {
            // Windows: rename over an existing file can fail while a reader holds it — retry once after removing the target.
            @unlink($file);
            if (! @rename($tmp, $file)) {
                @unlink($tmp);
                throw new RuntimeException('AUTO_UPDATE_STATE_WRITE: cannot replace ' . $file);
            }
        }
    }
}
