<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class RestaurantTable extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'restaurant_floor_id',
        'table_no',
        'name',
        'capacity',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'capacity'   => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function floor()
    {
        return $this->belongsTo(RestaurantFloor::class, 'restaurant_floor_id');
    }

    public function sessions()
    {
        return $this->hasMany(RestaurantTableSession::class);
    }

    public function openSession()
    {
        return $this->hasOne(RestaurantTableSession::class)
            ->whereIn('status', ['open', 'bill_requested'])
            ->latestOfMany();
    }
}
