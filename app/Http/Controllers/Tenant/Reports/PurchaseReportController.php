<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Supplier;
use App\Services\Reports\PurchaseReportService;
use Illuminate\Http\Request;

class PurchaseReportController extends Controller
{
    public function __construct(private readonly PurchaseReportService $service) {}

    public function summary(Request $request)
    {
        $filters = [
            'date_from'   => $request->input('date_from', today()->subDays(29)->format('Y-m-d')),
            'date_to'     => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_id'   => $request->input('branch_id'),
            'supplier_id' => $request->input('supplier_id'),
            'status'      => $request->input('status'),
        ];

        $bills   = $this->service->summary($filters);
        $totals  = $this->service->summaryTotals($filters);

        if ($request->boolean('export_csv')) {
            return $this->csvSummary($bills);
        }

        return view('tenant.reports.purchases.summary', array_merge(
            compact('bills', 'totals', 'filters'),
            $this->sharedViewData()
        ));
    }

    public function suppliers(Request $request)
    {
        $filters = [
            'date_from' => $request->input('date_from', today()->subDays(29)->format('Y-m-d')),
            'date_to'   => $request->input('date_to',   today()->format('Y-m-d')),
        ];

        $rows = $this->service->suppliers($filters);

        return view('tenant.reports.purchases.suppliers', array_merge(
            compact('rows', 'filters'),
            $this->sharedViewData()
        ));
    }

    public function payables(Request $request)
    {
        $rows = $this->service->payables();

        if ($request->boolean('export_csv')) {
            return response()->streamDownload(function () use ($rows) {
                $fp = fopen('php://output', 'w');
                fputcsv($fp, ['Supplier', 'Phone', 'Email', 'Outstanding Balance', 'Status']);
                foreach ($rows as $r) {
                    fputcsv($fp, [$r->name, $r->phone, $r->email, number_format($r->current_balance, 2), $r->status]);
                }
                fclose($fp);
            }, 'supplier-payables-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
        }

        return view('tenant.reports.purchases.payables', compact('rows'));
    }

    private function sharedViewData(): array
    {
        return [
            'branches'  => Branch::where('status', 'active')->orderBy('name')->get(),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(),
        ];
    }

    private function csvSummary($bills)
    {
        $data = $bills->getCollection();
        return response()->streamDownload(function () use ($data) {
            $fp = fopen('php://output', 'w');
            fputcsv($fp, ['Bill No', 'Date', 'Supplier', 'Branch', 'Status', 'Subtotal', 'Tax', 'Grand Total', 'Paid', 'Balance Due']);
            foreach ($data as $b) {
                fputcsv($fp, [
                    $b->bill_no, $b->bill_date, $b->supplier?->name, $b->branch?->name,
                    $b->status, $b->subtotal, $b->tax_total, $b->grand_total, $b->amount_paid, $b->balance_due,
                ]);
            }
            fclose($fp);
        }, 'purchase-summary-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
