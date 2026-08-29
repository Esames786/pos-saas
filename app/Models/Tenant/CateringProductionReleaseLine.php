<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** CATERING-SLICE-3: one item on a production release (no pricing fields by design). */
class CateringProductionReleaseLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'catering_production_release_id',
        'product_id',
        'item_name',
        'item_name_ur',
        'quantity',
        'unit_code',
        'production_station',
        'instructions',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'sort_order' => 'integer',
        ];
    }

    public function release()
    {
        return $this->belongsTo(CateringProductionRelease::class, 'catering_production_release_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
