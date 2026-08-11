<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SalesOrderRiderAssignment extends Model
{
    protected $connection = 'tenant';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function fromRider()
    {
        return $this->belongsTo(DeliveryRider::class, 'from_delivery_rider_id');
    }

    public function toRider()
    {
        return $this->belongsTo(DeliveryRider::class, 'to_delivery_rider_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
