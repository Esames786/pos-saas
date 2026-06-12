<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function planModules(): HasMany
    {
        return $this->hasMany(PlanModule::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'plan_modules')
            ->withPivot(['is_enabled', 'limits'])
            ->withTimestamps();
    }

    public function enabledModules(): BelongsToMany
    {
        return $this->modules()->wherePivot('is_enabled', true);
    }

    public function hasEnabledModuleKey(string $moduleKey): bool
    {
        return $this->enabledModules()
            ->where('modules.key', $moduleKey)
            ->exists();
    }

    public function hasEnabledRouteModuleKey(?string $routeModuleKey): bool
    {
        if (!$routeModuleKey) {
            return true;
        }

        return $this->enabledModules()
            ->whereJsonContains('modules.route_module_keys', $routeModuleKey)
            ->exists();
    }

    public function invoices()
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }
}
