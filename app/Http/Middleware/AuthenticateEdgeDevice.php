<?php

namespace App\Http\Middleware;

use App\Models\Master\EdgeDevice;
use Closure;
use Illuminate\Http\Request;

/**
 * BRANCH-DEVICE-PAIRING-1 — authenticate a Bingoo Edge device by its public UUID +
 * client-held secret. The plaintext secret is compared against the stored sha256 hash
 * in constant time. Revoked devices (active_slot NULL / status revoked) are rejected.
 *
 * Tenant/branch are taken from the DEVICE row only — request-supplied tenant/branch
 * fields are ignored and never used to activate a tenant.
 */
class AuthenticateEdgeDevice
{
    /** Only touch last_authenticated_at at most once per this many seconds (write throttle). */
    private const TOUCH_THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next)
    {
        $publicUuid = (string) $request->header('X-Edge-Device-ID', '');
        $bearer     = (string) $request->bearerToken();

        if ($publicUuid === '' || $bearer === '') {
            return $this->unauthorized();
        }

        $device = EdgeDevice::query()
            ->where('public_uuid', $publicUuid)
            ->active()                       // active_slot=1 AND status != revoked
            ->first();

        if (! $device) {
            return $this->unauthorized();
        }

        $suppliedHash = hash('sha256', $bearer);
        if (! hash_equals($device->device_secret_hash, $suppliedHash)) {
            return $this->unauthorized();
        }

        // Throttled write so a chatty device doesn't hammer the master DB.
        if (! $device->last_authenticated_at
            || $device->last_authenticated_at->diffInSeconds(now()) >= self::TOUCH_THROTTLE_SECONDS) {
            $device->forceFill(['last_authenticated_at' => now()])->save();
        }

        $request->attributes->set('edgeDevice', $device);

        return $next($request);
    }

    private function unauthorized()
    {
        return response()->json([
            'message' => 'Edge device authentication failed.',
            'code'    => 'EDGE_DEVICE_UNAUTHENTICATED',
        ], 401);
    }
}
