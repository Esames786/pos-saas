<?php

namespace App\Http\Controllers\Tenant\OnlineOrdering;

use App\Http\Controllers\Controller;
use App\Models\Master\GeoArea;
use App\Models\Master\GeoAreaPlace;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\DeliveryZonePlace;
use App\Services\OnlineOrdering\AreaLocator;
use App\Services\OnlineOrdering\DeliveryPlaces;
use App\Services\OnlineOrdering\MapPoint;
use App\Services\OnlineOrdering\OpenStreetMap;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * ONLINE-ORDERING-1 — Online Ordering → Delivery Places (malik, 16 Oct: "location area se properly linked, asal table
 * me — aage customers ki madad se area aur sahi suggest ho").
 *
 * Har jagah (society / mohalla) aur us ka delivery area aik table me: map se (platform — sab restaurants ki),
 * restaurant ki apni likhi, aur aage customers ke Paid orders se seekhi. Platform ki jagah restaurant mita nahi
 * sakta — sirf apne liye chhupa ya doosre area me daal sakta hai (override).
 */
class DeliveryPlaceController extends Controller
{
    public const SOURCES = [
        'osm' => 'From map',
        'platform' => 'Bingoo list',
        'manual' => 'Added by you',
        'learned' => 'Learned from orders',
    ];

    public const KINDS = [
        'sure' => 'Sure',
        'nearby' => 'Nearby guess',
    ];

    public function index(Request $request, DeliveryPlaces $places)
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'zone' => ['nullable', 'integer'],
            'source' => ['nullable', 'in:'.implode(',', array_keys(self::SOURCES))],
            'kind' => ['nullable', 'in:'.implode(',', array_keys(self::KINDS))],
            'status' => ['nullable', 'in:active,hidden,suggested'],
        ]);
        $zones = DeliveryZone::orderBy('area_name')->get(['id', 'area_name', 'is_active']);
        $all = $places->all(true);

        $status = $f['status'] ?? 'active';
        $rows = $all->where('status', $status)
            ->when($f['zone'] ?? null, fn ($c, $z) => $c->where('zone_id', (int) $z))
            ->when($f['source'] ?? null, fn ($c, $s) => $c->where('source', $s))
            ->when($f['kind'] ?? null, fn ($c, $k) => $c->where('kind', $k))
            ->when(trim((string) ($f['q'] ?? '')), fn ($c, $q) => $c->filter(fn ($r) => mb_stripos($r['name'], $q) !== false))
            ->sortBy(fn ($r) => mb_strtolower(($zones->firstWhere('id', $r['zone_id'])?->area_name ?? '').'|'.$r['name']))
            ->values();

        $page = max(1, (int) $request->query('page', 1));
        $paged = new LengthAwarePaginator($rows->forPage($page, 50)->values(), $rows->count(), 50, $page,
            ['path' => url('/online-ordering/places'), 'query' => $request->query()]);

        $active = $all->where('status', 'active');

        return view('tenant.online-ordering.places', [
            'rows' => $paged,
            'zones' => $zones,
            'zoneNames' => $zones->pluck('area_name', 'id'),
            'filters' => $f + ['status' => $status],
            'counts' => [
                'active' => $active->count(),
                'osm' => $active->where('source', 'osm')->count(),
                'platform' => $active->where('source', 'platform')->count(),
                'manual' => $active->where('source', 'manual')->count(),
                'learned' => $active->where('source', 'learned')->count(),
                'nearby' => $active->where('kind', 'nearby')->count(),
                'hidden' => $all->where('status', 'hidden')->count(),
                'suggested' => $all->where('status', 'suggested')->count(),
            ],
        ]);
    }

    public function store(Request $request, OpenStreetMap $osm)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'zone_id' => ['required', 'integer'],
            'location' => ['nullable', 'string', 'max:500'],
        ], [], ['zone_id' => 'area']);

        $zone = DeliveryZone::whereKey($data['zone_id'])->firstOrFail();
        $name = trim((string) preg_replace('/\s+/', ' ', $data['name']));
        $key = AreaLocator::norm($name);
        if ($key === '') {
            throw ValidationException::withMessages(['name' => 'Enter a place name.']);
        }

        // Aik naam aik hi area ka.
        if ($own = DeliveryZonePlace::where('name_key', $key)->first()) {
            throw ValidationException::withMessages(['name' => "“{$own->name}” is already in ".($own->zone?->area_name ?? 'another area').'. Move it there instead.']);
        }
        $geo = $zone->geo_area_id ? GeoArea::find($zone->geo_area_id) : null;
        $platform = $geo ? GeoAreaPlace::where('city', $geo->city)->where('name_key', $key)->where('status', 'active')->first() : null;
        if ($platform) {
            throw ValidationException::withMessages(['name' => "“{$platform->name}” is already on the map list. Find it below and use Move."]);
        }

        [$lat, $lng] = MapPoint::parse($data['location'] ?? null);
        if ($lat === null && $geo) {
            $hit = $osm->search($name.', '.$geo->city.', '.$geo->country, OpenStreetMap::countryCode($geo->country));
            if ($hit && ($geo->lat === null || AreaLocator::km((float) $geo->lat, (float) $geo->lng, $hit['lat'], $hit['lng']) <= AreaLocator::MAX_KM)) {
                [$lat, $lng] = [$hit['lat'], $hit['lng']];
            }
        }

        DeliveryZonePlace::create([
            'delivery_zone_id' => $zone->id, 'name' => $name, 'name_key' => $key, 'lat' => $lat, 'lng' => $lng,
            'source' => DeliveryZonePlace::SOURCE_MANUAL, 'kind' => 'sure', 'status' => 'active',
            'created_by' => auth('tenant')->id(),
        ]);

        return redirect('/online-ordering/places?zone='.$zone->id)->with('status', "Added “{$name}” to {$zone->area_name}."
            .($lat === null ? ' It was not found on the map, so it is matched by its name only.' : ''));
    }

    public function update(Request $request, string $origin, int $id, DeliveryPlaces $places)
    {
        $data = $request->validate([
            'action' => ['required', 'in:move,hide,show,approve'],
            'zone_id' => ['nullable', 'required_if:action,move', 'integer'],
        ], [], ['zone_id' => 'area']);
        $zoneId = isset($data['zone_id']) ? (int) DeliveryZone::whereKey($data['zone_id'])->firstOrFail()->id : null;

        if ($origin === 'platform') {
            $p = $this->platformPlace($id);
            $row = $places->all(true)->first(fn ($r) => $r['origin'] === 'platform' && $r['id'] === $p->id);
            match ($data['action']) {
                'move' => $places->overridePlatform($p, $zoneId, 'active', auth('tenant')->id()),
                'hide' => $places->overridePlatform($p, (int) $row['zone_id'], 'hidden', auth('tenant')->id()),
                default => $places->overridePlatform($p, (int) $row['home_zone_id'], 'active', auth('tenant')->id()),
            };
            $name = $p->name;
        } else {
            $q = DeliveryZonePlace::whereNull('geo_area_place_id')->findOrFail($id);
            $q->update(match ($data['action']) {
                'move' => ['delivery_zone_id' => $zoneId],
                'hide' => ['status' => 'hidden'],
                default => ['status' => 'active'],
            });
            $name = $q->name;
        }

        $done = ['move' => 'moved', 'hide' => 'hidden', 'show' => 'shown again', 'approve' => 'approved'][$data['action']];

        return back()->with('status', "“{$name}” {$done}.");
    }

    public function destroy(string $origin, int $id, DeliveryPlaces $places)
    {
        // Map ki jagah mitti nahi (sab restaurants ki hai) — is restaurant ke liye chhupti hai.
        if ($origin === 'platform') {
            $p = $this->platformPlace($id);
            $row = $places->all(true)->first(fn ($r) => $r['origin'] === 'platform' && $r['id'] === $p->id);
            $places->overridePlatform($p, (int) $row['zone_id'], 'hidden', auth('tenant')->id());

            return back()->with('status', "“{$p->name}” hidden for your restaurant.");
        }
        $q = DeliveryZonePlace::whereNull('geo_area_place_id')->findOrFail($id);
        $q->delete();

        return back()->with('status', "“{$q->name}” removed.");
    }

    /** Sirf is restaurant ke areas ki platform jagah. */
    private function platformPlace(int $id): GeoAreaPlace
    {
        $geoIds = DeliveryZone::whereNotNull('geo_area_id')->pluck('geo_area_id')->all();

        return GeoAreaPlace::whereIn('geo_area_id', $geoIds)->findOrFail($id);
    }
}
