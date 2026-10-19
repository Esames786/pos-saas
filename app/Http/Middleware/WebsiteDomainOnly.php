<?php

namespace App\Http\Middleware;

use App\Models\Master\TenantDomain;
use Closure;
use Illuminate\Http\Request;

/**
 * ONLINE-ORDERING-1 (Phase 5) — client ke apne domain (kashiffood.com) ke raaste sirf usi par.
 *
 * Ye raaste kisi domain se bandhe nahi (client ka domain pehle se maloom nahi hota), aur sab se aakhir me
 * register hote hain — `bingoopos.com` aur `*.bingoopos.com` apne raaston par pehle hi mil jate hain. Phir bhi
 * jo request yahan pohanche aur `IdentifyTenant` ne us ka host "website" qism ka na pehchana ho, 404.
 */
class WebsiteDomainOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (! app()->bound('tenant') || $request->attributes->get('tenant_domain_kind') !== TenantDomain::KIND_WEBSITE) {
            abort(404);
        }

        return $next($request);
    }
}
