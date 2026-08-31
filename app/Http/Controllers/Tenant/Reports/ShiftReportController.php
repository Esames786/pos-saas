<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\DailyClosing;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // SHIFT-CANCELLATIONS-1 — what was thrown away on each shift.
        //
        // The shift row already carries every way money ARRIVED (cash / card / bank / cheque) and
        // every way it went back (refunds by method). What it has never carried is what was
        // cancelled: a whole bill voided, or single items pulled off a check after the kitchen had
        // them. Those never became money, so no total on the row moves — which is exactly why they
        // are invisible, and why an owner cannot see that one counter voided twelve units in a
        // shift while another voided none.
        //
        // Counted here from the orders rather than stored on the shift, so EVERY past shift gets
        // the figure too — a new column would only ever fill in from the day it shipped. Two small
        // grouped queries over the shifts on this page, not one per row.
        $shiftIds = $shifts->pluck('id')->all();

        $cancelledOrders = $shiftIds ? \App\Models\Tenant\SalesOrder::query()
            ->whereIn('shift_id', $shiftIds)
            ->where('status', 'cancelled')
            ->selectRaw('shift_id, COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as amount')
            ->groupBy('shift_id')->get()->keyBy('shift_id') : collect();

        // Items pulled off a check that stayed open — the bill still exists, so the cancelled-order
        // query above can never see these.
        $voidedLines = $shiftIds ? DB::connection('tenant')
            ->table('sales_order_line_cancellations as c')
            ->join('sales_orders as o', 'o.id', '=', 'c.sales_order_id')
            ->whereIn('o.shift_id', $shiftIds)
            ->selectRaw('o.shift_id, COUNT(*) as lines_count, COALESCE(SUM(c.quantity), 0) as units')
            ->groupBy('o.shift_id')->get()->keyBy('shift_id') : collect();

        return view('tenant.reports.shifts.index', compact(
            'shifts', 'filters', 'branches', 'totals', 'cancelledOrders', 'voidedLines'
        ));
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
