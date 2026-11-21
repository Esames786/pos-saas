<?php

namespace App\Http\Controllers\Edge;

use App\Exceptions\EdgeBootstrapException;
use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Services\Edge\EdgeBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OFFLINE EDGE — Q: WARM STANDBY FRESHNESS, Cloud side — the config REFRESH package (device-authenticated).
 *
 * A paired appliance that learned from a heartbeat that its applied config revision is behind pulls the current
 * package here and applies it with EdgeLocalConfigRefreshApplier (revisioned upsert/tombstone, fail-closed on
 * binding/hash/old-revision). The branch, tenant and epoch come from the authenticated device, never the body.
 */
class EdgeConfigRefreshApiController extends Controller
{
    public function __construct(private readonly EdgeBootstrapService $bootstrap)
    {
    }

    public function package(Request $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        try {
            $package = $this->bootstrap->refreshPackage($device);
        } catch (EdgeBootstrapException $e) {
            return response()->json(['status' => 'refused', 'failure_code' => $e->bootstrapCode, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['status' => 'ok'] + $package);
    }
}
