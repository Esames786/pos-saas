<?php

namespace App\Console\Commands;

use App\Models\Master\GeoArea;
use App\Services\OnlineOrdering\OpenStreetMap;
use Illuminate\Console\Command;

/**
 * ONLINE-ORDERING-1 — master areas ke markazi nuqte OpenStreetMap se (aik dafa; naye area aayen to dobara).
 *
 * "Use my current location" par naam na mile to qareebi area inhi nuqton se chuna jata hai. OSM ke qaaide:
 * har request ke beech ~1 second. "Clifton Block 5" na mile to "Block 5, Clifton", phir "Clifton" (andaza —
 * report me likha jata hai). Default sirf dikhata hai.
 */
class OnlineOrderingGeocodeAreasCommand extends Command
{
    protected $signature = 'online-ordering:geocode-areas
        {--city= : Sirf is shehar ke areas}
        {--force : Jin ka nuqta pehle se hai unhe bhi dobara}
        {--yes : Likho. Is ke baghair sirf dikhata hai}';

    protected $description = 'Master delivery areas ke lat/lng OpenStreetMap se (default: dry run).';

    private const COUNTRY_CODES = ['pakistan' => 'pk', 'saudi arabia' => 'sa', 'united arab emirates' => 'ae', 'qatar' => 'qa'];

    public function handle(OpenStreetMap $osm): int
    {
        $areas = GeoArea::query()
            ->when($this->option('city'), fn ($q, $city) => $q->where('city', $city))
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('lat'))
            ->orderBy('city')->orderBy('name')->get();

        if ($areas->isEmpty()) {
            $this->info('Har area ka nuqta pehle se hai.');

            return self::SUCCESS;
        }

        $found = 0;
        $guessed = [];
        $missing = [];
        foreach ($areas as $i => $area) {
            $cc = self::COUNTRY_CODES[mb_strtolower((string) $area->country)] ?? null;
            $hit = null;
            $approx = false;
            foreach ($this->queries($area) as $n => $q) {
                if ($i > 0 || $n > 0) {
                    usleep(1100000);   // OSM: ~1 request/second
                }
                if ($hit = $osm->search($q, $cc)) {
                    $approx = $n === 2;
                    break;
                }
            }

            if (! $hit) {
                $missing[] = $area->name;
                $this->line(sprintf('  %-34s —', $area->name));

                continue;
            }
            $found++;
            if ($approx) {
                $guessed[] = $area->name;
            }
            $this->line(sprintf('  %-34s %.5f, %.5f%s', $area->name, $hit['lat'], $hit['lng'], $approx ? '  (andaza: bara area)' : ''));
            if ($this->option('yes')) {
                $area->update(['lat' => $hit['lat'], 'lng' => $hit['lng']]);
            }
        }

        $this->newLine();
        $this->info("Mile: {$found} / {$areas->count()}".($guessed ? ' · andaza: '.count($guessed) : '').($missing ? ' · nahi mile: '.count($missing) : ''));
        if (! $this->option('yes')) {
            $this->warn('Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.');
        }

        return self::SUCCESS;
    }

    /** @return list<string> pehle poora naam, phir "Block N, baqi", phir bina block (andaza). */
    private function queries(GeoArea $area): array
    {
        $tail = ', '.$area->city.', '.$area->country;
        $q = [$area->name.$tail];
        if (preg_match('/^(.*?)\s+(Block\s+\S+)$/i', $area->name, $m)) {
            $q[] = $m[2].', '.$m[1].$tail;
            $q[] = $m[1].$tail;
        }

        return $q;
    }
}
