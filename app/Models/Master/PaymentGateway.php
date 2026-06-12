<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class PaymentGateway extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'code',
        'name',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function subscriptionPayments()
    {
        return $this->hasMany(SubscriptionPayment::class);
    }
}
