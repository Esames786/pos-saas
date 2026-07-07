<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Services\Departments\DepartmentDashboardService;
use Illuminate\Http\Request;

/**
 * DEPT-5 — Department Command Center. Read-only aggregation of the custody
 * sub-ledger, exceptions, counts, and allocation risk.
 */
class DepartmentDashboardController extends Controller
{
    public function __construct(private readonly DepartmentDashboardService $service) {}

    public function index(Request $request)
    {
        $dashboard = $this->service->build([
            'date_from'     => $request->input('date_from', now()->subDays(7)->toDateString()),
            'date_to'       => $request->input('date_to', now()->toDateString()),
            'branch_id'     => $request->input('branch_id'),
            'department_id' => $request->input('department_id'),
        ]);

        return view('tenant.departments.dashboard', [
            'dashboard'   => $dashboard,
            'branches'    => Branch::where('status', 'active')->orderBy('name')->get(),
            'departments' => Department::with('branch')->where('status', 'active')
                ->orderBy('branch_id')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }
}
