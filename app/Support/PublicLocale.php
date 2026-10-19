<?php

namespace App\Support;

/**
 * WEBSITE-I18N-GEO-1 — the languages of the PUBLIC website (bingoopos.com), and its URLs.
 *
 * The default language (English) keeps every URL it has always had: /pricing, /start-trial.
 * Every other enabled language lives under its own prefix: /ar/pricing, /ar/start-trial — the same
 * controller and view, with the app locale set from the URL. A language needs its own URL because
 * that is the only way a search engine indexes it; a session-only language is invisible to Google.
 *
 * This is about the public site only. The POS / back office keeps its own session switch
 * (/locale/{x}, SetLocale).
 */
final class PublicLocale
{
    /** Enabled public languages, default first: [code => ['native' => …, 'dir' => …, 'og' => …]]. */
    public static function all(): array
    {
        $known = (array) config('saas.public_locales', []);
        $enabled = (array) config('saas.public_locales_enabled', array_keys($known));
        $out = [];
        foreach ($enabled as $code) {
            if (isset($known[$code])) {
                $out[$code] = $known[$code];
            }
        }

        return $out ?: ['en' => ['native' => 'English', 'dir' => 'ltr', 'og' => 'en_US']];
    }

    public static function default(): string
    {
        return array_key_first(self::all());
    }

    /** Languages that live under a URL prefix — every enabled one except the default. */
    public static function prefixed(): array
    {
        return array_values(array_diff(array_keys(self::all()), [self::default()]));
    }

    public static function isEnabled(?string $code): bool
    {
        return $code !== null && isset(self::all()[$code]);
    }

    public static function current(): string
    {
        $locale = app()->getLocale();

        return self::isEnabled($locale) ? $locale : self::default();
    }

    public static function dir(?string $code = null): string
    {
        return self::all()[$code ?? self::current()]['dir'] ?? 'ltr';
    }

    public static function isRtl(?string $code = null): bool
    {
        return self::dir($code) === 'rtl';
    }

    /**
     * The URL of a public path in a language (the current one by default).
     * url('/pricing') → https://bingoopos.com/pricing   ·   in Arabic → https://bingoopos.com/ar/pricing
     */
    public static function url(string $path = '/', ?string $locale = null): string
    {
        $locale = $locale ?? self::current();
        $path = '/' . ltrim($path, '/');
        if ($locale === self::default() || ! self::isEnabled($locale)) {
            return url($path);
        }

        return url('/' . $locale . ($path === '/' ? '' : $path));
    }

    /** A request path with any language prefix removed: "ar/pricing" → "/pricing", "ar" → "/". */
    public static function strip(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        foreach (self::prefixed() as $code) {
            if ($path === '/' . $code) {
                return '/';
            }
            if (str_starts_with($path, '/' . $code . '/')) {
                return substr($path, strlen($code) + 1);
            }
        }

        return $path;
    }

    /**
     * This page in every enabled language. [code => url]
     * The language switcher keeps the query (?plan=…&billing=yearly survives a switch); hreflang and
     * the sitemap do not, since they name the page, not one visit to it.
     */
    public static function alternates(?string $requestPath = null, bool $withQuery = true): array
    {
        $path = self::strip($requestPath ?? request()->path());
        $query = $withQuery ? request()->getQueryString() : null;
        $out = [];
        foreach (array_keys(self::all()) as $code) {
            $out[$code] = self::url($path, $code) . ($query ? '?' . $query : '');
        }

        return $out;
    }
}
