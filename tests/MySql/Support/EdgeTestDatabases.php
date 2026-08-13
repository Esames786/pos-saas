<?php

namespace Tests\MySql\Support;

use App\Support\EdgeLocalDatabase;
use RuntimeException;

/**
 * PLATFORM TEST-ISOLATION — the ONE resolver for the Edge-LOCAL test database name.
 *
 * Parallel worktrees (Edge, Catering, …) each run the authoritative MySQL suite at the same time.
 * The master/tenant test DBs are already env-driven (DB_DATABASE / EDGE_TEST_TENANT_DB); the
 * Edge-local appliance DB the import/auth/db-init/refresh tests DROP + recreate was a shared
 * hard-coded literal — one worktree could silently destroy the other's DB mid-run. Every test that
 * provisions an Edge-local DB must take its name from HERE, and every subprocess worker must be
 * handed the resolved name explicitly (edge_enroll_worker/edge_login_worker already read
 * EDGE_TEST_LOCAL_DB from their environment).
 *
 * Contract:
 *   EDGE_TEST_LOCAL_DB   base name, default pos_test_edge_local (single-worktree dev).
 *                        Per-worktree wrappers export their own, e.g.:
 *                          Edge worktree:     pos_test_edge_local_edge
 *                          Catering worktree: pos_test_edge_local_cat
 *
 * Fails closed: the resolved name must contain BOTH "edge" and "test" and satisfy the SAME
 * EdgeLocalDatabase naming rules the runtime guard enforces — never a production-shaped name.
 */
final class EdgeTestDatabases
{
    /**
     * The Edge-local test DB name, optionally suffixed for tests that need their OWN database
     * (e.g. local('refresh') so the refresh suite never drops the import suite's DB).
     */
    public static function local(?string $suffix = null): string
    {
        $name = (string) env('EDGE_TEST_LOCAL_DB', 'pos_test_edge_local');
        if ($suffix !== null && $suffix !== '') {
            $name .= '_' . $suffix;
        }

        if (stripos($name, 'test') === false
            || stripos($name, 'edge') === false
            || ! EdgeLocalDatabase::isEdgeDatabaseName($name)) {
            throw new RuntimeException(
                "Refusing Edge-local test DB [{$name}] — EDGE_TEST_LOCAL_DB must resolve to a dedicated "
                . "edge+test database name (e.g. pos_test_edge_local_edge), never a production-shaped name."
            );
        }

        return $name;
    }
}
