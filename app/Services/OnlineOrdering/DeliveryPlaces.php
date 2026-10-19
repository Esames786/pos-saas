<?php

namespace App\Services\OnlineOrdering;

use App\Models\Master\GeoAreaPlace;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\DeliveryZonePlace;
use Illuminate\Support\Collection;

/**
 * ONLINE-ORDERING-1 — is restaurant ki jagahen aur un ke delivery area, AIK jagah se (website ka area dhoondhna
 * aur Delivery Places screen dono yahi parhte hain):
 *   platform (geo_area_places: OpenStreetMap / hamari) — restaurant ke override ke saath (doosra area / chhupi)
 *   + restaurant ki apni (delivery_zone_places: manual / learned).
 */
class DeliveryPlaces
{
    /**
     * @return Collection<int, array{origin: string, id: int, override_id: ?int, zone_id: int, home_zone_id: ?int,
     *   name: string, name_key: string, lat: ?float, lng: ?float, source: string, kind: string, status: string,
     *   orders_count: int, osm_ref: ?string}>
     */
    public function all(bool $withHidden = false): Collection
    {
        $zones = DeliveryZone::whereNotNull('geo_area_id')->get(['id', 'geo_area_id']);
        $zoneOfGeo = $zones->keyBy('geo_area_id');
        $own = DeliveryZonePlace::all();
        $overrides = $own->whereNotNull('geo_area_place_id')->keyBy('geo_area_place_id');

        $rows = [];
        $platform = GeoAreaPlace::whereIn('geo_area_id', $zones->pluck('geo_area_id')->all())->where('status', 'active')->get();
        foreach ($platform as $p) {
            $home = $zoneOfGeo->get($p->geo_area_id)?->id;
            $o = $overrides->get($p->id);
            $status = $o ? $o->status : 'active';
            if (! $home || (! $withHidden && $status !== 'active')) {
                continue;
            }
            $rows[] = [
                'origin' => 'platform', 'id' => $p->id, 'override_id' => $o?->id,
                'zone_id' => $o ? $o->delivery_zone_id : $home, 'home_zone_id' => $home,
                'name' => $p->name, 'name_key' => $p->name_key, 'lat' => $p->lat, 'lng' => $p->lng,
                'source' => $p->source, 'kind' => $p->kind, 'status' => $status, 'orders_count' => (int) ($o?->orders_count ?? 0),
                'osm_ref' => $p->osm_ref,
            ];
        }
        foreach ($own->whereNull('geo_area_place_id') as $q) {
            if (! $withHidden && $q->status !== 'active') {
                continue;
            }
            $rows[] = [
                'origin' => 'restaurant', 'id' => $q->id, 'override_id' => null,
                'zone_id' => $q->delivery_zone_id, 'home_zone_id' => null,
                'name' => $q->name, 'name_key' => $q->name_key, 'lat' => $q->lat, 'lng' => $q->lng,
                'source' => $q->source, 'kind' => $q->kind, 'status' => $q->status, 'orders_count' => $q->orders_count,
                'osm_ref' => null,
            ];
        }

        return collect($rows);
    }

    /** Platform ki jagah is restaurant ke liye: doosre area me, ya chhupi / wapas. */
    public function overridePlatform(GeoAreaPlace $p, int $zoneId, string $status, ?int $userId = null): void
    {
        $home = DeliveryZone::where('geo_area_id', $p->geo_area_id)->value('id');
        $existing = DeliveryZonePlace::where('geo_area_place_id', $p->id)->first();
        // Wapas wohi area aur dikhti hui = koi override nahi.
        if ($status === 'active' && (int) $zoneId === (int) $home) {
            $existing?->delete();

            return;
        }
        DeliveryZonePlace::updateOrCreate(['geo_area_place_id' => $p->id], [
            'delivery_zone_id' => $zoneId, 'name' => $p->name, 'name_key' => $p->name_key, 'lat' => $p->lat, 'lng' => $p->lng,
            'source' => DeliveryZonePlace::SOURCE_OVERRIDE, 'kind' => $p->kind, 'status' => $status,
            'created_by' => $existing?->created_by ?? $userId,
        ]);
    }
}
