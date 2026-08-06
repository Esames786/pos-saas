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
        $query = Shift::with(['branch', 'terminal', 'openedBy', 'closedBy'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        return view('tenant.shifts.index', [
            'shifts'   => $query->paginate(15)->withQueryString(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
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
        $data = $request->validate([
            'branch_id'    => ['required', 'exists:branches,id'],
            'terminal_id'  => ['required', 'exists:terminals,id'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'opening_notes' => ['nullable', 'string'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);

        // BRANCH-OPERATING-MODE-HARDEN-1: a Local POS (active) branch opens/closes
        // shifts on its Branch Server — cloud must not, or cash reconciliation forks.
        app(\App\Services\Edge\BranchOperatingModeService::class)->assertSaleMutationAllowed($branch);

        $terminal = Terminal::where('id', $data['terminal_id'])
            ->where('branch_id', $branch->id)
            ->firstOrFail();

        try {
            // Canonical, row-locked open (reused by Cloud + future Edge). Freezes business_date + tz.
            $shift = $shiftService->open(
                $branch,
                $terminal,
                (int) auth('tenant')->id(),
                (float) $data['opening_cash'],
                $data['opening_notes'] ?? null,
            );
        } catch (ShiftException $e) {
            return back()->withErrors(['terminal_id' => $e->getMessage()])->withInput();
        }

        return redirect('/shifts')->with('status', 'Shift opened. Business date '
            . $shift->business_date->toDateString() . ' (' . $shift->timezone_name . ').');
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

        $shift = $shiftService->activeShiftForTerminal($terminal);

        return response()->json([
            'has_terminal'  => (bool) $terminal,
            'open'          => (bool) $shift,
            'shift_id'      => $shift?->id,
            'business_date' => $shift?->business_date?->toDateString(),
            'timezone'      => $shift?->timezone_name,
            'opened_at'     => $shift ? app(\App\Support\TenantClock::class)->format($shift->opened_at, 'd M H:i') : null,
            'open_url'      => url('/shifts/open'),
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

        // A shift owns real, unsettled cash/kitchen work — held (unpaid) sales and open restaurant
        // tables — that must be resolved before the drawer can be reconciled. (Offline print jobs
        // are NOT operational work and never block a close.)
        $work = $shiftService->unresolvedWork($shift);
        if (! empty($work['held_sales']) || ! empty($work['open_tables'])) {
            $bits = [];
            if (! empty($work['held_sales'])) {
                $bits[] = count($work['held_sales']) . ' held order(s): ' . implode(', ', $work['held_sales']);
            }
            if (! empty($work['open_tables'])) {
                $bits[] = count($work['open_tables']) . ' open table(s): ' . implode(', ', $work['open_tables']);
            }

            return back()->withErrors([
                'shift' => 'Settle all open work before closing this shift — ' . implode('; ', $bits) . '.',
            ])->withInput();
        }

        $data = $request->validate([
            'counted_cash'    => ['nullable', 'numeric', 'min:0'],
            'closing_notes'   => ['nullable', 'string'],
            'denominations'   => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($shift, $data) {
            $expectedCash = (float) $shift->expected_cash;
            $countedCash  = $this->calculateCashCount($data, 'shift', $shift->id);

            if ($countedCash === null) {
                $countedCash = (float) ($data['counted_cash'] ?? 0);
            }

            $shift->update([
                'closed_by_user_id' => auth('tenant')->id(),
                'counted_cash'      => $countedCash,
                'cash_variance'     => $countedCash - $expectedCash,
                'status'            => 'closed',
                'closed_at'         => now(),
                'closing_notes'     => $data['closing_notes'] ?? null,
            ]);
        });

        return redirect('/shifts/' . $shift->id)->with('status', 'Shift closed successfully.');
    }

    private function calculateCashCount(array $data, string $sourceType, int $sourceId): ?float
    {
        if (empty($data['denominations'])) {
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
