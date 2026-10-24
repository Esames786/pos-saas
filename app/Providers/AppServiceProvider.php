<?php

namespace App\Providers;

use App\Models\Master\Tenant;
use App\Services\Saas\TenantSubscriptionAccessService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ONLINE-ORDERING-1: rates aik request/job me aik dafa parhe jayen; agle job me dobara.
        $this->app->scoped(\App\Services\Saas\BillingRates::class);
    }

    public function boot(): void
    {
        // ONLINE-ORDERING-1: a website order's WhatsApp follows the ORDER, whichever path changed it — the
        // cashier's Hold, the inbox, the customer's confirm link, a cancel or the auto-cancel. Listening on
        // the model means no POS controller had to change. Every other order returns on the first line.
        \App\Models\Tenant\SalesOrder::updated(function (\App\Models\Tenant\SalesOrder $sale) {
            if ($sale->order_source !== 'website') {
                return;
            }
            if ($sale->wasChanged('is_draft') && ! $sale->is_draft && $sale->status === 'held') {
                \App\Services\OnlineOrdering\WebsiteOrderNotifier::later('confirmed', $sale->id);
            }
            if ($sale->wasChanged('status') && $sale->status === 'cancelled') {
                \App\Services\OnlineOrdering\WebsiteOrderNotifier::later('cancelled', $sale->id);
            }
        });

        // WEBSITE-I18N-GEO-1: public pages build every link in the page's own language — /pricing in
        // English, /ar/pricing in Arabic. $lurl('/pricing') in any public view.
        \Illuminate\Support\Facades\View::composer(['layouts.public', 'public.*'], function ($view) {
            $view->with('lurl', fn (string $path = '/') => \App\Support\PublicLocale::url($path));
        });

        // EDGE-RUNTIME-BOUNDARY-1: fail closed on a misconfigured Branch Server. A branch appliance
        // that cannot describe its own runtime must NOT silently boot as the full Cloud SaaS. Only
        // enforced for the HTTP/CLI runtime (not during migrations/config caching, where config may
        // not be fully resolved), and never for the cloud role.
        if ((\App\Support\EdgeRuntime::isBranchServer() || \App\Support\EdgeRuntime::isPackagedEdgeArtifact())
            && ! $this->app->runningInConsole()) {
            \App\Support\EdgeRuntime::assertBootConfig();
        }

        // EDGE-LOCAL-RUNTIME-1 (Section E): DEFAULT-DENY console boundary. On a branch_server runtime,
        // any Artisan command not on the Edge CLI allowlist is refused before it runs — so a Cloud-only
        // command can never operate against the appliance's local database. No-op on Cloud.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Console\Events\CommandStarting::class,
            function (\Illuminate\Console\Events\CommandStarting $event) {
                \App\Support\EdgeConsoleBoundary::assertAllowed($event->command);
            }
        );

        // EDGE-LOCAL-AUTH-1 (Section 3): HTTP throttle for the Branch Server local login, keyed by
        // employee_code + client IP — so even an UNKNOWN employee_code cannot be brute-forced without a
        // throttle (the per-user DB lockout only protects known users). Generic responses elsewhere
        // avoid leaking whether a code exists.
        \Illuminate\Support\Facades\RateLimiter::for('edge-login', function (\Illuminate\Http\Request $request) {
            $key = \Illuminate\Support\Str::lower((string) $request->input('employee_code')) . '|' . $request->ip();

            return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by($key);
        });

        // CONTACT-FORM-1: the public "Contact Sales" form.
        //
        // Do taalay, dono zaroori. IP par: ek hi jagah se spam ki baarish rukti hai — magar bohat se
        // asli log ek hi daftar ya mobile network ke peechhe hote hain, is liye 8, 1 nahi. Email
        // par: ek bot jo har baar naya proxy badal leta hai magar wohi email bhejta hai, sirf IP
        // wale taale se nahi rukta.
        //
        // Ghanta (per hour), minute nahi: koi insaan ek ghante me aath martaba sales ko nahi likhta,
        // aur ek bot ke liye ghanta lamba hota hai.
        //
        // ⚠️ Throttle validation se PEHLE chalta hai, is liye ghalti se bhari hui koshish bhi ginti
        // me aati hai — isi liye ginti itni kushada hai, aur isi liye khaali email par email wala
        // taala lagta hi nahi: warna "email likhna bhool gaya" jaise paanch log mil kar poori site
        // ka form band kar dete.
        \Illuminate\Support\Facades\RateLimiter::for('contact-form', function (\Illuminate\Http\Request $request) {
            $email = \Illuminate\Support\Str::lower(trim((string) $request->input('email')));

            return [
                \Illuminate\Cache\RateLimiting\Limit::perHour(8)->by('contact-ip:' . $request->ip()),
                $email === ''
                    ? \Illuminate\Cache\RateLimiting\Limit::none()
                    : \Illuminate\Cache\RateLimiting\Limit::perHour(5)->by('contact-email:' . $email),
            ];
        });

        Paginator::useBootstrapFive();

        // The tenant domain constraint uses {subdomain} for wildcard matching only.
        // Binding it to null causes parametersWithoutNulls() to drop it before
        // the controller dispatcher builds the argument list, preventing the
        // positional collision where 'demo' lands as arg #1 ahead of the actual
        // route model (e.g. Role $role, Branch $branch).
        Route::bind('subdomain', fn () => null);

        // Tenant subscription banner — only when a tenant is active in the
        // container (bound by TenancyManager::activate via IdentifyTenant).
        // Tenant is NOT on $request->attributes; it lives in app('tenant').
        View::composer('layouts.app', function ($view) {
            if (!app()->bound('tenant')) {
                return;
            }

            $tenant = app('tenant');

            if (!$tenant instanceof Tenant) {
                return;
            }

            $view->with(
                'tenantSubscriptionStatus',
                app(TenantSubscriptionAccessService::class)->subscriptionStatus($tenant)
            );
        });
    }
}
