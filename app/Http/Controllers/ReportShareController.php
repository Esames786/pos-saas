<?php

namespace App\Http\Controllers;

use App\Models\Master\Tenant;
use App\Services\Reports\Delivery\ReportDelivery;
use App\Services\Reports\SalesReportDocumentService;
use App\Services\Reports\Sharing\ReportShareLinkService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Http\Request;

/**
 * WHATSAPP-REPORT-CHANNEL-1 (qadam 4) — what opens when the owner taps the link.
 *
 * This sits on the CENTRAL domain, so it starts with no tenant: the token is the only thing that says
 * which shop's figures to load, and an expired or unknown one must say nothing at all.
 *
 * The owner asked for the page to open AND the PDF to download. One HTTP response can only do one of
 * those, so the page opens, starts the download itself, and also carries a button — because WhatsApp's
 * in-app browser blocks automatic downloads often enough that relying on it would leave some owners
 * with nothing and no idea anything was missing.
 */
class ReportShareController extends Controller
{
    public function __construct(
        private readonly ReportShareLinkService $links,
        private readonly SalesReportDocumentService $document,
        private readonly TenancyManager $tenancy,
    ) {}

    /** The page: figures first, download started, button always there. */
    public function show(Request $request, string $token)
    {
        [$link, $tenant] = $this->open($token);

        $data = $this->document->data(
            (array) json_decode($link->filters, true),
            (array) json_decode($link->sections, true),
            true,
        );

        $this->links->markOpened($token);

        return response()
            ->view('reports.shared', $data + [
                'businessName' => $tenant->business_name,
                'periodLabel' => $link->label,
                'pdfUrl' => url('/r/'.$token.'/pdf'),
            ])
            // A report is not something to leave in a shared cache.
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** The PDF itself — same token, same expiry, so the file is no more open than the page. */
    public function pdf(Request $request, string $token)
    {
        [$link, $tenant] = $this->open($token);

        $pdf = $this->document->pdf(
            (array) json_decode($link->filters, true),
            (array) json_decode($link->sections, true),
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="sales-report-'.$link->label.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Resolve the token and switch into its tenant, or 404.
     *
     * 404 — not 403 — on purpose: a wrong or expired token should not confirm that it ever existed.
     *
     * @return array{0: object, 1: Tenant}
     */
    private function open(string $token): array
    {
        $link = $this->links->resolve($token);
        abort_if($link === null, 404);

        $tenant = Tenant::find($link->tenant_id);
        abort_if($tenant === null, 404);

        $this->tenancy->activate($tenant);

        return [$link, $tenant];
    }
}
