<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section S + L) — the Branch Server local-runtime readiness report.
 *
 * Distinguishes RUNTIME-FOUNDATION readiness (boundary + local DB + bootstrap binding) from SELLING
 * readiness, which does not exist yet. It NEVER reports ready_to_sell / operational_stock ready:
 * a stale bootstrap config quantity must never become the selling authority (that requires the
 * future activation fence: Cloud baseline @ revision R + activation generation + ack cursor).
 */
class EdgeLocalReadiness
{
    public function __construct(private readonly EdgeBranchContext $context = new EdgeBranchContext())
    {
    }

    /** True if the Edge-local database has been provisioned (edge_local_meta exists). */
    public function localDatabaseReady(): bool
    {
        if (! EdgeRuntime::isBranchServer()) {
            return false;
        }
        try {
            return Schema::connection('tenant')->hasTable('edge_local_meta');
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $dbReady = $this->localDatabaseReady();
        $bound = $dbReady && $this->context->isBound();

        $foundationReady = $dbReady && $bound; // runtime_boundary is always implemented on a branch_server

        return [
            'runtime_mode' => EdgeRuntime::mode(),

            // Foundation checks owned by THIS sprint.
            'runtime_boundary' => 'ready',
            'local_database' => $dbReady ? 'ready' : 'not_ready',
            'bootstrap_binding' => $bound ? 'ready' : 'not_ready',

            // Non-secret facts about the binding (null until bootstrapped).
            'tenant_code' => $this->context->boundTenantCode(),
            'branch_id' => $this->context->boundBranchId(),
            'activation_epoch' => $this->context->activationEpoch(),
            'config_revision' => $this->context->configRevision(),
            'bootstrap_schema' => $this->context->current()?->bootstrap_schema,

            // Config vs operational-stock: config may be imported, but stock is NEVER ready here.
            'config_ready' => $bound,
            'operational_stock' => 'not_ready',

            // EDGE-LOCAL-AUTH-1 crypto prerequisites (Section 7): honest, so local_auth never claims
            // ready/needs_enrollment when enrollment is cryptographically impossible.
            'crypto_ready' => \App\Services\Edge\EdgeEnrollmentCrypto::available(),
            'enrollment_public_key_ready' => (string) config('edge.enrollment.public_key') !== '',

            // Session/cookie contract (Sections 5/6). Appliance sessions are LOCAL (file). Production
            // (TLS-managed terminals) must have a secure cookie; a plain-HTTP localhost proof overrides.
            'session_driver' => config('session.driver'),
            'session_local' => ! in_array(config('session.driver'), ['database', 'redis', 'memcached', 'dynamodb'], true),
            'secure_cookie' => (bool) config('session.secure'),

            // EDGE-LOCAL-AUTH-1: not_ready (no auth schema / no crypto) | needs_enrollment (runtime ok,
            // no eligible credential yet) | ready (>=1 active, epoch-current, branch-authorized,
            // login-eligible credential). FOUNDATION auth readiness only — NEVER "ready to sell".
            'local_auth' => $this->localAuthState($bound),
            'local_pos' => 'not_implemented',        // EDGE-LOCAL-POS-1
            'local_print' => 'not_implemented',      // EDGE-LOCAL-PRINT-1
            'sync' => 'not_implemented',             // OFFLINE-SYNC-ENGINE-1

            // The runtime FOUNDATION may be ready; the appliance still cannot sell.
            'foundation_ready' => $foundationReady,
            'activation_ready' => false,
            'runtime_state' => $this->safeRuntimeState($dbReady),
        ];
    }

    /** local_auth: not_ready (no schema/crypto) | needs_enrollment (no eligible credential) | ready. */
    private function localAuthState(bool $bound): string
    {
        if (! $bound) {
            return 'not_ready';
        }
        // Enrollment is cryptographically impossible without ext-sodium + a public verification key —
        // never report needs_enrollment/ready then (Section 7).
        if (! \App\Services\Edge\EdgeEnrollmentCrypto::available() || (string) config('edge.enrollment.public_key') === '') {
            return 'not_ready';
        }
        try {
            if (! Schema::connection('tenant')->hasTable('edge_local_user_credentials')) {
                return 'not_ready';
            }
            $epoch = (int) $this->context->activationEpoch();
            $branchId = (int) $this->context->boundBranchId();
            // Count only credentials whose user is genuinely login-eligible (non-empty employee_code).
            $hasEligible = \App\Models\Edge\EdgeLocalUserCredential::query()
                ->join('users', 'users.id', '=', 'edge_local_user_credentials.user_id')
                ->where('edge_local_user_credentials.status', \App\Models\Edge\EdgeLocalUserCredential::STATUS_ACTIVE)
                ->where('edge_local_user_credentials.activation_epoch', $epoch)
                ->where('edge_local_user_credentials.branch_id', $branchId)
                ->whereNotNull('users.employee_code')
                ->where('users.employee_code', '!=', '')
                ->where('users.status', 'active')
                ->exists();

            return $hasEligible ? 'ready' : 'needs_enrollment';
        } catch (Throwable $e) {
            return 'not_ready';
        }
    }

    private function safeRuntimeState(bool $dbReady): string
    {
        if (! $dbReady) {
            return EdgeLocalMeta::STATE_UNINITIALIZED;
        }
        try {
            return EdgeLocalMeta::current()?->runtime_state ?? EdgeLocalMeta::STATE_UNINITIALIZED;
        } catch (Throwable $e) {
            return EdgeLocalMeta::STATE_UNINITIALIZED;
        }
    }
}
