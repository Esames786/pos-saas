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
        $asOf     = $request->input('as_of_date', today()->format('Y-m-d'));
        $branchId = $request->input('branch_id');

        $tb = $this->exportService->trialBalance($asOf, $branchId);

        if ($request->boolean('export_csv')) {
            return $this->csv($tb, $asOf, $branchId);
        }

        return view('tenant.finance.trial-balance.index', [
            'rows'        => $tb['rows'],
            'totalDebit'  => $tb['total_debit'],
            'totalCredit' => $tb['total_credit'],
            'difference'  => $tb['difference'],
            'asOf'        => $asOf,
            'branches'    => Branch::orderBy('name')->get(['id', 'name']),
            'filters'     => $request->only(['as_of_date', 'branch_id']),
        ]);
    }

    private function csv(array $tb, string $asOf, ?int $branchId)
    {
        $branchName = $branchId ? (Branch::find($branchId)?->name ?? '') : 'All branches';

        $header = CsvStreamer::financeHeader('Trial Balance', [
            'As of'  => $asOf,
            'Branch' => $branchName,
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
