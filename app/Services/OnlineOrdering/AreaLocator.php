<?php

namespace App\Services\OnlineOrdering;

use App\Models\Master\GeoArea;
use App\Models\Tenant\DeliveryZone;
use Illuminate\Support\Collection;

/**
 * ONLINE-ORDERING-1 — customer ki location se delivery area (malik: "auto fetch scheme 33 ya qareebi area").
 *
 * 1. Naam: OpenStreetMap jagah ka naam deta hai (mohalla, block, town). Jis area ke naam — ya us ke andar ki
 *    kisi society (`geo_areas.aliases`, jaise Scheme 33 → Gulzar-e-Hijri, aur restaurant ki apni likhi
 *    `delivery_zones.places`) — ke SAARE lafz us me hon wo area; sab se lamba naam jeetta hai.
 * 2. Qareebi: naam na mile to area ke nuqton (markazi `lat/lng` + har alias ka apna) me se jo sab se qareeb ho —
 *    shehar ke andar (MAX_KM). Bare area ka aik nuqta kafi nahi (Scheme 33 ka markaz 7 km door, Johar 3.6 km).
 * Dono na milen to kuch nahi — customer list se chunta hai. Aas paas ke areas hamesha saath (chunne me aasani).
 */
class AreaLocator
{
    /**
     * Malik (15 Oct): "poora Karachi allowed hai, km range ki zaroorat nahi" — naam na mile to qareebi area, chahe
     * kitna door. Sirf shehar ki hadd: is se door phone (doosra shehar / ghalat GPS) par koi area khud nahi chuna jata.
     */
    public const MAX_KM = 50.0;

    /** OSM pate ke sarkari/bare hisse — naam milane me nahi ginte (town = "Liaquatabad Town" jaise poore zile). */
    private const ADMIN_PARTS = ['town', 'city_district', 'district', 'county', 'state_district', 'state', 'region',
        'city', 'municipality', 'postcode', 'country', 'country_code'];

    public function __construct(private readonly OpenStreetMap $osm) {}

    /**
     * @return array{area: ?int, by: ?string, km: ?float, place: ?string, street: ?string, nearby: list<int>}
     */
    public function locate(float $lat, float $lng): array
    {
        $zones = DeliveryZone::where('is_active', true)->get(['id', 'area_name', 'geo_area_id', 'places']);
        $geo = GeoArea::whereIn('id', $zones->pluck('geo_area_id')->filter()->all())
            ->get(['id', 'lat', 'lng', 'aliases'])->keyBy('id');

        $distances = $zones->mapWithKeys(function (DeliveryZone $z) use ($geo, $lat, $lng) {
            $points = self::points($geo->get($z->geo_area_id), $z);

            return $points ? [$z->id => min(array_map(fn ($p) => self::km($lat, $lng, $p[0], $p[1]), $points))] : [];
        })->sort();

        $osm = $this->osm->reverse($lat, $lng) ?? [];
        $address = (array) ($osm['address'] ?? []);
        $byName = $this->byName($zones, $geo, $address, (string) ($osm['name'] ?? ''));

        $nearestId = $distances->keys()->first();
        $nearest = $nearestId !== null && $distances[$nearestId] <= self::MAX_KM ? $nearestId : null;
        $areaId = $byName ?? $nearest;

        return [
            'area' => $areaId,
            'by' => $byName ? 'name' : ($nearest ? 'nearest' : null),
            'km' => $areaId !== null && isset($distances[$areaId]) ? round($distances[$areaId], 1) : null,
            'place' => $this->place($address),
            'street' => $this->street($address),
            // Customer ko dikhane aur order ke pate ke saath jorne ke liye: gali, mohalla/society, bari society.
            'label' => $this->label($address),
            'nearby' => $distances->filter(fn ($km) => $km <= self::MAX_KM)->keys()->take(5)->values()->all(),
        ];
    }

    /** @return list<array{0: float, 1: float}> area ka markaz + us ki societies ke nuqte (master + restaurant ki likhi) */
    private static function points(?GeoArea $g, DeliveryZone $z): array
    {
        $out = $g && $g->lat !== null && $g->lng !== null ? [[(float) $g->lat, (float) $g->lng]] : [];
        foreach (array_merge($g?->aliasList() ?? [], $z->placeList()) as $a) {
            if ($a['lat'] !== null && $a['lng'] !== null) {
                $out[] = [$a['lat'], $a['lng']];
            }
        }

        return $out;
    }

    /** Naam se: area ke (ya us ki kisi society ke) naam ke saare lafz OSM ke pate me hon. */
    private function byName(Collection $zones, Collection $geo, array $address, string $placeName): ?int
    {
        // Sirf jagah ke apne naam (gali, mohalla, society, imarat). Bare sarkari hisse nahi: Nazimabad ka pata
        // "Liaquatabad Town" me aata hai, Clifton ka "Saddar Town" me — un se "Liaquatabad"/"Saddar" area PAKKA
        // nahi hota (UAT: Dow Hospital Nazimabad "You are in Liaquatabad"). Wahan qareebi area (andaza) chalta hai.
        $parts = array_filter($address, fn ($v, $k) => is_string($v) && ! in_array($k, self::ADMIN_PARTS, true)
            && ! str_starts_with((string) $k, 'ISO3166'), ARRAY_FILTER_USE_BOTH);
        $hay = ' '.self::norm(implode(' ', $parts).' '.$placeName).' ';

        $best = null;
        $bestLen = 0;
        foreach ($zones as $z) {
            $names = array_merge([(string) $z->area_name], array_column($geo->get($z->geo_area_id)?->aliasList() ?? [], 'name'),
                array_column($z->placeList(), 'name'));
            foreach ($names as $name) {
                $tokens = array_values(array_filter(explode(' ', self::norm($name))));
                if ($tokens === []) {
                    continue;
                }
                $all = collect($tokens)->every(fn ($t) => str_contains($hay, ' '.$t.' '));
                $len = strlen(implode(' ', $tokens));
                if ($all && $len > $bestLen) {
                    $best = $z->id;
                    $bestLen = $len;
                }
            }
        }

        return $best;
    }

    private function place(array $a): ?string
    {
        $bits = array_values(array_unique(array_filter([
            $a['neighbourhood'] ?? $a['residential'] ?? $a['quarter'] ?? null,
            $a['suburb'] ?? $a['hamlet'] ?? $a['town'] ?? null,
        ])));

        return $bits ? implode(', ', $bits) : null;
    }

    /** "Zuhra Nagar Road, City Villas, Usmani Town" — sarkari town/zila nahi. */
    private function label(array $a): ?string
    {
        $bits = array_values(array_unique(array_filter([
            $a['road'] ?? null,
            $a['neighbourhood'] ?? $a['residential'] ?? $a['quarter'] ?? null,
            $a['suburb'] ?? $a['hamlet'] ?? null,
        ])));

        return $bits ? implode(', ', $bits) : null;
    }

    private function street(array $a): ?string
    {
        $bits = array_values(array_filter([$a['house_number'] ?? null, $a['road'] ?? null, $a['neighbourhood'] ?? $a['residential'] ?? null]));

        return $bits ? implode(', ', $bits) : null;
    }

    /** "Gulshan-e-Iqbal Block 13-D" → "gulshan e iqbal block 13 d" */
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/(\d)(\p{L})/u', '$1 $2', $s) ?? $s;   // 13d → 13 d

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * 6371.0 * asin(min(1.0, sqrt($h)));
    }
}
