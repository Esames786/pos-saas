<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** §7: one issued (or non-stock) material with its FEFO ledger references. */
class CateringMaterialIssueLine extends Model
{
    protected $connection = 'tenant';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_NON_STOCK = 'non_stock';

    protected $fillable = [
        'catering_material_issue_id',
        'product_id',
        'item_name',
        'required_qty',
        'issued_qty',
        'unit_code',
        'line_status',
        'stock_ledger_ids',
        'fefo_cost_total',
    ];

    protected function casts(): array
    {
        return [
            'required_qty' => 'decimal:3',
            'issued_qty' => 'decimal:3',
            'stock_ledger_ids' => 'array',
            'fefo_cost_total' => 'decimal:4',
        ];
    }

    public function issue()
    {
        return $this->belongsTo(CateringMaterialIssue::class, 'catering_material_issue_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
