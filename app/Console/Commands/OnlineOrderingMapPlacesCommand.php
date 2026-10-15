<?php

namespace App\Console\Commands;

use App\Models\Master\GeoArea;
use App\Models\Master\Tenant;
use App\Models\Tenant\DeliveryZone;
use App\Services\OnlineOrdering\AreaLocator;
use App\Services\OnlineOrdering\OpenStreetMap;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * ONLINE-ORDERING-1 — restaurant ke aas paas (default 10 km) ki har naam wali jagah (society, mohalla, goth…)
 * OpenStreetMap se, aur us ka delivery area — malik (15 Oct): "10 km ke radius mai jo b societies hain open street
 * say data le kar map karlo".
 *
 * Sirf pakke faisle likhe jate hain (andaza nahi — ghalat naam "You are in" ko ghalat banata hai):
 *   1. naam me hi area ka naam ("… SCHEME 33", "Block 13 Gulistan-e-Johar");
 *   2. jagah area ki OSM hadd (polygon) ke andar (Gulistan e Johar ke blocks ki haddein OSM par hain);
 *   3. jagah ka OSM "parent" (suburb) kisi area ka jana pehchana naam (Chappal Sun City → New Rizvia Society →
 *      Scheme 33).
 * Baqi "pata nahi" — report me, khud nahi. "Block 7", "Society" jaise aam naam kabhi nahi (har jagah milte).
 * Likhe naam master `geo_areas.aliases` me nuqte ke saath (src: osm) — har restaurant ke kaam ke. Default: dry run.
 */
class OnlineOrderingMapPlacesCommand extends Command
{
    protected $signature = 'online-ordering:map-places
        {tenant_code}
        {--lat= : Restaurant ka nuqta (latitude)}
        {--lng= : Restaurant ka nuqta (longitude)}
        {--radius=10 : Kitne km tak}
        {--pause=1100 : OSM pate ke sawalon ke beech (ms) — OSM ka qaida ~1/second}
        {--max-lookups=1500 : Zyada se zyada OSM pate ke sawal}
        {--yes : Likho. Is ke baghair sirf dikhata hai}';

    protected $description = 'Aas paas ki societies OpenStreetMap se — har ek ka delivery area (sirf pakke), default: dry run.';

    /** Public Overpass servers (pehla masroof ho to agla). */
    private const OVERPASS = [
        'https://overpass-api.de/api/interpreter',
        'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    /** Ye lafz akele naam nahi bana sakte ("Block 7", "Sector 5", "Society"). */
    private const GENERIC = ['block', 'sector', 'phase', 'society', 'societies', 'colony', 'town', 'garden', 'gardens', 'housing',
        'scheme', 'area', 'road', 'street', 'no', 'number', 'the', 'and', 'of', 'e', 'i', 'cooperative', 'co', 'operative', 'chs',
        'apartments', 'apartment', 'complex', 'plaza', 'square', 'tower', 'towers', 'residency', 'city', 'new', 'old', 'extension',
        'karachi', 'west', 'east', 'north', 'south', 'main', 'flats', 'quarters', 'lines', 'ext',
        // "Defence" / "DHA" akela har phase ka hai — kisi aik phase ka naam nahi.
        'defence', 'defense', 'dha', 'authority'];

    /** Aik hi jagah ke mukhtalif hijje. */
    private const SPELLINGS = ['jauhar' => 'johar', 'jouhar' => 'johar', 'jowhar' => 'johar', 'federal b' => 'fb', 'f b' => 'fb',
        'extention' => 'extension', 'extn' => 'extension', 'ext' => 'extension',
        'gulshan e iqbal' => 'gulshan e iqbal', 'gulshan i iqbal' => 'gulshan e iqbal', 'gulistan i johar' => 'gulistan e johar'];

    private int $asked = 0;

    private int $failed = 0;

    public function handle(TenancyManager $tenancy, OpenStreetMap $osm): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        $lat = (float) $this->option('lat');
        $lng = (float) $this->option('lng');
        $radiusM = (int) round(max(1.0, min(25.0, (float) $this->option('radius'))) * 1000);
        if (! $tenant || ! $lat || ! $lng) {
            $this->error('Tenant, --lat aur --lng chahiye.');

            return self::FAILURE;
        }
        $tenancy->activate($tenant);

        $zones = DeliveryZone::where('is_active', true)->whereNotNull('geo_area_id')->get(['id', 'area_name', 'geo_area_id']);
        $geo = GeoArea::whereIn('id', $zones->pluck('geo_area_id')->all())->get()->keyBy('id');
        // area id → us ke naam (area + aliases), har naam ke tokens
        $names = [];
        foreach ($zones as $z) {
            $g = $geo->get($z->geo_area_id);
            if (! $g) {
                continue;
            }
            foreach (array_merge([$g->name], array_column($g->aliasList(), 'name')) as $n) {
                $names[$g->id][] = self::tokens($n);
            }
        }

        $elements = $this->overpass($lat, $lng, $radiusM);
        if ($elements === null) {
            $this->error('OpenStreetMap (Overpass) se data nahi mila — thori der baad dobara.');

            return self::FAILURE;
        }

        // Areas ki haddein: OSM ki band shakal jis ka naam kisi area ka naam ho.
        $polygons = [];
        foreach ($elements as $e) {
            if (($e['type'] ?? '') === 'way' && count($e['geometry'] ?? []) >= 4 && ($areaId = $this->byName((string) $this->name($e), $names))) {
                $polygons[$areaId][] = array_map(fn ($p) => [(float) $p['lat'], (float) $p['lon']], $e['geometry']);
            }
        }

        $found = [];   // areaId => [normName => [name, lat, lng, how]]
        $unsure = [];
        $how = ['name' => 0, 'inside' => 0, 'parent' => 0];
        foreach ($elements as $e) {
            $name = trim((string) $this->name($e));
            $centre = $this->centre($e);
            if ($name === '' || ! $centre || ! self::distinctive($name)) {
                continue;
            }
            $areaId = $this->byName($name, $names);
            $by = 'name';
            if (! $areaId) {
                $areaId = $this->inside($centre, $polygons);
                $by = 'inside';
            }
            if (! $areaId) {
                $areaId = $this->byParent($osm, $centre, $name, $names);
                $by = 'parent';
            }
            if (! $areaId) {
                $unsure[] = $name;

                continue;
            }
            $key = implode(' ', self::tokens($name));
            if (isset($found[$areaId][$key]) || $this->isAreaName($key, $geo->get($areaId))) {
                continue;
            }
            $found[$areaId][$key] = ['name' => $name, 'lat' => round($centre[0], 6), 'lng' => round($centre[1], 6), 'how' => $by];
            $how[$by]++;
        }

        // Aik hi naam do areas me = shak — dono se nikal do.
        $seen = [];
        foreach ($found as $areaId => $list) {
            foreach (array_keys($list) as $k) {
                $seen[$k][] = $areaId;
            }
        }
        foreach ($seen as $k => $areas) {
            if (count(array_unique($areas)) > 1) {
                foreach ($areas as $a) {
                    unset($found[$a][$k]);
                }
                $unsure[] = $k;
            }
        }

        $this->report($found, $geo, $how, array_values(array_unique($unsure)), count($elements));
        if ($this->option('yes')) {
            $added = $this->write($found, $geo);
            $this->info("Likh diya: {$added} naye naam areas me.");
        } else {
            $this->warn('Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.');
        }

        return self::SUCCESS;
    }

    /** @return list<array>|null */
    private function overpass(float $lat, float $lng, int $radius): ?array
    {
        $around = "around:{$radius},{$lat},{$lng}";
        $q = '[out:json][timeout:120];('
            ."node({$around})[place~\"^(neighbourhood|suburb|quarter|hamlet|village|locality|isolated_dwelling)$\"][name];"
            ."way({$around})[place~\"^(neighbourhood|suburb|quarter|hamlet|village|locality)$\"][name];"
            ."relation({$around})[place~\"^(neighbourhood|suburb|quarter|hamlet|village|locality)$\"][name];"
            ."way({$around})[landuse=residential][name];"
            ."relation({$around})[landuse=residential][name];"
            .');out geom;';
        foreach (self::OVERPASS as $url) {
            try {
                $res = Http::withHeaders(['User-Agent' => 'BingooPOS-OnlineOrdering/1.0 (+https://bingoopos.com)'])
                    ->timeout(150)->asForm()->post($url, ['data' => $q]);
                if ($res->successful() && is_array($res->json('elements'))) {
                    $this->line('OpenStreetMap: '.count($res->json('elements')).' jagahen ('.parse_url($url, PHP_URL_HOST).')');

                    return $res->json('elements');
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function name(array $e): ?string
    {
        $t = $e['tags'] ?? [];

        return $t['name:en'] ?? $t['name'] ?? null;
    }

    /** @return array{0: float, 1: float}|null */
    private function centre(array $e): ?array
    {
        if (isset($e['lat'], $e['lon'])) {
            return [(float) $e['lat'], (float) $e['lon']];
        }
        if (isset($e['bounds'])) {
            $b = $e['bounds'];

            return [((float) $b['minlat'] + (float) $b['maxlat']) / 2, ((float) $b['minlon'] + (float) $b['maxlon']) / 2];
        }
        $pts = $e['geometry'] ?? [];
        if ($pts) {
            return [array_sum(array_column($pts, 'lat')) / count($pts), array_sum(array_column($pts, 'lon')) / count($pts)];
        }

        return null;
    }

    /** Naam me kisi area (ya us ke jane naam) ke SAARE tokens — sab se lamba jeetta hai. */
    private function byName(string $name, array $names): ?int
    {
        $have = array_flip(self::tokens($name));
        $best = null;
        $bestLen = 0;
        foreach ($names as $areaId => $list) {
            foreach ($list as $tokens) {
                // Area ke apne naam (Scheme 33 bhi) chalte hain; aam naam waise bhi alias nahi bante (distinctive).
                if ($tokens && ! array_diff($tokens, array_keys($have))) {
                    $len = strlen(implode(' ', $tokens));
                    if ($len > $bestLen) {
                        [$best, $bestLen] = [$areaId, $len];
                    }
                }
            }
        }

        return $best;
    }

    private function inside(array $p, array $polygons): ?int
    {
        foreach ($polygons as $areaId => $list) {
            foreach ($list as $poly) {
                if (self::pointInPolygon($p, $poly)) {
                    return $areaId;
                }
            }
        }

        return null;
    }

    /** OSM ka pata (suburb/mohalla) — jagah ka apna naam chhoR kar — kisi area ka jana naam? */
    private function byParent(OpenStreetMap $osm, array $p, string $own, array $names): ?int
    {
        // Cache me ho to foran; warna OSM ka qaida (~1 sawal/second) aur aik had.
        if (! \Illuminate\Support\Facades\Cache::has(OpenStreetMap::reverseKey($p[0], $p[1]))) {
            if ($this->asked >= (int) $this->option('max-lookups')) {
                return null;
            }
            if ($this->asked > 0) {
                usleep(max(0, (int) $this->option('pause')) * 1000);
            }
            $this->asked++;
        }
        $rev = $osm->reverse($p[0], $p[1]);
        if ($rev === null) {
            // OSM ne mana kiya / connection toota: thora ruk kar aik dafa aur. Phir bhi nahi = "jaanch nahi saka".
            sleep(3);
            $rev = $osm->reverse($p[0], $p[1]);
            if ($rev === null) {
                $this->failed++;

                return null;
            }
        }
        $a = (array) ($rev['address'] ?? []);
        $ownKey = implode(' ', self::tokens($own));
        foreach (['neighbourhood', 'residential', 'quarter', 'suburb', 'hamlet'] as $k) {
            $v = trim((string) ($a[$k] ?? ''));
            if ($v === '' || implode(' ', self::tokens($v)) === $ownKey) {
                continue;
            }
            if ($areaId = $this->byName($v, $names)) {
                return $areaId;
            }
        }

        return null;
    }

    private function isAreaName(string $key, ?GeoArea $g): bool
    {
        return $g && $key === implode(' ', self::tokens($g->name));
    }

    /** @return list<string> */
    public static function tokens(string $s): array
    {
        $n = ' '.AreaLocator::norm($s).' ';
        foreach (self::SPELLINGS as $from => $to) {
            $n = str_replace(' '.$from.' ', ' '.$to.' ', $n);
        }

        return array_values(array_filter(explode(' ', trim($n)), fn ($t) => $t !== ''));
    }

    /** "Block 7", "Society", "Sector 11 E" — akele kisi jagah ki pehchan nahi. */
    public static function distinctive(string $name): bool
    {
        return self::distinctiveTokens(self::tokens($name));
    }

    private static function distinctiveTokens(array $tokens): bool
    {
        foreach ($tokens as $t) {
            if (mb_strlen($t) >= 3 && ! ctype_digit($t) && ! in_array($t, self::GENERIC, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: float, 1: float}> $poly */
    public static function pointInPolygon(array $p, array $poly): bool
    {
        $in = false;
        for ($i = 0, $j = count($poly) - 1; $i < count($poly); $j = $i++) {
            [$yi, $xi] = $poly[$i];
            [$yj, $xj] = $poly[$j];
            if ((($yi > $p[0]) !== ($yj > $p[0])) && ($p[1] < ($xj - $xi) * ($p[0] - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $in = ! $in;
            }
        }

        return $in;
    }

    private function report(array $found, $geo, array $how, array $unsure, int $total): void
    {
        $rows = [];
        foreach ($found as $areaId => $list) {
            if ($list) {
                $rows[] = [$geo->get($areaId)?->name, count($list), implode(', ', array_slice(array_column($list, 'name'), 0, 6)).(count($list) > 6 ? ' …' : '')];
            }
        }
        usort($rows, fn ($a, $b) => $b[1] <=> $a[1]);
        $this->table(['Area', 'Places', 'Examples'], $rows);
        $this->line(sprintf('Pakke: %d (naam %d · hadd ke andar %d · parent %d) · pata nahi: %d · OSM jagahen: %d · OSM se pate poochhe: %d',
            array_sum($how), $how['name'], $how['inside'], $how['parent'], count($unsure), $total, $this->asked));
        if ($this->failed > 0) {
            $this->warn("OSM se {$this->failed} pate nahi mile (mana / connection) — dobara chalayein, jo mil chuke wo cache se foran.");
        }
        if ($unsure) {
            $this->line('Pata nahi (khud nahi lagaye): '.implode(' | ', array_slice($unsure, 0, 40)).(count($unsure) > 40 ? ' …' : ''));
        }
    }

    private function write(array $found, $geo): int
    {
        $added = 0;
        foreach ($found as $areaId => $list) {
            $g = $geo->get($areaId);
            if (! $g || ! $list) {
                continue;
            }
            $aliases = (array) ($g->aliases ?? []);
            $have = array_map(fn ($a) => implode(' ', self::tokens((string) ($a['name'] ?? ''))), $aliases);
            foreach ($list as $key => $p) {
                if (! in_array($key, $have, true)) {
                    $aliases[] = ['name' => $p['name'], 'lat' => $p['lat'], 'lng' => $p['lng'], 'src' => 'osm'];
                    $have[] = $key;
                    $added++;
                }
            }
            $g->update(['aliases' => $aliases]);
        }

        return $added;
    }
}
