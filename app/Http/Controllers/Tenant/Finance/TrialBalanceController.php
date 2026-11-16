<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Concerns\NormalizesBranchIds;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Finance\FinancialExportService;
use App\Services\Finance\TrialBalanceLayout;
use App\Services\Finance\TrialBalancePartyService;
use App\Support\CsvStreamer;
use App\Support\FinancePdf;
use Illuminate\Http\Request;

class TrialBalanceController extends Controller
{
    use NormalizesBranchIds;

    public function __construct(
        private FinancialExportService $exportService,
        private TrialBalancePartyService $partyService,
        private TrialBalanceLayout $layout,
    ) {}

    /**
     * TRIAL-BALANCE-PERIOD-1 — From/To with Opening · Debit · Credit · Balance per account.
     *
     * Defaults: the current month to today. An old `?as_of_date=` link still opens: it becomes To,
     * with From at the first posting, so its Balance column is exactly what that link used to show.
     */
    public function index(Request $request)
    {
        $request->validate([
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date', 'after_or_equal:date_from'],
            'as_of_date' => ['nullable', 'date'],
        ]);

        $legacyAsOf = $request->input('as_of_date');
        $to   = $request->input('date_to') ?: ($legacyAsOf ?: today()->format('Y-m-d'));
        $from = $request->input('date_from')
            ?: ($legacyAsOf
                ? min($this->exportService->firstPostingDate() ?? $to, $to)
                : today()->startOfMonth()->format('Y-m-d'));
        $branchIds = $this->normalizeBranchIds($request);

        $tb = $this->exportService->trialBalancePeriod($from, $to, $branchIds);

        // Part 2: the parties under each control account. Default ON; the screen, the CSV and the PDF
        // all take them from this one call, so the three can never disagree.
        $showParties = $request->input('parties', '1') !== '0';
        $parties = $showParties
            ? $this->partyService->partiesFor($from, $to, $branchIds, array_column($tb['rows'], 'account_id'))
            : [];

        // TB-SECTION-HEADS-1: grouped under the heads of the books, parents over their children, a
        // subtotal per head. Default ON; group=0 is the flat list (and the flat CSV) as before.
        $grouped = (string) $request->input('group', '1') !== '0';
        $sections = $grouped ? $this->layout->sections($tb['rows']) : [];

        if ($request->boolean('export_csv')) {
            return $grouped
                ? $this->groupedCsv($tb, $sections, $parties, $from, $to, $branchIds)
                : $this->csv($tb, $parties, $from, $to, $branchIds);
        }
        if ($request->query('format') === 'pdf') {
            return $this->pdf($tb, $parties, $from, $to, $branchIds, $grouped, $sections);
        }

        return view('tenant.finance.trial-balance.index', [
            'rows'              => $tb['rows'],
            'totals'            => $tb['totals'],
            'difference'        => $tb['difference'],
            'from'              => $from,
            'to'                => $to,
            'branches'          => Branch::orderBy('name')->get(['id', 'name']),
            'selectedBranchIds' => $branchIds ?? [],
            'parties'           => $parties,
            'showParties'       => $showParties,
            'grouped'           => $grouped,
            'sections'          => $sections,
        ]);
    }

    /** "1,234.00 Dr", "56.00 Cr", or "0.00" — one balance figure with its side. */
    public static function sided(float $debit, float $credit, bool $thousands = true): string
    {
        $fmt = fn (float $v) => $thousands ? number_format($v, 2) : number_format($v, 2, '.', '');
        if (round($debit, 2) > 0) {
            return $fmt($debit) . ' Dr';
        }
        if (round($credit, 2) > 0) {
            return $fmt($credit) . ' Cr';
        }

        return $fmt(0.0);
    }

    private function branchLabel(?array $branchIds): string
    {
        return $branchIds
            ? Branch::whereIn('id', $branchIds)->orderBy('name')->pluck('name')->implode(', ')
            : 'All Branches';
    }

    private function csv(array $tb, array $parties, string $from, string $to, ?array $branchIds)
    {
        $branchLabel = $this->branchLabel($branchIds);

        $header = CsvStreamer::financeHeader('Trial Balance', [
            'From'   => $from,
            'To'     => $to,
            'Branch' => $branchLabel,
        ]);

        return CsvStreamer::download('trial-balance-' . $from . '-to-' . $to . '.csv', $header, function ($fp) use ($tb, $parties) {
            $n = fn (float $v) => number_format($v, 2, '.', '');
            fputcsv($fp, ['Code', 'Account', 'Type', 'Opening', 'Debit', 'Credit', 'Balance']);
            foreach ($tb['rows'] as $r) {
                fputcsv($fp, [
                    $r['code'], $r['name'], ucfirst($r['type']),
                    self::sided($r['opening_debit'], $r['opening_credit'], false),
                    $n($r['period_debit']), $n($r['period_credit']),
                    self::sided($r['closing_debit'], $r['closing_credit'], false),
                ]);
                foreach ($parties[$r['account_id']] ?? [] as $p) {
                    fputcsv($fp, [
                        '', '    ' . $p['label'], 'Party',
                        self::sided($p['opening_debit'], $p['opening_credit'], false),
                        $n($p['period_debit']), $n($p['period_credit']),
                        self::sided($p['closing_debit'], $p['closing_credit'], false),
                    ]);
                }
            }
            $t = $tb['totals'];
            fputcsv($fp, [
                '', '', 'TOTAL',
                $n($t['opening_debit']) . ' Dr / ' . $n($t['opening_credit']) . ' Cr',
                $n($t['period_debit']), $n($t['period_credit']),
                $n($t['closing_debit']) . ' Dr / ' . $n($t['closing_credit']) . ' Cr',
            ]);
            fputcsv($fp, ['', '', 'Difference', '', '', '', $n($tb['difference'])]);
        });
    }

    /**
     * TB-SECTION-HEADS-1 — the grouped CSV. Every row says what it is ("Row"): a SUM of Debit or Credit
     * over the "Account" rows equals the grand total; Head, Party and Subtotal rows are summaries.
     */
    private function groupedCsv(array $tb, array $sections, array $parties, string $from, string $to, ?array $branchIds)
    {
        $header = CsvStreamer::financeHeader('Trial Balance', [
            'From'   => $from,
            'To'     => $to,
            'Branch' => $this->branchLabel($branchIds),
        ]);

        return CsvStreamer::download('trial-balance-' . $from . '-to-' . $to . '.csv', $header, function ($fp) use ($tb, $sections, $parties) {
            $n = fn (float $v) => number_format($v, 2, '.', '');
            $money = fn (array $r) => [
                self::sided($r['opening_debit'], $r['opening_credit'], false),
                $n($r['period_debit']), $n($r['period_credit']),
                self::sided($r['closing_debit'], $r['closing_credit'], false),
            ];
            fputcsv($fp, ['Section', 'Row', 'Level', 'Code', 'Account', 'Opening', 'Debit', 'Credit', 'Balance']);
            foreach ($sections as $s) {
                foreach ($s['lines'] as $l) {
                    fputcsv($fp, [$s['label'], $l['kind'] === 'head' ? 'Head' : 'Account', $l['depth'], $l['code'], $l['name'], ...$money($l)]);
                    if ($l['kind'] !== 'head') {
                        foreach ($parties[$l['account_id']] ?? [] as $p) {
                            fputcsv($fp, [$s['label'], 'Party', $l['depth'] + 1, '', $p['label'], ...$money($p)]);
                        }
                    }
                }
                fputcsv($fp, [$s['label'], 'Subtotal', 0, '', 'Total ' . $s['label'], ...$money($s['subtotal'])]);
            }
            $t = $tb['totals'];
            fputcsv($fp, [
                '', 'Total', '', '', 'TOTAL',
                $n($t['opening_debit']) . ' Dr / ' . $n($t['opening_credit']) . ' Cr',
                $n($t['period_debit']), $n($t['period_credit']),
                $n($t['closing_debit']) . ' Dr / ' . $n($t['closing_credit']) . ' Cr',
            ]);
            fputcsv($fp, ['', 'Difference', '', '', '', '', '', '', $n($tb['difference'])]);
        });
    }
    /**
     * Part 2 — Print; FINANCE-PDF-DOWNLOADS-1 — as the hand-made sample: Debit / Credit pairs for Opening, Transactions
     * and Closing, a total per head, the Grand Total from the service's totals, the difference in the footer.
     * Downloaded (attachment), never opened in a blank tab.
     */
    private function pdf(array $tb, array $parties, string $from, string $to, ?array $branchIds, bool $grouped = false, array $sections = [])
    {
        $html = view('tenant.finance.trial-balance.print', [
            'rows'          => $tb['rows'],
            'totals'        => $tb['totals'],
            'difference'    => $tb['difference'],
            'parties'       => $parties,
            'showParties'   => (bool) $parties,
            'grouped'       => $grouped,
            'sections'      => $sections,
            'sectionTotals' => self::sectionTotals($sections),
            'from'          => $from,
            'to'            => $to,
            'branchLabel'   => $this->branchLabel($branchIds),
            'company'       => app()->bound('tenant') ? (app('tenant')->business_name ?? '') : '',
        ])->render();

        return FinancePdf::download($html, 'trial-balance-' . $from . '-to-' . $to . '.pdf');
    }

    /**
     * A head's total in the PDF's Debit / Credit pairs: each column summed over its ACCOUNT rows (the heads only sum
     * them again), as an accountant adds a column — the opening and closing debits and credits are not netted.
     *
     * @return array<string, array<string,float>> section key => column => amount
     */
    public static function sectionTotals(array $sections): array
    {
        $cols = ['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit'];
        $out = [];
        foreach ($sections as $s) {
            $sum = array_fill_keys($cols, 0.0);
            foreach ($s['lines'] as $l) {
                if ($l['kind'] === 'head') {
                    continue;
                }
                foreach ($cols as $c) {
                    $sum[$c] += (float) $l[$c];
                }
            }
            $out[$s['key']] = array_map(fn ($v) => round($v, 2), $sum);
        }

        return $out;
    }
}
