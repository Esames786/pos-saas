<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EDGE-RUNTIME-LOCAL-1 (Section B + C) — the ONE canonical accessor for the Branch Server's own
 * local database, plus the fail-closed safety guard that must pass before any destructive local
 * schema work (db-init / bootstrap import).
 *
 * On a branch_server runtime the Laravel `tenant` connection is RESOLVED to this local database
 * (useAsTenantConnection), so the appliance's tenant-scoped models read/write the local MariaDB and
 * never require the Cloud master/tenant DBs for normal runtime. On Cloud this class is unused.
 *
 * SAFETY (fails closed): destructive work is refused unless
 *   1. the runtime is branch_server (never touch a local DB from a cloud process), AND
 *   2. the DB host is loopback (127.0.0.1 / localhost / ::1) — unless an explicit, test-only remote
 *      override is set, AND
 *   3. the database name matches the dedicated Edge convention (bingoo_edge_* / edge_local_* / an
 *      *edge*…*test* name) and is NOT a known Cloud/master/tenant/production database.
 * These three together make it impossible to db-init or import over pos_saas_master or a
 * pos_tenant_* database.
 */
class EdgeLocalDatabase
{
    /** The physical connection defined in config/database.php. */
    public const CONNECTION = 'edge_local';

    /** Hosts we consider machine-local. */
    public const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    /** Database names that must NEVER be treated as an Edge-local target. */
    public const FORBIDDEN_EXACT = ['pos_saas_master', 'information_schema', 'mysql', 'performance_schema', 'sys'];

    /**
     * Resolve the Laravel `tenant` connection to the Edge-local database for this branch_server
     * process. Copies the edge_local connection config onto `tenant`, PURGES any stale tenant
     * connection instance, and makes it the default. It deliberately does NOT open a socket
     * (no reconnect) — a purged connection is rebuilt lazily on the first query — so this is safe to
     * call before the local DB has even been provisioned (health/ready and edge:local:db-init must all
     * work from an uninitialised state). The socket only opens when an actual DB operation runs.
     */
    public static function useAsTenantConnection(): void
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('Refusing to bind the tenant connection to the Edge-local DB outside a branch_server runtime.');
        }

        $edge = config('database.connections.' . self::CONNECTION);
        if (! is_array($edge)) {
            throw new RuntimeException('The [edge_local] database connection is not configured.');
        }

        config(['database.connections.tenant' => $edge]);
        DB::purge('tenant');               // drop any stale instance; next query reconnects lazily
        DB::setDefaultConnection('tenant');
    }

    /** The configured Edge-local host, or null. */
    public static function host(): ?string
    {
        $h = config('database.connections.' . self::CONNECTION . '.host');

        return $h === null ? null : (string) $h;
    }

    /** The configured Edge-local database name, or null. */
    public static function database(): ?string
    {
        $d = config('database.connections.' . self::CONNECTION . '.database');

        return $d === null ? null : (string) $d;
    }

    /**
     * Dev/test ONLY escape hatch for a non-loopback MariaDB. Deliberately narrow: it is honoured only
     * together with an *test* database name, so it can never point a real appliance at a remote
     * production-shaped database. Never set this on a shipped appliance.
     */
    public static function remoteOverrideAllowed(): bool
    {
        return filter_var(env('EDGE_DB_ALLOW_REMOTE', false), FILTER_VALIDATE_BOOL);
    }

    public static function isLoopbackHost(?string $host): bool
    {
        return $host !== null && in_array(strtolower(trim($host)), self::LOOPBACK_HOSTS, true);
    }

    /**
     * Is $database a name that matches the dedicated Edge-local convention (and is not a known
     * Cloud/system database)? bingoo_edge_* / edge_local_* / any name that clearly marks itself as an
     * *edge*…*test* database (for the authoritative MySQL suite, e.g. pos_test_edge_local).
     */
    public static function isEdgeDatabaseName(?string $database): bool
    {
        if ($database === null || $database === '') {
            return false;
        }
        $name = strtolower(trim($database));

        if (in_array($name, self::FORBIDDEN_EXACT, true)) {
            return false;
        }
        // A cloud tenant database is never an Edge-local target.
        if (str_starts_with($name, 'pos_tenant_') || str_starts_with($name, 'pos_saas_')) {
            return false;
        }
        if (str_starts_with($name, 'bingoo_edge_') || str_starts_with($name, 'edge_local_')) {
            return true;
        }
        // Dedicated Edge test database, e.g. pos_test_edge_local.
        if (str_contains($name, 'edge') && str_contains($name, 'test')) {
            return true;
        }

        return false;
    }

    /**
     * Full fail-closed evaluation. Returns null when the target is safe for destructive Edge-local
     * schema work, otherwise a human-readable reason string.
     */
    public static function unsafeReason(): ?string
    {
        if (! EdgeRuntime::isBranchServer()) {
            return 'not a branch_server runtime (APP_ROLE must be branch_server for Edge-local schema work)';
        }

        $host = self::host();
        $database = self::database();

        if (! self::isEdgeDatabaseName($database)) {
            return "database [" . ($database ?? '') . "] is not a dedicated Edge-local database name "
                . '(expected bingoo_edge_* / edge_local_* / an edge…test name; never a master/tenant/production DB)';
        }

        if (! self::isLoopbackHost($host)) {
            if (! (self::remoteOverrideAllowed() && str_contains(strtolower((string) $database), 'test'))) {
                return "host [" . ($host ?? '') . "] is not loopback; a non-local Edge DB is refused "
                    . '(dev/test only: set EDGE_DB_ALLOW_REMOTE=true with a *test* database name)';
            }
        }

        return null;
    }

    public static function isSafeTarget(): bool
    {
        return self::unsafeReason() === null;
    }

    /** Throw unless the Edge-local target is safe for destructive schema work. */
    public static function assertSafeTarget(): void
    {
        if (($reason = self::unsafeReason()) !== null) {
            throw new RuntimeException("Refusing Edge-local database operation: {$reason}.");
        }
    }
}
