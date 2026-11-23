<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Services\Edge\Cloud\LiveStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EDGE 0.9 (K1) — GET /api/edge/live-state?since=<revision> (device-authenticated, Cloud only): the device's branch's open
 * shifts, open table sessions with their unpaid orders, and held sales, for the warm standby's read-only mirror. Branch and
 * tenant come from the authenticated device. Read-only; never cacheable.
 */
class EdgeLiveStateApiController extends Controller
{
    public function __construct(private readonly LiveStateService $live) {}

    public function show(Request $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $since = $request->query('since');

        $r = response()->json($this->live->respond($device, is_string($since) ? substr($since, 0, 128) : null));
        $r->headers->set('Cache-Control', 'no-store, private, max-age=0');

        return $r;
    }
}
