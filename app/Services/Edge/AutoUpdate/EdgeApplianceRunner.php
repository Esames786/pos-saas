<?php

namespace App\Services\Edge\AutoUpdate;

use App\Support\EdgeApplianceLayout;
use Symfony\Component\Process\Process;

/**
 * EDGE 0.9 (stream B) — run an appliance command in a CHILD process: either through the installed launcher
 * (`php <InstallRoot>\artisan …`, which resolves the CURRENT runtime pointer — i.e. the version just switched to) or through
 * a specific runtime directory's own artisan. Argument arrays only (no shell string, no quoting ambiguity); the appliance env
 * dir is inherited so the child loads the same appliance.env. No secret is ever placed on a command line by this class.
 */
class EdgeApplianceRunner
{
    /** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
    public function artisan(array $args, int $timeoutSeconds = 300, ?string $runtimeDir = null): array
    {
        $installRoot = EdgeAutoUpdateSettings::installRoot();
        if ($runtimeDir !== null) {
            $cmd = array_merge([$this->php(), rtrim($runtimeDir, "/\\") . DIRECTORY_SEPARATOR . 'artisan'], $args);
            $cwd = $runtimeDir;
        } elseif ($installRoot !== null && is_file($installRoot . DIRECTORY_SEPARATOR . 'artisan')) {
            $cmd = array_merge([$this->php(), $installRoot . DIRECTORY_SEPARATOR . 'artisan'], $args);
            $cwd = $installRoot;
        } else {
            $cmd = array_merge([$this->php(), base_path('artisan')], $args); // dev tree
            $cwd = base_path();
        }

        return $this->run($cmd, $timeoutSeconds, $cwd);
    }

    /** @return array{0:int,1:string,2:string} */
    public function run(array $cmd, int $timeoutSeconds = 300, ?string $cwd = null, array $extraEnv = []): array
    {
        $env = $extraEnv;
        $envDir = (string) (getenv(EdgeApplianceLayout::ENV_DIR_VAR) ?: '');
        if ($envDir !== '') {
            $env[EdgeApplianceLayout::ENV_DIR_VAR] = $envDir;
        }
        $process = new Process($cmd, $cwd, $env !== [] ? $env : null, null, $timeoutSeconds);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return [124, $process->getOutput(), 'process failed or timed out: ' . mb_substr($e->getMessage(), 0, 200)];
        }

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    public function php(): string
    {
        $installRoot = EdgeAutoUpdateSettings::installRoot();
        if ($installRoot !== null) {
            $layout = json_decode((string) @file_get_contents($installRoot . DIRECTORY_SEPARATOR . EdgeApplianceLayout::LAYOUT_FILE), true);
            $php = is_array($layout) ? (string) ($layout['php'] ?? '') : '';
            if ($php !== '' && is_file($php)) {
                return $php;
            }
        }

        return PHP_BINARY;
    }

    /** The last line of $out that is a JSON object (artisan commands print human lines around their --json document). */
    public static function lastJson(string $out): ?array
    {
        $doc = json_decode(trim($out), true);
        if (is_array($doc)) {
            return $doc;
        }
        foreach (array_reverse(preg_split('/\r?\n/', trim($out)) ?: []) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{')) {
                $doc = json_decode($line, true);
                if (is_array($doc)) {
                    return $doc;
                }
            }
        }

        return null;
    }
}
