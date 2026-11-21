<?php

namespace App\Services\Edge\AutoUpdate;

use RuntimeException;

/**
 * EDGE 0.9 (stream B) — download + extract a release into the work dir, OUTSIDE the runtime:
 *
 *   <work>/downloads/<version>.zip            the streamed zip (temp .part first; size-capped; size checked when advertised)
 *   <work>/staging/<version>-<manifest8>/     the extracted package (the dir holding package-manifest.json is the root)
 *
 * Extraction refuses absolute paths, drive letters and `..` segments (zip-slip) and never follows links. Uses ZipArchive when
 * the PHP runtime has it, otherwise Windows PowerShell's Expand-Archive (PS 5.1) — both followed by the same path check.
 * Nothing here trusts the bytes: EdgeAutoUpdatePackageVerifier decides.
 */
class EdgeAutoUpdateStager
{
    public function __construct(private readonly EdgeDeviceCloudClient $client)
    {
    }

    /** Download (or reuse an identical earlier download) and extract; returns the package root directory. */
    public function stage(array $release, string $workDir): string
    {
        $version = (string) ($release['version'] ?? '');
        $sha = strtolower((string) ($release['manifest_sha256'] ?? ''));
        $url = (string) ($release['download_url'] ?? '');
        if ($version === '' || $sha === '' || $url === '') {
            throw new RuntimeException('AUTO_UPDATE_RELEASE_INCOMPLETE: version / manifest_sha256 / download_url missing.');
        }
        $safe = preg_replace('/[^A-Za-z0-9._+-]/', '_', $version);
        $stagingDir = $workDir . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . $safe . '-' . substr($sha, 0, 8);
        $existing = $this->packageRoot($stagingDir);
        if ($existing !== null && is_file($existing . DIRECTORY_SEPARATOR . 'package-manifest.json')
            && hash_equals($sha, (string) hash_file('sha256', $existing . DIRECTORY_SEPARATOR . 'package-manifest.json'))) {
            return $existing; // the verifier re-checks every byte anyway
        }
        $this->removeTree($stagingDir);
        $downloads = $workDir . DIRECTORY_SEPARATOR . 'downloads';
        if (! is_dir($downloads)) {
            @mkdir($downloads, 0770, true);
        }
        $zip = $downloads . DIRECTORY_SEPARATOR . $safe . '.zip';
        $this->client->download($url, $zip, (int) config('edge.auto_update.max_download_bytes', 2147483647), isset($release['size_bytes']) ? (int) $release['size_bytes'] : null);
        try {
            $this->extract($zip, $stagingDir);
        } finally {
            @unlink($zip); // the extracted tree is what gets verified; never keep two copies of a large release
        }
        $root = $this->packageRoot($stagingDir);
        if ($root === null) {
            $this->removeTree($stagingDir);
            throw new RuntimeException('AUTO_UPDATE_NOT_A_PACKAGE: the downloaded zip holds no package-manifest.json.');
        }

        return $root;
    }

    public function extract(string $zipFile, string $dest): void
    {
        if (! is_dir($dest) && ! @mkdir($dest, 0770, true) && ! is_dir($dest)) {
            throw new RuntimeException('AUTO_UPDATE_STAGE_DIR: cannot create ' . $dest);
        }
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new RuntimeException('AUTO_UPDATE_BAD_ZIP: the download is not a readable zip.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (! self::safeEntry($name)) {
                    $zip->close();
                    throw new RuntimeException('AUTO_UPDATE_BAD_ZIP: unsafe entry path [' . mb_substr($name, 0, 120) . '].');
                }
            }
            $ok = $zip->extractTo($dest);
            $zip->close();
            if (! $ok) {
                throw new RuntimeException('AUTO_UPDATE_BAD_ZIP: extraction failed.');
            }
        } elseif (DIRECTORY_SEPARATOR === '\\') {
            // PowerShell 5.1 Expand-Archive; argument array (no shell string), paths passed through env, not interpolated.
            $script = '$ErrorActionPreference="Stop"; Expand-Archive -LiteralPath $env:BINGOO_ZIP -DestinationPath $env:BINGOO_DEST -Force';
            $env = array_merge(getenv() ?: [], ['BINGOO_ZIP' => $zipFile, 'BINGOO_DEST' => $dest]);
            [$code, $out] = $this->run(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script], $env);
            if ($code !== 0) {
                throw new RuntimeException('AUTO_UPDATE_BAD_ZIP: Expand-Archive failed: ' . mb_substr($out, 0, 200));
            }
        } else {
            throw new RuntimeException('AUTO_UPDATE_NO_UNZIP: no zip support in this PHP runtime.');
        }
        // Post-check whatever extracted it: nothing may sit outside $dest, and no link/junction may have been created.
        $realDest = realpath($dest);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dest, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('AUTO_UPDATE_BAD_ZIP: the package contains a link (' . $item->getFilename() . ').');
            }
            $real = realpath($item->getPathname());
            if ($real === false || $realDest === false || ! str_starts_with(strtolower($real), strtolower($realDest))) {
                throw new RuntimeException('AUTO_UPDATE_BAD_ZIP: an extracted path escapes the staging directory.');
            }
        }
    }

    /** Keep the newest $keep staged packages (plus $keepDir, the one in use). */
    public function prune(string $workDir, ?string $keepDir, int $keep): void
    {
        $staging = $workDir . DIRECTORY_SEPARATOR . 'staging';
        if (! is_dir($staging)) {
            return;
        }
        $dirs = array_filter(glob($staging . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [], 'is_dir');
        usort($dirs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $keepReal = $keepDir !== null ? strtolower((string) realpath($keepDir)) : null;
        foreach (array_slice($dirs, max(1, $keep)) as $d) {
            if ($keepReal !== null && str_starts_with($keepReal, strtolower((string) realpath($d)))) {
                continue;
            }
            $this->removeTree($d);
        }
    }

    public static function safeEntry(string $name): bool
    {
        $n = str_replace('\\', '/', $name);
        if ($n === '' || str_starts_with($n, '/') || preg_match('#^[A-Za-z]:#', $n) || str_contains($n, "\0")) {
            return false;
        }
        foreach (explode('/', $n) as $seg) {
            if ($seg === '..') {
                return false;
            }
        }

        return true;
    }

    private function packageRoot(string $stagingDir): ?string
    {
        if (! is_dir($stagingDir)) {
            return null;
        }
        if (is_file($stagingDir . DIRECTORY_SEPARATOR . 'package-manifest.json')) {
            return $stagingDir;
        }
        foreach (glob($stagingDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $d) {
            if (is_file($d . DIRECTORY_SEPARATOR . 'package-manifest.json')) {
                return $d;
            }
        }

        return null;
    }

    public function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            if ($item->isLink()) {
                @unlink($item->getPathname()) || @rmdir($item->getPathname());
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @chmod($item->getPathname(), 0666);
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    /** @return array{0:int,1:string} */
    private function run(array $cmd, array $env): array
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (! is_resource($proc)) {
            return [1, 'could not start ' . $cmd[0]];
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out];
    }
}
