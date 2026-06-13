<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Central\DashboardController;
use App\Http\Controllers\Central\InvoiceController;
use App\Http\Controllers\Central\ModuleController;
use App\Http\Controllers\Central\PlanController;
use App\Http\Controllers\Central\RouteCatalogController;
use App\Http\Controllers\Central\SubscriptionRequestController;
use App\Http\Controllers\Central\TenantController;
use App\Http\Controllers\Central\TenantDomainController;
use Illuminate\Support\Facades\Route;

// Note: Central routes use Route::domain() constraint to match pos-saas.test only.

Route::domain(config('tenancy.central_domain'))
    ->middleware(['central.only'])
    ->group(function () {

        Route::get('/login', [AuthController::class, 'showLogin'])->name('central.login');
        Route::post('/login', [AuthController::class, 'login'])->name('central.login.post');

        Route::middleware(['auth:central'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('central.logout');

            Route::get('/locale/{locale}', [AuthController::class, 'switchLocale'])->name('central.locale.switch');

            Route::get('/password/change', [AuthController::class, 'showChangePassword'])
                ->name('central.password.change');
            Route::post('/password/change', [AuthController::class, 'changePassword'])
                ->name('central.password.update');

            Route::middleware(['route.permission'])->group(function () {
                // GET / on the central domain is owned by the public marketing
                // home (routes/public.php). Central admins land on /dashboard
                // via the post-login redirect.
                Route::get('/dashboard', DashboardController::class)->name('central.dashboard');

                Route::get('/routes', [RouteCatalogController::class, 'index'])->name('central.routes.index');
                Route::post('/routes/sync', [RouteCatalogController::class, 'sync'])->name('central.routes.sync');
                Route::post('/routes/publish', [RouteCatalogController::class, 'publish'])->name('central.routes.publish');
                Route::post('/routes/unpublish', [RouteCatalogController::class, 'unpublish'])->name('central.routes.unpublish');
                Route::post('/routes/publish-all', [RouteCatalogController::class, 'publishAll'])->name('central.routes.publish-all');
                Route::post('/routes/sync-permissions', [RouteCatalogController::class, 'syncPermissions'])->name('central.routes.sync-permissions');

                Route::get('/tenants', [TenantController::class, 'index'])->name('central.tenants.index');
                Route::get('/tenants/create', [TenantController::class, 'create'])->name('central.tenants.create');
                Route::post('/tenants', [TenantController::class, 'store'])->name('central.tenants.store');
                Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('central.tenants.show');
                Route::get('/tenants/{tenant}/edit', [TenantController::class, 'edit'])->name('central.tenants.edit');
                Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->name('central.tenants.update');

                Route::post('/tenants/{tenant}/provision', [TenantController::class, 'provision'])
                    ->name('central.tenants.provision');
                Route::post('/tenants/{tenant}/activate', [TenantController::class, 'activate'])
                    ->name('central.tenants.activate');
                Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend'])
                    ->name('central.tenants.suspend');
                Route::post('/tenants/{tenant}/cancel', [TenantController::class, 'cancel'])
                    ->name('central.tenants.cancel');

                Route::put('/tenants/{tenant}/subscription', [TenantController::class, 'updateSubscription'])
                    ->name('central.tenants.subscription.update');

                // Plans
                Route::get('/plans', [PlanController::class, 'index'])->name('central.plans.index');
                Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('central.plans.edit');
                Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('central.plans.update');

                // Modules
                Route::get('/modules', [ModuleController::class, 'index'])->name('central.modules.index');
                Route::get('/modules/{module}/edit', [ModuleController::class, 'edit'])->name('central.modules.edit');
                Route::put('/modules/{module}', [ModuleController::class, 'update'])->name('central.modules.update');

                // Billing — subscription invoices + manual payments
                Route::get('/invoices', [InvoiceController::class, 'index'])->name('central.invoices.index');
                Route::get('/tenants/{tenant}/invoices/create', [InvoiceController::class, 'create'])->name('central.tenants.invoices.create');
                Route::post('/tenants/{tenant}/invoices', [InvoiceController::class, 'store'])->name('central.tenants.invoices.store');
                Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('central.invoices.show');
                Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'storePayment'])->name('central.invoices.payments.store');
                Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('central.invoices.void');
                Route::get('/invoices/{invoice}/payments/{payment}/proof', [InvoiceController::class, 'downloadPaymentProof'])->name('central.invoices.payments.proof');
                Route::post('/invoices/{invoice}/payments/{payment}/verify', [InvoiceController::class, 'verifyPayment'])->name('central.invoices.payments.verify');
                Route::post('/invoices/{invoice}/payments/{payment}/reject', [InvoiceController::class, 'rejectPayment'])->name('central.invoices.payments.reject');

                // Subscription change requests (plan upgrades)
                Route::get('/subscription-requests', [SubscriptionRequestController::class, 'index'])->name('central.subscription-requests.index');
                Route::get('/subscription-requests/{subscriptionRequest}', [SubscriptionRequestController::class, 'show'])->name('central.subscription-requests.show');
                Route::post('/subscription-requests/{subscriptionRequest}/approve', [SubscriptionRequestController::class, 'approve'])->name('central.subscription-requests.approve');
                Route::post('/subscription-requests/{subscriptionRequest}/reject', [SubscriptionRequestController::class, 'reject'])->name('central.subscription-requests.reject');

                Route::post('/tenants/{tenant}/domains', [TenantDomainController::class, 'store'])
                    ->name('central.tenant-domains.store');
                Route::post('/tenant-domains/{domain}/primary', [TenantDomainController::class, 'makePrimary'])
                    ->name('central.tenant-domains.primary');
                Route::post('/tenant-domains/{domain}/activate', [TenantDomainController::class, 'activate'])
                    ->name('central.tenant-domains.activate');
                Route::post('/tenant-domains/{domain}/deactivate', [TenantDomainController::class, 'deactivate'])
                    ->name('central.tenant-domains.deactivate');
                Route::delete('/tenant-domains/{domain}', [TenantDomainController::class, 'destroy'])
                    ->name('central.tenant-domains.destroy');
            });
        });
    });
