<?php

namespace App\Http\Controllers\Tenant\OnlineOrdering;

use App\Http\Controllers\Controller;
use App\Models\Master\GeoArea;
use App\Models\Tenant\Branch;
use App\Models\Tenant\DeliveryFeeBand;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\WebBranchSetting;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\DeliveryFee;
use App\Services\OnlineOrdering\DeliveryPlaces;
use App\Services\OnlineOrdering\MapPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ONLINE-ORDERING-1 — Online Ordering → Delivery areas.
 *
 * Areas system ki master list se aate hain. Client sirf tick kare, branch chune (area-wise), fee,
 * minimum order, "itne se upar free" aur ETA likhe. Tick hataya area mitaya nahi jata, band hota
 * hai — dobara tick karne par purani fee wapas.
 *
 * Aik waqt me aik shehar: kal 2–3 shehar hon to bhi form chhota rahe, aur save sirf usi shehar ki
 * rows ko chhue.
 */
class DeliveryAreaController extends Controller
{
    public function index(Request $request)
    {
        $cities = GeoArea::where('is_active', true)->distinct()->orderBy('city')->pluck('city');
        $city = $cities->contains($request->query('city')) ? $request->query('city') : $cities->first();

        $settings = WebSetting::tenantDefault();
        $zones = DeliveryZone::all();
        $fees = app(DeliveryFee::class);
        // Doori se fee: har area ke markaz ki fee (andaza — customer ki asal fee us ke pin se).
        $byDistance = DeliveryFee::mode($settings) === DeliveryFee::MODE_DISTANCE
            ? $zones->mapWithKeys(fn ($z) => [$z->id => $fees->quote($z, null, null, DeliveryFee::MODE_DISTANCE)]) : collect();

        return view('tenant.online-ordering.areas', [
            'settings' => $settings,
            'cities' => $cities,
            'city' => $city,
            'areas' => GeoArea::where('is_active', true)->where('city', $city)->orderBy('sort_order')->orderBy('name')->get(),
            'zones' => $zones->keyBy('geo_area_id'),
            'feeMode' => DeliveryFee::mode($settings),
            'branchLocs' => WebBranchSetting::all()->keyBy('branch_id'),
            'bands' => DeliveryFeeBand::orderBy('up_to_km')->get()->groupBy('branch_id'),
            'byDistance' => $byDistance,
            // Har area ki jagahen (Delivery Places screen par) — yahan sirf ginti.
            'placeCounts' => app(DeliveryPlaces::class)->all()->countBy('zone_id'),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'city' => ['required', 'string'],
            'zones' => ['nullable', 'array'],
            'zones.*.serve' => ['nullable', 'boolean'],
            'zones.*.branch_id' => ['nullable', 'integer'],
            'zones.*.fee' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'zones.*.min_order' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'zones.*.free_delivery_above' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'zones.*.eta_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            // DELIVERY-RADIUS-1: fee kaise bane + branch ka nuqta + doori ke bands (form me hon tab hi chhute).
            'fee_mode' => ['nullable', 'in:'.DeliveryFee::MODE_AREA.','.DeliveryFee::MODE_DISTANCE],
            'branches' => ['nullable', 'array'],
            'branches.*.location' => ['nullable', 'string', 'max:500'],
            'branches.*.map_url' => ['nullable', 'url', 'max:500'],
            'bands' => ['nullable', 'array'],
            'bands.*' => ['array'],
            'bands.*.*.up_to_km' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'bands.*.*.fee' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], [], ['branches.*.map_url' => 'directions link', 'bands.*.*.up_to_km' => 'km', 'bands.*.*.fee' => 'fee']);

        $settings = WebSetting::tenantDefault();
        $branchIds = Branch::where('status', 'active')->pluck('id')->all();
        $singleBranch = $settings->branch_mode === 'single' ? (int) $settings->default_branch_id : null;

        if ($settings->branch_mode === 'single' && ! in_array($singleBranch, $branchIds, true)) {
            throw ValidationException::withMessages([
                'zones' => 'Choose the website\'s branch in Website settings first.',
            ]);
        }

        // Sirf master ke usi shehar ke areas; koi gharha hua id nahi.
        $areas = GeoArea::where('city', $data['city'])
            ->whereIn('id', array_map('intval', array_keys($data['zones'] ?? [])))
            ->get()->keyBy('id');

        $served = 0;
        DB::connection('tenant')->transaction(function () use ($data, $request, $settings, $areas, $branchIds, $singleBranch, &$served) {
            $this->saveFees($request, $data, $settings, $branchIds);
            foreach ($data['zones'] ?? [] as $areaId => $row) {
                $area = $areas->get((int) $areaId);
                if (! $area) {
                    continue;
                }

                $zone = DeliveryZone::where('geo_area_id', $area->id)->first();

                if (empty($row['serve'])) {
                    // Band, mitaya nahi: fee yaad rahe.
                    $zone?->update(['is_active' => false]);

                    continue;
                }

                $branchId = $singleBranch ?? (int) ($row['branch_id'] ?? 0);
                if (! in_array($branchId, $branchIds, true)) {
                    throw ValidationException::withMessages([
                        "zones.$areaId.branch_id" => "{$area->name}: choose the branch that delivers here.",
                    ]);
                }

                DeliveryZone::updateOrCreate(['geo_area_id' => $area->id], [
                    'area_name' => $area->name,
                    'city' => $area->city,
                    'branch_id' => $branchId,
                    'fee' => (float) ($row['fee'] ?? 0),
                    'min_order' => $this->money($row['min_order'] ?? null),
                    'free_delivery_above' => $this->money($row['free_delivery_above'] ?? null),
                    'eta_minutes' => isset($row['eta_minutes']) && $row['eta_minutes'] !== '' ? (int) $row['eta_minutes'] : null,
                    'is_active' => true,
                ]);
                $served++;
            }
        });

        // url(), route() nahi: tenant routes par {subdomain} hai.
        return redirect('/online-ordering/areas?city='.urlencode($data['city']))
            ->with('status', "Delivery areas saved — {$served} areas in {$data['city']} are served.");
    }

    /** Fee ka tareeqa, branch ka nuqta / directions link aur bands — sirf jo form me aaye (purana form kuch nahi mitata). */
    private function saveFees(Request $request, array $data, WebSetting $settings, array $branchIds): void
    {
        foreach ($data['branches'] ?? [] as $branchId => $row) {
            if (! in_array((int) $branchId, $branchIds, true)) {
                continue;
            }
            [$lat, $lng] = MapPoint::parse($row['location'] ?? null);
            if ($lat === null && trim((string) ($row['location'] ?? '')) !== '') {
                throw ValidationException::withMessages(["branches.$branchId.location" => 'Branch location: paste the numbers (e.g. 24.9230, 67.0893) or a Google Maps link that shows them.']);
            }
            WebBranchSetting::updateOrCreate(['branch_id' => (int) $branchId], [
                'lat' => $lat, 'lng' => $lng, 'map_url' => trim((string) ($row['map_url'] ?? '')) ?: null,
            ]);
        }

        foreach ($data['bands'] ?? [] as $branchId => $rows) {
            if (! in_array((int) $branchId, $branchIds, true)) {
                continue;
            }
            $clean = [];
            foreach ($rows as $r) {
                $km = $r['up_to_km'] ?? null;
                $fee = $r['fee'] ?? null;
                if (($km === null || $km === '') && ($fee === null || $fee === '')) {
                    continue;   // khaali row
                }
                if ($km === null || $km === '' || $fee === null || $fee === '') {
                    throw ValidationException::withMessages(["bands.$branchId" => 'Each distance band needs both the km and the fee.']);
                }
                $key = number_format((float) $km, 2, '.', '');
                if (isset($clean[$key])) {
                    throw ValidationException::withMessages(["bands.$branchId" => "Two bands end at {$km} km — keep one."]);
                }
                $clean[$key] = (float) $fee;
            }
            DeliveryFeeBand::where('branch_id', (int) $branchId)->delete();
            foreach ($clean as $km => $fee) {
                DeliveryFeeBand::create(['branch_id' => (int) $branchId, 'up_to_km' => (float) $km, 'fee' => $fee]);
            }
        }

        if (! $request->has('fee_mode')) {
            return;
        }
        if ($data['fee_mode'] === DeliveryFee::MODE_DISTANCE) {
            // Har branch jo areas serve karti hai: nuqta aur kam se kam aik band — warna doori kis se ginein.
            foreach (Branch::whereIn('id', $branchIds)->get(['id', 'name']) as $b) {
                if (! DeliveryZone::where('branch_id', $b->id)->where('is_active', true)->exists()) {
                    continue;
                }
                if (! WebBranchSetting::where('branch_id', $b->id)->whereNotNull('lat')->exists() || ! DeliveryFeeBand::where('branch_id', $b->id)->exists()) {
                    throw ValidationException::withMessages(['fee_mode' => "{$b->name}: add the branch location and at least one distance band before charging by distance."]);
                }
            }
        }
        $settings->update(['delivery_fee_mode' => $data['fee_mode']]);
    }

    private function money(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
