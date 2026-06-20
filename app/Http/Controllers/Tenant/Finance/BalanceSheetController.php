<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Finance\BalanceSheetService;
use App\Support\CsvStreamer;
use Illuminate\Http\Request;

class BalanceSheetController extends Controller
{
    public function __construct(private BalanceSheetService $service) {}

    public function index(Request $request)
    {
        $branchIds = $this->normalizeBranchIds($request);
        $filters = [
            'as_of_date' => $request->input('as_of_date', now()->toDateString()),
            'branch_ids' => $branchIds,
        ];

        $bs = $this->service->statement($filters);

        if ($request->boolean('export_csv')) {
            return $this->csv($bs);
        }

        return view('tenant.finance.balance-sheet.index', [
            'bs'                => $bs,
            'filters'           => $filters,
            'branches'          => Branch::orderBy('name')->get(['id', 'name']),
            'selectedBranchIds' => $branchIds ?? [],
        ]);
    }

    private function normalizeBranchIds(Request $request): ?array
    {
        if ($request->filled('branch_ids')) {
            $ids = array_values(array_filter(array_map('intval', (array) $request->input('branch_ids'))));
            return $ids ?: null;
        }
        if ($request->filled('branch_id')) {
            return [(int) $request->input('branch_id')];
        }
        return null;
    }

    private function csv(array $bs)
    {
        $header = CsvStreamer::financeHeader('Balance Sheet', ['As of' => $bs['as_of_date']]);

        return CsvStreamer::download('balance-sheet-' . $bs['as_of_date'] . '.csv', $header, function ($fp) use ($bs) {
            fputcsv($fp, ['Section', 'Code', 'Account', 'Amount']);

            foreach ($bs['asset_rows'] as $r) {
                fputcsv($fp, ['Asset', $r['code'], $r['name'], number_format($r['amount'], 2)]);
            }
            fputcsv($fp, ['', '', 'Total Assets', number_format($bs['total_assets'], 2)]);
            foreach ($bs['liability_rows'] as $r) {
                fputcsv($fp, ['Liability', $r['code'], $r['name'], number_format($r['amount'], 2)]);
            }
            fputcsv($fp, ['', '', 'Total Liabilities', number_format($bs['total_liabilities'], 2)]);
            foreach ($bs['equity_rows'] as $r) {
                fputcsv($fp, ['Equity', $r['code'], $r['name'], number_format($r['amount'], 2)]);
            }
            fputcsv($fp, ['Equity', '', 'Current Earnings', number_format($bs['current_earnings'], 2)]);
            fputcsv($fp, ['', '', 'Total Equity + Current Earnings', number_format($bs['total_equity'] + $bs['current_earnings'], 2)]);
            fputcsv($fp, ['', '', 'Total Liabilities + Equity', number_format($bs['total_liabilities_equity'], 2)]);
            fputcsv($fp, ['', '', 'Difference', number_format($bs['difference'], 2)]);
        });
    }
}
