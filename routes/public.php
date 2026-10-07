<?php

use App\Http\Controllers\PublicSiteController;
use App\Support\PublicLocale;
use Illuminate\Support\Facades\Route;

// WEBSITE-I18N-GEO-1: every public page exists once per language. English keeps the URLs and route
// names it always had (/pricing, public.pricing); each other enabled language gets the same pages
// under its own prefix (/ar/pricing, public.ar.pricing). Same controller, same view — the locale
// comes from the URL (SetPublicLocale), never from the back office's session switch.
$publicPages = function () {
    Route::get('/', [PublicSiteController::class, 'home'])->name('home');

    Route::get('/pricing', [PublicSiteController::class, 'pricing'])->name('pricing');

    Route::get('/features', [PublicSiteController::class, 'features'])->name('features');

    Route::get('/demos', [PublicSiteController::class, 'demos'])->name('demos');

    Route::get('/start-trial', [PublicSiteController::class, 'trialCreate'])->name('trial.create');

    Route::post('/start-trial', [PublicSiteController::class, 'trialStore'])
        ->middleware('throttle:5,1')
        ->name('trial.store');

    Route::get('/trial/success', [PublicSiteController::class, 'trialSuccess'])->name('trial.success');

    // TRIAL-SIGNUP-QUEUE-1: the success page polls this while the workspace is built (session-scoped).
    Route::get('/trial/status', [PublicSiteController::class, 'trialStatus'])
        ->middleware('throttle:60,1')
        ->name('trial.status');

    Route::get('/contact', [PublicSiteController::class, 'contact'])->name('contact');

    // Legal / policy pages (PRD-4)
    Route::get('/terms', [PublicSiteController::class, 'terms'])->name('terms');
    Route::get('/privacy', [PublicSiteController::class, 'privacy'])->name('privacy');
    Route::get('/refund-policy', [PublicSiteController::class, 'refundPolicy'])->name('refund');
    Route::get('/support-policy', [PublicSiteController::class, 'supportPolicy'])->name('support-policy');
};

Route::domain(config('tenancy.central_domain'))
    ->middleware(['central.only'])
    ->group(function () use ($publicPages) {
        Route::middleware('public.locale:' . PublicLocale::default())->name('public.')->group($publicPages);

        foreach (PublicLocale::prefixed() as $locale) {
            Route::prefix($locale)->middleware('public.locale:' . $locale)->name('public.' . $locale . '.')->group($publicPages);
        }

        // WHATSAPP-REPORT-CHANNEL-1: the first hop of a report link. The approved template button
        // carries ONE fixed base URL for every tenant, so this cannot be a subdomain — a per-tenant
        // base would need its own approved template for every customer. All this does is look the
        // token up and send the reader to the tenant that owns it, where the page is an ordinary
        // tenant page with an ordinary tenant connection.
        Route::get('/r/{token}', [\App\Http\Controllers\ReportShareController::class, 'redirect'])
            ->where('token', '(?:\{\{1\}\})?[A-Za-z0-9]{16,64}')->name('report.share');

        // Every public page in every language, for search engines.
        Route::get('/sitemap.xml', [PublicSiteController::class, 'sitemap'])->name('public.sitemap');
    });
