<?php

namespace App\Http\Middleware;

use App\Support\PublicLocale;
use Closure;
use Illuminate\Http\Request;

/**
 * WEBSITE-I18N-GEO-1 — the public website's language comes from its URL (/ar/… = Arabic).
 *
 * Runs after the web group's SetLocale, which reads the back office's session switch. On the public
 * site the URL wins, so an owner who uses the POS in Arabic still gets English at /pricing, and a
 * search engine always gets the language the URL promises.
 */
class SetPublicLocale
{
    public function handle(Request $request, Closure $next, string $locale)
    {
        app()->setLocale(PublicLocale::isEnabled($locale) ? $locale : PublicLocale::default());

        return $next($request);
    }
}
