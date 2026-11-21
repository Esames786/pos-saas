<?php

namespace App\Services\Edge\Cloud;

use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Services\Edge\EdgeCanonicalJson;
use App\Services\Edge\OfflineEdgeEntitlementService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EDGE 0.9 (K1) — the per-branch LIVE STATE the warm standby mirrors every 30–60 s (stream A stores it read-only and adopts
 * it as drafts at takeover; nothing here is a financial fact on the Edge until it is rung there).
 *
 * Read-only, ONE consistent InnoDB snapshot (a read transaction on the tenant connection), batched queries (no N+1):
 *   shifts          open shifts of the branch (per terminal) with the Cloud's running counters from the shifts row
 *                   (SaleOperationalSettlementService / SalesReturnService keep them current, BUG-032 atomic increments)
 *   table_sessions  open / bill-requested restaurant table sessions of the branch with their UNPAID orders
 *                   (sales_orders.status = held), lines, payments and KOT state
 *   held_sales      every other held sale of the branch (not on an open table session)
 *
 * Only the device's branch; money as decimal strings; no customer PII beyond ids (no names, phones, addresses).
 * `revision` is MONOTONIC per device: a counter on the master edge_devices row, bumped by an atomic compare-and-set only when
 * `state_hash` (sha256 of the canonical state, generated_at excluded) differs from the hash it last numbered. Identical state
 * ⇒ identical revision, so `since=<revision>` short-circuits to {"unchanged": true}; a newer state always has a numerically
 * greater revision. `generated_at` is the Cloud's clock at build time — the appliance judges staleness from it (fail closed).
 */
class LiveStateService
{
    public const SCHEMA = 'edge-live-state-v1';
    /** Hard cap on held orders mirrored in one response (a branch with more is reported as truncated). */
    public const MAX_HELD_ORDERS = 1000;
    private const OPEN_SESSION_STATUSES = ['open', 'bill_requested'];

    public function __construct(
        private readonly TenancyManager $tenancy,
        private readonly OfflineEdgeEntitlementService $entitlement,
    ) {}

    /** @return array<string,mixed> the K1 response body */
    public function respond(EdgeDevice $device, ?string $since): array
    {
        $state = $this->build($device);
        if ($since !== null && $since !== '' && hash_equals($state['revision'], $since)) {
            return ['unchanged' => true, 'schema' => self::SCHEMA, 'revision' => $state['revision'], 'state_hash' => $state['state_hash'],
                'generated_at' => $state['generated_at'], 'branch_id' => $state['branch_id']];
        }

        return $state;
    }

    /** @return array<string,mixed> */
    public function build(EdgeDevice $device): array
    {
        $tenant = Tenant::find((int) $device->tenant_id);
        if (! $tenant) {
            throw EdgeSetupException::of(EdgeSetupException::DEVICE_NOT_CURRENT);
        }
        $this->tenancy->activate($tenant);
        try {
            $this->assertContract($device);
            $branchId = (int) $device->branch_id;
            $body = DB::connection('tenant')->transaction(fn () => $this->snapshot($branchId));
            $hash = hash('sha256', EdgeCanonicalJson::encode(['branch_id' => $branchId] + $body));
            $revision = $this->revisionFor($device, $hash);

            return ['schema' => self::SCHEMA, 'revision' => (string) $revision, 'state_hash' => $hash, 'generated_at' => now()->toIso8601String(),
                'branch_id' => $branchId] + $body;
        } finally {
            $this->tenancy->deactivate();
        }
    }

    /**
     * The monotonic revision numbering $hash: unchanged hash ⇒ the stored counter; a new hash ⇒ counter + 1 via a single
     * compare-and-set UPDATE (two concurrent polls of the same new state bump it once; the counter never goes backwards).
     */
    private function revisionFor(EdgeDevice $device, string $hash): int
    {
        $m = DB::connection('master');
        $m->table('edge_devices')->where('id', $device->id)
            ->where(fn ($q) => $q->whereNull('live_state_hash')->orWhere('live_state_hash', '!=', $hash))
            ->update([
                'live_state_revision'   => DB::raw('COALESCE(live_state_revision, 0) + 1'),
                'live_state_hash'       => $hash,
                'live_state_changed_at' => now(),
            ]);

        return (int) $m->table('edge_devices')->where('id', $device->id)->value('live_state_revision');
    }

    private function assertContract(EdgeDevice $device): void
    {
        $current = EdgeDevice::active()->where('tenant_id', $device->tenant_id)->where('branch_id', $device->branch_id)->first();
        if ($device->isRevoked() || ! $current || (int) $current->id !== (int) $device->id) {
            throw EdgeSetupException::of(EdgeSetupException::DEVICE_NOT_CURRENT);
        }
        if (! in_array($device->status, [EdgeDevice::STATUS_READY, EdgeDevice::STATUS_ACTIVE], true)) {
            throw EdgeSetupException::of(EdgeSetupException::DEVICE_NOT_CURRENT, 'The Branch Server has not finished its bootstrap yet.', ['reason' => 'not_bootstrapped']);
        }
        if (! $this->entitlement->featureIsEnabled() || ! $this->entitlement->tenantHasOfflineEdgeAccess()) {
            throw EdgeSetupException::of(EdgeSetupException::ENTITLEMENT);
        }
    }

    /** @return array{shifts:list<array>, table_sessions:list<array>, held_sales:list<array>, truncated:bool} */
    private function snapshot(int $branchId): array
    {
        $c = DB::connection('tenant');

        // 1. Open shifts — index shifts(branch_id, terminal_id, status).
        $shiftRows = $c->table('shifts')->where('branch_id', $branchId)->where('status', 'open')
            ->orderBy('terminal_id')->orderBy('id')
            ->get(['id', 'shift_uuid', 'terminal_id', 'status', 'opened_at', 'opened_by_user_id', 'opening_cash', 'business_date',
                'total_sales', 'total_cash', 'total_card', 'total_bank_transfer', 'total_cheque', 'total_refunds', 'total_cash_refunds',
                'total_discount', 'total_tax', 'expected_cash']);

        // 2. Open table sessions of the branch.
        $sessionRows = $c->table('restaurant_table_sessions')->where('branch_id', $branchId)
            ->whereIn('status', self::OPEN_SESSION_STATUSES)->orderBy('id')
            ->get(['id', 'session_uuid', 'session_no', 'restaurant_table_id', 'restaurant_waiter_id', 'opened_by_user_id', 'opened_shift_id',
                'guest_count', 'status', 'business_date', 'opened_at']);
        $sessionIds = $sessionRows->pluck('id')->map(fn ($v) => (int) $v)->all();

        // 3. Every HELD (unpaid) order of the branch — table-session orders and stand-alone held sales in one query.
        $orderRows = $c->table('sales_orders')->where('branch_id', $branchId)->where('status', 'held')
            ->orderBy('id')->limit(self::MAX_HELD_ORDERS + 1)
            ->get(['id', 'sale_uuid', 'sale_no', 'terminal_id', 'shift_id', 'restaurant_table_id', 'restaurant_table_session_id',
                'restaurant_waiter_id', 'customer_id', 'order_type', 'is_draft', 'subtotal', 'discount_amount', 'tax_amount',
                'service_charge_amount', 'delivery_charge_amount', 'grand_total', 'paid_amount', 'balance_due', 'notes',
                'business_date', 'created_at', 'updated_at']);
        $truncated = $orderRows->count() > self::MAX_HELD_ORDERS;
        if ($truncated) {
            $orderRows = $orderRows->take(self::MAX_HELD_ORDERS);
        }
        $orderIds = $orderRows->pluck('id')->map(fn ($v) => (int) $v)->all();

        // 4. Lines, payments, KOT batches of those orders (all keyed by sales_order_id — FK indexes).
        $lineRows = $orderIds === [] ? collect() : $c->table('sales_order_lines')->whereIn('sales_order_id', $orderIds)->orderBy('sales_order_id')->orderBy('id')
            ->get(['id', 'line_uuid', 'sales_order_id', 'parent_sales_order_line_id', 'line_kind', 'combo_id', 'product_id', 'product_variant_id',
                'product_name', 'variant_name', 'unit_code', 'quantity', 'unit_price', 'discount_amount', 'tax_amount', 'line_total',
                'kot_sent', 'kot_sent_quantity', 'void_reason_id', 'kitchen_note', 'modifiers', 'kitchen_status']);
        $lineIds = $lineRows->pluck('id')->map(fn ($v) => (int) $v)->all();
        $lineUuidById = $lineRows->mapWithKeys(fn ($l) => [(int) $l->id => $l->line_uuid])->all();

        $paymentRows = $orderIds === [] ? collect() : $c->table('sale_payments as p')
            ->leftJoin('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->whereIn('p.sales_order_id', $orderIds)->orderBy('p.id')
            ->get(['p.id', 'p.payment_uuid', 'p.sales_order_id', 'p.payment_method_id', 'p.amount', 'm.code as method_code', 'm.method_type']);

        $batchRows = $orderIds === [] ? collect() : $c->table('kot_batches')->whereIn('sales_order_id', $orderIds)
            ->orderBy('sales_order_id')->orderBy('sequence_no')
            ->get(['id', 'event_uuid', 'sales_order_id', 'sequence_no', 'event_type', 'created_at']);
        $batchUuidById = $batchRows->mapWithKeys(fn ($b) => [(int) $b->id => (string) $b->event_uuid])->all();

        // Latest KOT batch per line (kot_batch_lines.sales_order_line_id FK index).
        $lastBatchByLine = [];
        if ($lineIds !== []) {
            foreach ($c->table('kot_batch_lines')->whereIn('sales_order_line_id', $lineIds)->orderBy('kot_batch_id')
                ->get(['sales_order_line_id', 'kot_batch_id']) as $kl) {
                $lastBatchByLine[(int) $kl->sales_order_line_id] = (int) $kl->kot_batch_id;
            }
        }

        // Shift uuids for every shift an order or session references (closed shifts included).
        $shiftIds = array_values(array_unique(array_filter(array_merge(
            $orderRows->pluck('shift_id')->all(), $sessionRows->pluck('opened_shift_id')->all(), $shiftRows->pluck('id')->all()
        ))));
        $shiftUuid = $shiftIds === [] ? [] : $c->table('shifts')->whereIn('id', $shiftIds)->pluck('shift_uuid', 'id')->all();

        $linesByOrder = [];
        foreach ($lineRows as $l) {
            $qty = (string) $l->quantity;
            $sent = (string) ($l->kot_sent_quantity ?? '0');
            $state = self::kotState((float) $qty, (float) $sent, (bool) $l->kot_sent);
            $batchId = $lastBatchByLine[(int) $l->id] ?? null;
            $linesByOrder[(int) $l->sales_order_id][] = [
                'line_id'           => (int) $l->id,
                'line_uuid'         => $l->line_uuid,
                'parent_line_uuid'  => $l->parent_sales_order_line_id ? ($lineUuidById[(int) $l->parent_sales_order_line_id] ?? null) : null,
                'line_kind'         => (string) $l->line_kind,
                'combo_id'          => $l->combo_id !== null ? (int) $l->combo_id : null,
                'product_id'        => $l->product_id !== null ? (int) $l->product_id : null,
                'variant_id'        => $l->product_variant_id !== null ? (int) $l->product_variant_id : null,
                'product_name'      => (string) $l->product_name,
                'variant_name'      => $l->variant_name,
                'unit_code'         => $l->unit_code,
                'qty'               => self::qty($qty),
                'unit_price'        => self::money($l->unit_price),
                'discount'          => self::money($l->discount_amount),
                'tax'               => self::money($l->tax_amount),
                'line_total'        => self::money($l->line_total),
                'notes'             => $l->kitchen_note,
                'modifiers'         => self::jsonList($l->modifiers),
                'voided'            => $l->void_reason_id !== null,
                'kot_state'         => $state,
                'kot_sent_qty'      => self::qty($sent),
                'kot_batch_uuid'    => $batchId !== null ? ($batchUuidById[$batchId] ?? null) : null,
                'kitchen_status'    => $l->kitchen_status,
            ];
        }
        $paymentsByOrder = [];
        foreach ($paymentRows as $p) {
            $paymentsByOrder[(int) $p->sales_order_id][] = [
                'payment_uuid'      => $p->payment_uuid,
                'payment_method_id' => $p->payment_method_id !== null ? (int) $p->payment_method_id : null,
                'method'            => strtolower((string) ($p->method_type ?: $p->method_code ?: 'unknown')),
                'method_code'       => $p->method_code,
                'amount'            => self::money($p->amount),
            ];
        }
        $batchesByOrder = [];
        foreach ($batchRows as $b) {
            $batchesByOrder[(int) $b->sales_order_id][] = [
                'event_uuid' => (string) $b->event_uuid, 'sequence_no' => (int) $b->sequence_no,
                'event_type' => (string) $b->event_type, 'created_at' => self::iso($b->created_at),
            ];
        }

        $order = function ($o) use ($shiftUuid, $linesByOrder, $paymentsByOrder, $batchesByOrder): array {
            $id = (int) $o->id;

            return [
                'order_id'        => $id,
                'order_uuid'      => $o->sale_uuid,
                'order_no'        => $o->sale_no,
                'status'          => 'open',               // Cloud sales_orders.status = held (unpaid)
                'is_draft'        => (bool) $o->is_draft,
                'order_type'      => $o->order_type,
                'terminal_id'     => $o->terminal_id !== null ? (int) $o->terminal_id : null,
                'shift_uuid'      => $o->shift_id ? ($shiftUuid[(int) $o->shift_id] ?? null) : null,
                'table_id'        => $o->restaurant_table_id !== null ? (int) $o->restaurant_table_id : null,
                'waiter_id'       => $o->restaurant_waiter_id !== null ? (int) $o->restaurant_waiter_id : null,
                'customer_id'     => $o->customer_id !== null ? (int) $o->customer_id : null,
                'subtotal'        => self::money($o->subtotal),
                'discount'        => self::money($o->discount_amount),
                'tax'             => self::money($o->tax_amount),
                'service_charge'  => self::money($o->service_charge_amount),
                'delivery_charge' => self::money($o->delivery_charge_amount),
                'total'           => self::money($o->grand_total),
                'paid'            => self::money($o->paid_amount),
                'balance_due'     => self::money($o->balance_due),
                'notes'           => $o->notes,
                'business_date'   => $o->business_date !== null ? substr((string) $o->business_date, 0, 10) : null,
                'created_at'      => self::iso($o->created_at),
                'held_at'         => self::iso($o->updated_at),
                'lines'           => $linesByOrder[$id] ?? [],
                'payments'        => $paymentsByOrder[$id] ?? [],
                'kot_batches'     => $batchesByOrder[$id] ?? [],
            ];
        };

        $openSession = array_flip($sessionIds);
        $ordersBySession = [];
        $held = [];
        foreach ($orderRows as $o) {
            $sid = $o->restaurant_table_session_id !== null ? (int) $o->restaurant_table_session_id : null;
            if ($sid !== null && isset($openSession[$sid])) {
                $ordersBySession[$sid][] = $order($o);
            } else {
                $held[] = $order($o);
            }
        }

        $shifts = $shiftRows->map(fn ($s) => [
            'shift_uuid'        => (string) $s->shift_uuid,
            'terminal_id'       => $s->terminal_id !== null ? (int) $s->terminal_id : null,
            'status'            => (string) $s->status,
            'opened_at'         => self::iso($s->opened_at),
            'opened_by_user_id' => $s->opened_by_user_id !== null ? (int) $s->opened_by_user_id : null,
            'business_date'     => $s->business_date !== null ? substr((string) $s->business_date, 0, 10) : null,
            'opening_cash'      => self::money($s->opening_cash),
            'running'           => [
                'total_sales'          => self::money($s->total_sales),
                'cash_sales'           => self::money($s->total_cash),
                'card_sales'           => self::money($s->total_card),
                'bank_transfer_sales'  => self::money($s->total_bank_transfer),
                'cheque_sales'         => self::money($s->total_cheque),
                'refunds'              => self::money($s->total_refunds),
                'cash_refunds'         => self::money($s->total_cash_refunds),
                'discounts'            => self::money($s->total_discount),
                'tax'                  => self::money($s->total_tax),
                // The Cloud has no shift pay-in / pay-out movements: these are always 0.00 (kept for the K1 shape).
                'cash_in'              => '0.00',
                'cash_out'             => '0.00',
                'expected_cash'        => self::money($s->expected_cash),
            ],
        ])->values()->all();

        $sessions = $sessionRows->map(fn ($s) => [
            'session_id'        => (int) $s->id,
            'session_uuid'      => $s->session_uuid,
            'session_no'        => $s->session_no,
            'table_id'          => (int) $s->restaurant_table_id,
            'status'            => (string) $s->status,
            'opened_at'         => self::iso($s->opened_at),
            'covers'            => $s->guest_count !== null ? (int) $s->guest_count : null,
            'waiter_id'         => $s->restaurant_waiter_id !== null ? (int) $s->restaurant_waiter_id : null,
            'opened_by_user_id' => $s->opened_by_user_id !== null ? (int) $s->opened_by_user_id : null,
            'opened_shift_uuid' => $s->opened_shift_id ? ($shiftUuid[(int) $s->opened_shift_id] ?? null) : null,
            'business_date'     => $s->business_date !== null ? substr((string) $s->business_date, 0, 10) : null,
            'orders'            => $ordersBySession[(int) $s->id] ?? [],
        ])->values()->all();

        return ['shifts' => $shifts, 'table_sessions' => $sessions, 'held_sales' => $held, 'truncated' => $truncated];
    }

    /* ── formatting ─────────────────────────────────────────────────────── */

    /** fired = every unit sent to the kitchen; partial = some; pending = none (legacy rows: the kot_sent flag alone). */
    public static function kotState(float $qty, float $sent, bool $flag): string
    {
        if ($sent > 0.0) {
            return $sent + 1e-9 >= $qty ? 'fired' : 'partial';
        }

        return $flag ? 'fired' : 'pending';
    }

    public static function money(mixed $v): string
    {
        return number_format(round((float) ($v ?? 0), 2), 2, '.', '');
    }

    public static function qty(mixed $v): string
    {
        $s = (string) ($v ?? '0');
        if (! is_numeric($s)) {
            return '0';
        }
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    private static function iso(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return Carbon::parse((string) $v, 'UTC')->toIso8601String();
    }

    private static function jsonList(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (! is_string($v) || trim($v) === '') {
            return [];
        }
        $d = json_decode($v, true);

        return is_array($d) ? $d : [];
    }
}
