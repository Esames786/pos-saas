<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Reports\SalesReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends Controller
{
    public function __construct(private readonly SalesReportService $service) {}

    public function summary(Request $request)
    {
        $filters = $this->filters($request);
        $data    = $this->service->summary($filters);

        if ($request->boolean('export_csv')) {
            return $this->csvSummary($data);
        }

        return view('tenant.reports.sales.summary', array_merge(
            compact('data', 'filters'),
            $this->sharedViewData()
        ));
    }

    public function items(Request $request)
    {
        $filters = $this->filters($request);
        $rows    = $this->service->items($filters);

        if ($request->boolean('export_csv')) {
            return $this->csvItems($rows);
        }

        return view('tenant.reports.sales.items', array_merge(
            compact('rows', 'filters'),
            $this->sharedViewData()
        ));
    }

    public function payments(Request $request)
    {
        $filters = $this->filters($request);
        $rows    = $this->service->payments($filters);

        return view('tenant.reports.sales.payments', array_merge(
            compact('rows', 'filters'),
            $this->sharedViewData()
        ));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function filters(Request $request): array
    {
        return [
            'date_from'   => $request->input('date_from', today()->format('Y-m-d')),
            'date_to'     => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_id'   => $request->input('branch_id'),
            'terminal_id' => $request->input('terminal_id'),
            'order_type'  => $request->input('order_type'),
            'cashier_id'  => $request->input('cashier_id'),
        ];
    }

    private function sharedViewData(): array
    {
        return [
            'branches'   => Branch::where('status', 'active')->orderBy('name')->get(),
            'terminals'  => Terminal::where('status', 'active')->orderBy('name')->get(),
            'orderTypes' => ['quick_sale', 'takeaway', 'dine_in', 'delivery'],
        ];
    }

    private function csvSummary(array $data): StreamedResponse
    {
        $totals = $data['totals'];
        $daily  = $data['daily'];

        return response()->streamDownload(function () use ($totals, $daily) {
            $fp = fopen('php://output', 'w');
            fputcsv($fp, ['Date', 'Orders', 'Gross Sales', 'Discount', 'Tax', 'Service Charge', 'Tips', 'Net Sales']);
            foreach ($daily as $row) {
                fputcsv($fp, [
                    $row->sale_day,
                    $row->order_count,
                    number_format($row->gross_sales, 2),
                    number_format($row->total_discount, 2),
                    number_format($row->total_tax, 2),
                    number_format($row->total_service_charge, 2),
                    number_format($row->total_tips, 2),
                    number_format($row->net_sales, 2),
                ]);
            }
            fputcsv($fp, []);
            fputcsv($fp, ['TOTAL', $totals->order_count,
                number_format($totals->gross_sales, 2),
                number_format($totals->total_discount, 2),
                number_format($totals->total_tax, 2),
                number_format($totals->total_service_charge, 2),
                number_format($totals->total_tips, 2),
                number_format($totals->net_sales, 2),
            ]);
            fclose($fp);
        }, 'sales-summary-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function csvItems($rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $fp = fopen('php://output', 'w');
            fputcsv($fp, ['Product', 'Variant', 'Category', 'Qty Sold', 'Gross Amount', 'Discount', 'Tax', 'Net Amount', 'Cost', 'Profit']);
            foreach ($rows as $row) {
                fputcsv($fp, [
                    $row->product_name,
                    $row->variant_name,
                    $row->category_name,
                    number_format($row->qty_sold, 3),
                    number_format($row->gross_amount, 2),
                    number_format($row->total_discount, 2),
                    number_format($row->total_tax, 2),
                    number_format($row->net_amount, 2),
                    number_format($row->total_cost, 2),
                    number_format($row->profit, 2),
                ]);
            }
            fclose($fp);
        }, 'sales-items-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
