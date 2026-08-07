<?php

namespace App\Models\Edge;

use Illuminate\Database\Eloquent\Model;

/**
 * EDGE-LOCAL-AUTH-1 — durable, NON-SECRET local auth audit (identifiers + event metadata only; never
 * a raw password/PIN, hash, key, token or full assertion).
 */
class EdgeAuthAudit extends Model
{
    protected $connection = 'tenant';

    protected $table = 'edge_auth_audit';

    public $timestamps = false;

    protected $guarded = [];

    public const E_ENROLL_OK = 'enrollment_success';
    public const E_ENROLL_REJECT = 'enrollment_rejected';
    public const E_LOGIN_OK = 'login_success';
    public const E_LOGIN_FAIL = 'login_failure';
    public const E_LOCKOUT = 'lockout';
    public const E_LOGOUT = 'logout';
    public const E_ROTATE = 'credential_rotation';
    public const E_MGR_OK = 'manager_reauth_success';
    public const E_MGR_FAIL = 'manager_reauth_failure';

    /**
     * BEST-EFFORT audit for ORDINARY, non-state-critical events (rejected login attempts, logout):
     * never throws into the caller, so a logging failure can never become an authentication-availability
     * DoS. Deliberately NOT "durable" — for security-critical state changes use recordDurable() inside
     * the same transaction as the state change.
     */
    public static function record(string $event, array $data = []): void
    {
        try {
            static::recordDurable($event, $data);
        } catch (\Throwable $e) {
            // Ordinary attempt/logout audit is best-effort — must never break the auth flow.
        }
    }

    /**
     * DURABLE audit for security-critical state changes (enrollment success, credential rotation,
     * lockout transition). Call INSIDE the same DB transaction as the state change so the state and its
     * audit commit coherently — an exception here rolls the whole change back (no silent commit without
     * its audit record).
     */
    public static function recordDurable(string $event, array $data = []): void
    {
        static::create(array_merge([
            'event' => $event,
            'created_at' => now(),
        ], collect($data)->only(['user_id', 'branch_id', 'activation_epoch', 'issuer_user_id', 'jti', 'detail', 'ip'])->all()));
    }
}
