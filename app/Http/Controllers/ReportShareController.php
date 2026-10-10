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

    /** TENANT: link par click — seedha poori report. */
    public function show(Request $request, string $token)
    {
        // Link par click karte hi SEEDHA poori report. Pehle yahan aik khulasa ka safha aata tha
        // (net sales, orders, cash) aur us par "Open full report" ka button hota tha — do qadam,
        // jabke malik ko aik hi cheez chahiye thi.
        //
        // Aur ab wo safha kehta bhi wohi tha jo message me pehle se likha hota hai: template v2 me
        // poora OVERALL + CASH FROM SALES block WhatsApp ke paigham me hi aa jata hai. Yani wo safha
        // aik hi baat teesri dafa dohra raha tha, aur beech me khaRa tha.
        //
        // `reports.shared` wala view jaan boojh kar rakha hai, mitaya nahi: wapis chahiye to ye
        // method hi badalna hai, aur kuch nahi.
        return $this->render($token);
    }

    /** TENANT: wohi report, purana pata — pehle bheje gaye links tootne nahi chahiyen. */
    public function pdf(Request $request, string $token)
    {
        return $this->render($token);
    }

    /**
     * Token se poori report — dono raaston ka aik hi jawab.
     *
     * `markOpened()` YAHAN hai, kisi aik route par nahi. Pehle wo sirf khulasa ke safhe par chalta
     * tha aur PDF par nahi; ab jab link seedha PDF deta hai, agar nishan wahin reh jata to har open
     * chup-chaap guzar jata — aur ye nishan isi liye hai ke koi anjaan parhne wala pakRa ja sake.
     */
    private function render(string $token)
    {
        $link = $this->claim($token);

        $this->links->markOpened($token);

        return response($this->document->pdf(
            (array) json_decode($link->filters, true),
            (array) json_decode($link->sections, true),
        ), 200, [
            'Content-Type' => 'application/pdf',
            // INLINE, attachment nahi: malik chahte hain ke report khul jaye, phone ya laptop ke
            // apne PDF viewer me, bajaye is ke ke pehle Downloads me gire aur phir usay dhoondna
            // paRe. Jise mehfooz karni ho wo viewer ke apne download ke button se kar sakta hai —
            // yani dekhna aasan ho gaya aur mehfooz karna waisa hi raha.
            //
            // (Jab beech me khulasa ka safha tha, sirf ye header badalna kaafi NAHI hota tha: us ke
            // link par `download` attribute bhi laga hota hai jo is header par bhaari paRta hai.
            // Ab safha beech me nahi, magar baat yaad rakhne ki hai.)
            'Content-Disposition' => 'inline; filename="sales-report-'.$link->label.'.pdf"',
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
