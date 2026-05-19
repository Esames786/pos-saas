<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CashCountLine extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'source_type',
        'source_id',
        'currency_denomination_id',
        'quantity',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function denomination()
    {
        return $this->belongsTo(CurrencyDenomination::class, 'currency_denomination_id');
    }
}
