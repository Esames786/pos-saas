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
        'allow_negative_stock',
        'default_delivery_charge',
        'delivery_charge_locked',
        'held_kot_cancellation_approval_mode',
        'held_kot_line_cancellation_approval_mode',
        'manual_discount_approval_mode',
        'sales_return_approval_mode',
        'receipt_footer',
        'status',
        'sales_operating_mode',
        'local_edge_status',
        'local_edge_activated_at',
        'local_edge_suspended_at',
        'local_edge_status_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_tax_enabled' => 'boolean',
            'show_tax_number_on_invoice' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'local_edge_activated_at' => 'datetime',
            'local_edge_suspended_at' => 'datetime',
        ];
    }

    // BRANCH-OPERATING-MODE-1/HARDEN-1: Local POS (Branch Edge) lifecycle vocabulary.
    public const MODE_CLOUD      = 'cloud';
    public const MODE_LOCAL_EDGE = 'local_edge';

    public const STATUS_INACTIVE  = 'inactive';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_CLOSING   = 'closing';
    public const STATUS_SUSPENDED = 'suspended';

    public const KOT_CANCELLATION_MANAGER_REQUIRED = 'manager_required';
    public const KOT_CANCELLATION_AUTO_APPROVE = 'auto_approve';
    public const MANUAL_DISCOUNT_MANAGER_REQUIRED = 'manager_required';
    public const MANUAL_DISCOUNT_AUTO_APPROVE = 'auto_approve';

    /** RETURN-MANAGER-APPROVAL-1: default is AUTO — see the migration for why. */
    public const SALES_RETURN_MANAGER_REQUIRED = 'manager_required';
    public const SALES_RETURN_AUTO_APPROVE = 'auto_approve';

    /** This branch runs all sales on a Branch Server (server is the live authority). */
    public function isLocalEdgeActive(): bool
    {
        return $this->sales_operating_mode === self::MODE_LOCAL_EDGE
            && in_array($this->local_edge_status, [self::STATUS_ACTIVE, self::STATUS_CLOSING], true);
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
