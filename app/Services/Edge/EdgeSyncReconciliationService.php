<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeSyncOutbox;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * OFFLINE-SYNC-ENGINE-1E — reconcile the LOCAL outbox truth against the CLOUD ingestion truth.
 *
 * The sender (1D) already converges the happy path: a re-sent envelope whose ACK was lost comes back
 * `already_applied` and acknowledges the row. Reconciliation exists for the cases the sender alone cannot
 * express to an operator: a row the appliance never re-sent, a Cloud row with no local twin, and — the one
 * that must NEVER be auto-repaired destructively — a hash that diverges from the first accepted Cloud truth.
 *
 * This service performs NO HTTP and NO Cloud posting. The caller supplies a Cloud ingestion-status snapshot
 * (an authenticated Cloud query in production; in-process in tests). Recovery of a lost ACK is driven ONLY
 * through the existing owner-guarded outbox authority (lease -> markAcknowledged) using Cloud's OWN stored
 * ACK — it re-posts nothing, and a stale worker can never bypass the lease ownership guard. A divergent hash
 * is classified and surfaced, never mutated: the first accepted Cloud truth is authoritative.
 *
 * Cloud-status snapshot shape, keyed by sale_uuid:
 *   [ 'status' => applied|conflict|refused|exception, 'content_hash' => string, 'ingestion_uuid' => ?string,
 *     'official_sale_no' => ?string, 'branch_id' => ?int, 'device_public_uuid' => ?string,
 *     'activation_epoch' => ?int, 'ack_payload' => ?array ]
 */
class EdgeSyncReconciliationService
{
    /** Local acknowledged, Cloud applied, same hash — the steady state, nothing to do. */
    public const IN_SYNC = 'in_sync';

    /** Local NOT acknowledged (pending / lease-expired), Cloud applied SAME hash — safe lost-ACK recovery. */
    public const RECOVERABLE_LOST_ACK = 'recoverable_lost_ack';

    /** Local failed_permanent, Cloud applied SAME hash — the appliance gave up but Cloud truly applied.
     *  Recovery needs a supervisor requeue first (a terminal row is never silently un-terminated). */
    public const TERMINAL_LOCAL_CLOUD_APPLIED = 'terminal_local_cloud_applied';

    /** Local present, Cloud has no accepted row yet — normal in-flight; the sender will deliver it. */
    public const PENDING_UNSENT = 'pending_unsent';

    /** Cloud accepted a DIFFERENT content_hash for this sale_uuid — hard divergence, never auto-repaired. */
    public const HASH_DIVERGENCE = 'hash_divergence';

    /** Cloud terminally refused/conflicted this sale_uuid — surface the Cloud verdict, do not repost. */
    public const CLOUD_TERMINAL_FAILURE = 'cloud_terminal_failure';

    /** Local acknowledged, but Cloud registry has no row for it — divergence to investigate, never mutate. */
    public const LOCAL_ACK_CLOUD_MISSING = 'local_ack_cloud_missing';

    /** Cloud has an ingestion for this device/branch with no retained local outbox row — orphan to surface. */
    public const CLOUD_ORPHAN = 'cloud_orphan';

    public function __construct(
        private readonly EdgeSyncOutboxService $outbox,
    ) {
    }

    /**
     * Correlate every local outbox row (and every Cloud row) against the supplied Cloud snapshot.
     * Returns one classified finding per sale_uuid. This is READ-ONLY: it mutates nothing. The caller
     * decides which safe recoveries to run via recoverLostAck().
     *
     * @param  array<string,array>  $cloudStatuses  keyed by sale_uuid (see class doc)
     * @return array<int,array{sale_uuid:string,classification:string,local_state:?string,cloud_status:?string,detail:string}>
     */
    public function reconcile(array $cloudStatuses): array
    {
        $findings = [];
        $seenCloud = [];

        foreach (EdgeSyncOutbox::query()->orderBy('id')->get() as $row) {
            $saleUuid = (string) $row->sale_uuid;
            $cloud = $cloudStatuses[$saleUuid] ?? null;
            $seenCloud[$saleUuid] = true;

            $findings[] = $this->classify($row, $cloud);
        }

        // Cloud rows with no local outbox twin (a lost/rebuilt local DB, or a foreign-origin row).
        foreach ($cloudStatuses as $saleUuid => $cloud) {
            if (isset($seenCloud[$saleUuid])) {
                continue;
            }
            $findings[] = [
                'sale_uuid' => (string) $saleUuid,
                'classification' => self::CLOUD_ORPHAN,
                'local_state' => null,
                'cloud_status' => $cloud['status'] ?? null,
                'detail' => 'Cloud has an ingestion for this sale with no retained local outbox row.',
            ];
        }

        return $findings;
    }

    /** Classify a single local row against its Cloud twin (or absence). */
    private function classify(EdgeSyncOutbox $row, ?array $cloud): array
    {
        $state = (string) $row->state;
        $localHash = (string) $row->content_hash;
        $base = [
            'sale_uuid' => (string) $row->sale_uuid,
            'local_state' => $state,
            'cloud_status' => $cloud['status'] ?? null,
        ];

        if ($cloud === null) {
            if ($state === EdgeSyncOutbox::STATE_ACKNOWLEDGED) {
                return $base + [
                    'classification' => self::LOCAL_ACK_CLOUD_MISSING,
                    'detail' => 'Local row is acknowledged but the Cloud registry has no matching ingestion — investigate before any action.',
                ];
            }

            return $base + [
                'classification' => self::PENDING_UNSENT,
                'detail' => 'Not yet accepted by Cloud; the sender will deliver this envelope.',
            ];
        }

        $cloudStatus = (string) ($cloud['status'] ?? '');
        $cloudHash = (string) ($cloud['content_hash'] ?? '');

        if ($cloudStatus === 'applied') {
            if (! hash_equals($localHash, $cloudHash)) {
                return $base + [
                    'classification' => self::HASH_DIVERGENCE,
                    'detail' => 'Cloud accepted a DIFFERENT content hash for this sale. The first Cloud truth is authoritative; this must be reconciled by a supervisor, never overwritten.',
                ];
            }
            if ($state === EdgeSyncOutbox::STATE_ACKNOWLEDGED) {
                return $base + ['classification' => self::IN_SYNC, 'detail' => 'Local and Cloud agree.'];
            }
            if ($state === EdgeSyncOutbox::STATE_FAILED_PERMANENT) {
                return $base + [
                    'classification' => self::TERMINAL_LOCAL_CLOUD_APPLIED,
                    'detail' => 'This appliance parked the sale as permanently failed, but Cloud applied it. A supervisor requeue lets reconciliation acknowledge it — no effects are reposted.',
                ];
            }

            // pending or lease-expired -> safe lost-ACK recovery through the normal owner-guarded authority.
            return $base + [
                'classification' => self::RECOVERABLE_LOST_ACK,
                'detail' => 'Cloud applied this exact envelope; the local ACK was lost. Safe to acknowledge locally (no repost).',
            ];
        }

        if ($cloudStatus === 'conflict' || $cloudStatus === 'refused') {
            return $base + [
                'classification' => self::CLOUD_TERMINAL_FAILURE,
                'detail' => 'Cloud terminally ' . $cloudStatus . 'ed this sale. Surface the Cloud verdict; do not repost.',
            ];
        }

        // exception / anything non-terminal on the Cloud side -> still in flight.
        return $base + [
            'classification' => self::PENDING_UNSENT,
            'detail' => 'Cloud has not reached a terminal accepted state; the sender may retry.',
        ];
    }

    /**
     * Safe lost-ACK recovery for ONE row: acknowledge the local outbox row from Cloud's OWN stored ACK,
     * driven entirely through the existing owner-guarded outbox authority. Re-posts NOTHING.
     *
     * Guards (fail closed):
     *   - the row must currently be leasable (pending or lease-expired) — a terminal failed_permanent is
     *     refused here; it requires an explicit supervisor requeue first;
     *   - the Cloud ACK must be `applied` and its content_hash must equal the local row's immutable hash —
     *     a divergent hash is refused (never overwrite the first Cloud truth);
     *   - acknowledgement goes through EdgeSyncOutboxService::markAcknowledged, whose §18 lease-owner guard
     *     means a stale worker can never use reconciliation to bypass lease ownership.
     *
     * Returns 'acknowledged' on success. Throws on any guard violation.
     */
    public function recoverLostAck(string $saleUuid, array $cloudAck, string $owner = 'reconciler'): string
    {
        if ((string) ($cloudAck['status'] ?? '') !== 'applied') {
            throw new RuntimeException('RECONCILE_NOT_APPLIED: Cloud has not applied this sale; refusing to acknowledge locally.');
        }
        if ((string) ($cloudAck['sale_uuid'] ?? '') !== $saleUuid) {
            throw new RuntimeException('RECONCILE_IDENTITY: the Cloud ACK does not identify this sale.');
        }

        $row = EdgeSyncOutbox::query()->where('sale_uuid', $saleUuid)->first();
        if (! $row) {
            throw new RuntimeException('RECONCILE_NO_LOCAL_ROW: no local outbox row for this sale_uuid.');
        }
        if ($row->state === EdgeSyncOutbox::STATE_ACKNOWLEDGED) {
            return 'acknowledged'; // already converged — idempotent
        }
        if ($row->state === EdgeSyncOutbox::STATE_FAILED_PERMANENT) {
            throw new RuntimeException('RECONCILE_TERMINAL: a permanently-failed row must be requeued by a supervisor before recovery.');
        }
        if (! hash_equals((string) $row->content_hash, (string) ($cloudAck['content_hash'] ?? ''))) {
            throw new RuntimeException('RECONCILE_HASH_DIVERGENCE: Cloud accepted a different content hash; refusing to acknowledge (first Cloud truth is authoritative).');
        }

        // Take a targeted lease on the (pending / lease-expired) row, then acknowledge from Cloud's own ACK.
        $leased = $this->outbox->leaseSpecific($saleUuid, $owner);
        if (! $leased) {
            throw new RuntimeException('RECONCILE_UNLEASABLE: the target row is not currently leasable (held by a live worker); retry later.');
        }

        $ingestionUuid = (string) ($cloudAck['ingestion_uuid'] ?? '');
        $this->outbox->markAcknowledged($leased, $ingestionUuid, $cloudAck);

        return 'acknowledged';
    }
}
