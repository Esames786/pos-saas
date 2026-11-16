<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;

/**
 * FINANCE-PDF-DOWNLOADS-1 — the finance reports' PDF, one way for all of them (the Trial Balance's since part 2, now the
 * P&L's too): Dompdf, A4 portrait, DejaVu Sans, remote files and PHP off, "Page X of Y" on every page. Sent as an
 * attachment so a phone downloads it instead of opening a blank tab.
 */
final class FinancePdf
{
    public static function download(string $html, string $filename): Response
    {
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
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** "1 October 2026" */
    public static function longDate(string $date): string
    {
        return \Illuminate\Support\Carbon::parse($date)->format('j F Y');
    }

    /** "Prepared from Bingoo POS · 26 Oct 2026, 11:40 PM · <user>" */
    public static function preparedLine(): string
    {
        $user = auth('tenant')->user()?->name;

        return 'Prepared from Bingoo POS · ' . app(TenantClock::class)->now()->format('j M Y, g:i A') . ($user ? ' · ' . $user : '');
    }
}
