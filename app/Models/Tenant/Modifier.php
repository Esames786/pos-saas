<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Modifier extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'modifier_group_id',
        'name',
        'price_delta',
        'linked_product_id',
        'consume_stock',
        'linked_quantity',
        'linked_unit_id',
        'is_default',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price_delta'     => 'decimal:2',
            'is_default'      => 'boolean',
            'sort_order'      => 'integer',
            'consume_stock'   => 'boolean',
            'linked_quantity' => 'decimal:4',
        ];
    }

    public function group()
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    public function linkedProduct()
    {
        return $this->belongsTo(Product::class, 'linked_product_id');
    }

    public function linkedUnit()
    {
        return $this->belongsTo(Unit::class, 'linked_unit_id');
    }

    /** MODIFIER-INVENTORY-1: does selecting this option deduct linked stock on sale? */
    public function consumesStock(): bool
    {
        return (bool) $this->consume_stock && $this->linked_product_id !== null;
    }

    public function stockConsumptionLabel(): string
    {
        if (! $this->consumesStock()) {
            return 'Price-only (no stock)';
        }

        $qty  = rtrim(rtrim(number_format((float) $this->linked_quantity, 4), '0'), '.');
        $unit = $this->linkedUnit?->code ?? $this->linkedProduct?->unit?->code ?? '';

        return trim("Deducts {$qty} {$unit} " . ($this->linkedProduct?->name ?? ''));
    }
}
