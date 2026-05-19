<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CurrencyDenomination extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'currency_id',
        'denomination_value',
        'denomination_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'denomination_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
