<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Tenant\Ajax\FinishedGoodReceiptLookupController;
use App\Http\Controllers\Tenant\Ajax\ManufacturingCustomerLookupController;
use App\Http\Controllers\Tenant\Ajax\MaterialRequisitionLookupController;
use App\Http\Controllers\Tenant\Ajax\ProductionOrderLookupController;
use App\Http\Controllers\Tenant\Ajax\ProductLookupController;
use App\Http\Controllers\Tenant\Ajax\WipJobLookupController;
use App\Http\Controllers\Tenant\Api\PrintAgentApiController;
use App\Http\Controllers\Tenant\Auth\PasswordResetController;
use App\Http\Controllers\Tenant\BranchController;
use App\Http\Controllers\Tenant\CategoryController;
use App\Http\Controllers\Tenant\CategoryPrinterMappingController;
use App\Http\Controllers\Tenant\ComboController;
use App\Http\Controllers\Tenant\ComingSoonController;
use App\Http\Controllers\Tenant\CurrencyController;
use App\Http\Controllers\Tenant\CustomerController;
use App\Http\Controllers\Tenant\DailyClosingController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\DeliveryChannelController;
use App\Http\Controllers\Tenant\DeliveryRiderController;
use App\Http\Controllers\Tenant\DepartmentController;
use App\Http\Controllers\Tenant\DepartmentCountController;
use App\Http\Controllers\Tenant\DepartmentHandoverController;
use App\Http\Controllers\Tenant\DepartmentStockTransferController;
use App\Http\Controllers\Tenant\Finance\AccountController;
use App\Http\Controllers\Tenant\Finance\BalanceSheetController;
use App\Http\Controllers\Tenant\Finance\BranchProfitLossController;
use App\Http\Controllers\Tenant\Finance\CashBankAccountController;
use App\Http\Controllers\Tenant\Finance\CustomerPaymentController;
use App\Http\Controllers\Tenant\Finance\ExpenseCategoryController;
use App\Http\Controllers\Tenant\Finance\ExpenseVoucherController;
use App\Http\Controllers\Tenant\Finance\FinancialExportController;
use App\Http\Controllers\Tenant\Finance\GeneralLedgerController;
use App\Http\Controllers\Tenant\Finance\JournalEntryController;
use App\Http\Controllers\Tenant\Finance\OpeningBalanceController;
use App\Http\Controllers\Tenant\Finance\ProfitLossController;
use App\Http\Controllers\Tenant\Finance\TrialBalanceController;
use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Http\Controllers\Tenant\HeldSaleController;
use App\Http\Controllers\Tenant\InventoryController;
use App\Http\Controllers\Tenant\KitchenDisplayController;
use App\Http\Controllers\Tenant\KitchenProductionController;
use App\Http\Controllers\Tenant\KitchenWastageController;
use App\Http\Controllers\Tenant\ManagerApprovalController;
use App\Http\Controllers\Tenant\Manufacturing\BomController;
use App\Http\Controllers\Tenant\Manufacturing\FinishedGoodReceiptController;
use App\Http\Controllers\Tenant\Manufacturing\ManufacturingConsumptionController;
use App\Http\Controllers\Tenant\Manufacturing\ManufacturingConsumptionPostingController;
use App\Http\Controllers\Tenant\Manufacturing\ManufacturingCustomerController;
use App\Http\Controllers\Tenant\Manufacturing\ManufacturingPostingSettingController;
use App\Http\Controllers\Tenant\Manufacturing\ManufacturingRejectionController;
use App\Http\Controllers\Tenant\Manufacturing\ManufacturingReportController;
use App\Http\Controllers\Tenant\Manufacturing\ManufacturingScrapController;
use App\Http\Controllers\Tenant\Manufacturing\MaterialRequisitionController;
use App\Http\Controllers\Tenant\Manufacturing\ProductionOrderController;
use App\Http\Controllers\Tenant\Manufacturing\WipJobController;
use App\Http\Controllers\Tenant\ModifierGroupController;
use App\Http\Controllers\Tenant\OfflineEdgeController;
use App\Http\Controllers\Tenant\PaymentMethodController;
use App\Http\Controllers\Tenant\POSController;
use App\Http\Controllers\Tenant\PrintAgentController;
use App\Http\Controllers\Tenant\PrintDocumentController;
use App\Http\Controllers\Tenant\PrinterController;
use App\Http\Controllers\Tenant\PrintJobController;
use App\Http\Controllers\Tenant\ProductBarcodeController;
use App\Http\Controllers\Tenant\ProductBranchPriceController;
use App\Http\Controllers\Tenant\ProductBulkImportController;
use App\Http\Controllers\Tenant\ProductController;
use App\Http\Controllers\Tenant\ProductVariantController;
use App\Http\Controllers\Tenant\PromotionController;
use App\Http\Controllers\Tenant\PurchaseBillController;
use App\Http\Controllers\Tenant\PurchaseOrderController;
use App\Http\Controllers\Tenant\ReceiptLayoutController;
use App\Http\Controllers\Tenant\RecipeController;
use App\Http\Controllers\Tenant\Reports\AuditReportController;
use App\Http\Controllers\Tenant\Reports\DepartmentReportController;
use App\Http\Controllers\Tenant\Reports\InventoryReportController;
use App\Http\Controllers\Tenant\Reports\KitchenReportController;
use App\Http\Controllers\Tenant\Reports\PrintReportController;
use App\Http\Controllers\Tenant\Reports\PurchaseReportController;
use App\Http\Controllers\Tenant\Reports\RestaurantReportController;
use App\Http\Controllers\Tenant\Reports\SalesReportController;
use App\Http\Controllers\Tenant\Reports\ShiftReportController;
use App\Http\Controllers\Tenant\RestaurantFloorController;
use App\Http\Controllers\Tenant\RestaurantTableController;
use App\Http\Controllers\Tenant\RestaurantTableSessionController;
use App\Http\Controllers\Tenant\RestaurantWaiterController;
use App\Http\Controllers\Tenant\RoleController;
use App\Http\Controllers\Tenant\SalesLedgerController;
use App\Http\Controllers\Tenant\SalesOrderController;
use App\Http\Controllers\Tenant\SalesReturnController;
use App\Http\Controllers\Tenant\ServiceChargeSettingController;
use App\Http\Controllers\Tenant\ShiftController;
use App\Http\Controllers\Tenant\SplitBillController;
use App\Http\Controllers\Tenant\StockAdjustmentController;
use App\Http\Controllers\Tenant\StockCountController;
use App\Http\Controllers\Tenant\StockTransferController;
use App\Http\Controllers\Tenant\SupplierController;
use App\Http\Controllers\Tenant\SupplierPaymentController;
use App\Http\Controllers\Tenant\TenantBillingController;
use App\Http\Controllers\Tenant\TenantUpgradeController;
use App\Http\Controllers\Tenant\TenantUserController;
use App\Http\Controllers\Tenant\TerminalController;
use App\Http\Controllers\Tenant\UnitController;
use App\Http\Controllers\Tenant\UnitConversionController;
use App\Http\Controllers\Tenant\VoidReasonController;
use Illuminate\Support\Facades\Route;

Route::domain('{subdomain}.'.config('tenancy.tenant_base_domain'))
    ->middleware(['tenant.only'])
    ->group(function () {

        Route::get('/login', [AuthController::class, 'showLogin'])->name('tenant.login');
        Route::post('/login', [AuthController::class, 'login'])->name('tenant.login.post');

        // Self-service password reset (PRD-5) — operates on the tenant connection.
        Route::get('/forgot-password', [PasswordResetController::class, 'showLinkRequest'])->name('tenant.password.request');
        Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
            ->middleware('throttle:5,1')->name('tenant.password.email');
        Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('tenant.password.reset');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])
            ->middleware('throttle:5,1')->name('tenant.password.store');

        Route::middleware(['auth:tenant'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('tenant.logout');

            Route::get('/locale/{locale}', [AuthController::class, 'switchLocale'])->name('tenant.locale.switch');

            Route::get('/password/change', [AuthController::class, 'showChangePassword'])
                ->name('tenant.password.change');
            Route::post('/password/change', [AuthController::class, 'changePassword'])
                ->name('tenant.password.update');

            // SHIFT-TIMEZONE-BUSINESS-DATE-1 (C): personal display-timezone preference.
            Route::post('/preferences/timezone', [AuthController::class, 'updateTimezone'])
                ->name('tenant.preferences.timezone');

            // AJAX Select2 lookups (authenticated only — no per-permission gate;
            // they are read-only searchable pickers used across forms/filters).
            Route::get('/ajax/products', ProductLookupController::class)->name('tenant.ajax.products');
            Route::get('/ajax/sales', \App\Http\Controllers\Tenant\Ajax\SaleLookupController::class)->name('tenant.ajax.sales');
            Route::get('/ajax/customers', \App\Http\Controllers\Tenant\Ajax\CustomerLookupController::class)->name('tenant.ajax.customers');
            Route::get('/ajax/goods-receipts', \App\Http\Controllers\Tenant\Ajax\GrnLookupController::class)->name('tenant.ajax.goods-receipts');
            Route::get('/ajax/manufacturing-customers', ManufacturingCustomerLookupController::class)->name('tenant.ajax.manufacturing-customers');
            Route::get('/ajax/production-orders', ProductionOrderLookupController::class)->name('tenant.ajax.production-orders');
            Route::get('/ajax/material-requisitions', MaterialRequisitionLookupController::class)->name('tenant.ajax.material-requisitions');
            Route::get('/ajax/wip-jobs', WipJobLookupController::class)->name('tenant.ajax.wip-jobs');
            Route::get('/ajax/finished-goods', FinishedGoodReceiptLookupController::class)->name('tenant.ajax.finished-goods');

            // BRANCH-DEVICE-PAIRING-1 (+HARDEN-1) — SECURITY controls: gated by permission +
            // ownership ONLY (route.permission), deliberately OUTSIDE the subscription/module
            // gate so an Owner can still see paired devices, cancel a live code, and revoke a
            // device after offline_edge entitlement is removed, the subscription lapses, or the
            // rollout flag is turned off. Generation/download stay entitlement+rollout gated.
            Route::middleware(['route.permission', 'prevent.demo.mutation'])->group(function () {
                Route::get('/settings/offline-edge/security', [OfflineEdgeController::class, 'security'])
                    ->name('tenant.offline-edge.security');
                Route::post('/settings/offline-edge/branches/{branch}/pairing-code/cancel', [OfflineEdgeController::class, 'cancelPairingCode'])
                    ->name('tenant.offline-edge.pairing-code.cancel');
                Route::post('/settings/offline-edge/devices/{edgeDevice}/revoke', [OfflineEdgeController::class, 'revokeDevice'])
                    ->name('tenant.offline-edge.devices.revoke');
                // EDGE-LOCAL-AUTH-1 (Section 6) — an authorized Cloud actor issues a signed, single-use
                // enrollment assertion for a Branch Server user (permission == route name).
                Route::post('/settings/offline-edge/enrollment-assertions', [\App\Http\Controllers\Tenant\EdgeEnrollmentController::class, 'issue'])
                    ->middleware('throttle:20,1')->name('tenant.offline-edge.enrollment.issue');
            });

            Route::middleware(['tenant.subscription.access', 'route.permission', 'prevent.demo.mutation'])->group(function () {
                Route::get('/', fn () => redirect('/dashboard'));
                Route::get('/dashboard', DashboardController::class)->name('tenant.dashboard');

                // KASHIF-CATERING-CALENDAR-1 — older months for the booking
                // calendar, loaded on demand. Named under tenant.dashboard so it
                // inherits the always-allowed landing surface; the controller
                // still refuses with 404 unless catering is on the plan.
                Route::get('/dashboard/catering-calendar', [DashboardController::class, 'cateringCalendar'])
                    ->name('tenant.dashboard.catering-calendar');

                // Tenant Users
                Route::get('/users', [TenantUserController::class, 'index'])->name('tenant.users.index');
                Route::get('/users/create', [TenantUserController::class, 'create'])->name('tenant.users.create');
                Route::post('/users', [TenantUserController::class, 'store'])->name('tenant.users.store');
                Route::get('/users/{user}', [TenantUserController::class, 'show'])->name('tenant.users.show');
                Route::get('/users/{user}/edit', [TenantUserController::class, 'edit'])->name('tenant.users.edit');
                Route::put('/users/{user}', [TenantUserController::class, 'update'])->name('tenant.users.update');
                Route::post('/users/{user}/reset-password', [TenantUserController::class, 'resetPassword'])->name('tenant.users.reset-password');
                Route::post('/users/{user}/activate', [TenantUserController::class, 'activate'])->name('tenant.users.activate');
                Route::delete('/users/{user}', [TenantUserController::class, 'destroy'])->name('tenant.users.destroy');
                Route::get('/users/{user}/manager-pin', [TenantUserController::class, 'managerPinForm'])->name('tenant.users.manager-pin');
                Route::post('/users/{user}/manager-pin', [TenantUserController::class, 'managerPinStore'])->name('tenant.users.manager-pin.store');

                // Roles & Permissions
                // TENANT-RESET-1: Owner-facing transactional reset (permission-gated; the artisan
                // command underneath carries its own guards).
                Route::get('/system-reset', [\App\Http\Controllers\Tenant\SystemResetController::class, 'index'])->name('tenant.system-reset.index');
                Route::post('/system-reset', [\App\Http\Controllers\Tenant\SystemResetController::class, 'execute'])->name('tenant.system-reset.execute');

                Route::get('/roles', [RoleController::class, 'index'])->name('tenant.roles.index');
                Route::get('/roles/create', [RoleController::class, 'create'])->name('tenant.roles.create');
                Route::post('/roles', [RoleController::class, 'store'])->name('tenant.roles.store');
                Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('tenant.roles.edit');
                Route::put('/roles/{role}', [RoleController::class, 'update'])->name('tenant.roles.update');
                Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('tenant.roles.destroy');
                Route::get('/roles/{role}/permissions', [RoleController::class, 'editPermissions'])
                    ->name('tenant.roles.permissions.edit');
                Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])
                    ->name('tenant.roles.permissions.update');
                Route::post('/permissions/sync', [RoleController::class, 'syncPermissions'])
                    ->name('tenant.permissions.sync');

                // Branches
                Route::get('/branches', [BranchController::class, 'index'])->name('tenant.branches.index');
                Route::get('/branches/create', [BranchController::class, 'create'])->name('tenant.branches.create');
                Route::post('/branches', [BranchController::class, 'store'])->name('tenant.branches.store');
                Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('tenant.branches.edit');
                Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('tenant.branches.update');
                Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])->name('tenant.branches.destroy');
                // BRANCH-OPERATING-MODE-1: Local POS lifecycle (setup→pending / return-to-cloud). No activation from UI.
                Route::post('/branches/{branch}/local-mode/request', [BranchController::class, 'requestLocalMode'])->name('tenant.branches.local-mode.request');
                Route::post('/branches/{branch}/local-mode/cancel', [BranchController::class, 'cancelLocalMode'])->name('tenant.branches.local-mode.cancel');

                // Terminals
                Route::get('/terminals', [TerminalController::class, 'index'])->name('tenant.terminals.index');
                Route::get('/terminals/create', [TerminalController::class, 'create'])->name('tenant.terminals.create');
                Route::post('/terminals', [TerminalController::class, 'store'])->name('tenant.terminals.store');
                Route::get('/terminals/{terminal}/edit', [TerminalController::class, 'edit'])->name('tenant.terminals.edit');
                Route::put('/terminals/{terminal}', [TerminalController::class, 'update'])->name('tenant.terminals.update');
                Route::delete('/terminals/{terminal}', [TerminalController::class, 'destroy'])->name('tenant.terminals.destroy');

                // Currencies & Denominations
                Route::get('/currencies', [CurrencyController::class, 'index'])->name('tenant.currencies.index');
                Route::post('/currencies', [CurrencyController::class, 'store'])->name('tenant.currencies.store');
                Route::post('/currencies/{currency}/default', [CurrencyController::class, 'setDefault'])->name('tenant.currencies.default');
                Route::post('/currencies/{currency}/denominations', [CurrencyController::class, 'storeDenomination'])->name('tenant.currency-denominations.store');
                Route::delete('/currency-denominations/{denomination}', [CurrencyController::class, 'destroyDenomination'])->name('tenant.currency-denominations.destroy');

                // Shifts
                Route::get('/shifts', [ShiftController::class, 'index'])->name('tenant.shifts.index');
                Route::get('/shifts/open', [ShiftController::class, 'create'])->name('tenant.shifts.create');
                Route::post('/shifts/open', [ShiftController::class, 'store'])->name('tenant.shifts.store');
                Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->name('tenant.shifts.show');
                Route::get('/shifts/{shift}/close', [ShiftController::class, 'closeForm'])->name('tenant.shifts.close-form');
                Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])->name('tenant.shifts.close');
                // SHIFT-BRANCH-UX-1: branch-wise close (all open terminal shifts of a branch at once).
                Route::get('/shifts-close-branch', [ShiftController::class, 'closeBranchForm'])->name('tenant.shifts.close-branch-form');
                Route::post('/shifts-close-branch', [ShiftController::class, 'closeBranch'])->name('tenant.shifts.close-branch');

                // Units
                Route::get('/units', [UnitController::class, 'index'])->name('tenant.units.index');
                Route::get('/units/create', [UnitController::class, 'create'])->name('tenant.units.create');
                Route::post('/units', [UnitController::class, 'store'])->name('tenant.units.store');
                Route::get('/units/{unit}/edit', [UnitController::class, 'edit'])->name('tenant.units.edit');
                Route::put('/units/{unit}', [UnitController::class, 'update'])->name('tenant.units.update');
                Route::delete('/units/{unit}', [UnitController::class, 'destroy'])->name('tenant.units.destroy');

                // Categories
                Route::get('/categories', [CategoryController::class, 'index'])->name('tenant.categories.index');
                Route::get('/categories/create', [CategoryController::class, 'create'])->name('tenant.categories.create');
                Route::post('/categories', [CategoryController::class, 'store'])->name('tenant.categories.store');
                Route::get('/categories/{category}/edit', [CategoryController::class, 'edit'])->name('tenant.categories.edit');
                Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('tenant.categories.update');
                Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('tenant.categories.destroy');

                // Products
                Route::get('/products', [ProductController::class, 'index'])->name('tenant.products.index');
                Route::get('/products/create', [ProductController::class, 'create'])->name('tenant.products.create');
                Route::post('/products', [ProductController::class, 'store'])->name('tenant.products.store');
                Route::get('/products/{product}', [ProductController::class, 'show'])->name('tenant.products.show');
                Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('tenant.products.edit');
                Route::put('/products/{product}', [ProductController::class, 'update'])->name('tenant.products.update');
                Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('tenant.products.destroy');

                // Modifier Groups (MOD-1 config only)
                Route::get('/modifier-groups', [ModifierGroupController::class, 'index'])->name('tenant.modifier-groups.index');
                Route::get('/modifier-groups/create', [ModifierGroupController::class, 'create'])->name('tenant.modifier-groups.create');
                Route::post('/modifier-groups', [ModifierGroupController::class, 'store'])->name('tenant.modifier-groups.store');
                Route::get('/modifier-groups/{modifierGroup}/edit', [ModifierGroupController::class, 'edit'])->name('tenant.modifier-groups.edit');
                Route::put('/modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'update'])->name('tenant.modifier-groups.update');
                Route::delete('/modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'destroy'])->name('tenant.modifier-groups.destroy');
                Route::put('/products/{product}/modifier-groups', [ModifierGroupController::class, 'syncProduct'])->name('tenant.products.modifier-groups.update');

                // Product Variants
                Route::post('/products/{product}/variants', [ProductVariantController::class, 'store'])->name('tenant.product-variants.store');
                Route::put('/product-variants/{variant}', [ProductVariantController::class, 'update'])->name('tenant.product-variants.update');
                Route::delete('/product-variants/{variant}', [ProductVariantController::class, 'destroy'])->name('tenant.product-variants.destroy');

                // Product Barcodes
                Route::post('/products/{product}/barcodes', [ProductBarcodeController::class, 'store'])->name('tenant.product-barcodes.store');
                Route::post('/products/{product}/barcodes/generate', [ProductBarcodeController::class, 'generate'])->name('tenant.product-barcodes.generate');
                Route::delete('/product-barcodes/{barcode}', [ProductBarcodeController::class, 'destroy'])->name('tenant.product-barcodes.destroy');

                // Branch Prices
                Route::put('/products/{product}/branch-prices', [ProductBranchPriceController::class, 'update'])->name('tenant.product-branch-prices.update');

                // Bulk Import
                Route::get('/products-bulk-import', [ProductBulkImportController::class, 'create'])->name('tenant.products.bulk-import.create');
                Route::post('/products-bulk-import', [ProductBulkImportController::class, 'store'])->name('tenant.products.bulk-import.store');

                // Inventory
                Route::get('/inventory', [InventoryController::class, 'index'])->name('tenant.inventory.index');
                Route::get('/inventory/movements', [InventoryController::class, 'movements'])->name('tenant.inventory.movements');
                Route::get('/inventory/batches', [InventoryController::class, 'batches'])->name('tenant.inventory.batches');
                Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock'])->name('tenant.inventory.low-stock');
                Route::get('/inventory/expiry-alerts', [InventoryController::class, 'expiryAlerts'])->name('tenant.inventory.expiry-alerts');

                // Stock Adjustments
                Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])->name('tenant.stock-adjustments.index');
                Route::get('/stock-adjustments/create', [StockAdjustmentController::class, 'create'])->name('tenant.stock-adjustments.create');
                Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])->name('tenant.stock-adjustments.store');
                Route::get('/stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'show'])->name('tenant.stock-adjustments.show');

                // Stock Transfers
                Route::get('/stock-transfers', [StockTransferController::class, 'index'])->name('tenant.stock-transfers.index');
                Route::get('/stock-transfers/create', [StockTransferController::class, 'create'])->name('tenant.stock-transfers.create');
                Route::post('/stock-transfers', [StockTransferController::class, 'store'])->name('tenant.stock-transfers.store');
                Route::get('/stock-transfers/{stockTransfer}', [StockTransferController::class, 'show'])->name('tenant.stock-transfers.show');

                // Stock Counts
                Route::get('/stock-counts', [StockCountController::class, 'index'])->name('tenant.stock-counts.index');
                Route::get('/stock-counts/create', [StockCountController::class, 'create'])->name('tenant.stock-counts.create');
                Route::post('/stock-counts', [StockCountController::class, 'store'])->name('tenant.stock-counts.store');
                Route::get('/stock-counts/{stockCountSession}', [StockCountController::class, 'show'])->name('tenant.stock-counts.show');
                Route::post('/stock-counts/{stockCountSession}/lines', [StockCountController::class, 'addLine'])->name('tenant.stock-counts.lines.store');
                Route::patch('/stock-counts/{stockCountSession}/lines/{line}', [StockCountController::class, 'updateLine'])->name('tenant.stock-counts.lines.update');
                Route::delete('/stock-counts/{stockCountSession}/lines/{line}', [StockCountController::class, 'destroyLine'])->name('tenant.stock-counts.lines.destroy');
                Route::post('/stock-counts/{stockCountSession}/post', [StockCountController::class, 'post'])->name('tenant.stock-counts.post');
                Route::post('/stock-counts/{stockCountSession}/cancel', [StockCountController::class, 'cancel'])->name('tenant.stock-counts.cancel');

                // Departments (DEPARTMENT-FOUNDATION-1) — mapping/reporting only,
                // no stock movement in this phase.
                Route::get('/departments', [DepartmentController::class, 'index'])->name('tenant.departments.index');
                // NB: registered BEFORE /departments/{department} or the model
                // binding would swallow "dashboard" as a department id.
                Route::get('/departments/dashboard', [\App\Http\Controllers\Tenant\DepartmentDashboardController::class, 'index'])->name('tenant.departments.dashboard');
                // Third-party department handovers (THIRD-PARTY-DEPARTMENT-HANDOVER-1) — registered
                // BEFORE /departments/{department} so "handovers" is not swallowed as a department id.
                Route::get('/departments/handovers', [DepartmentHandoverController::class, 'index'])->name('tenant.departments.handovers.index');
                Route::post('/departments/handovers', [DepartmentHandoverController::class, 'store'])->name('tenant.departments.handovers.store');
                Route::post('/departments/handovers/{handover}/payout', [DepartmentHandoverController::class, 'payout'])->name('tenant.departments.handovers.payout');
                Route::post('/departments/handovers/{handover}/reverse', [DepartmentHandoverController::class, 'reverse'])->name('tenant.departments.handovers.reverse');
                Route::get('/departments/create', [DepartmentController::class, 'create'])->name('tenant.departments.create');
                Route::post('/departments', [DepartmentController::class, 'store'])->name('tenant.departments.store');
                Route::get('/departments/{department}', [DepartmentController::class, 'show'])->name('tenant.departments.show');
                Route::get('/departments/{department}/edit', [DepartmentController::class, 'edit'])->name('tenant.departments.edit');
                Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('tenant.departments.update');
                Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('tenant.departments.destroy');

                // Department custody stock (DEPT-2) — sub-ledger only, never
                // touches official branch stock or GL.
                Route::get('/department-stock', [DepartmentStockTransferController::class, 'index'])->name('tenant.department-stock.index');
                Route::get('/department-stock/transfers', [DepartmentStockTransferController::class, 'transfersIndex'])->name('tenant.department-stock.transfers.index');
                Route::get('/department-stock/transfers/create', [DepartmentStockTransferController::class, 'create'])->name('tenant.department-stock.transfers.create');
                Route::post('/department-stock/transfers', [DepartmentStockTransferController::class, 'store'])->name('tenant.department-stock.transfers.store');
                Route::get('/department-stock/transfers/{transfer}', [DepartmentStockTransferController::class, 'show'])->name('tenant.department-stock.transfers.show');
                Route::get('/department-stock/transfers/{transfer}/edit', [DepartmentStockTransferController::class, 'edit'])->name('tenant.department-stock.transfers.edit');
                Route::put('/department-stock/transfers/{transfer}', [DepartmentStockTransferController::class, 'update'])->name('tenant.department-stock.transfers.update');
                Route::post('/department-stock/transfers/{transfer}/post', [DepartmentStockTransferController::class, 'post'])->name('tenant.department-stock.transfers.post');
                Route::post('/department-stock/transfers/{transfer}/cancel', [DepartmentStockTransferController::class, 'cancel'])->name('tenant.department-stock.transfers.cancel');

                // Department counts (DEPT-4) — custody reconciliation only.
                Route::get('/department-counts', [DepartmentCountController::class, 'index'])->name('tenant.department-counts.index');
                Route::get('/department-counts/create', [DepartmentCountController::class, 'create'])->name('tenant.department-counts.create');
                Route::post('/department-counts', [DepartmentCountController::class, 'store'])->name('tenant.department-counts.store');
                Route::get('/department-counts/{session}', [DepartmentCountController::class, 'show'])->name('tenant.department-counts.show');
                Route::get('/department-counts/{session}/edit', [DepartmentCountController::class, 'edit'])->name('tenant.department-counts.edit');
                Route::put('/department-counts/{session}', [DepartmentCountController::class, 'update'])->name('tenant.department-counts.update');
                Route::post('/department-counts/{session}/submit', [DepartmentCountController::class, 'submit'])->name('tenant.department-counts.submit');
                Route::post('/department-counts/{session}/approve', [DepartmentCountController::class, 'approve'])->name('tenant.department-counts.approve');
                Route::post('/department-counts/{session}/reject', [DepartmentCountController::class, 'reject'])->name('tenant.department-counts.reject');
                Route::post('/department-counts/{session}/cancel', [DepartmentCountController::class, 'cancel'])->name('tenant.department-counts.cancel');

                // Suppliers
                Route::resource('suppliers', SupplierController::class)->names([
                    'index' => 'tenant.suppliers.index',
                    'create' => 'tenant.suppliers.create',
                    'store' => 'tenant.suppliers.store',
                    'show' => 'tenant.suppliers.show',
                    'edit' => 'tenant.suppliers.edit',
                    'update' => 'tenant.suppliers.update',
                    'destroy' => 'tenant.suppliers.destroy',
                ]);
                Route::get('/suppliers/{supplier}/ledger', [SupplierController::class, 'ledger'])
                    ->name('tenant.suppliers.ledger');

                // Purchase Orders
                Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])
                    ->name('tenant.purchase-orders.index');
                Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])
                    ->name('tenant.purchase-orders.create');
                Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])
                    ->name('tenant.purchase-orders.store');
                Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
                    ->name('tenant.purchase-orders.show');
                Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])
                    ->name('tenant.purchase-orders.approve');
                Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
                    ->name('tenant.purchase-orders.cancel');

                // Goods Receipts
                Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])
                    ->name('tenant.goods-receipts.index');
                Route::get('/goods-receipts/create', [GoodsReceiptController::class, 'create'])
                    ->name('tenant.goods-receipts.create');
                Route::post('/goods-receipts', [GoodsReceiptController::class, 'store'])
                    ->name('tenant.goods-receipts.store');
                Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])
                    ->name('tenant.goods-receipts.show');

                // Purchase Bills
                Route::get('/purchase-bills', [PurchaseBillController::class, 'index'])
                    ->name('tenant.purchase-bills.index');
                Route::get('/purchase-bills/create', [PurchaseBillController::class, 'create'])
                    ->name('tenant.purchase-bills.create');
                Route::post('/purchase-bills', [PurchaseBillController::class, 'store'])
                    ->name('tenant.purchase-bills.store');
                Route::get('/purchase-bills/{purchaseBill}', [PurchaseBillController::class, 'show'])
                    ->name('tenant.purchase-bills.show');

                // Supplier Payments
                Route::get('/supplier-payments', [SupplierPaymentController::class, 'index'])
                    ->name('tenant.supplier-payments.index');
                Route::get('/supplier-payments/create', [SupplierPaymentController::class, 'create'])
                    ->name('tenant.supplier-payments.create');
                Route::post('/supplier-payments', [SupplierPaymentController::class, 'store'])
                    ->name('tenant.supplier-payments.store');
                Route::get('/supplier-payments/{supplierPayment}', [SupplierPaymentController::class, 'show'])
                    ->name('tenant.supplier-payments.show');

                // Purchase Returns (PURCHASE-RETURNS-1) — completes the purchasing cycle.
                Route::get('/purchase-returns', [\App\Http\Controllers\Tenant\PurchaseReturnController::class, 'index'])->name('tenant.purchase-returns.index');
                Route::get('/purchase-returns/create', [\App\Http\Controllers\Tenant\PurchaseReturnController::class, 'create'])->name('tenant.purchase-returns.create');
                Route::post('/purchase-returns', [\App\Http\Controllers\Tenant\PurchaseReturnController::class, 'store'])->name('tenant.purchase-returns.store');
                Route::get('/purchase-returns/{purchaseReturn}', [\App\Http\Controllers\Tenant\PurchaseReturnController::class, 'show'])->name('tenant.purchase-returns.show');
                Route::get('/purchase-returns/{purchaseReturn}/edit', [\App\Http\Controllers\Tenant\PurchaseReturnController::class, 'edit'])->name('tenant.purchase-returns.edit');
                Route::put('/purchase-returns/{purchaseReturn}', [\App\Http\Controllers\Tenant\PurchaseReturnController::class, 'update'])->name('tenant.purchase-returns.update');
                Route::post('/purchase-returns/{purchaseReturn}/post', [\App\Http\Controllers\Tenant\PurchaseReturnController::class, 'post'])->name('tenant.purchase-returns.post');
                Route::post('/purchase-returns/{purchaseReturn}/cancel', [\App\Http\Controllers\Tenant\PurchaseReturnController::class, 'cancel'])->name('tenant.purchase-returns.cancel');

                // Daily Closings
                Route::get('/daily-closings', [DailyClosingController::class, 'index'])->name('tenant.daily-closings.index');
                Route::get('/daily-closings/create', [DailyClosingController::class, 'create'])->name('tenant.daily-closings.create');
                Route::post('/daily-closings', [DailyClosingController::class, 'store'])->name('tenant.daily-closings.store');
                Route::get('/daily-closings/{dailyClosing}', [DailyClosingController::class, 'show'])->name('tenant.daily-closings.show');
                Route::post('/daily-closings/{dailyClosing}/approve', [DailyClosingController::class, 'approve'])->name('tenant.daily-closings.approve');

                // Customers
                Route::get('/customers', [CustomerController::class, 'index'])->name('tenant.customers.index');
                Route::get('/customers/create', [CustomerController::class, 'create'])->name('tenant.customers.create');
                Route::post('/customers', [CustomerController::class, 'store'])->name('tenant.customers.store');
                Route::get('/customers/{customer}/ledger', [CustomerController::class, 'ledger'])->name('tenant.customers.ledger');
                Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('tenant.customers.show');
                Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('tenant.customers.edit');
                Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('tenant.customers.update');
                Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('tenant.customers.destroy');

                // Payment Methods
                Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('tenant.payment-methods.index');
                Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->name('tenant.payment-methods.store');
                Route::put('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])->name('tenant.payment-methods.update');
                Route::delete('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy'])->name('tenant.payment-methods.destroy');

                // Delivery channels/riders (DELIVERY-CHANNELS-1)
                Route::get('/delivery/channels', [DeliveryChannelController::class, 'index'])->name('tenant.delivery-channels.index');
                Route::post('/delivery/channels', [DeliveryChannelController::class, 'store'])->name('tenant.delivery-channels.store');
                Route::put('/delivery/channels/{deliveryChannel}', [DeliveryChannelController::class, 'update'])->name('tenant.delivery-channels.update');
                Route::delete('/delivery/channels/{deliveryChannel}', [DeliveryChannelController::class, 'destroy'])->name('tenant.delivery-channels.destroy');
                Route::get('/delivery/riders', [DeliveryRiderController::class, 'index'])->name('tenant.delivery-riders.index');
                Route::post('/delivery/riders', [DeliveryRiderController::class, 'store'])->name('tenant.delivery-riders.store');
                Route::put('/delivery/riders/{deliveryRider}', [DeliveryRiderController::class, 'update'])->name('tenant.delivery-riders.update');
                Route::delete('/delivery/riders/{deliveryRider}', [DeliveryRiderController::class, 'destroy'])->name('tenant.delivery-riders.destroy');

                // POS
                Route::get('/pos', [POSController::class, 'index'])->name('tenant.pos.index');
                Route::post('/pos', [SalesOrderController::class, 'store'])->name('tenant.pos.store');
                Route::post('/pos/{salesOrder}/printing/retry', [SalesOrderController::class, 'retryDirectPayPrinting'])
                    ->name('tenant.pos.printing.retry');
                Route::post('/pos/customers/quick-store', [CustomerController::class, 'quickStore'])
                    ->name('tenant.pos.customers.quick-store');
                Route::post('/pos/customers/{customer}/addresses', [CustomerController::class, 'storeAddress'])
                    ->name('tenant.pos.customers.addresses.store');

                // Sales Orders
                Route::get('/sales-orders', [SalesOrderController::class, 'index'])->name('tenant.sales-orders.index');
                Route::get('/sales-orders/create', [SalesOrderController::class, 'create'])->name('tenant.sales-orders.create');
                Route::post('/sales-orders', [SalesOrderController::class, 'store'])->name('tenant.sales-orders.store');
                Route::get('/sales-orders/{salesOrder}', [SalesOrderController::class, 'show'])->name('tenant.sales-orders.show');
                Route::patch('/sales-orders/{salesOrder}/rider', [SalesOrderController::class, 'updateRider'])->name('tenant.sales-orders.rider.update');
                Route::post('/sales-orders/{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])->name('tenant.sales-orders.cancel');

                // Sales Ledger
                Route::get('/sales-ledger', [SalesLedgerController::class, 'index'])->name('tenant.sales-ledger.index');

                // Sales Returns
                Route::get('/sales-returns', [SalesReturnController::class, 'index'])->name('tenant.sales-returns.index');
                Route::get('/sales-returns/create', [SalesReturnController::class, 'create'])->name('tenant.sales-returns.create');
                Route::post('/sales-returns', [SalesReturnController::class, 'store'])->name('tenant.sales-returns.store');
                Route::get('/sales-returns/{salesReturn}', [SalesReturnController::class, 'show'])->name('tenant.sales-returns.show');

                // Restaurant Floors
                Route::get('/restaurant/floors', [RestaurantFloorController::class, 'index'])->name('tenant.restaurant.floors.index');
                Route::post('/restaurant/floors', [RestaurantFloorController::class, 'store'])->name('tenant.restaurant.floors.store');
                Route::put('/restaurant/floors/{restaurantFloor}', [RestaurantFloorController::class, 'update'])->name('tenant.restaurant.floors.update');
                Route::delete('/restaurant/floors/{restaurantFloor}', [RestaurantFloorController::class, 'destroy'])->name('tenant.restaurant.floors.destroy');

                // Restaurant Tables
                Route::get('/restaurant/tables', [RestaurantTableController::class, 'index'])->name('tenant.restaurant.tables.index');
                Route::post('/restaurant/tables', [RestaurantTableController::class, 'store'])->name('tenant.restaurant.tables.store');
                Route::put('/restaurant/tables/{restaurantTable}', [RestaurantTableController::class, 'update'])->name('tenant.restaurant.tables.update');
                Route::delete('/restaurant/tables/{restaurantTable}', [RestaurantTableController::class, 'destroy'])->name('tenant.restaurant.tables.destroy');

                // Restaurant Waiters
                Route::get('/restaurant/waiters', [RestaurantWaiterController::class, 'index'])->name('tenant.restaurant.waiters.index');
                Route::post('/restaurant/waiters', [RestaurantWaiterController::class, 'store'])->name('tenant.restaurant.waiters.store');
                Route::put('/restaurant/waiters/{restaurantWaiter}', [RestaurantWaiterController::class, 'update'])->name('tenant.restaurant.waiters.update');
                Route::delete('/restaurant/waiters/{restaurantWaiter}', [RestaurantWaiterController::class, 'destroy'])->name('tenant.restaurant.waiters.destroy');

                // Restaurant Board & Sessions
                Route::get('/restaurant/board', [RestaurantTableSessionController::class, 'board'])->name('tenant.restaurant.board');
                Route::post('/restaurant/tables/{restaurantTable}/open', [RestaurantTableSessionController::class, 'open'])->name('tenant.restaurant.table-sessions.open');
                Route::post('/restaurant/table-sessions/{restaurantTableSession}/bill-requested', [RestaurantTableSessionController::class, 'billRequested'])->name('tenant.restaurant.table-sessions.bill-requested');
                Route::post('/restaurant/table-sessions/{restaurantTableSession}/close', [RestaurantTableSessionController::class, 'close'])->name('tenant.restaurant.table-sessions.close');
                Route::get('/restaurant/table-sessions/{restaurantTableSession}', [RestaurantTableSessionController::class, 'show'])->name('tenant.restaurant.table-sessions.show');
                Route::get('/restaurant/table-sessions/{restaurantTableSession}/bill-preview', [RestaurantTableSessionController::class, 'billPreview'])->name('tenant.restaurant.table-sessions.bill-preview');
                Route::post('/restaurant/table-sessions/{restaurantTableSession}/move', [RestaurantTableSessionController::class, 'move'])->name('tenant.restaurant.table-sessions.move');
                Route::post('/restaurant/table-sessions/{restaurantTableSession}/merge', [RestaurantTableSessionController::class, 'merge'])->name('tenant.restaurant.table-sessions.merge');

                // Kitchen Display System (KDS)
                Route::get('/kitchen-display', [KitchenDisplayController::class, 'index'])->name('tenant.kitchen-display.index');
                Route::get('/api/kitchen-display/orders', [KitchenDisplayController::class, 'orders'])->name('tenant.api.kitchen-display.orders');
                Route::post('/api/kitchen-display/lines/{line}/status', [KitchenDisplayController::class, 'updateLineStatus'])->name('tenant.api.kitchen-display.lines.status');
                Route::post('/api/kitchen-display/orders/{salesOrder}/status', [KitchenDisplayController::class, 'updateOrderStatus'])->name('tenant.api.kitchen-display.orders.status');

                // Billing portal (tenant) — reachable even when subscription lapsed (see TenantSubscriptionAccessService always-allowed)
                Route::get('/billing', [TenantBillingController::class, 'index'])->name('tenant.billing.index');
                Route::get('/billing/invoices/{invoice}', [TenantBillingController::class, 'show'])->name('tenant.billing.invoices.show');
                Route::post('/billing/invoices/{invoice}/payments', [TenantBillingController::class, 'uploadPaymentProof'])->name('tenant.billing.invoices.payments.store');
                Route::get('/billing/invoices/{invoice}/payments/{payment}/proof', [TenantBillingController::class, 'downloadProof'])->name('tenant.billing.invoices.payments.proof');

                // Plan upgrade requests (always-allowed prefix tenant.billing — reachable when lapsed)
                Route::get('/billing/upgrade', [TenantUpgradeController::class, 'create'])->name('tenant.billing.upgrade.create');
                Route::post('/billing/upgrade', [TenantUpgradeController::class, 'store'])->name('tenant.billing.upgrade.store');
                Route::get('/billing/upgrade/{requestModel}', [TenantUpgradeController::class, 'show'])->name('tenant.billing.upgrade.show');
                Route::post('/billing/upgrade/{requestModel}/cancel', [TenantUpgradeController::class, 'cancel'])->name('tenant.billing.upgrade.cancel');

                // Split Bill
                Route::get('/sales-orders/{salesOrder}/split-bill', [SplitBillController::class, 'create'])->name('tenant.sales-orders.split-bill');
                Route::post('/sales-orders/{salesOrder}/split-bill', [SplitBillController::class, 'store'])->name('tenant.sales-orders.split-bill.store');

                // Held Sales
                Route::get('/held-sales', [HeldSaleController::class, 'index'])->name('tenant.held-sales.index');
                Route::get('/held-sales/create', [HeldSaleController::class, 'create'])->name('tenant.held-sales.create');
                Route::post('/held-sales', [HeldSaleController::class, 'store'])->name('tenant.held-sales.store');
                Route::post('/held-sales/{salesOrder}/cancel', [HeldSaleController::class, 'cancel'])->name('tenant.held-sales.cancel');
                Route::get('/api/pos/held-sales', [HeldSaleController::class, 'ajaxList'])->name('tenant.api.pos.held-sales');
                Route::get('/api/pos/table-sessions', [HeldSaleController::class, 'ajaxTableSessions'])->name('tenant.api.pos.table-sessions');
                Route::get('/api/pos/print-jobs/{saleId}', [PrintJobController::class, 'ajaxForSale'])->name('tenant.api.pos.print-jobs');
                Route::post('/api/pos/totals/quote', [POSController::class, 'quoteTotals'])->name('tenant.api.pos.totals.quote');
                // BILL-PREVIEW-PARITY-1: proforma of the current cart rendered from the REAL receipt template.
                Route::post('/api/pos/bill-preview', [POSController::class, 'billPreview'])->name('tenant.api.pos.bill-preview');
                Route::get('/api/pos/table-board', [POSController::class, 'tableBoard'])->name('tenant.api.pos.table-board');
                Route::get('/api/pos/recent-sales', [POSController::class, 'recentSales'])->name('tenant.api.pos.recent-sales');
                Route::get('/api/pos/shift-status', [ShiftController::class, 'posStatus'])->name('tenant.api.pos.shift-status');
                // SHIFT-TIMEZONE-BUSINESS-DATE-HARDEN-1: server-authoritative clock resync source for
                // the live header/POS clock (protects a long-running session from server time drift).
                Route::get('/api/server-time', fn () => response()->json([
                    'epoch_ms' => (int) round(microtime(true) * 1000),
                ]))->name('tenant.api.server-time');
                Route::get('/api/pos/table-sessions/{restaurantTableSession}/open-orders', [HeldSaleController::class, 'tableSessionOpenOrders'])->name('tenant.api.pos.table-sessions.open-orders');

                // Unit Conversions
                Route::get('/unit-conversions', [UnitConversionController::class, 'index'])->name('tenant.unit-conversions.index');
                Route::post('/unit-conversions', [UnitConversionController::class, 'store'])->name('tenant.unit-conversions.store');
                Route::put('/unit-conversions/{unitConversion}', [UnitConversionController::class, 'update'])->name('tenant.unit-conversions.update');
                Route::delete('/unit-conversions/{unitConversion}', [UnitConversionController::class, 'destroy'])->name('tenant.unit-conversions.destroy');

                // Recipes / BOM
                Route::get('/recipes', [RecipeController::class, 'index'])->name('tenant.recipes.index');
                Route::get('/recipes/create', [RecipeController::class, 'create'])->name('tenant.recipes.create');
                Route::post('/recipes', [RecipeController::class, 'store'])->name('tenant.recipes.store');
                Route::get('/recipes/{recipe}', [RecipeController::class, 'show'])->name('tenant.recipes.show');
                Route::get('/recipes/{recipe}/edit', [RecipeController::class, 'edit'])->name('tenant.recipes.edit');
                Route::put('/recipes/{recipe}', [RecipeController::class, 'update'])->name('tenant.recipes.update');
                Route::delete('/recipes/{recipe}', [RecipeController::class, 'destroy'])->name('tenant.recipes.destroy');

                // Kitchen Productions
                Route::get('/kitchen/productions', [KitchenProductionController::class, 'index'])->name('tenant.kitchen.productions.index');
                Route::get('/kitchen/productions/create', [KitchenProductionController::class, 'create'])->name('tenant.kitchen.productions.create');
                Route::post('/kitchen/productions', [KitchenProductionController::class, 'store'])->name('tenant.kitchen.productions.store');
                Route::get('/kitchen/productions/{kitchenProduction}', [KitchenProductionController::class, 'show'])->name('tenant.kitchen.productions.show');
                Route::post('/kitchen/productions/{kitchenProduction}/complete', [KitchenProductionController::class, 'complete'])->name('tenant.kitchen.productions.complete');

                // Kitchen Wastages
                Route::get('/kitchen/wastages', [KitchenWastageController::class, 'index'])->name('tenant.kitchen.wastages.index');
                Route::get('/kitchen/wastages/create', [KitchenWastageController::class, 'create'])->name('tenant.kitchen.wastages.create');
                Route::post('/kitchen/wastages', [KitchenWastageController::class, 'store'])->name('tenant.kitchen.wastages.store');
                Route::get('/kitchen/wastages/{kitchenWastage}', [KitchenWastageController::class, 'show'])->name('tenant.kitchen.wastages.show');

                // Printing — Printers
                Route::get('/printing/printers', [PrinterController::class, 'index'])->name('tenant.printing.printers.index');
                Route::post('/printing/printers', [PrinterController::class, 'store'])->name('tenant.printing.printers.store');
                Route::put('/printing/printers/{printer}', [PrinterController::class, 'update'])->name('tenant.printing.printers.update');
                Route::delete('/printing/printers/{printer}', [PrinterController::class, 'destroy'])->name('tenant.printing.printers.destroy');
                Route::post('/printing/terminal-settings', [PrinterController::class, 'saveTerminalSettings'])->name('tenant.printing.terminal-settings.save');

                // Printing — Category Mappings
                Route::get('/printing/category-mappings', [CategoryPrinterMappingController::class, 'index'])->name('tenant.printing.category-mappings.index');
                Route::post('/printing/category-mappings', [CategoryPrinterMappingController::class, 'store'])->name('tenant.printing.category-mappings.store');
                Route::delete('/printing/category-mappings/{categoryPrinterMapping}', [CategoryPrinterMappingController::class, 'destroy'])->name('tenant.printing.category-mappings.destroy');

                // Printing — Layouts
                Route::get('/printing/layouts', [ReceiptLayoutController::class, 'index'])->name('tenant.printing.layouts.index');
                Route::post('/printing/layouts', [ReceiptLayoutController::class, 'store'])->name('tenant.printing.layouts.store');
                Route::get('/printing/layouts/{receiptLayoutSetting}/preview', [ReceiptLayoutController::class, 'preview'])->name('tenant.printing.layouts.preview');

                // Printing — Jobs
                Route::get('/printing/jobs', [PrintJobController::class, 'index'])->name('tenant.printing.jobs.index');
                Route::post('/printing/jobs/receipt/{salesOrder}', [PrintJobController::class, 'queueReceipt'])->name('tenant.printing.jobs.queue-receipt');
                Route::post('/printing/jobs/kot/{salesOrder}', [PrintJobController::class, 'queueKot'])->name('tenant.printing.jobs.queue-kot');
                Route::post('/printing/jobs/reminder/{salesOrder}/confirm', [PrintJobController::class, 'confirmReminders'])->name('tenant.printing.jobs.confirm-reminders');
                Route::post('/printing/jobs/{printJob}/reminder-reprint', [PrintJobController::class, 'reprintReminder'])->name('tenant.printing.jobs.reprint-reminder');
                Route::post('/printing/jobs/{printJob}/mark-printed', [PrintJobController::class, 'markPrinted'])->name('tenant.printing.jobs.mark-printed');
                Route::post('/printing/jobs/{printJob}/retry', [PrintJobController::class, 'retry'])->name('tenant.printing.jobs.retry');
                Route::post('/printing/jobs/{printJob}/dismiss', [PrintJobController::class, 'dismiss'])->name('tenant.printing.jobs.dismiss');

                // Printing — Document preview (receipt / KOT browser print)
                Route::get('/printing/documents/{printJob}/receipt', [PrintDocumentController::class, 'preview'])->name('tenant.printing.documents.receipt');
                Route::get('/printing/documents/{printJob}/kot', [PrintDocumentController::class, 'preview'])->name('tenant.printing.documents.kot');
                Route::get('/printing/documents/{printJob}/reminder', [PrintDocumentController::class, 'preview'])->name('tenant.printing.documents.reminder');
                Route::get('/printing/documents/{printJob}/preview', [PrintDocumentController::class, 'preview'])->name('tenant.printing.documents.preview');

                // Printing — Print Agents (web management)
                Route::get('/print/agents', [PrintAgentController::class, 'index'])->name('tenant.print-agents.index');
                Route::post('/print/agents', [PrintAgentController::class, 'store'])->name('tenant.print-agents.store');
                Route::post('/print/agents/{printAgent}/regenerate-token', [PrintAgentController::class, 'regenerateToken'])->name('tenant.print-agents.regenerate-token');
                Route::post('/print/agents/{printAgent}/deactivate', [PrintAgentController::class, 'deactivate'])->name('tenant.print-agents.deactivate');
                // PRINT-AGENT-INSTALLER-1 — pairing-code flow + test page + agent download
                Route::post('/print/agents/{printAgent}/pairing-code', [PrintAgentController::class, 'pairingCode'])->name('tenant.print-agents.pairing-code');
                Route::post('/print/agents/{printAgent}/test-print', [PrintAgentController::class, 'testPrint'])->name('tenant.print-agents.test-print');
                Route::get('/print/agents/download/windows', [PrintAgentController::class, 'downloadWindows'])->name('tenant.print-agents.download-windows');

                // OFFLINE-EDGE-ENTITLEMENT-1 — sellable Offline Branch Edge (offline_edge
                // module). Module-gated by the subscription middleware; the controller
                // additionally enforces EDGE_FEATURE_ENABLED + installer availability.
                // No pairing/bootstrap/sync/device endpoints exist yet.
                Route::get('/settings/offline-edge', [OfflineEdgeController::class, 'index'])->name('tenant.offline-edge.index');
                Route::get('/settings/offline-edge/download', [OfflineEdgeController::class, 'download'])->name('tenant.offline-edge.download');

                // BRANCH-DEVICE-PAIRING-1 — generate branch pairing codes (entitlement+rollout
                // gated). Cancel + revoke are SECURITY controls registered above, OUTSIDE this
                // subscription gate. Pairing NEVER activates Local POS.
                Route::post('/settings/offline-edge/branches/{branch}/pairing-code', [OfflineEdgeController::class, 'generatePairingCode'])
                    ->middleware('throttle:10,1')->name('tenant.offline-edge.pairing-code.generate');

                // Sales Controls — Promotions
                Route::get('/promotions', [PromotionController::class, 'index'])->name('tenant.promotions.index');
                Route::get('/promotions/create', [PromotionController::class, 'create'])->name('tenant.promotions.create');
                Route::post('/promotions', [PromotionController::class, 'store'])->name('tenant.promotions.store');
                Route::get('/promotions/{promotion}/edit', [PromotionController::class, 'edit'])->name('tenant.promotions.edit');
                Route::put('/promotions/{promotion}', [PromotionController::class, 'update'])->name('tenant.promotions.update');
                Route::delete('/promotions/{promotion}', [PromotionController::class, 'destroy'])->name('tenant.promotions.destroy');

                // Sales Controls — Service Charge
                Route::get('/combos', [ComboController::class, 'index'])->name('tenant.combos.index');
                Route::get('/combos/create', [ComboController::class, 'create'])->name('tenant.combos.create');
                Route::post('/combos', [ComboController::class, 'store'])->name('tenant.combos.store');
                Route::get('/combos/{combo}/edit', [ComboController::class, 'edit'])->name('tenant.combos.edit');
                Route::put('/combos/{combo}', [ComboController::class, 'update'])->name('tenant.combos.update');
                Route::delete('/combos/{combo}', [ComboController::class, 'destroy'])->name('tenant.combos.destroy');

                Route::get('/service-charge-settings', [ServiceChargeSettingController::class, 'index'])->name('tenant.service-charge-settings.index');
                Route::post('/service-charge-settings', [ServiceChargeSettingController::class, 'store'])->name('tenant.service-charge-settings.store');

                // Sales Controls — Void Reasons
                Route::get('/void-reasons', [VoidReasonController::class, 'index'])->name('tenant.void-reasons.index');
                Route::get('/void-reasons/create', [VoidReasonController::class, 'create'])->name('tenant.void-reasons.create');
                Route::post('/void-reasons', [VoidReasonController::class, 'store'])->name('tenant.void-reasons.store');
                Route::get('/void-reasons/{voidReason}/edit', [VoidReasonController::class, 'edit'])->name('tenant.void-reasons.edit');
                Route::put('/void-reasons/{voidReason}', [VoidReasonController::class, 'update'])->name('tenant.void-reasons.update');
                Route::delete('/void-reasons/{voidReason}', [VoidReasonController::class, 'destroy'])->name('tenant.void-reasons.destroy');

                // API — Manager Approvals
                // Manager Approvals — PIN verify (rate-limited, BUG-041 FIX)
                Route::post('/api/manager-approvals/verify', [ManagerApprovalController::class, 'verify'])
                    ->middleware('throttle:10,1')
                    ->name('tenant.api.manager-approvals.verify');
                Route::post('/api/pos/promotions/quote', [PromotionController::class, 'quote'])->name('tenant.api.pos.promotions.quote');

                // Catalog API — shared barcode/SKU lookup used by POS, GRN, stock screens
                Route::post('/api/catalog/barcode/lookup', [ProductController::class, 'lookupBarcode'])->name('tenant.api.catalog.barcode.lookup');

                // SALES REPORT CENTER — one shared engine (tenant.reports.* => reports module).
                Route::get('/reports/center', [\App\Http\Controllers\Tenant\Reports\SalesReportCenterController::class, 'index'])->name('tenant.reports.center.index');
                Route::get('/reports/center/export', [\App\Http\Controllers\Tenant\Reports\SalesReportCenterController::class, 'export'])->name('tenant.reports.center.export');
                Route::get('/reports/center/print', [\App\Http\Controllers\Tenant\Reports\SalesReportCenterController::class, 'print'])->name('tenant.reports.center.print');
                Route::post('/reports/center/send-to-network', [\App\Http\Controllers\Tenant\Reports\SalesReportCenterController::class, 'sendToNetwork'])->name('tenant.reports.center.send-to-network');
                Route::post('/reports/center/email', [\App\Http\Controllers\Tenant\Reports\SalesReportCenterController::class, 'emailNow'])->name('tenant.reports.center.email');
                Route::post('/reports/center/schedules', [\App\Http\Controllers\Tenant\Reports\SalesReportCenterController::class, 'storeSchedule'])->name('tenant.reports.center.schedules.store');
                Route::delete('/reports/center/schedules/{schedule}', [\App\Http\Controllers\Tenant\Reports\SalesReportCenterController::class, 'destroySchedule'])->name('tenant.reports.center.schedules.destroy');

                // Reports — Phase 1
                Route::get('/reports/sales/summary', [SalesReportController::class, 'summary'])->name('tenant.reports.sales.summary');
                Route::get('/reports/sales/items', [SalesReportController::class, 'items'])->name('tenant.reports.sales.items');
                Route::get('/reports/sales/payments', [SalesReportController::class, 'payments'])->name('tenant.reports.sales.payments');
                Route::get('/reports/sales/receivables', [SalesReportController::class, 'receivables'])->name('tenant.reports.sales.receivables');
                Route::get('/reports/sales/channels', [SalesReportController::class, 'channels'])->name('tenant.reports.sales.channels');
                Route::get('/reports/sales/riders', [SalesReportController::class, 'riders'])->name('tenant.reports.sales.riders');
                Route::get('/reports/shifts', [ShiftReportController::class, 'index'])->name('tenant.reports.shifts');
                Route::get('/reports/inventory/valuation', [InventoryReportController::class, 'valuation'])->name('tenant.reports.inventory.valuation');

                // Reports — Phase 2
                Route::get('/reports/daily-closings', [ShiftReportController::class, 'dailyClosings'])->name('tenant.reports.daily-closings');
                Route::get('/reports/inventory/movements', [InventoryReportController::class, 'movements'])->name('tenant.reports.inventory.movements');
                Route::get('/reports/inventory/low-stock', [InventoryReportController::class, 'lowStock'])->name('tenant.reports.inventory.low-stock');
                Route::get('/reports/inventory/expiry', [InventoryReportController::class, 'expiry'])->name('tenant.reports.inventory.expiry');
                Route::get('/reports/inventory/negative-stock', [InventoryReportController::class, 'negativeStock'])->name('tenant.reports.inventory.negative-stock');
                Route::get('/reports/purchases/summary', [PurchaseReportController::class, 'summary'])->name('tenant.reports.purchases.summary');
                Route::get('/reports/purchases/suppliers', [PurchaseReportController::class, 'suppliers'])->name('tenant.reports.purchases.suppliers');
                Route::get('/reports/purchases/payables', [PurchaseReportController::class, 'payables'])->name('tenant.reports.purchases.payables');
                Route::get('/reports/purchases/returns', [PurchaseReportController::class, 'returns'])->name('tenant.reports.purchases.returns');
                Route::get('/reports/restaurant/tables', [RestaurantReportController::class, 'tables'])->name('tenant.reports.restaurant.tables');
                Route::get('/reports/restaurant/waiters', [RestaurantReportController::class, 'waiters'])->name('tenant.reports.restaurant.waiters');
                Route::get('/reports/restaurant/order-types', [RestaurantReportController::class, 'orderTypes'])->name('tenant.reports.restaurant.order-types');
                Route::get('/reports/kitchen/recipe-consumption', [KitchenReportController::class, 'recipeConsumption'])->name('tenant.reports.kitchen.recipe-consumption');
                Route::get('/reports/kitchen/wastage', [KitchenReportController::class, 'wastage'])->name('tenant.reports.kitchen.wastage');
                Route::get('/reports/kitchen/production', [KitchenReportController::class, 'production'])->name('tenant.reports.kitchen.production');
                Route::get('/reports/departments/sales', [DepartmentReportController::class, 'sales'])->name('tenant.reports.departments.sales');
                Route::get('/reports/departments/consumption', [DepartmentReportController::class, 'consumption'])->name('tenant.reports.departments.consumption');
                Route::get('/reports/departments/stock', [DepartmentReportController::class, 'stock'])->name('tenant.reports.departments.stock');
                Route::get('/reports/departments/movements', [DepartmentReportController::class, 'movements'])->name('tenant.reports.departments.movements');
                Route::get('/reports/departments/allocation', [DepartmentReportController::class, 'allocation'])->name('tenant.reports.departments.allocation');
                // DEPT-3A: shadow consumption exception report + resolve/ignore actions.
                Route::get('/reports/departments/consumption-exceptions', [DepartmentReportController::class, 'consumptionExceptions'])->name('tenant.reports.departments.consumption-exceptions');
                Route::get('/reports/departments/reconciliation', [DepartmentReportController::class, 'reconciliation'])->name('tenant.reports.departments.reconciliation');
                Route::post('/department-consumption-exceptions/{exception}/resolve', [DepartmentReportController::class, 'resolveException'])->name('tenant.department-consumption-exceptions.resolve');
                Route::post('/department-consumption-exceptions/{exception}/ignore', [DepartmentReportController::class, 'ignoreException'])->name('tenant.department-consumption-exceptions.ignore');
                Route::get('/reports/audit/manager-approvals', [AuditReportController::class, 'managerApprovals'])->name('tenant.reports.audit.manager-approvals');
                Route::get('/reports/printing/jobs', [PrintReportController::class, 'jobs'])->name('tenant.reports.printing.jobs');

                // Finance — Chart of Accounts (FIN-2)
                Route::get('/finance/accounts', [AccountController::class, 'index'])->name('tenant.finance.accounts.index');
                Route::get('/finance/accounts/create', [AccountController::class, 'create'])->name('tenant.finance.accounts.create');
                Route::post('/finance/accounts', [AccountController::class, 'store'])->name('tenant.finance.accounts.store');
                Route::get('/finance/accounts/{account}/edit', [AccountController::class, 'edit'])->name('tenant.finance.accounts.edit');
                Route::put('/finance/accounts/{account}', [AccountController::class, 'update'])->name('tenant.finance.accounts.update');
                Route::delete('/finance/accounts/{account}', [AccountController::class, 'destroy'])->name('tenant.finance.accounts.destroy');

                // Finance — Cash & Bank Accounts (FIN-3)
                Route::get('/finance/cash-bank-accounts', [CashBankAccountController::class, 'index'])->name('tenant.finance.cash-bank-accounts.index');
                Route::get('/finance/cash-bank-accounts/create', [CashBankAccountController::class, 'create'])->name('tenant.finance.cash-bank-accounts.create');
                Route::post('/finance/cash-bank-accounts', [CashBankAccountController::class, 'store'])->name('tenant.finance.cash-bank-accounts.store');
                Route::get('/finance/cash-bank-accounts/{cashBankAccount}/edit', [CashBankAccountController::class, 'edit'])->name('tenant.finance.cash-bank-accounts.edit');
                Route::put('/finance/cash-bank-accounts/{cashBankAccount}', [CashBankAccountController::class, 'update'])->name('tenant.finance.cash-bank-accounts.update');
                Route::delete('/finance/cash-bank-accounts/{cashBankAccount}', [CashBankAccountController::class, 'destroy'])->name('tenant.finance.cash-bank-accounts.destroy');

                // Finance — Expense Categories (FIN-4)
                Route::get('/finance/expense-categories', [ExpenseCategoryController::class, 'index'])->name('tenant.finance.expense-categories.index');
                Route::get('/finance/expense-categories/create', [ExpenseCategoryController::class, 'create'])->name('tenant.finance.expense-categories.create');
                Route::post('/finance/expense-categories', [ExpenseCategoryController::class, 'store'])->name('tenant.finance.expense-categories.store');
                Route::get('/finance/expense-categories/{expenseCategory}/edit', [ExpenseCategoryController::class, 'edit'])->name('tenant.finance.expense-categories.edit');
                Route::put('/finance/expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->name('tenant.finance.expense-categories.update');
                Route::delete('/finance/expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])->name('tenant.finance.expense-categories.destroy');

                // Finance — Expense Vouchers (FIN-4)
                Route::get('/finance/expenses', [ExpenseVoucherController::class, 'index'])->name('tenant.finance.expenses.index');
                Route::get('/finance/expenses/create', [ExpenseVoucherController::class, 'create'])->name('tenant.finance.expenses.create');
                Route::post('/finance/expenses', [ExpenseVoucherController::class, 'store'])->name('tenant.finance.expenses.store');
                Route::get('/finance/expenses/{expenseVoucher}', [ExpenseVoucherController::class, 'show'])->name('tenant.finance.expenses.show');
                Route::get('/finance/expenses/{expenseVoucher}/edit', [ExpenseVoucherController::class, 'edit'])->name('tenant.finance.expenses.edit');
                Route::put('/finance/expenses/{expenseVoucher}', [ExpenseVoucherController::class, 'update'])->name('tenant.finance.expenses.update');
                Route::delete('/finance/expenses/{expenseVoucher}', [ExpenseVoucherController::class, 'destroy'])->name('tenant.finance.expenses.destroy');
                Route::post('/finance/expenses/{expenseVoucher}/post', [ExpenseVoucherController::class, 'post'])->name('tenant.finance.expenses.post');
                Route::post('/finance/expenses/{expenseVoucher}/void', [ExpenseVoucherController::class, 'void'])->name('tenant.finance.expenses.void');

                // Finance — Customer Payments (FIN-6)
                Route::get('/finance/customer-payments', [CustomerPaymentController::class, 'index'])->name('tenant.finance.customer-payments.index');
                Route::get('/finance/customer-payments/create', [CustomerPaymentController::class, 'create'])->name('tenant.finance.customer-payments.create');
                Route::post('/finance/customer-payments', [CustomerPaymentController::class, 'store'])->name('tenant.finance.customer-payments.store');
                Route::get('/finance/customer-payments/{customerPayment}', [CustomerPaymentController::class, 'show'])->name('tenant.finance.customer-payments.show');

                // Finance — General Ledger (FIN-7)
                Route::get('/finance/journal-entries', [JournalEntryController::class, 'index'])->name('tenant.finance.journal-entries.index');
                Route::get('/finance/journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])->name('tenant.finance.journal-entries.show');

                // Finance — Manual Journal Entries (Q2 feature)
                Route::get('/finance/manual-journals', [\App\Http\Controllers\Tenant\Finance\ManualJournalController::class, 'index'])->name('tenant.finance.manual-journals.index');
                Route::get('/finance/manual-journals/create', [\App\Http\Controllers\Tenant\Finance\ManualJournalController::class, 'create'])->name('tenant.finance.manual-journals.create');
                Route::post('/finance/manual-journals', [\App\Http\Controllers\Tenant\Finance\ManualJournalController::class, 'store'])->name('tenant.finance.manual-journals.store');
                Route::get('/finance/manual-journals/{manualJournal}', [\App\Http\Controllers\Tenant\Finance\ManualJournalController::class, 'show'])->name('tenant.finance.manual-journals.show');
                Route::post('/finance/manual-journals/{manualJournal}/reverse', [\App\Http\Controllers\Tenant\Finance\ManualJournalController::class, 'reverse'])->name('tenant.finance.manual-journals.reverse');

                // Finance — Opening Balances / Owner Capital (FIN-13)
                Route::get('/finance/opening-balances', [OpeningBalanceController::class, 'index'])->name('tenant.finance.opening-balances.index');
                Route::get('/finance/opening-balances/create', [OpeningBalanceController::class, 'create'])->name('tenant.finance.opening-balances.create');
                Route::post('/finance/opening-balances', [OpeningBalanceController::class, 'store'])->name('tenant.finance.opening-balances.store');
                Route::get('/finance/opening-balances/{openingBalanceBatch}', [OpeningBalanceController::class, 'show'])->name('tenant.finance.opening-balances.show');
                Route::get('/finance/opening-balances/{openingBalanceBatch}/edit', [OpeningBalanceController::class, 'edit'])->name('tenant.finance.opening-balances.edit');
                Route::put('/finance/opening-balances/{openingBalanceBatch}', [OpeningBalanceController::class, 'update'])->name('tenant.finance.opening-balances.update');
                Route::post('/finance/opening-balances/{openingBalanceBatch}/post', [OpeningBalanceController::class, 'post'])->name('tenant.finance.opening-balances.post');
                Route::post('/finance/opening-balances/{openingBalanceBatch}/void', [OpeningBalanceController::class, 'void'])->name('tenant.finance.opening-balances.void');

                Route::get('/finance/general-ledger', [GeneralLedgerController::class, 'index'])->name('tenant.finance.general-ledger.index');
                Route::get('/finance/trial-balance', [TrialBalanceController::class, 'index'])->name('tenant.finance.trial-balance.index');
                Route::get('/finance/profit-loss', [ProfitLossController::class, 'index'])->name('tenant.finance.profit-loss.index');
                Route::get('/finance/branch-profit-loss', [BranchProfitLossController::class, 'index'])->name('tenant.finance.branch-profit-loss.index');
                Route::get('/finance/balance-sheet', [BalanceSheetController::class, 'index'])->name('tenant.finance.balance-sheet.index');
                Route::get('/finance/export', [FinancialExportController::class, 'index'])->name('tenant.finance.export.index');

                // ── Coming Soon ERP extensions (ERP-SOON-1) — read-only roadmap pages,
                //    no business logic. Visibility gated in the sidebar by plan + @can.
                Route::get('/finance/bank-reconciliation', [ComingSoonController::class, 'show'])
                    ->defaults('feature', 'bank-reconciliation')->name('tenant.finance.bank-reconciliation.index');

                Route::get('/quotations', [ComingSoonController::class, 'show'])
                    ->defaults('feature', 'quotations')->name('tenant.quotations.index');

                // ── Catering & Events (CATERING-SLICE-1) — separate business vertical.
                //    Estimates/events are catering documents, never sales_orders; zero
                //    stock/GL/shift interaction from these routes. Module key: catering.
                //
                // KASHIF-CATERING-DOUBLE-SUBMIT-1: every catering POST goes through the
                // duplicate guard. It is inert unless the form sends a _submit_token, so
                // this line changes nothing on its own — it only lets a form opt in.
                Route::middleware('prevent.duplicate.submit')->group(function () {
                    Route::get('/catering/events', [\App\Http\Controllers\Tenant\Catering\CateringEventController::class, 'index'])->name('tenant.catering.events.index');
                    Route::get('/catering/events/create', [\App\Http\Controllers\Tenant\Catering\CateringEventController::class, 'create'])->name('tenant.catering.events.create');
                    Route::post('/catering/events', [\App\Http\Controllers\Tenant\Catering\CateringEventController::class, 'store'])->name('tenant.catering.events.store');
                    Route::get('/catering/events/{cateringEvent}', [\App\Http\Controllers\Tenant\Catering\CateringEventController::class, 'show'])->name('tenant.catering.events.show');
                    Route::get('/catering/events/{cateringEvent}/edit', [\App\Http\Controllers\Tenant\Catering\CateringEventController::class, 'edit'])->name('tenant.catering.events.edit');
                    Route::put('/catering/events/{cateringEvent}', [\App\Http\Controllers\Tenant\Catering\CateringEventController::class, 'update'])->name('tenant.catering.events.update');
                    Route::post('/catering/events/{cateringEvent}/confirm', [\App\Http\Controllers\Tenant\Catering\CateringEventController::class, 'confirm'])->name('tenant.catering.events.confirm');
                    Route::post('/catering/events/{cateringEvent}/cancel', [\App\Http\Controllers\Tenant\Catering\CateringEventController::class, 'cancel'])->name('tenant.catering.events.cancel');

                    Route::put('/catering/estimates/{cateringEstimate}', [\App\Http\Controllers\Tenant\Catering\CateringEstimateController::class, 'update'])->name('tenant.catering.estimates.update');
                    Route::post('/catering/estimates/{cateringEstimate}/send', [\App\Http\Controllers\Tenant\Catering\CateringEstimateController::class, 'send'])->name('tenant.catering.estimates.send');
                    Route::post('/catering/estimates/{cateringEstimate}/accept', [\App\Http\Controllers\Tenant\Catering\CateringEstimateController::class, 'accept'])->name('tenant.catering.estimates.accept');
                    Route::post('/catering/estimates/{cateringEstimate}/revise', [\App\Http\Controllers\Tenant\Catering\CateringEstimateController::class, 'revise'])->name('tenant.catering.estimates.revise');

                    Route::post('/catering/estimates/{cateringEstimate}/reprice', [\App\Http\Controllers\Tenant\Catering\CateringEstimateController::class, 'reprice'])->name('tenant.catering.estimates.reprice');

                    // KASHIF-CATERING-LINE-SNAPSHOT-1: decisions about ONE booking —
                    // what tonight actually needs, and what is actually being quoted.
                    // Neither touches the dish or any other quotation.
                    Route::put('/catering/line-cost-blocks/{costBlock}', [\App\Http\Controllers\Tenant\Catering\CateringLineCostController::class, 'updateMaterial'])->name('tenant.catering.line-cost-blocks.update');
                    Route::post('/catering/line-cost-blocks/{costBlock}/reset', [\App\Http\Controllers\Tenant\Catering\CateringLineCostController::class, 'resetMaterial'])->name('tenant.catering.line-cost-blocks.reset');
                    // KASHIF-CATERING-CUSTOMER-SUPPLIED-1: the family brings the meat.
                    // The kitchen still needs it; our store issues none of it, and it
                    // is not charged for.
                    Route::put('/catering/line-cost-blocks/{costBlock}/customer-supplied', [\App\Http\Controllers\Tenant\Catering\CateringLineCostController::class, 'customerSupplied'])->name('tenant.catering.line-cost-blocks.customer-supplied');
                    Route::put('/catering/estimate-lines/{cateringEstimateLine}/quoted-rate', [\App\Http\Controllers\Tenant\Catering\CateringLineCostController::class, 'quoteRate'])->name('tenant.catering.estimate-lines.quoted-rate');
                    Route::post('/catering/estimate-lines/{cateringEstimateLine}/use-calculated-rate', [\App\Http\Controllers\Tenant\Catering\CateringLineCostController::class, 'useCalculatedRate'])->name('tenant.catering.estimate-lines.use-calculated-rate');

                    Route::get('/catering/guide', [\App\Http\Controllers\Tenant\Catering\CateringGuideController::class, 'index'])->name('tenant.catering.guide.index');

                    // A catering-only tenant owns raw materials (mutton, rice, oil) but has
                    // no Manufacturing module, and the Catalog list deliberately shows only
                    // sellable/recipe items — so its materials were reachable from NO screen
                    // at all. These reuse ProductController's proven manufacturing context
                    // under the catering module key; nothing about Manufacturing changes.
                    Route::get('/catering/materials', [ProductController::class, 'index'])->name('tenant.catering.materials.index');
                    Route::get('/catering/materials/create', [ProductController::class, 'create'])->name('tenant.catering.materials.create');
                    Route::post('/catering/materials', [ProductController::class, 'store'])->name('tenant.catering.materials.store');
                    Route::get('/catering/materials/{product}/edit', [ProductController::class, 'edit'])->name('tenant.catering.materials.edit');
                    Route::put('/catering/materials/{product}', [ProductController::class, 'update'])->name('tenant.catering.materials.update');

                    Route::get('/catering/material-rates', [\App\Http\Controllers\Tenant\Catering\CateringMaterialRateController::class, 'index'])->name('tenant.catering.material-rates.index');
                    Route::post('/catering/material-rates', [\App\Http\Controllers\Tenant\Catering\CateringMaterialRateController::class, 'store'])->name('tenant.catering.material-rates.store');

                    // KASHIF-CATERING-COMMERCIAL-RATE-1: what materials are CHARGED
                    // at — a different book from what they cost — and what changing
                    // a house rate would do before anything is changed.
                    Route::get('/catering/commercial-rates', [\App\Http\Controllers\Tenant\Catering\CateringCommercialRateController::class, 'index'])->name('tenant.catering.commercial-rates.index');
                    Route::post('/catering/commercial-rates', [\App\Http\Controllers\Tenant\Catering\CateringCommercialRateController::class, 'store'])->name('tenant.catering.commercial-rates.store');
                    Route::get('/catering/commercial-rates/{product}/impact', [\App\Http\Controllers\Tenant\Catering\CateringCommercialRateController::class, 'impact'])->name('tenant.catering.commercial-rates.impact');
                    Route::post('/catering/commercial-rates/{product}/apply-products', [\App\Http\Controllers\Tenant\Catering\CateringCommercialRateController::class, 'applyToProducts'])->name('tenant.catering.commercial-rates.apply-products');
                    Route::post('/catering/commercial-rates/{product}/apply-drafts', [\App\Http\Controllers\Tenant\Catering\CateringCommercialRateController::class, 'applyToDrafts'])->name('tenant.catering.commercial-rates.apply-drafts');
                    // A sent quotation is never repriced in place: this creates the
                    // next version and applies the house rate to that one.
                    Route::post('/catering/commercial-rates/{product}/revise-and-apply', [\App\Http\Controllers\Tenant\Catering\CateringCommercialRateController::class, 'reviseAndApply'])->name('tenant.catering.commercial-rates.revise-and-apply');

                    Route::get('/catering/rate-impact', [\App\Http\Controllers\Tenant\Catering\CateringRateImpactController::class, 'index'])->name('tenant.catering.rate-impact.index');
                    Route::post('/catering/rate-impact/apply', [\App\Http\Controllers\Tenant\Catering\CateringRateImpactController::class, 'apply'])->name('tenant.catering.rate-impact.apply');

                    Route::get('/catering/profiles', [\App\Http\Controllers\Tenant\Catering\CateringProductProfileController::class, 'index'])->name('tenant.catering.profiles.index');
                    Route::post('/catering/profiles', [\App\Http\Controllers\Tenant\Catering\CateringProductProfileController::class, 'store'])->name('tenant.catering.profiles.store');
                    Route::put('/catering/profiles/{cateringProductProfile}', [\App\Http\Controllers\Tenant\Catering\CateringProductProfileController::class, 'update'])->name('tenant.catering.profiles.update');

                    // KASHIF-CATERING-COST-BLOCKS-1: what a dish is made of, and what
                    // each part adds to its price. Configuration only — posts nothing.
                    Route::get('/catering/profiles/{cateringProductProfile}/blocks', [\App\Http\Controllers\Tenant\Catering\CateringCostBlockController::class, 'edit'])->name('tenant.catering.cost-blocks.edit');
                    Route::put('/catering/profiles/{cateringProductProfile}/blocks', [\App\Http\Controllers\Tenant\Catering\CateringCostBlockController::class, 'update'])->name('tenant.catering.cost-blocks.update');

                    Route::post('/catering/events/{cateringEvent}/advances', [\App\Http\Controllers\Tenant\Catering\CateringAdvanceController::class, 'store'])->name('tenant.catering.advances.store');

                    // KASHIF-CATERING-CUSTOMER-CREDIT-1: the one catering action that
                    // pays money OUT, behind its own permission for that reason.
                    Route::post('/catering/events/{cateringEvent}/refunds', [\App\Http\Controllers\Tenant\Catering\CateringRefundController::class, 'store'])->name('tenant.catering.refunds.store');

                    Route::post('/catering/events/{cateringEvent}/production-releases', [\App\Http\Controllers\Tenant\Catering\CateringProductionReleaseController::class, 'store'])->name('tenant.catering.production-releases.store');
                    Route::get('/catering/production-releases/{cateringProductionRelease}', [\App\Http\Controllers\Tenant\Catering\CateringProductionReleaseController::class, 'show'])->name('tenant.catering.production-releases.show');
                    Route::post('/catering/production-releases/{cateringProductionRelease}/print', [\App\Http\Controllers\Tenant\Catering\CateringProductionReleaseController::class, 'print'])->name('tenant.catering.production-releases.print');
                    Route::post('/catering/production-releases/{cateringProductionRelease}/reprint', [\App\Http\Controllers\Tenant\Catering\CateringProductionReleaseController::class, 'reprint'])->name('tenant.catering.production-releases.reprint');
                    Route::post('/catering/production-releases/{cateringProductionRelease}/issue-materials', [\App\Http\Controllers\Tenant\Catering\CateringMaterialIssueController::class, 'store'])->name('tenant.catering.material-issues.store');

                    // KASHIF-CATERING-STORE-1 — the store counter, answering to nobody.
                    // Issue any quantity, covering any number of bookings or none;
                    // the booking reference is a note, never a requirement.
                    Route::get('/catering/store-issues', [\App\Http\Controllers\Tenant\Catering\CateringStoreIssueController::class, 'index'])->name('tenant.catering.store-issues.index');
                    Route::post('/catering/store-issues', [\App\Http\Controllers\Tenant\Catering\CateringStoreIssueController::class, 'store'])->name('tenant.catering.store-issues.store');
                    // KASHIF-CATERING-STORE-2: read-only booking lookup for the
                    // selection modal — "what is going out tonight".
                    Route::get('/catering/store-issues/bookings', [\App\Http\Controllers\Tenant\Catering\CateringStoreIssueController::class, 'bookings'])->name('tenant.catering.store-issues.bookings');
                    // CAT-STORE-001: what the selected bookings still need from
                    // us — required, customer-supplied, already issued, remaining.
                    // Read-only; looking at it moves no stock.
                    Route::get('/catering/store-issues/requirements', [\App\Http\Controllers\Tenant\Catering\CateringStoreIssueController::class, 'requirements'])->name('tenant.catering.store-issues.requirements');

                    Route::post('/catering/events/{cateringEvent}/final-invoice', [\App\Http\Controllers\Tenant\Catering\CateringFinalInvoiceController::class, 'store'])->name('tenant.catering.final-invoices.store');
                    Route::post('/catering/events/{cateringEvent}/close', [\App\Http\Controllers\Tenant\Catering\CateringFinalInvoiceController::class, 'close'])->name('tenant.catering.events.close');

                    Route::get('/catering/documents/estimate/{cateringEstimate}', [\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class, 'estimate'])->name('tenant.catering.documents.estimate');
                    Route::get('/catering/documents/kitchen-sheet/{cateringProductionRelease}', [\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class, 'kitchenSheet'])->name('tenant.catering.documents.kitchen-sheet');
                    Route::get('/catering/documents/final-invoice/{cateringFinalInvoice}', [\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class, 'finalInvoice'])->name('tenant.catering.documents.final-invoice');

                    // KASHIF-CATERING-OPERATOR-UI-1: bulk documents for a selected set
                    // of bookings. GET + read-only composition for the browser print
                    // dialog — no stock, no postings, no lifecycle change, no queue.
                    Route::get('/catering/documents/bulk/quotations', [\App\Http\Controllers\Tenant\Catering\CateringBulkDocumentController::class, 'quotations'])->name('tenant.catering.documents.bulk-quotations');
                    Route::get('/catering/documents/bulk/kitchen-sheets', [\App\Http\Controllers\Tenant\Catering\CateringBulkDocumentController::class, 'kitchenSheets'])->name('tenant.catering.documents.bulk-kitchen-sheets');
                    Route::get('/catering/documents/bulk/address-sheet', [\App\Http\Controllers\Tenant\Catering\CateringBulkDocumentController::class, 'addressSheet'])->name('tenant.catering.documents.bulk-address-sheet');

                    // KASHIF-CATERING-PRODUCT-UX-1 (item 7) — customer documents over the
                    // existing print_jobs transport. English only: the ESC/POS path has no
                    // codepage or raster support, so Urdu stays A4/browser and is refused
                    // here rather than printed as mojibake.
                    Route::post('/catering/documents/estimate/{cateringEstimate}/print', [\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class, 'printEstimate'])->name('tenant.catering.documents.estimate-print');
                    Route::post('/catering/documents/final-invoice/{cateringFinalInvoice}/print', [\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class, 'printFinalInvoice'])->name('tenant.catering.documents.final-invoice-print');

                    Route::get('/catering/printer-mappings', [\App\Http\Controllers\Tenant\Catering\CateringPrinterMappingController::class, 'index'])->name('tenant.catering.printer-mappings.index');
                    Route::post('/catering/printer-mappings', [\App\Http\Controllers\Tenant\Catering\CateringPrinterMappingController::class, 'store'])->name('tenant.catering.printer-mappings.store');
                    Route::delete('/catering/printer-mappings/{cateringPrinterMapping}', [\App\Http\Controllers\Tenant\Catering\CateringPrinterMappingController::class, 'destroy'])->name('tenant.catering.printer-mappings.destroy');
                    Route::post('/catering/printer-mappings/copy-from-pos', [\App\Http\Controllers\Tenant\Catering\CateringPrinterMappingController::class, 'copyFromPos'])->name('tenant.catering.printer-mappings.copy-from-pos');

                    // KASHIF-CATERING-INSTRUCTIONS-1: the managed kitchen-instruction
                    // vocabulary. Config only — no money, no stock.
                    Route::get('/catering/instructions', [\App\Http\Controllers\Tenant\Catering\CateringInstructionController::class, 'index'])->name('tenant.catering.instructions.index');
                    Route::post('/catering/instructions', [\App\Http\Controllers\Tenant\Catering\CateringInstructionController::class, 'store'])->name('tenant.catering.instructions.store');
                    Route::put('/catering/instructions/{cateringInstruction}', [\App\Http\Controllers\Tenant\Catering\CateringInstructionController::class, 'update'])->name('tenant.catering.instructions.update');

                    Route::get('/catering/settings', [\App\Http\Controllers\Tenant\Catering\CateringSettingController::class, 'index'])->name('tenant.catering.settings.index');
                    Route::put('/catering/settings', [\App\Http\Controllers\Tenant\Catering\CateringSettingController::class, 'update'])->name('tenant.catering.settings.update');
                }); // end prevent.duplicate.submit group

                Route::get('/purchase-requisitions', [ComingSoonController::class, 'show'])
                    ->defaults('feature', 'purchase-requisitions')->name('tenant.purchase-requisitions.index');

                // ── Manufacturing Customers — real CRUD (MANUF-1) ────────────
                // Manufacturing Products - same Product model/table, manufacturing context.
                Route::get('/manufacturing/products', [ProductController::class, 'index'])->name('tenant.manufacturing.products.index');
                Route::get('/manufacturing/products/create', [ProductController::class, 'create'])->name('tenant.manufacturing.products.create');
                Route::post('/manufacturing/products', [ProductController::class, 'store'])->name('tenant.manufacturing.products.store');
                Route::get('/manufacturing/products/{product}/edit', [ProductController::class, 'edit'])->name('tenant.manufacturing.products.edit');
                Route::put('/manufacturing/products/{product}', [ProductController::class, 'update'])->name('tenant.manufacturing.products.update');

                Route::get('/manufacturing/customers', [ManufacturingCustomerController::class, 'index'])->name('tenant.manufacturing.customers.index');
                Route::get('/manufacturing/customers/create', [ManufacturingCustomerController::class, 'create'])->name('tenant.manufacturing.customers.create');
                Route::post('/manufacturing/customers', [ManufacturingCustomerController::class, 'store'])->name('tenant.manufacturing.customers.store');
                Route::get('/manufacturing/customers/{manufacturingCustomer}', [ManufacturingCustomerController::class, 'show'])->name('tenant.manufacturing.customers.show');
                Route::get('/manufacturing/customers/{manufacturingCustomer}/edit', [ManufacturingCustomerController::class, 'edit'])->name('tenant.manufacturing.customers.edit');
                Route::put('/manufacturing/customers/{manufacturingCustomer}', [ManufacturingCustomerController::class, 'update'])->name('tenant.manufacturing.customers.update');
                Route::delete('/manufacturing/customers/{manufacturingCustomer}', [ManufacturingCustomerController::class, 'destroy'])->name('tenant.manufacturing.customers.destroy');

                // ── Bill of Materials — real CRUD (MANUF-3) ─────────────────
                Route::get('/manufacturing/bom', [BomController::class, 'index'])->name('tenant.manufacturing.bom.index');
                Route::get('/manufacturing/bom/create', [BomController::class, 'create'])->name('tenant.manufacturing.bom.create');
                Route::post('/manufacturing/bom', [BomController::class, 'store'])->name('tenant.manufacturing.bom.store');
                Route::get('/manufacturing/bom/{manufacturingBom}', [BomController::class, 'show'])->name('tenant.manufacturing.bom.show');
                Route::get('/manufacturing/bom/{manufacturingBom}/edit', [BomController::class, 'edit'])->name('tenant.manufacturing.bom.edit');
                Route::put('/manufacturing/bom/{manufacturingBom}', [BomController::class, 'update'])->name('tenant.manufacturing.bom.update');
                Route::delete('/manufacturing/bom/{manufacturingBom}', [BomController::class, 'destroy'])->name('tenant.manufacturing.bom.destroy');
                // ── Material Requisition / MRC — real CRUD (MANUF-4) ─────────
                Route::get('/manufacturing/material-requisitions', [MaterialRequisitionController::class, 'index'])->name('tenant.manufacturing.material-requisitions.index');
                Route::get('/manufacturing/material-requisitions/create', [MaterialRequisitionController::class, 'create'])->name('tenant.manufacturing.material-requisitions.create');
                Route::post('/manufacturing/material-requisitions', [MaterialRequisitionController::class, 'store'])->name('tenant.manufacturing.material-requisitions.store');
                Route::get('/manufacturing/material-requisitions/{materialRequisition}', [MaterialRequisitionController::class, 'show'])->name('tenant.manufacturing.material-requisitions.show');
                Route::get('/manufacturing/material-requisitions/{materialRequisition}/edit', [MaterialRequisitionController::class, 'edit'])->name('tenant.manufacturing.material-requisitions.edit');
                Route::put('/manufacturing/material-requisitions/{materialRequisition}', [MaterialRequisitionController::class, 'update'])->name('tenant.manufacturing.material-requisitions.update');
                Route::delete('/manufacturing/material-requisitions/{materialRequisition}', [MaterialRequisitionController::class, 'destroy'])->name('tenant.manufacturing.material-requisitions.destroy');
                // ── Production Orders — real CRUD (MANUF-2) ─────────────────
                Route::get('/manufacturing/production-orders', [ProductionOrderController::class, 'index'])->name('tenant.manufacturing.production-orders.index');
                Route::get('/manufacturing/production-orders/create', [ProductionOrderController::class, 'create'])->name('tenant.manufacturing.production-orders.create');
                Route::post('/manufacturing/production-orders', [ProductionOrderController::class, 'store'])->name('tenant.manufacturing.production-orders.store');
                Route::get('/manufacturing/production-orders/{productionOrder}', [ProductionOrderController::class, 'show'])->name('tenant.manufacturing.production-orders.show');
                Route::get('/manufacturing/production-orders/{productionOrder}/edit', [ProductionOrderController::class, 'edit'])->name('tenant.manufacturing.production-orders.edit');
                Route::put('/manufacturing/production-orders/{productionOrder}', [ProductionOrderController::class, 'update'])->name('tenant.manufacturing.production-orders.update');
                Route::delete('/manufacturing/production-orders/{productionOrder}', [ProductionOrderController::class, 'destroy'])->name('tenant.manufacturing.production-orders.destroy');
                // ── Work in Process / WIP — real CRUD (MANUF-5) ──────────────
                Route::get('/manufacturing/wip', [WipJobController::class, 'index'])->name('tenant.manufacturing.wip.index');
                Route::get('/manufacturing/wip/create', [WipJobController::class, 'create'])->name('tenant.manufacturing.wip.create');
                Route::post('/manufacturing/wip', [WipJobController::class, 'store'])->name('tenant.manufacturing.wip.store');
                Route::get('/manufacturing/wip/{wipJob}', [WipJobController::class, 'show'])->name('tenant.manufacturing.wip.show');
                Route::get('/manufacturing/wip/{wipJob}/edit', [WipJobController::class, 'edit'])->name('tenant.manufacturing.wip.edit');
                Route::put('/manufacturing/wip/{wipJob}', [WipJobController::class, 'update'])->name('tenant.manufacturing.wip.update');
                Route::delete('/manufacturing/wip/{wipJob}', [WipJobController::class, 'destroy'])->name('tenant.manufacturing.wip.destroy');
                // ── Finished Goods — real CRUD (MANUF-6) ─────────────────────
                Route::get('/manufacturing/finished-goods', [FinishedGoodReceiptController::class, 'index'])->name('tenant.manufacturing.finished-goods.index');
                Route::get('/manufacturing/finished-goods/create', [FinishedGoodReceiptController::class, 'create'])->name('tenant.manufacturing.finished-goods.create');
                Route::post('/manufacturing/finished-goods', [FinishedGoodReceiptController::class, 'store'])->name('tenant.manufacturing.finished-goods.store');
                Route::get('/manufacturing/finished-goods/{finishedGoodReceipt}', [FinishedGoodReceiptController::class, 'show'])->name('tenant.manufacturing.finished-goods.show');
                Route::get('/manufacturing/finished-goods/{finishedGoodReceipt}/edit', [FinishedGoodReceiptController::class, 'edit'])->name('tenant.manufacturing.finished-goods.edit');
                Route::put('/manufacturing/finished-goods/{finishedGoodReceipt}', [FinishedGoodReceiptController::class, 'update'])->name('tenant.manufacturing.finished-goods.update');
                Route::delete('/manufacturing/finished-goods/{finishedGoodReceipt}', [FinishedGoodReceiptController::class, 'destroy'])->name('tenant.manufacturing.finished-goods.destroy');
                // ── Scrap / Hard Waste — real CRUD (MANUF-7) ─────────────────
                Route::get('/manufacturing/scrap', [ManufacturingScrapController::class, 'index'])->name('tenant.manufacturing.scrap.index');
                Route::get('/manufacturing/scrap/create', [ManufacturingScrapController::class, 'create'])->name('tenant.manufacturing.scrap.create');
                Route::post('/manufacturing/scrap', [ManufacturingScrapController::class, 'store'])->name('tenant.manufacturing.scrap.store');
                Route::get('/manufacturing/scrap/{manufacturingScrapRecord}', [ManufacturingScrapController::class, 'show'])->name('tenant.manufacturing.scrap.show');
                Route::get('/manufacturing/scrap/{manufacturingScrapRecord}/edit', [ManufacturingScrapController::class, 'edit'])->name('tenant.manufacturing.scrap.edit');
                Route::put('/manufacturing/scrap/{manufacturingScrapRecord}', [ManufacturingScrapController::class, 'update'])->name('tenant.manufacturing.scrap.update');
                Route::delete('/manufacturing/scrap/{manufacturingScrapRecord}', [ManufacturingScrapController::class, 'destroy'])->name('tenant.manufacturing.scrap.destroy');
                // ── Rejections — real CRUD (MANUF-8) ─────────────────────────
                Route::get('/manufacturing/rejections', [ManufacturingRejectionController::class, 'index'])->name('tenant.manufacturing.rejections.index');
                Route::get('/manufacturing/rejections/create', [ManufacturingRejectionController::class, 'create'])->name('tenant.manufacturing.rejections.create');
                Route::post('/manufacturing/rejections', [ManufacturingRejectionController::class, 'store'])->name('tenant.manufacturing.rejections.store');
                Route::get('/manufacturing/rejections/{manufacturingRejectionRecord}', [ManufacturingRejectionController::class, 'show'])->name('tenant.manufacturing.rejections.show');
                Route::get('/manufacturing/rejections/{manufacturingRejectionRecord}/edit', [ManufacturingRejectionController::class, 'edit'])->name('tenant.manufacturing.rejections.edit');
                Route::put('/manufacturing/rejections/{manufacturingRejectionRecord}', [ManufacturingRejectionController::class, 'update'])->name('tenant.manufacturing.rejections.update');
                Route::delete('/manufacturing/rejections/{manufacturingRejectionRecord}', [ManufacturingRejectionController::class, 'destroy'])->name('tenant.manufacturing.rejections.destroy');
                // ── Consumption — real CRUD (MANUF-9) ────────────────────────
                Route::get('/manufacturing/consumption', [ManufacturingConsumptionController::class, 'index'])->name('tenant.manufacturing.consumption.index');
                Route::get('/manufacturing/consumption/create', [ManufacturingConsumptionController::class, 'create'])->name('tenant.manufacturing.consumption.create');
                Route::post('/manufacturing/consumption', [ManufacturingConsumptionController::class, 'store'])->name('tenant.manufacturing.consumption.store');
                Route::get('/manufacturing/consumption/{manufacturingConsumptionRecord}', [ManufacturingConsumptionController::class, 'show'])->name('tenant.manufacturing.consumption.show');
                Route::get('/manufacturing/consumption/{manufacturingConsumptionRecord}/edit', [ManufacturingConsumptionController::class, 'edit'])->name('tenant.manufacturing.consumption.edit');
                Route::put('/manufacturing/consumption/{manufacturingConsumptionRecord}', [ManufacturingConsumptionController::class, 'update'])->name('tenant.manufacturing.consumption.update');
                Route::delete('/manufacturing/consumption/{manufacturingConsumptionRecord}', [ManufacturingConsumptionController::class, 'destroy'])->name('tenant.manufacturing.consumption.destroy');
                // MFG-FIN-C — consumption posting (Dr WIP / Cr Raw Material)
                Route::post('/manufacturing/consumption/{manufacturingConsumptionRecord}/post', [ManufacturingConsumptionPostingController::class, 'post'])->name('tenant.manufacturing.consumption.post');
                Route::post('/manufacturing/consumption/{manufacturingConsumptionRecord}/reverse', [ManufacturingConsumptionPostingController::class, 'reverse'])->name('tenant.manufacturing.consumption.reverse');

                // MFG-FIN-E — Finished Goods posting (Dr FG / Cr WIP) + WIP closing
                Route::post('/manufacturing/finished-goods/{finishedGoodReceipt}/post', [\App\Http\Controllers\Tenant\Manufacturing\FinishedGoodPostingController::class, 'post'])->name('tenant.manufacturing.finished-goods.post');
                Route::post('/manufacturing/finished-goods/{finishedGoodReceipt}/reverse', [\App\Http\Controllers\Tenant\Manufacturing\FinishedGoodPostingController::class, 'reverse'])->name('tenant.manufacturing.finished-goods.reverse');
                Route::post('/manufacturing/wip/{wipJob}/close', [\App\Http\Controllers\Tenant\Manufacturing\FinishedGoodPostingController::class, 'closeWip'])->name('tenant.manufacturing.wip.close');
                // ── Production Reports — read-only analytics (MANUF-10) ──────
                Route::get('/manufacturing/reports', [ManufacturingReportController::class, 'index'])->name('tenant.manufacturing.reports.index');
                Route::get('/manufacturing/reports/export', [ManufacturingReportController::class, 'export'])->name('tenant.manufacturing.reports.export');

                // ── Posting Settings — configuration only, NO posting (MFG-FIN-A) ──
                Route::get('/manufacturing/posting-settings', [ManufacturingPostingSettingController::class, 'show'])->name('tenant.manufacturing.posting-settings.show');
                Route::get('/manufacturing/posting-settings/edit', [ManufacturingPostingSettingController::class, 'edit'])->name('tenant.manufacturing.posting-settings.edit');
                Route::put('/manufacturing/posting-settings', [ManufacturingPostingSettingController::class, 'update'])->name('tenant.manufacturing.posting-settings.update');
            });
        });

        // Print agent API — token-based auth, no session/cookie required
        Route::prefix('/api/print-agent')->group(function () {
            // Pairing exchange: the one-time code IS the credential (rate limited).
            Route::post('/pair', [PrintAgentApiController::class, 'pair'])->name('tenant.api.print-agent.pair');
            Route::post('/heartbeat', [PrintAgentApiController::class, 'heartbeat'])->name('tenant.api.print-agent.heartbeat');
            Route::get('/pending', [PrintAgentApiController::class, 'pending'])->name('tenant.api.print-agent.pending');
            Route::post('/jobs/{printJob}/printed', [PrintAgentApiController::class, 'printed'])->name('tenant.api.print-agent.jobs.printed');
            Route::post('/jobs/{printJob}/failed', [PrintAgentApiController::class, 'failed'])->name('tenant.api.print-agent.jobs.failed');
        });
    });
