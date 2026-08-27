<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeSyncOutbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OFFLINE-SYNC-ENGINE-1E — the operator/support SYNC STATUS read model (§D/§E/§T).
 *
 * A single, READ-ONLY, secret-free view of where the appliance stands: identity + binding, the outbox
 * depth by state, how far behind the oldest un-synced sale is, the last ACK / attempt / failure (in
 * business terms via EdgeSyncFailureClassifier), and the baseline-cutover state. It mutates nothing and
 * NEVER exposes the device secret, auth signature, or full envelope payloads — only enough business
 * context (sale reference, business date, Cloud sale number) to identify a sale.
 */
class EdgeSyncStatusService
{
    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeBaselineCutoverService $cutover,
    ) {
    }

    /** The headline status surface (§D) + machine/support diagnostics (§T). No secrets. */
    public function snapshot(): array
    {
        $meta = $this->context->tryCurrent();
        if ($meta === null) {
            return ['bound' => false];
        }

        $counts = $this->countsByState();
        $oldestPending = DB::connection('tenant')->table('edge_sync_outbox')
            ->where('state', EdgeSyncOutbox::STATE_PENDING)->min('created_at');
        $lastAck = DB::connection('tenant')->table('edge_sync_outbox')->max('acknowledged_at');
        $lastAttempt = DB::connection('tenant')->table('edge_sync_outbox')->max('first_sent_at');
        $lastFailureRow = DB::connection('tenant')->table('edge_sync_outbox')
            ->whereNotNull('last_error')
            ->orderByDesc('updated_at')->first();

        $lastFailure = null;
        if ($lastFailureRow !== null) {
            $classified = EdgeSyncFailureClassifier::classify($lastFailureRow->last_error);
            $lastFailure = [
                'class' => $classified['class'],
                'label' => $classified['label'],
                'action' => $classified['action'],
                'message' => mb_substr((string) $lastFailureRow->last_error, 0, 200),
                'at' => $this->iso($lastFailureRow->updated_at),
            ];
        }

        $cutover = $this->cutover->status();

        return [
            'bound' => true,
            'device_public_uuid' => (string) $meta->device_uuid, // public identity, NOT the secret
            'tenant_id' => (int) $meta->tenant_id,
            'branch_id' => (int) $meta->branch_id,
            'activation_epoch' => (int) $meta->activation_epoch,
            'config_revision' => (string) $meta->source_revision,
            'baseline' => [
                'state' => $cutover['state'] ?? null,
                'baseline_uuid' => $cutover['baseline_uuid'] ?? null,
                'baseline_revision' => $cutover['baseline_revision'] ?? null,
                'generation' => $cutover['generation'] ?? null,
                'selling_fenced' => $cutover['selling_fenced'] ?? null,
                'cutover_ready' => $cutover['cutover_ready'] ?? null,
            ],
            'outbox' => [
                'pending' => $counts[EdgeSyncOutbox::STATE_PENDING] ?? 0,
                'leased' => $counts[EdgeSyncOutbox::STATE_LEASED] ?? 0,
                'acknowledged' => $counts[EdgeSyncOutbox::STATE_ACKNOWLEDGED] ?? 0,
                'failed_permanent' => $counts[EdgeSyncOutbox::STATE_FAILED_PERMANENT] ?? 0,
            ],
            'oldest_pending_at' => $this->iso($oldestPending),
            'oldest_pending_age_seconds' => $oldestPending !== null ? max(0, now()->getTimestamp() - Carbon::parse($oldestPending)->getTimestamp()) : null,
            'last_ack_at' => $this->iso($lastAck),
            'last_send_attempt_at' => $this->iso($lastAttempt),
            'last_failure' => $lastFailure,
            // A plain-language safety headline the operator can trust at a glance.
            'local_selling_safe' => ($cutover['selling_fenced'] ?? false) === false,
        ];
    }

    /**
     * The queue drill-down (§E): one row per outbox envelope with enough business context to identify the
     * sale — never the full payload, never secrets. Newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function queue(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $rows = EdgeSyncOutbox::query()->orderByDesc('id')->limit($limit)->get();

        return $rows->map(function (EdgeSyncOutbox $row) {
            $env = $row->envelopeArray();
            $classified = EdgeSyncFailureClassifier::classify($row->last_error);
            $ack = $row->ack_payload ?? [];

            return [
                'sale_uuid' => (string) $row->sale_uuid,
                'local_reference' => $env['sale_no'] ?? ($env['reference'] ?? null),
                'business_date' => $env['business_date'] ?? null,
                'created_at' => $this->iso($row->created_at),
                'state' => (string) $row->state,
                'leased' => $row->state === EdgeSyncOutbox::STATE_LEASED,
                'lease_expires_at' => $this->iso($row->lease_expires_at),
                'attempts' => (int) $row->attempts,
                'requeue_count' => (int) $row->requeue_count,
                'first_sent_at' => $this->iso($row->first_sent_at),
                'content_hash_short' => mb_substr((string) $row->content_hash, 0, 12),
                'failure_class' => $classified['class'],
                'failure_label' => $classified['label'],
                'failure_action' => $classified['action'],
                'requeueable' => $row->state === EdgeSyncOutbox::STATE_FAILED_PERMANENT && $classified['requeueable'],
                // Cloud acknowledgement identity, only when acknowledged.
                'cloud_official_sale_no' => $ack['official_sale_no'] ?? null,
                'cloud_ingestion_uuid' => $row->ack_ingestion_uuid,
                'acknowledged_at' => $this->iso($row->acknowledged_at),
            ];
        })->all();
    }

    private function countsByState(): array
    {
        return DB::connection('tenant')->table('edge_sync_outbox')
            ->selectRaw('state, COUNT(*) as c')->groupBy('state')->pluck('c', 'state')
            ->map(fn ($c) => (int) $c)->all();
    }

    private function iso($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof Carbon ? $value->toIso8601String() : Carbon::parse($value)->toIso8601String();
    }
}
