<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONLINE-ORDERING-1 — restaurant ki jagah aur us ka delivery area (Online Ordering → Delivery Places):
 * admin ki likhi (manual), customers se seekhi (learned), ya platform ki jagah ka is restaurant wala faisla
 * (override: doosra area / chhupi).
 */
class DeliveryZonePlace extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_LEARNED = 'learned';

    public const SOURCE_OVERRIDE = 'override';

    protected $connection = 'tenant';

    protected $table = 'delivery_zone_places';

    protected $fillable = ['delivery_zone_id', 'geo_area_place_id', 'name', 'name_key', 'lat', 'lng', 'source', 'kind',
        'status', 'orders_count', 'last_order_at', 'created_by'];

    protected function casts(): array
    {
        return ['delivery_zone_id' => 'integer', 'geo_area_place_id' => 'integer', 'lat' => 'float', 'lng' => 'float',
            'orders_count' => 'integer', 'last_order_at' => 'datetime'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }
}
