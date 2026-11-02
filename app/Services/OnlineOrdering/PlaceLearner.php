<?php

namespace App\Services\OnlineOrdering;

use App\Console\Commands\OnlineOrderingMapPlacesCommand;
use App\Models\Master\GeoArea;
use App\Models\Master\GeoAreaPlace;
use App\Models\Tenant\DeliveryPlaceSighting;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\DeliveryZonePlace;
use App\Models\Tenant\SalesOrder;
use App\Services\Reports\SalesReportEngine;
use Illuminate\Support\Collection;

/**
 * PLACE-LEARNING-1 — the website's delivery areas mature with every delivered order (owner, 2 Nov: "suggested na
 * ae, area mature hote rahen jese jese order barhte rahen"). Plan: docs/plans/place-learning-2026-11-02.md.
 *
 * A PAID website delivery order with a good pin leaves one piece of evidence (delivery_place_sightings): what
 * OpenStreetMap calls the place at the pin, and the area the restaurant delivered it to. The learned place
 * (delivery_zone_places, source `learned`) is recomputed from ALL its evidence each time:
 *   area  = the area most deliveries went to (a tie: the latest),
 *   point = the median of those pins (one wrong pin cannot drag it),
 *   kind  = `nearby` after 1 order (counts for "nearest area" only), `sure` from 2 (picks the area by name and shows
 *           in "Recommended near you").
 * No "suggested" step. The admin always wins: a manual place or a moved map place is never touched, and a name the
 * map list already knows is not duplicated.
 */
class PlaceLearner
{
    /** Phone GPS rougher than this is a guess, not a place. A pin dropped by hand has no figure and counts. */
    public const MAX_ACCURACY_M = 200;

    /** From this many agreeing orders a learned place is trusted by its name. */
    public const SURE_AFTER = 2;

    public function __construct(private readonly OpenStreetMap $osm) {}

    /** Paid website delivery orders not looked at yet, oldest first. */
    public function pending(int $limit = 200): Collection
    {
        return SalesOrder::where('order_source', 'website')->where('order_type', 'delivery')
            ->whereIn('status', SalesReportEngine::POPULATION)
            ->whereNotIn('id', DeliveryPlaceSighting::query()->select('sales_order_id'))
            ->orderBy('id')->limit($limit)->get(['id', 'sale_no', 'web_meta']);
    }

    /**
     * Read one order. With $write: keep its evidence (or why there is none) and re-mature the place.
     * OpenStreetMap not answering = nothing kept, so the order is tried again next run.
     *
     * @return array{name: ?string, skipped: ?string}
     */
    public function learn(SalesOrder $sale, bool $write = true): array
    {
        $m = (array) $sale->web_meta;
        $zoneId = (int) ($m['area_id'] ?? 0);
        $lat = is_numeric($m['lat'] ?? null) ? (float) $m['lat'] : null;
        $lng = is_numeric($m['lng'] ?? null) ? (float) $m['lng'] : null;
        $acc = is_numeric($m['place_accuracy_m'] ?? null) ? (int) $m['place_accuracy_m'] : null;

        $skipped = match (true) {
            $zoneId === 0 || ! DeliveryZone::whereKey($zoneId)->exists() => 'no_area',
            $lat === null || $lng === null => 'no_pin',
            $acc !== null && $acc > self::MAX_ACCURACY_M => 'rough_gps',
            default => null,
        };
        $name = null;
        if ($skipped === null) {
            $osm = $this->osm->reverse($lat, $lng);
            if ($osm === null) {
                return ['name' => null, 'skipped' => 'map_unavailable'];
            }
            $name = self::nameFor((array) ($osm['address'] ?? []));
            $skipped = $name === null ? 'no_name' : null;
        }

        if ($write) {
            $key = $name !== null ? AreaLocator::norm($name) : null;
            DeliveryPlaceSighting::create([
                'sales_order_id' => $sale->id, 'delivery_zone_id' => $zoneId ?: null, 'name' => $name, 'name_key' => $key,
                'lat' => $lat, 'lng' => $lng, 'accuracy_m' => $acc, 'skipped' => $skipped,
            ]);
            if ($key !== null) {
                $this->mature($key);
            }
        }

        return ['name' => $name, 'skipped' => $skipped];
    }

    /**
     * The place's name at a pin: its neighbourhood when that is a name on its own; a generic one ("Block 6") gets
     * its society in front ("PECHS Block 6"); else the society; else nothing (a road is not a place).
     */
    public static function nameFor(array $a): ?string
    {
        $local = trim((string) ($a['neighbourhood'] ?? $a['residential'] ?? $a['quarter'] ?? ''));
        $broad = trim((string) ($a['suburb'] ?? $a['hamlet'] ?? ''));
        $distinct = fn (string $n) => $n !== '' && OnlineOrderingMapPlacesCommand::distinctive($n);

        if ($distinct($local)) {
            return $local;
        }
        if ($local !== '' && $distinct($broad)) {
            return str_contains(AreaLocator::norm($local), AreaLocator::norm($broad)) ? $local : $broad.' '.$local;
        }

        return $distinct($broad) ? $broad : null;
    }

    /** Recompute one learned place from all its evidence. */
    public function mature(string $key): ?DeliveryZonePlace
    {
        $rows = DeliveryPlaceSighting::where('name_key', $key)->whereNull('skipped')->whereNotNull('delivery_zone_id')->get();
        if ($rows->isEmpty()) {
            return null;
        }
        // The area most deliveries went to; a tie goes to the latest delivery.
        $zoneId = (int) $rows->groupBy('delivery_zone_id')
            ->map(fn (Collection $g) => [$g->count(), $g->max('id')])
            ->sort(fn ($a, $b) => $b <=> $a)->keys()->first();
        $in = $rows->where('delivery_zone_id', $zoneId);

        $place = DeliveryZonePlace::where('name_key', $key)->first();
        if ($place && $place->source !== DeliveryZonePlace::SOURCE_LEARNED) {
            return $place;   // the admin's own place, or a map place the restaurant moved: never ours to change
        }
        if (! $place && $this->onMapList($key, $zoneId)) {
            return null;     // the map list already knows this name
        }

        $values = [
            'delivery_zone_id' => $zoneId,
            'name' => $in->sortByDesc('id')->first()->name,
            'lat' => self::median($in->pluck('lat')), 'lng' => self::median($in->pluck('lng')),
            'kind' => $in->count() >= self::SURE_AFTER ? 'sure' : 'nearby',
            'orders_count' => $in->count(), 'last_order_at' => $in->max('created_at'),
        ];
        if ($place) {
            $place->update($values);   // status kept: one the admin hid stays hidden while it matures

            return $place;
        }

        return DeliveryZonePlace::create($values + ['name_key' => $key, 'source' => DeliveryZonePlace::SOURCE_LEARNED, 'status' => 'active']);
    }

    private function onMapList(string $key, int $zoneId): bool
    {
        $geoId = DeliveryZone::whereKey($zoneId)->value('geo_area_id');
        $city = $geoId ? GeoArea::whereKey($geoId)->value('city') : null;

        return GeoAreaPlace::where('name_key', $key)->where('status', 'active')
            ->when($city, fn ($q) => $q->where('city', $city))->exists();
    }

    private static function median(Collection $values): float
    {
        $v = $values->map(fn ($x) => (float) $x)->sort()->values();
        $n = $v->count();

        return round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2, 7);
    }
}
