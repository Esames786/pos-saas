<?php

use App\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\Route;

Route::domain(config('tenancy.central_domain'))
    ->middleware(['central.only'])
    ->group(function () {
        Route::get('/', [PublicSiteController::class, 'home'])->name('public.home');

        Route::get('/pricing', [PublicSiteController::class, 'pricing'])->name('public.pricing');

        // WHATSAPP-REPORT-CHANNEL-1: the first hop of a report link. The approved template button
        // carries ONE fixed base URL for every tenant, so this cannot be a subdomain — a per-tenant
        // base would need its own approved template for every customer. All this does is look the
        // token up and send the reader to the tenant that owns it, where the page is an ordinary
        // tenant page with an ordinary tenant connection.
        Route::get('/r/{token}', [\App\Http\Controllers\ReportShareController::class, 'redirect'])
            ->where('token', '(?:\{\{1\}\})?[A-Za-z0-9]{16,64}')->name('report.share');

        Route::get('/features', [PublicSiteController::class, 'features'])->name('public.features');

        Route::get('/demos', [PublicSiteController::class, 'demos'])->name('public.demos');

        Route::get('/start-trial', [PublicSiteController::class, 'trialCreate'])->name('public.trial.create');

        Route::post('/start-trial', [PublicSiteController::class, 'trialStore'])
            ->middleware('throttle:5,1')
            ->name('public.trial.store');

        Route::get('/trial/success', [PublicSiteController::class, 'trialSuccess'])->name('public.trial.success');

        // TRIAL-SIGNUP-QUEUE-1: the success page polls this while the workspace is built (session-scoped).
        Route::get('/trial/status', [PublicSiteController::class, 'trialStatus'])
            ->middleware('throttle:60,1')
            ->name('public.trial.status');

        Route::get('/contact', [PublicSiteController::class, 'contact'])->name('public.contact');

        // Legal / policy pages (PRD-4)
        Route::get('/terms', [PublicSiteController::class, 'terms'])->name('public.terms');
        Route::get('/privacy', [PublicSiteController::class, 'privacy'])->name('public.privacy');
        Route::get('/refund-policy', [PublicSiteController::class, 'refundPolicy'])->name('public.refund');
        Route::get('/support-policy', [PublicSiteController::class, 'supportPolicy'])->name('public.support-policy');
    });
