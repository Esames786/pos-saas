<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Services\Edge\EdgeBuildInfoService;
use App\Support\EdgeRuntime;
use Illuminate\Http\JsonResponse;

/**
 * EDGE-RUNTIME-BOUNDARY-1 — the ONLY Branch Server runtime surface this sprint exposes: health,
 * readiness and build/version info. NON-SECRET only (never .env, DB password, APP_KEY, device
 * tokens, certificates or customer data). These are the routes on the branch_server allowlist.
 */
class EdgeRuntimeController extends Controller
{
    public function __construct(private readonly EdgeBuildInfoService $buildInfo)
    {
    }

    /**
     * Liveness — MINIMISED (HARDEN-1): only status, runtime mode, the version/compat essentials a
     * peer needs, and the server epoch. The fuller build fingerprint (php version, git commit, build
     * timestamp, min_php) lives on /build-info so /health does not broadcast an OS/runtime
     * fingerprint on the LAN. Still non-secret.
     */
    public function health(): JsonResponse
    {
        $info = $this->buildInfo->info();

        return response()->json([
            'status'           => 'ok',
            'runtime_mode'     => $info['runtime_mode'],
            'edge_app_version' => $info['edge_app_version'],
            'bootstrap_schema' => $info['bootstrap_schema'],
            'sync_protocol'    => $info['sync_protocol'],
            'server_epoch_ms'  => (int) round(microtime(true) * 1000),
        ]);
    }

    /**
     * Readiness. This sprint only owns the runtime boundary, so "ready" means the runtime can
     * describe itself (fail-closed boot check passes). Later sprints will extend the checks with
     * branch binding / local DB / config revision / backup age / sync queue — reported here as
     * explicitly not-yet-implemented so nothing is faked.
     */
    public function ready(): JsonResponse
    {
        $problems = EdgeRuntime::bootProblems();

        return response()->json([
            'runtime_mode' => EdgeRuntime::mode(),
            'ready'        => $problems === [],
            'problems'     => $problems,
            'checks'       => [
                'runtime_boundary' => 'implemented',
                'branch_binding'   => 'not_implemented', // EDGE-LOCAL-RUNTIME-1
                'local_database'   => 'not_implemented', // EDGE-LOCAL-RUNTIME-1
                'local_auth'       => 'not_implemented', // EDGE-LOCAL-AUTH-1
                'config_revision'  => 'not_implemented',
                'sync_queue'       => 'not_implemented',
                'print_agent'      => 'not_implemented',
            ],
        ], $problems === [] ? 200 : 503);
    }

    /** Build/version/compatibility surface for the future signed updater. */
    public function buildInfo(): JsonResponse
    {
        return response()->json($this->buildInfo->info());
    }
}
