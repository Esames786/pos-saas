<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashCountLine;
use App\Models\Tenant\Currency;
use App\Exceptions\ShiftException;
use App\Models\Tenant\SalesOrder;
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

        $shifts = $query->paginate(15)->withQueryString();

        // HIDE-AMOUNTS-2: list kai branches par phaili ho sakti hai, is liye faisla PER BRANCH
        // hota hai — ek branch ka masked hona doosre ka masked hona nahi. Jis row ka branch hi na
        // mile wo masked rehti hai (fail closed), wohi rukh jo AmountVisibility khud apnaata hai.
        $visibility = app(\App\Support\AmountVisibility::class);
        $user       = auth('tenant')->user();
        $maySeeAmounts = collect($shifts->items())
            ->pluck('branch')->filter()->unique('id')
            ->mapWithKeys(fn ($branch) => [$branch->id => $visibility->allows($user, $branch)])
            ->all();

        return view('tenant.shifts.index', [
            'shifts'        => $shifts,
            'branches'      => Branch::where('status', 'active')->orderBy('name')->get(),
            'openCounts'    => $openCounts,
            'maySeeAmounts' => $maySeeAmounts,
        ]);
    }

    public function create()
    {
        // USER-DATA-SCOPE-1: a terminal-restricted cashier opens only HIS terminals — the same
        // scope the POS terminal picker uses. An unbound Owner/Manager still sees every terminal.
        $user  = auth('tenant')->user();
        $scope = app(\App\Services\Security\UserDataScope::class);

        // SHIFT-OPEN-UX-1: terminals that already have an open shift are shown but locked (disabled +
        // "already open" badge) so the operator knows they cannot re-open one — openMany() skips them
        // server-side regardless, this just makes it visible instead of a silent skip.
        $openTerminalIds = Shift::where('status', 'open')->pluck('terminal_id')->map(fn ($id) => (int) $id)->all();

        return view('tenant.shifts.open', [
            'branches'        => $scope->branchesForPos($user),
            'terminals'       => $scope->terminalsForPos($user),
            'openTerminalIds' => $openTerminalIds,
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

        // A terminal cashier may open a shift only on the terminal assigned to them; Owner/Manager
        // (bound to every terminal, or unbound) open any. Draft-expense logic is untouched.
        $scope = app(\App\Services\Security\UserDataScope::class);
        foreach ($data['terminal_ids'] as $tid) {
            $scope->assertCanOperateTerminal(auth('tenant')->user(), (int) $tid);
        }

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

        // HIDE-AMOUNTS-2: ye screen wohi saat figures dikhati hai jo Close Shift par masked hain —
        // Expected Cash samet — magar mask yahan lagta hi nahi tha. Chaar operator accounts is
        // safhe tak pahunch rakhte hain, is liye feature is raaste par be-asar tha.
        return view('tenant.shifts.show', [
            'shift'         => $shift,
            'maySeeAmounts' => app(\App\Support\AmountVisibility::class)
                ->allows(auth('tenant')->user(), $shift->branch),
        ]);
    }

    public function closeForm(Shift $shift)
    {
        abort_if($shift->status !== 'open', 404);

        $shift->load(['branch', 'terminal']);

        // HIDE-AMOUNTS-1 — see closeBranchForm(). Stripped on the model, not hidden in the Blade,
        // because this screen also pre-fills and data-attributes the expected cash.
        $maySeeAmounts = app(\App\Support\AmountVisibility::class)->allows(
            auth('tenant')->user(), $shift->branch
        );

        if (! $maySeeAmounts) {
            foreach (['expected_cash', 'total_sales', 'total_cash', 'total_card',
                      'total_bank_transfer', 'total_cheque', 'total_discount',
                      'total_refunds', 'total_tax', 'opening_cash'] as $field) {
                $shift->setAttribute($field, null);
            }
        }

        return view('tenant.shifts.close', [
            'shift'         => $shift,
            'currency'      => Currency::where('is_default', true)->with('denominations')->first(),
            'maySeeAmounts' => $maySeeAmounts,
        ]);
    }

    public function close(Request $request, Shift $shift, ShiftService $shiftService)
    {
        abort_if($shift->status !== 'open', 404);

        // A cashier closes only their own terminal's shift; Owner/Manager close any. This gates
        // WHO may close WHICH shift — the shortage draft-expense flow below is unchanged.
        app(\App\Services\Security\UserDataScope::class)
            ->assertCanOperateTerminal(auth('tenant')->user(), (int) $shift->terminal_id);

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
        // USER-DATA-SCOPE-1: show only the open shifts this operator may actually close — the same
        // canOperateTerminal rule the close action enforces — so the list and the "Close N shifts"
        // count match what will happen. An unbound Owner/Manager still sees every open shift.
        $user  = auth('tenant')->user();
        $scope = app(\App\Services\Security\UserDataScope::class);

        $branches = $scope->branchesForPos($user);
        $selectedBranchId = $request->input('branch_id');
        $openShifts = collect();

        if ($selectedBranchId) {
            $openShifts = Shift::with('terminal')
                ->where('branch_id', $selectedBranchId)
                ->where('status', 'open')
                ->orderBy('terminal_id')
                ->get()
                ->filter(fn ($shift) => $scope->canOperateTerminal($user, (int) $shift->terminal_id))
                ->values();
        }

        // SHIFT-CANCELLATIONS-1 — the same breakup the Shift Report shows, at the moment it matters
        // most: while the drawer is being counted. "Sales 270,740 but Expected 224,305" looks like a
        // hole until the line underneath says 46,435 of it arrived by card and bank and never went
        // into the till. Cancellations come from the orders, not the shift row, so a shift that
        // closed months ago reports them too.
        $shiftIds = $openShifts->pluck('id')->all();

        $cancelledOrders = $shiftIds ? SalesOrder::query()
            ->whereIn('shift_id', $shiftIds)->where('status', 'cancelled')
            ->selectRaw('shift_id, COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as amount')
            ->groupBy('shift_id')->get()->keyBy('shift_id') : collect();

        $voidedLines = $shiftIds ? DB::connection('tenant')
            ->table('sales_order_line_cancellations as c')
            ->join('sales_orders as o', 'o.id', '=', 'c.sales_order_id')
            ->whereIn('o.shift_id', $shiftIds)
            ->selectRaw('o.shift_id, COUNT(*) as lines_count, COALESCE(SUM(c.quantity), 0) as units')
            ->groupBy('o.shift_id')->get()->keyBy('shift_id') : collect();

        // HIDE-AMOUNTS-1: decided here, once, and the figures are STRIPPED from the models before
        // they reach the view. A Blade-level @if would not be enough on this screen: the Counted
        // input is pre-filled with the expected cash and carries data-expected for the live
        // difference, so the number the operator is meant to verify would still be sitting in the
        // page — in the very box they type into, and one View Source away.
        $maySeeAmounts = app(\App\Support\AmountVisibility::class)->allows(
            $user,
            $selectedBranchId ? Branch::find($selectedBranchId) : null
        );

        if (! $maySeeAmounts) {
            $openShifts = $openShifts->map(function ($shift) {
                foreach (['expected_cash', 'total_sales', 'total_cash', 'total_card',
                          'total_bank_transfer', 'total_cheque', 'total_discount',
                          'total_refunds', 'total_tax', 'opening_cash'] as $field) {
                    $shift->setAttribute($field, null);
                }

                return $shift;
            });
            // The cancellation COUNTS stay (an operator should know a bill was thrown away);
            // their amounts do not.
            $cancelledOrders = $cancelledOrders->map(function ($row) {
                $row->amount = null;

                return $row;
            });
        }

        return view('tenant.shifts.close-branch', compact(
            'branches', 'selectedBranchId', 'openShifts', 'cancelledOrders', 'voidedLines',
            'maySeeAmounts'
        ));
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

        $scope = app(\App\Services\Security\UserDataScope::class);
        $operator = auth('tenant')->user();

        try {
            $result = DB::connection('tenant')->transaction(function () use ($branch, $data, $shiftService, $scope, $operator) {
                // Lock all the branch's open shifts up front (consistent order), then close each via
                // the canonical guard (still blocks on held sales / open tables per terminal).
                // A terminal cashier's Close Branch closes only the terminals they may operate, so a
                // shared close never reaches another cashier's drawer; Owner/Manager close all.
                $shifts = Shift::where('branch_id', $branch->id)
                    ->where('status', 'open')
                    ->orderBy('terminal_id')
                    ->lockForUpdate()
                    ->get()
                    ->filter(fn ($shift) => $scope->canOperateTerminal($operator, (int) $shift->terminal_id))
                    ->values();

                if ($shifts->isEmpty()) {
                    throw new ShiftException('No open shifts you can close on this branch.');
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
