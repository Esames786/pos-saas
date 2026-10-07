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
        // WHATSAPP-REPORT-CHANNEL-1: where the tenant's reports go when no branch overrides it.
        'report_channels',
        'report_whatsapp',
        'currency_code',
        // WEBSITE-I18N-GEO-1 P3: the owner's language (emails) and the business's timezone (first branch).
        'locale',
        'timezone',
        'status',
        'is_demo',
        'trial_ends_at',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            // Both are json columns holding lists; without these casts they come back as
            // raw strings and every read has to remember to decode — which is exactly the kind
            // of thing one caller forgets.
            'report_channels' => 'array',
            'report_whatsapp' => 'array',
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

    public function backupSetting()
    {
        return $this->hasOne(TenantBackupSetting::class);
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
