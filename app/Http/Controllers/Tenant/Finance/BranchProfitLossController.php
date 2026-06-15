<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Finance\BranchProfitLossService;
use Illuminate\Http\Request;

class BranchProfitLossController extends Controller
{
    public function __construct(private BranchProfitLossService $service) {}

    public function index(Request $request)
    {
        $filters = [
            'date_from' => $request->input('date_from', now()->startOfMonth()->toDateString()),
            'date_to'   => $request->input('date_to', now()->toDateString()),
            'branch_id' => $request->input('branch_id'),
        ];

        $report = $this->service->statement($filters);

        if ($request->boolean('export_csv')) {
            return $this->csv($report);
        }

        return view('tenant.finance.branch-profit-loss.index', [
            'report'   => $report,
            'filters'  => $filters,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function csv(array $report)
    {
        return response()->streamDownload(function () use ($report) {
            $fp = fopen('php://output', 'w');
            fputcsv($fp, ['Branch-wise P&L', $report['period']['from'] . ' to ' . $report['period']['to']]);
            fputcsv($fp, []);
            fputcsv($fp, ['Branch', 'Gross Revenue', 'Discounts', 'Net Revenue', 'COGS', 'Gross Profit', 'Operating Expenses', 'Net Profit', 'Gross Margin %', 'Net Margin %']);
            foreach ($report['rows'] as $r) {
                fputcsv($fp, [
                    $r['branch_name'],
                    number_format($r['gross_revenue'], 2), number_format($r['discounts'], 2),
                    number_format($r['net_revenue'], 2), number_format($r['cogs'], 2),
                    number_format($r['gross_profit'], 2), number_format($r['operating_expenses'], 2),
                    number_format($r['net_profit'], 2), $r['gross_margin_percent'], $r['net_margin_percent'],
                ]);
            }
            $t = $report['totals'];
            fputcsv($fp, [
                'TOTAL',
                number_format($t['gross_revenue'], 2), number_format($t['discounts'], 2),
                number_format($t['net_revenue'], 2), number_format($t['cogs'], 2),
                number_format($t['gross_profit'], 2), number_format($t['operating_expenses'], 2),
                number_format($t['net_profit'], 2), $t['gross_margin_percent'], $t['net_margin_percent'],
            ]);
            fclose($fp);
        }, 'branch-profit-loss-' . $report['period']['from'] . '_' . $report['period']['to'] . '.csv', ['Content-Type' => 'text/csv']);
    }
}
