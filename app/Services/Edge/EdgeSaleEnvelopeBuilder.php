<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Tenant\KotBatch;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Support\Edge\EdgeIdentity;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OFFLINE-SYNC-ENGINE-1B — build the IMMUTABLE `edge-sale-envelope-v1` for a PAID offline sale
 * (docs/design/OFFLINE_SYNC_ENGINE_V1.md §5).
 *
 * Canonicalisation/hash REUSES the bootstrap wire strategy (EdgeBootstrapService::canonicalJson —
 * recursive key-sort, unescaped unicode/slashes) — no second ad-hoc JSON/hash scheme.
 * `content_hash = sha256(canonicalJson(envelope minus content_hash))`.
 *
 * FAIL-CLOSED (defense in depth, §8): only the offline-supported commercial shape may become a
 * syncable envelope — paid status, quick_sale/takeaway/dine_in, cash-only payments, no
 * discount/promo/tip, no combo lines. Anything else refuses loudly; nothing is silently dropped.
 *
 * Field authority: every value is read from the PERSISTED rows of the sale transaction (never the
 * request), and parents are referenced by canonical identity (sale/line/payment/shift/session/KOT
 * UUIDs). Numeric Edge PKs appear only as replicated-CONFIG references (product/terminal/user/
 * branch/payment-method ids are Cloud-stable by bootstrap preservation). No secret field can appear:
 * the envelope is assembled from explicit field lists and then scanned recursively as a final guard.
 */
class EdgeSaleEnvelopeBuilder
{
    public const SCHEMA_VERSION = 'edge-sale-envelope-v1';

    /** V1 offline-supported commercial shape (mirrors the EdgeLocalPosService gates). */
    private const SUPPORTED_ORDER_TYPES = ['quick_sale', 'takeaway', 'dine_in'];
    private const SUPPORTED_METHOD_TYPES = ['cash'];

    /** Same never-ship discipline as the bootstrap importer. */
    private const SECRET_FIELDS = ['password', 'password_hash', 'remember_token', 'pin', 'pin_hash', 'manager_pin', 'device_secret', 'device_secret_hash', 'secret', 'credential_hash'];

    public function __construct(
        private readonly EdgeBootstrapService $canonical,
        private readonly EdgeOperationalBaselineService $baselines,
    ) {
    }

    /**
     * Build the envelope (including content_hash) for a finalized PAID sale. Must be called INSIDE
     * the sale's own transaction so every read is snapshot-consistent with the sale itself.
     */
    public function build(SalesOrder $sale, EdgeLocalMeta $meta): array
    {
        $this->assertSupported($sale, $meta);

        $sale->loadMissing(['lines', 'payments.method', 'terminal']);
        $shift = Shift::on('tenant')->findOrFail((int) $sale->shift_id);
        $session = $sale->restaurant_table_session_id
            ? RestaurantTableSession::on('tenant')->find((int) $sale->restaurant_table_session_id)
            : null;
        $user = \App\Models\Tenant\User::on('tenant')->find((int) $sale->created_by_user_id);

        $envelope = [
            'envelope_schema_version' => self::SCHEMA_VERSION,

            // Frozen binding + config context (§5 / §9 of the design).
            'tenant_id' => (int) $meta->tenant_id,
            'tenant_code' => (string) $meta->tenant_code,
            'branch_id' => (int) $meta->branch_id,
            'device_public_uuid' => (string) $meta->device_uuid,
            'activation_epoch' => (int) $meta->activation_epoch,
            'config_revision' => (int) $meta->last_applied_config_revision,
            'config_schema_version' => (string) $meta->config_schema_version,

            // Sale identity + occurred facts.
            'sale_uuid' => (string) $sale->sale_uuid,
            'sale_no' => (string) $sale->sale_no,
            'client_uuid' => $sale->client_uuid !== null ? (string) $sale->client_uuid : null,
            'business_date' => optional($sale->business_date)->toDateString(),
            'sale_date' => optional($sale->sale_date)->toIso8601String(),
            'completed_at' => optional($sale->completed_at)->toIso8601String(),
            'created_at' => optional($sale->created_at)->toIso8601String(),
            'order_type' => (string) $sale->order_type,
            'order_source' => (string) $sale->order_source,
            'vehicle_number' => $sale->vehicle_number !== null ? (string) $sale->vehicle_number : null,

            // Principals (replicated-config references + human identity).
            'terminal_id' => (int) $sale->terminal_id,
            'terminal_code' => (string) ($sale->terminal?->code ?? ''),
            'user_id' => (int) $sale->created_by_user_id,
            'employee_code' => $user?->employee_code !== null ? (string) $user->employee_code : null,
            // PHASE 2b parity: quick-sale waiter attribution (dine-in's waiter lives on the session snapshot).
            'restaurant_waiter_id' => $sale->restaurant_waiter_id !== null ? (int) $sale->restaurant_waiter_id : null,

            // Parent snapshots by canonical identity.
            'shift' => [
                'shift_uuid' => (string) $shift->shift_uuid,
                'business_date' => optional($shift->business_date)->toDateString(),
                'opened_at' => optional($shift->opened_at)->toIso8601String(),
                'terminal_id' => (int) $shift->terminal_id,
                'opened_by_user_id' => (int) $shift->opened_by_user_id,
            ],
            'table_session' => $session ? [
                'session_uuid' => (string) $session->session_uuid,
                'session_no' => (string) $session->session_no,
                'restaurant_table_id' => (int) $session->restaurant_table_id,
                'restaurant_waiter_id' => $session->restaurant_waiter_id !== null ? (int) $session->restaurant_waiter_id : null,
                'opened_at' => optional($session->opened_at)->toIso8601String(),
            ] : null,
            'kot_events' => KotBatch::on('tenant')->where('sales_order_id', $sale->id)
                ->orderBy('sequence_no')->get(['event_uuid', 'sequence_no', 'event_type'])
                ->map(fn ($k) => ['event_uuid' => (string) $k->event_uuid, 'sequence_no' => (int) $k->sequence_no, 'event_type' => (string) $k->event_type])
                ->all(),
            // CROSS-SYSTEM customer identity (1B closure): never a local integer PK. Walk-in is explicit; an
            // attached customer is carried by its canonical customer_uuid (+ the sale's own name/phone snapshot).
            'customer' => $this->customerIdentity($sale),

            // Frozen commercial totals (envelope is the price authority at ingest — never repriced).
            'totals' => [
                'subtotal' => round((float) $sale->subtotal, 2),
                'discount_amount' => round((float) $sale->discount_amount, 2),
                'tax_amount' => round((float) $sale->tax_amount, 2),
                'service_charge_amount' => round((float) ($sale->service_charge_amount ?? 0), 2),
                'tip_amount' => round((float) ($sale->tip_amount ?? 0), 2),
                'grand_total' => round((float) $sale->grand_total, 2),
                'paid_amount' => round((float) $sale->paid_amount, 2),
                'change_amount' => round((float) $sale->change_amount, 2),
            ],

            'lines' => $sale->lines->map(fn ($line) => [
                'line_uuid' => (string) $line->line_uuid,
                'line_kind' => (string) ($line->line_kind ?? 'standard'),
                'product_id' => (int) $line->product_id,
                'product_variant_id' => $line->product_variant_id !== null ? (int) $line->product_variant_id : null,
                'combo_id' => $line->combo_id !== null ? (int) $line->combo_id : null,
                'product_name' => (string) $line->product_name,        // frozen snapshot
                'quantity' => round((float) $line->quantity, 3),
                'unit_price' => round((float) $line->unit_price, 2),
                'discount_amount' => round((float) $line->discount_amount, 2),
                'tax_amount' => round((float) $line->tax_amount, 2),
                'line_total' => round((float) $line->line_total, 2),
                'modifiers' => is_array($line->modifiers) ? $line->modifiers : [],
            ])->values()->all(),

            'payments' => $sale->payments->map(fn ($payment) => [
                'payment_uuid' => (string) $payment->payment_uuid,
                'payment_method_id' => (int) $payment->payment_method_id,
                'method_type' => (string) ($payment->method?->method_type ?? ''),
                'amount' => round((float) $payment->amount, 2),
                'tendered_amount' => $payment->tendered_amount !== null ? round((float) $payment->tendered_amount, 2) : null,
                'change_amount' => round((float) $payment->change_amount, 2),
                'transaction_ref' => $payment->transaction_ref !== null ? (string) $payment->transaction_ref : null,
                'paid_at' => optional($payment->created_at)->toIso8601String(),
            ])->values()->all(),

            // Provisional operational-stock evidence (audit input for Cloud validation — never
            // official authority; Cloud posts FEFO itself at ingest).
            'operational_stock' => [
                'posted' => (bool) $sale->edge_operational_stock_posted,
                'baseline_uuid' => $this->baselines->currentAccepted()?->baseline_uuid,
            ],

            // Local sync facts at envelope-creation time (audit).
            'local_state' => [
                'edge_sync_state' => (string) $sale->edge_sync_state,
                'edge_activation_epoch' => (int) $sale->edge_activation_epoch,
                'inventory_posted' => (bool) $sale->inventory_posted,
                'is_draft' => (bool) $sale->is_draft,   // always false here: only a PAID sale becomes an envelope
            ],
        ];

        $this->assertNoSecretFields($envelope);

        $envelope['content_hash'] = hash('sha256', $this->canonical->canonicalJson($envelope));

        return $envelope;
    }

    /** The canonical JSON string of a built envelope — the exact immutable bytes the outbox stores. */
    public function canonicalEnvelopeJson(array $envelope): string
    {
        return $this->canonical->canonicalJson($envelope);
    }

    /**
     * @return array{kind: string, customer_uuid?: string, name: ?string, phone: ?string}
     */
    private function customerIdentity(SalesOrder $sale): array
    {
        $name = $sale->customer_name !== null && $sale->customer_name !== '' ? (string) $sale->customer_name : null;
        $phone = $sale->customer_phone !== null && $sale->customer_phone !== '' ? (string) $sale->customer_phone : null;

        if ($sale->customer_id === null) {
            return ['kind' => 'walk_in', 'name' => $name, 'phone' => $phone];
        }

        $customer = \App\Models\Tenant\Customer::on('tenant')->find((int) $sale->customer_id);
        if (! $customer || ! EdgeIdentity::isValid((string) $customer->customer_uuid, EdgeIdentity::FORMAT_ULID)) {
            throw new RuntimeException('ENVELOPE_UNSUPPORTED: the sale references a customer without a canonical customer_uuid — a local id is never a cross-system identity.');
        }

        return [
            'kind' => 'customer',
            'customer_uuid' => (string) $customer->customer_uuid,
            'name' => $name ?? (string) $customer->name,
            'phone' => $phone ?? ($customer->phone !== null ? (string) $customer->phone : null),
        ];
    }
    // ── fail-closed guards ───────────────────────────────────────────────────

    private function assertSupported(SalesOrder $sale, EdgeLocalMeta $meta): void
    {
        if ($meta->runtime_state !== EdgeLocalMeta::STATE_BOOTSTRAPPED || (int) ($meta->last_applied_config_revision ?? 0) < 1) {
            throw new RuntimeException('ENVELOPE_UNSUPPORTED: the appliance binding/config revision is not available for envelope creation.');
        }
        if ($sale->status !== 'paid') {
            throw new RuntimeException('ENVELOPE_UNSUPPORTED: only a PAID sale can become a sync envelope.');
        }
        if ($sale->edge_sync_state !== 'pending') {
            throw new RuntimeException('ENVELOPE_UNSUPPORTED: only an offline-origin pending sale can become a sync envelope.');
        }
        if (! in_array((string) $sale->order_type, self::SUPPORTED_ORDER_TYPES, true)) {
            throw new RuntimeException("ENVELOPE_UNSUPPORTED: order type [{$sale->order_type}] is not offline-syncable.");
        }
        if (! EdgeIdentity::isValid((string) $sale->sale_uuid, EdgeIdentity::FORMAT_ULID)) {
            throw new RuntimeException('ENVELOPE_UNSUPPORTED: the sale is missing its canonical sale_uuid.');
        }
        if ((float) $sale->discount_amount > 0 || $sale->promotion_id !== null || $sale->promo_code !== null) {
            throw new RuntimeException('ENVELOPE_UNSUPPORTED: discounts/promotions are not supported offline (defense in depth).');
        }
        if ((float) ($sale->tip_amount ?? 0) > 0) {
            throw new RuntimeException('ENVELOPE_UNSUPPORTED: tips are not supported offline (defense in depth).');
        }

        $sale->loadMissing(['lines', 'payments.method']);
        if ($sale->lines->isEmpty() || $sale->payments->isEmpty()) {
            throw new RuntimeException('ENVELOPE_UNSUPPORTED: a syncable sale needs persisted lines and payments.');
        }
        foreach ($sale->lines as $line) {
            if (($line->line_kind ?? 'standard') !== 'standard' || $line->combo_id !== null) {
                throw new RuntimeException('ENVELOPE_UNSUPPORTED: combo selling is not supported offline (defense in depth).');
            }
        }
        foreach ($sale->payments as $payment) {
            $type = (string) ($payment->method?->method_type ?? '');
            if (! in_array($type, self::SUPPORTED_METHOD_TYPES, true)) {
                throw new RuntimeException("ENVELOPE_UNSUPPORTED: payment method type [{$type}] is not offline-syncable.");
            }
        }
    }

    private function assertNoSecretFields(array $node): void
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_FIELDS, true)) {
                throw new RuntimeException("ENVELOPE_UNSUPPORTED: secret field [{$key}] must never enter a sync envelope.");
            }
            if (is_array($value)) {
                $this->assertNoSecretFields($value);
            }
        }
    }
}
