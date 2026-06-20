<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ManufacturingBom extends Model
{
    protected $connection = 'tenant';

    public const STATUSES = ['draft', 'active', 'inactive', 'archived'];

    public const STATUS_COLORS = [
        'draft'    => 'secondary',
        'active'   => 'success',
        'inactive' => 'warning',
        'archived' => 'dark',
    ];

    protected $fillable = [
        'bom_no',
        'finished_product_id',
        'name',
        'version',
        'output_quantity',
        'status',
        'effective_from',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'output_quantity' => 'decimal:4',
            'effective_from'  => 'date',
        ];
    }

    public function finishedProduct()
    {
        return $this->belongsTo(Product::class, 'finished_product_id');
    }

    public function lines()
    {
        return $this->hasMany(ManufacturingBomLine::class, 'manufacturing_bom_id')
                    ->orderBy('sort_order');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
