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

    protected $fillable = ['country', 'state', 'city', 'name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
