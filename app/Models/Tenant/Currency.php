<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimal_places',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function denominations()
    {
        return $this->hasMany(CurrencyDenomination::class);
    }
}
