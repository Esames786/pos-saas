<?php

use App\Http\Controllers\Edge\EdgeLocalAuthController;
use App\Http\Controllers\Edge\EdgeLocalHeldSalesController;
use App\Http\Controllers\Edge\EdgeLocalManagerApprovalController;
use App\Http\Controllers\Edge\EdgeLocalPosController;
use App\Http\Controllers\Edge\EdgeLocalPrintJobController;
use App\Http\Controllers\Edge\EdgeLocalRestaurantController;
use App\Http\Controllers\Edge\EdgeLocalReturnController;
use App\Http\Controllers\Edge\EdgeLocalShiftController;
use App\Http\Controllers\Edge\EdgeQuickReportController;
use App\Http\Controllers\Edge\EdgeRuntimeController;
use Illuminate\Support\Facades\Route;

/**
 * EDGE-RUNTIME-BOUNDARY-1 — Branch Server LOCAL runtime routes (health / readiness / build info).
 *
 * Unlike routes/edge.php (the CENTRAL cloud-side pairing/bootstrap API on the central domain), these
 * run ON the Branch Server appliance, so they are NOT domain-restricted — they answer on the local
 * host. They are the ONLY names on the branch_server route allowlist (config/edge.php). On a cloud
 * instance they still resolve (non-secret) but are unremarkable; on a branch_server instance the
 * runtime boundary permits ONLY these.
 *
 * They intentionally carry NO auth (no local auth exists yet) and expose non-secret data only.
 */
Route::prefix('edge/local')->name('edge.local.')->group(function () {
    // Non-secret liveness/readiness/build (no auth — health must answer while uninitialised).
    Route::get('/health', [EdgeRuntimeController::class, 'health'])->name('health');
    Route::get('/ready', [EdgeRuntimeController::class, 'ready'])->name('ready');
    Route::get('/build-info', [EdgeRuntimeController::class, 'buildInfo'])->name('build-info');

    // EDGE-LOCAL-AUTH-1 — local login/logout + authenticated status. These authenticate via the Edge
    // credential (never the Cloud password) and establish the `tenant` session. /pos is NOT opened.
    Route::get('/login', [EdgeLocalAuthController::class, 'showLogin'])->name('auth.login');
    Route::post('/login', [EdgeLocalAuthController::class, 'login'])->middleware('throttle:edge-login')->name('auth.login.post');
    Route::post('/logout', [EdgeLocalAuthController::class, 'logout'])->name('auth.logout');
    Route::get('/status', [EdgeLocalAuthController::class, 'status'])->middleware('edge.auth')->name('auth.status');

    // EDGE-LOCAL-POS-1 — the branch-local POS surface: authenticated local session (edge.auth) + bound
    // appliance (edge.branch — request tenant/branch ids can never override the binding). Registered ONLY
    // on a branch_server (this file is not loaded on Cloud), and every name is on the explicit allowlist.
    // Cash quick_sale/takeaway only; the service refuses everything else. activation_ready stays false.
    Route::prefix('pos')->name('pos.')->middleware(['edge.auth', 'edge.branch'])->group(function () {
        // EDGE-CASHIER-UI-1 — the browser cashier POS page itself (the Online operator surface, Edge-executed).
        Route::get('/', [EdgeLocalPosController::class, 'screen'])->name('screen');
        Route::get('/terminals', [EdgeLocalPosController::class, 'terminals'])->name('terminals');
        Route::post('/terminal/select', [EdgeLocalPosController::class, 'selectTerminal'])->name('terminal.select');
        Route::get('/shift', [EdgeLocalShiftController::class, 'shiftStatus'])->name('shift.status');
        // EDGE-CASHIER-UI — shift parity (breakup / blind count / operating date / terminal lock) + business-friendly sync state.
        Route::get('/shift/summary', [EdgeLocalShiftController::class, 'shiftSummary'])->name('shift.summary');
        Route::get('/sync/summary', [EdgeLocalPosController::class, 'syncSummary'])->name('sync.summary');
        Route::post('/shift/open', [EdgeLocalShiftController::class, 'openShift'])->name('shift.open');
        Route::post('/shift/close', [EdgeLocalShiftController::class, 'closeShift'])->name('shift.close');
        Route::post('/sales', [EdgeLocalPosController::class, 'storeSale'])->name('sales.store');
        // ONLINE-POS PARITY — Preview Bill (zero-mutation running bill).
        Route::post('/preview-bill', [EdgeLocalPosController::class, 'previewBill'])->name('preview.bill');
        // F1 — SALES RETURNS (post-settlement void = return): find a returnable sale (local or mirrored Online), the
        // return screen data, post the return (cash refund out of this till), and the posted document.
        Route::get('/returns/search', [EdgeLocalReturnController::class, 'returnsSearch'])->name('returns.search');
        Route::get('/returns/sales/{sale}', [EdgeLocalReturnController::class, 'returnableSale'])->name('returns.sale');
        Route::post('/returns', [EdgeLocalReturnController::class, 'storeReturn'])->name('returns.store');
        Route::get('/returns/{return}', [EdgeLocalReturnController::class, 'showReturn'])->name('returns.show');

        // F2 — SUPPLIER FINANCE: Suppliers → Supplier Ledger → Record Payment, and the General Journal with the supplier/AP
        // dimension. Read-only warm projection + immutable local events the Cloud posts officially, exactly once.
        Route::get('/suppliers', [\App\Http\Controllers\Edge\EdgeLocalSupplierFinanceController::class, 'suppliersScreen'])->name('suppliers.screen');
        Route::get('/suppliers/options', [\App\Http\Controllers\Edge\EdgeLocalSupplierFinanceController::class, 'options'])->name('suppliers.options');
        Route::get('/suppliers/{supplier}/ledger', [\App\Http\Controllers\Edge\EdgeLocalSupplierFinanceController::class, 'ledger'])->name('suppliers.ledger');
        Route::post('/suppliers/payments', [\App\Http\Controllers\Edge\EdgeLocalSupplierFinanceController::class, 'storePayment'])->name('suppliers.payments.store');
        Route::get('/finance/events/{event}', [\App\Http\Controllers\Edge\EdgeLocalSupplierFinanceController::class, 'showEvent'])->name('finance.events.show');
        Route::get('/finance/journal', [\App\Http\Controllers\Edge\EdgeLocalSupplierFinanceController::class, 'journalScreen'])->name('finance.journal.screen');
        Route::get('/finance/journal/options', [\App\Http\Controllers\Edge\EdgeLocalSupplierFinanceController::class, 'journalOptions'])->name('finance.journal.options');
        Route::post('/finance/journal', [\App\Http\Controllers\Edge\EdgeLocalSupplierFinanceController::class, 'storeJournal'])->name('finance.journal.store');

        // F3 — PURCHASE RETURNS: pick the source goods receipt, return received lines, post (pending sync); the Cloud posts the official return.
        Route::get('/purchase-returns', [\App\Http\Controllers\Edge\EdgeLocalPurchaseReturnController::class, 'screen'])->name('purchase-returns.screen');
        Route::get('/purchase-returns/options', [\App\Http\Controllers\Edge\EdgeLocalPurchaseReturnController::class, 'options'])->name('purchase-returns.options');
        Route::get('/purchase-returns/grns/{grn}', [\App\Http\Controllers\Edge\EdgeLocalPurchaseReturnController::class, 'grn'])->name('purchase-returns.grn');
        Route::post('/purchase-returns', [\App\Http\Controllers\Edge\EdgeLocalPurchaseReturnController::class, 'store'])->name('purchase-returns.store');
        Route::get('/purchase-returns/{event}', [\App\Http\Controllers\Edge\EdgeLocalPurchaseReturnController::class, 'show'])->name('purchase-returns.show');

        // Restaurant layer: dine-in table sessions, held orders (Add Round), KOT business events,
        // settle/cancel, manager re-auth. Same authority envelope (EdgeLocalPosService); NO print transport.
        Route::get('/restaurant/board', [EdgeLocalRestaurantController::class, 'restaurantBoard'])->name('restaurant.board');
        Route::post('/restaurant/tables/{table}/open', [EdgeLocalRestaurantController::class, 'openTable'])->name('restaurant.table.open');
        // ONLINE-POS PARITY — table reservations (reserve / view / cancel).
        Route::get('/restaurant/tables/{table}/reservation', [EdgeLocalRestaurantController::class, 'tableReservation'])->name('restaurant.table.reservation');
        Route::post('/restaurant/tables/{table}/reserve', [EdgeLocalRestaurantController::class, 'reserveTable'])->name('restaurant.table.reserve');
        Route::post('/restaurant/tables/{table}/unreserve', [EdgeLocalRestaurantController::class, 'cancelReservation'])->name('restaurant.table.unreserve');
        Route::post('/restaurant/table-sessions/{session}/close', [EdgeLocalRestaurantController::class, 'closeTableSession'])->name('restaurant.session.close');
        // EDGE-CASHIER-UI-2 — Recall / Dine-In browser workflow reads.
        Route::get('/held-sales', [EdgeLocalHeldSalesController::class, 'heldSales'])->name('held.index');
        Route::get('/held-sales/{sale}', [EdgeLocalHeldSalesController::class, 'heldSale'])->name('held.show');
        Route::get('/void-reasons', [EdgeLocalHeldSalesController::class, 'voidReasons'])->name('void-reasons');
        // CUSTOMER-UX parity — on-demand lookup in the synced customer book (delivery / attach customer / addresses).
        Route::get('/customers', [EdgeLocalPosController::class, 'customers'])->name('customers.search');
        Route::post('/held-sales', [EdgeLocalHeldSalesController::class, 'storeHeldSale'])->name('held.store');
        Route::post('/held-sales/{sale}/kot', [EdgeLocalHeldSalesController::class, 'queueKot'])->name('held.kot');
        Route::post('/held-sales/{sale}/settle', [EdgeLocalHeldSalesController::class, 'settleHeldSale'])->name('held.settle');
        Route::post('/held-sales/{sale}/cancel', [EdgeLocalHeldSalesController::class, 'cancelHeldSale'])->name('held.cancel');
        // ONLINE-POS PARITY — Split Bill (a new held check on the same table; each pays on its own).
        Route::post('/held-sales/{sale}/split', [EdgeLocalHeldSalesController::class, 'splitHeldSale'])->name('held.split');
        Route::post('/manager-approvals/verify', [EdgeLocalManagerApprovalController::class, 'verifyManagerApproval'])->name('manager.verify');

        // EDGE-CASHIER-UI-4 — printing: receipt / KOT reprint / Recent Prints / Print Here document / fallback completion / retry.
        Route::post('/sales/{sale}/receipt', [EdgeLocalPrintJobController::class, 'queueReceipt'])->name('sales.receipt');
        Route::post('/sales/{sale}/kot-reprint', [EdgeLocalPrintJobController::class, 'reprintKot'])->name('sales.kot-reprint');
        Route::get('/print-jobs', [EdgeLocalPrintJobController::class, 'printJobs'])->name('print-jobs.index');
        Route::get('/print-jobs/{job}/document', [EdgeLocalPrintJobController::class, 'printDocument'])->name('print-jobs.document');
        Route::post('/print-jobs/{job}/printed', [EdgeLocalPrintJobController::class, 'markPrinted'])->name('print-jobs.printed');
        Route::post('/print-jobs/{job}/retry', [EdgeLocalPrintJobController::class, 'retryPrintJob'])->name('print-jobs.retry');

        // EDGE-CASHIER-UI-5 — Quick Report on the canonical report authority (view / print here / network; email = Internet required).
        Route::get('/quick-report/options', [EdgeQuickReportController::class, 'options'])->name('quick-report.options');
        Route::get('/quick-report/view', [EdgeQuickReportController::class, 'view'])->name('quick-report.view');
        Route::post('/quick-report/network', [EdgeQuickReportController::class, 'network'])->name('quick-report.network');
        Route::post('/quick-report/email', [EdgeQuickReportController::class, 'email'])->name('quick-report.email');
        // P4 §11 — the ONE operator/admin health page (non-secret; same report as edge:local:health).
        Route::get('/health', [\App\Http\Controllers\Edge\EdgeLocalHealthController::class, 'view'])->name('health.view');

        // ═══════════════ PARITY WORKSTREAM ROUTES (owner directive 20 Sep 2026) — one block per team, append-only ═══════════════
        // Every new URI must ALSO be added, deliberately, to the approved census in tests/Feature/Edge/EdgeBranchServerRegistrationTest.
        // ── W1 (Team 1) — shell / navigation ──
        //    (no authenticated W1 route; the one W1 route — local static assets — must answer the login page too, so it
        //     sits OUTSIDE this edge.auth group: see the "W1 (Team 1) — local static assets" block below the group.)
        // ── W2 (Team 2) — menu & sale ──
        // ── W3 (Team 3) — tables & order lifecycle ──
        // Table Workspace operations (Online RestaurantTableSessionController / HeldSaleController / POSController::recentSales).
        Route::get('/restaurant/table-sessions', [EdgeLocalRestaurantController::class, 'tableSessions'])->name('restaurant.sessions.index');
        Route::get('/restaurant/table-sessions/{session}', [EdgeLocalRestaurantController::class, 'showSession'])->name('restaurant.session.show');
        Route::get('/restaurant/table-sessions/{session}/bill-preview', [EdgeLocalRestaurantController::class, 'sessionBillPreview'])->name('restaurant.session.bill-preview');
        Route::post('/restaurant/table-sessions/{session}/bill-requested', [EdgeLocalRestaurantController::class, 'requestBill'])->name('restaurant.session.bill-requested');
        Route::post('/restaurant/table-sessions/{session}/move', [EdgeLocalRestaurantController::class, 'moveSession'])->name('restaurant.session.move');
        Route::post('/restaurant/table-sessions/{session}/merge', [EdgeLocalRestaurantController::class, 'mergeSessions'])->name('restaurant.session.merge');
        Route::post('/held-sales/{sale}/reattach-table', [EdgeLocalHeldSalesController::class, 'reattachTable'])->name('held.reattach-table');
        Route::get('/recent-sales', [EdgeLocalHeldSalesController::class, 'recentSales'])->name('recent-sales');
        // ── W4 (Team 4) — shifts / permissions / finance ──
        // Edge-local LIST / DETAIL screens (Online route permission enforced in each action) + Quick Report saved selection.
        Route::get('/shifts', [EdgeLocalShiftController::class, 'historyScreen'])->name('shifts.index');
        Route::get('/shifts/{shift}', [EdgeLocalShiftController::class, 'showScreen'])->whereNumber('shift')->name('shifts.show');
        Route::get('/sales-returns', [EdgeLocalReturnController::class, 'listScreen'])->name('sales-returns.index');
        Route::get('/sales-returns/{salesReturn}', [EdgeLocalReturnController::class, 'detailScreen'])->whereNumber('salesReturn')->name('sales-returns.show');
        Route::get('/supplier-payments', [\App\Http\Controllers\Edge\EdgeLocalSupplierFinanceController::class, 'paymentsIndex'])->name('supplier-payments.index');
        Route::get('/supplier-payments/{event}', [\App\Http\Controllers\Edge\EdgeLocalSupplierFinanceController::class, 'paymentShow'])->name('supplier-payments.show');
        Route::get('/finance/manual-journals', [\App\Http\Controllers\Edge\EdgeLocalSupplierFinanceController::class, 'journalsIndex'])->name('finance.manual-journals.index');
        Route::get('/finance/manual-journals/{event}', [\App\Http\Controllers\Edge\EdgeLocalSupplierFinanceController::class, 'journalShow'])->name('finance.manual-journals.show');
        Route::get('/purchase-return-list', [\App\Http\Controllers\Edge\EdgeLocalPurchaseReturnController::class, 'listScreen'])->name('purchase-returns.list');
        Route::get('/purchase-return-list/{event}', [\App\Http\Controllers\Edge\EdgeLocalPurchaseReturnController::class, 'detailScreen'])->name('purchase-returns.detail');
        Route::get('/quick-report/settings', [EdgeQuickReportController::class, 'settings'])->name('quick-report.settings');
        Route::post('/quick-report/save-settings', [EdgeQuickReportController::class, 'saveSettings'])->name('quick-report.save-settings');
        // ── W5 (Team 5) — printing ──
        // Online tenant.printing.jobs.* / tenant.printing.documents.* are permission-free + UserDataScope-scoped — mirrored.
        Route::post('/sales/{sale}/kot', [EdgeLocalPrintJobController::class, 'queueKot'])->name('sales.kot');                                   // printing.jobs.kot (+ Reminder plan)
        Route::post('/sales/{sale}/reminders/confirm', [EdgeLocalPrintJobController::class, 'confirmReminders'])->name('sales.reminders.confirm'); // printing.jobs.reminder.confirm
        Route::post('/print-jobs/{job}/reminder-reprint', [EdgeLocalPrintJobController::class, 'reprintReminder'])->name('print-jobs.reminder-reprint');
        Route::post('/print-jobs/{job}/dismiss', [EdgeLocalPrintJobController::class, 'dismissPrintJob'])->name('print-jobs.dismiss');
        Route::post('/sales/{sale}/printing/retry', [EdgeLocalPrintJobController::class, 'retryDirectPayPrinting'])->name('sales.printing.retry'); // Direct Pay printing retry
        Route::post('/bill-preview/document', [EdgeLocalPrintJobController::class, 'billPreviewDocument'])->name('bill-preview.document');      // canonical BILL PREVIEW
        Route::get('/print-preferences', [EdgeLocalPrintJobController::class, 'printPreferences'])->name('print-preferences');                  // terminal auto-print prefs
    });

    // ── W1 (Team 1) — local static assets (UNAUTHENTICATED on purpose: the login page needs them too) ──
    // Streams a WHITELISTED file from public/assets only (css/js/woff/woff2/ttf/svg/png/ico; no '..', no dot-files, no
    // absolute path, no symlink; real path must stay inside public/assets) — so the Edge pages load the SAME locally
    // packaged Bootstrap 5.3.8 / SweetAlert2 / Tabler icons as the Online POS with NO Internet. No session is started
    // for an asset request (no session-file churn on the appliance); everything else in the web stack still applies,
    // including the branch_server route allowlist (config/edge.php must list `edge.local.assets`).
    Route::get('/assets/{path}', [\App\Http\Controllers\Edge\EdgeLocalAssetController::class, 'show'])
        ->where('path', '.*')
        ->withoutMiddleware([
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ])
        ->name('assets');
});
