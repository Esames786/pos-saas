<?php

namespace App\Http\Controllers;

use App\Models\Master\Tenant;
use App\Services\Reports\SalesReportDocumentService;
use App\Services\Reports\Sharing\ReportShareLinkService;
use Illuminate\Http\Request;

/**
 * WHATSAPP-REPORT-CHANNEL-1 — the link the owner taps in WhatsApp.
 *
 * Two hops, on purpose:
 *
 *  1. CENTRAL (bingoopos.com/r/{token}) — only looks the token up and REDIRECTS. The approved
 *     template's button carries one fixed base URL for every tenant, so the first hop cannot be a
 *     subdomain; a per-tenant base would have meant a separate approved template per customer.
 *  2. TENANT (kashiffood.bingoopos.com/r/{token}) — renders it. By then the normal tenant middleware
 *     has run, so this is an ordinary tenant page with an ordinary tenant connection. Rendering on
 *     the central domain instead would mean activating tenancy by hand in a place that has none.
 */
class ReportShareController extends Controller
{
    public function __construct(
        private readonly ReportShareLinkService $links,
        private readonly SalesReportDocumentService $document,
    ) {}

    /** CENTRAL: send the reader to the tenant that owns this report. */
    public function redirect(Request $request, string $token)
    {
        // The approved template's button base was entered as '.../r/{{1}}' and Meta APPENDS the
        // parameter rather than substituting it, so live links arrive as '/r/{{1}}<token>'. Stripping
        // it keeps today's messages working, and costs nothing once the template is corrected — a
        // clean token simply has no prefix to remove.
        $token = preg_replace('/^\{\{1\}\}/', '', $token) ?? $token;

        $link = $this->links->resolve($token);
        abort_if($link === null, 404);

        $tenant = Tenant::find($link->tenant_id);
        abort_if($tenant === null, 404);

        return redirect()->away(sprintf(
            'https://%s.%s/r/%s',
            $tenant->tenant_code,
            config('tenancy.tenant_base_domain'),
            $token,
        ));
    }

    /** TENANT: the page itself — figures first, download started, button always there. */
    public function show(Request $request, string $token)
    {
        $link = $this->claim($token);

        $data = $this->document->data(
            (array) json_decode($link->filters, true),
            (array) json_decode($link->sections, true),
            true,
        );

        $this->links->markOpened($token);

        return response()
            ->view('reports.shared', $data + [
                'businessName' => app('tenant')->business_name,
                'periodLabel' => $link->label,
                'pdfUrl' => url('/r/'.$token.'/pdf'),
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** TENANT: the PDF — same token, same expiry, so the file is no more open than the page. */
    public function pdf(Request $request, string $token)
    {
        $link = $this->claim($token);

        return response($this->document->pdf(
            (array) json_decode($link->filters, true),
            (array) json_decode($link->sections, true),
        ), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="sales-report-'.$link->label.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Resolve the token AND check it belongs to the tenant whose subdomain we are on.
     *
     * Without that second check a link minted for one shop would render on another shop's subdomain —
     * the reader would simply swap the hostname and read somebody else's takings.
     */
    private function claim(string $token): object
    {
        $link = $this->links->resolve($token);
        abort_if($link === null, 404);

        abort_unless(app()->bound('tenant'), 404);
        abort_if((int) $link->tenant_id !== (int) app('tenant')->id, 404);

        return $link;
    }
}
