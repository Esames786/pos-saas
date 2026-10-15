<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONLINE-ORDERING-1 — restaurant jo area serve karta hai: kis branch se, kitni fee, kitni der.
 * Area khud master `geo_areas` me hai; yahan us ka naam bhi likha hai taake tenant DB mukammal rahe.
 */
class DeliveryZone extends Model
{
    protected $connection = 'tenant';

    protected $table = 'delivery_zones';

    protected $fillable = [
        'geo_area_id', 'area_name', 'city', 'branch_id', 'fee', 'min_order',
        'free_delivery_above', 'eta_minutes', 'is_active', 'places',
    ];

    protected function casts(): array
    {
        return [
            'geo_area_id' => 'integer',
            'fee' => 'decimal:2',
            'min_order' => 'decimal:2',
            'free_delivery_above' => 'decimal:2',
            'eta_minutes' => 'integer',
            'is_active' => 'boolean',
            'places' => 'array',
        ];
    }

    /**
     * "Places inside this area" — restaurant ki likhi societies (naam + OSM ka nuqta, agar mila).
     *
     * @return list<array{name: string, lat: ?float, lng: ?float}>
     */
    public function placeList(): array
    {
        return array_values(array_filter(array_map(fn ($p) => is_array($p) && trim((string) ($p['name'] ?? '')) !== '' ? [
            'name' => trim((string) $p['name']),
            'lat' => isset($p['lat']) && $p['lat'] !== null ? (float) $p['lat'] : null,
            'lng' => isset($p['lng']) && $p['lng'] !== null ? (float) $p['lng'] : null,
        ] : null, (array) ($this->places ?? []))));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
