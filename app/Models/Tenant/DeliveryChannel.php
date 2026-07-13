<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class DeliveryChannel extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'name',
        'type',
        'commission_percent',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            'is_active'          => 'boolean',
        ];
    }

    public function sales()
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function isOwn(): bool
    {
        return $this->type === 'own';
    }
}
