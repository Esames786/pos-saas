<?php

namespace App\Console\Commands;

use App\Services\Saas\VisitorCountry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;
use Throwable;

/**
 * WEBSITE-I18N-GEO-1 P4 — fetch this month's DB-IP "IP to Country Lite" database (CC BY 4.0).
 *
 * Downloads to a temporary file, proves it opens and answers (8.8.8.8 → a country), and only then
 * replaces the live file in one rename — a broken download never takes the old file's place.
 * Tries this month's edition, then last month's (DB-IP publishes early in the month).
 * Run it as the web user on the server: sudo -u www-data php artisan geoip:update
 */
class GeoipUpdateCommand extends Command
{
    protected $signature = 'geoip:update {--url= : download this .mmdb.gz instead of the monthly DB-IP edition}';

    protected $description = 'Download the free DB-IP country database the public website uses to guess a visitor\'s country';

    public function handle(): int
    {
        $target = VisitorCountry::path();
        $dir = dirname($target);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create {$dir}");

            return self::FAILURE;
        }

        $urls = $this->option('url') ? [(string) $this->option('url')] : array_map(
            fn ($month) => str_replace(['{Y}', '{m}'], [$month->format('Y'), $month->format('m')], (string) config('saas.geoip.download_url')),
            [now()->startOfMonth(), now()->startOfMonth()->subMonth()]
        );

        foreach ($urls as $url) {
            try {
                $response = Http::timeout(120)->get($url);
            } catch (Throwable $e) {
                $this->warn("{$url}: {$e->getMessage()}");

                continue;
            }
            if (! $response->successful()) {
                $this->warn("{$url}: HTTP {$response->status()}");

                continue;
            }
            $bytes = str_ends_with($url, '.gz') ? @gzdecode($response->body()) : $response->body();
            if (! is_string($bytes) || $bytes === '') {
                $this->warn("{$url}: not a readable archive");

                continue;
            }

            $tmp = $target . '.download';
            file_put_contents($tmp, $bytes);
            try {
                $reader = new Reader($tmp);
                $probe = $reader->get('8.8.8.8')['country']['iso_code'] ?? null;
                $reader->close();
            } catch (Throwable $e) {
                $probe = null;
            }
            if (! is_string($probe) || strlen($probe) !== 2) {
                @unlink($tmp);
                $this->warn("{$url}: downloaded, but it does not answer a lookup — kept the old file");

                continue;
            }

            if (! @rename($tmp, $target)) {
                @unlink($tmp);
                $this->error("Could not replace {$target}");

                return self::FAILURE;
            }
            $this->info('GeoIP database updated: ' . basename($url) . ' (' . number_format(filesize($target) / 1048576, 1) . ' MB, 8.8.8.8 → ' . $probe . ')');

            return self::SUCCESS;
        }

        $this->error('No GeoIP database could be downloaded; the website keeps working without one (language default market).');

        return self::FAILURE;
    }
}
