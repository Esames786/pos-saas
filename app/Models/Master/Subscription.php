<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'trial_ends_at',
        'current_period_ends_at',
        'gateway_code',
        'gateway_customer_id',
        'gateway_subscription_id',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
        ];
    }
}
