<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TenantOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (!app()->bound('tenant')) {
            abort(404);
        }

        // ONLINE-ORDERING-1: a website domain that is itself a *.bingoopos.com name (UAT, e.g.
        // kashiffoodweb.bingoopos.com) lands in this group too — there only the website opens. The bare
        // address goes to the menu; login, POS, admin and every other tenant page are 404.
        if ($request->attributes->get('tenant_domain_kind') === \App\Models\Master\TenantDomain::KIND_WEBSITE
            && ! str_starts_with((string) $request->route()?->getName(), 'website.')) {
            if ($request->path() === '/') {
                return redirect('/order');
            }
            abort(404);
        }

        return $next($request);
    }
}
