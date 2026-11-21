<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Master\EdgeDevice;
use App\Services\Edge\EdgeBackupRecoveryAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * P5B §3 — device-authenticated retrieval of the branch backup recovery material (Cloud-hosted only).
 *
 * POST /api/edge/backup/recovery-keys  { key_id?: string }
 *   → { status, tenant_id, branch_id, current: {key_id, key}, retired: {key_id: key, …} }   Cache-Control: no-store
 *
 * Scope is the DEVICE's (tenant, branch) from the authenticated device row — never from input. An optional key_id is
 * only a scope assertion (a replacement asking for the key that sealed a specific backup): another branch's id → 404
 * RECOVERY_KEY_NOT_FOUND, audited. Revoked devices never reach here (the device auth middleware refuses them).
 */
class EdgeBackupRecoveryApiController extends Controller
{
    public function __construct(private readonly EdgeBackupRecoveryAuthority $authority)
    {
    }

    public function material(Request $request): JsonResponse
    {
        $device = $request->attributes->get('edgeDevice');
        if (! $device instanceof EdgeDevice) {
            return $this->refused('EDGE_DEVICE_UNAUTHENTICATED', 401);
        }
        $requested = trim((string) $request->input('key_id', ''));
        try {
            if ($requested !== '') {
                $this->authority->assertKeyInScope($device, $requested, $request->ip());
            }
            $material = $this->authority->materialForDevice($device, $request->ip());
        } catch (RuntimeException $e) {
            $code = (string) strtok($e->getMessage(), ':');

            return $this->refused($code, $code === 'RECOVERY_KEY_NOT_FOUND' ? 404 : 403);
        }

        return response()->json([
            'status' => 'ok',
            'tenant_id' => $material['tenant_id'],
            'branch_id' => $material['branch_id'],
            'current' => $material['current'],
            'retired' => (object) $material['retired'],
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    private function refused(string $code, int $status): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => 'Recovery material refused.'], $status)
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
