<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashCountLine;
use App\Models\Tenant\Currency;
use App\Models\Tenant\DailyClosing;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DailyClosingController extends Controller
{
    public function index(Request $request)
    {
        $query = DailyClosing::with(['branch', 'terminal', 'closedBy'])->latest('closing_date');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('terminal_id')) {
            $query->where('terminal_id', $request->terminal_id);
        }

        return view('tenant.daily-closings.index', [
            'closings'  => $query->paginate(15)->withQueryString(),
            'branches'  => Branch::where('status', 'active')->orderBy('name')->get(),
            'terminals' => Terminal::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('tenant.daily-closings.create', [
            'branches'  => Branch::where('status', 'active')->orderBy('name')->get(),
            'terminals' => Terminal::where('status', 'active')->orderBy('name')->get(),
            'currency'  => Currency::where('is_default', true)->with('denominations')->first(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'       => ['required', 'exists:branches,id'],
            'terminal_id'     => ['nullable', 'exists:terminals,id'],
            'closing_date'    => ['required', 'date'],
            'counted_cash'    => ['nullable', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string'],
            'denominations'   => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $dupQuery = DailyClosing::where('branch_id', $data['branch_id'])
            ->whereDate('closing_date', $data['closing_date']);

        if (!empty($data['terminal_id'])) {
            $dupQuery->where('terminal_id', $data['terminal_id']);
        } else {
            $dupQuery->whereNull('terminal_id');
        }

        if ($dupQuery->exists()) {
            return back()->withErrors(['closing_date' => 'This branch/terminal/date is already closed.'])->withInput();
        }

        DB::transaction(function () use ($data) {
            $shiftsQuery = Shift::where('branch_id', $data['branch_id'])
                ->where('status', 'closed')
                ->whereDate('closed_at', $data['closing_date']);

            if (!empty($data['terminal_id'])) {
                $shiftsQuery->where('terminal_id', $data['terminal_id']);
            }

            $shifts = $shiftsQuery->get();

            $expectedCash = $shifts->sum('expected_cash');
            $countedCash  = (float) ($data['counted_cash'] ?? 0);

            $closing = DailyClosing::create([
                'branch_id'           => $data['branch_id'],
                'terminal_id'         => $data['terminal_id'] ?? null,
                'closing_date'        => $data['closing_date'],
                'closed_by_user_id'   => auth('tenant')->id(),
                'total_sales'         => $shifts->sum('total_sales'),
                'total_cash'          => $shifts->sum('total_cash'),
                'total_card'          => $shifts->sum('total_card'),
                'total_bank_transfer' => $shifts->sum('total_bank_transfer'),
                'total_cheque'        => $shifts->sum('total_cheque'),
                'total_refunds'       => $shifts->sum('total_refunds'),
                'total_cash_refunds'  => $shifts->sum('total_cash_refunds'),
                'total_discount'      => $shifts->sum('total_discount'),
                'total_tax'           => $shifts->sum('total_tax'),
                'expected_cash'       => $expectedCash,
                'counted_cash'        => $countedCash,
                'cash_variance'       => $countedCash - $expectedCash,
                'status'              => 'closed',
                'notes'               => $data['notes'] ?? null,
            ]);

            $denominationTotal = $this->storeCashCount($data, $closing->id);

            if ($denominationTotal !== null) {
                $closing->update([
                    'counted_cash'  => $denominationTotal,
                    'cash_variance' => $denominationTotal - $expectedCash,
                ]);
            }
        });

        return redirect('/daily-closings')->with('status', 'Daily closing completed successfully.');
    }

    public function show(DailyClosing $dailyClosing)
    {
        $dailyClosing->load(['branch', 'closedBy', 'cashCountLines.denomination']);

        return view('tenant.daily-closings.show', compact('dailyClosing'));
    }

    public function approve(DailyClosing $dailyClosing)
    {
        $dailyClosing->update(['status' => 'approved']);

        return back()->with('status', 'Daily closing approved.');
    }

    private function storeCashCount(array $data, int $closingId): ?float
    {
        if (empty($data['denominations'])) {
            return null;
        }

        $currency = Currency::where('is_default', true)->with('denominations')->first();

        if (!$currency) {
            return null;
        }

        $total = 0;

        foreach ($currency->denominations as $denomination) {
            $quantity = (int) ($data['denominations'][$denomination->id] ?? 0);
            $amount   = $quantity * (float) $denomination->denomination_value;

            if ($quantity > 0) {
                CashCountLine::create([
                    'source_type'              => 'daily_closing',
                    'source_id'                => $closingId,
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
