<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Finance\FinancialExportService;
use App\Support\CsvStreamer;
use Illuminate\Http\Request;

class TrialBalanceController extends Controller
{
    public function __construct(private FinancialExportService $exportService) {}

    public function index(Request $request)
    {
        $asOf      = $request->input('as_of_date', today()->format('Y-m-d'));
        $branchIds = $this->normalizeBranchIds($request);

        $tb = $this->exportService->trialBalance($asOf, $branchIds);

        if ($request->boolean('export_csv')) {
            return $this->csv($tb, $asOf, $branchIds);
        }

        return view('tenant.finance.trial-balance.index', [
            'rows'              => $tb['rows'],
            'totalDebit'        => $tb['total_debit'],
            'totalCredit'       => $tb['total_credit'],
            'difference'        => $tb['difference'],
            'asOf'              => $asOf,
            'branches'          => Branch::orderBy('name')->get(['id', 'name']),
            'selectedBranchIds' => $branchIds ?? [],
            'filters'           => $request->only(['as_of_date', 'branch_ids']),
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

    private function csv(array $tb, string $asOf, ?array $branchIds)
    {
        $branchLabel = $branchIds
            ? Branch::whereIn('id', $branchIds)->orderBy('name')->pluck('name')->implode(', ')
            : 'All Branches';

        $header = CsvStreamer::financeHeader('Trial Balance', [
            'As of'  => $asOf,
            'Branch' => $branchLabel,
        ]);

        return CsvStreamer::download('trial-balance-' . $asOf . '.csv', $header, function ($fp) use ($tb) {
            fputcsv($fp, ['Code', 'Account', 'Type', 'Debit', 'Credit']);
            foreach ($tb['rows'] as $r) {
                fputcsv($fp, [$r['code'], $r['name'], ucfirst($r['type']), number_format($r['debit_balance'], 2, '.', ''), number_format($r['credit_balance'], 2, '.', '')]);
            }
            fputcsv($fp, ['', '', 'TOTAL', number_format($tb['total_debit'], 2, '.', ''), number_format($tb['total_credit'], 2, '.', '')]);
            fputcsv($fp, ['', '', 'Difference', number_format($tb['difference'], 2, '.', ''), '']);
        });
    }
}
