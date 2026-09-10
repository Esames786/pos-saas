<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Services\Edge\EdgeAuthorityLeaseService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * OFFLINE EDGE — P0 BRANCH AUTHORITY LEASE, the Cloud's device-authenticated endpoints.
 *
 * POST /api/edge/authority/heartbeat — the appliance renews the Cloud's lease for ITS branch (or asserts it took
 * over). POST /api/edge/authority/handback — the appliance returns authority when its sync is clean. Both are
 * scoped to the device's own branch (the middleware-authenticated active device is the only identity trusted).
 */
class EdgeAuthorityApiController extends Controller
{
    public function __construct(private readonly TenancyManager $tenancy, private readonly EdgeAuthorityLeaseService $leases)
    {
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seq' => ['required', 'integer', 'min:1'],
            'edge_state' => ['required', 'string', 'in:standby,local_active,handing_back'],
        ]);
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $tenant = Tenant::find($device->tenant_id);
        if (! $tenant) {
            return response()->json(['status' => 'refused', 'failure_code' => 'WRONG_TENANT'], 403);
        }
        $this->tenancy->activate($tenant);
        try {
            $lease = $this->leases->heartbeat($device, (int) $data['seq'], (string) $data['edge_state']);
            // Q — WARM STANDBY FRESHNESS: every accepted heartbeat tells the appliance where the Cloud stands
            // (config revision + official-stock watermark) so the standby can prove, and keep, its freshness.
            $advertised = app(\App\Services\Edge\EdgeStandbyAdvertiser::class)->forDevice($device, $tenant);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'refused', 'failure_code' => $e->getMessage()], 409);
        } finally {
            $this->tenancy->deactivate();
        }

        return response()->json(['status' => 'ok'] + $lease + $advertised);
    }

    public function handback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outbox_pending' => ['required', 'integer', 'min:0'],
            'failed_permanent' => ['required', 'integer', 'min:0'],
        ]);
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $tenant = Tenant::find($device->tenant_id);
        if (! $tenant) {
            return response()->json(['status' => 'refused', 'failure_code' => 'WRONG_TENANT'], 403);
        }
        $this->tenancy->activate($tenant);
        try {
            $lease = $this->leases->handback($device, (int) $data['outbox_pending'], (int) $data['failed_permanent']);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'refused', 'failure_code' => $e->getMessage()], 422);
        } finally {
            $this->tenancy->deactivate();
        }

        return response()->json(['status' => 'ok'] + $lease);
    }
}
