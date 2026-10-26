<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Concerns\NormalizesBranchIds;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Finance\ProfitLossReport;
use App\Services\Finance\ProfitLossService;
use App\Support\CsvStreamer;
use App\Support\FinancePdf;
use Illuminate\Http\Request;

class ProfitLossController extends Controller
{
    use NormalizesBranchIds;

    public function __construct(
        private ProfitLossService $service,
        private ProfitLossReport $report,
    ) {}

    /** The statement's parts as the reader sees them. */
    public const PART_LABELS = [
        'revenue'       => 'Revenue',
        'discounts'     => 'Less: Sales Discounts',
        'cost_of_sales' => 'Cost of Sales',
        'expenses'      => 'Operating Expenses',
    ];

    public function index(Request $request)
    {
        $branchIds = $this->normalizeBranchIds($request);
        $filters = [
            'date_from'  => $request->input('date_from', now()->startOfMonth()->toDateString()),
            'date_to'    => $request->input('date_to', now()->toDateString()),
            'branch_ids' => $branchIds,
        ];

        $pl = $this->service->statement($filters);

        // PNL-DETAIL-1: parents over children and, under each cost / expense account, what it is made of. Default ON;
        // detail=0 hides the lines (the heads stay). The screen, the CSV and the PDF all take this one build.
        $detail = (string) $request->input('detail', '1') !== '0';
        $report = $this->report->build($pl, $branchIds, $detail);
        $filters['detail'] = $detail ? '1' : '0';

        if ($request->boolean('export_csv')) {
            return $this->csv($pl, $report);
        }
        if ($request->query('format') === 'pdf') {
            return $this->pdf($pl, $report, $branchIds);
        }

        return view('tenant.finance.profit-loss.index', [
            'pl'                => $pl,
            'report'            => $report,
            'filters'           => $filters,
            'branches'          => Branch::orderBy('name')->get(['id', 'name']),
            'selectedBranchIds' => $branchIds ?? [],
        ]);
    }

    /**
     * PNL-DETAIL-1 R3 — every row says what it is ("Row": Head / Account / Detail / Total) and how deep it sits ("Level").
     * A SUM of Amount over a section's Account rows is that section's total; Head and Detail rows are summaries of them.
     */
    private function csv(array $pl, array $report)
    {
        $header = CsvStreamer::financeHeader('Profit & Loss', ['Period' => $pl['period']['from'] . ' to ' . $pl['period']['to']]);

        return CsvStreamer::download('profit-loss-' . $pl['period']['from'] . '_' . $pl['period']['to'] . '.csv', $header, function ($fp) use ($pl, $report) {
            $n = fn (float $v) => number_format($v, 2, '.', '');
            $total = fn (string $label, float $v) => fputcsv($fp, ['', 'Total', '', '', $label, '', $n($v)]);
            fputcsv($fp, ['Section', 'Row', 'Level', 'Code', 'Account', 'Detail', 'Amount']);
            foreach ($report['parts'] as $part => $lines) {
                foreach ($lines as $l) {
                    fputcsv($fp, [self::PART_LABELS[$part], $l['kind'] === 'head' ? 'Head' : 'Account', $l['depth'], $l['code'], $l['name'], '', $n($l['amount'])]);
                    foreach ($l['detail'] as $d) {
                        fputcsv($fp, [self::PART_LABELS[$part], 'Detail', $l['depth'] + 1, $l['code'], $l['name'], $d['label'], $n($d['amount'])]);
                    }
                }
                match ($part) {
                    'revenue'       => $total('Gross Revenue', $pl['gross_revenue']),
                    'discounts'     => $total('Net Revenue', $pl['net_revenue']),
                    'cost_of_sales' => [$total('Total Cost of Sales', $pl['total_cogs']), $total('Gross Profit', $pl['gross_profit'])],
                    'expenses'      => [$total('Total Operating Expenses', $pl['total_expenses']), $total('Net Profit / Loss', $pl['net_profit'])],
                };
            }
        });
    }

    /** FINANCE-PDF-DOWNLOADS-1 — the P&L as the hand-made sample: the same tree and detail as the screen. */
    private function pdf(array $pl, array $report, ?array $branchIds)
    {
        $html = view('tenant.finance.profit-loss.print', [
            'pl'          => $pl,
            'report'      => $report,
            'labels'      => self::PART_LABELS,
            'branchLabel' => $branchIds ? Branch::whereIn('id', $branchIds)->orderBy('name')->pluck('name')->implode(', ') : null,
            'company'     => app()->bound('tenant') ? (app('tenant')->business_name ?? '') : '',
        ])->render();

        return FinancePdf::download($html, 'profit-loss-' . $pl['period']['from'] . '-to-' . $pl['period']['to'] . '.pdf');
    }
}
