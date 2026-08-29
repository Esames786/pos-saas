<?php

namespace App\Models\Tenant;

use App\Models\Concerns\HasCanonicalIdentity;
use Illuminate\Database\Eloquent\Model;

class RestaurantTableSession extends Model
{
    use HasCanonicalIdentity;

    protected $connection = 'tenant';

    /**
     * EDGE-IDENTITY-1: canonical cross-system identity of the table session (immutable; not mass-assignable).
     * Distinct from `session_no`, which is a locally time/random-generated human display label.
     */
    protected string $canonicalIdentityColumn = 'session_uuid';

    protected $fillable = [
        'session_no',
        'branch_id',
        'restaurant_table_id',
        'restaurant_waiter_id',
        'customer_id',
        'customer_name',
        'customer_phone',
        'opened_by_user_id',
        'closed_by_user_id',
        'opened_shift_id',
        'business_date',
        'guest_count',
        'status',
        'opened_at',
        'closed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'guest_count'   => 'integer',
            'business_date' => 'date:Y-m-d',
            'opened_at'     => 'datetime',
            'closed_at'     => 'datetime',
        ];
    }

    public function openedShift()
    {
        return $this->belongsTo(Shift::class, 'opened_shift_id');
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

    // TABLE-RESERVATION-2b: the customer carried over from the reservation, pre-attached to the
    // first POS order (walk-in reservations carry only customer_name/phone, no customer_id).
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
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
