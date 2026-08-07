<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeAuthAudit;
use App\Models\Edge\EdgeConsumedAssertion;
use App\Models\Edge\EdgeLocalUserCredential;
use App\Models\Tenant\User;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use App\Support\EdgeUserAuthz;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EDGE-LOCAL-AUTH-1 (Sections 3/7/12/13) — BRANCH SERVER side. Consumes a Cloud-signed enrollment
 * assertion and sets the user's Edge-specific credential. NO Cloud master DB is touched.
 *
 * Verifies: signature (public key), purpose/version, expiry, binding (tenant/branch/device/epoch match
 * the bound appliance), single-use jti (atomic), and that the target user exists locally + is active +
 * is branch-authorized. Then stores an Argon2id verifier bound to the current activation epoch. A
 * second valid assertion ROTATES the credential (version bump; old hash unusable).
 */
class EdgeEnrollmentConsumer
{
    /** Weak credentials that may never be set. */
    private const WEAK = ['1234', '0000', '000000', '123456', '111111', 'password', 'password1', 'admin'];

    /** Allowed clock skew (seconds) for assertion time validation. */
    private const CLOCK_SKEW = 60;

    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    public function consume(array $assertion, string $credential, string $type = EdgeLocalUserCredential::TYPE_PASSWORD): EdgeLocalUserCredential
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('Enrollment consumption only runs on a Branch Server.');
        }
        EdgeLocalDatabase::assertSafeTarget();

        $meta = $this->context->requireCurrent(); // EdgeNotBoundException if uninitialised

        $publicKey = (string) config('edge.enrollment.public_key');
        if ($publicKey === '' || ! EdgeEnrollmentCrypto::available()) {
            throw new RuntimeException('Edge enrollment public key/crypto not configured. Failing closed.');
        }
        if (! EdgeEnrollmentCrypto::verifySignature($assertion, $publicKey)) {
            EdgeAuthAudit::record(EdgeAuthAudit::E_ENROLL_REJECT, ['branch_id' => $meta->branch_id, 'detail' => 'bad_signature']);
            throw new RuntimeException('Enrollment assertion signature is invalid.');
        }

        $p = $assertion['payload'];
        $reject = function (string $why) use ($meta, $p) {
            EdgeAuthAudit::record(EdgeAuthAudit::E_ENROLL_REJECT, ['branch_id' => $meta->branch_id, 'jti' => $p['jti'] ?? null, 'detail' => $why]);
            throw new RuntimeException("Enrollment assertion rejected: {$why}.");
        };

        if (($p['purpose'] ?? '') !== EdgeEnrollmentCrypto::PURPOSE || ($p['version'] ?? '') !== EdgeEnrollmentCrypto::ASSERTION_VERSION) {
            $reject('purpose/version');
        }
        // Time contract (Section 10): a Cloud-signed but malformed long-lived assertion must not become
        // effectively permanent. Allow only a small clock skew.
        $now = time();
        $iat = (int) ($p['issued_at'] ?? 0);
        $exp = (int) ($p['expires_at'] ?? 0);
        $ttl = (int) config('edge.enrollment.assertion_ttl', 900);
        if ($exp < $now) {
            $reject('expired');
        }
        if ($iat > $now + self::CLOCK_SKEW) {
            $reject('issued_in_future');
        }
        if ($exp <= $iat) {
            $reject('bad_time_window');
        }
        if (($exp - $iat) > $ttl + self::CLOCK_SKEW) {
            $reject('lifetime_exceeds_ttl');
        }
        // Binding — the assertion must be for THIS exact appliance.
        if ((int) ($p['tenant_id'] ?? 0) !== (int) $meta->tenant_id) {
            $reject('tenant_mismatch');
        }
        if ((int) ($p['branch_id'] ?? 0) !== (int) $meta->branch_id) {
            $reject('branch_mismatch');
        }
        if ((string) ($p['device_public_uuid'] ?? '') !== (string) $meta->device_uuid) {
            $reject('device_mismatch');
        }
        if ((int) ($p['activation_epoch'] ?? -1) !== (int) $meta->activation_epoch) {
            $reject('epoch_mismatch');
        }

        $user = User::on('tenant')->find((int) ($p['user_id'] ?? 0));
        if (! $user || ! EdgeUserAuthz::isActive($user) || ! EdgeUserAuthz::isEdgeLoginEligible($user)) {
            $reject('user_ineligible_or_missing'); // inactive / missing / no employee_code
        }
        if (! EdgeUserAuthz::mayOperateBranch($user, (int) $meta->branch_id)) {
            $reject('user_not_branch_authorized');
        }

        $this->assertStrong($credential, $type);

        // Atomic: consume the jti (single-use) + enroll/rotate the credential.
        return DB::connection('tenant')->transaction(function () use ($p, $user, $meta, $credential, $type, $reject) {
            try {
                EdgeConsumedAssertion::create([
                    'jti' => $p['jti'], 'purpose' => $p['purpose'],
                    'user_id' => $user->id, 'branch_id' => $meta->branch_id, 'activation_epoch' => $meta->activation_epoch,
                    'consumed_at' => now(),
                ]);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    $reject('replay'); // jti already consumed
                }
                throw $e;
            }

            $existing = EdgeLocalUserCredential::where('user_id', $user->id)->first();
            $version = $existing ? (int) $existing->credential_version + 1 : 1;

            $cred = EdgeLocalUserCredential::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'branch_id' => (int) $meta->branch_id,
                    'activation_epoch' => (int) $meta->activation_epoch,
                    'credential_hash' => password_hash($credential, PASSWORD_ARGON2ID),
                    'credential_type' => $type,
                    'credential_version' => $version,
                    'status' => EdgeLocalUserCredential::STATUS_ACTIVE,
                    'failed_attempts' => 0,
                    'locked_until' => null,
                    'enrolled_at' => now(),
                ]
            );

            // DURABLE: the enrollment/rotation audit commits coherently with the jti + credential in
            // this same transaction (no silent state change without its audit record).
            EdgeAuthAudit::recordDurable($existing ? EdgeAuthAudit::E_ROTATE : EdgeAuthAudit::E_ENROLL_OK, [
                'user_id' => $user->id, 'branch_id' => $meta->branch_id, 'activation_epoch' => $meta->activation_epoch,
                'issuer_user_id' => $p['issuer_user_id'] ?? null, 'jti' => $p['jti'],
            ]);

            return $cred->fresh();
        });
    }

    /** No weak/default credential; enforce minimum strength per type. */
    private function assertStrong(string $credential, string $type): void
    {
        if (in_array(strtolower($credential), self::WEAK, true)) {
            throw new RuntimeException('That credential is too weak / a known default. Choose a stronger one.');
        }
        if ($type === EdgeLocalUserCredential::TYPE_PIN) {
            if (! preg_match('/^\d{6,}$/', $credential)) {
                throw new RuntimeException('An Edge PIN must be at least 6 digits.');
            }
            if (preg_match('/^(\d)\1+$/', $credential)) {
                throw new RuntimeException('An Edge PIN must not be all the same digit.');
            }
        } else {
            if (strlen($credential) < 8) {
                throw new RuntimeException('An Edge password must be at least 8 characters.');
            }
        }
    }
}
