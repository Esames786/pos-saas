<?php

namespace App\Services\OnlineOrdering;

use App\Models\Master\GeoArea;
use App\Models\Tenant\DeliveryFeeBand;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\WebBranchSetting;
use App\Models\Tenant\WebSetting;
use Illuminate\Support\Collection;

/**
 * DELIVERY-RADIUS-1 — website ki delivery fee, AIK jagah se (checkout yahi chalata hai; website wahi hisaab
 * browser me dikhane ke liye `payload()` se leti hai — faisla hamesha server ka).
 *
 *   mode area     = area ki apni fee (pehle jaisa).
 *   mode distance = branch ke nuqte se seedhi doori (km) → band ki fee. Customer ka pin ho to pin se, warna area
 *                   ke markaz se. Aakhri band se door = delivery nahi (malik: "Karachi se bahar delivery nahi").
 *                   Branch ka nuqta ya bands na hon to area ki fee — kabhi muft nahi.
 * Minimum order aur "itne se upar free" har haal me area ke.
 */
class DeliveryFee
{
    public const MODE_AREA = 'area';

    public const MODE_DISTANCE = 'distance';

    /** @var array<int, array{loc: ?WebBranchSetting, bands: Collection<int, DeliveryFeeBand>}> */
    private array $branches = [];

    public static function mode(?WebSetting $settings = null): string
    {
        return ($settings ?? WebSetting::tenantDefault())->delivery_fee_mode === self::MODE_DISTANCE ? self::MODE_DISTANCE : self::MODE_AREA;
    }

    /**
     * @return array{ok: bool, fee: float, km: ?float, from: string, max_km: ?float}
     *   from: area (area ki fee) | pin (customer ka nuqta) | centre (area ka markaz)
     */
    public function quote(DeliveryZone $zone, ?float $lat = null, ?float $lng = null, ?string $mode = null): array
    {
        $areaFee = ['ok' => true, 'fee' => (float) $zone->fee, 'km' => null, 'from' => 'area', 'max_km' => null];
        if (($mode ?? self::mode()) !== self::MODE_DISTANCE) {
            return $areaFee;
        }

        ['loc' => $loc, 'bands' => $bands] = $this->branch((int) $zone->branch_id);
        if (! $loc?->hasPoint() || $bands->isEmpty()) {
            return $areaFee;
        }

        $from = 'pin';
        if ($lat === null || $lng === null) {
            $geo = $zone->geo_area_id ? GeoArea::find($zone->geo_area_id, ['id', 'lat', 'lng']) : null;
            if ($geo?->lat === null || $geo?->lng === null) {
                return $areaFee;
            }
            [$lat, $lng, $from] = [(float) $geo->lat, (float) $geo->lng, 'centre'];
        }

        $km = AreaLocator::km((float) $loc->lat, (float) $loc->lng, $lat, $lng);
        $band = $bands->first(fn (DeliveryFeeBand $b) => $km <= $b->up_to_km);

        return [
            'ok' => $band !== null,
            'fee' => $band ? $band->fee : 0.0,
            'km' => round($km, 1),
            'from' => $from,
            'max_km' => $bands->last()->up_to_km,
        ];
    }

    /**
     * Website ke liye: har branch ka nuqta aur bands (browser wahi hisaab dikhaye jo checkout lagaye ga).
     *
     * @param  iterable<int>  $branchIds
     * @return array{mode: string, branches: array<int, array{lat: float, lng: float, bands: list<array{0: float, 1: float}>}>}
     */
    public function payload(iterable $branchIds, ?WebSetting $settings = null): array
    {
        $out = [];
        foreach ($branchIds as $id) {
            ['loc' => $loc, 'bands' => $bands] = $this->branch((int) $id);
            if ($loc?->hasPoint() && $bands->isNotEmpty()) {
                $out[(int) $id] = ['lat' => (float) $loc->lat, 'lng' => (float) $loc->lng,
                    'bands' => $bands->map(fn (DeliveryFeeBand $b) => [$b->up_to_km, $b->fee])->values()->all()];
            }
        }

        return ['mode' => self::mode($settings), 'branches' => $out];
    }

    /** @return array{loc: ?WebBranchSetting, bands: Collection<int, DeliveryFeeBand>} */
    private function branch(int $branchId): array
    {
        return $this->branches[$branchId] ??= [
            'loc' => WebBranchSetting::where('branch_id', $branchId)->first(),
            'bands' => DeliveryFeeBand::where('branch_id', $branchId)->orderBy('up_to_km')->get(),
        ];
    }
}
