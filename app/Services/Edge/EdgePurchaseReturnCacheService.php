<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeSyncOutbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OFFLINE EDGE — F3: the appliance's READ-ONLY warm purchase-return projection + its own pending events.
 *
 *   LOCAL RETURNABLE (per received line) = quantity_received − official Cloud returns (as of the projection)
 *                                          − the local purchase-return lines whose event is NOT in the applied set.
 * An ACK before the next refresh keeps subtracting the event; the refresh that lists it as applied stops subtracting it
 * exactly when the Cloud's returned quantity includes it — never a double return, never an over-return. Mutation paths
 * read the line's pending quantity as a LOCKING read after locking the projected line row (two terminals serialise).
 *
 * Freshness: the same rule as the other warm caches (cached watermark == last advertised, or received strictly after
 * the last acknowledged heartbeat). Stale → every purchase return fails closed; the appliance never guesses.
 */
class EdgePurchaseReturnCacheService
{
    public const T_GRNS = 'edge_purchase_return_grns';
    public const T_LINES = 'edge_purchase_return_grn_lines';
    public const T_APPLIED = 'edge_purchase_return_applied_events';
    public const T_EVENTS = 'edge_local_purchase_return_events';
    public const T_EVENT_LINES = 'edge_local_purchase_return_lines';

    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    public function apply(array $package): array
    {
        $meta = $this->context->requireCurrent();
        $now = now();
        $asOf = isset($package['as_of']) ? Carbon::parse($package['as_of']) : $now;
        $stats = [];
        DB::connection('tenant')->transaction(function () use ($package, $meta, $now, $asOf, &$stats) {
            $conn = DB::connection('tenant');
            $conn->table(self::T_LINES)->delete();
            $conn->table(self::T_GRNS)->delete();
            $grnRows = [];
            $lineRows = [];
            foreach ((array) ($package['grns'] ?? []) as $g) {
                $grnRows[] = [
                    'cloud_grn_id' => (int) $g['cloud_grn_id'], 'grn_no' => (string) $g['grn_no'], 'branch_id' => (int) $g['branch_id'], 'status' => (string) ($g['status'] ?? 'posted'),
                    'receipt_date' => $g['receipt_date'] ?? null, 'notes' => $g['notes'] ?? null,
                    'cloud_supplier_id' => (int) $g['cloud_supplier_id'], 'supplier_code' => $g['supplier_code'] ?? null, 'supplier_name' => $g['supplier_name'] ?? null, 'supplier_status' => $g['supplier_status'] ?? null,
                    'cloud_bill_id' => $g['cloud_bill_id'] ?? null, 'bill_no' => $g['bill_no'] ?? null,
                    'cloud_updated_at' => ! empty($g['updated_at']) ? Carbon::parse($g['updated_at']) : null, 'refreshed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
                foreach ((array) ($g['lines'] ?? []) as $l) {
                    $lineRows[] = [
                        'cloud_grn_line_id' => (int) $l['cloud_grn_line_id'], 'cloud_grn_id' => (int) $g['cloud_grn_id'],
                        'product_id' => (int) $l['product_id'], 'product_variant_id' => $l['product_variant_id'] ?? null, 'product_name' => (string) ($l['product_name'] ?? ''),
                        'variant_name' => $l['variant_name'] ?? null, 'unit_code' => $l['unit_code'] ?? null, 'batch_no' => $l['batch_no'] ?? null, 'expiry_date' => $l['expiry_date'] ?? null,
                        'quantity_received' => round((float) $l['quantity_received'], 3), 'cloud_returned_quantity' => round((float) ($l['cloud_returned_quantity'] ?? 0), 3),
                        'unit_cost' => round((float) ($l['unit_cost'] ?? 0), 4), 'refreshed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
            foreach (array_chunk($grnRows, 200) as $chunk) {
                $conn->table(self::T_GRNS)->insert($chunk);
            }
            foreach (array_chunk($lineRows, 200) as $chunk) {
                $conn->table(self::T_LINES)->insert($chunk);
            }
            $conn->table(self::T_APPLIED)->delete();
            $applied = array_map(fn ($e) => ['event_uuid' => (string) $e['event_uuid'], 'official_return_no' => $e['official_return_no'] ?? null,
                'applied_at' => ! empty($e['applied_at']) ? Carbon::parse($e['applied_at']) : null, 'refreshed_at' => $now, 'created_at' => $now, 'updated_at' => $now], (array) ($package['applied_events'] ?? []));
            foreach (array_chunk($applied, 200) as $chunk) {
                $conn->table(self::T_APPLIED)->insert($chunk);
            }
            $stats = ['grns' => count($grnRows), 'lines' => count($lineRows), 'applied_events' => count($applied)];
            $meta->forceFill([
                'purchase_return_cache_watermark' => (string) ($package['watermark'] ?? ''),
                'purchase_return_cache_as_of' => $asOf,
                'purchase_return_cache_refreshed_at' => $now,
                'purchase_return_rules' => json_encode(['reason_codes' => $package['reason_codes'] ?? [], 'permissions' => $package['permissions'] ?? [], 'rules' => $package['rules'] ?? []]),
            ])->save();
        });

        return $stats;
    }

    public function freshness(): array
    {
        $meta = $this->context->current();
        if (! $meta) {
            return ['ok' => false, 'reasons' => ['appliance not bound'], 'watermark' => null, 'as_of' => null];
        }
        $seen = $meta->standby_purchase_return_watermark_seen !== null ? (string) $meta->standby_purchase_return_watermark_seen : null;
        $cached = $meta->purchase_return_cache_watermark !== null && $meta->purchase_return_cache_watermark !== '' ? (string) $meta->purchase_return_cache_watermark : null;
        $asOf = $meta->purchase_return_cache_as_of ? Carbon::parse($meta->purchase_return_cache_as_of) : null;
        $lastAck = $meta->authority_last_ack_at ? Carbon::parse($meta->authority_last_ack_at) : null;
        $reasons = [];
        $ok = false;
        if ($cached === null) {
            $reasons[] = 'no purchase-return information has been received from the Cloud';
        } elseif ($seen === null) {
            $reasons[] = 'the Cloud never advertised a purchase-return watermark';
        } elseif ($cached === $seen) {
            $ok = true;
        } elseif ($asOf !== null && $lastAck !== null && $asOf->greaterThan($lastAck)) {
            $ok = true;
        } else {
            $reasons[] = 'the purchase-return information does not equal the last advertised Cloud position';
        }

        return ['ok' => $ok, 'reasons' => $reasons, 'watermark' => $cached, 'as_of' => $asOf?->toIso8601String()];
    }

    public function rules(): array
    {
        $meta = $this->context->current();
        $stored = $meta && $meta->purchase_return_rules ? json_decode((string) $meta->purchase_return_rules, true) : null;

        return [
            'reason_codes' => (array) ($stored['reason_codes'] ?? ['damaged', 'expired', 'wrong_item', 'over_supply', 'quality_issue', 'price_dispute', 'other']),
            'permissions' => (array) ($stored['permissions'] ?? ['store' => 'tenant.purchase-returns.store', 'post' => 'tenant.purchase-returns.post']),
            'source_receipt_required_offline' => true,
        ];
    }

    // ── lookups ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function grn(int $cloudGrnId, bool $lock = false): ?object
    {
        $q = DB::connection('tenant')->table(self::T_GRNS)->where('cloud_grn_id', $cloudGrnId);

        return $lock ? $q->lockForUpdate()->first() : $q->first();
    }

    public function line(int $cloudGrnLineId, bool $lock = false): ?object
    {
        $q = DB::connection('tenant')->table(self::T_LINES)->where('cloud_grn_line_id', $cloudGrnLineId);

        return $lock ? $q->lockForUpdate()->first() : $q->first();
    }

    /** Σ pending local return quantity per received line (events the Cloud has NOT applied). */
    public function pendingByLine(array $cloudGrnLineIds, bool $lock = false): array
    {
        if ($cloudGrnLineIds === []) {
            return [];
        }
        $rows = DB::connection('tenant')->table(self::T_EVENT_LINES . ' as l')
            ->whereIn('l.cloud_grn_line_id', $cloudGrnLineIds)
            ->whereNotIn('l.event_uuid', fn ($q) => $q->select('event_uuid')->from(self::T_APPLIED))
            ->groupBy('l.cloud_grn_line_id')->selectRaw('l.cloud_grn_line_id, SUM(l.quantity) as q, COUNT(DISTINCT l.event_uuid) as events')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->cloud_grn_line_id] = ['quantity' => (float) $r->q, 'events' => (int) $r->events];
        }

        return $out;
    }

    public function lines(int $cloudGrnId, bool $lock = false): array
    {
        $rows = DB::connection('tenant')->table(self::T_LINES)->where('cloud_grn_id', $cloudGrnId)->orderBy('cloud_grn_line_id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $pending = $this->pendingByLine($rows->pluck('cloud_grn_line_id')->map(fn ($v) => (int) $v)->all(), $lock);

        return $rows->map(function ($l) use ($pending) {
            $p = $pending[(int) $l->cloud_grn_line_id] ?? ['quantity' => 0.0, 'events' => 0];
            $returnable = round((float) $l->quantity_received - (float) $l->cloud_returned_quantity - $p['quantity'], 3);

            return [
                'cloud_grn_line_id' => (int) $l->cloud_grn_line_id, 'product_id' => (int) $l->product_id, 'product_variant_id' => $l->product_variant_id !== null ? (int) $l->product_variant_id : null,
                'product_name' => (string) $l->product_name, 'variant_name' => $l->variant_name, 'unit_code' => $l->unit_code, 'batch_no' => $l->batch_no, 'expiry_date' => $l->expiry_date,
                'quantity_received' => round((float) $l->quantity_received, 3), 'cloud_returned_quantity' => round((float) $l->cloud_returned_quantity, 3),
                'pending_local_quantity' => round($p['quantity'], 3), 'pending_events' => $p['events'],
                'already_returned' => round((float) $l->cloud_returned_quantity + $p['quantity'], 3),
                'returnable' => max(0.0, $returnable), 'unit_cost' => round((float) $l->unit_cost, 4),
            ];
        })->values()->all();
    }

    public function grns(?int $cloudSupplierId = null): array
    {
        $rows = DB::connection('tenant')->table(self::T_GRNS)->when($cloudSupplierId !== null, fn ($q) => $q->where('cloud_supplier_id', $cloudSupplierId))
            ->orderByDesc('receipt_date')->orderByDesc('cloud_grn_id')->get();

        return $rows->map(function ($g) {
            $lines = $this->lines((int) $g->cloud_grn_id);

            return $this->grnView($g) + ['line_count' => count($lines), 'returnable_total' => round(array_sum(array_column($lines, 'returnable')), 3)];
        })->values()->all();
    }

    public function grnView(object $g): array
    {
        return [
            'cloud_grn_id' => (int) $g->cloud_grn_id, 'grn_no' => (string) $g->grn_no, 'branch_id' => (int) $g->branch_id, 'status' => (string) $g->status, 'receipt_date' => $g->receipt_date, 'notes' => $g->notes,
            'cloud_supplier_id' => (int) $g->cloud_supplier_id, 'supplier_code' => $g->supplier_code, 'supplier_name' => $g->supplier_name, 'supplier_status' => $g->supplier_status,
            'cloud_bill_id' => $g->cloud_bill_id !== null ? (int) $g->cloud_bill_id : null, 'bill_no' => $g->bill_no,
        ];
    }

    public function suppliers(): array
    {
        return DB::connection('tenant')->table(self::T_GRNS)->select('cloud_supplier_id', 'supplier_code', 'supplier_name', 'supplier_status')->distinct()->orderBy('supplier_name')->get()
            ->map(fn ($s) => ['cloud_supplier_id' => (int) $s->cloud_supplier_id, 'code' => $s->supplier_code, 'name' => $s->supplier_name, 'status' => $s->supplier_status])->values()->all();
    }

    public function isApplied(string $eventUuid): bool
    {
        return DB::connection('tenant')->table(self::T_APPLIED)->where('event_uuid', $eventUuid)->exists();
    }

    // ── local events ─────────────────────────────────────────────────────────────────────────────────────────────

    public function recordEvent(array $event, array $lines): void
    {
        $conn = DB::connection('tenant');
        $now = now();
        $conn->table(self::T_EVENTS)->insert([
            'event_uuid' => $event['event_uuid'], 'cloud_grn_id' => (int) $event['cloud_grn_id'], 'cloud_supplier_id' => (int) $event['cloud_supplier_id'], 'branch_id' => (int) $event['branch_id'],
            'terminal_id' => $event['terminal_id'] ?? null, 'user_id' => $event['user_id'] ?? null, 'return_date' => $event['return_date'], 'reason_code' => $event['reason_code'] ?? null,
            'notes' => $event['notes'] ?? null, 'grand_total' => round((float) $event['grand_total'], 4), 'payload' => json_encode($event['payload']),
            'envelope_schema_version' => $event['envelope_schema_version'], 'content_hash' => $event['content_hash'], 'projection_watermark' => $event['projection_watermark'] ?? null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($lines as $l) {
            $conn->table(self::T_EVENT_LINES)->insert([
                'event_uuid' => $event['event_uuid'], 'line_uuid' => $l['line_uuid'], 'cloud_grn_line_id' => (int) $l['cloud_grn_line_id'], 'product_id' => (int) $l['product_id'],
                'product_variant_id' => $l['product_variant_id'] ?? null, 'quantity' => round((float) $l['quantity'], 3), 'unit_cost' => round((float) $l['unit_cost'], 4),
                'line_total' => round((float) $l['line_total'], 4), 'reason_code' => $l['reason_code'] ?? null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function syncStateFor(string $eventUuid): array
    {
        $row = EdgeSyncOutbox::on('tenant')->where('sale_uuid', $eventUuid)->first();
        $applied = $this->isApplied($eventUuid);
        $state = match (true) {
            $applied => 'official',
            $row === null => 'missing',
            $row->state === EdgeSyncOutbox::STATE_ACKNOWLEDGED => 'synced',
            $row->state === EdgeSyncOutbox::STATE_FAILED_PERMANENT => 'failed',
            default => 'pending',
        };
        $labels = [
            'pending' => 'PENDING SYNC — recorded on this branch server; the Cloud has not posted it yet',
            'synced' => 'POSTED AT CLOUD — official; the projection refresh will show it',
            'official' => 'OFFICIAL — posted at the Cloud',
            'failed' => 'REFUSED BY THE CLOUD — needs a supervisor',
            'missing' => 'NOT QUEUED — integrity problem, needs a supervisor',
        ];
        $ack = $row?->ack_payload;
        $ack = is_string($ack) ? json_decode($ack, true) : $ack;

        return [
            'state' => $state, 'label' => $labels[$state], 'outbox_state' => $row?->state, 'attempts' => $row ? (int) $row->attempts : 0, 'last_error' => $row?->last_error,
            'official_return_no' => is_array($ack) ? ($ack['official_return_no'] ?? null) : null,
            'acknowledged_at' => $row && $row->acknowledged_at ? Carbon::parse($row->acknowledged_at)->toIso8601String() : null,
        ];
    }

    public function event(string $eventUuid): ?array
    {
        $row = DB::connection('tenant')->table(self::T_EVENTS)->where('event_uuid', $eventUuid)->first();

        return $row ? $this->eventView($row) : null;
    }

    public function eventView(object $row): array
    {
        $lines = DB::connection('tenant')->table(self::T_EVENT_LINES)->where('event_uuid', $row->event_uuid)->orderBy('id')->get()
            ->map(fn ($l) => ['line_uuid' => (string) $l->line_uuid, 'cloud_grn_line_id' => (int) $l->cloud_grn_line_id, 'product_id' => (int) $l->product_id,
                'product_variant_id' => $l->product_variant_id !== null ? (int) $l->product_variant_id : null, 'quantity' => round((float) $l->quantity, 3),
                'unit_cost' => round((float) $l->unit_cost, 4), 'line_total' => round((float) $l->line_total, 4), 'reason_code' => $l->reason_code])->values()->all();

        return [
            'event_uuid' => (string) $row->event_uuid, 'event_type' => EdgePurchaseReturnEnvelopeBuilder::EVENT, 'cloud_grn_id' => (int) $row->cloud_grn_id, 'cloud_supplier_id' => (int) $row->cloud_supplier_id,
            'return_date' => (string) $row->return_date, 'reason_code' => $row->reason_code, 'notes' => $row->notes, 'grand_total' => round((float) $row->grand_total, 4),
            'payload' => json_decode((string) $row->payload, true), 'lines' => $lines, 'content_hash' => (string) $row->content_hash, 'projection_watermark' => $row->projection_watermark,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(), 'user_id' => $row->user_id !== null ? (int) $row->user_id : null, 'sync' => $this->syncStateFor((string) $row->event_uuid),
        ];
    }

    public function recentEvents(int $limit = 50): array
    {
        return DB::connection('tenant')->table(self::T_EVENTS)->orderByDesc('id')->limit($limit)->get()->map(fn ($r) => $this->eventView($r))->values()->all();
    }

    /** Handback: pending / permanently failed / divergent purchase-return events (same discipline as supplier finance). */
    public function handbackFindings(): array
    {
        $conn = DB::connection('tenant');
        $schema = EdgePurchaseReturnEnvelopeBuilder::SCHEMA;
        $pending = EdgeSyncOutbox::on('tenant')->where('envelope_schema_version', $schema)->whereIn('state', [EdgeSyncOutbox::STATE_PENDING, EdgeSyncOutbox::STATE_LEASED])->count();
        $failed = EdgeSyncOutbox::on('tenant')->where('envelope_schema_version', $schema)->where('state', EdgeSyncOutbox::STATE_FAILED_PERMANENT)->count();
        $details = [];
        $divergent = 0;
        $meta = $this->context->current();
        $asOf = $meta && $meta->purchase_return_cache_as_of ? Carbon::parse($meta->purchase_return_cache_as_of) : null;
        $events = $conn->table(self::T_EVENTS)->orderBy('id')->get(['event_uuid']);
        $outbox = EdgeSyncOutbox::on('tenant')->whereIn('sale_uuid', $events->pluck('event_uuid')->all())->get()->keyBy('sale_uuid');
        $applied = $conn->table(self::T_APPLIED)->whereIn('event_uuid', $events->pluck('event_uuid')->all())->pluck('event_uuid')->flip();
        foreach ($events as $ev) {
            $uuid = (string) $ev->event_uuid;
            $row = $outbox[$uuid] ?? null;
            if ($row === null) {
                $divergent++;
                $details[] = "purchase return {$uuid} has no outbox row";
                continue;
            }
            if ($row->state === EdgeSyncOutbox::STATE_ACKNOWLEDGED) {
                $ack = is_string($row->ack_payload) ? json_decode($row->ack_payload, true) : $row->ack_payload;
                $status = is_array($ack) ? (string) ($ack['status'] ?? '') : '';
                if (! in_array($status, ['applied', 'already_applied'], true) || (string) ($ack['event_uuid'] ?? $ack['sale_uuid'] ?? '') !== $uuid) {
                    $divergent++;
                    $details[] = "purchase return {$uuid} acknowledged without an applied verdict";
                    continue;
                }
                $ackedAt = $row->acknowledged_at ? Carbon::parse($row->acknowledged_at) : null;
                if ($asOf !== null && $ackedAt !== null && $asOf->greaterThan($ackedAt) && ! isset($applied[$uuid])) {
                    $divergent++;
                    $details[] = "purchase return {$uuid} was acknowledged but the Cloud projection taken later does not list it as applied";
                }
            }
        }

        return ['pending' => $pending, 'failed' => $failed, 'divergent' => $divergent, 'details' => $details];
    }
}
