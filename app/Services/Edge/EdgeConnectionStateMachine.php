<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use Illuminate\Support\Facades\DB;

/**
 * OFFLINE EDGE — Q: the CONNECTION STATE MACHINE (appliance side).
 *
 * This is NOT a second authority. Who may write the branch is decided ONLY by the P lease (edge_local_meta
 * .authority_state on the appliance, edge_branch_authority_leases on the Cloud). The connection state is a
 * deterministic function of persisted facts — the authority state, the heartbeat health counters, the local lease
 * lapse, the sync outbox and the reconciliation bookkeeping — so a process restart re-derives the same state from
 * the same facts and can never create a second writer. Every change is persisted with a reason and audited.
 *
 *   ONLINE ──(consecutive failed heartbeats ≥ unstable)──▶ CONNECTION_UNSTABLE ──(≥ lost)──▶ CONNECTION_LOST
 *     ──(lease lapsed locally: TTL + skew margin)──▶ PREPARING_LOCAL ──(supervised takeover)──▶ LOCAL_ACTIVE
 *   LOCAL_ACTIVE ──(heartbeat acknowledged again)──▶ CONNECTION_RESTORED ──(stable)──▶ SYNCING ──(outbox drained)──▶
 *     RECONCILING ──(clean + supervised handback)──▶ HANDING_BACK ──(Cloud acknowledged)──▶ ONLINE
 *
 * One failed heartbeat is a blip: the state stays ONLINE. Reconnect never leaves LOCAL: the appliance remains the
 * writer through CONNECTION_RESTORED / SYNCING / RECONCILING until the controlled handback.
 */
class EdgeConnectionStateMachine
{
    public const ONLINE = 'online';
    public const CONNECTION_UNSTABLE = 'connection_unstable';
    public const CONNECTION_LOST = 'connection_lost';
    public const PREPARING_LOCAL = 'preparing_local';
    public const LOCAL_ACTIVE = 'local_active';
    public const CONNECTION_RESTORED = 'connection_restored';
    public const SYNCING = 'syncing';
    public const RECONCILING = 'reconciling';
    public const HANDING_BACK = 'handing_back';

    /** Cashier-facing labels — business words only, never internals. */
    public const LABELS = [
        self::ONLINE => 'ONLINE',
        self::CONNECTION_UNSTABLE => 'INTERNET CONNECTION UNSTABLE',
        self::CONNECTION_LOST => 'INTERNET CONNECTION LOST',
        self::PREPARING_LOCAL => 'PREPARING LOCAL MODE',
        self::LOCAL_ACTIVE => 'LOCAL MODE ACTIVE',
        self::CONNECTION_RESTORED => 'CONNECTION RESTORED',
        self::SYNCING => 'SYNCHRONIZING',
        self::RECONCILING => 'SYNCHRONIZING',
        self::HANDING_BACK => 'RETURNING TO ONLINE',
    ];

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeAuthorityService $authority,
        private readonly EdgeSyncStatusService $sync,
    ) {
    }

    public static function label(string $state): string
    {
        return self::LABELS[$state] ?? 'ONLINE';
    }

    /**
     * Derive the connection state from the persisted facts — pure, no writes.
     *
     * @return array{state:string, reason:string, handback_ready:bool, pending:int, needs_attention:int}
     */
    public function derive(): array
    {
        $meta = $this->context->current();
        if (! $meta || ! $this->authority->leaseModeEnabled()) {
            return ['state' => self::ONLINE, 'reason' => 'lease mode not provisioned (manual Local Mode governs)', 'handback_ready' => false, 'pending' => 0, 'needs_attention' => 0];
        }
        $snap = $this->sync->snapshot();
        $pending = (int) ($snap['outbox']['pending'] ?? 0) + (int) ($snap['outbox']['leased'] ?? 0);
        $failed = (int) ($snap['outbox']['failed_permanent'] ?? 0);
        $failures = (int) $meta->heartbeat_consecutive_failures;
        $acks = (int) $meta->heartbeat_consecutive_acks;
        $auth = (string) $meta->authority_state;
        $out = fn (string $state, string $reason, bool $ready = false) => ['state' => $state, 'reason' => $reason, 'handback_ready' => $ready, 'pending' => $pending, 'needs_attention' => $failed];

        if ($auth === EdgeAuthorityService::HANDING_BACK) {
            return $out(self::HANDING_BACK, 'authority handback in progress — fenced on both sides');
        }

        if ($auth === EdgeAuthorityService::LOCAL_ACTIVE) {
            if ($failures > 0 || $meta->authority_last_ack_at === null) {
                return $out(self::LOCAL_ACTIVE, $failures > 0 ? "Cloud unreachable ({$failures} consecutive failed heartbeats)" : 'local writer');
            }
            // The WAN is back. The appliance REMAINS the writer; the Cloud stays fenced (holder edge) until handback.
            $minAcks = max(1, (int) config('edge.authority.handback_min_consecutive_acks', 2));
            if ($acks < $minAcks) {
                return $out(self::CONNECTION_RESTORED, "connection restored — confirming stability ({$acks}/{$minAcks} acknowledged heartbeats)");
            }
            if ($pending > 0) {
                return $out(self::SYNCING, "{$pending} offline sale(s) syncing to the Cloud");
            }
            if ($failed > 0) {
                return $out(self::RECONCILING, "{$failed} sale(s) need attention before handback");
            }
            if ($meta->reconcile_clean_at === null) {
                return $out(self::RECONCILING, 'outbox drained — reconciling with the Cloud');
            }

            return $out(self::RECONCILING, 'sync clean — ready for the controlled handback', true);
        }

        // STANDBY — the Cloud is the writer.
        if ($failures === 0) {
            return $out(self::ONLINE, 'heartbeats acknowledged');
        }
        if ($this->authority->cloudLeaseLapsedLocally()) {
            return $out(self::PREPARING_LOCAL, 'Cloud lease lapsed on the appliance clock — readiness gates decide; supervisor confirmation required');
        }
        $lost = max(2, (int) config('edge.authority.lost_after_failures', 4));
        $unstable = max(1, (int) config('edge.authority.unstable_after_failures', 2));
        if ($failures >= $lost) {
            return $out(self::CONNECTION_LOST, "{$failures} consecutive failed heartbeats — lease still live, no takeover yet");
        }
        if ($failures >= $unstable) {
            return $out(self::CONNECTION_UNSTABLE, "{$failures} consecutive failed heartbeats");
        }

        return $out(self::ONLINE, 'one failed heartbeat — a blip, not a failure');
    }

    /**
     * Derive, then PERSIST the state (with reason and an audit transition row) when it changed. Idempotent.
     *
     * @return array{state:string, label:string, since:?string, reason:string, changed:bool, handback_ready:bool, pending:int, needs_attention:int}
     */
    public function evaluate(): array
    {
        $derived = $this->derive();
        $meta = $this->context->current();
        $changed = false;
        if ($meta) {
            $previous = (string) ($meta->connection_state ?: self::ONLINE);
            if ($previous !== $derived['state'] || $meta->connection_state_since === null) {
                DB::connection('tenant')->transaction(function () use ($meta, $previous, $derived) {
                    $locked = EdgeLocalMeta::on('tenant')->where('id', $meta->id)->lockForUpdate()->first();
                    if ((string) ($locked->connection_state ?: self::ONLINE) === $derived['state'] && $locked->connection_state_since !== null) {
                        return; // another evaluator got there first
                    }
                    $locked->forceFill([
                        'connection_state' => $derived['state'],
                        'connection_state_since' => now(),
                        'connection_state_reason' => mb_substr($derived['reason'], 0, 255),
                    ])->save();
                    DB::connection('tenant')->table('edge_local_connection_transitions')->insert([
                        'from_state' => $previous,
                        'to_state' => $derived['state'],
                        'authority_state' => (string) $locked->authority_state,
                        'reason' => mb_substr($derived['reason'], 0, 500),
                        'occurred_at' => now(),
                    ]);
                });
                $changed = true;
                $meta = $this->context->current();
            }
        }

        return [
            'state' => $derived['state'],
            'label' => self::label($derived['state']),
            'since' => $meta?->connection_state_since?->toIso8601String(),
            'reason' => $derived['reason'],
            'changed' => $changed,
            'handback_ready' => $derived['handback_ready'],
            'pending' => $derived['pending'],
            'needs_attention' => $derived['needs_attention'],
        ];
    }

    /** The persisted state as last evaluated (what survived the restart), without re-deriving. */
    public function persisted(): array
    {
        $meta = $this->context->current();
        $state = (string) ($meta?->connection_state ?: self::ONLINE);

        return ['state' => $state, 'label' => self::label($state), 'since' => $meta?->connection_state_since?->toIso8601String(), 'reason' => (string) ($meta?->connection_state_reason ?? '')];
    }
}
