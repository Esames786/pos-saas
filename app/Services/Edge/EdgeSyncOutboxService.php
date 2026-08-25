<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OFFLINE-SYNC-ENGINE-1B — the Edge outbox authority: same-transaction envelope creation and the
 * LOCAL durable lease primitive the future 1D sender will drive. NO HTTP, NO Cloud calls, NO ACK
 * faking lives here — leased→acknowledged / leased→failed_permanent are guarded primitives that
 * only the future VERIFIED-ACK service (1D) may invoke with a real Cloud result.
 */
class EdgeSyncOutboxService
{
    public const CONN = 'tenant';
    public const DEFAULT_LEASE_SECONDS = 120;

    public function __construct(private readonly EdgeSaleEnvelopeBuilder $builder)
    {
    }

    /**
     * Create the immutable outbox row for a just-finalized PAID sale. MUST be called INSIDE the
     * sale-finalizing transaction (the central 1B invariant: a paid offline sale cannot exist
     * without its envelope, and a rolled-back sale leaves no envelope). Idempotent per sale_uuid:
     * a POS replay that returns the already-finalized sale never reaches this method; the unique
     * index is the last line of defense.
     */
    public function createForPaidSale(SalesOrder $sale): EdgeSyncOutbox
    {
        // Snapshot-consistent read INSIDE the sale transaction: this is the config revision that
        // governed the sale's own catalog/price resolution (REPEATABLE READ — a refresh committing
        // concurrently serializes on the meta row and never rewrites this envelope afterwards).
        $meta = EdgeLocalMeta::query()->where('singleton_guard', EdgeLocalMeta::SINGLETON)->first();
        if (! $meta) {
            throw new RuntimeException('OUTBOX_UNAVAILABLE: no appliance binding — cannot create a sync envelope.');
        }

        $envelope = $this->builder->build($sale, $meta);

        return EdgeSyncOutbox::create([
            'sale_uuid' => (string) $sale->sale_uuid,
            'envelope_schema_version' => (string) $envelope['envelope_schema_version'],
            'config_revision' => (int) $envelope['config_revision'],
            'activation_epoch' => (int) $envelope['activation_epoch'],
            'envelope' => $this->builder->canonicalEnvelopeJson($envelope),
            'content_hash' => (string) $envelope['content_hash'],
            'state' => EdgeSyncOutbox::STATE_PENDING,
        ]);
    }

    /**
     * Atomically lease the oldest eligible outbox row (pending, or leased with an EXPIRED lease) for
     * one sender. Single-statement claim: two racing workers can never own the same row. Returns the
     * claimed row or null when nothing is eligible. attempts increments on every claim;
     * first_sent_at records the FIRST lease-for-sending.
     */
    public function lease(string $owner, int $leaseSeconds = self::DEFAULT_LEASE_SECONDS): ?EdgeSyncOutbox
    {
        $token = $owner . ':' . (string) Str::ulid();     // unique per claim → unambiguous readback
        $conn = DB::connection(self::CONN);

        // Deadlock-free claim under genuine concurrency (proven by the two-process races): SELECT the oldest
        // eligible row FOR UPDATE **SKIP LOCKED** so each worker grabs a DISTINCT unlocked row (or none),
        // then UPDATE it by primary key. A plain `UPDATE ... ORDER BY id LIMIT 1` gap-locks the scanned range
        // and two simultaneous workers deadlock (InnoDB 1213); SKIP LOCKED is the canonical outbox pattern.
        return $conn->transaction(function () use ($conn, $token, $leaseSeconds) {
            $row = $conn->table('edge_sync_outbox')
                ->where(function ($q) {
                    $q->where('state', EdgeSyncOutbox::STATE_PENDING)
                        ->orWhere(function ($q2) {
                            $q2->where('state', EdgeSyncOutbox::STATE_LEASED)
                                ->whereNotNull('lease_expires_at')
                                ->where('lease_expires_at', '<=', now());
                        });
                })
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if (! $row) {
                return null;
            }

            $now = $conn->getDriverName() === 'sqlite' ? "datetime('now')" : 'NOW()';
            $conn->table('edge_sync_outbox')->where('id', $row->id)->update([
                'state' => EdgeSyncOutbox::STATE_LEASED,
                'lease_owner' => $token,
                'lease_expires_at' => now()->addSeconds($leaseSeconds),
                'attempts' => DB::raw('attempts + 1'),
                'first_sent_at' => DB::raw('COALESCE(first_sent_at, ' . $now . ')'),
                'updated_at' => now(),
            ]);

            return EdgeSyncOutbox::query()->whereKey($row->id)->firstOrFail();
        });
    }

    /** Retryable release: leased -> pending (the row becomes eligible again immediately). */
    public function releaseLease(EdgeSyncOutbox $row, ?string $error = null): void
    {
        if ($row->state !== EdgeSyncOutbox::STATE_LEASED) {
            throw new RuntimeException('OUTBOX_STATE: only a leased row can be released.');
        }
        $row->update([
            'state' => EdgeSyncOutbox::STATE_PENDING,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'last_error' => $error !== null ? mb_substr($error, 0, 2000) : $row->last_error,
        ]);
    }

    /**
     * leased -> acknowledged. 1D-ONLY primitive: the caller must hold a VERIFIED Cloud ACK
     * (sale_uuid + content_hash echo + Cloud ingestion identity). Nothing in 1B calls this from any
     * HTTP path — HTTP transport does not exist yet. The row is retained forever (append-only).
     */
    public function markAcknowledged(EdgeSyncOutbox $row, string $ackIngestionUuid, array $ackPayload): void
    {
        if ($row->state !== EdgeSyncOutbox::STATE_LEASED) {
            throw new RuntimeException('OUTBOX_STATE: only a leased row can be acknowledged.');
        }
        if (($ackPayload['sale_uuid'] ?? null) !== $row->sale_uuid
            || ! hash_equals((string) $row->content_hash, (string) ($ackPayload['content_hash'] ?? ''))) {
            throw new RuntimeException('OUTBOX_ACK_MISMATCH: the ACK does not identify this envelope (sale_uuid/content_hash).');
        }
        $row->update([
            'state' => EdgeSyncOutbox::STATE_ACKNOWLEDGED,
            'acknowledged_at' => now(),
            'ack_ingestion_uuid' => $ackIngestionUuid,
            'ack_payload' => $ackPayload,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'last_error' => null,
        ]);
    }

    /** leased -> failed_permanent. 1C/1D-ONLY: explicit terminal Cloud verdict (e.g. hash conflict). */
    public function markFailedPermanent(EdgeSyncOutbox $row, string $verdict): void
    {
        if ($row->state !== EdgeSyncOutbox::STATE_LEASED) {
            throw new RuntimeException('OUTBOX_STATE: only a leased row can be marked failed_permanent.');
        }
        $row->update([
            'state' => EdgeSyncOutbox::STATE_FAILED_PERMANENT,
            'last_error' => mb_substr($verdict, 0, 2000),
            'lease_owner' => null,
            'lease_expires_at' => null,
        ]);
    }
}
