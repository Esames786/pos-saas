<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'sale_no',
        'branch_id',
        'terminal_id',
        'shift_id',
        'customer_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'order_source',
        'order_type',
        'sale_date',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_amount',
        'grand_total',
        'paid_amount',
        'change_amount',
        'status',
        'inventory_posted',
        'created_by_user_id',
        'completed_at',
        'notes',
        'restaurant_floor_id',
        'restaurant_table_id',
        'restaurant_table_session_id',
        'restaurant_waiter_id',
    ];

    protected function casts(): array
    {
        return [
            'sale_date'        => 'datetime',
            'subtotal'         => 'decimal:2',
            'discount_value'   => 'decimal:4',
            'discount_amount'  => 'decimal:2',
            'tax_amount'       => 'decimal:2',
            'grand_total'      => 'decimal:2',
            'paid_amount'      => 'decimal:2',
            'change_amount'    => 'decimal:2',
            'inventory_posted' => 'boolean',
            'completed_at'     => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function terminal()
    {
        return $this->belongsTo(Terminal::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lines()
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(SalesLedger::class);
    }

    public function returns()
    {
        return $this->hasMany(SalesReturn::class);
    }

    public function restaurantFloor()
    {
        return $this->belongsTo(RestaurantFloor::class);
    }

    public function restaurantTable()
    {
        return $this->belongsTo(RestaurantTable::class);
    }

    public function restaurantTableSession()
    {
        return $this->belongsTo(RestaurantTableSession::class);
    }

    public function restaurantWaiter()
    {
        return $this->belongsTo(RestaurantWaiter::class);
    }
}
