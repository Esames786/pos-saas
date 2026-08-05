<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashCountLine;
use App\Models\Tenant\Currency;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Support\TenantClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'    => ['required', 'exists:branches,id'],
            'terminal_id'  => ['required', 'exists:terminals,id'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'opening_notes' => ['nullable', 'string'],
        ]);

        // BRANCH-OPERATING-MODE-HARDEN-1: a Local POS (active) branch opens/closes
        // shifts on its Branch Server — cloud must not, or cash reconciliation forks.
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(\App\Models\Tenant\Branch::findOrFail($data['branch_id']));

        try {
            $shift = DB::connection('tenant')->transaction(function () use ($data) {
                // Serialize concurrent opens for THIS terminal: lock the terminal row, then
                // re-check for an open shift under the lock, so two requests can never both
                // create an open shift for the same terminal (per-terminal invariant).
                $terminal = Terminal::where('id', $data['terminal_id'])
                    ->where('branch_id', $data['branch_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $hasOpenShift = Shift::where('terminal_id', $terminal->id)
                    ->where('status', 'open')
                    ->exists();

                if ($hasOpenShift) {
                    throw new RuntimeException('This terminal already has an open shift.');
                }

                // Freeze the shift's timezone + business date at OPEN. Immutable afterwards:
                // crossing midnight or changing user/tenant timezone never moves this shift's
                // business date. Branch timezone is the org anchor (falls back to Asia/Karachi).
                $branch = Branch::findOrFail($data['branch_id']);
                $clock  = app(TenantClock::class);
                $tz     = $clock->normalize($branch->timezone) ?? TenantClock::DEFAULT_TIMEZONE;

                return Shift::create([
                    'branch_id'           => $data['branch_id'],
                    'terminal_id'         => $terminal->id,
                    'opened_by_user_id'   => auth('tenant')->id(),
                    'opening_cash'        => $data['opening_cash'],
                    'expected_cash'       => $data['opening_cash'],
                    'status'              => 'open',
                    'opened_at'           => now(),
                    'business_date'       => $clock->businessDateForOpening($tz),
                    'timezone_name'       => $tz,
                    'opening_notes'       => $data['opening_notes'] ?? null,
                ]);
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['terminal_id' => $e->getMessage()])->withInput();
        }

        return redirect('/shifts')->with('status', 'Shift opened. Business date ' . $shift->business_date . ' (' . $shift->timezone_name . ').');
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

    public function close(Request $request, Shift $shift)
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
