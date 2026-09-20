<?php

namespace App\Http\Controllers\Edge;

use App\Exceptions\ShiftException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Edge\Concerns\ResolvesEdgePosContext;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Sales\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * W0c (Team 4 owns) — the cashier's SHIFT surface on the Branch Server: status, summary (tender breakup / blind count /
 * zero drawer / operating date), open, close. Split out of EdgeLocalPosController; same routes, same behaviour.
 * The SHARED ShiftService does the work; this controller adds no authority of its own.
 */
class EdgeLocalShiftController extends Controller
{
    use ResolvesEdgePosContext;

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly ShiftService $shifts,
    ) {
    }

    /** Current shift state for the selected terminal. */
    public function shiftStatus(Request $request): JsonResponse
    {
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $open = Shift::on('tenant')->where('terminal_id', $terminal->id)->where('status', 'open')->latest('id')->first();
        // HIDE-AMOUNTS parity (W0b, audit R1.1/R1.4): the SAME AmountVisibility rule as the shift summary — figures are
        // STRIPPED for an operator the branch hides them from, never merely hidden in the page.
        $branch = Branch::on('tenant')->find((int) $this->context->requireCurrent()->branch_id);
        $maySeeAmounts = app(\App\Support\AmountVisibility::class)->allows(auth('tenant')->user(), $branch);

        return response()->json([
            'terminal_id' => $terminal->id,
            'may_see_amounts' => $maySeeAmounts,
            'shift' => $open ? [
                'id' => $open->id,
                'shift_uuid' => $open->shift_uuid,
                'business_date' => $open->business_date?->toDateString(),
                'opened_at' => $open->opened_at?->toIso8601String(),
                'total_sales' => $maySeeAmounts ? (float) $open->total_sales : null,
                'expected_cash' => $maySeeAmounts ? (float) $open->expected_cash : null,
            ] : null,
        ]);
    }

    /** Open a shift on the selected terminal (the authenticated cashier is the opener). */
    public function openShift(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.shifts.store', 'Opening a shift needs the Open Shift permission.')) {
            return $denied;
        }
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

    /**
     * SHIFT parity — what the Online Shift screens show, for this terminal and the branch: the operating
     * business date (OPERATING-DATE-1: the open shift's business_date, never the wall clock), the open shift
     * with its tender breakup and cancellations (SHIFT-CANCELLATIONS-1 / SHIFT-RECONCILE), the branch's other
     * open shifts (terminal lock), and HIDE-AMOUNTS (blind count) decided ONCE by the shared AmountVisibility
     * rule — figures are STRIPPED here, never merely hidden in the page.
     */
    public function shiftSummary(Request $request): JsonResponse
    {
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $branch = Branch::on('tenant')->findOrFail((int) $this->context->requireCurrent()->branch_id);
        $user = auth('tenant')->user();
        $maySeeAmounts = app(\App\Support\AmountVisibility::class)->allows($user, $branch);
        $money = fn ($v) => $maySeeAmounts ? (float) $v : null;

        $open = Shift::on('tenant')->where('terminal_id', $terminal->id)->where('status', 'open')->latest('id')->first();
        $breakup = null;
        if ($open) {
            $cancelled = SalesOrder::on('tenant')->where('shift_id', $open->id)->where('status', 'cancelled')
                ->selectRaw('COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as amount')->first();
            $voided = DB::connection('tenant')->table('sales_order_line_cancellations as c')
                ->join('sales_orders as o', 'o.id', '=', 'c.sales_order_id')
                ->where('o.shift_id', $open->id)
                ->selectRaw('COUNT(*) as lines_count, COALESCE(SUM(c.quantity), 0) as units')->first();
            $breakup = [
                'opening_cash' => $money($open->opening_cash),
                'total_sales' => $money($open->total_sales),
                'cash' => $money($open->total_cash),
                'card' => $money($open->total_card),
                'bank' => $money($open->total_bank_transfer),
                'cheque' => $money($open->total_cheque),
                'expected_cash' => $money($open->expected_cash),
                // cancellation COUNTS stay visible to an operator (a bill was thrown away); amounts follow the rule.
                'cancelled_bills' => (int) ($cancelled->bills ?? 0),
                'cancelled_amount' => $money($cancelled->amount ?? 0),
                'voided_lines' => (int) ($voided->lines_count ?? 0),
                'voided_units' => (float) ($voided->units ?? 0),
            ];
        }

        $branchOpen = Shift::on('tenant')->with('terminal:id,name')->where('branch_id', $branch->id)->where('status', 'open')
            ->orderBy('terminal_id')->get()->map(fn (Shift $s) => [
                'terminal_id' => (int) $s->terminal_id, 'terminal_name' => $s->terminal?->name,
                'business_date' => $s->business_date?->toDateString(), 'opened_at' => $s->opened_at?->toIso8601String(),
                'is_current' => (int) $s->terminal_id === (int) $terminal->id,
            ])->values();

        return response()->json([
            'terminal_id' => $terminal->id,
            'operating_business_date' => app(\App\Support\TenantClock::class)->operatingBusinessDate($branch),
            'current_business_date' => app(\App\Support\TenantClock::class)->currentBusinessDate($branch),
            'may_see_amounts' => $maySeeAmounts,
            'shift' => $open ? [
                'id' => $open->id, 'shift_uuid' => $open->shift_uuid,
                'business_date' => $open->business_date?->toDateString(),
                'opened_at' => $open->opened_at?->toIso8601String(),
                'zero_drawer' => abs((float) $open->expected_cash) < 0.005, // ZERO-DRAWER-1: nothing to count
            ] : null,
            'breakup' => $breakup,
            'branch_open_shifts' => $branchOpen,
        ]);
    }

    /**
     * Close the selected terminal's open shift — the SHARED ShiftService::closeShift operation.
     * ZERO-DRAWER-1: an omitted count is passed down as NULL and resolved under the row lock (an
     * empty drawer closes; a drawer holding cash demands a typed count — 0 must be typed deliberately).
     */
    public function closeShift(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.shifts.close', 'Closing a shift needs the Close Shift permission.')) {
            return $denied;
        }
        $data = $request->validate([
            'counted_cash' => ['nullable', 'numeric', 'min:0'],
            'closing_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $open = Shift::on('tenant')->where('terminal_id', $terminal->id)->where('status', 'open')->latest('id')->first();
        if (! $open) {
            return response()->json(['message' => 'No open shift on this terminal.'], 422);
        }
        try {
            $counted = $request->filled('counted_cash') ? (float) $data['counted_cash'] : null;
            $closed = $this->shifts->closeShift($open, (int) auth('tenant')->id(), $counted, $data['closing_notes'] ?? null);
        } catch (ShiftException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'shift_id' => $closed->id,
            'status' => $closed->status,
            'expected_cash' => (float) $closed->expected_cash,
            'counted_cash' => (float) $closed->counted_cash,
            'cash_variance' => (float) $closed->cash_variance,
        ]);
    }
}
