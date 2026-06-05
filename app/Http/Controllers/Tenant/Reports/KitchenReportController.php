<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Reports\KitchenReportService;
use Illuminate\Http\Request;

class KitchenReportController extends Controller
{
    public function __construct(private readonly KitchenReportService $service) {}

    public function recipeConsumption(Request $request)
    {
        $filters = [
            'date_from' => $request->input('date_from', today()->format('Y-m-d')),
            'date_to'   => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_id' => $request->input('branch_id'),
        ];

        $rows     = $this->service->recipeConsumption($filters);
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.reports.kitchen.recipe-consumption', compact('rows', 'filters', 'branches'));
    }

    public function wastage(Request $request)
    {
        $filters = [
            'date_from' => $request->input('date_from', today()->format('Y-m-d')),
            'date_to'   => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_id' => $request->input('branch_id'),
        ];

        $rows     = $this->service->wastage($filters);
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.reports.kitchen.wastage', compact('rows', 'filters', 'branches'));
    }

    public function production(Request $request)
    {
        $filters = [
            'date_from' => $request->input('date_from', today()->format('Y-m-d')),
            'date_to'   => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_id' => $request->input('branch_id'),
            'status'    => $request->input('status'),
        ];

        $rows       = $this->service->production($filters);
        $branches   = Branch::where('status', 'active')->orderBy('name')->get();
        $statuses   = ['scheduled', 'in_progress', 'completed', 'cancelled'];

        return view('tenant.reports.kitchen.production', compact('rows', 'filters', 'branches', 'statuses'));
    }
}
