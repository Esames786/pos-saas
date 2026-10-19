<?php

namespace App\Console\Commands;

use App\Support\PublicLocale;
use App\Support\PublicTranslations;
use Illuminate\Console\Command;

/**
 * WEBSITE-I18N-GEO-1 — which public-site sentences have no translation yet, per language.
 * Exit code 1 when anything is missing, so it can gate a deploy.
 */
class LangAuditCommand extends Command
{
    protected $signature = 'lang:audit {locale?* : languages to check (default: every enabled one but English)} {--unused : also list translations no page uses} {--plans : also check the names and descriptions of the public plans (from the master DB)}';

    protected $description = 'List public-website sentences that are missing a translation';

    public function handle(): int
    {
        $locales = $this->argument('locale') ?: PublicLocale::prefixed();
        $total = count(PublicTranslations::keys((bool) $this->option('plans')));
        $bad = 0;

        foreach ($locales as $locale) {
            $audit = PublicTranslations::audit($locale, (bool) $this->option('plans'));
            $missing = count($audit['missing']);
            $this->line(sprintf('<info>%s</info>: %d sentences · %d missing · %d empty · %d unused', $locale, $total, $missing, count($audit['empty']), count($audit['unused'])));
            foreach ($audit['missing'] as $key => $where) {
                $this->line("  missing  {$where}  " . mb_strimwidth($key, 0, 90, '…'));
            }
            foreach ($audit['empty'] as $key) {
                $this->line('  empty    ' . mb_strimwidth($key, 0, 90, '…'));
            }
            if ($this->option('unused')) {
                foreach ($audit['unused'] as $key) {
                    $this->line('  unused   ' . mb_strimwidth($key, 0, 90, '…'));
                }
            }
            $bad += $missing + count($audit['empty']);
        }

        return $bad > 0 ? self::FAILURE : self::SUCCESS;
    }
}
