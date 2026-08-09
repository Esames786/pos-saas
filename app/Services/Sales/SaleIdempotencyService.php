<?php

namespace App\Services\Sales;

use App\Models\Tenant\SalesOrder;

/**
 * SALE-IDEMPOTENCY-1 — the one place that decides whether an incoming paid-sale
 * request is a fresh sale, a harmless replay (retry/double-click/timeout), or a
 * conflict (same client_uuid, different sale details). Shared by the live cloud
 * POS today and the future Bingoo Edge offline sync.
 */
class SaleIdempotencyService
{
    /** A finalized sale for replay purposes is one that already posted (status paid). */
    private const FINALIZED_STATUSES = ['paid', 'partially_returned', 'returned'];

    /** Trim + lowercase + validate uuid shape; garbage/empty → null (treated as "no key"). */
    public function normalizeClientUuid(?string $uuid): ?string
    {
        $uuid = strtolower(trim((string) $uuid));

        if ($uuid === '') {
            return null;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) === 1
            ? $uuid
            : null;
    }

    /**
     * The canonical INTENT of a sale request — the single source of truth for both the Cloud
     * SalesOrderController and the offline Branch Server (EdgeLocalPosService). Line/payment order is not
     * material (sorted by stable identity) so harmless browser reordering never causes a false conflict.
     * Browser-only fields and server-resolved values are deliberately excluded.
     */
    public function canonicalSalePayload(array $data): array
    {
        return [
            'branch_id'                   => $data['branch_id'] ?? null,
            'terminal_id'                 => $data['terminal_id'] ?? null,
            'customer_id'                 => $data['customer_id'] ?? null,
            'order_source'                => $data['order_source'] ?? null,
            'order_type'                  => $data['order_type'] ?? null,
            'restaurant_table_session_id' => $data['restaurant_table_session_id'] ?? null,
            'held_sale_id'                => $data['held_sale_id'] ?? null,
            'delivery_channel_id'         => $data['delivery_channel_id'] ?? null,
            'delivery_rider_id'           => $data['delivery_rider_id'] ?? null,
            'delivery_address'            => $data['delivery_address'] ?? null,
            'delivery_charge_amount'      => $data['delivery_charge_amount'] ?? null,
            'vehicle_number'              => $data['vehicle_number'] ?? null,
            'discount_type'               => $data['discount_type'] ?? null,
            'discount_value'              => $data['discount_value'] ?? null,
            'promo_code'                  => $data['promo_code'] ?? null,
            'tip_amount'                  => $data['tip_amount'] ?? null,
            'kot_print_intent'            => $data['kot_print_intent'] ?? null,
            'receipt_print_intent'        => $data['receipt_print_intent'] ?? null,
            'lines' => collect($data['lines'] ?? [])->map(fn ($l) => [
                'product_id'         => $l['product_id'] ?? null,
                'product_variant_id' => $l['product_variant_id'] ?? null,
                'line_kind'          => $l['line_kind'] ?? 'standard',
                'combo_id'           => $l['combo_id'] ?? null,
                'client_line_key'    => $l['client_line_key'] ?? null,
                'quantity'           => $l['quantity'] ?? null,
                'unit_price'         => $l['unit_price'] ?? null,
                'discount_amount'    => $l['discount_amount'] ?? null,
                'tax_amount'         => $l['tax_amount'] ?? null,
                'modifiers'          => $l['modifiers'] ?? null,
            ])->sortBy(fn ($l) => implode('|', [$l['client_line_key'] ?? '', $l['product_id'] ?? '', $l['product_variant_id'] ?? '', $l['line_kind'] ?? '']))->values()->all(),
            'payments' => collect($data['payments'] ?? [])->map(fn ($p) => [
                'payment_method_id' => $p['payment_method_id'] ?? null,
                'amount'            => $p['amount'] ?? null,
                'tendered_amount'   => $p['tendered_amount'] ?? null,
            ])->sortBy(fn ($p) => ($p['payment_method_id'] ?? '') . '|' . ($p['amount'] ?? ''))->values()->all(),
        ];
    }

    /**
     * Deterministic SHA-256 of the customer's INTENDED sale — stable regardless of
     * request key order or browser-only fields. See {@see canonicalSalePayload()} for the exact fields.
     */
    public function buildPayloadHash(array $canonicalPayload): string
    {
        $normalized = $this->deepNormalize($canonicalPayload);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The sale (if any) already recorded under this client_uuid. */
    public function findExisting(string $clientUuid): ?SalesOrder
    {
        return SalesOrder::where('client_uuid', $clientUuid)->first();
    }

    /** A sale that has already been finalized (posted) — a true replay candidate. */
    public function findFinalized(string $clientUuid): ?SalesOrder
    {
        $sale = $this->findExisting($clientUuid);

        return $sale && in_array($sale->status, self::FINALIZED_STATUSES, true) ? $sale : null;
    }

    /**
     * HARDEN-1: after a client_uuid unique-key collision, the winning transaction
     * has committed its (atomic) sale — but re-fetch with a short bounded retry to
     * be safe across drivers/replication. Returns the finalized sale, or null if it
     * cannot be resolved in the budget (caller returns a retryable PENDING).
     */
    public function resolveFinalizedWithRetry(string $clientUuid, int $attempts = 8, int $delayMs = 80): ?SalesOrder
    {
        for ($i = 0; $i < $attempts; $i++) {
            if ($sale = $this->findFinalized($clientUuid)) {
                return $sale;
            }
            usleep($delayMs * 1000);
        }

        return $this->findFinalized($clientUuid);
    }

    /** HARDEN-1: a sale with NO stored hash cannot be verified as a safe replay. */
    public function hasVerifiableHash(SalesOrder $sale): bool
    {
        return $sale->client_payload_hash !== null && $sale->client_payload_hash !== '';
    }

    /** Strict: the stored non-null hash equals this request's payload hash. */
    public function payloadMatches(SalesOrder $sale, string $payloadHash): bool
    {
        return $this->hasVerifiableHash($sale)
            && hash_equals((string) $sale->client_payload_hash, $payloadHash);
    }

    /** Recursively ksort arrays + normalize scalars so equal intents hash equally. */
    private function deepNormalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->deepNormalize($v);
            }
            if (! $isList) {
                ksort($out);
            }
            return $out;
        }

        if (is_bool($value) || is_null($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            // 5, 5.0, "5.00" must all normalize identically.
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }

        if (is_string($value) && is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }

        return (string) $value;
    }
}
