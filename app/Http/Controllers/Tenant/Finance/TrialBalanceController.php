<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Concerns\NormalizesBranchIds;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Finance\FinancialExportService;
use App\Support\CsvStreamer;
use Illuminate\Http\Request;

class TrialBalanceController extends Controller
{
    use NormalizesBranchIds;

    public function __construct(private FinancialExportService $exportService) {}

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

        if ($request->boolean('export_csv')) {
            return $this->csv($tb, $from, $to, $branchIds);
        }

        return view('tenant.finance.trial-balance.index', [
            'rows'              => $tb['rows'],
            'totals'            => $tb['totals'],
            'difference'        => $tb['difference'],
            'from'              => $from,
            'to'                => $to,
            'branches'          => Branch::orderBy('name')->get(['id', 'name']),
            'selectedBranchIds' => $branchIds ?? [],
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

    private function csv(array $tb, string $from, string $to, ?array $branchIds)
    {
        $branchLabel = $branchIds
            ? Branch::whereIn('id', $branchIds)->orderBy('name')->pluck('name')->implode(', ')
            : 'All Branches';

        $header = CsvStreamer::financeHeader('Trial Balance', [
            'From'   => $from,
            'To'     => $to,
            'Branch' => $branchLabel,
        ]);

        return CsvStreamer::download('trial-balance-' . $from . '-to-' . $to . '.csv', $header, function ($fp) use ($tb) {
            $n = fn (float $v) => number_format($v, 2, '.', '');
            fputcsv($fp, ['Code', 'Account', 'Type', 'Opening', 'Debit', 'Credit', 'Balance']);
            foreach ($tb['rows'] as $r) {
                fputcsv($fp, [
                    $r['code'], $r['name'], ucfirst($r['type']),
                    self::sided($r['opening_debit'], $r['opening_credit'], false),
                    $n($r['period_debit']), $n($r['period_credit']),
                    self::sided($r['closing_debit'], $r['closing_credit'], false),
                ]);
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
}
