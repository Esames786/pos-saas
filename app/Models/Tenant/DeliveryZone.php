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
        'free_delivery_above', 'eta_minutes', 'is_active',
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
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
