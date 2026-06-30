<?php

namespace App\Services\Saas;

use App\Models\Master\Module;
use App\Models\Master\RouteCatalog;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use Illuminate\Support\Carbon;

class TenantSubscriptionAccessService
{
    private const ALWAYS_ALLOWED_MODULE_KEYS = [
        'tenant.login',
        'tenant.logout',
        'tenant.password',
        'tenant.locale',
        'tenant.api',
        'tenant.billing',
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
        // Resolve the route module key FIRST. Always-allowed routes
        // (login/logout/locale/api AND billing) must stay reachable even when
        // the subscription is missing/expired/past_due — otherwise a lapsed
        // tenant could never reach /billing to pay and would be deadlocked.
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
            // BUG-059 FIX: add a 3-day grace period after period end so tenants who
            // have already paid but whose admin hasn't verified the payment yet are
            // not immediately locked out. The daily expiry sweep (saas:subscriptions-expire)
            // is the authoritative mechanism that eventually flips to past_due.
            $graceDays = (int) config('saas.subscription_grace_days', 3);
            return ! $subscription->current_period_ends_at
                || \Illuminate\Support\Carbon::parse($subscription->current_period_ends_at)
                    ->addDays($graceDays)
                    ->isFuture();
        }

        if ($subscription->status === 'trial') {
            return ! $subscription->trial_ends_at
                || \Illuminate\Support\Carbon::parse($subscription->trial_ends_at)->isFuture();
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
            || str_starts_with($routeName, 'tenant.billing')
            || str_starts_with($routeName, 'central.')
        )) {
            return true;
        }

        return false;
    }

    public function featureLimit(Tenant $tenant, string $featureKey): ?int
    {
        $subscription = $tenant->subscription?->loadMissing(['plan.features']);

        if (!$subscription || !$subscription->plan) {
            // No usable subscription/plan: block creation safely (limit 0).
            return 0;
        }

        $feature = $subscription->plan->features
            ->firstWhere('feature_key', $featureKey);

        $value = $feature?->feature_value;

        // Missing/blank feature = unlimited (allow).
        if ($value === null || $value === '') {
            return null;
        }

        $limit = (int) $value;

        // Negative = treat as unlimited.
        if ($limit < 0) {
            return null;
        }

        return $limit;
    }

    public function currentTenantUsage(string $resourceKey): int
    {
        // BUG-057 FIX: only count active resources so inactive branches/users/terminals
        // don't block tenants from adding new ones under their plan limit.
        return match ($resourceKey) {
            'branches'  => Branch::where('status', 'active')->count(),
            'users'     => User::where('status', 'active')->count(),
            'terminals' => Terminal::where('status', 'active')->count(),
            default     => 0,
        };
    }

    public function checkLimit(Tenant $tenant, string $resourceKey): array
    {
        $featureKey = match ($resourceKey) {
            'branches'  => 'branch_limit',
            'users'     => 'user_limit',
            'terminals' => 'terminal_limit',
            default     => null,
        };

        if (!$featureKey) {
            return [
                'allowed'  => true,
                'limit'    => null,
                'used'     => 0,
                'resource' => $resourceKey,
                'message'  => null,
            ];
        }

        $limit = $this->featureLimit($tenant, $featureKey);

        // Unlimited (missing/negative feature) — allow.
        if ($limit === null) {
            return [
                'allowed'  => true,
                'limit'    => null,
                'used'     => $this->currentTenantUsage($resourceKey),
                'resource' => $resourceKey,
                'message'  => null,
            ];
        }

        $used = $this->currentTenantUsage($resourceKey);

        if ($used < $limit) {
            return [
                'allowed'  => true,
                'limit'    => $limit,
                'used'     => $used,
                'resource' => $resourceKey,
                'message'  => null,
            ];
        }

        $label = match ($resourceKey) {
            'branches'  => 'branch',
            'users'     => 'user',
            'terminals' => 'terminal',
            default     => 'resource',
        };

        $plural = match ($resourceKey) {
            'branches'  => 'branches',
            'users'     => 'users',
            'terminals' => 'terminals',
            default     => 'resources',
        };

        $message = $used > $limit
            ? "Your plan allows only {$limit} {$label}, but you currently have {$used}. Existing {$plural} remain available, but you cannot add more until you upgrade your plan."
            : "Your current plan allows only {$limit} {$label}. Please upgrade your plan to add more {$plural}.";

        return [
            'allowed'  => false,
            'limit'    => $limit,
            'used'     => $used,
            'resource' => $resourceKey,
            'message'  => $message,
        ];
    }
}
