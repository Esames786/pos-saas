<?php

namespace App\Support;

use RuntimeException;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section E) — the Branch Server console boundary (DEFAULT DENY).
 *
 * On a branch_server runtime, only commands whose name is on the Edge CLI allowlist
 * (config('edge.cli_allowlist')) may run; every other Artisan command is refused. This is the
 * console analogue of the HTTP route boundary: with a real local database present, a Cloud-only
 * command (tenant provisioning, system:reset, demo reset, master seed, Cloud backup, a raw tenant
 * migrate against the local DB, finance/manufacturing jobs) must never execute on the appliance.
 *
 * On a Cloud runtime this is a pure pass-through (zero behaviour change for the SaaS).
 */
class EdgeConsoleBoundary
{
    /** @return array<int,string> */
    public static function allowlist(): array
    {
        $list = config('edge.cli_allowlist', []);

        return is_array($list) ? array_values(array_filter(array_map('strval', $list))) : [];
    }

    /** Is $command permitted to run on a Branch Server? (Always true on Cloud.) */
    public static function isAllowed(?string $command): bool
    {
        if (! EdgeRuntime::isBranchServer()) {
            return true;
        }
        if ($command === null || $command === '') {
            // A bare `php artisan` (no command) just lists commands — harmless.
            return true;
        }

        foreach (self::allowlist() as $pattern) {
            if ($pattern === $command) {
                return true;
            }
            if (str_ends_with($pattern, '*') && str_starts_with($command, rtrim($pattern, '*'))) {
                return true;
            }
        }

        return false;
    }

    /** Throw a controlled refusal if $command may not run on this Branch Server. */
    public static function assertAllowed(?string $command): void
    {
        if (! self::isAllowed($command)) {
            throw new RuntimeException(
                "Command [{$command}] is not permitted on a Bingoo Edge Branch Server (default-deny CLI boundary). "
                . 'Only the Edge-local operator commands and required framework cache operations may run on the appliance.'
            );
        }
    }

    /**
     * Census helper: given every registered command name, return those that WOULD be denied on a
     * Branch Server. Used by the boundary census test.
     *
     * @param  array<int,string>  $allCommands
     * @return array<int,string>
     */
    public static function deniedFrom(array $allCommands): array
    {
        $denied = [];
        foreach ($allCommands as $name) {
            foreach (self::allowlist() as $pattern) {
                if ($pattern === $name || (str_ends_with($pattern, '*') && str_starts_with($name, rtrim($pattern, '*')))) {
                    continue 2;
                }
            }
            $denied[] = $name;
        }
        sort($denied);

        return $denied;
    }
}
