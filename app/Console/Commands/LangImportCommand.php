<?php

namespace App\Console\Commands;

use App\Support\PublicTranslations;
use Illuminate\Console\Command;

/**
 * WEBSITE-I18N-GEO-1 — take a reviewed lang:export sheet back into resources/lang/<locale>.json.
 *
 * Only sentences the site actually uses are taken; an empty cell never wipes a translation;
 * nothing is written with --dry-run. Every change is listed.
 */
class LangImportCommand extends Command
{
    protected $signature = 'lang:import {locale} {path} {--dry-run : show what would change, write nothing}';

    protected $description = 'Import a reviewed translation CSV for the public website';

    public function handle(): int
    {
        $locale = (string) $this->argument('locale');
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $keys = PublicTranslations::keys(true);
        $lines = PublicTranslations::load($locale);
        $in = fopen($path, 'r');
        $header = fgetcsv($in);
        $changed = $skipped = 0;
        while (($row = fgetcsv($in)) !== false) {
            $key = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($row[0] ?? ''));
            $value = trim((string) ($row[2] ?? ''));
            if ($key === '' || $value === '') {
                continue;
            }
            if (! isset($keys[$key])) {
                $skipped++;
                $this->line('  skipped (no page uses it)  ' . mb_strimwidth($key, 0, 80, '…'));

                continue;
            }
            if (($lines[$key] ?? null) !== $value) {
                $this->line('  ' . (isset($lines[$key]) ? 'changed' : 'added  ') . '  ' . mb_strimwidth($key, 0, 60, '…') . '  →  ' . mb_strimwidth($value, 0, 60, '…'));
                $lines[$key] = $value;
                $changed++;
            }
        }
        fclose($in);

        if (! $this->option('dry-run') && $changed > 0) {
            PublicTranslations::save($locale, $lines);
        }
        $this->info(($this->option('dry-run') ? '[dry run] ' : '') . "{$changed} changed, {$skipped} skipped");

        return self::SUCCESS;
    }
}
