<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Reports\RestaurantReportService;
use Illuminate\Http\Request;

class RestaurantReportController extends Controller
{
    public function __construct(private readonly RestaurantReportService $service) {}

    public function tables(Request $request)
    {
        $filters = [
            'branch_id' => $request->input('branch_id'),
            'date_from' => $request->input('date_from', today()->subDays(29)->format('Y-m-d')),
            'date_to'   => $request->input('date_to',   today()->format('Y-m-d')),
        ];

        $rows     = $this->service->tables($filters);
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.reports.restaurant.tables', compact('rows', 'filters', 'branches'));
    }

    public function waiters(Request $request)
    {
        $filters = [
            'branch_id' => $request->input('branch_id'),
            'date_from' => $request->input('date_from', today()->subDays(29)->format('Y-m-d')),
            'date_to'   => $request->input('date_to',   today()->format('Y-m-d')),
        ];

        $rows     = $this->service->waiters($filters);
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.reports.restaurant.waiters', compact('rows', 'filters', 'branches'));
    }

    public function orderTypes(Request $request)
    {
        $filters = [
            'branch_id' => $request->input('branch_id'),
            'date_from' => $request->input('date_from', today()->subDays(29)->format('Y-m-d')),
            'date_to'   => $request->input('date_to',   today()->format('Y-m-d')),
        ];

        $rows     = $this->service->orderTypes($filters);
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.reports.restaurant.order-types', compact('rows', 'filters', 'branches'));
    }
}
