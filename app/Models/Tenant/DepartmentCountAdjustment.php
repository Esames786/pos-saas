<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * DEPT-4 — audit link between an approved count line and the custody
 * adjustment ledger row it produced.
 */
class DepartmentCountAdjustment extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'department_count_session_id',
        'department_count_line_id',
        'department_stock_ledger_id',
        'direction',
        'quantity',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity'  => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function session()
    {
        return $this->belongsTo(DepartmentCountSession::class, 'department_count_session_id');
    }

    public function line()
    {
        return $this->belongsTo(DepartmentCountLine::class, 'department_count_line_id');
    }

    public function ledger()
    {
        return $this->belongsTo(DepartmentStockLedger::class, 'department_stock_ledger_id');
    }
}
