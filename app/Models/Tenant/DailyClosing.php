<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use App\Models\Tenant\Terminal;

class DailyClosing extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'terminal_id',
        'closing_date',
        'closed_by_user_id',
        'total_sales',
        'total_cash',
        'total_card',
        'total_bank_transfer',
        'total_cheque',
        'total_refunds',
        'total_cash_refunds',
        'total_discount',
        'total_tax',
        'expected_cash',
        'counted_cash',
        'cash_variance',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'closing_date' => 'date',
            'total_sales' => 'decimal:2',
            'total_cash' => 'decimal:2',
            'total_card' => 'decimal:2',
            'total_bank_transfer' => 'decimal:2',
            'total_cheque' => 'decimal:2',
            'total_refunds' => 'decimal:2',
            'total_cash_refunds' => 'decimal:2',
            'total_discount' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'cash_variance' => 'decimal:2',
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

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function cashCountLines()
    {
        return $this->hasMany(CashCountLine::class, 'source_id')
            ->where('source_type', 'daily_closing');
    }
}
