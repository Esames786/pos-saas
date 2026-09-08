<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * OFFLINE EDGE — P0 BRANCH AUTHORITY, appliance side.
 *
 * The appliance reasons ONLY on its own clock and its own observations:
 *  - Each acknowledged heartbeat records `authority_last_ack_at` (local clock) and the Cloud TTL.
 *  - The Cloud's lease is considered LAPSED locally only when local now() ≥ last_ack + TTL + skew margin — i.e.
 *    strictly after the moment the Cloud fenced itself, whatever the clock skew within the margin. One failed
 *    request is never a takeover signal; a lease that was never granted can never be taken over.
 *  - LOCAL authority is assumed only when the lease has lapsed AND every readiness gate passes AND (pilot posture)
 *    a supervisor confirmed. Until then the appliance is STANDBY and refuses local mutations (fail closed).
 *  - Handback goes through the Cloud only when the outbox is clean (pending 0, permanent failures 0).
 *  - Lease mode is ON only when the appliance is provisioned with the Cloud authority URLs; an appliance without
 *    them keeps the manual Local-Mode switch (branch handed to its server) — no change for it.
 */
class EdgeAuthorityService
{
    public const STANDBY = 'standby';
    public const LOCAL_ACTIVE = 'local_active';
    public const HANDING_BACK = 'handing_back';

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeAuthorityLeaseClient $client,
        private readonly EdgeLocalReadiness $readiness,
        private readonly EdgeOperationalBaselineService $baselines,
        private readonly EdgeSyncStatusService $sync,
    ) {
    }

    public function leaseModeEnabled(): bool
    {
        return trim((string) config('edge.authority.heartbeat_url', '')) !== '';
    }

    public function state(): string
    {
        $meta = $this->context->current();

        return (string) ($meta?->authority_state ?? self::STANDBY);
    }

    /** One heartbeat tick: send, record the ack (local clock) — or record the failure. Never throws on transport. */
    public function heartbeat(): array
    {
        $meta = $this->context->requireCurrent();
        $seq = (int) $meta->authority_heartbeat_seq + 1;
        try {
            $ack = $this->client->heartbeat($seq, $this->state());
        } catch (RuntimeException $e) {
            $meta->forceFill(['authority_last_failure_at' => now()])->save();

            return ['ok' => false, 'reason' => $e->getMessage(), 'state' => $this->state()];
        }
        $meta->forceFill([
            'authority_heartbeat_seq' => $seq,
            'authority_last_ack_at' => now(),                       // APPLIANCE clock, deliberately
            'authority_lease_ttl_seconds' => (int) ($ack['lease_ttl_seconds'] ?? config('edge.authority.ttl_seconds', 120)),
        ])->save();

        return ['ok' => true, 'holder' => $ack['holder'] ?? null, 'cloud_edge_state' => $ack['edge_state'] ?? null, 'state' => $this->state(), 'seq' => $seq];
    }

    /** The Cloud lease has lapsed on the appliance's own clock (TTL + skew margin after the last acknowledged beat). */
    public function cloudLeaseLapsedLocally(): bool
    {
        $meta = $this->context->current();
        if (! $meta || $meta->authority_last_ack_at === null) {
            return false; // never granted → nothing has lapsed → no takeover (fail closed)
        }
        $ttl = (int) ($meta->authority_lease_ttl_seconds ?: config('edge.authority.ttl_seconds', 120));
        $margin = max(0, (int) config('edge.authority.skew_margin_seconds', 30));
        $lastAck = \Illuminate\Support\Carbon::parse($meta->authority_last_ack_at);

        return now()->getTimestamp() >= $lastAck->getTimestamp() + $ttl + $margin;
    }

    /**
     * The readiness gates for a local takeover — every one must be true. Reported by name so the supervisor
     * (and the tests) see exactly which condition holds the appliance in standby.
     *
     * @return array<string, bool>
     */
    public function gates(): array
    {
        $meta = $this->context->current();
        $report = $this->readiness->report();
        $credentials = $meta ? DB::connection('tenant')->table('edge_local_user_credentials')
            ->where('branch_id', (int) $meta->branch_id)->where('activation_epoch', (int) $meta->activation_epoch)
            ->where('status', 'active')->count() : 0;

        return [
            'LOCAL_DB_HEALTHY' => ($report['local_database'] ?? null) === 'ready',
            'CONFIG_COMPATIBLE' => (bool) ($report['config_ready'] ?? false) && (int) ($meta?->last_applied_config_revision ?? 0) >= 1,
            'SCHEMA_COMPATIBLE' => $meta !== null && (string) $meta->bootstrap_schema === (string) config('edge.bootstrap_schema') && (string) $meta->config_schema_version === (string) config('edge.config_schema'),
            'BRANCH_BINDING_VALID' => ($report['bootstrap_binding'] ?? null) === 'ready' && $meta !== null && $meta->runtime_state === EdgeLocalMeta::STATE_BOOTSTRAPPED,
            'LOCAL_USERS_READY' => $credentials > 0,
            'STOCK_AUTHORITY_READY' => $this->baselines->currentAccepted() !== null,
            'ENTITLEMENT_VALID' => $this->leaseModeEnabled() && trim((string) config('edge.sync.device_id', '')) !== '' && $meta !== null && $meta->authority_last_ack_at !== null,
            'AUTHORITY_TAKEOVER_SAFE' => $this->cloudLeaseLapsedLocally(),
        ];
    }

    public function canTakeOver(): bool
    {
        return ! in_array(false, $this->gates(), true);
    }

    /**
     * Assume LOCAL authority. Pilot posture: a supervisor must confirm (config edge.authority.require_confirmation).
     * Fails closed on any gate; idempotent when already local_active.
     */
    public function takeOver(bool $confirmed, ?string $by = null): array
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('Local authority can only be assumed on a Branch Server.');
        }
        if (! $this->leaseModeEnabled()) {
            throw new RuntimeException('Lease mode is not provisioned on this appliance (no Cloud authority URL).');
        }
        if ((bool) config('edge.authority.require_confirmation', true) && ! $confirmed) {
            throw new RuntimeException('Supervisor confirmation is required to activate Local Mode.');
        }

        return DB::connection('tenant')->transaction(function () use ($by) {
            $meta = EdgeLocalMeta::on('tenant')->where('id', EdgeLocalMeta::SINGLETON)->lockForUpdate()->firstOrFail();
            if ((string) $meta->authority_state === self::LOCAL_ACTIVE) {
                return ['state' => self::LOCAL_ACTIVE, 'gates' => $this->gates(), 'already' => true];
            }
            $gates = $this->gates();
            $failed = array_keys(array_filter($gates, fn ($ok) => ! $ok));
            if ($failed !== []) {
                throw new RuntimeException('Local Mode cannot start — failing gates: ' . implode(', ', $failed));
            }
            $meta->forceFill([
                'authority_state' => self::LOCAL_ACTIVE,
                'authority_takeover_at' => now(),
                'authority_state_reason' => mb_substr('Cloud lease lapsed; local readiness verified' . ($by ? "; confirmed by {$by}" : ''), 0, 255),
            ])->save();

            return ['state' => self::LOCAL_ACTIVE, 'gates' => $gates, 'already' => false];
        });
    }

    /** Return authority to the Cloud — only with a clean outbox, only through the Cloud's acknowledgement. */
    public function handback(?string $by = null): array
    {
        $meta = $this->context->requireCurrent();
        if ((string) $meta->authority_state !== self::LOCAL_ACTIVE && (string) $meta->authority_state !== self::HANDING_BACK) {
            throw new RuntimeException('Nothing to hand back — the appliance is not the branch writer.');
        }
        $snap = $this->sync->snapshot();
        $pending = (int) ($snap['outbox']['pending'] ?? 0) + (int) ($snap['outbox']['leased'] ?? 0);
        $failed = (int) ($snap['outbox']['failed_permanent'] ?? 0);
        if ($pending > 0 || $failed > 0) {
            throw new RuntimeException("Handback refused — sync is not clean (pending {$pending}, needs attention {$failed}).");
        }
        $meta->forceFill(['authority_state' => self::HANDING_BACK, 'authority_state_reason' => 'handback in progress'])->save();
        try {
            $ack = $this->client->handback($pending, $failed);
        } catch (RuntimeException $e) {
            // stay handing_back (fenced on both sides) — never flip to standby on a failed call.
            throw new RuntimeException('Handback not acknowledged by the Cloud: ' . $e->getMessage());
        }
        if (($ack['holder'] ?? null) !== 'cloud') {
            throw new RuntimeException('Handback not accepted by the Cloud (holder ' . (string) ($ack['holder'] ?? '?') . ').');
        }
        $meta->forceFill([
            'authority_state' => self::STANDBY,
            'authority_last_ack_at' => now(),
            'authority_state_reason' => mb_substr('handed back to Cloud' . ($by ? " by {$by}" : ''), 0, 255),
        ])->save();

        return ['state' => self::STANDBY, 'cloud' => $ack];
    }

    /**
     * THE local fence: in lease mode the appliance mutates branch state only while it is the writer.
     * Without lease mode (manual Local Mode) this is a no-op — the existing hand-to-server switch governs.
     */
    public function assertLocalMutationAllowed(): void
    {
        if (! $this->leaseModeEnabled()) {
            return;
        }
        if ($this->state() !== self::LOCAL_ACTIVE) {
            throw new RuntimeException('Local Mode is not active — the Cloud POS is the active writer for this branch (standby).');
        }
    }

    /** Business-friendly state for the till: what the cashier should see. */
    public function cashierState(): array
    {
        if (! $this->leaseModeEnabled()) {
            return ['label' => 'ONLINE', 'mode' => 'manual', 'state' => $this->state()];
        }
        $state = $this->state();
        if ($state === self::LOCAL_ACTIVE) {
            return ['label' => 'LOCAL MODE ACTIVE', 'mode' => 'lease', 'state' => $state];
        }
        if ($state === self::HANDING_BACK) {
            return ['label' => 'RETURNING TO ONLINE', 'mode' => 'lease', 'state' => $state];
        }
        $meta = $this->context->current();
        if ($meta && $meta->authority_last_failure_at !== null && ($meta->authority_last_ack_at === null || $meta->authority_last_failure_at->gt($meta->authority_last_ack_at))) {
            return ['label' => $this->cloudLeaseLapsedLocally() ? 'PREPARING LOCAL MODE' : 'INTERNET CONNECTION LOST', 'mode' => 'lease', 'state' => $state];
        }

        return ['label' => 'ONLINE', 'mode' => 'lease', 'state' => $state];
    }
}
