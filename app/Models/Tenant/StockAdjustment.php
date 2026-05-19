<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'adjustment_no',
        'branch_id',
        'adjustment_type',
        'adjustment_date',
        'status',
        'posted_by_user_id',
        'posted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date',
            'posted_at'       => 'datetime',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function lines()
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }

    public function ledgers()
    {
        return $this->hasMany(StockLedger::class, 'reference_id')
            ->where('reference_type', 'stock_adjustment');
    }
}
