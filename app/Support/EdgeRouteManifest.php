<?php

namespace App\Support;

/**
 * EDGE-RUNTIME-BOUNDARY-1 — the single machine-readable manifest of which route names may execute in
 * branch_server runtime. Default is DENY: a name must explicitly match an allowlist pattern. Kept
 * tiny on purpose this sprint (only the Edge health/build surface exists).
 */
class EdgeRouteManifest
{
    /** Allowlisted route-name patterns for branch_server (config-driven; '*' = suffix wildcard). */
    public static function patterns(): array
    {
        return (array) config('edge.route_allowlist', []);
    }

    /** True only if the route name explicitly matches an allowlist pattern. Null name => denied. */
    public static function isAllowed(?string $routeName): bool
    {
        if ($routeName === null || $routeName === '') {
            return false;
        }

        foreach (self::patterns() as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($routeName, substr($pattern, 0, -1))) {
                    return true;
                }
            } elseif ($routeName === $pattern) {
                return true;
            }
        }

        return false;
    }
}
