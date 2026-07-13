<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class DeliveryRider extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'name',
        'phone',
        'status',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function sales()
    {
        return $this->hasMany(SalesOrder::class);
    }
}
