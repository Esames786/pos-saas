<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;

/**
 * EDGE PHASE 4 — what the Cloud ADVERTISED to this appliance (heartbeat ACK `capabilities`, stored on edge_local_meta by
 * EdgeAuthorityService::heartbeat). The ONE reader for every capability-gated Edge behaviour:
 *
 *   - EdgePosRuntimeFactory turns the shared view's customerCreate / customerAddressCreate on only while advertised;
 *   - EdgeLocalCustomerController refuses quick-store / address-store server-side while not advertised;
 *   - EdgeSaleEnvelopeBuilder emits the `new_customer` envelope kind (edge-sale-envelope-v3) only while advertised.
 *
 * Fail closed: no row, no advertisement yet, an old Cloud that omits the key, a malformed value — all read as `false`.
 * An appliance therefore never emits an envelope kind the Cloud has not said it can ingest (owner requirement: the old
 * Cloud must refuse the capability safely BEFORE a device emits unsupported envelopes).
 */
class EdgeCloudCapabilities
{
    public const CUSTOMER_CREATE = 'customer_create';

    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    /** @return array<string,bool> the advertisement as stored (empty when nothing was ever advertised). */
    public function advertised(): array
    {
        return self::decode($this->context->current()?->getAttribute('cloud_capabilities_seen'));
    }

    public function has(string $capability): bool
    {
        return $this->advertised()[$capability] ?? false;
    }

    public function customerCreate(): bool
    {
        return $this->has(self::CUSTOMER_CREATE);
    }

    /** Normalise a heartbeat ACK `capabilities` value to a flat {name: bool} map (unknown shapes read as nothing advertised). */
    public static function normalizeAck(mixed $capabilities): array
    {
        if (! is_array($capabilities)) {
            return [];
        }
        $out = [];
        foreach ($capabilities as $key => $value) {
            if (is_string($key) && $key !== '' && is_bool($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @return array<string,bool> */
    public static function decode(mixed $stored): array
    {
        if (is_array($stored)) {
            return self::normalizeAck($stored);
        }
        if (! is_string($stored) || $stored === '') {
            return [];
        }
        $decoded = json_decode($stored, true);

        return self::normalizeAck(is_array($decoded) ? $decoded : []);
    }

    /** Read the advertisement straight from a meta row (for callers that already hold it, e.g. the envelope builder). */
    public static function fromMeta(?EdgeLocalMeta $meta, string $capability): bool
    {
        return self::decode($meta?->getAttribute('cloud_capabilities_seen'))[$capability] ?? false;
    }
}
