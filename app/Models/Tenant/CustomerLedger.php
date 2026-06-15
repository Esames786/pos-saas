<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLedger extends Model
{
    protected $connection = 'tenant';

    public const ENTRY_TYPES = ['opening_balance', 'sale', 'payment', 'return', 'adjustment'];

    public const DIRECTIONS = ['debit', 'credit'];

    protected $fillable = [
        'customer_id',
        'branch_id',
        'entry_date',
        'entry_type',
        'direction',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'reference_no',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date'    => 'date',
            'amount'        => 'decimal:4',
            'balance_after' => 'decimal:4',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
