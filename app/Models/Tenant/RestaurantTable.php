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
        // TABLE-RESERVATION-1
        'reserved_customer_id',
        'reserved_name',
        'reserved_phone',
        'reserved_for',
        'reservation_note',
        'reserved_by_user_id',
        'reserved_at',
    ];

    protected function casts(): array
    {
        return [
            'capacity'     => 'integer',
            'sort_order'   => 'integer',
            'reserved_for' => 'datetime',
            'reserved_at'  => 'datetime',
        ];
    }

    public function reservedCustomer()
    {
        return $this->belongsTo(Customer::class, 'reserved_customer_id');
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

    /**
     * ⚠️ Shart `ofMany()` ke ANDAR deni parti hai, `->whereIn(...)->latestOfMany()` se NAHI.
     *
     * Purani shakl ka andruni `MAX(id)` subquery upar wali `whereIn('status')` ko nahi ginta tha —
     * yani wo "sab se nayi KHULI session" nahi, "sab se nayi session" utha kar phir poochta tha ke
     * khuli hai ya nahi. Nateeja: ek khuli session ke OOPAR naye id wali BAND session pari ho to
     * board us table ko KHALI dikhata tha, halanke bill wahin mojood hota.
     *
     * 12 Sep 2026 ko live dekha: bachaya hua bill table 20 par bheja aur board ne table khali dikhai.
     * docs/plans/held-sale-dead-session-2026-09-12.md
     */
    public function openSession()
    {
        return $this->hasOne(RestaurantTableSession::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->whereIn('status', ['open', 'bill_requested'])
        );
    }
}
