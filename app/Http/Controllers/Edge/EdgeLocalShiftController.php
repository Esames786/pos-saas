<?php

namespace App\Http\Controllers\Edge;

use App\Exceptions\ShiftException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Edge\Concerns\ResolvesEdgePosContext;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashCountLine;
use App\Models\Tenant\Currency;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Sales\ShiftService;
use App\Support\AmountVisibility;
use App\Support\TenantClock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * W4 (Team 4) — the cashier's SHIFT surface on the Branch Server: status (badge), summary (tender breakup / blind
 * count / zero drawer / operating date / denominations), open (opening cash + notes), close (denomination count OR a
 * typed total + closing notes), and the Edge-local shift history + shift detail screens.
 *
 * The SHARED ShiftService does every state change (open/close/zero-drawer/blank-count refusal under the row lock);
 * the SHARED AmountVisibility decides every figure; this controller adds no business rule of its own. The Online
 * references are Tenant\ShiftController (index/create/store/posStatus/show/closeForm/close) and the
 * tenant/shifts/{index,open,show,close} views.
 *
 * NOT here, on purpose (see docs/status/edge-w4-team4-report.md):
 *   - CASH-SHORTAGE-1: Online raises a DRAFT expense voucher (CashShortageExpenseService) when a drawer closes short.
 *     That is a Cloud finance posting; the branch server never creates a financial event. The shortage IS recorded
 *     on the shift (cash_variance); the voucher needs a Team 6 contract (shift-close event) + owner approval.
 *   - Close Branch / Daily Closing (R1.10): owner-dependent — each counter closes its own shift here.
 */
class EdgeLocalShiftController extends Controller
{
    use ResolvesEdgePosContext;

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly ShiftService $shifts,
    ) {
    }

    private function branch(): Branch
    {
        return Branch::on('tenant')->findOrFail((int) $this->context->requireCurrent()->branch_id);
    }

    private function maySeeAmounts(?Branch $branch = null): bool
    {
        return app(AmountVisibility::class)->allows(auth('tenant')->user(), $branch ?? $this->branch());
    }

    /**
     * Current shift state for the selected terminal — the POS badge (Online ShiftController@posStatus). Backward
     * compatible: every key the page already reads stays; the Online badge keys are ADDED (has_terminal, open,
     * business_date, timezone, opened_at display, terminal name, server epoch). No figure for a blind-count operator.
     */
    public function shiftStatus(Request $request): JsonResponse
    {
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $open = Shift::on('tenant')->where('terminal_id', $terminal->id)->where('status', 'open')->latest('id')->first();
        // HIDE-AMOUNTS parity (W0b, audit R1.1/R1.4): the SAME AmountVisibility rule as the shift summary — figures are
        // STRIPPED for an operator the branch hides them from, never merely hidden in the page.
        $branch = $this->branch();
        $maySeeAmounts = $this->maySeeAmounts($branch);
        $clock = app(TenantClock::class);

        return response()->json([
            'terminal_id' => $terminal->id,
            'terminal_name' => $terminal->name,
            'has_terminal' => true,
            'open' => (bool) $open,
            'business_date' => $open?->business_date?->toDateString(),
            'timezone' => $open?->timezone_name ?: $clock->businessTimezone($branch),
            'opened_at_display' => $open ? $clock->format($open->opened_at, 'd M H:i', $open->timezone_name) : null,
            'server_epoch_ms' => (int) round(microtime(true) * 1000),
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

    /**
     * Open a shift on the selected terminal — what the Online cashier sees on /shifts/open for HIS terminal: Opening
     * Cash REQUIRED (numeric ≥ 0, ShiftController@store) + optional Opening Notes; the authenticated operator is the
     * opener. The branch-wide multi-terminal open is a manager page on Online (not the cashier surface).
     */
    public function openShift(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.shifts.store', 'Opening a shift needs the Open Shift permission.')) {
            return $denied;
        }
        $data = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'opening_notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'opening_cash.required' => 'Enter the opening cash in the drawer (type 0 for an empty drawer).',
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $branch = $this->branch();
        try {
            $shift = $this->shifts->open($branch, $terminal, (int) auth('tenant')->id(), (float) $data['opening_cash'], $data['opening_notes'] ?? null);
        } catch (ShiftException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'shift_id' => $shift->id, 'shift_uuid' => $shift->shift_uuid, 'business_date' => $shift->business_date?->toDateString(),
            'terminal_id' => (int) $terminal->id, 'terminal_name' => $terminal->name,
        ], 201);
    }

    /**
     * SHIFT parity — what the Online Shift screens show, for this terminal and the branch: the operating
     * business date (OPERATING-DATE-1: the open shift's business_date, never the wall clock), the open shift
     * with its tender breakup and cancellations (SHIFT-CANCELLATIONS-1 / SHIFT-RECONCILE), the branch's other
     * open shifts (terminal lock), the default-currency denominations for the count grid, and HIDE-AMOUNTS
     * (blind count) decided ONCE by the shared AmountVisibility rule — figures are STRIPPED here, never merely
     * hidden in the page.
     */
    public function shiftSummary(Request $request): JsonResponse
    {
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $branch = $this->branch();
        $user = auth('tenant')->user();
        $maySeeAmounts = $this->maySeeAmounts($branch);

        $open = Shift::on('tenant')->where('terminal_id', $terminal->id)->where('status', 'open')->latest('id')->first();
        $breakup = $open ? $this->breakup($open, $maySeeAmounts) : null;

        $branchOpen = Shift::on('tenant')->with('terminal:id,name')->where('branch_id', $branch->id)->where('status', 'open')
            ->orderBy('terminal_id')->get()->map(fn (Shift $s) => [
                'terminal_id' => (int) $s->terminal_id, 'terminal_name' => $s->terminal?->name,
                'business_date' => $s->business_date?->toDateString(), 'opened_at' => $s->opened_at?->toIso8601String(),
                'is_current' => (int) $s->terminal_id === (int) $terminal->id,
            ])->values();

        $clock = app(TenantClock::class);

        return response()->json([
            'terminal_id' => $terminal->id,
            'terminal_name' => $terminal->name,
            'branch_name' => $branch->name,
            'operating_business_date' => $clock->operatingBusinessDate($branch),
            'current_business_date' => $clock->currentBusinessDate($branch),
            'may_see_amounts' => $maySeeAmounts,
            'shift' => $open ? [
                'id' => $open->id, 'shift_uuid' => $open->shift_uuid,
                'business_date' => $open->business_date?->toDateString(),
                'opened_at' => $open->opened_at?->toIso8601String(),
                'opened_at_display' => $clock->format($open->opened_at, 'Y-m-d H:i', $open->timezone_name),
                'opening_notes' => $open->opening_notes,
                'zero_drawer' => abs((float) $open->expected_cash) < 0.005, // ZERO-DRAWER-1: nothing to count
            ] : null,
            'breakup' => $breakup,
            'branch_open_shifts' => $branchOpen,
            // Online close form: the default currency's denominations (tenant.partials.cash-count), highest first.
            'currency' => $this->denominationBook(),
            'permissions' => [
                'can_open' => (bool) $user?->can('tenant.shifts.store'),
                'can_close' => (bool) $user?->can('tenant.shifts.close'),
                'can_view_history' => (bool) $user?->can('tenant.shifts.index'),
                'can_view_detail' => (bool) $user?->can('tenant.shifts.show'),
            ],
            'history_url' => url('/edge/local/pos/shifts'),
        ]);
    }

    /**
     * Close the selected terminal's open shift — the SHARED ShiftService::closeShift operation, with the Online close
     * form's inputs: an optional DENOMINATION count (the total wins when any quantity is entered, recorded as
     * CashCountLine rows exactly like ShiftController@calculateCashCount), else the typed Counted Cash, plus Closing
     * Notes. ZERO-DRAWER-1: no count at all is passed down as NULL and resolved under the row lock (an empty drawer
     * closes; a drawer holding cash demands a count). The count lines and the close are ONE transaction — a refused
     * close leaves no orphan count behind.
     */
    public function closeShift(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.shifts.close', 'Closing a shift needs the Close Shift permission.')) {
            return $denied;
        }
        $data = $request->validate([
            'counted_cash' => ['nullable', 'numeric', 'min:0'],
            'closing_notes' => ['nullable', 'string', 'max:1000'],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        $open = Shift::on('tenant')->where('terminal_id', $terminal->id)->where('status', 'open')->latest('id')->first();
        if (! $open) {
            return response()->json(['message' => 'No open shift on this terminal.'], 422);
        }
        $source = 'manual';
        try {
            $closed = DB::connection('tenant')->transaction(function () use ($data, $request, $open, &$source) {
                $counted = $this->recordDenominationCount((array) ($data['denominations'] ?? []), (int) $open->id);
                if ($counted !== null) {
                    $source = 'denominations';
                } elseif ($request->filled('counted_cash')) {
                    $counted = (float) $data['counted_cash'];
                } else {
                    $source = 'zero_drawer';
                }

                return $this->shifts->closeShift($open, (int) auth('tenant')->id(), $counted, $data['closing_notes'] ?? null);
            });
        } catch (ShiftException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $maySeeAmounts = $this->maySeeAmounts();
        $variance = (float) $closed->cash_variance;

        return response()->json([
            'shift_id' => $closed->id,
            'status' => $closed->status,
            'may_see_amounts' => $maySeeAmounts,
            // HIDE-AMOUNTS: the close answer carries figures only for an operator allowed to read them (Online lands a
            // blind-count operator on the masked shift page, never on the figures).
            'expected_cash' => $maySeeAmounts ? (float) $closed->expected_cash : null,
            'counted_cash' => $maySeeAmounts ? (float) $closed->counted_cash : null,
            'cash_variance' => $maySeeAmounts ? $variance : null,
            'count_source' => $source,
            'closing_notes' => $closed->closing_notes,
            // CASH-SHORTAGE-1 boundary: the shortage is recorded on the shift; the Online draft expense voucher is a
            // Cloud finance posting the branch server does not create (Team 6 contract + owner approval pending).
            'shortage_voucher' => [
                'raised' => false,
                'status' => 'not_raised_on_branch_server',
                'message' => $maySeeAmounts && $variance < -0.009
                    ? 'Cash short by ' . number_format(-$variance, 2) . ' — recorded on this shift. The branch server does not raise the finance draft expense voucher; finance settles the shortage from the shift record.'
                    : null,
            ],
            'detail_url' => url('/edge/local/pos/shifts/' . $closed->id),
        ]);
    }

    // ── Edge-local shift screens (Online /shifts and /shifts/{shift}) ─────────────────────────────────────────────

    /**
     * SHIFT HISTORY — Online ShiftController@index on the branch server: this branch's LOCAL shifts (the appliance
     * never holds Cloud shift history), newest first, with the Online filters (status, business-date range on the
     * FROZEN business_date — SHIFT-DATE-FILTER-1 — and Today/Yesterday quick links on the OPERATING date) and the
     * per-row cash detail only for an operator allowed to read money (HIDE-AMOUNTS-2).
     */
    public function historyScreen(Request $request): View
    {
        abort_unless((bool) auth('tenant')->user()?->can('tenant.shifts.index'), 403, 'Viewing shifts needs the Shifts permission (tenant.shifts.index).');
        $branch = $this->branch();
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,closed'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = Shift::on('tenant')->with(['terminal:id,name', 'openedBy:id,name', 'closedBy:id,name'])
            ->where('branch_id', $branch->id)->orderByDesc('id');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $onDay = function ($q, string $op, string $date) {
            $q->where(function ($w) use ($op, $date) {
                $w->where(fn ($a) => $a->whereNotNull('business_date')->whereDate('business_date', $op, $date))
                    ->orWhere(fn ($a) => $a->whereNull('business_date')->whereDate('opened_at', $op, $date));
            });
        };
        if (! empty($filters['date_from'])) {
            $onDay($query, '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $onDay($query, '<=', $filters['date_to']);
        }
        $shifts = $query->paginate(15)->withQueryString();
        $clock = app(TenantClock::class);
        $today = $clock->operatingBusinessDate($branch);
        $user = auth('tenant')->user();

        return view('edge.finance.shifts-index', [
            'branchName' => $branch->name,
            'userName' => $user?->name,
            'shifts' => $shifts,
            'maySeeAmounts' => $this->maySeeAmounts($branch),
            'openCount' => Shift::on('tenant')->where('branch_id', $branch->id)->where('status', 'open')->count(),
            'filters' => $filters,
            'today' => $today,
            'yesterday' => \Carbon\Carbon::parse($today)->subDay()->format('Y-m-d'),
            'maxDate' => max($today, $clock->currentBusinessDate($branch)),
            'canShow' => (bool) $user?->can('tenant.shifts.show'),
            'clock' => $clock,
        ]);
    }

    /**
     * SHIFT DETAIL / POST-CLOSE SUMMARY — Online ShiftController@show on the branch server: Shift Summary (branch,
     * terminal, opened/closed by + at, opening/closing notes), Cash Summary (masked for a blind-count operator —
     * HIDE-AMOUNTS-2), the tender breakup + cancellations (SHIFT-CANCELLATIONS-1) and the Cash Count Breakdown.
     */
    public function showScreen(Request $request, int $shift): View
    {
        abort_unless((bool) auth('tenant')->user()?->can('tenant.shifts.show'), 403, 'Viewing a shift needs the Shift detail permission (tenant.shifts.show).');
        $branch = $this->branch();
        $row = Shift::on('tenant')->with(['terminal:id,name', 'openedBy:id,name', 'closedBy:id,name', 'cashCountLines.denomination'])
            ->where('branch_id', $branch->id)->find($shift);
        abort_if(! $row, 404, 'No such shift on this branch server.');
        $maySeeAmounts = $this->maySeeAmounts($branch);
        $user = auth('tenant')->user();

        return view('edge.finance.shifts-show', [
            'branchName' => $branch->name,
            'userName' => $user?->name,
            'shift' => $row,
            'maySeeAmounts' => $maySeeAmounts,
            'breakup' => $this->breakup($row, $maySeeAmounts),
            'clock' => app(TenantClock::class),
            'canIndex' => (bool) $user?->can('tenant.shifts.index'),
            'canClose' => (bool) $user?->can('tenant.shifts.close'),
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** Tender breakup + cancellations/voids for ONE shift (SHIFT-CANCELLATIONS-1 / SHIFT-RECONCILE-2), masked per the rule. */
    private function breakup(Shift $shift, bool $maySeeAmounts): array
    {
        $money = fn ($v) => $maySeeAmounts ? (float) $v : null;
        $cancelled = SalesOrder::on('tenant')->where('shift_id', $shift->id)->where('status', 'cancelled')
            ->selectRaw('COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as amount')->first();
        $voided = DB::connection('tenant')->table('sales_order_line_cancellations as c')
            ->join('sales_orders as o', 'o.id', '=', 'c.sales_order_id')
            ->where('o.shift_id', $shift->id)
            ->selectRaw('COUNT(*) as lines_count, COALESCE(SUM(c.quantity), 0) as units')->first();

        return [
            'opening_cash' => $money($shift->opening_cash),
            'total_sales' => $money($shift->total_sales),
            'cash' => $money($shift->total_cash),
            'card' => $money($shift->total_card),
            'bank' => $money($shift->total_bank_transfer),
            'cheque' => $money($shift->total_cheque),
            'refunds' => $money($shift->total_refunds),
            'cash_refunds' => $money($shift->total_cash_refunds),
            'expected_cash' => $money($shift->expected_cash),
            'counted_cash' => $shift->counted_cash === null ? null : $money($shift->counted_cash),
            'cash_variance' => $shift->cash_variance === null ? null : $money($shift->cash_variance),
            // cancellation COUNTS stay visible to an operator (a bill was thrown away); amounts follow the rule.
            'cancelled_bills' => (int) ($cancelled->bills ?? 0),
            'cancelled_amount' => $money($cancelled->amount ?? 0),
            'voided_lines' => (int) ($voided->lines_count ?? 0),
            'voided_units' => (float) ($voided->units ?? 0),
        ];
    }

    /** The default currency's denominations (the Online close form's count grid), highest first; null when none exist locally. */
    private function denominationBook(): ?array
    {
        $currency = Currency::on('tenant')->where('is_default', true)->with('denominations')->first();
        if (! $currency || $currency->denominations->isEmpty()) {
            return null;
        }

        return [
            'code' => $currency->code,
            'symbol' => $currency->symbol,
            'denominations' => $currency->denominations->sortByDesc(fn ($d) => (float) $d->denomination_value)->values()
                ->map(fn ($d) => ['id' => (int) $d->id, 'value' => (float) $d->denomination_value, 'type' => (string) $d->denomination_type])->all(),
        ];
    }

    /**
     * The Online denomination count (ShiftController@calculateCashCount semantics): an untouched grid — no positive
     * quantity — is NO count (null), never a zero count; otherwise the previous lines for this shift are replaced and
     * the total is Σ quantity × face value over the default currency's denominations.
     */
    private function recordDenominationCount(array $quantities, int $shiftId): ?float
    {
        // Coordinator 25 Sep 2026: the rule now lives in the SHARED App\Services\Sales\CashCountService (Online's
        // ShiftController@calculateCashCount delegates to the same class) — no Edge-only copy of an Online rule.
        return app(\App\Services\Sales\CashCountService::class)->record($quantities, \App\Services\Sales\CashCountService::SOURCE_SHIFT, $shiftId);
    }
}
