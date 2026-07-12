<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Concerns\NormalizesBranchIds;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Supplier;
use App\Services\Finance\SupplierPayableService;
use App\Services\Reports\PurchaseReportService;
use Illuminate\Http\Request;

class PurchaseReportController extends Controller
{
    use NormalizesBranchIds;

    public function __construct(
        private readonly PurchaseReportService $service,
        private readonly SupplierPayableService $payableService,
    ) {}

    public function summary(Request $request)
    {
        $filters = [
            'date_from'   => $request->input('date_from', today()->subDays(29)->format('Y-m-d')),
            'date_to'     => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_ids'  => $this->normalizeBranchIds($request),
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
        $filters = [
            'supplier_id' => $request->input('supplier_id'),
            'branch_ids'  => $this->normalizeBranchIds($request),
            'as_of_date'  => $request->input('as_of_date', today()->format('Y-m-d')),
            'status'      => $request->input('status', 'all'),
        ];

        $aging  = $this->payableService->aging($filters);
        $rows   = $aging['rows'];
        $totals = $aging['totals'];

        if ($request->boolean('export_csv')) {
            return response()->streamDownload(function () use ($rows, $totals) {
                $fp = fopen('php://output', 'w');
                fputcsv($fp, ['Supplier', 'Current', '1-30', '31-60', '61-90', '90+', 'Total Due']);
                foreach ($rows as $r) {
                    fputcsv($fp, [
                        $r['supplier_name'],
                        number_format($r['current'], 2), number_format($r['d1_30'], 2),
                        number_format($r['d31_60'], 2), number_format($r['d61_90'], 2),
                        number_format($r['d90_plus'], 2), number_format($r['total'], 2),
                    ]);
                }
                fputcsv($fp, [
                    'TOTAL',
                    number_format($totals['current'], 2), number_format($totals['d1_30'], 2),
                    number_format($totals['d31_60'], 2), number_format($totals['d61_90'], 2),
                    number_format($totals['d90_plus'], 2), number_format($totals['total'], 2),
                ]);
                fclose($fp);
            }, 'supplier-payables-aging-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
        }

        return view('tenant.reports.purchases.payables', array_merge(
            compact('rows', 'totals', 'filters'),
            ['asOf' => $aging['as_of']],
            $this->sharedViewData()
        ));
    }

    // PURCHASE-RETURNS-1 — supplier return lines + summary.
    public function returns(Request $request)
    {
        $filters = [
            'date_from'   => $request->input('date_from', today()->subDays(29)->format('Y-m-d')),
            'date_to'     => $request->input('date_to',   today()->format('Y-m-d')),
            'branch_id'   => $request->input('branch_id'),
            'supplier_id' => $request->input('supplier_id'),
            'status'      => $request->input('status'),
            'reason'      => $request->input('reason'),
            'product'     => $request->input('product'),
        ];

        $report  = $this->service->returns($filters);
        $reasons = \App\Models\Tenant\PurchaseReturn::REASON_CODES;

        return view('tenant.reports.purchases.returns', array_merge(
            compact('report', 'filters', 'reasons'),
            $this->sharedViewData()
        ));
    }

    private function sharedViewData(): array
    {
        return [
            'branches'          => Branch::where('status', 'active')->orderBy('name')->get(),
            'suppliers'         => Supplier::where('status', 'active')->orderBy('name')->get(),
            'selectedBranchIds' => $this->normalizeBranchIds(request()) ?? [],
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
