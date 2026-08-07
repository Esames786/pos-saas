<?php

namespace App\Support;

use RuntimeException;

/**
 * EDGE-RUNTIME-BOUNDARY-1 — the ONE canonical runtime-identity reader.
 *
 * The runtime mode is `config('app.role')` and NOTHING else (no parallel EDGE/LOCAL/OFFLINE
 * booleans). BranchOperatingModeService reads the same config key; this is the low-level accessor
 * the new runtime boundary and health/build surface use.
 *
 * FAIL CLOSED: a Branch Server that is misconfigured (unknown role value, or missing minimum runtime
 * config) must NEVER silently behave like the full Cloud SaaS. mode() rejects an unrecognized role
 * value rather than defaulting to cloud, and assertBootConfig() blocks a branch_server boot that
 * cannot describe itself.
 */
class EdgeRuntime
{
    public const MODE_CLOUD = 'cloud';
    public const MODE_BRANCH_SERVER = 'branch_server';

    /**
     * The immutable process runtime mode, from config only. An empty/unset value is the documented
     * default (cloud); a NON-empty unrecognized value fails closed (a typo'd APP_ROLE must not
     * quietly run the full SaaS on a branch PC).
     */
    public static function mode(): string
    {
        $raw = config('app.role');

        if ($raw === null || $raw === '') {
            return self::MODE_CLOUD;
        }

        if (! in_array($raw, [self::MODE_CLOUD, self::MODE_BRANCH_SERVER], true)) {
            throw new RuntimeException(
                "Invalid APP_ROLE [{$raw}] — must be 'cloud' or 'branch_server'. Refusing to boot to avoid "
                . 'silently running the full Cloud SaaS on a misconfigured instance.'
            );
        }

        return $raw;
    }

    public static function isBranchServer(): bool
    {
        return self::mode() === self::MODE_BRANCH_SERVER;
    }

    public static function isCloud(): bool
    {
        return self::mode() === self::MODE_CLOUD;
    }

    /**
     * NON-THROWING cloud check, safe to call at CONFIG-LOAD time (a config file must never throw, or
     * the framework cannot bootstrap far enough to render a controlled error). Returns true ONLY for
     * the documented cloud role (unset/empty or 'cloud'); a branch_server OR any INVALID role returns
     * false — fail closed. The throwing mode()/isCloud() remain the runtime source of truth and still
     * refuse the boot for an invalid role at the controlled guard point.
     */
    public static function isCloudSafe(): bool
    {
        $raw = config('app.role');

        return $raw === null || $raw === '' || $raw === self::MODE_CLOUD;
    }

    /**
     * HARDEN-1: is this running from a PACKAGED Edge artifact? The builder writes
     * edge-build-manifest.json (runtime_mode_supported = branch_server) into the artifact root; the
     * normal Cloud working tree does NOT contain it (git-ignored, only produced by the builder). A
     * present, branch_server-marked manifest is the signal "this is an appliance build".
     */
    public static function isPackagedEdgeArtifact(): bool
    {
        $path = base_path('edge-build-manifest.json');
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }
        $manifest = json_decode((string) file_get_contents($path), true);

        return is_array($manifest) && ($manifest['runtime_mode_supported'] ?? null) === self::MODE_BRANCH_SERVER;
    }

    /**
     * FAIL-CLOSED boot validation for a Branch Server. Validates only what THIS sprint owns — the
     * runtime can describe itself (mode + versions + a supported bootstrap schema). It deliberately
     * does NOT require local DB / device credentials / tenant-branch binding: those belong to later
     * Edge sprints (EDGE-LOCAL-RUNTIME-1 / EDGE-LOCAL-AUTH-1). Throws on any missing piece.
     *
     * @return array<int,string> the problems found (empty = healthy)
     */
    public static function bootProblems(): array
    {
        $problems = [];

        // Force the role read so an invalid value surfaces here too.
        try {
            $mode = self::mode();
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }

        // HARDEN-1: a packaged Edge artifact MUST run as branch_server. If the artifact marker is
        // present but APP_ROLE is missing/cloud/anything-but-branch_server, fail closed — a
        // misconfigured appliance must NEVER silently become a Cloud SaaS runtime.
        if (self::isPackagedEdgeArtifact() && $mode !== self::MODE_BRANCH_SERVER) {
            return ['This is a packaged Bingoo Edge artifact but APP_ROLE is not branch_server '
                . "(got '" . (config('app.role') ?? '') . "'). Refusing to run as Cloud."];
        }

        if ($mode !== self::MODE_BRANCH_SERVER) {
            return $problems; // cloud (non-artifact) has no extra runtime requirements
        }

        if (! is_string(config('edge.app_version')) || config('edge.app_version') === '') {
            $problems[] = 'edge.app_version is not configured.';
        }
        if (! is_string(config('edge.bootstrap_schema')) || config('edge.bootstrap_schema') === '') {
            $problems[] = 'edge.bootstrap_schema is not configured.';
        }
        if (! is_string(config('edge.artifact_format_version')) || config('edge.artifact_format_version') === '') {
            $problems[] = 'edge.artifact_format_version is not configured.';
        }
        if (version_compare(PHP_VERSION, (string) config('edge.min_php', '8.2.0'), '<')) {
            $problems[] = 'PHP ' . PHP_VERSION . ' is below the minimum required for the Edge runtime ('
                . config('edge.min_php') . ').';
        }

        // EDGE-LOCAL-RUNTIME-1 (Section F): the appliance must carry its OWN machine-local application
        // key (EDGE_LOCAL_APP_KEY, resolved into app.key). Fail closed if it is missing rather than
        // silently running without encryption/session key material or reaching for the Cloud APP_KEY.
        if (! is_string(config('app.key')) || config('app.key') === '') {
            $problems[] = 'EDGE_LOCAL_APP_KEY is not set — a Branch Server requires its own machine-local application key.';
        }

        // EDGE-LOCAL-AUTH-1 (Section 5): the appliance session/cache must be LOCAL. A connection-backed
        // session driver is read by StartSession BEFORE the tenant→edge-local binding, so it would query
        // an unbound/wrong connection. Enforce a file (local) session driver — the chosen appliance
        // contract. (Tests may override; this only guards a real branch_server HTTP/CLI runtime.)
        if (in_array(config('session.driver'), ['database', 'redis', 'memcached', 'dynamodb'], true)) {
            $problems[] = 'Branch Server SESSION_DRIVER must be local (file); a connection-backed session is read before the local DB is bound.';
        }

        return $problems;
    }

    /** Throw if a Branch Server cannot boot (never falls back to cloud behavior). */
    public static function assertBootConfig(): void
    {
        $problems = self::bootProblems();

        if ($problems !== []) {
            throw new RuntimeException(
                'Branch Server runtime is misconfigured and will not fall back to Cloud: '
                . implode(' ', $problems)
            );
        }
    }
}
