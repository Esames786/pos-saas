<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;

/**
 * OFFLINE EDGE — F3 PURCHASE RETURN PARITY: the immutable PURCHASE_RETURN event.
 *
 * Bound to the supplier, the ORIGINAL goods receipt (the canonical source of a purchase return: received quantities and
 * unit costs live on goods_receipt_lines), the source line identities and quantities, the business date, the operator,
 * the branch/device, the projection watermark it validated against, and a content hash. Same transport contract as
 * sales / returns / supplier finance: content_hash = sha256(canonicalJson(envelope − hash)); the outbox row's `sale_uuid`
 * carries the event_uuid; the schema version routes the sender to the Cloud purchase-return ingestion, which posts the
 * OFFICIAL return exactly once through the existing PurchaseReturnService (stock OUT, supplier subledger, AP / GL).
 */
class EdgePurchaseReturnEnvelopeBuilder
{
    public const SCHEMA = 'edge-purchase-return-envelope-v1';
    public const EVENT = 'purchase_return';

    public function __construct(private readonly EdgeBootstrapService $canonical)
    {
    }

    /**
     * @param array $return cloud_grn_id, grn_no, cloud_supplier_id, supplier_code, supplier_name, return_date (Y-m-d), reason_code,
     *                      notes, cloud_bill_id, bill_no, lines[] {line_uuid, cloud_grn_line_id, product_id, product_variant_id,
     *                      product_name, unit_code, quantity, unit_cost, line_total, reason_code}
     */
    public function build(EdgeLocalMeta $meta, string $eventUuid, array $return, array $actor, array $freshness): array
    {
        $lines = array_values(array_map(fn ($l) => [
            'line_uuid' => (string) $l['line_uuid'],
            'cloud_grn_line_id' => (int) $l['cloud_grn_line_id'],
            'product_id' => (int) $l['product_id'],
            'product_variant_id' => ! empty($l['product_variant_id']) ? (int) $l['product_variant_id'] : null,
            'product_name' => (string) ($l['product_name'] ?? ''),
            'unit_code' => isset($l['unit_code']) ? (string) $l['unit_code'] : null,
            'quantity' => round((float) $l['quantity'], 3),
            'unit_cost' => round((float) $l['unit_cost'], 4),
            'line_total' => round((float) $l['line_total'], 4),
            'reason_code' => ! empty($l['reason_code']) ? (string) $l['reason_code'] : null,
        ], $return['lines']));

        $envelope = [
            'envelope_schema_version' => self::SCHEMA,
            'event_type' => self::EVENT,
            'event_uuid' => $eventUuid,
            'tenant_id' => (int) $meta->tenant_id,
            'tenant_code' => (string) $meta->tenant_code,
            'branch_id' => (int) $meta->branch_id,
            'device_public_uuid' => (string) $meta->device_uuid,
            'activation_epoch' => (int) $meta->activation_epoch,
            'config_revision' => (int) ($meta->last_applied_config_revision ?? 0),
            'supplier' => [
                'cloud_supplier_id' => (int) $return['cloud_supplier_id'],
                'code' => (string) ($return['supplier_code'] ?? ''),
                'name' => (string) ($return['supplier_name'] ?? ''),
            ],
            'goods_receipt' => [
                'cloud_grn_id' => (int) $return['cloud_grn_id'],
                'grn_no' => (string) ($return['grn_no'] ?? ''),
                'cloud_bill_id' => ! empty($return['cloud_bill_id']) ? (int) $return['cloud_bill_id'] : null,
                'bill_no' => ! empty($return['bill_no']) ? (string) $return['bill_no'] : null,
            ],
            'return_date' => (string) $return['return_date'],
            'business_date' => (string) $return['return_date'],
            'reason_code' => ! empty($return['reason_code']) ? (string) $return['reason_code'] : null,
            'notes' => isset($return['notes']) && trim((string) $return['notes']) !== '' ? trim((string) $return['notes']) : null,
            'lines' => $lines,
            'totals' => ['grand_total' => round(array_sum(array_column($lines, 'line_total')), 4), 'quantity' => round(array_sum(array_column($lines, 'quantity')), 3)],
            'actor' => ['user_id' => (int) $actor['user_id'], 'employee_code' => $actor['employee_code'] ?? null, 'terminal_id' => isset($actor['terminal_id']) ? (int) $actor['terminal_id'] : null],
            'freshness' => ['purchase_return_watermark' => $freshness['watermark'] ?? null, 'purchase_return_as_of' => $freshness['as_of'] ?? null],
            'created_at' => now()->toIso8601String(),
        ];
        $envelope['content_hash'] = hash('sha256', $this->canonical->canonicalJson($envelope));

        return $envelope;
    }

    public function canonicalEnvelopeJson(array $envelope): string
    {
        return $this->canonical->canonicalJson($envelope);
    }
}
