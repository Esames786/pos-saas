<?php

namespace App\Services\Saas;

use App\Models\Master\Module;
use App\Models\Master\RouteCatalog;
use App\Models\Master\Tenant;
use Illuminate\Support\Carbon;

class TenantSubscriptionAccessService
{
    private const ALWAYS_ALLOWED_MODULE_KEYS = [
        'tenant.login',
        'tenant.logout',
        'tenant.password',
        'tenant.locale',
        'tenant.api',
        'central.dashboard',
        'central.login',
        'central.logout',
        'central.password',
        'central.locale',
        'central.routes',
        'central.tenants',
        'central.tenant-domains',
        'storage.local',
    ];

    public function check(Tenant $tenant, ?string $routeName): array
    {
        $subscription = $tenant->subscription?->loadMissing(['plan.enabledModules']);

        if (!$subscription) {
            return [
                'allowed'    => false,
                'reason'     => 'no_subscription',
                'message'    => 'No active subscription found for this tenant.',
                'module_key' => null,
                'module'     => null,
            ];
        }

        if (!$this->subscriptionIsUsable($subscription)) {
            return [
                'allowed'    => false,
                'reason'     => 'subscription_' . $subscription->status,
                'message'    => 'Your subscription is not active. Please contact support.',
                'module_key' => null,
                'module'     => null,
            ];
        }

        $routeCatalog = $routeName
            ? RouteCatalog::where('route_name', $routeName)->first()
            : null;

        $routeModuleKey = $routeCatalog?->module_key;

        if ($this->isAlwaysAllowed($routeModuleKey, $routeName)) {
            return [
                'allowed'    => true,
                'reason'     => 'always_allowed',
                'message'    => null,
                'module_key' => $routeModuleKey,
                'module'     => null,
            ];
        }

        if (!$routeModuleKey) {
            return [
                'allowed'    => true,
                'reason'     => 'no_module_key',
                'message'    => null,
                'module_key' => null,
                'module'     => null,
            ];
        }

        $module = Module::forRouteModuleKey($routeModuleKey)->first();

        // Important 14A-2B safety decision:
        // Unmapped routes are allowed for now, so one missing mapping does not break the tenant app.
        if (!$module) {
            return [
                'allowed'    => true,
                'reason'     => 'unmapped_route_module_key',
                'message'    => null,
                'module_key' => $routeModuleKey,
                'module'     => null,
            ];
        }

        $plan = $subscription->plan;

        if (!$plan || !$plan->hasEnabledModuleKey($module->key)) {
            return [
                'allowed'    => false,
                'reason'     => 'module_disabled',
                'message'    => 'Your current plan does not include the ' . $module->name . ' module.',
                'module_key' => $routeModuleKey,
                'module'     => $module,
            ];
        }

        return [
            'allowed'    => true,
            'reason'     => 'module_enabled',
            'message'    => null,
            'module_key' => $routeModuleKey,
            'module'     => $module,
        ];
    }

    public function subscriptionStatus(Tenant $tenant): array
    {
        $subscription = $tenant->subscription?->loadMissing(['plan']);

        if (!$subscription) {
            return [
                'state'    => 'missing',
                'message'  => 'No subscription is attached to this tenant.',
                'severity' => 'danger',
            ];
        }

        if ($subscription->status === 'trial') {
            $trialEndsAt = $subscription->trial_ends_at ? Carbon::parse($subscription->trial_ends_at) : null;

            if ($trialEndsAt && $trialEndsAt->isPast()) {
                return [
                    'state'    => 'trial_expired',
                    'message'  => 'Your trial has expired. Please upgrade your subscription.',
                    'severity' => 'danger',
                ];
            }

            return [
                'state'    => 'trial',
                'message'  => $trialEndsAt
                    ? 'Trial plan: ' . ($subscription->plan?->name ?? 'Plan') . ' — ends ' . $trialEndsAt->format('Y-m-d')
                    : 'Trial plan: ' . ($subscription->plan?->name ?? 'Plan'),
                'severity' => 'warning',
            ];
        }

        if ($subscription->status === 'past_due') {
            return [
                'state'    => 'past_due',
                'message'  => 'Your subscription payment is past due. Please update billing.',
                'severity' => 'danger',
            ];
        }

        if ($subscription->status === 'cancelled') {
            return [
                'state'    => 'cancelled',
                'message'  => 'Your subscription is cancelled. Please contact support.',
                'severity' => 'danger',
            ];
        }

        return [
            'state'    => 'active',
            'message'  => null,
            'severity' => 'success',
        ];
    }

    private function subscriptionIsUsable($subscription): bool
    {
        if ($subscription->status === 'active') {
            return true;
        }

        if ($subscription->status === 'trial') {
            return !$subscription->trial_ends_at || Carbon::parse($subscription->trial_ends_at)->isFuture();
        }

        return false;
    }

    private function isAlwaysAllowed(?string $routeModuleKey, ?string $routeName): bool
    {
        if ($routeModuleKey && in_array($routeModuleKey, self::ALWAYS_ALLOWED_MODULE_KEYS, true)) {
            return true;
        }

        if ($routeName && (
            str_starts_with($routeName, 'tenant.api.')
            || str_starts_with($routeName, 'tenant.login')
            || str_starts_with($routeName, 'tenant.logout')
            || str_starts_with($routeName, 'tenant.password')
            || str_starts_with($routeName, 'tenant.locale')
            || str_starts_with($routeName, 'central.')
        )) {
            return true;
        }

        return false;
    }
}
