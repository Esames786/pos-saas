<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PreventDemoMutation
{
    /**
     * Safe write-workflow route prefixes that must stay usable in a demo
     * (POS sales, KDS, restaurant tables, held sales). Checked AFTER the
     * DELETE guard, so destructive verbs are still blocked.
     */
    private const ALLOW_PREFIXES = [
        'tenant.pos.',
        'tenant.api.pos.',
        'tenant.kitchen-display.',
        'tenant.api.kitchen-display.',
        'tenant.held-sales.',
        'tenant.restaurant.',
        'tenant.sales-orders.',
        'tenant.api.catalog.',
        'tenant.api.manager-approvals.',
    ];

    /**
     * Dangerous/sensitive substrings in a route name → blocked in demo mode.
     */
    private const BLOCK_KEYWORDS = [
        'destroy', 'delete', 'remove',
        'reset-password', 'password',
        'billing', 'subscription', 'upgrade',
        'payment', 'proof',
        'settings', 'roles', 'permissions',
        'users', 'import', 'export',
        'regenerate', 'manager-pin',
        'activate', 'deactivate',
        // BUG-026 FIX: block printer/agent config writes in demo
        'printers.store', 'printers.update', 'printers.destroy',
        'category-mappings.store', 'category-mappings.destroy',
        'layouts.store',
        'print-agents.store', 'print-agents.deactivate', 'print-agents.regenerate-token',
        'terminal-settings',
    ];

    public function handle(Request $request, Closure $next)
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if (! $tenant || ! method_exists($tenant, 'isDemo') || ! $tenant->isDemo()) {
            return $next($request);
        }

        $method = strtoupper($request->method());

        // Reads are always safe.
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $routeName = optional($request->route())->getName();

        // Unnamed routes can't be classified — allow (framework/utility routes).
        if (! $routeName) {
            return $next($request);
        }

        // Destructive verb is always blocked, regardless of allow-prefixes.
        if ($method === 'DELETE') {
            return $this->deny($request);
        }

        // Known-safe demo workflow writes (POS sale, KDS status, table ops…).
        foreach (self::ALLOW_PREFIXES as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return $next($request);
            }
        }

        // Block sensitive/destructive named routes.
        foreach (self::BLOCK_KEYWORDS as $keyword) {
            if (str_contains($routeName, $keyword)) {
                return $this->deny($request);
            }
        }

        return $next($request);
    }

    private function deny(Request $request)
    {
        $message = 'Demo mode: this action is disabled to keep the demo workspace clean.';

        if ($request->expectsJson() || $request->isJson()) {
            return response()->json(['message' => $message], 423);
        }

        return redirect()->back()->with('error', $message);
    }
}
