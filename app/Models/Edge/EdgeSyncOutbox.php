<?php

namespace App\Models\Edge;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * OFFLINE-SYNC-ENGINE-1B — one append-only outbox row per PAID offline sale (Edge-local DB only).
 *
 * IMMUTABILITY (guarded in booted()): sale_uuid / envelope / content_hash / config_revision /
 * activation_epoch / envelope_schema_version never change after insert. Only delivery-state
 * metadata transitions, and only along the allowed state machine:
 *
 *   pending  -> leased                       (sender lease; EdgeSyncOutboxService::lease)
 *   leased   -> pending                      (retryable release / lease expiry)
 *   leased   -> acknowledged                 (ONLY via a verified Cloud ACK — 1D; primitive exists)
 *   leased   -> failed_permanent             (ONLY for an explicit terminal Cloud verdict)
 *   acknowledged / failed_permanent -> (terminal; never re-leased, never deleted-on-ack)
 *
 * The raw-SQL lease claim in EdgeSyncOutboxService enforces the same transitions in its WHERE
 * clause; this model guard is defense-in-depth for every Eloquent write path.
 */
class EdgeSyncOutbox extends Model
{
    protected $connection = 'tenant';

    protected $table = 'edge_sync_outbox';

    public const STATE_PENDING = 'pending';
    public const STATE_LEASED = 'leased';
    public const STATE_ACKNOWLEDGED = 'acknowledged';
    public const STATE_FAILED_PERMANENT = 'failed_permanent';

    /**
     * Allowed state transitions (see class doc). Expiry reclaim is release+lease, not a new edge.
     *
     * OFFLINE-SYNC-ENGINE-1E: failed_permanent -> pending is the SANCTIONED supervisor REQUEUE (§H). It is
     * performed ONLY by EdgeSyncOutboxService::requeueFailedPermanent, which refuses non-requeuable failure
     * classes (hash conflict / wrong binding / stale activation / unsupported schema / invalid payload) and
     * records the requeue audit. The state edge existing here is defence-in-depth for that one authority; no
     * other code path may un-terminate a row, and acknowledged remains strictly terminal.
     */
    public const TRANSITIONS = [
        self::STATE_PENDING => [self::STATE_LEASED],
        self::STATE_LEASED => [self::STATE_PENDING, self::STATE_ACKNOWLEDGED, self::STATE_FAILED_PERMANENT],
        self::STATE_ACKNOWLEDGED => [],
        self::STATE_FAILED_PERMANENT => [self::STATE_PENDING],
    ];

    /** The immutable envelope fields — frozen at creation, never rewritten. */
    public const IMMUTABLE_FIELDS = [
        'sale_uuid', 'envelope', 'content_hash', 'config_revision', 'activation_epoch', 'envelope_schema_version',
    ];

    protected $guarded = [];

    protected $casts = [
        'config_revision' => 'integer',
        'activation_epoch' => 'integer',
        'attempts' => 'integer',
        'requeue_count' => 'integer',
        'lease_expires_at' => 'datetime',
        'first_sent_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'last_requeued_at' => 'datetime',
        'ack_payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($row->isDirty($field)) {
                    throw new RuntimeException("edge_sync_outbox.[{$field}] is immutable once the envelope is created.");
                }
            }
            if ($row->isDirty('state')) {
                $from = (string) $row->getOriginal('state');
                $to = (string) $row->state;
                if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                    throw new RuntimeException("Invalid edge_sync_outbox state transition [{$from} -> {$to}].");
                }
            }
        });

        static::deleting(function () {
            // Append-only, durable audit: no delete path exists in V1 (no delete-on-ack design).
            throw new RuntimeException('edge_sync_outbox rows are append-only and must not be deleted.');
        });
    }

    /** The decoded immutable envelope. */
    public function envelopeArray(): array
    {
        return json_decode((string) $this->envelope, true) ?: [];
    }
}
