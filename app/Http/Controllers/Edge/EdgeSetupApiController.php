<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Services\Edge\Cloud\BulkEnrollment;
use App\Services\Edge\Cloud\SetupSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EDGE 0.9 — CENTRAL setup-wizard API (Cloud only; routes/edge.php is never registered on a Branch Server).
 *
 *   POST /api/edge/setup/redeem                  K2 — public, IP-throttled; per-code attempt limit in the service
 *   POST /api/edge/setup/enrollment-assertions   K3 — device-authenticated + X-Edge-Setup-Token (same tenant/branch)
 *
 * Tenant + branch always come from the setup session / the authenticated device, never from the request body. Expected
 * failures render as structured JSON (EdgeSetupException) — never a 500. Responses are never cacheable.
 */
class EdgeSetupApiController extends Controller
{
    public const TOKEN_HEADER = 'X-Edge-Setup-Token';

    public function __construct(
        private readonly SetupSessionService $sessions,
        private readonly BulkEnrollment $bulk,
    ) {}

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'setup_code'        => ['required', 'string', 'max:32'],
            'installation_uuid' => ['required', 'uuid'],
            'device_name'       => ['nullable', 'string', 'max:190'],
        ]);

        return $this->noStore(response()->json($this->sessions->redeem($data)));
    }

    public function enrollmentAssertions(Request $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $session = $this->sessions->resolveToken($request->header(self::TOKEN_HEADER), $device);

        $data = $request->validate([
            'user_ids'   => ['required_without:all_active', 'array', 'max:' . BulkEnrollment::MAX_USERS],
            'user_ids.*' => ['integer', 'min:1'],
            'all_active' => ['sometimes', 'boolean'],
        ]);
        $allActive = (bool) ($data['all_active'] ?? false);

        $result = $this->bulk->issue($device, $session, $allActive ? null : array_map('intval', $data['user_ids'] ?? []), $allActive);

        return $this->noStore(response()->json($result));
    }

    private function noStore(JsonResponse $r): JsonResponse
    {
        $r->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $r->headers->set('Pragma', 'no-cache');

        return $r;
    }
}
