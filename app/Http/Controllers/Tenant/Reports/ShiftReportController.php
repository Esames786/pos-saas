<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\DailyClosing;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use Illuminate\Http\Request;

class ShiftReportController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'date_from' => $request->input('date_from', today()->format('Y-m-d')),
            'date_to'   => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_id' => $request->input('branch_id'),
            'status'    => $request->input('status'),
        ];

        $query = Shift::query()
            ->with(['branch', 'terminal', 'openedBy', 'closedBy'])
            ->when(!empty($filters['branch_id']),
                fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['status']),
                fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['date_from']),
                fn ($q) => $q->whereDate('opened_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),
                fn ($q) => $q->whereDate('opened_at', '<=', $filters['date_to']))
            ->orderByDesc('opened_at');

        $shifts = $query->paginate(20)->withQueryString();

        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        // Summary totals for the filtered result set
        $totals = Shift::query()
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['status']),    fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('opened_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->whereDate('opened_at', '<=', $filters['date_to']))
            ->selectRaw('
                COUNT(*) as shift_count,
                COALESCE(SUM(total_sales), 0)    as total_sales,
                COALESCE(SUM(total_cash), 0)     as total_cash,
                COALESCE(SUM(total_card), 0)     as total_card,
                COALESCE(SUM(total_refunds), 0)  as total_refunds,
                COALESCE(SUM(total_discount), 0) as total_discount,
                COALESCE(SUM(cash_variance), 0)  as total_variance
            ')->first();

        return view('tenant.reports.shifts.index', compact('shifts', 'filters', 'branches', 'totals'));
    }

    public function dailyClosings(Request $request)
    {
        $filters = [
            'date_from'   => $request->input('date_from', today()->subDays(6)->format('Y-m-d')),
            'date_to'     => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_id'   => $request->input('branch_id'),
            'terminal_id' => $request->input('terminal_id'),
            'status'      => $request->input('status'),
        ];

        $closings = DailyClosing::query()
            ->with(['branch', 'terminal', 'closedBy'])
            ->when(!empty($filters['branch_id']),   fn ($q) => $q->where('branch_id',   $filters['branch_id']))
            ->when(!empty($filters['terminal_id']), fn ($q) => $q->where('terminal_id', $filters['terminal_id']))
            ->when(!empty($filters['status']),      fn ($q) => $q->where('status',      $filters['status']))
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereDate('closing_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereDate('closing_date', '<=', $filters['date_to']))
            ->orderByDesc('closing_date')
            ->paginate(20)
            ->withQueryString();

        $totals = DailyClosing::query()
            ->when(!empty($filters['branch_id']),   fn ($q) => $q->where('branch_id',   $filters['branch_id']))
            ->when(!empty($filters['terminal_id']), fn ($q) => $q->where('terminal_id', $filters['terminal_id']))
            ->when(!empty($filters['status']),      fn ($q) => $q->where('status',      $filters['status']))
            ->when(!empty($filters['date_from']),   fn ($q) => $q->whereDate('closing_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),     fn ($q) => $q->whereDate('closing_date', '<=', $filters['date_to']))
            ->selectRaw('
                COUNT(*) as closing_count,
                COALESCE(SUM(total_sales), 0)    as total_sales,
                COALESCE(SUM(total_cash), 0)     as total_cash,
                COALESCE(SUM(total_refunds), 0)  as total_refunds,
                COALESCE(SUM(expected_cash), 0)  as expected_cash,
                COALESCE(SUM(counted_cash), 0)   as counted_cash,
                COALESCE(SUM(cash_variance), 0)  as total_variance
            ')->first();

        $branches  = Branch::where('status', 'active')->orderBy('name')->get();
        $terminals = Terminal::where('status', 'active')->orderBy('name')->get();

        return view('tenant.reports.daily-closings', compact('closings', 'filters', 'branches', 'terminals', 'totals'));
    }
}
