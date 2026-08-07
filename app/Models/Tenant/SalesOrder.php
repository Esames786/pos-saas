<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use App\Support\Edge\EdgeIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SalesOrder extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    /**
     * EDGE-IDENTITY-1: `sale_uuid` (ULID) is the canonical cross-system identity of the SALE itself —
     * immutable, generated once, stable across the held->pay update (same row). It is DISTINCT from
     * `client_uuid`, which is a per-write idempotency token (may change on held->pay, or differ per split
     * child). The sale_uuid column is intentionally not in $fillable so a request cannot supply it.
     */
    protected string $canonicalIdentityColumn = 'sale_uuid';

    protected string $canonicalIdentityFormat = EdgeIdentity::FORMAT_ULID;

    /**
     * SALE-IDEMPOTENCY-1: every sale carries a client_uuid. Cloud POS/manual
     * sales that don't supply one get a server-generated uuid so the column is
     * always populated (backward compatible); offline Edge sales supply theirs.
     */
    protected static function booted(): void
    {
        static::creating(function (SalesOrder $sale) {
            if (empty($sale->client_uuid)) {
                $sale->client_uuid = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'sale_no',
        'client_uuid',
        'client_payload_hash',
        'direct_pay_print_state',
        'direct_pay_print_orchestrated_at',
        'branch_id',
        'terminal_id',
        'shift_id',
        'customer_id',
        'promotion_id',
        'promo_code',
        'customer_name',
        'customer_phone',
        'customer_email',
        'order_source',
        'order_type',
        'sale_date',
        'business_date',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_amount',
        'service_charge_amount',
        'tip_amount',
        'grand_total',
        'paid_amount',
        'balance_due',
        'payment_status',
        'due_date',
        'change_amount',
        'manager_approval_id',
        'status',
        'inventory_posted',
        'created_by_user_id',
        'completed_at',
        'notes',
        'restaurant_floor_id',
        'restaurant_table_id',
        'restaurant_table_session_id',
        'restaurant_waiter_id',
        'delivery_channel_id',
        'delivery_rider_id',
        'delivery_address',
    ];

    protected function casts(): array
    {
        return [
            'sale_date'             => 'datetime',
            'business_date'         => 'date:Y-m-d',
            'subtotal'              => 'decimal:2',
            'discount_value'        => 'decimal:4',
            'discount_amount'       => 'decimal:2',
            'tax_amount'            => 'decimal:2',
            'service_charge_amount' => 'decimal:2',
            'tip_amount'            => 'decimal:2',
            'grand_total'           => 'decimal:2',
            'paid_amount'           => 'decimal:2',
            'balance_due'           => 'decimal:4',
            'due_date'              => 'date',
            'change_amount'         => 'decimal:2',
            'inventory_posted'      => 'boolean',
            'completed_at'          => 'datetime',
            'direct_pay_print_state' => 'array',
            'direct_pay_print_orchestrated_at' => 'datetime',
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

    public function kotBatches()
    {
        return $this->hasMany(KotBatch::class);
    }

    public function lineCancellations()
    {
        return $this->hasMany(SalesOrderLineCancellation::class);
    }

    public function customerPayments()
    {
        return $this->hasMany(CustomerPayment::class);
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

    public function deliveryChannel()
    {
        return $this->belongsTo(DeliveryChannel::class);
    }

    public function deliveryRider()
    {
        return $this->belongsTo(DeliveryRider::class);
    }
}
