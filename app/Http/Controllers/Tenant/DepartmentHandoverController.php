<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentHandover;
use App\Services\Departments\DepartmentHandoverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * THIRD-PARTY-DEPARTMENT-HANDOVER-1 — hand a third-party department's sales to its owner and settle it.
 * Money-only (revenue → payable → cash/bank); never touches stock or COGS.
 */
class DepartmentHandoverController extends Controller
{
    public function __construct(private readonly DepartmentHandoverService $service)
    {
    }

    public function index(Request $request)
    {
        $query = DepartmentHandover::with(['department', 'branch', 'reclassEntry', 'payoutEntry'])
            ->orderByDesc('id');

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return view('tenant.departments.handovers.index', [
            'handovers'        => $query->limit(500)->get(),
            'departments'      => Department::where('is_third_party', true)->with('branch')->orderBy('name')->get(),
            'cashBankAccounts' => CashBankAccount::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'account_type']),
            'filters'          => $request->only(['department_id', 'status']),
        ]);
    }

    /** Post the handover (Dr 4210 / Cr 24xx payable) — triggered by the Department Sales report button. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'date_from'     => ['required', 'date'],
            'date_to'       => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $dept = Department::findOrFail($data['department_id']);

        try {
            $handover = $this->service->postHandover($dept, $data['date_from'], $data['date_to'], Auth::guard('tenant')->id());
        } catch (Throwable $e) {
            return back()->withErrors(['handover' => $e->getMessage()]);
        }

        return redirect(url('/departments/handovers'))
            ->with('status', 'Handed over ' . number_format((float) $handover->handover_total, 2) . ' of ' . $dept->name . ' sales to ' . ($dept->owner_name ?: 'the owner') . '. Record the payout when you pay them.');
    }

    /** Settle the handover by cash/bank (Dr 24xx payable / Cr cash-bank). */
    public function payout(Request $request, DepartmentHandover $handover)
    {
        $data = $request->validate([
            'cash_bank_account_id' => ['required', 'integer', 'exists:cash_bank_accounts,id'],
        ]);

        try {
            $this->service->postPayout($handover, (int) $data['cash_bank_account_id'], Auth::guard('tenant')->id());
        } catch (Throwable $e) {
            return back()->withErrors(['handover' => $e->getMessage()]);
        }

        return back()->with('status', 'Payout recorded for handover DH-' . $handover->id . '.');
    }

    public function reverse(Request $request, DepartmentHandover $handover)
    {
        $reason = (string) $request->input('reason', 'Handover reversed');

        try {
            $this->service->reverse($handover, $reason, Auth::guard('tenant')->id());
        } catch (Throwable $e) {
            return back()->withErrors(['handover' => $e->getMessage()]);
        }

        return back()->with('status', 'Handover DH-' . $handover->id . ' reversed.');
    }
}
