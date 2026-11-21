<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeSyncOutbox;
use Throwable;

/**
 * OFFLINE EDGE — Q: ONE tick of the supervised authority worker. Deterministic, restart-safe, bounded work:
 *
 *   1. heartbeat (P lease renewal; a failure is recorded, never acted on);
 *   2. connection state machine evaluation (persisted + audited);
 *   3. STANDBY and acknowledged → warm-standby freshness (config refresh when behind, stock baseline when behind);
 *      LOCAL and acknowledged → resume authenticated sync: drain a bounded batch of the outbox, and once drained run a
 *      reconciliation pass (lost ACKs recovered exactly-once; divergences surfaced, never repaired) — the appliance
 *      REMAINS the writer throughout;
 *   4. evaluate again so the persisted state reflects what this tick changed.
 *
 * Nothing here ever changes authority_state: takeover and handback are supervised actions.
 */
class EdgeAuthorityTick
{
    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeAuthorityService $authority,
        private readonly EdgeConnectionStateMachine $states,
        private readonly EdgeStandbyFreshnessService $freshness,
        private readonly EdgeSyncSender $sender,
        private readonly EdgeSyncReconciliationClient $reconClient,
        private readonly EdgeSyncReconciliationService $recon,
    ) {
    }

    /** @return array{heartbeat:array, state:array, work:array} */
    public function run(string $owner = 'authority-worker'): array
    {
        $hb = $this->authority->heartbeat();
        $before = $this->states->evaluate();
        $work = ['kind' => 'none'];

        if ($hb['ok'] && $this->authority->state() === EdgeAuthorityService::STANDBY) {
            $work = ['kind' => 'standby_freshness'] + $this->freshness->tick();
        } elseif ($hb['ok'] && $this->authority->state() === EdgeAuthorityService::LOCAL_ACTIVE) {
            $work = ['kind' => 'local_sync'] + $this->drainAndReconcile($owner);
        }

        $after = $this->states->evaluate();

        return ['heartbeat' => $hb, 'state' => $after, 'state_before_work' => $before['state'], 'work' => $work];
    }

    /**
     * Drain a bounded batch; when the outbox is fully drained run one reconciliation pass and record whether it is
     * clean. The reconciliation is READ-ONLY except for the exactly-once lost-ACK recovery.
     *
     * @return array{drained:int, outcomes:array<string,int>, reconciled:bool, clean:?bool, findings:array<string,int>, error:?string}
     */
    public function drainAndReconcile(string $owner): array
    {
        $batch = max(1, (int) config('edge.authority.drain_batch', 25));
        $outcomes = [];
        $sent = 0;
        for ($i = 0; $i < $batch; $i++) {
            try {
                $outcome = $this->sender->sendNext($owner);
            } catch (Throwable $e) {
                $outcomes['error'] = ($outcomes['error'] ?? 0) + 1;
                break;
            }
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
            if ($outcome === 'idle' || $outcome === 'retry' || $outcome === 'reject') {
                break; // nothing to send, or a transport problem — do not spin inside one tick
            }
            $sent++;
        }

        $meta = $this->context->requireCurrent();

        // Reconcile EVERY tick the Cloud answers: a lost ACK is, by definition, a row still pending/leased locally that
        // the Cloud already applied — recovered exactly once WITHOUT a repost. Divergences are surfaced, never repaired.
        $uuids = EdgeSyncOutbox::on('tenant')->orderByDesc('id')->limit(200)->pluck('sale_uuid')->all();
        try {
            $statuses = $uuids === [] ? [] : $this->reconClient->fetchStatuses($uuids);
            $findings = $this->recon->reconcile($statuses);
            $summary = [];
            $divergent = false;
            foreach ($findings as $f) {
                $class = (string) $f['classification'];
                $summary[$class] = ($summary[$class] ?? 0) + 1;
                if ($class === EdgeSyncReconciliationService::RECOVERABLE_LOST_ACK) {
                    $uuid = (string) $f['sale_uuid'];
                    $this->recon->recoverLostAck($uuid, array_merge(['sale_uuid' => $uuid], (array) ($statuses[$uuid] ?? [])), $owner);
                    $summary['recovered_lost_ack'] = ($summary['recovered_lost_ack'] ?? 0) + 1;
                } elseif ($class !== EdgeSyncReconciliationService::IN_SYNC && $class !== EdgeSyncReconciliationService::PENDING_UNSENT) {
                    $divergent = true; // hash divergence, terminal failure, orphan, local-ack-cloud-missing — never auto-repaired
                }
            }
        } catch (Throwable $e) {
            $meta->forceFill(['reconcile_clean_at' => null])->save();

            return ['drained' => $sent, 'outcomes' => $outcomes, 'reconciled' => false, 'clean' => null, 'findings' => [], 'error' => mb_substr($e->getMessage(), 0, 200)];
        }

        $open = EdgeSyncOutbox::on('tenant')->whereIn('state', [EdgeSyncOutbox::STATE_PENDING, EdgeSyncOutbox::STATE_LEASED])->count();
        $failed = EdgeSyncOutbox::on('tenant')->where('state', EdgeSyncOutbox::STATE_FAILED_PERMANENT)->count();
        $clean = $open === 0 && $failed === 0 && ! $divergent;
        $meta->forceFill(['reconcile_clean_at' => $clean ? now() : null])->save();

        return ['drained' => $sent, 'outcomes' => $outcomes, 'reconciled' => true, 'clean' => $clean, 'open' => $open, 'findings' => $summary, 'error' => null];
    }
}
