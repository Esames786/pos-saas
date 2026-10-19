<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONLINE-ORDERING-1 — delivery area ki master list (sab tenants ki aik). Sirf naam aur jagah;
 * fee/branch har tenant ki `delivery_zones` me.
 */
class GeoArea extends Model
{
    protected $connection = 'master';

    protected $table = 'geo_areas';

    protected $fillable = ['country', 'state', 'city', 'name', 'lat', 'lng', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean', 'lat' => 'float', 'lng' => 'float'];
    }

    /** Area ke andar ki jagahen (societies) — `geo_area_places`. Purana `aliases` json column sirf wapsi ke liye. */
    public function places(): HasMany
    {
        return $this->hasMany(GeoAreaPlace::class);
    }
}
