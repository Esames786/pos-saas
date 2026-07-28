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
     * Deterministic SHA-256 of the customer's INTENDED sale — stable regardless of
     * request key order or browser-only fields. See canonicalSalePayload() in the
     * controller for exactly which fields are included/excluded.
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

    /** True when the stored hash matches this request's payload (→ replay, not conflict). */
    public function payloadMatches(SalesOrder $sale, string $payloadHash): bool
    {
        // A sale created before this feature (or via a path that didn't record a
        // hash) has a null hash — treat a same-uuid hit as a replay, not a conflict,
        // to stay backward compatible and never wrongly 409 a legitimate retry.
        return $sale->client_payload_hash === null
            || hash_equals((string) $sale->client_payload_hash, $payloadHash);
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
