<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** CATERING-SLICE-2: frozen costing run for an estimate version (audit basis). */
class CateringCostSnapshot extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'catering_estimate_id',
        'breakdown',
        'total_material_cost',
        'computed_at',
        'computed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'breakdown' => 'array',
            'total_material_cost' => 'decimal:2',
            'computed_at' => 'datetime',
        ];
    }

    public function estimate()
    {
        return $this->belongsTo(CateringEstimate::class, 'catering_estimate_id');
    }
}
