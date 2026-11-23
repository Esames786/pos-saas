<?php

namespace App\Services\Edge\AutoUpdate;

/**
 * EDGE 0.9 (stream B) — "is the appliance healthy on the version it was just switched to?"
 *
 * Runs `edge:local:health --json` in a CHILD process through the launcher (so it boots the runtime the `current` pointer
 * names), and compares the report with the one taken before the update. Healthy =
 *   - the report parses, the runtime reports the target version, no runtime boot problem;
 *   - the local DB is reachable and the appliance is still bound (never NOT_BOUND);
 *   - no NEW problem appeared compared with the pre-update report, ignoring printer reachability lines (printers are often
 *     switched off at night, inside the maintenance window) — a pre-existing problem never blocks an update by itself.
 * Polls until healthy or the deadline (web backends need a few seconds to listen after the restart).
 */
class EdgeAutoUpdateHealthProbe
{
    /** Problem lines that never fail an update (they describe the LAN, not the software). */
    private const IGNORABLE = ['/printer/i', '/print job/i'];

    public function __construct(private readonly EdgeApplianceRunner $runner)
    {
    }

    /** The current health report (child process), or ['error' => …]. */
    public function probe(): array
    {
        [$code, $out, $err] = $this->runner->artisan(['edge:local:health', '--json', '--no-interaction'], 120);
        $doc = EdgeApplianceRunner::lastJson($out);
        if (! is_array($doc) || ! isset($doc['status'])) {
            return ['error' => 'health report unavailable (exit ' . $code . '): ' . mb_substr(trim($err !== '' ? $err : $out), 0, 300)];
        }

        return $doc;
    }

    /** @return array{ok:bool,reasons:array<int,string>} */
    public function evaluate(array $before, array $after, string $expectedVersion): array
    {
        $reasons = [];
        if (isset($after['error'])) {
            return ['ok' => false, 'reasons' => [(string) $after['error']]];
        }
        $version = (string) ($after['runtime']['edge_app_version'] ?? '');
        if ($version !== $expectedVersion) {
            $reasons[] = "the runtime reports version [{$version}], expected [{$expectedVersion}]";
        }
        foreach ((array) ($after['runtime']['boot_problems'] ?? []) as $p) {
            $reasons[] = 'boot: ' . $p;
        }
        if (! ($after['database']['reachable'] ?? false)) {
            $reasons[] = 'the local database is unreachable';
        }
        if (($after['status'] ?? '') === 'NOT_BOUND') {
            $reasons[] = 'the appliance reports NOT_BOUND';
        }
        $old = array_map('strval', (array) ($before['problems'] ?? []));
        foreach ((array) ($after['problems'] ?? []) as $p) {
            $p = (string) $p;
            if (in_array($p, $old, true) || $this->ignorable($p)) {
                continue;
            }
            $reasons[] = 'new problem: ' . $p;
        }

        return ['ok' => $reasons === [], 'reasons' => $reasons];
    }

    /** @return array{ok:bool,reasons:array<int,string>,report:array} */
    public function waitHealthy(array $before, string $expectedVersion, int $timeoutSeconds): array
    {
        $deadline = time() + max(5, $timeoutSeconds);
        $last = ['ok' => false, 'reasons' => ['not probed'], 'report' => []];
        do {
            $report = $this->probe();
            $verdict = $this->evaluate($before, $report, $expectedVersion);
            $last = $verdict + ['report' => $report];
            if ($verdict['ok']) {
                return $last;
            }
            $this->pause(10);
        } while (time() < $deadline);

        return $last;
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    private function ignorable(string $problem): bool
    {
        foreach (self::IGNORABLE as $re) {
            if (preg_match($re, $problem)) {
                return true;
            }
        }

        return false;
    }
}
