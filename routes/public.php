<?php

use App\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\Route;

Route::domain(config('tenancy.central_domain'))
    ->middleware(['central.only'])
    ->group(function () {
        Route::get('/', [PublicSiteController::class, 'home'])->name('public.home');

        Route::get('/pricing', [PublicSiteController::class, 'pricing'])->name('public.pricing');

        Route::get('/features', [PublicSiteController::class, 'features'])->name('public.features');

        Route::get('/demos', [PublicSiteController::class, 'demos'])->name('public.demos');

        Route::get('/start-trial', [PublicSiteController::class, 'trialCreate'])->name('public.trial.create');

        Route::post('/start-trial', [PublicSiteController::class, 'trialStore'])
            ->middleware('throttle:5,1')
            ->name('public.trial.store');

        Route::get('/trial/success', [PublicSiteController::class, 'trialSuccess'])->name('public.trial.success');

        Route::get('/contact', [PublicSiteController::class, 'contact'])->name('public.contact');

        // Legal / policy pages (PRD-4)
        Route::get('/terms', [PublicSiteController::class, 'terms'])->name('public.terms');
        Route::get('/privacy', [PublicSiteController::class, 'privacy'])->name('public.privacy');
        Route::get('/refund-policy', [PublicSiteController::class, 'refundPolicy'])->name('public.refund');
        Route::get('/support-policy', [PublicSiteController::class, 'supportPolicy'])->name('public.support-policy');
    });
