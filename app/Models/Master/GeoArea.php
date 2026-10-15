<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * ONLINE-ORDERING-1 — delivery area ki master list (sab tenants ki aik). Sirf naam aur jagah;
 * fee/branch har tenant ki `delivery_zones` me.
 */
class GeoArea extends Model
{
    protected $connection = 'master';

    protected $table = 'geo_areas';

    protected $fillable = ['country', 'state', 'city', 'name', 'lat', 'lng', 'aliases', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean', 'lat' => 'float', 'lng' => 'float', 'aliases' => 'array'];
    }

    /**
     * Area ke andar ki jagahen (bara area, jaise Scheme 33): naam pate se milte hain, nuqte qareebi area me gine jate hain.
     *
     * @return list<array{name: string, lat: ?float, lng: ?float}>
     */
    public function aliasList(): array
    {
        return array_values(array_filter(array_map(fn ($a) => is_array($a) && trim((string) ($a['name'] ?? '')) !== '' ? [
            'name' => trim((string) $a['name']),
            'lat' => isset($a['lat']) && $a['lat'] !== null ? (float) $a['lat'] : null,
            'lng' => isset($a['lng']) && $a['lng'] !== null ? (float) $a['lng'] : null,
        ] : null, (array) ($this->aliases ?? []))));
    }
}
