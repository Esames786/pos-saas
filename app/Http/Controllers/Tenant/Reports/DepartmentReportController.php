<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Services\Reports\DepartmentReportService;
use Illuminate\Http\Request;

/**
 * DEPARTMENT-FOUNDATION-1 — read-only department reports built from existing
 * sales and stock-ledger data. No stock movement, no GL.
 */
class DepartmentReportController extends Controller
{
    public function __construct(private readonly DepartmentReportService $service) {}

    public function sales(Request $request)
    {
        $filters = [
            'date_from'     => $request->input('date_from', today()->subDays(6)->format('Y-m-d')),
            'date_to'       => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_id'     => $request->input('branch_id'),
            'department_id' => $request->input('department_id'),
            'order_type'    => $request->input('order_type'),
        ];

        $report      = $this->service->sales($filters);
        $branches    = Branch::where('status', 'active')->orderBy('name')->get();
        $departments = Department::with('branch')->orderBy('branch_id')->orderBy('sort_order')->orderBy('name')->get();
        $orderTypes  = ['quick_sale', 'takeaway', 'dine_in', 'delivery'];

        return view('tenant.reports.departments.sales', compact('report', 'filters', 'branches', 'departments', 'orderTypes'));
    }

    public function consumption(Request $request)
    {
        $filters = [
            'date_from'     => $request->input('date_from', today()->subDays(6)->format('Y-m-d')),
            'date_to'       => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_id'     => $request->input('branch_id'),
            'department_id' => $request->input('department_id'),
            'movement_type' => $request->input('movement_type'),
        ];

        $report        = $this->service->consumption($filters);
        $branches      = Branch::where('status', 'active')->orderBy('name')->get();
        $departments   = Department::with('branch')->orderBy('branch_id')->orderBy('sort_order')->orderBy('name')->get();
        $movementTypes = DepartmentReportService::CONSUMPTION_MOVEMENT_TYPES;

        return view('tenant.reports.departments.consumption', compact('report', 'filters', 'branches', 'departments', 'movementTypes'));
    }

    // ── DEPT-2 custody reports ───────────────────────────────────────────────

    public function stock(Request $request)
    {
        $filters = [
            'branch_id'     => $request->input('branch_id'),
            'department_id' => $request->input('department_id'),
            'nonzero'       => $request->boolean('nonzero', true),
        ];

        $report      = $this->service->stock($filters);
        $branches    = Branch::where('status', 'active')->orderBy('name')->get();
        $departments = Department::with('branch')->orderBy('branch_id')->orderBy('sort_order')->orderBy('name')->get();

        return view('tenant.reports.departments.stock', compact('report', 'filters', 'branches', 'departments'));
    }

    public function movements(Request $request)
    {
        $filters = [
            'date_from'     => $request->input('date_from', today()->subDays(6)->format('Y-m-d')),
            'date_to'       => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_id'     => $request->input('branch_id'),
            'department_id' => $request->input('department_id'),
            'movement_type' => $request->input('movement_type'),
            'product'       => $request->input('product'),
        ];

        $rows          = $this->service->movements($filters);
        $branches      = Branch::where('status', 'active')->orderBy('name')->get();
        $departments   = Department::with('branch')->orderBy('branch_id')->orderBy('sort_order')->orderBy('name')->get();
        $movementTypes = \App\Models\Tenant\DepartmentStockLedger::MOVEMENT_TYPES;

        return view('tenant.reports.departments.movements', compact('rows', 'filters', 'branches', 'departments', 'movementTypes'));
    }

    public function allocation(Request $request)
    {
        $filters = [
            'branch_id'      => $request->input('branch_id'),
            'only_allocated' => $request->boolean('only_allocated'),
        ];

        $report   = $this->service->allocation($filters);
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.reports.departments.allocation', compact('report', 'filters', 'branches'));
    }

    // ── DEPT-3A shadow consumption exceptions ────────────────────────────────

    public function consumptionExceptions(Request $request)
    {
        $filters = [
            'date_from'     => $request->input('date_from', today()->subDays(29)->format('Y-m-d')),
            'date_to'       => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_id'     => $request->input('branch_id'),
            'department_id' => $request->input('department_id'),
            'reason'        => $request->input('reason'),
            'status'        => $request->input('status'),
            'product'       => $request->input('product'),
        ];

        $rows        = $this->service->consumptionExceptions($filters);
        $branches    = Branch::where('status', 'active')->orderBy('name')->get();
        $departments = Department::with('branch')->orderBy('branch_id')->orderBy('sort_order')->orderBy('name')->get();
        $reasons     = \App\Models\Tenant\DepartmentConsumptionException::REASONS;

        return view('tenant.reports.departments.consumption-exceptions', compact('rows', 'filters', 'branches', 'departments', 'reasons'));
    }

    public function resolveException(\App\Models\Tenant\DepartmentConsumptionException $exception)
    {
        $exception->update(['status' => 'resolved', 'resolved_by' => auth()->id(), 'resolved_at' => now()]);

        return back()->with('status', 'Exception marked resolved.');
    }

    public function ignoreException(\App\Models\Tenant\DepartmentConsumptionException $exception)
    {
        $exception->update(['status' => 'ignored', 'resolved_by' => auth()->id(), 'resolved_at' => now()]);

        return back()->with('status', 'Exception marked ignored.');
    }
}
