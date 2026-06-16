<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Finance\BalanceSheetService;
use App\Services\Finance\BranchProfitLossService;
use App\Services\Finance\FinancialExportService;
use App\Services\Finance\ProfitLossService;
use App\Support\CsvStreamer;
use Illuminate\Http\Request;

/**
 * FIN-12 Accounting Export hub. One screen → a dated "Financial Statement Pack"
 * combining the selected statements into a single Excel-friendly CSV (no ext-zip
 * needed for a multi-section CSV; true .xlsx bundling is a future upgrade once
 * ext-zip is enabled).
 */
class FinancialExportController extends Controller
{
    private const SECTIONS = [
        'trial_balance'      => 'Trial Balance',
        'profit_loss'        => 'Profit & Loss',
        'branch_profit_loss' => 'Branch-wise P&L',
        'balance_sheet'      => 'Balance Sheet',
        'journal_lines'      => 'Journal Lines (GL detail)',
    ];

    public function __construct(
        private FinancialExportService $exportService,
        private ProfitLossService $plService,
        private BalanceSheetService $bsService,
        private BranchProfitLossService $branchPlService,
    ) {}

    public function index(Request $request)
    {
        $filters = [
            'date_from' => $request->input('date_from', now()->startOfMonth()->toDateString()),
            'date_to'   => $request->input('date_to', now()->toDateString()),
            'branch_id' => $request->input('branch_id'),
        ];

        if ($request->boolean('export_csv')) {
            $selected = (array) $request->input('sections', array_keys(self::SECTIONS));
            return $this->pack($filters, array_intersect(array_keys(self::SECTIONS), $selected));
        }

        return view('tenant.finance.export.index', [
            'filters'  => $filters,
            'sections' => self::SECTIONS,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function pack(array $filters, array $sections)
    {
        $from     = $filters['date_from'];
        $to       = $filters['date_to'];
        $branchId = $filters['branch_id'] ?: null;
        $branchName = $branchId ? (Branch::find($branchId)?->name ?? '') : 'All branches';

        $header = CsvStreamer::financeHeader('Financial Statement Pack', [
            'Period' => $from . ' to ' . $to,
            'Branch' => $branchName,
        ]);

        return CsvStreamer::download('financial-statement-pack-' . $from . '_' . $to . '.csv', $header, function ($fp) use ($sections, $from, $to, $branchId) {
            foreach ($sections as $key) {
                fputcsv($fp, ['===== ' . self::SECTIONS[$key] . ' =====']);
                match ($key) {
                    'trial_balance'      => $this->sectionTrialBalance($fp, $to, $branchId),
                    'profit_loss'        => $this->sectionProfitLoss($fp, $from, $to, $branchId),
                    'branch_profit_loss' => $this->sectionBranchProfitLoss($fp, $from, $to, $branchId),
                    'balance_sheet'      => $this->sectionBalanceSheet($fp, $to, $branchId),
                    'journal_lines'      => $this->sectionJournalLines($fp, $from, $to, $branchId),
                    default              => null,
                };
                fputcsv($fp, []);
            }
        });
    }

    private function sectionTrialBalance($fp, string $asOf, ?int $branchId): void
    {
        $tb = $this->exportService->trialBalance($asOf, $branchId);
        fputcsv($fp, ['Code', 'Account', 'Type', 'Debit', 'Credit']);
        foreach ($tb['rows'] as $r) {
            fputcsv($fp, [$r['code'], $r['name'], ucfirst($r['type']), $this->n($r['debit_balance']), $this->n($r['credit_balance'])]);
        }
        fputcsv($fp, ['', '', 'TOTAL', $this->n($tb['total_debit']), $this->n($tb['total_credit'])]);
        fputcsv($fp, ['', '', 'Difference', $this->n($tb['difference']), '']);
    }

    private function sectionProfitLoss($fp, string $from, string $to, ?int $branchId): void
    {
        $pl = $this->plService->statement(['date_from' => $from, 'date_to' => $to, 'branch_id' => $branchId]);
        fputcsv($fp, ['Section', 'Code', 'Account', 'Amount']);
        foreach ($pl['revenue_rows'] as $r) {
            fputcsv($fp, ['Revenue', $r['code'], $r['name'], $this->n($r['amount'])]);
        }
        fputcsv($fp, ['', '', 'Gross Revenue', $this->n($pl['gross_revenue'])]);
        foreach ($pl['discount_rows'] as $r) {
            fputcsv($fp, ['Less: Discounts', $r['code'], $r['name'], $this->n($r['amount'])]);
        }
        fputcsv($fp, ['', '', 'Net Revenue', $this->n($pl['net_revenue'])]);
        foreach ($pl['cogs_rows'] as $r) {
            fputcsv($fp, ['COGS', $r['code'], $r['name'], $this->n($r['amount'])]);
        }
        fputcsv($fp, ['', '', 'Gross Profit', $this->n($pl['gross_profit'])]);
        foreach ($pl['expense_rows'] as $r) {
            fputcsv($fp, ['Operating Expense', $r['code'], $r['name'], $this->n($r['amount'])]);
        }
        fputcsv($fp, ['', '', 'Total Operating Expenses', $this->n($pl['total_expenses'])]);
        fputcsv($fp, ['', '', 'Net Profit / Loss', $this->n($pl['net_profit'])]);
    }

    private function sectionBranchProfitLoss($fp, string $from, string $to, ?int $branchId): void
    {
        $report = $this->branchPlService->statement(['date_from' => $from, 'date_to' => $to, 'branch_id' => $branchId]);
        fputcsv($fp, ['Branch', 'Net Revenue', 'COGS', 'Gross Profit', 'Operating Expenses', 'Net Profit', 'Net Margin %']);
        foreach ($report['rows'] as $r) {
            fputcsv($fp, [$r['branch_name'], $this->n($r['net_revenue']), $this->n($r['cogs']), $this->n($r['gross_profit']), $this->n($r['operating_expenses']), $this->n($r['net_profit']), $r['net_margin_percent']]);
        }
        $t = $report['totals'];
        fputcsv($fp, ['TOTAL', $this->n($t['net_revenue']), $this->n($t['cogs']), $this->n($t['gross_profit']), $this->n($t['operating_expenses']), $this->n($t['net_profit']), $t['net_margin_percent']]);
    }

    private function sectionBalanceSheet($fp, string $asOf, ?int $branchId): void
    {
        $bs = $this->bsService->statement(['as_of_date' => $asOf, 'branch_id' => $branchId]);
        fputcsv($fp, ['Section', 'Code', 'Account', 'Amount']);
        foreach ($bs['asset_rows'] as $r) {
            fputcsv($fp, ['Asset', $r['code'], $r['name'], $this->n($r['amount'])]);
        }
        fputcsv($fp, ['', '', 'Total Assets', $this->n($bs['total_assets'])]);
        foreach ($bs['liability_rows'] as $r) {
            fputcsv($fp, ['Liability', $r['code'], $r['name'], $this->n($r['amount'])]);
        }
        fputcsv($fp, ['', '', 'Total Liabilities', $this->n($bs['total_liabilities'])]);
        foreach ($bs['equity_rows'] as $r) {
            fputcsv($fp, ['Equity', $r['code'], $r['name'], $this->n($r['amount'])]);
        }
        fputcsv($fp, ['Equity', '', 'Current Earnings', $this->n($bs['current_earnings'])]);
        fputcsv($fp, ['', '', 'Total Liabilities + Equity', $this->n($bs['total_liabilities_equity'])]);
        fputcsv($fp, ['', '', 'Difference', $this->n($bs['difference'])]);
    }

    private function sectionJournalLines($fp, string $from, string $to, ?int $branchId): void
    {
        $lines = $this->exportService->generalLedgerLines($from, $to, $branchId, null);
        fputcsv($fp, ['Entry No', 'Date', 'Source', 'Account Code', 'Account', 'Branch', 'Debit', 'Credit']);
        foreach ($lines as $line) {
            fputcsv($fp, [
                $line->journalEntry->entry_no ?? '',
                optional($line->journalEntry->entry_date)->format('Y-m-d'),
                $line->journalEntry->source_type ?? '',
                $line->account->code ?? '',
                $line->account->name ?? '',
                $line->branch->name ?? '',
                $this->n((float) $line->debit),
                $this->n((float) $line->credit),
            ]);
        }
    }

    private function n($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
