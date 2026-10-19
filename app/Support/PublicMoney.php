<?php

namespace App\Support;

/**
 * WEBSITE-I18N-GEO-1 P2 — a price as the public website shows it, in the page's language.
 *
 * Western digits in every language (Saudi commerce on the web uses them, and Arabic-Indic digits
 * make prices harder to read). English: "SAR 549", "$169". Arabic: "549 ر.س", "$169".
 * Two decimals only when there are cents (2,082.60), never "549.00".
 * The plan builder's script formats the same way (config('saas.currency_labels') is handed to it).
 */
final class PublicMoney
{
    public static function number(float $amount): string
    {
        $cents = (int) round($amount * 100);

        return number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }

    public static function label(string $currency, ?string $locale = null): array
    {
        $labels = (array) config('saas.currency_labels', []);
        $entry = $labels[$currency] ?? [];
        $locale = $locale ?? app()->getLocale();

        return [
            'text' => $entry[$locale] ?? $entry['en'] ?? $currency,
            'prefix' => (bool) ($entry['prefix'] ?? ($locale !== 'ar')),
        ];
    }

    /** Plain text, wrapped so a right-to-left page never reorders the number and its sign. */
    public static function format(float $amount, string $currency, ?string $locale = null): string
    {
        $label = self::label($currency, $locale);
        $figure = self::number(abs($amount));
        $sign = $amount < 0 ? '−' : '';
        $text = $label['prefix']
            ? $sign . $label['text'] . ($label['text'] === '$' ? '' : ' ') . $figure
            : $sign . $figure . ' ' . $label['text'];

        return "\u{2068}" . $text . "\u{2069}";
    }
}
