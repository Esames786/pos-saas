<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * DELIVERY-RADIUS-1 — branch se itne km tak itni delivery fee. Aakhri band se aage delivery nahi.
 */
class DeliveryFeeBand extends Model
{
    protected $connection = 'tenant';

    protected $table = 'delivery_fee_bands';

    protected $fillable = ['branch_id', 'up_to_km', 'fee'];

    protected function casts(): array
    {
        return ['branch_id' => 'integer', 'up_to_km' => 'float', 'fee' => 'float'];
    }
}
