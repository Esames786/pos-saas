<?php

namespace App\Services\Saas;

use Illuminate\Http\Request;
use MaxMind\Db\Reader;
use Throwable;

/**
 * WEBSITE-I18N-GEO-1 P4 — which country a website visitor is in, from their IP.
 *
 * Read from a database FILE on this server (DB-IP "IP to Country Lite", CC BY 4.0, refreshed monthly
 * by `geoip:update`): no call to any outside service, so a visitor's address goes nowhere. Country
 * only — a city would change neither the language nor the price, and its guess is far weaker.
 *
 * Never decisive and never fatal: no file, a private IP, or any error is simply "unknown", and the
 * site falls back to the language's default market. A visitor's own choice always wins over it.
 */
class VisitorCountry
{
    /** Search engines and link previews (WhatsApp, Facebook) — they see a page as everyone would. */
    private const BOTS = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|telegram|preview|lighthouse|headless/i';

    private ?Reader $reader = null;

    private bool $opened = false;

    /** Two-letter ISO code, upper case, or null when unknown. */
    public function of(Request $request): ?string
    {
        // Google crawls mostly from the US: a crawler must index the English page with its own (Pakistan)
        // prices and never be sent to /ar, so to the site a bot is always from nowhere.
        if (self::isBot($request)) {
            return null;
        }

        // Behind Cloudflare (if it is ever put in front) the edge already knows — trust it only when told to.
        if (config('saas.geoip.trust_cloudflare_header')) {
            $edge = strtoupper((string) $request->header('CF-IPCountry'));
            if (preg_match('/^[A-Z]{2}$/', $edge) && $edge !== 'XX' && $edge !== 'T1') {
                return $edge;
            }
        }

        return $this->lookup((string) $request->ip());
    }

    public function lookup(string $ip): ?string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;   // local / private / reserved: nothing to look up
        }
        $reader = $this->reader();
        if (! $reader) {
            return null;
        }
        try {
            $record = $reader->get($ip);
            $code = $record['country']['iso_code'] ?? null;

            return is_string($code) && preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function isBot(Request $request): bool
    {
        return (bool) preg_match(self::BOTS, (string) $request->userAgent());
    }

    public static function path(): string
    {
        return (string) config('saas.geoip.path', storage_path('app/geoip/dbip-country-lite.mmdb'));
    }

    private function reader(): ?Reader
    {
        if (! $this->opened) {
            $this->opened = true;
            try {
                $this->reader = is_file(self::path()) ? new Reader(self::path()) : null;
            } catch (Throwable) {
                $this->reader = null;
            }
        }

        return $this->reader;
    }
}
