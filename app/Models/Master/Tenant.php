<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $connection = 'master';

    protected $fillable = [
        'tenant_code',
        'business_name',
        'owner_name',
        'owner_email',
        'currency_code',
        'status',
        'is_demo',
        'trial_ends_at',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'trial_ends_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function isDemo(): bool
    {
        return (bool) $this->is_demo;
    }

    public function domains()
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function database()
    {
        return $this->hasOne(TenantDatabase::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    public function invoices()
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function subscriptionPayments()
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function changeRequests()
    {
        return $this->hasMany(SubscriptionChangeRequest::class);
    }
}
