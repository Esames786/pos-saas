<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Concerns\NormalizesBranchIds;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Finance\ProfitLossService;
use App\Support\CsvStreamer;
use Illuminate\Http\Request;

class ProfitLossController extends Controller
{
    use NormalizesBranchIds;

    public function __construct(private ProfitLossService $service) {}

    public function index(Request $request)
    {
        $branchIds = $this->normalizeBranchIds($request);
        $filters = [
            'date_from'  => $request->input('date_from', now()->startOfMonth()->toDateString()),
            'date_to'    => $request->input('date_to', now()->toDateString()),
            'branch_ids' => $branchIds,
        ];

        $pl = $this->service->statement($filters);

        if ($request->boolean('export_csv')) {
            return $this->csv($pl);
        }

        return view('tenant.finance.profit-loss.index', [
            'pl'                => $pl,
            'filters'           => $filters,
            'branches'          => Branch::orderBy('name')->get(['id', 'name']),
            'selectedBranchIds' => $branchIds ?? [],
        ]);
    }


    private function csv(array $pl)
    {
        $header = CsvStreamer::financeHeader('Profit & Loss', ['Period' => $pl['period']['from'] . ' to ' . $pl['period']['to']]);

        return CsvStreamer::download('profit-loss-' . $pl['period']['from'] . '_' . $pl['period']['to'] . '.csv', $header, function ($fp) use ($pl) {
            fputcsv($fp, ['Section', 'Code', 'Account', 'Amount']);

            foreach ($pl['revenue_rows'] as $r) {
                fputcsv($fp, ['Revenue', $r['code'], $r['name'], number_format($r['amount'], 2)]);
            }
            fputcsv($fp, ['', '', 'Gross Revenue', number_format($pl['gross_revenue'], 2)]);
            foreach ($pl['discount_rows'] as $r) {
                fputcsv($fp, ['Less: Discounts', $r['code'], $r['name'], number_format($r['amount'], 2)]);
            }
            fputcsv($fp, ['', '', 'Net Revenue', number_format($pl['net_revenue'], 2)]);
            foreach ($pl['cogs_rows'] as $r) {
                fputcsv($fp, ['COGS', $r['code'], $r['name'], number_format($r['amount'], 2)]);
            }
            fputcsv($fp, ['', '', 'Total COGS', number_format($pl['total_cogs'], 2)]);
            fputcsv($fp, ['', '', 'Gross Profit', number_format($pl['gross_profit'], 2)]);
            foreach ($pl['expense_rows'] as $r) {
                fputcsv($fp, ['Operating Expense', $r['code'], $r['name'], number_format($r['amount'], 2)]);
            }
            fputcsv($fp, ['', '', 'Total Operating Expenses', number_format($pl['total_expenses'], 2)]);
            fputcsv($fp, ['', '', 'Net Profit / Loss', number_format($pl['net_profit'], 2)]);
        });
    }
}
