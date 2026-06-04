<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'name',
        'code',
        'promotion_type',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'min_order_amount',
        'order_types',
        'requires_code',
        'usage_limit',
        'used_count',
        'starts_at',
        'ends_at',
        'status',
        'priority',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_types'           => 'json',
            'discount_value'        => 'decimal:4',
            'max_discount_amount'   => 'decimal:2',
            'min_order_amount'      => 'decimal:2',
            'starts_at'             => 'datetime',
            'ends_at'               => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function targets()
    {
        return $this->hasMany(PromotionTarget::class);
    }
}
