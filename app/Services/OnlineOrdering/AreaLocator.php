<?php

namespace App\Services\OnlineOrdering;

use App\Models\Master\GeoArea;
use App\Models\Tenant\DeliveryZone;
use Illuminate\Support\Collection;

/**
 * ONLINE-ORDERING-1 — customer ki location se delivery area (malik: "auto fetch scheme 33 ya qareebi area").
 *
 * 1. Naam: OpenStreetMap jagah ka naam deta hai (mohalla, block, town). Jis area ke naam ke SAARE lafz us me
 *    hon wo area — sab se lamba naam jeetta hai ("Clifton Block 5" > "Clifton").
 * 2. Qareebi: naam na mile to area ke markazi nuqte (`geo_areas.lat/lng`) se sab se qareebi, MAX_KM ke andar.
 * Dono na milen to kuch nahi — customer list se chunta hai. Aas paas ke areas hamesha saath (chunne me aasani).
 */
class AreaLocator
{
    public const MAX_KM = 5.0;   // malik: naam na mile to 5 km ke andar ka qareebi area

    public function __construct(private readonly OpenStreetMap $osm) {}

    /**
     * @return array{area: ?int, by: ?string, km: ?float, place: ?string, street: ?string, nearby: list<int>}
     */
    public function locate(float $lat, float $lng): array
    {
        $zones = DeliveryZone::where('is_active', true)->get(['id', 'area_name', 'geo_area_id']);
        $points = GeoArea::whereIn('id', $zones->pluck('geo_area_id')->filter()->all())->whereNotNull('lat')
            ->get(['id', 'lat', 'lng'])->keyBy('id');

        $distances = $zones->mapWithKeys(function (DeliveryZone $z) use ($points, $lat, $lng) {
            $p = $points->get($z->geo_area_id);

            return $p ? [$z->id => self::km($lat, $lng, (float) $p->lat, (float) $p->lng)] : [];
        })->sort();

        $osm = $this->osm->reverse($lat, $lng) ?? [];
        $address = (array) ($osm['address'] ?? []);
        $byName = $this->byName($zones, $address, (string) ($osm['display_name'] ?? ''));

        $nearestId = $distances->keys()->first();
        $nearest = $nearestId !== null && $distances[$nearestId] <= self::MAX_KM ? $nearestId : null;
        $areaId = $byName ?? $nearest;

        return [
            'area' => $areaId,
            'by' => $byName ? 'name' : ($nearest ? 'nearest' : null),
            'km' => $areaId !== null && isset($distances[$areaId]) ? round($distances[$areaId], 1) : null,
            'place' => $this->place($address),
            'street' => $this->street($address),
            'nearby' => $distances->filter(fn ($km) => $km <= self::MAX_KM * 2)->keys()->take(5)->values()->all(),
        ];
    }

    /** Naam se: area ke saare lafz OSM ke pate me hon. */
    private function byName(Collection $zones, array $address, string $display): ?int
    {
        $parts = array_intersect_key($address, array_flip([
            'house_number', 'road', 'neighbourhood', 'quarter', 'suburb', 'hamlet', 'residential', 'village', 'town', 'city_district',
        ]));
        $hay = ' '.self::norm(implode(' ', $parts).' '.$display).' ';

        $best = null;
        $bestLen = 0;
        foreach ($zones as $z) {
            $tokens = array_values(array_filter(explode(' ', self::norm((string) $z->area_name))));
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
