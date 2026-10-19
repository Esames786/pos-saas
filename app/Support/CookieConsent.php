<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * WEBSITE-I18N-GEO-1 P5 — the public website's cookie question.
 *
 * Only analytics needs the visitor's permission; everything else the site stores is necessary
 * (session, form token, language, currency, this answer). So: no measurement ID configured → nothing
 * to ask → no banner and no Google script. The answer is "v1.a1" (analytics yes) or "v1.a0" (no) —
 * the "v1" lets a later version (say, an ad pixel) ask again instead of reading an old yes as a yes
 * to something the visitor never saw.
 */
final class CookieConsent
{
    public const COOKIE = 'bingoo_consent';

    /** MD §8: ask again after 12 months. */
    public const DAYS = 365;

    /** The GA4 measurement ID, or null when analytics is off (or the value is not a GA4 ID). */
    public static function ga4Id(): ?string
    {
        $id = strtoupper(trim((string) config('saas.analytics.ga4_id')));

        return preg_match('/^G-[A-Z0-9]{4,20}$/', $id) ? $id : null;
    }

    /** ['analytics' => bool] once the visitor has answered; null while they have not. */
    public static function choice(Request $request): ?array
    {
        if (! preg_match('/^v1\.a([01])$/', (string) $request->cookie(self::COOKIE), $m)) {
            return null;
        }

        return ['analytics' => $m[1] === '1'];
    }
}
