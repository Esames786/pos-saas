<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ServiceChargeSetting extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'charge_type',
        'charge_value',
        'order_types',
        'is_taxable',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'charge_value' => 'decimal:4',
            'order_types'  => 'json',
            'is_taxable'   => 'boolean',
            'is_active'    => 'boolean',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
