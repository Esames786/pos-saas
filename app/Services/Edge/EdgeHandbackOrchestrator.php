<?php

namespace App\Services\Edge;

use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Edge\EdgeTableReservation;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OFFLINE EDGE — Q: the CONTROLLED HANDBACK orchestrator, built around the proven P handback protocol.
 *
 *   1 connectivity stable          2 Cloud branch still fenced (holder edge)
 *   3 offline paid sales drained   4 lost ACKs reconciled           5 no permanent failure
 *   6 reservation handback         7 active operational-state blockers (open tables / held or draft checks / open
 *                                    shifts) → HANDBACK_BLOCKED with explicit reasons — never silently discarded
 *   8 briefly fence local mutation (authority handing_back)   9 verify the Cloud still holds the appliance's lease
 *  10 Cloud acknowledges the handback   11 appliance standby   12 Cloud POS re-enabled (lease holder cloud)
 *  13 resume warm standby (pull the latest Cloud config/baseline)
 *
 * Steps 1–7 are an ASSESSMENT (read-only). Only when nothing blocks does the appliance fence itself; until then the
 * cashier keeps settling and closing on the appliance, which is the only way the blockers clear.
 */
class EdgeHandbackOrchestrator
{
    public const BLOCKED = 'blocked';
    public const HANDED_BACK = 'handed_back';
    public const FAILED = 'failed';

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeAuthorityService $authority,
        private readonly EdgeConnectionStateMachine $states,
        private readonly EdgeSyncStatusService $sync,
        private readonly EdgeReservationHandbackService $reservations,
        private readonly EdgeStandbyFreshnessService $freshness,
    ) {
    }

    /** @return array{ready:bool, blockers:array<int,array{code:string,detail:string}>, facts:array} */
    public function assess(): array
    {
        $meta = $this->context->requireCurrent();
        $blockers = [];
        $block = function (string $code, string $detail) use (&$blockers) {
            $blockers[] = ['code' => $code, 'detail' => $detail];
        };
        $auth = (string) $meta->authority_state;
        if ($auth !== EdgeAuthorityService::LOCAL_ACTIVE && $auth !== EdgeAuthorityService::HANDING_BACK) {
            $block('NOT_LOCAL_WRITER', 'the appliance is not the branch writer — nothing to hand back');
        }
        // 1 connectivity stable
        $minAcks = max(1, (int) config('edge.authority.handback_min_consecutive_acks', 2));
        if ((int) $meta->heartbeat_consecutive_failures > 0 || (int) $meta->heartbeat_consecutive_acks < $minAcks) {
            $block('CONNECTION_NOT_STABLE', "need {$minAcks} consecutive acknowledged heartbeats (have " . (int) $meta->heartbeat_consecutive_acks . ', failures ' . (int) $meta->heartbeat_consecutive_failures . ')');
        }
        // 2 Cloud still fenced for this branch
        if ((string) $meta->authority_cloud_holder_seen !== 'edge') {
            $block('CLOUD_NOT_FENCED', 'the Cloud does not report this appliance as the lease holder (' . ((string) $meta->authority_cloud_holder_seen ?: 'unknown') . ')');
        }
        // 3 / 5 drained, no permanent failure
        $snap = $this->sync->snapshot();
        $pending = (int) ($snap['outbox']['pending'] ?? 0) + (int) ($snap['outbox']['leased'] ?? 0);
        $failed = (int) ($snap['outbox']['failed_permanent'] ?? 0);
        if ($pending > 0) {
            $block('OUTBOX_PENDING', "{$pending} offline sale(s) still syncing");
        }
        if ($failed > 0) {
            $block('PERMANENT_SYNC_FAILURE', "{$failed} sale(s) need attention before the Cloud can take the branch back");
        }
        // F2 — supplier-finance events are money the Cloud has not yet posted officially: a pending supplier payment or
        // manual AP journal, a permanently failed one, or a local/Cloud divergence all block the handback explicitly.
        $finance = app(EdgeSupplierFinanceCacheService::class)->handbackFindings();
        $purchase = app(EdgePurchaseReturnCacheService::class)->handbackFindings();
        if ($purchase['pending'] > 0) {
            $block('PURCHASE_RETURN_PENDING', "{$purchase['pending']} purchase return(s) still syncing to the Cloud");
        }
        if ($purchase['failed'] > 0) {
            $block('PURCHASE_RETURN_PERMANENT_FAILURE', "{$purchase['failed']} purchase return(s) were refused by the Cloud and need a supervisor");
        }
        if ($purchase['divergent'] > 0) {
            $block('PURCHASE_RETURN_DIVERGENCE', "{$purchase['divergent']} purchase return(s) do not reconcile with the Cloud (" . implode('; ', $purchase['details']) . ')');
        }
        if ($finance['pending'] > 0) {
            $block('SUPPLIER_FINANCE_PENDING', "{$finance['pending']} supplier payment / AP journal event(s) still syncing to the Cloud");
        }
        if ($finance['failed'] > 0) {
            $block('SUPPLIER_FINANCE_PERMANENT_FAILURE', "{$finance['failed']} supplier-finance event(s) were refused by the Cloud and need a supervisor");
        }
        if ($finance['divergent'] > 0) {
            $block('SUPPLIER_FINANCE_DIVERGENCE', "{$finance['divergent']} supplier-finance event(s) do not reconcile with the Cloud (" . implode('; ', $finance['details']) . ')');
        }
        // 4 reconciliation clean since the connection was restored
        if ($meta->reconcile_clean_at === null) {
            $block('RECONCILIATION_NOT_CLEAN', 'the last reconciliation with the Cloud is not clean (or has not run since the connection returned)');
        }
        // 6 reservations that could not be projected (open/occupied tables) are surfaced now, not during the flip
        $branchId = (int) $meta->branch_id;
        $activeReservations = EdgeTableReservation::on('tenant')->where('branch_id', $branchId)->where('status', EdgeTableReservation::STATUS_ACTIVE)->get();
        foreach ($activeReservations as $r) {
            if (RestaurantTableSession::on('tenant')->where('restaurant_table_id', $r->restaurant_table_id)->whereIn('status', ['open', 'bill_requested'])->exists()) {
                $block('RESERVATION_ON_OPEN_TABLE', 'an active reservation sits on an open table — settle or cancel it first');
                break;
            }
        }
        // 7 active operational state — explicit, never discarded
        $openTables = RestaurantTableSession::on('tenant')->whereIn('status', ['open', 'bill_requested'])
            ->whereHas('table', fn ($q) => $q->where('branch_id', $branchId))->count();
        if ($openTables > 0) {
            $block('OPEN_TABLES', "{$openTables} table(s) still open — settle or close them on the appliance first");
        }
        $heldChecks = SalesOrder::on('tenant')->where('branch_id', $branchId)->where('status', 'held')->count();
        if ($heldChecks > 0) {
            $block('HELD_CHECKS', "{$heldChecks} held/draft check(s) unpaid — settle or cancel them on the appliance first");
        }
        $openShifts = Shift::on('tenant')->where('branch_id', $branchId)->where('status', 'open')->count();
        if ($openShifts > 0) {
            $block('OPEN_SHIFTS', "{$openShifts} shift(s) still open on the appliance — close them (the shift's cash belongs to this branch server)");
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'facts' => [
                'pending' => $pending, 'failed_permanent' => $failed, 'open_tables' => $openTables, 'held_checks' => $heldChecks, 'open_shifts' => $openShifts,
                'supplier_finance_pending' => $finance['pending'], 'supplier_finance_failed' => $finance['failed'], 'supplier_finance_divergent' => $finance['divergent'],
                'purchase_return_pending' => $purchase['pending'], 'purchase_return_failed' => $purchase['failed'], 'purchase_return_divergent' => $purchase['divergent'],
                'active_reservations' => $activeReservations->count(), 'consecutive_acks' => (int) $meta->heartbeat_consecutive_acks,
                'reconcile_clean_at' => $meta->reconcile_clean_at?->toIso8601String(), 'cloud_holder_seen' => $meta->authority_cloud_holder_seen,
            ],
        ];
    }

    /**
     * Run the controlled handback. Returns status: blocked (nothing changed; reason persisted) | handed_back |
     * failed (the appliance may be left handing_back — fenced on both sides — for a retry; never a second writer).
     */
    public function run(?string $by = null): array
    {
        $meta = $this->context->requireCurrent();
        $assessment = $this->assess();
        if (! $assessment['ready']) {
            $codes = implode(', ', array_column($assessment['blockers'], 'code'));
            $meta->forceFill(['handback_blocked_reason' => mb_substr('HANDBACK_BLOCKED: ' . $codes, 0, 500)])->save();
            $this->states->evaluate();

            return ['status' => self::BLOCKED, 'blockers' => $assessment['blockers'], 'facts' => $assessment['facts']];
        }
        $meta->forceFill(['handback_blocked_reason' => null])->save();

        // 6 reservations → canonical reserved_* (atomic, fail-closed; authority retained on failure)
        try {
            $reservations = $this->reservations->handback();
        } catch (Throwable $e) {
            $meta->forceFill(['handback_blocked_reason' => mb_substr('HANDBACK_BLOCKED: RESERVATION_HANDBACK — ' . $e->getMessage(), 0, 500)])->save();
            $this->states->evaluate();

            return ['status' => self::BLOCKED, 'blockers' => [['code' => 'RESERVATION_HANDBACK', 'detail' => $e->getMessage()]], 'facts' => $assessment['facts']];
        }

        // 8–12 the proven P protocol: fence locally (handing_back) → Cloud acknowledges → standby → Cloud POS re-enabled.
        // The fenced interval is persisted/audited as HANDING_BACK before the Cloud is asked.
        try {
            $result = $this->authority->handback($by, fn () => $this->states->evaluate());
        } catch (Throwable $e) {
            $this->states->evaluate();

            return ['status' => self::FAILED, 'error' => $e->getMessage(), 'authority_state' => $this->authority->state(), 'reservations' => $reservations];
        }

        // 13 resume warm standby: the Cloud position now includes every offline sale — pull config/baseline freshness.
        $fresh = $this->freshness->tick();
        $state = $this->states->evaluate();

        return ['status' => self::HANDED_BACK, 'cloud' => $result['cloud'] ?? null, 'reservations' => $reservations, 'freshness' => $fresh, 'state' => $state];
    }
}
