<?php

namespace App\Console\Commands;

use App\Support\PublicTranslations;
use Illuminate\Console\Command;

/**
 * WEBSITE-I18N-GEO-1 — a review sheet for a native reader: one row per public-site sentence,
 * English next to the current translation, with an empty "notes" column. Opens in Excel
 * (UTF-8 with BOM, so Arabic shows correctly). The reviewer edits column C and sends it back
 * to lang:import.
 */
class LangExportCommand extends Command
{
    protected $signature = 'lang:export {locale} {path? : where to write (default storage/app/lang-<locale>.csv)}';

    protected $description = 'Export public-website sentences and their translation to a CSV for review';

    public function handle(): int
    {
        $locale = (string) $this->argument('locale');
        $path = (string) ($this->argument('path') ?: storage_path("app/lang-{$locale}.csv"));
        $lines = PublicTranslations::load($locale);

        $out = fopen($path, 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['key (English)', 'where', $locale, 'notes']);
        $n = 0;
        foreach (PublicTranslations::keys(true) as $key => $where) {   // the reviewer sees the plan texts too
            fputcsv($out, [$key, $where, $lines[$key] ?? '', '']);
            $n++;
        }
        fclose($out);

        $this->info("{$n} sentences → {$path}");

        return self::SUCCESS;
    }
}
