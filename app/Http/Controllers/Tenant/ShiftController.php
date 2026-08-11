<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashCountLine;
use App\Models\Tenant\Currency;
use App\Exceptions\ShiftException;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        // SHIFT-BRANCH-UX-1: history is grouped by branch (open/close happen per branch), so order
        // branch-first, newest shift within each branch.
        $query = Shift::with(['branch', 'terminal', 'openedBy', 'closedBy'])
            ->orderBy('branch_id')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // True open-shift count per branch (not just the current page) so the "Close Branch" action
        // is accurate even when a branch's shifts span pages.
        $openCounts = Shift::where('status', 'open')
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->branch_id))
            ->selectRaw('branch_id, COUNT(*) as c')
            ->groupBy('branch_id')
            ->pluck('c', 'branch_id');

        return view('tenant.shifts.index', [
            'shifts'     => $query->paginate(15)->withQueryString(),
            'branches'   => Branch::where('status', 'active')->orderBy('name')->get(),
            'openCounts' => $openCounts,
        ]);
    }

    public function create()
    {
        return view('tenant.shifts.open', [
            'branches'  => Branch::where('status', 'active')->orderBy('name')->get(),
            'terminals' => Terminal::where('status', 'active')->with('branch')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, ShiftService $shiftService)
    {
        // SHIFT-BRANCH-UX-1: branch-oriented open. Accepts one or more terminals of a branch (the UI
        // pre-selects ALL of the branch's terminals) with a shared opening cash + optional per-terminal
        // override. Backward compatible: a single legacy `terminal_id` is folded into `terminal_ids`.
        if ($request->filled('terminal_id') && ! $request->filled('terminal_ids')) {
            $request->merge(['terminal_ids' => [$request->input('terminal_id')]]);
        }

        $data = $request->validate([
            'branch_id'                 => ['required', 'exists:branches,id'],
            'terminal_ids'              => ['required', 'array', 'min:1'],
            'terminal_ids.*'            => ['integer', 'exists:terminals,id'],
            'opening_cash'              => ['required', 'numeric', 'min:0'],
            'terminal_opening_cash'     => ['nullable', 'array'],
            'terminal_opening_cash.*'   => ['nullable', 'numeric', 'min:0'],
            'opening_notes'             => ['nullable', 'string'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);

        // BRANCH-OPERATING-MODE-HARDEN-1: a Local POS (active) branch opens/closes
        // shifts on its Branch Server — cloud must not, or cash reconciliation forks.
        app(\App\Services\Edge\BranchOperatingModeService::class)->assertSaleMutationAllowed($branch);

        $overrides = [];
        foreach ((array) ($data['terminal_opening_cash'] ?? []) as $tid => $value) {
            if ($value !== null && $value !== '') {
                $overrides[(int) $tid] = (float) $value;
            }
        }

        $result = $shiftService->openMany(
            $branch,
            $data['terminal_ids'],
            (int) auth('tenant')->id(),
            (float) $data['opening_cash'],
            $overrides,
            $data['opening_notes'] ?? null,
        );

        $openedCount = count($result['opened']);
        if ($openedCount === 0) {
            return back()->withErrors(['terminal_ids' => 'No shift was opened. ' . implode('; ', $result['skipped'])])->withInput();
        }

        $msg = $openedCount . ' shift(s) opened for ' . $branch->name;
        if (! empty($result['skipped'])) {
            $msg .= ' — skipped ' . implode('; ', $result['skipped']);
        }

        return redirect('/shifts')->with('status', $msg . '.');
    }

    /**
     * SHIFT-TIMEZONE-BUSINESS-DATE-1 (R/S): lightweight shift status for the POS badge. Given the
     * client-selected terminal, report whether it has an open shift and that shift's frozen
     * business date/timezone, so the cashier sees at a glance that POS operations are allowed.
     */
    public function posStatus(Request $request, ShiftService $shiftService)
    {
        $terminal = $request->filled('terminal_id')
            ? Terminal::find($request->input('terminal_id'))
            : null;

        if ($terminal) {
            app(\App\Services\Security\UserDataScope::class)->assertPosSelection(
                auth('tenant')->user(),
                (int) $terminal->branch_id,
                (int) $terminal->id,
            );
        }

        $shift = $shiftService->activeShiftForTerminal($terminal);

        // SHIFT-POS-INTEGRATION-CLOSURE-1: one source of truth for the POS clock + badge. The clock
        // ticks in the SELECTED terminal's shift timezone (falling back to the branch business tz
        // when no shift is open), anchored to this server epoch.
        $branchTz = app(\App\Support\TenantClock::class)->businessTimezone(
            $terminal?->branch ?: ($terminal ? \App\Models\Tenant\Branch::find($terminal->branch_id) : null)
        );

        return response()->json([
            'has_terminal'   => (bool) $terminal,
            'open'           => (bool) $shift,
            'shift_id'       => $shift?->id,
            'shift_uuid'     => $shift?->shift_uuid,
            'business_date'  => $shift?->business_date?->toDateString(),
            'timezone'       => $shift?->timezone_name ?: $branchTz,
            'opened_at'      => $shift ? app(\App\Support\TenantClock::class)->format($shift->opened_at, 'd M H:i', $shift->timezone_name) : null,
            'server_epoch_ms' => (int) round(microtime(true) * 1000),
            'open_url'       => url('/shifts/open'),
        ]);
    }

    public function show(Shift $shift)
    {
        $shift->load(['branch', 'terminal', 'openedBy', 'closedBy', 'cashCountLines.denomination']);

        return view('tenant.shifts.show', compact('shift'));
    }

    public function closeForm(Shift $shift)
    {
        abort_if($shift->status !== 'open', 404);

        return view('tenant.shifts.close', [
            'shift'    => $shift->load(['branch', 'terminal']),
            'currency' => Currency::where('is_default', true)->with('denominations')->first(),
        ]);
    }

    public function close(Request $request, Shift $shift, ShiftService $shiftService)
    {
        abort_if($shift->status !== 'open', 404);

        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(\App\Models\Tenant\Branch::findOrFail($shift->branch_id));

        $data = $request->validate([
            'counted_cash'    => ['nullable', 'numeric', 'min:0'],
            'closing_notes'   => ['nullable', 'string'],
            'denominations'   => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
        ]);

        // HARDEN-1: the close is atomic. Inside the transaction we row-lock the shift and, still
        // holding the lock, assert it is open with no unresolved work — this deterministically
        // orders the close against any in-flight sale/hold (which locks the same shift row via
        // lockOpenShiftForTerminal). A blocked close is a controlled ShiftException, never a 500.
        // (Offline print jobs are NOT operational work and never block a close.)
        try {
            // EDGE-LOCAL-POS-1 (slice 1.1): the lock/assert/update itself is the SHARED ShiftService
            // closeShift operation (also used by the Edge local shift endpoint) — behavior unchanged; the
            // denomination-based counted-cash resolution stays a Cloud-controller concern.
            $countedCash = $this->calculateCashCount($data, 'shift', $shift->id);
            if ($countedCash === null && $request->filled('counted_cash')) {
                $countedCash = (float) $data['counted_cash'];
            }
            // No count entered at all used to silently close at 0 — recording the whole drawer as
            // missing and raising a full-takings shortage voucher (it happened live: a 28,400
            // shift closed at 0). Typing 0 explicitly is still allowed; defaulting to it is not.
            if ($countedCash === null) {
                return back()->withErrors(['counted_cash' => 'Count the drawer first — enter the counted cash (0 must be typed deliberately).'])->withInput();
            }
            $closed = $shiftService->closeShift($shift, (int) auth('tenant')->id(), $countedCash, $data['closing_notes'] ?? null);
        } catch (ShiftException $e) {
            return back()->withErrors(['shift' => $e->getMessage()])->withInput();
        }

        // CASH-SHORTAGE-1: a short drawer raises a DRAFT expense for finance to settle later.
        $message = 'Shift closed successfully.';
        $short = -(float) $closed->cash_variance;
        if ($short > 0.009) {
            $voucher = app(\App\Services\Finance\CashShortageExpenseService::class)->recordShortage(
                \App\Models\Tenant\Branch::findOrFail($closed->branch_id),
                $closed->business_date?->toDateString() ?? now()->toDateString(),
                $short,
                'shift',
                (int) $closed->id,
                (int) auth('tenant')->id(),
                'Shift #' . $closed->id . ' on terminal ' . ($closed->terminal?->name ?? $closed->terminal_id) . '.'
            );
            $message .= ' Cash short by ' . number_format($short, 2)
                . ($voucher ? ' — draft expense ' . $voucher->voucher_no . ' created for finance to settle.' : '.');
        }

        return redirect('/shifts/' . $shift->id)->with('status', $message);
    }

    /**
     * SHIFT-BRANCH-UX-1: branch-wise close screen. Pick a branch → see all its OPEN terminal shifts
     * with per-terminal Opening / Sales / Expected cash, then close them all at once (per-terminal
     * counts, or one branch total).
     */
    public function closeBranchForm(Request $request)
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $selectedBranchId = $request->input('branch_id');
        $openShifts = collect();

        if ($selectedBranchId) {
            $openShifts = Shift::with('terminal')
                ->where('branch_id', $selectedBranchId)
                ->where('status', 'open')
                ->orderBy('terminal_id')
                ->get();
        }

        return view('tenant.shifts.close-branch', compact('branches', 'selectedBranchId', 'openShifts'));
    }

    public function closeBranch(Request $request, ShiftService $shiftService)
    {
        $data = $request->validate([
            'branch_id'           => ['required', 'exists:branches,id'],
            'mode'                => ['required', 'in:per_terminal,branch_total'],
            // A blank branch count used to default to 0 and freeze a full-takings shortage into
            // the Daily Closing. The count is the point of the exercise — typed 0 only.
            'branch_counted_cash' => ['required_if:mode,branch_total', 'nullable', 'numeric', 'min:0'],
            'counted'             => ['nullable', 'array'],
            'counted.*'           => ['nullable', 'numeric', 'min:0'],
            'closing_notes'       => ['nullable', 'string'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);
        app(\App\Services\Edge\BranchOperatingModeService::class)->assertSaleMutationAllowed($branch);

        try {
            $result = DB::connection('tenant')->transaction(function () use ($branch, $data, $shiftService) {
                // Lock all the branch's open shifts up front (consistent order), then close each via
                // the canonical guard (still blocks on held sales / open tables per terminal).
                $shifts = Shift::where('branch_id', $branch->id)
                    ->where('status', 'open')
                    ->orderBy('terminal_id')
                    ->lockForUpdate()
                    ->get();

                if ($shifts->isEmpty()) {
                    throw new ShiftException('No open shifts on this branch.');
                }

                $userId = (int) auth('tenant')->id();
                $closed = [];

                foreach ($shifts as $shift) {
                    $locked = $shiftService->assertClosableUnderLock($shift);
                    $expected = (float) $locked->expected_cash;

                    // per_terminal: the entered count for THIS terminal. branch_total: each terminal
                    // closes at its expected (per-terminal variance 0 — drawers weren't counted
                    // separately); the real figure is recorded at branch level on a Daily Closing.
                    if ($data['mode'] === 'per_terminal'
                        && (! isset($data['counted'][$locked->id]) || $data['counted'][$locked->id] === '' || $data['counted'][$locked->id] === null)) {
                        // A drawer left blank used to close at 0 — the whole terminal's takings
                        // recorded as missing. Every open drawer must be counted (0 typed counts).
                        throw new ShiftException(
                            'Enter the counted cash for terminal '
                            . ($locked->terminal?->name ?? ('#' . $locked->terminal_id))
                            . ' — every open drawer must be counted before the branch can close.'
                        );
                    }
                    $counted = $data['mode'] === 'per_terminal'
                        ? (float) $data['counted'][$locked->id]
                        : $expected;

                    $locked->update([
                        'closed_by_user_id' => $userId,
                        'counted_cash'      => $counted,
                        'cash_variance'     => $counted - $expected,
                        'status'            => 'closed',
                        'closed_at'         => now(),
                        'closing_notes'     => $data['closing_notes'] ?? null,
                    ]);
                    $closed[] = $locked;
                }

                $daily = null;
                if ($data['mode'] === 'branch_total') {
                    $sumExpected = array_sum(array_map(fn ($s) => (float) $s->getOriginal('expected_cash'), $closed));
                    $branchTotal = (float) ($data['branch_counted_cash'] ?? 0);
                    $sum = fn (string $col) => array_sum(array_map(fn ($s) => (float) $s->{$col}, $closed));

                    $daily = \App\Models\Tenant\DailyClosing::updateOrCreate(
                        [
                            'branch_id'    => $branch->id,
                            'terminal_id'  => null,
                            'closing_date' => $closed[0]->business_date?->toDateString(),
                        ],
                        [
                            'closed_by_user_id'   => $userId,
                            'total_sales'         => $sum('total_sales'),
                            'total_cash'          => $sum('total_cash'),
                            'total_card'          => $sum('total_card'),
                            'total_bank_transfer' => $sum('total_bank_transfer'),
                            'total_cheque'        => $sum('total_cheque'),
                            'total_refunds'       => $sum('total_refunds'),
                            'total_cash_refunds'  => $sum('total_cash_refunds'),
                            'total_discount'      => $sum('total_discount'),
                            'total_tax'           => $sum('total_tax'),
                            'expected_cash'       => $sumExpected,
                            'counted_cash'        => $branchTotal,
                            'cash_variance'       => $branchTotal - $sumExpected,
                            'notes'               => $data['closing_notes'] ?? null,
                        ]
                    );
                }

                return ['closed' => $closed, 'daily' => $daily];
            });
        } catch (ShiftException $e) {
            return back()->withErrors(['branch' => $e->getMessage()])->withInput();
        }

        $msg = count($result['closed']) . ' shift(s) closed for ' . $branch->name;
        if ($result['daily']) {
            $msg .= ' — branch variance ' . number_format((float) $result['daily']->cash_variance, 2)
                . ' recorded on Daily Closing';
        }

        // CASH-SHORTAGE-1: raise ONE draft expense per short source — the branch total when the
        // branch was counted as a whole, otherwise each short terminal drawer separately.
        $shortages = app(\App\Services\Finance\CashShortageExpenseService::class);
        $vouchers = [];
        if ($result['daily']) {
            $short = -(float) $result['daily']->cash_variance;
            if ($short > 0.009) {
                $voucher = $shortages->recordShortage(
                    $branch,
                    (string) $result['daily']->closing_date,
                    $short,
                    'daily_closing',
                    (int) $result['daily']->id,
                    (int) auth('tenant')->id(),
                    'Branch total close for ' . $branch->name . '.'
                );
                if ($voucher) {
                    $vouchers[] = $voucher->voucher_no;
                }
            }
        } else {
            foreach ($result['closed'] as $closedShift) {
                $short = -(float) $closedShift->cash_variance;
                if ($short <= 0.009) {
                    continue;
                }
                $voucher = $shortages->recordShortage(
                    $branch,
                    $closedShift->business_date?->toDateString() ?? now()->toDateString(),
                    $short,
                    'shift',
                    (int) $closedShift->id,
                    (int) auth('tenant')->id(),
                    'Terminal ' . ($closedShift->terminal?->name ?? $closedShift->terminal_id) . ' drawer count.'
                );
                if ($voucher) {
                    $vouchers[] = $voucher->voucher_no;
                }
            }
        }
        if ($vouchers) {
            $msg .= ' — short cash raised as draft expense ' . implode(', ', $vouchers) . ' for finance to settle';
        }

        return redirect('/shifts')->with('status', $msg . '.');
    }

    private function calculateCashCount(array $data, string $sourceType, int $sourceId): ?float
    {
        // The close form submits the denominations array even when every field is blank. That
        // used to come back as a "count" of 0.00, sailing past any blank-count guard — an
        // untouched form is NO count, not a zero count.
        $anyQuantity = collect($data['denominations'] ?? [])->contains(fn ($q) => (int) $q > 0);
        if (empty($data['denominations']) || ! $anyQuantity) {
            return null;
        }

        CashCountLine::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->delete();

        $total = 0;

        $denominations = Currency::where('is_default', true)
            ->with('denominations')
            ->first()
            ?->denominations ?? collect();

        foreach ($denominations as $denomination) {
            $quantity = (int) ($data['denominations'][$denomination->id] ?? 0);
            $amount   = $quantity * (float) $denomination->denomination_value;

            if ($quantity > 0) {
                CashCountLine::create([
                    'source_type'              => $sourceType,
                    'source_id'                => $sourceId,
                    'currency_denomination_id' => $denomination->id,
                    'quantity'                 => $quantity,
                    'amount'                   => $amount,
                ]);
            }

            $total += $amount;
        }

        return $total;
    }
}
