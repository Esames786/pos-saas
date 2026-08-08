<?php

namespace App\Http\Controllers\Edge;

use App\Exceptions\ShiftException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeOperationalBaselineService;
use App\Services\Sales\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * EDGE-LOCAL-POS-1 — the branch_server-only local POS HTTP surface.
 *
 * Registered ONLY in routes/edge_runtime.php (absent — genuine 404 — on Cloud), behind `edge.auth`
 * (authenticated local tenant session) + `edge.branch` (bound appliance; request tenant/branch ids can never
 * override the binding). ALL authority comes from EdgeBranchContext + the authenticated principal +
 * EdgeLocalPosService's own transactional revalidation; this controller adds no authority of its own.
 * It never calls Cloud finance/inventory mutators (fenced anyway) and cannot bypass the accepted
 * operational-stock baseline (the service refuses before mutation). activation_ready stays false.
 *
 * The selected terminal is per-session (`edge_pos_terminal_id`) and re-validated on every use.
 */
class EdgeLocalPosController extends Controller
{
    private const TERMINAL_SESSION_KEY = 'edge_pos_terminal_id';

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeLocalPosService $pos,
        private readonly ShiftService $shifts,
        private readonly EdgeOperationalBaselineService $baselines,
    ) {
    }

    /** Terminal-selection data: the bound branch's active terminals + open-shift state + current selection. */
    public function terminals(Request $request): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $terminals = Terminal::on('tenant')->where('branch_id', $branchId)->where('status', 'active')
            ->orderBy('name')->get()->map(function (Terminal $t) {
                $open = Shift::on('tenant')->where('terminal_id', $t->id)->where('status', 'open')->latest('id')->first();

                return [
                    'id' => $t->id,
                    'code' => $t->code,
                    'name' => $t->name,
                    'open_shift_id' => $open?->id,
                    'open_shift_uuid' => $open?->shift_uuid,
                    'business_date' => $open?->business_date?->toDateString(),
                ];
            })->values();

        return response()->json([
            'branch_id' => $branchId,
            'terminals' => $terminals,
            'selected_terminal_id' => $request->session()->get(self::TERMINAL_SESSION_KEY),
            'operational_stock_ready' => $this->baselines->currentAccepted() !== null,
            'payment_methods' => PaymentMethod::on('tenant')->where('is_active', true)->where('method_type', 'cash')
                ->get(['id', 'code', 'name', 'method_type']),
        ]);
    }

    /** Select the operating terminal for this session (must belong to the bound branch and be active). */
    public function selectTerminal(Request $request): JsonResponse
    {
        $data = $request->validate(['terminal_id' => ['required', 'integer']]);
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $terminal = Terminal::on('tenant')->where('id', (int) $data['terminal_id'])
            ->where('branch_id', $branchId)->where('status', 'active')->first();
        if (! $terminal) {
            return response()->json(['message' => 'Select an active terminal on this branch.'], 422);
        }
        $request->session()->put(self::TERMINAL_SESSION_KEY, $terminal->id);

        return response()->json(['selected_terminal_id' => $terminal->id]);
    }

    /** Current shift state for the selected terminal. */
    public function shiftStatus(Request $request): JsonResponse
    {
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $open = Shift::on('tenant')->where('terminal_id', $terminal->id)->where('status', 'open')->latest('id')->first();

        return response()->json([
            'terminal_id' => $terminal->id,
            'shift' => $open ? [
                'id' => $open->id,
                'shift_uuid' => $open->shift_uuid,
                'business_date' => $open->business_date?->toDateString(),
                'opened_at' => $open->opened_at?->toIso8601String(),
                'total_sales' => (float) $open->total_sales,
                'expected_cash' => (float) $open->expected_cash,
            ] : null,
        ]);
    }

    /** Open a shift on the selected terminal (the authenticated cashier is the opener). */
    public function openShift(Request $request): JsonResponse
    {
        $data = $request->validate(['opening_cash' => ['nullable', 'numeric', 'min:0']]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $branch = Branch::on('tenant')->find((int) $this->context->requireCurrent()->branch_id);
        try {
            $shift = $this->shifts->open($branch, $terminal, (int) auth('tenant')->id(), (float) ($data['opening_cash'] ?? 0));
        } catch (ShiftException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['shift_id' => $shift->id, 'shift_uuid' => $shift->shift_uuid, 'business_date' => $shift->business_date?->toDateString()], 201);
    }

    /** Local paid Direct Pay (quick_sale/takeaway, cash) through EdgeLocalPosService. */
    public function storeSale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_type' => ['required', 'string'],
            'client_uuid' => ['required', 'string', 'max:36'],
            'discount_type' => ['nullable', 'string'],
            'discount_value' => ['nullable', 'numeric'],
            'promo_code' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.modifiers' => ['nullable', 'array'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.tendered_amount' => ['nullable', 'numeric'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }

        try {
            $sale = $this->pos->completePaidSale($data, auth('tenant')->user(), $terminal->id);
        } catch (\App\Exceptions\SaleIdempotencyConflictException $e) {
            throw $e; // renders itself (409 conflict / 503 pending) — must NOT collapse into a generic 422
        } catch (RuntimeException $e) {
            // controlled operational refusal (no baseline / stock / conversion / shift) — never a 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'sale_id' => $sale->id,
            'sale_no' => $sale->sale_no,
            'sale_uuid' => $sale->sale_uuid,
            'status' => $sale->status,
            'grand_total' => (float) $sale->grand_total,
            'paid_amount' => (float) $sale->paid_amount,
            'change_amount' => (float) $sale->payments()->first()?->change_amount,
            'edge_sync_state' => $sale->edge_sync_state,
        ], 201);
    }

    /** The session-selected terminal, re-validated against the bound branch on EVERY use. */
    private function selectedTerminal(Request $request): Terminal|JsonResponse
    {
        $terminalId = (int) $request->session()->get(self::TERMINAL_SESSION_KEY, 0);
        if ($terminalId <= 0) {
            return response()->json(['message' => 'Select a terminal first.'], 422);
        }
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $terminal = Terminal::on('tenant')->where('id', $terminalId)->where('branch_id', $branchId)->where('status', 'active')->first();
        if (! $terminal) {
            $request->session()->forget(self::TERMINAL_SESSION_KEY);

            return response()->json(['message' => 'The selected terminal is no longer available — select a terminal.'], 422);
        }

        return $terminal;
    }
}
