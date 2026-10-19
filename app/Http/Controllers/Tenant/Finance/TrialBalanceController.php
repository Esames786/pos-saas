<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Concerns\NormalizesBranchIds;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Finance\FinancialExportService;
use App\Services\Finance\TrialBalancePartyService;
use App\Support\CsvStreamer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;

class TrialBalanceController extends Controller
{
    use NormalizesBranchIds;

    public function __construct(
        private FinancialExportService $exportService,
        private TrialBalancePartyService $partyService,
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

        if ($request->boolean('export_csv')) {
            return $this->csv($tb, $parties, $from, $to, $branchIds);
        }
        if ($request->query('format') === 'pdf') {
            return $this->pdf($tb, $parties, $from, $to, $branchIds);
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
     * Part 2 — Print. A4 portrait, column headings on every page, "Page X of Y", the totals block
     * kept on one page, signature lines. Dompdf, as the Sales Report PDF already uses.
     */
    private function pdf(array $tb, array $parties, string $from, string $to, ?array $branchIds)
    {
        $html = view('tenant.finance.trial-balance.print', [
            'rows'        => $tb['rows'],
            'totals'      => $tb['totals'],
            'difference'  => $tb['difference'],
            'parties'     => $parties,
            'from'        => $from,
            'to'          => $to,
            'branchLabel' => $this->branchLabel($branchIds),
            'company'     => app()->bound('tenant') ? (app('tenant')->business_name ?? '') : '',
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.35, 0.35, 0.35]);

        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="trial-balance-' . $from . '-to-' . $to . '.pdf"',
        ]);
    }
}
