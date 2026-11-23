<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\Shift;

/**
 * OFFLINE EDGE — F1: the immutable SALES-RETURN event (envelope schema `edge-return-envelope-v2`).
 *
 * Same wire strategy as the sale envelope: canonical JSON, `content_hash = sha256(canonicalJson(envelope minus
 * content_hash))`, one stable identity (`return_uuid`, ULID) that the Cloud registry keys on. Once queued the
 * commercial content is immutable; the Cloud recomputes the return through its OFFICIAL authority and refuses any
 * envelope whose refund does not equal what it computes.
 *
 * v2 (REFUND-SHIFT-H1, owner decision 22 Oct 2026): the event names the PROCESSING shift — the local shift whose
 * drawer paid the refund — by its STABLE identity only (`processing_shift` = shift_uuid + terminal + business day +
 * open facts). `actor.shift_id` (a numeric LOCAL id, meaningless on the Cloud) is gone: "do not use numeric local
 * shift IDs across systems". The Cloud still ingests v1 (appliances built before H1) — see
 * EdgeInboundReturnIngestionService::resolveProcessingShift for the v1 limitation.
 */
class EdgeReturnEnvelopeBuilder
{
    public const SCHEMA = 'edge-return-envelope-v2';

    /** The pre-H1 generation: no processing shift, `actor.shift_id` = a local numeric id. Accepted by the Cloud, never emitted. */
    public const SCHEMA_V1 = 'edge-return-envelope-v1';

    /**
     * @param  array{kind:string, cloud_sales_order_id:?int, sale_uuid:?string}  $origin
     * @param  array<int,array<string,mixed>>  $lines  per-line commercial content (already computed by the canonical formula)
     * @param  array<string,mixed>|null  $approval  the local manager-approval audit (null when the branch auto-approves)
     * @param  array<string,mixed>  $freshness  returnable-cache watermark/as_of that validated a Cloud-sale return
     * @param  Shift|null  $processingShift  the local shift that PAID the refund (null only for a refund that moved no drawer)
     */
    public function build(SalesReturn $return, SalesOrder $sale, EdgeLocalMeta $meta, array $origin, array $lines, ?array $approval, array $actor, array $freshness, ?Shift $processingShift = null): array
    {
        $envelope = [
            'envelope_schema_version' => self::SCHEMA,
            'event_type' => 'sales_return',
            'return_uuid' => (string) $return->edge_return_uuid,
            'tenant_id' => (int) $meta->tenant_id,
            'tenant_code' => (string) $meta->tenant_code,
            'branch_id' => (int) $meta->branch_id,
            'device_public_uuid' => (string) $meta->device_uuid,
            'activation_epoch' => (int) $meta->activation_epoch,
            'config_revision' => (int) ($meta->last_applied_config_revision ?? 0),
            'original' => [
                'kind' => (string) $origin['kind'],                                   // cloud | edge
                'cloud_sales_order_id' => $origin['cloud_sales_order_id'] !== null ? (int) $origin['cloud_sales_order_id'] : null,
                'sale_uuid' => $origin['sale_uuid'] !== null ? (string) $origin['sale_uuid'] : null,
                'sale_no' => (string) $sale->sale_no,
            ],
            'return_no' => (string) $return->return_no,                                // local label; the Cloud mints its own
            'return_date' => optional($return->return_date)->toIso8601String(),
            'business_date' => $return->business_date ? \Illuminate\Support\Carbon::parse($return->business_date)->toDateString() : null,
            'reason' => $return->reason,
            'refund_method' => (string) $return->refund_method,
            'refund_amount' => round((float) $return->refund_amount, 2),
            'lines' => array_values(array_map(fn ($l) => [
                'return_line_uuid' => (string) $l['return_line_uuid'],
                'cloud_sales_order_line_id' => $l['cloud_sales_order_line_id'] !== null ? (int) $l['cloud_sales_order_line_id'] : null,
                'line_uuid' => $l['line_uuid'] !== null ? (string) $l['line_uuid'] : null,
                'product_id' => (int) $l['product_id'],
                'product_variant_id' => $l['product_variant_id'] !== null ? (int) $l['product_variant_id'] : null,
                'quantity' => round((float) $l['quantity'], 3),
                'unit_code' => $l['unit_code'] !== null ? (string) $l['unit_code'] : null,
                'unit_price' => round((float) $l['unit_price'], 2),
                'discount_amount' => round((float) $l['discount_amount'], 2),
                'tax_amount' => round((float) $l['tax_amount'], 2),
                'line_total' => round((float) $l['line_total'], 2),
            ], $lines)),
            'totals' => [
                'subtotal' => round((float) $return->subtotal, 2),
                'discount_amount' => round((float) $return->discount_amount, 2),
                'tax_amount' => round((float) $return->tax_amount, 2),
                'delivery_charge_amount' => round((float) $return->delivery_charge_amount, 2),
                'grand_total' => round((float) $return->grand_total, 2),
            ],
            'approval' => $approval,
            // v2: no `shift_id` here — a numeric local shift id must never cross systems (REFUND-SHIFT-H1).
            'actor' => ['user_id' => (int) $actor['user_id'], 'employee_code' => $actor['employee_code'] ?? null, 'terminal_id' => $actor['terminal_id'] ?? null],
            // v2: the shift that PAID the refund, by stable identity only (the Cloud resolves `shift_uuid`; the rest are facts).
            'processing_shift' => $processingShift ? [
                'shift_uuid' => (string) $processingShift->shift_uuid,
                'terminal_id' => (int) $processingShift->terminal_id,
                'business_date' => $processingShift->business_date ? \Illuminate\Support\Carbon::parse($processingShift->business_date)->toDateString() : null,
                'opened_at' => optional($processingShift->opened_at)->toIso8601String(),
                'opened_by_user_id' => (int) $processingShift->opened_by_user_id,
            ] : null,
            'freshness' => [
                'returnable_cache_watermark' => $freshness['watermark'] ?? null,
                'returnable_cache_as_of' => $freshness['as_of'] ?? null,
            ],
            'created_at' => now()->toIso8601String(),
        ];
        $envelope['content_hash'] = hash('sha256', EdgeCanonicalJson::encode($envelope));

        return $envelope;
    }

    public function canonicalEnvelopeJson(array $envelope): string
    {
        return EdgeCanonicalJson::encode($envelope);
    }
}
