<?php

namespace App\Http\Controllers\Tenant\OnlineOrdering;

use App\Http\Controllers\Controller;
use App\Models\Master\GeoArea;
use App\Models\Tenant\Branch;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\DeliveryPlaces;
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

        return view('tenant.online-ordering.areas', [
            'settings' => WebSetting::tenantDefault(),
            'cities' => $cities,
            'city' => $city,
            'areas' => GeoArea::where('is_active', true)->where('city', $city)->orderBy('sort_order')->orderBy('name')->get(),
            'zones' => DeliveryZone::all()->keyBy('geo_area_id'),
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
        ]);

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
        DB::connection('tenant')->transaction(function () use ($data, $areas, $branchIds, $singleBranch, &$served) {
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

    private function money(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
