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
        private readonly EdgeStandbyFreshnessService $freshness,
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
            // Q: a failure is RECORDED (counters drive the connection state), never acted on. A lost connection also
            // invalidates any earlier "reconciliation clean" — it must be re-proven once the Cloud answers again.
            $meta->forceFill([
                'authority_last_failure_at' => now(),
                'heartbeat_consecutive_failures' => (int) $meta->heartbeat_consecutive_failures + 1,
                'heartbeat_consecutive_acks' => 0,
                'reconcile_clean_at' => null,
            ])->save();

            return ['ok' => false, 'reason' => $e->getMessage(), 'state' => $this->state(), 'consecutive_failures' => (int) $meta->heartbeat_consecutive_failures];
        }
        $meta->forceFill([
            'authority_heartbeat_seq' => $seq,
            'authority_last_ack_at' => now(),                       // APPLIANCE clock, deliberately
            'authority_lease_ttl_seconds' => (int) ($ack['lease_ttl_seconds'] ?? config('edge.authority.ttl_seconds', 120)),
            'heartbeat_consecutive_failures' => 0,
            'heartbeat_consecutive_acks' => (int) $meta->heartbeat_consecutive_acks + 1,
            'authority_cloud_holder_seen' => isset($ack['holder']) ? (string) $ack['holder'] : $meta->authority_cloud_holder_seen,
            // Q — WARM STANDBY FRESHNESS: what the Cloud advertised on this beat (the appliance's freshness targets).
            'standby_config_revision_seen' => isset($ack['cloud_config_revision']) ? (int) $ack['cloud_config_revision'] : $meta->standby_config_revision_seen,
            'standby_stock_watermark_seen' => isset($ack['stock_watermark']) ? (string) $ack['stock_watermark'] : $meta->standby_stock_watermark_seen,
            'standby_stock_as_of_seen' => isset($ack['stock_as_of']) ? \Illuminate\Support\Carbon::parse($ack['stock_as_of']) : $meta->standby_stock_as_of_seen,
            // F1 — the returnable-sale watermark the Cloud advertised (the return cache's freshness target).
            'standby_returnable_watermark_seen' => isset($ack['returnable_watermark']) ? (string) $ack['returnable_watermark'] : $meta->standby_returnable_watermark_seen,
            'standby_returnable_as_of_seen' => isset($ack['returnable_as_of']) ? \Illuminate\Support\Carbon::parse($ack['returnable_as_of']) : $meta->standby_returnable_as_of_seen,
        ])->save();

        return [
            'ok' => true, 'holder' => $ack['holder'] ?? null, 'cloud_edge_state' => $ack['edge_state'] ?? null, 'state' => $this->state(), 'seq' => $seq,
            'consecutive_acks' => (int) $meta->heartbeat_consecutive_acks,
            'advertised' => ['config_revision' => $ack['cloud_config_revision'] ?? null, 'stock_watermark' => $ack['stock_watermark'] ?? null, 'stock_as_of' => $ack['stock_as_of'] ?? null],
        ];
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
            // Q — WARM STANDBY FRESHNESS: the standby provably equals the Cloud's last advertised config revision and
            // official-stock position (or holds a baseline issued after the last acknowledged heartbeat).
            'STANDBY_FRESH_ENOUGH' => $this->freshness->freshEnough()['ok'],
        ];
    }

    /** The freshness proof behind the STANDBY_FRESH_ENOUGH gate (facts + reasons), for the supervisor and the audit. */
    public function freshness(): array
    {
        return $this->freshness->freshEnough();
    }

    public function canTakeOver(): bool
    {
        return ! in_array(false, $this->gates(), true);
    }

    /**
     * Assume LOCAL authority. Pilot posture: a supervisor must confirm (config edge.authority.require_confirmation).
     * Fails closed on any gate; idempotent when already local_active.
     */
    public function takeOver(bool $confirmed, ?string $by = null, bool $acceptStale = false, ?string $staleReason = null): array
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
        if ($acceptStale && trim((string) $staleReason) === '') {
            throw new RuntimeException('Accepting a stale standby requires a supervisor reason (audited).');
        }

        $result = DB::connection('tenant')->transaction(function () use ($by, $acceptStale, $staleReason) {
            $meta = EdgeLocalMeta::on('tenant')->where('id', EdgeLocalMeta::SINGLETON)->lockForUpdate()->firstOrFail();
            if ((string) $meta->authority_state === self::LOCAL_ACTIVE) {
                return ['state' => self::LOCAL_ACTIVE, 'gates' => $this->gates(), 'already' => true];
            }
            $gates = $this->gates();
            $freshness = $this->freshness->freshEnough();
            $failed = array_keys(array_filter($gates, fn ($ok) => ! $ok));
            // Q — freshness is the ONE gate a supervisor may consciously override (audited reason), never the others.
            $staleAccepted = false;
            if ($failed === ['STANDBY_FRESH_ENOUGH'] && $acceptStale) {
                $failed = [];
                $staleAccepted = true;
            }
            if ($failed !== []) {
                $detail = in_array('STANDBY_FRESH_ENOUGH', $failed, true) && $freshness['reasons'] !== [] ? ' [' . implode('; ', $freshness['reasons']) . ']' : '';
                throw new RuntimeException('Local Mode cannot start — failing gates: ' . implode(', ', $failed) . $detail);
            }
            $meta->forceFill([
                'authority_state' => self::LOCAL_ACTIVE,
                'authority_takeover_at' => now(),
                'authority_state_reason' => mb_substr('Cloud lease lapsed; local readiness verified' . ($by ? "; confirmed by {$by}" : '') . ($staleAccepted ? '; STALE STANDBY ACCEPTED: ' . trim((string) $staleReason) : ''), 0, 255),
                // The provable freshness at the moment of takeover — what the local selling position is based on.
                'authority_takeover_freshness' => json_encode([
                    'fresh' => $freshness['ok'],
                    'stale_accepted' => $staleAccepted,
                    'stale_reason' => $staleAccepted ? trim((string) $staleReason) : null,
                    'confirmed_by' => $by,
                    'reasons' => $freshness['reasons'],
                    // F1 — RETURN_CACHE_WATERMARK at takeover: which returnable-sale truth offline returns validate against.
                    'return_cache_watermark' => $meta->returnable_cache_watermark,
                    'return_cache_as_of' => $meta->returnable_cache_as_of ? \Illuminate\Support\Carbon::parse($meta->returnable_cache_as_of)->toIso8601String() : null,
                    'return_cache_advertised' => $meta->standby_returnable_watermark_seen,
                    'return_cache_fresh' => app(EdgeReturnableSaleCacheService::class)->freshness()['ok'],
                ] + $freshness['facts']),
            ])->save();

            return ['state' => self::LOCAL_ACTIVE, 'gates' => $gates, 'already' => false, 'freshness' => $freshness, 'stale_accepted' => $staleAccepted];
        });
        // Q: an authority change is persisted as a connection-state transition at once (restart-safe, audited).
        app(EdgeConnectionStateMachine::class)->evaluate();

        return $result;
    }

    /**
     * Return authority to the Cloud — only with a clean outbox, only through the Cloud's acknowledgement.
     * $afterFence runs once the appliance has fenced itself (handing_back) and BEFORE the Cloud is asked — the Q
     * orchestrator uses it to persist/audit the HANDING_BACK connection state while both sides are fenced.
     */
    public function handback(?string $by = null, ?callable $afterFence = null): array
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
        if ($afterFence !== null) {
            $afterFence();
        }
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
            'authority_cloud_holder_seen' => 'cloud',
            'reconcile_clean_at' => null,
            'handback_blocked_reason' => null,
            'authority_state_reason' => mb_substr('handed back to Cloud' . ($by ? " by {$by}" : ''), 0, 255),
        ])->save();
        app(EdgeConnectionStateMachine::class)->evaluate();

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

    /**
     * Business-friendly state for the till: what the cashier should see. Q: derived by the connection state machine
     * from the persisted facts (no internals: no timestamps, uuids, hashes, epochs, baseline identifiers).
     */
    public function cashierState(): array
    {
        if (! $this->leaseModeEnabled()) {
            return ['label' => 'ONLINE', 'mode' => 'manual', 'state' => $this->state(), 'connection' => EdgeConnectionStateMachine::ONLINE];
        }
        $derived = app(EdgeConnectionStateMachine::class)->derive();

        return ['label' => EdgeConnectionStateMachine::label($derived['state']), 'mode' => 'lease', 'state' => $this->state(), 'connection' => $derived['state'], 'pending' => $derived['pending']];
    }
}
