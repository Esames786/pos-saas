<?php

namespace App\Http\Controllers\Edge;

use App\Exceptions\SaleIdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Edge\Concerns\ResolvesEdgePosContext;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\VoidReason;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalOrderLifecycleService;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeLocalTableOperationsService;
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
    use ResolvesEdgePosContext {
        selectedTerminal as private resolveSelectedTerminal;
    }

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeLocalPosService $pos,
        private readonly EdgeLocalOrderLifecycleService $lifecycle,
        private readonly EdgeLocalTableOperationsService $tables,
    ) {
    }

    /** W-B canonical contract (§3.2): a missing / stale terminal answers 422 with Online's `code: INVALID_TERMINAL`. */
    protected function selectedTerminal(Request $request): \App\Models\Tenant\Terminal|JsonResponse
    {
        if ($refused = \App\Services\Edge\EdgePosRuntimeFactory::adoptRequestedTerminal($request, (int) $this->context->requireCurrent()->branch_id, fn ($t) => $this->denyUnlessMayOperateTerminal($t))) {
            return $refused;
        }

        return \App\Services\Edge\EdgePosRuntimeFactory::terminalOrCoded($this->resolveSelectedTerminal($request));
    }

    /**
     * Online HeldSaleController::store TABLE_HAS_OPEN_ORDERS (409): a NEW check on a table session that already carries an
     * open one answers with the session + its open orders so the page offers "continue" (same keys as Online). A non-locking
     * pre-check for the response shape only — EdgeLocalPosService still refuses under the row lock (a race → 422).
     */
    private function tableHasOpenOrders(array $data): ?JsonResponse
    {
        if (! empty($data['held_sale_id']) || empty($data['restaurant_table_session_id'])) {
            return null;
        }
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $session = \App\Models\Tenant\RestaurantTableSession::on('tenant')->with(['table', 'waiter'])
            ->where('branch_id', $branchId)->whereIn('status', ['open', 'bill_requested'])
            ->find((int) $data['restaurant_table_session_id']);
        if (! $session) {
            return null;
        }
        $orders = SalesOrder::on('tenant')->with('lines')->where('restaurant_table_session_id', $session->id)
            ->where('status', 'held')->orderByDesc('updated_at')->get();
        if ($orders->isEmpty()) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'code' => 'TABLE_HAS_OPEN_ORDERS',
            'message' => 'This table already has an open check. Continue it to add the next round.',
            'table_session_id' => (int) $session->id,
            'branch_id' => (int) $session->branch_id,
            'session' => $this->tables->sessionPayload($session) + ['branch_id' => (int) $session->branch_id],
            'orders' => $orders->map(fn (SalesOrder $s) => $this->openOrderView($s))->values(),
        ], 409);
    }

    /**
     * Online HeldSaleController::openOrderPayload shape (O14) — shared by the 409 above and the table-session open-orders
     * twin (EdgeLocalRestaurantController::sessionOpenOrders). `recall_url` is null on Edge: the page recalls through the
     * runtime route map (posIndex / heldShow), never a Cloud URL.
     */
    public static function openOrderView(SalesOrder $sale): array
    {
        $sale->loadMissing('lines');

        return [
            'id' => (int) $sale->id,
            'sale_no' => $sale->sale_no,
            'order_type' => $sale->order_type,
            'terminal_id' => $sale->terminal_id !== null ? (int) $sale->terminal_id : null,
            'restaurant_table_session_id' => $sale->restaurant_table_session_id !== null ? (int) $sale->restaurant_table_session_id : null,
            'restaurant_table_id' => $sale->restaurant_table_id !== null ? (int) $sale->restaurant_table_id : null,
            'is_draft' => (bool) $sale->is_draft,
            'grand_total' => (float) $sale->grand_total,
            'grand_total_formatted' => number_format((float) $sale->grand_total, 2),
            'items_count' => $sale->lines->count(),
            'created_at' => $sale->created_at?->format('d M Y H:i'),
            'updated_at' => $sale->updated_at?->diffForHumans(),
            'recall_url' => null,
            'lines' => $sale->lines->map(fn ($l) => self::onlineLineView($l))->values(),
        ];
    }

    /** One held line in Online's ajaxList / openOrderPayload shape (W2 re-hydration keys included). */
    public static function onlineLineView($l): array
    {
        return [
            'id' => (int) $l->id,
            'product_id' => (int) $l->product_id,
            'product_variant_id' => $l->product_variant_id ? (int) $l->product_variant_id : null,
            'parent_sales_order_line_id' => $l->parent_sales_order_line_id ? (int) $l->parent_sales_order_line_id : null,
            'line_kind' => (string) ($l->line_kind ?? 'standard'),
            'combo_id' => $l->combo_id ? (int) $l->combo_id : null,
            'quantity' => (float) $l->quantity,
            'unit_price' => (float) $l->unit_price,
            'discount_amount' => (float) $l->discount_amount,
            'tax_amount' => (float) $l->tax_amount,
            'line_total' => (float) $l->line_total,
            'product_name' => $l->product_name,
            'variant_name' => $l->variant_name,
            'unit_code' => $l->unit_code,
            'modifiers' => is_array($l->modifiers) ? $l->modifiers : [],
            'kot_sent' => (bool) $l->kot_sent,
            'kot_sent_quantity' => (float) ($l->kot_sent_quantity ?? 0),
            'kitchen_note' => $l->kitchen_note,
        ];
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
            // R21 (Team 2 EdgeLocalPosService): a revision may re-target the check's order type / table session.
            'change_order_details' => ['nullable', 'boolean'],
            'vehicle_number' => ['nullable', 'string', 'max:50', 'required_if:order_type,quick_sale'],
            'restaurant_waiter_id' => ['nullable', 'integer', 'required_if:order_type,quick_sale'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['required_without:lines.*.combo_id', 'nullable', 'integer'],
            'lines.*.combo_id' => ['nullable', 'integer'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            // W-G3 (G1): Online HeldSaleController::store `nullable|string` (JSON) decoded by normalizeLineModifiers; arrays stay.
            'lines.*.modifiers' => ['nullable'],
            // W-G3 (G2): Online `lines.*.client_line_key` / `parent_client_line_key` / `line_kind` — the page matches the
            // response's `client_line_key` to learn each saved line id (submitHeldSale → item._dbLineId).
            'lines.*.client_line_key' => ['nullable', 'string', 'max:120'],
            'lines.*.parent_client_line_key' => ['nullable', 'string', 'max:120'],
            'lines.*.line_kind' => ['nullable', 'in:standard,combo_header,component,modifier'],
            // W2 (Online lines.*.kitchen_note / lines.*.discount_amount) — validated by EdgeLocalPosService; not stripped on Hold.
            'lines.*.kitchen_note' => ['nullable', 'string', 'max:500'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'void_items' => ['nullable', 'array'],
            'void_items.*.old_line_id' => ['required_with:void_items', 'integer'],
            'void_items.*.quantity' => ['required_with:void_items', 'numeric', 'gt:0'],
            'void_items.*.reason_id' => ['required_with:void_items', 'integer'],
            'void_items.*.manager_approval_id' => ['nullable', 'integer'],
        ]);
        // W-G3 (G1): the shared view's multipart shape (JSON-string modifiers, deal component rows) → Online's normalised lines.
        $data['lines'] = $this->normalizeSharedLines($data['lines']);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        if ($conflict = $this->tableHasOpenOrders($data)) {
            return $conflict;
        }
        // T3-3 (D-06): remember the newest KOT batch BEFORE a revision that voids sent lines, so the correction
        // Reminder is planned for exactly the cancel batch this save creates (Team 5 EdgeLocalPrintKotService).
        $hasVoids = ! empty($data['held_sale_id']) && ! empty($data['void_items']);
        $batchBefore = $hasVoids ? (int) \App\Models\Tenant\KotBatch::on('tenant')->where('sales_order_id', (int) $data['held_sale_id'])->max('id') : null;
        try {
            $sale = $this->pos->holdOrReviseSale($data, auth('tenant')->user(), $terminal->id);
        } catch (\App\Exceptions\ShiftException $e) {
            return \App\Services\Edge\EdgePosRuntimeFactory::noOpenShift($e);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $voidJobs = [];
        if ($hasVoids) {
            $voidJobs = app(\App\Services\Edge\EdgeLocalPrintKotService::class)->queueLineVoidCorrectionReminders($sale, (int) $terminal->id, $batchBefore);
        }

        // W-G3 (G2) — Online HeldSaleController::store answers per saved line `{id, client_line_key, kot_sent, kot_sent_quantity}`
        // (its $savedLinePayload); the page matches `client_line_key` to set item._dbLineId so the NEXT Hold carries
        // `sales_order_line_id` (a kitchen-sent line is then continued, never "removed"). Same keys here — the Edge extras
        // (line_uuid, product_id, quantity, unit_price) the old page reads stay additive. A deal's header row carries the
        // deal's key; its server-expanded component rows carry none (the page posts them as components, which are dropped).
        $clientKeys = $this->pos->lastSavedLineClientKeys();

        return response()->json([
            'void_print_jobs' => collect($voidJobs)->filter(fn ($j) => $j instanceof \App\Models\Tenant\PrintJob)->map(fn ($j) => $this->jobView($j))->values(),
            'sale_id' => $sale->id, 'sale_no' => $sale->sale_no, 'sale_uuid' => $sale->sale_uuid,
            'status' => $sale->status, 'is_draft' => (bool) $sale->is_draft, 'grand_total' => (float) $sale->grand_total,
            'restaurant_table_session_id' => $sale->restaurant_table_session_id,
            'lines' => $sale->lines()->orderBy('id')->get()->map(fn ($l) => [
                'id' => (int) $l->id,
                'client_line_key' => $clientKeys[(int) $l->id] ?? null,
                'kot_sent' => (bool) $l->kot_sent,
                'kot_sent_quantity' => (float) ($l->kot_sent_quantity ?? 0),
                'line_uuid' => $l->line_uuid,
                'product_id' => (int) $l->product_id,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
            ])->values(),
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

    /**
     * Settle (pay) a held sale with cash — closes the table session when it was the last open check.
     *
     * W-G1 — Direct Pay printing on a held settle, Online parity (SalesOrderController::store with held_sale_id: the intents are
     * validated :833-834, hashed :57-58 of SaleIdempotencyService, stored on the paid row :392, orchestrated AFTER the commit
     * :616 and re-orchestrated on an idempotent replay :636; the response carries `printing` + `idempotent_replay` :651-660).
     * The SAME EdgeLocalPrintDirectPayService the Direct Pay endpoint uses (EdgeLocalPosController::storeSale) runs the shared
     * DirectPayPrintOrchestrator: ensure-once receipt, existing-KOT reuse, Reminder plan — a retried settle never queues twice.
     */
    public function settleHeldSale(Request $request, int $sale): JsonResponse
    {
        // W-B (shared POS, §3.2 "Pay a held order"): the shared page sends the SAME payload it sends Online's POST /pos with
        // held_sale_id — every key this settle cannot apply (lines, order_type, branch_id, customer …) is simply not validated
        // and therefore ignored. A2 (owner decision): the manual discount + its manager approval are a SETTLEMENT concern —
        // accepted here and consumed inside the settle transaction (EdgeLocalPosService::settleHeldSale).
        $data = $request->validate([
            'client_uuid' => ['required', 'string', 'max:36'],
            // Online Direct Pay print intents (tenant.pos.store `in:print,skip`); BOTH are REQUIRED below with Online's own
            // `printing` refusal (Phase 3 Stage A — the shared view always sends them).
            'kot_print_intent' => ['nullable', 'in:print,skip'],
            'receipt_print_intent' => ['nullable', 'in:print,skip'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.tendered_amount' => ['nullable', 'numeric'],
            'payments.*.transaction_ref' => ['nullable', 'string', 'max:190'],
            'discount_type' => ['nullable', 'string'],
            'discount_value' => ['nullable', 'numeric'],
            'manager_approval_id' => ['nullable', 'integer'],
            'promo_code' => ['nullable', 'string', 'max:50'],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        // Online SalesOrderController::store on tenant.pos.store: a Direct Pay settle without BOTH intents is refused with the
        // SAME 422 shape ({message, errors.printing}) BEFORE any authority/terminal work — the printing decision is part of the sale.
        if (! isset($data['kot_print_intent']) || ! isset($data['receipt_print_intent'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'printing' => 'Choose the Direct Pay KOT and Receipt intent before completing the sale.',
            ]);
        }
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        if ($denied = $this->denyUnlessMayCompleteSale()) {
            return $denied;
        }
        // Online `idempotent_replay`: a sale already finalized under this client_uuid before this request is a replay (the
        // service then verifies the hash — same payload replays, a different one is a 409). Read BEFORE the settle so the
        // flag is truthful for the request that actually posted the sale.
        $idempotency = app(\App\Services\Sales\SaleIdempotencyService::class);
        $normalizedUuid = $idempotency->normalizeClientUuid($data['client_uuid']);
        $replay = $normalizedUuid !== null && $idempotency->findFinalized($normalizedUuid) !== null;
        try {
            $settled = $this->pos->settleHeldSale($sale, $data, auth('tenant')->user(), $terminal->id);
        } catch (SaleIdempotencyConflictException $e) {
            throw $e; // renders its own 409/503
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Direct Pay printing AFTER the paid settle committed (and on an idempotent replay) — the durable orchestrator state
        // drives it; a printing failure is recorded as retryable state and NEVER unwinds the sale (Online semantics). The same
        // call, the same service and the same fallback as the Edge Direct Pay endpoint (EdgeLocalPosController::storeSale).
        $printing = null;
        if ($settled->direct_pay_print_state) {
            try {
                $printing = app(\App\Services\Edge\EdgeLocalPrintDirectPayService::class)
                    ->afterPaidSale($settled, $settled->direct_pay_print_state['kot_intent'] ?? null, $settled->direct_pay_print_state['receipt_intent'] ?? null);
            } catch (\Throwable $e) {
                report($e);
                $printing = null; // the page falls back to print_intents → POST /sales/{sale}/printing/retry
            }
        }

        return response()->json([
            'sale_id' => $settled->id, 'sale_no' => $settled->sale_no, 'sale_uuid' => $settled->sale_uuid,
            'status' => $settled->status, 'grand_total' => (float) $settled->grand_total,
            'paid_amount' => (float) $settled->paid_amount,
            'change_amount' => (float) $settled->payments()->first()?->change_amount,
            'edge_sync_state' => $settled->edge_sync_state,
            // Online saleResponse keys the shared page reads after a held settle (SalesOrderController :651-660).
            'idempotent_replay' => $replay,
            'printing' => $printing,
            'print_intents' => $settled->direct_pay_print_state
                ? ['kot' => $settled->direct_pay_print_state['kot_intent'] ?? null, 'receipt' => $settled->direct_pay_print_state['receipt_intent'] ?? null]
                : null,
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

        // T3-2 (D-05): the CANCEL KOT (+ Reminder) jobs this cancel created — a browser/fallback one opens Print Here on the page.
        $jobs = collect(array_merge($result['jobs'] ?? [], $result['reminder_jobs'] ?? []))
            ->filter(fn ($j) => $j instanceof \App\Models\Tenant\PrintJob)->map(fn ($j) => $this->jobView($j))->values();

        return response()->json(['sale_id' => $result['sale']->id, 'status' => $result['sale']->status, 'jobs' => $jobs]);
    }

    /** The print-job fields the page's handlePrintJobs() reads (same names as EdgeLocalPrintJobController::printJobView). */
    private function jobView(\App\Models\Tenant\PrintJob $j): array
    {
        $j = $j->fresh() ?? $j;
        $j->loadMissing('printer');

        return [
            'id' => (int) $j->id, 'job_id' => (int) $j->id, 'job_no' => $j->job_no,
            'document_type' => $j->document_type, 'print_status' => $j->print_status,
            'printer_id' => $j->printer_id !== null ? (int) $j->printer_id : null,
            'printer_type' => $j->printer?->printer_type ?? 'browser',
            'printer_name' => $j->printer?->name ?? 'Print here (browser)', 'fallback' => empty($j->printer_id),
            // path-only (never an absolute http:// URL): the page opens it on the same origin.
            'preview_url' => '/edge/local/pos/print-jobs/' . $j->id . '/document',
            'created_at_human' => $j->created_at?->diffForHumans(),
        ];
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

    // ═══════════ W-B SEPARATE SCREEN — the SAME split-bill page (tenant/sales-orders/split-bill), Edge data + routes ═══════════

    /**
     * Online SplitBillController@create → tenant.sales-orders.split-bill for a HELD check on the bound branch. The POS embeds
     * it in its Split Bill modal (iframe, `?embed=1`) exactly like Online; it is also reachable directly.
     */
    public function splitPage(Request $request, int $sale)
    {
        abort_unless((bool) auth('tenant')->user()?->can('tenant.sales-orders.split-bill'), 403, 'Splitting a bill needs the Split Bill permission (tenant.sales-orders.split-bill).');
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $row = SalesOrder::on('tenant')->with([
            'branch', 'terminal', 'customer', 'restaurantTableSession.table', 'restaurantTableSession.salesOrders',
            'restaurantWaiter', 'restaurantTable', 'lines.product.unit', 'lines.variant',
        ])->where('branch_id', $branchId)->find($sale);
        abort_if(! $row, 404, 'No such check on this Branch Server.');
        if ($row->status !== 'held') {
            return back()->withErrors(['sale' => 'Only held sales can be split.']);
        }
        abort_if(app(\App\Services\Security\UserDataScope::class)->deniesSale(auth('tenant')->user(), $row), 403);

        return view('tenant.sales-orders.split-bill', [
            'salesOrder' => $row,
            'paymentMethods' => \App\Models\Tenant\PaymentMethod::on('tenant')->where('is_active', true)->orderBy('name')->get(),
            'posRuntime' => app(\App\Services\Edge\EdgePosRuntimeFactory::class)->make($request),
        ]);
    }

    /**
     * Online SplitBillController@store (the split form's POST: lines[i][sales_order_line_id], lines[i][quantity], notes) →
     * EdgeLocalPosService::splitHeldSale (the same authority the JSON split uses), then the SAME top-window breakout Online
     * answers with (the form lives in the POS modal's iframe): back to the shared POS on the table, recalling the remaining
     * original (or the new check when the original emptied).
     */
    public function splitFromPage(Request $request, int $sale)
    {
        abort_unless((bool) auth('tenant')->user()?->can('tenant.sales-orders.split-bill.store'), 403, 'Splitting a bill needs the Split Bill permission (tenant.sales-orders.split-bill.store).');
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array'],
            'lines.*.sales_order_line_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
        ]);
        $selected = collect($data['lines'])
            ->filter(fn ($l) => ! empty($l['sales_order_line_id']) && (float) ($l['quantity'] ?? 0) > 0)
            ->map(fn ($l) => ['sales_order_line_id' => (int) $l['sales_order_line_id'], 'quantity' => (float) $l['quantity']])
            ->values()->all();
        if ($selected === []) {
            return back()->withErrors(['split' => 'Select at least one item quantity to split.'])->withInput();
        }
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return back()->withErrors(['split' => (string) ($terminal->getData(true)['message'] ?? 'Select a terminal first.')])->withInput();
        }
        try {
            $result = $this->pos->splitHeldSale($sale, $selected, auth('tenant')->user(), $terminal->id, $data['notes'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['split' => $e->getMessage()])->withInput();
        }
        $parent = $result['parent']->fresh();
        $child = $result['child']->fresh();
        $recallId = $parent->status === 'held' ? $parent->id : $child->id;
        $sessionId = $child->restaurant_table_session_id;
        $target = \App\Services\Edge\EdgePosRuntimeFactory::SHARED_PAGE . '?held_sale_id=' . $recallId
            . ($sessionId ? '&table_session_id=' . $sessionId : '') . '&mode=dine_in&branch_id=' . $child->branch_id;
        session()->flash('status', 'Split into held order ' . $child->sale_no . '. Pay each order from the POS.');
        $encoded = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return response('<!doctype html><meta charset="utf-8"><script>window.top.location.href=' . $encoded . ';</script>'
            . 'Split complete — returning to the POS…');
    }

    // ═══════════════════════ EDGE-CASHIER-UI-2 — Recall / Dine-In browser workflow data ═══════════════════════

    /** RECALL parity — the open checks (held + draft) on the bound branch, limited to the order types this operator may run. */
    public function heldSales(Request $request): JsonResponse
    {
        // A26 — Online HeldSaleController::ajaxList scoping: allowed order types, the optional type filter (narrows, never
        // widens) and UserDataScope (branch / terminal / order-type assignments).
        $sales = $this->lifecycle->heldSalesQuery(auth('tenant')->user(), $request->query('order_type'))
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(100)->get();
        // W-B canonical contract (§3.2): Online /api/pos/held-sales embeds every check's lines under `sales` — the shared page
        // recalls client-side without a second call. The old page keeps reading `held_sales` (additive).
        $sales->load(['lines', 'customer:id,name,phone', 'deliveryChannel:id,name,type', 'deliveryRider:id,name']);

        return response()->json([
            'held_sales' => $sales->map(fn (SalesOrder $s) => $this->heldSaleView($s))->values(),
            'sales' => $sales->map(fn (SalesOrder $s) => $this->onlineHeldView($s))->values(),
        ]);
    }

    /** Online HeldSaleController::ajaxList row (keys + formats), from the local check. */
    private function onlineHeldView(SalesOrder $s): array
    {
        return [
            'id' => (int) $s->id,
            'sale_no' => $s->sale_no,
            'is_draft' => (bool) $s->is_draft,
            'order_type' => $s->order_type,
            'branch_id' => (int) $s->branch_id,
            'terminal_id' => $s->terminal_id !== null ? (int) $s->terminal_id : null,
            'restaurant_table_session_id' => $s->restaurant_table_session_id !== null ? (int) $s->restaurant_table_session_id : null,
            'restaurant_table_id' => $s->restaurant_table_id !== null ? (int) $s->restaurant_table_id : null,
            'delivery_channel_id' => $s->delivery_channel_id !== null ? (int) $s->delivery_channel_id : null,
            'delivery_rider_id' => $s->delivery_rider_id !== null ? (int) $s->delivery_rider_id : null,
            'delivery_channel' => $s->deliveryChannel?->name,
            'delivery_channel_type' => $s->deliveryChannel?->type,
            'delivery_rider' => $s->deliveryRider?->name,
            'delivery_address' => $s->delivery_address,
            'delivery_charge_amount' => (float) $s->delivery_charge_amount,
            'vehicle_number' => $s->vehicle_number,
            'restaurant_waiter_id' => $s->restaurant_waiter_id !== null ? (int) $s->restaurant_waiter_id : null,
            'waiter' => $s->restaurantWaiter?->name,
            'table' => $s->restaurantTable?->table_no,
            'discount_type' => $s->discount_type,
            'discount_value' => (float) $s->discount_value,
            'customer' => $s->customer_name ?: $s->customer?->name ?: 'Walk-in',
            'customer_id' => $s->customer_id !== null ? (int) $s->customer_id : null,
            'customer_name' => $s->customer_name ?: $s->customer?->name,
            'customer_phone' => $s->customer_phone ?: $s->customer?->phone,
            'total' => number_format((float) $s->grand_total, 2),
            'items' => $s->lines->count(),
            'time' => $s->created_at?->diffForHumans(),
            'notes' => $s->notes,
            'lines' => $s->lines->map(fn ($l) => self::onlineLineView($l))->values(),
        ];
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

        $view = $this->heldSaleView($row, true);
        // R10 — the session bar data (Online HeldSaleController::sessionPayload); A29/R31 — the dead-session facts the
        // Online POS computes on recall (a held bill whose table session is closed/cancelled → deadSessionModal).
        $session = $row->restaurant_table_session_id ? \App\Models\Tenant\RestaurantTableSession::on('tenant')->find((int) $row->restaurant_table_session_id) : null;
        $view['table_session'] = $session && in_array($session->status, ['open', 'bill_requested'], true) ? $this->tables->sessionPayload($session) : null;
        $view['dead_session'] = $this->tables->deadSessionInfo($row);

        return response()->json(['held_sale' => $view]);
    }

    // ═══════════════════════ W3 — order lifecycle (Team 3) ═══════════════════════

    /** A27 — Recent / Completed Orders (Online POSController::recentSales; tenant.api.pos.* is permission-free on Online). */
    public function recentSales(Request $request): JsonResponse
    {
        $rows = $this->lifecycle->recentSales(auth('tenant')->user(), $request->query('order_type'));
        // W-B canonical contract (§3.2): Online POSController::recentSales `time` = TenantClock::formatSale('d M, h:i A') and
        // `ago` = diffForHumans(); the ISO instant stays available as `time_iso`.
        $models = SalesOrder::on('tenant')->with(['shift', 'branch'])->whereIn('id', collect($rows)->pluck('id')->all() ?: [0])->get()->keyBy('id');
        $clock = app(\App\Support\TenantClock::class);
        $rows = collect($rows)->map(function (array $r) use ($models, $clock) {
            $s = $models->get($r['id']);
            $r['time_iso'] = $r['time'] ?? null;
            $r['time'] = $s ? $clock->formatSale($s, 'd M, h:i A') : ($r['time'] ?? null);
            $r['ago'] = optional($s?->sale_date ?? $s?->created_at)->diffForHumans();

            return $r;
        })->values();

        return response()->json(['sales' => $rows]);
    }

    /** A29/R31 — dead-session recovery: a held bill on a closed session moves to a NEW session on a free table. */
    public function reattachTable(Request $request, int $sale): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.held-sales.reattach-table', 'Moving a bill off a closed table needs the Reattach Table permission.')) {
            return $denied;
        }
        $data = $request->validate(['restaurant_table_id' => ['required', 'integer']]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        try {
            $r = $this->tables->reattachTable($sale, (int) $data['restaurant_table_id'], $terminal, auth('tenant')->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true, 'sale_id' => (int) $r['sale']->id,
            'restaurant_table_session_id' => (int) $r['session']->id,
            'restaurant_table_id' => (int) $r['session']->restaurant_table_id,
            'table_no' => $r['session']->table?->table_no,
            'message' => 'Bill moved to table ' . ($r['session']->table?->table_no ?? '') . '.',
        ]);
    }

    /** Active cancellation reasons (the shared VoidReason book) for cancel-order / void flows. */
    public function voidReasons(): JsonResponse
    {
        // R25/R27 — the branch approval modes the Online page embeds (branchCancellationModes / branchLineCancellationModes)
        // so the reason picker can say "Manager code required" before the cashier commits; the shared
        // KotCancellationService still decides on the server (same line-falls-back-to-order rule).
        $branch = \App\Models\Tenant\Branch::on('tenant')->find((int) $this->context->requireCurrent()->branch_id);
        $orderMode = (string) ($branch?->held_kot_cancellation_approval_mode ?: \App\Models\Tenant\Branch::KOT_CANCELLATION_MANAGER_REQUIRED);
        $lineMode = (string) ($branch?->held_kot_line_cancellation_approval_mode ?: $orderMode);

        return response()->json(['reasons' => VoidReason::on('tenant')->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'reason_type', 'requires_manager_approval']),
            'order_approval_mode' => $orderMode, 'line_approval_mode' => $lineMode]);
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
            'updated_at' => $s->updated_at?->toIso8601String(),
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
                // W2 re-hydration on Recall / Add Round (Online ajaxList line payload): options, variant, unit, kitchen note, line discount.
                'modifiers' => is_array($l->modifiers) ? $l->modifiers : [],
                'variant_name' => $l->variant_name, 'unit_code' => $l->unit_code, 'kitchen_note' => $l->kitchen_note,
                'discount_amount' => (float) $l->discount_amount,
            ])->values();
            $out['notes'] = $s->notes;
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
