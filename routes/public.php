<?php

use App\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\Route;

Route::domain(config('tenancy.central_domain'))
    ->middleware(['central.only'])
    ->group(function () {
        Route::get('/', [PublicSiteController::class, 'home'])->name('public.home');

        Route::get('/pricing', [PublicSiteController::class, 'pricing'])->name('public.pricing');

        Route::get('/features', [PublicSiteController::class, 'features'])->name('public.features');

        Route::get('/start-trial', [PublicSiteController::class, 'trialCreate'])->name('public.trial.create');

        Route::post('/start-trial', [PublicSiteController::class, 'trialStore'])
            ->middleware('throttle:5,1')
            ->name('public.trial.store');

        Route::get('/trial/success', [PublicSiteController::class, 'trialSuccess'])->name('public.trial.success');
    });
