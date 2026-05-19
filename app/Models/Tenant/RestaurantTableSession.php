<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class RestaurantTableSession extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'session_no',
        'branch_id',
        'restaurant_table_id',
        'restaurant_waiter_id',
        'opened_by_user_id',
        'closed_by_user_id',
        'guest_count',
        'status',
        'opened_at',
        'closed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'guest_count' => 'integer',
            'opened_at'   => 'datetime',
            'closed_at'   => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function table()
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    public function waiter()
    {
        return $this->belongsTo(RestaurantWaiter::class, 'restaurant_waiter_id');
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function salesOrders()
    {
        return $this->hasMany(SalesOrder::class);
    }
}
