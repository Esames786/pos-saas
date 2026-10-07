<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * WEBSITE-I18N-GEO-1 — the public website's sentences and their translations.
 *
 * Keys are the English sentences themselves, written in the views as __('…'), and each other
 * language has one JSON file: resources/lang/ar.json. This class finds every key the public site
 * uses and compares it with each file. lang:audit / lang:export / lang:import and the guard test
 * all read it, so they can never disagree about what "missing" means.
 */
final class PublicTranslations
{
    /** Views that make up the public site. */
    public const VIEW_PATHS = [
        'resources/views/layouts/public.blade.php',
        'resources/views/public',
    ];

    /** PHP files whose user-facing sentences are shown on the public site (validation, errors). */
    public const PHP_PATHS = [
        'app/Http/Requests/Public/StartTrialRequest.php',
        'app/Http/Controllers/PublicSiteController.php',
    ];

    /**
     * Single words that are also translation FILE names. As a JSON key, "Dashboard" falls through to
     * resources/lang/en/dashboard.php on a case-insensitive disk (Windows) and renders an array.
     */
    public const RESERVED = ['auth', 'common', 'dashboard', 'sidebar', 'pagination', 'passwords', 'validation'];

    /**
     * Every key used, in order of first appearance: [key => 'file:line'].
     *
     * $withPlans adds the public plans' names and descriptions, which live in the master DB and are
     * shown through __(). Off by default: a test database holds throwaway plans that no one translates.
     */
    public static function keys(bool $withPlans = false): array
    {
        $files = [];
        foreach (array_merge(self::VIEW_PATHS, self::PHP_PATHS) as $path) {
            $abs = base_path($path);
            if (is_dir($abs)) {
                foreach (File::allFiles($abs) as $file) {
                    if (str_ends_with($file->getFilename(), '.php')) {
                        $files[] = $file->getPathname();
                    }
                }
            } elseif (is_file($abs)) {
                $files[] = $abs;
            }
        }
        sort($files);

        $keys = [];
        foreach ($files as $file) {
            foreach (self::extract((string) file_get_contents($file)) as [$key, $line]) {
                $keys[$key] ??= str_replace([base_path() . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file) . ':' . $line;
            }
        }
        foreach (self::configKeys() as $key) {
            $keys[$key] ??= 'config/saas.php';
        }
        if ($withPlans) {
            foreach (self::planKeys() as $key) {
                $keys[$key] ??= 'master plans';
            }
        }

        return $keys;
    }

    /** Names and descriptions of the active public plans (empty when the master DB is unreachable). */
    public static function planKeys(): array
    {
        try {
            return \App\Models\Master\Plan::query()->where('is_active', true)->where('is_public', true)
                ->get(['name', 'public_description'])
                ->flatMap(fn ($p) => [$p->name, $p->public_description])
                ->filter(fn ($v) => is_string($v) && trim($v) !== '')->unique()->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** [[key, line], …] for every __('…') / __("…") / @lang('…') / trans_choice('…') in a source. */
    public static function extract(string $source): array
    {
        $out = [];
        $pattern = '/(?:\b__|@lang|\btrans_choice)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/u';
        if (preg_match_all($pattern, $source, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $single = $match[1][0] ?? '';
                $raw = $single !== '' ? $single : ($match[2][0] ?? '');
                $key = $single !== '' ? str_replace(["\\'", '\\\\'], ["'", '\\'], $raw) : stripcslashes($raw);
                if ($key === '') {
                    continue;
                }
                $out[] = [$key, substr_count(substr($source, 0, $match[0][1]), "\n") + 1];
            }
        }

        return $out;
    }

    /** Sentences that live in config but are shown on the public site (tagline, markets, modules, demo cards). */
    public static function configKeys(): array
    {
        $keys = array_filter([(string) config('saas.brand_tagline', '')]);
        foreach ((array) config('saas.markets', []) as $market) {
            if (! empty($market['name'])) {
                $keys[] = $market['name'];
            }
        }
        foreach ((array) config('saas.module_labels', []) as $label) {
            $keys[] = $label;
        }
        foreach ((array) config('saas.demos.cards', []) as $card) {
            foreach (['title', 'badge', 'description'] as $field) {
                if (! empty($card[$field]) && is_string($card[$field])) {
                    $keys[] = $card[$field];
                }
            }
            foreach ((array) ($card['bullets'] ?? []) as $bullet) {
                if (is_string($bullet) && $bullet !== '') {
                    $keys[] = $bullet;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    public static function path(string $locale): string
    {
        return lang_path($locale . '.json');
    }

    /** The translation file of a language: [key => translation]. */
    public static function load(string $locale): array
    {
        $file = self::path($locale);

        return is_file($file) ? (array) json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public static function save(string $locale, array $lines): void
    {
        file_put_contents(self::path($locale), json_encode($lines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** ['missing' => [key => where], 'empty' => [...], 'unused' => [key, …]] for one language. */
    public static function audit(string $locale, bool $withPlans = false): array
    {
        $keys = self::keys($withPlans);
        $lines = self::load($locale);

        return [
            'missing' => array_diff_key($keys, $lines),
            'empty'   => array_keys(array_filter($lines, fn ($v) => ! is_string($v) || trim($v) === '')),
            'unused'  => array_keys(array_diff_key($lines, $keys)),
        ];
    }
}
