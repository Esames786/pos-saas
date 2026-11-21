<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Services\Edge\EdgeEnrollmentConsumer;
use App\Support\EdgeLocalDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EDGE 0.9 (stream B, lead decisions 21 Nov 2026 §5; contract with stream D's counter wizard) — POST /edge/local/enroll: a
 * counter wizard sets the logged-in cashier's OWN Edge PIN.
 *
 *   request  {"assertion": {"payload": {…}, "signature": "…"}, "credential": "<digits>", "type": "pin"}   (JSON, over LAN TLS)
 *   201      {"ok": true, "user_id": N}
 *   4xx      {"ok": false, "reason": "…"}   expiry → contains "expire"; replay → contains "replay"; weak PIN → plain text
 *
 * The Cloud-signed 15-minute assertion (issued by the Cloud only for the requesting user himself) is the whole authorisation;
 * nothing is added or bypassed: EdgeEnrollmentConsumer::consume() checks the Ed25519 signature, purpose/version, expiry, single
 * use (jti replay store), tenant / branch / device / activation-epoch binding, the user, and the PIN strength.
 *
 * OFF by default (edge.local_enroll_endpoint.enabled) → 404. No session, CSRF-exempt (JSON client, not a browser form) and
 * throttled 5/min/IP by its route. The credential is never logged, echoed or stored except as the consumer's Argon2id verifier.
 */
class EdgeLocalSelfEnrollController extends Controller
{
    public function __construct(private readonly EdgeEnrollmentConsumer $consumer)
    {
    }

    public function store(Request $request): JsonResponse
    {
        if (! (bool) config('edge.local_enroll_endpoint.enabled', false)) {
            return response()->json(['ok' => false, 'reason' => 'Self-enrolment is not enabled on this Branch Server.'], 404);
        }
        $assertion = $request->input('assertion');
        $credential = $request->input('credential', $request->input('pin'));
        $type = (string) $request->input('type', 'pin');
        if ($type !== 'pin') {
            return response()->json(['ok' => false, 'reason' => 'Only an Edge PIN can be set here (type must be "pin").'], 422);
        }
        if (! is_array($assertion) || ! isset($assertion['payload'], $assertion['signature']) || ! is_array($assertion['payload']) || ! is_string($assertion['signature'])) {
            return response()->json(['ok' => false, 'reason' => 'The request needs the signed assertion {"payload", "signature"} from the Cloud.'], 422);
        }
        if (! is_string($credential) || $credential === '') {
            return response()->json(['ok' => false, 'reason' => 'Type the PIN (digits).'], 422);
        }
        EdgeLocalDatabase::useAsTenantConnection();
        $userId = (int) ($assertion['payload']['user_id'] ?? 0);
        try {
            $cred = $this->consumer->consume(['payload' => $assertion['payload'], 'signature' => $assertion['signature']], $credential, 'pin');
        } catch (Throwable $e) {
            Log::info('[edge-local-self-enroll]', ['outcome' => 'refused', 'user_id' => $userId, 'ip' => $request->ip(), 'reason' => mb_substr($e->getMessage(), 0, 160)]);

            return response()->json(['ok' => false, 'reason' => $e->getMessage()], 422);
        } finally {
            unset($credential);
        }
        Log::info('[edge-local-self-enroll]', ['outcome' => 'enrolled', 'user_id' => (int) $cred->user_id, 'credential_version' => (int) $cred->credential_version, 'ip' => $request->ip()]);

        return response()->json(['ok' => true, 'user_id' => (int) $cred->user_id], 201);
    }
}
