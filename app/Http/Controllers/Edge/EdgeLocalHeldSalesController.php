<?php

namespace App\Http\Controllers\Edge;

use App\Exceptions\SaleIdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Edge\Concerns\ResolvesEdgePosContext;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\VoidReason;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * W0c (Team 3 owns) — the HELD-CHECK lifecycle on the Branch Server: Hold / Draft / Add Round, KOT, settle, cancel,
 * split, Recall list + detail, void reasons. Split out of EdgeLocalPosController; same routes, same behaviour.
 * EdgeLocalPosService holds the authority (sent-pool, captured prices, table locks, approval modes).
 */
class EdgeLocalHeldSalesController extends Controller
{
    use ResolvesEdgePosContext;

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeLocalPosService $pos,
    ) {
    }

    /** Create or revise (Add Round) a HELD sale. */
    public function storeHeldSale(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.held-sales.store', 'Holding or revising an order needs the Hold Sale permission.')) {
            return $denied;
        }
        $data = $request->validate([
            'held_sale_id' => ['nullable', 'integer'],
            'order_type' => ['required', 'string'],
            'restaurant_table_session_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'discount_type' => ['nullable', 'string'],
            'discount_value' => ['nullable', 'numeric'],
            'promo_code' => ['nullable', 'string'],
            'manager_approval_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'delivery_channel_id' => ['nullable', 'integer'],
            'delivery_rider_id' => ['nullable', 'integer'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_charge_amount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            // POS-DRAFT-1 + PHASE 2b parity (offline): park as draft; quick-sale vehicle + waiter attribution.
            'save_as_draft' => ['nullable', 'boolean'],
            'vehicle_number' => ['nullable', 'string', 'max:50', 'required_if:order_type,quick_sale'],
            'restaurant_waiter_id' => ['nullable', 'integer', 'required_if:order_type,quick_sale'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['required_without:lines.*.combo_id', 'nullable', 'integer'],
            'lines.*.combo_id' => ['nullable', 'integer'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.modifiers' => ['nullable', 'array'],
            'void_items' => ['nullable', 'array'],
            'void_items.*.old_line_id' => ['required_with:void_items', 'integer'],
            'void_items.*.quantity' => ['required_with:void_items', 'numeric', 'gt:0'],
            'void_items.*.reason_id' => ['required_with:void_items', 'integer'],
            'void_items.*.manager_approval_id' => ['nullable', 'integer'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        try {
            $sale = $this->pos->holdOrReviseSale($data, auth('tenant')->user(), $terminal->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'sale_id' => $sale->id, 'sale_no' => $sale->sale_no, 'sale_uuid' => $sale->sale_uuid,
            'status' => $sale->status, 'is_draft' => (bool) $sale->is_draft, 'grand_total' => (float) $sale->grand_total,
            'restaurant_table_session_id' => $sale->restaurant_table_session_id,
            'lines' => $sale->lines()->get(['id', 'line_uuid', 'product_id', 'quantity', 'unit_price', 'kot_sent', 'kot_sent_quantity']),
        ], empty($data['held_sale_id']) ? 201 : 200);
    }

    /** Record the KOT business event for a held sale's unsent delta. */
    public function queueKot(Request $request, int $sale): JsonResponse
    {
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        try {
            $result = $this->pos->queueKotEvents($sale, auth('tenant')->user(), $terminal->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $batch = $result['batch'];

        return response()->json([
            'batch' => $batch ? [
                'id' => $batch->id, 'event_uuid' => $batch->event_uuid, 'sequence_no' => $batch->sequence_no,
                'event_type' => $batch->event_type,
                'lines' => $batch->lines()->get(['id', 'kot_line_uuid', 'source_line_uuid', 'product_name', 'quantity']),
            ] : null,
            'jobs' => collect($result['jobs'])->map(fn ($j) => ['id' => $j->id, 'logical_key' => $j->logical_key, 'print_status' => $j->fresh()->print_status])->values(),
            'message' => $batch ? null : 'No new items to send to kitchen.',
        ]);
    }

    /** Settle (pay) a held sale with cash — closes the table session when it was the last open check. */
    public function settleHeldSale(Request $request, int $sale): JsonResponse
    {
        $data = $request->validate([
            'client_uuid' => ['required', 'string', 'max:36'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.tendered_amount' => ['nullable', 'numeric'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        if ($denied = $this->denyUnlessMayCompleteSale()) {
            return $denied;
        }
        try {
            $settled = $this->pos->settleHeldSale($sale, $data, auth('tenant')->user(), $terminal->id);
        } catch (SaleIdempotencyConflictException $e) {
            throw $e; // renders its own 409/503
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'sale_id' => $settled->id, 'sale_no' => $settled->sale_no, 'sale_uuid' => $settled->sale_uuid,
            'status' => $settled->status, 'grand_total' => (float) $settled->grand_total,
            'paid_amount' => (float) $settled->paid_amount,
            'change_amount' => (float) $settled->payments()->first()?->change_amount,
            'edge_sync_state' => $settled->edge_sync_state,
        ]);
    }

    /** Cancel a whole held order (reason + branch-mode manager approval enforced by the real Cloud service). */
    public function cancelHeldSale(Request $request, int $sale): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.held-sales.cancel', 'Cancelling an order needs the Cancel Held Sale permission.')) {
            return $denied;
        }
        $data = $request->validate([
            'reason_id' => ['required', 'integer'],
            'manager_approval_id' => ['nullable', 'integer'],
        ]);
        // POS-CANCEL-TERMINAL-1: the cancellation prints at the CURRENT counter (the operator's selected
        // terminal), while the order keeps its original terminal_id. No selection → no override.
        $current = (int) $request->session()->get(self::TERMINAL_SESSION_KEY, 0);
        try {
            $result = $this->pos->cancelHeldSale($sale, (int) $data['reason_id'], isset($data['manager_approval_id']) ? (int) $data['manager_approval_id'] : null, auth('tenant')->user(), $current > 0 ? $current : null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['sale_id' => $result['sale']->id, 'status' => $result['sale']->status]);
    }

    /** SPLIT BILL parity — move selected quantities onto a new held check on the same table (each pays on its own). */
    public function splitHeldSale(Request $request, int $sale): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.sales-orders.split-bill.store', 'Splitting a bill needs the Split Bill permission.')) {
            return $denied;
        }
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        try {
            $result = $this->pos->splitHeldSale($sale, $data['lines'], auth('tenant')->user(), $terminal->id, $data['notes'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $parent = $result['parent']->load(['restaurantTable:id,table_no,name', 'restaurantWaiter:id,name', 'lines']);
        $child = $result['child']->load(['restaurantTable:id,table_no,name', 'restaurantWaiter:id,name', 'lines']);

        return response()->json([
            'child' => $this->heldSaleView($child, true),
            'parent' => $parent->status === 'held' ? $this->heldSaleView($parent, true) : ['id' => (int) $parent->id, 'status' => $parent->status],
        ], 201);
    }

    // ═══════════════════════ EDGE-CASHIER-UI-2 — Recall / Dine-In browser workflow data ═══════════════════════

    /** RECALL parity — the open checks (held + draft) on the bound branch, limited to the order types this operator may run. */
    public function heldSales(): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $allowed = auth('tenant')->user()?->effectiveAllowedOrderTypes() ?? [];
        $sales = SalesOrder::on('tenant')->with(['restaurantTable:id,table_no,name', 'restaurantWaiter:id,name', 'lines:id,sales_order_id,quantity'])
            ->where('branch_id', $branchId)->where('status', 'held')
            ->when($allowed, fn ($q) => $q->whereIn('order_type', $allowed))
            ->orderByDesc('id')->limit(100)->get();

        return response()->json(['held_sales' => $sales->map(fn (SalesOrder $s) => $this->heldSaleView($s))->values()]);
    }

    /** One open check with its lines — what Recall / Add Round / Review & Pay load into the cart. */
    public function heldSale(int $sale): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $row = SalesOrder::on('tenant')->with(['restaurantTable:id,table_no,name', 'restaurantWaiter:id,name', 'lines'])
            ->where('branch_id', $branchId)->where('status', 'held')->find($sale);
        if (! $row) {
            return response()->json(['message' => 'No open check found.'], 404);
        }

        return response()->json(['held_sale' => $this->heldSaleView($row, true)]);
    }

    /** Active cancellation reasons (the shared VoidReason book) for cancel-order / void flows. */
    public function voidReasons(): JsonResponse
    {
        return response()->json(['reasons' => VoidReason::on('tenant')->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'reason_type', 'requires_manager_approval'])]);
    }

    private function heldSaleView(SalesOrder $s, bool $withLines = false): array
    {
        $out = [
            'id' => (int) $s->id, 'sale_no' => $s->sale_no, 'sale_uuid' => $s->sale_uuid,
            'order_type' => $s->order_type, 'is_draft' => (bool) $s->is_draft, 'status' => $s->status,
            'subtotal' => (float) $s->subtotal, 'discount_amount' => (float) $s->discount_amount,
            'tax_amount' => (float) $s->tax_amount, 'service_charge_amount' => (float) $s->service_charge_amount,
            'grand_total' => (float) $s->grand_total,
            'customer_id' => $s->customer_id ? (int) $s->customer_id : null,
            'customer_name' => $s->customer_name, 'customer_phone' => $s->customer_phone,
            // The ORIGINAL counter — Recall never rewrites it (POS-RECALL-TERMINAL-1 / POS-CANCEL-TERMINAL-1).
            'terminal_id' => (int) $s->terminal_id,
            'vehicle_number' => $s->vehicle_number,
            'restaurant_table_session_id' => $s->restaurant_table_session_id ? (int) $s->restaurant_table_session_id : null,
            'table_no' => $s->restaurantTable?->table_no,
            'waiter_id' => $s->restaurant_waiter_id ? (int) $s->restaurant_waiter_id : null,
            'waiter_name' => $s->restaurantWaiter?->name,
            'item_count' => (float) $s->lines->sum('quantity'),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
        if ($withLines) {
            $out['lines'] = $s->lines->map(fn ($l) => [
                'id' => (int) $l->id, 'product_id' => (int) $l->product_id,
                'product_variant_id' => $l->product_variant_id ? (int) $l->product_variant_id : null,
                'product_name' => $l->product_name, 'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price, 'line_total' => (float) $l->line_total,
                'kot_sent_quantity' => (float) $l->kot_sent_quantity,
                // DEAL parity: the page rebuilds a deal as ONE cart row from its header; components ride underneath.
                'line_kind' => (string) ($l->line_kind ?? 'standard'),
                'combo_id' => $l->combo_id ? (int) $l->combo_id : null,
                'parent_line_id' => $l->parent_sales_order_line_id ? (int) $l->parent_sales_order_line_id : null,
            ])->values();
            $out['discount_type'] = (string) ($s->discount_type ?? 'none');
            $out['discount_value'] = (float) $s->discount_value;
            $out['promo_code'] = $s->promo_code;
            $out['delivery_channel_id'] = $s->delivery_channel_id ? (int) $s->delivery_channel_id : null;
            $out['delivery_rider_id'] = $s->delivery_rider_id ? (int) $s->delivery_rider_id : null;
            $out['delivery_address'] = $s->delivery_address;
            $out['delivery_charge_amount'] = (float) ($s->delivery_charge_amount ?? 0);
        }

        return $out;
    }
}
