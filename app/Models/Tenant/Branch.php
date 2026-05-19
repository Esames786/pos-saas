<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'code',
        'name',
        'business_type',
        'address',
        'phone',
        'email',
        'timezone',
        'tax_registration_no',
        'is_tax_enabled',
        'show_tax_number_on_invoice',
        'receipt_footer',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_tax_enabled' => 'boolean',
            'show_tax_number_on_invoice' => 'boolean',
        ];
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'branch_user')->withTimestamps();
    }

    public function terminals()
    {
        return $this->hasMany(Terminal::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function dailyClosings()
    {
        return $this->hasMany(DailyClosing::class);
    }

    public function stockBalances()
    {
        return $this->hasMany(StockBalance::class);
    }

    public function stockLedgers()
    {
        return $this->hasMany(StockLedger::class);
    }

    public function inventoryBatches()
    {
        return $this->hasMany(InventoryBatch::class);
    }

    public function salesOrders()
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function salesLedgers()
    {
        return $this->hasMany(SalesLedger::class);
    }

    public function restaurantFloors()
    {
        return $this->hasMany(RestaurantFloor::class);
    }

    public function restaurantTables()
    {
        return $this->hasMany(RestaurantTable::class);
    }

    public function restaurantWaiters()
    {
        return $this->hasMany(RestaurantWaiter::class);
    }

    public function restaurantTableSessions()
    {
        return $this->hasMany(RestaurantTableSession::class);
    }
}
