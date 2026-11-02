<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * PLACE-LEARNING-1 — one delivered website order's evidence: where its pin was, what OpenStreetMap calls that
 * place, and which delivery area the restaurant served it from — or why nothing was learned from it.
 * Learned places are recomputed from these rows.
 */
class DeliveryPlaceSighting extends Model
{
    protected $connection = 'tenant';

    protected $table = 'delivery_place_sightings';

    protected $fillable = ['sales_order_id', 'delivery_zone_id', 'name', 'name_key', 'lat', 'lng', 'accuracy_m', 'skipped'];

    protected function casts(): array
    {
        return ['sales_order_id' => 'integer', 'delivery_zone_id' => 'integer', 'lat' => 'float', 'lng' => 'float', 'accuracy_m' => 'integer'];
    }
}
