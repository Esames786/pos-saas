<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'transfer_no',
        'from_branch_id',
        'to_branch_id',
        'transfer_date',
        'status',
        'posted_by_user_id',
        'posted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'posted_at'     => 'datetime',
        ];
    }

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function lines()
    {
        return $this->hasMany(StockTransferLine::class);
    }

    public function ledgers()
    {
        return $this->hasMany(StockLedger::class, 'reference_id')
            ->where('reference_type', 'stock_transfer');
    }
}
