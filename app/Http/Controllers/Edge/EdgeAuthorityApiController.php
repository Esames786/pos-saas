<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Services\Edge\EdgeAuthorityLeaseService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * OFFLINE EDGE — P0 BRANCH AUTHORITY LEASE, the Cloud's device-authenticated endpoints.
 *
 * POST /api/edge/authority/heartbeat — the appliance renews the Cloud's lease for ITS branch (or asserts it took
 * over). POST /api/edge/authority/handback — the appliance returns authority when its sync is clean. Both are
 * scoped to the device's own branch (the middleware-authenticated active device is the only identity trusted).
 *
 * W-F VERSION REPORTING — a heartbeat may also carry an informational `build` block. It is validated on its own: an
 * invalid block is DROPPED (not recorded), never a reason to refuse the beat, so a malformed report can never cost the
 * branch its lease. It is recorded only after an ACCEPTED beat, outside the lease transaction, and only when it changed.
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
            // W-F: it also carries the Cloud's capability advertisement (`capabilities`).
            $advertised = app(\App\Services\Edge\EdgeStandbyAdvertiser::class)->forDevice($device, $tenant);
        } catch (\App\Exceptions\EdgeStaleHeartbeatException $e) {
            // P5C — tell the appliance where the Cloud stands so it can resync its sequence (restore from an older backup,
            // a replacement machine) instead of missing forever.
            return response()->json(['status' => 'refused', 'failure_code' => $e->getMessage(), 'seq' => $e->cloudSeq], 409);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'refused', 'failure_code' => $e->getMessage()], 409);
        } finally {
            $this->tenancy->deactivate();
        }

        // W-F — informational build report: after the lease work, master DB only, write-on-change, never fails the beat.
        $this->leases->recordBuildReport($device, $this->reportedBuild($request, $device));

        return response()->json(['status' => 'ok'] + $lease + $advertised);
    }

    /**
     * The heartbeat's `build` block when present AND valid; null otherwise (an old appliance sends none; an invalid block
     * is dropped and logged — the heartbeat itself is judged only on seq + edge_state).
     */
    private function reportedBuild(Request $request, EdgeDevice $device): ?array
    {
        try {
            $build = $request->input('build');
            if ($build === null) {
                return null;
            }
            $rules = ['build' => ['required', 'array']];
            foreach (EdgeAuthorityLeaseService::BUILD_STRING_FIELDS as $key => $max) {
                $rules["build.{$key}"] = ['nullable', 'string', "max:{$max}"];
            }
            foreach (EdgeAuthorityLeaseService::BUILD_LIST_FIELDS as $key => [$maxItems, $maxLen]) {
                $rules["build.{$key}"] = ['nullable', 'array', 'list', "max:{$maxItems}"];
                $rules["build.{$key}.*"] = ['string', "max:{$maxLen}"];
            }
            $validator = Validator::make(['build' => $build], $rules);
            if ($validator->fails()) {
                Log::notice('[edge-authority] heartbeat build block ignored (invalid)', [
                    'device' => (string) $device->public_uuid, 'errors' => array_slice($validator->errors()->keys(), 0, 10),
                ]);

                return null;
            }

            return is_array($build) ? $build : null;
        } catch (\Throwable $e) {
            return null;
        }
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
