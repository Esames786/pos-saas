<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class UnitConversion extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'from_unit_id',
        'to_unit_id',
        'factor',
    ];

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:8',
        ];
    }

    public function fromUnit()
    {
        return $this->belongsTo(Unit::class, 'from_unit_id');
    }

    public function toUnit()
    {
        return $this->belongsTo(Unit::class, 'to_unit_id');
    }
}
