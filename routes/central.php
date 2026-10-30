<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Central\BillingRatesController;
use App\Http\Controllers\Central\ContactEnquiryController;
use App\Http\Controllers\Central\DashboardController;
use App\Http\Controllers\Central\HisabController;
use App\Http\Controllers\Central\InvoiceController;
use App\Http\Controllers\Central\WhatsAppUsageController;
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
                // MASTER-UNIVERSE-1 — "Refresh now": the same read-only usage snapshot the daily
                // `system:usage-snapshot` takes. Permission name listed in MasterSeeder.
                Route::post('/system-usage/refresh', [\App\Http\Controllers\Central\SystemUsageController::class, 'refresh'])
                    ->name('central.system-usage.refresh');

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

                // PRIMARY-BUSINESS-1 — who the tenant is (restaurant / catering / medical store / mart /
                // industry) and its switches. Permission names are listed in MasterSeeder.
                Route::get('/tenants/{tenant}/business/impact', [\App\Http\Controllers\Central\TenantBusinessController::class, 'impact'])
                    ->name('central.tenants.business.impact');
                Route::put('/tenants/{tenant}/business', [\App\Http\Controllers\Central\TenantBusinessController::class, 'updateBusiness'])
                    ->name('central.tenants.business.update');
                Route::put('/tenants/{tenant}/features', [\App\Http\Controllers\Central\TenantBusinessController::class, 'updateFeatures'])
                    ->name('central.tenants.features.update');
                // UB-10 — read-only: which items the new screens take as they are (permission in MasterSeeder).
                Route::get('/tenants/{tenant}/readiness', [\App\Http\Controllers\Central\TenantBusinessController::class, 'readiness'])
                    ->name('central.tenants.readiness');

                // MASTER-UNIVERSE-1 — add a branch from the tenant hub (the same insert as the tenant's
                // own Branches screen, plan limit included). Permission name listed in MasterSeeder.
                Route::post('/tenants/{tenant}/branches', [TenantController::class, 'storeBranch'])
                    ->name('central.tenants.branches.store');

                // MASTER-TENANT-OPS-1 — backup / restore / reset / sync (superadmin only)
                // Global actions use hyphenated paths so they never collide with /tenants/{tenant}.
                Route::post('/tenants-sync-all', [TenantController::class, 'syncAll'])->name('central.tenants.sync-all');
                Route::post('/tenants-backup-all', [TenantController::class, 'backupAll'])->name('central.tenants.backup-all');
                Route::post('/tenants-reset-demos', [TenantController::class, 'resetDemoTenants'])->name('central.tenants.reset-demos');

                Route::post('/tenants/{tenant}/backup', [TenantController::class, 'backup'])->name('central.tenants.backup');
                Route::get('/tenants/{tenant}/backups', [TenantController::class, 'backups'])->name('central.tenants.backups');
                // TENANT-AUTO-BACKUP-1 — save a tenant's automatic backup schedule.
                Route::post('/tenants/{tenant}/backup-settings', [TenantController::class, 'saveBackupSettings'])->name('central.tenants.backup-settings.save');
                Route::post('/tenants/{tenant}/sync', [TenantController::class, 'sync'])->name('central.tenants.sync');
                Route::post('/tenants/{tenant}/reset', [TenantController::class, 'reset'])->name('central.tenants.reset');

                Route::get('/tenant-backups/{backup}/download', [TenantController::class, 'downloadBackup'])->name('central.tenant-backups.download');
                Route::post('/tenant-backups/{backup}/restore', [TenantController::class, 'restoreBackup'])->name('central.tenant-backups.restore');
                Route::delete('/tenant-backups/{backup}', [TenantController::class, 'deleteBackup'])->name('central.tenant-backups.delete');

                // Plans
                Route::get('/plans', [PlanController::class, 'index'])->name('central.plans.index');
                Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('central.plans.edit');
                Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('central.plans.update');

                // Modules
                Route::get('/modules', [ModuleController::class, 'index'])->name('central.modules.index');
                Route::get('/modules/{module}/edit', [ModuleController::class, 'edit'])->name('central.modules.edit');
                Route::put('/modules/{module}', [ModuleController::class, 'update'])->name('central.modules.update');

                // Billing — subscription invoices + manual payments
                // SAAS-BILLING-WHATSAPP-1 — WhatsApp ke paise aik safhe par. Apna alag permission
                // (`central.whatsapp-usage.index`) rakhta hai, invoices wala nahi: is par har tenant
                // ki raqam aur hamara MARGIN likha hai, aur wo har us shakhs ko nahi dikhna chahiye
                // jo invoice dekh sakta hai.
                // ⚠️ deploy.sh nayi permission sirf Owner/super ko deta hai — kisi aur ko chahiye to
                // alag se additive grant chalana paRega.
                Route::get('/whatsapp-usage', [WhatsAppUsageController::class, 'index'])->name('central.whatsapp-usage.index');

                // ONLINE-ORDERING-1 — Billing rates (WhatsApp fi message + website commission %),
                // `.env` ke bajaye master me (malik ka faisla).
                Route::get('/billing-rates', [BillingRatesController::class, 'edit'])->name('central.billing-rates.edit');
                Route::put('/billing-rates', [BillingRatesController::class, 'update'])->name('central.billing-rates.update');

                // MASTER-HISAB-1 — Bingoo ka apna hisab (owners ke khate, kharche, 50/50 barabari). Har action isi
                // safhe ka hai; permissions MasterSeeder me (deploy step 3b unhe Super Admin ko deta hai).
                Route::get('/hisab', [HisabController::class, 'index'])->name('central.hisab.index');
                Route::get('/hisab/export', [HisabController::class, 'export'])->name('central.hisab.export');
                Route::post('/hisab/entries', [HisabController::class, 'storeEntry'])->name('central.hisab.entries.store');
                Route::put('/hisab/entries/{entry}', [HisabController::class, 'updateEntry'])->name('central.hisab.entries.update');
                Route::delete('/hisab/entries/{entry}', [HisabController::class, 'destroyEntry'])->name('central.hisab.entries.destroy');
                Route::put('/hisab/setup', [HisabController::class, 'updateSetup'])->name('central.hisab.setup.update');

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

                // CONTACT-FORM-1 — enquiries from the public "Contact Sales" form.
                Route::get('/contact-enquiries', [ContactEnquiryController::class, 'index'])->name('central.contact-enquiries.index');
                Route::post('/contact-enquiries/{contactEnquiry}/handled', [ContactEnquiryController::class, 'handled'])->name('central.contact-enquiries.handled');

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
