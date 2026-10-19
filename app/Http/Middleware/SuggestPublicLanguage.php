<?php

namespace App\Http\Middleware;

use App\Services\Saas\VisitorCountry;
use App\Support\PublicLocale;
use Closure;
use Illuminate\Http\Request;

/**
 * WEBSITE-I18N-GEO-1 P4 — a first-time visitor from Saudi Arabia lands on the Arabic site.
 *
 * Runs on the DEFAULT-language pages only. Redirects once, on a GET of a page, when:
 *   - the visitor has not chosen a language (no bingoo_lang cookie, no ?lang=), and
 *   - is not a search engine or link-preview bot (VisitorCountry gives them no country, so they index
 *     the English URLs as English), and
 *   - their country opens in Arabic: always for the "arabic_countries" list; for the
 *     "arabic_if_browser_prefers" list only when the browser itself asks for Arabic first
 *     (many people in the UAE and Qatar read the web in English).
 * Choosing a language — the switcher sends ?lang= — is remembered for a year and always wins.
 */
class SuggestPublicLanguage
{
    public function __construct(private VisitorCountry $country) {}

    public function handle(Request $request, Closure $next)
    {
        $chosen = $request->query('lang');
        if (is_string($chosen) && PublicLocale::isEnabled($chosen)) {
            // An explicit choice: remember it, never second-guess it.
            $response = $next($request);

            return $this->remember($response, $chosen);
        }

        if ($request->isMethod('GET') && ! $request->cookie('bingoo_lang')) {
            $target = $this->suggested($request);
            if ($target !== null && $target !== app()->getLocale()) {
                $url = PublicLocale::url(PublicLocale::strip($request->path()), $target)
                    . ($request->getQueryString() ? '?' . $request->getQueryString() : '');

                return $this->remember(redirect($url, 302), $target);
            }
        }

        return $next($request);
    }

    private function suggested(Request $request): ?string
    {
        $country = $this->country->of($request);
        if (! $country || ! PublicLocale::isEnabled('ar')) {
            return null;
        }
        if (in_array($country, (array) config('saas.geoip.arabic_countries', []), true)) {
            return 'ar';
        }
        if (in_array($country, (array) config('saas.geoip.arabic_if_browser_prefers', []), true)
            && str_starts_with(strtolower((string) ($request->getLanguages()[0] ?? '')), 'ar')) {
            return 'ar';
        }

        return null;
    }


    private function remember($response, string $locale)
    {
        if (method_exists($response, 'withCookie')) {
            $response->withCookie(cookie('bingoo_lang', $locale, 60 * 24 * 365, '/', null, null, false, false, 'lax'));
        }

        return $response;
    }
}
