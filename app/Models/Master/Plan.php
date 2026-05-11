<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'code',
        'name',
        'price',
        'currency_code',
        'billing_period',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function features()
    {
        return $this->hasMany(PlanFeature::class);
    }
}
