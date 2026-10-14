<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Master\Tenant;
use Illuminate\Http\Request;

/**
 * ONLINE-ORDERING-1 (Phase 4) — WhatsApp "Confirm Order" button ka pehla qadam.
 *
 * Meta ke approved template me button ka base aik hi hota hai (`https://bingoopos.com/o/{{1}}`) — har tenant
 * ka apna domain nahi ho sakta. Is liye link me tenant ka code saath hai (`kashiffood.<token>`); ye safha sirf
 * customer ko us tenant ke confirm safhe par bhej deta hai. Report share link (`/r/…`) jaisa hi.
 */
class OrderLinkController extends Controller
{
    public function redirect(Request $request, string $code)
    {
        // Meta parameter ko `{{1}}` ke BAAD jorta hai (report link par yahi hua) — wo hissa hata do.
        $code = preg_replace('/^\{\{1\}\}/', '', $code) ?? $code;
        abort_unless(preg_match('/^([a-z0-9-]+)\.([A-Za-z0-9]{40})$/', $code, $m), 404);

        $tenant = Tenant::where('tenant_code', $m[1])->where('status', 'active')->first();
        abort_unless($tenant, 404);

        $port = $request->getPort();
        $host = $tenant->tenant_code.'.'.config('tenancy.tenant_base_domain').(in_array($port, [80, 443], true) ? '' : ':'.$port);

        return redirect()->away($request->getScheme().'://'.$host.'/order/confirm/'.$m[2]);
    }
}
