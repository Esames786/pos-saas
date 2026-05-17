<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Tenant\BranchController;
use App\Http\Controllers\Tenant\CategoryController;
use App\Http\Controllers\Tenant\CurrencyController;
use App\Http\Controllers\Tenant\CustomerController;
use App\Http\Controllers\Tenant\DailyClosingController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Http\Controllers\Tenant\InventoryController;
use App\Http\Controllers\Tenant\PaymentMethodController;
use App\Http\Controllers\Tenant\POSController;
use App\Http\Controllers\Tenant\ProductBarcodeController;
use App\Http\Controllers\Tenant\PurchaseBillController;
use App\Http\Controllers\Tenant\PurchaseOrderController;
use App\Http\Controllers\Tenant\SalesLedgerController;
use App\Http\Controllers\Tenant\SalesOrderController;
use App\Http\Controllers\Tenant\SalesReturnController;
use App\Http\Controllers\Tenant\SupplierController;
use App\Http\Controllers\Tenant\SupplierPaymentController;
use App\Http\Controllers\Tenant\ProductBranchPriceController;
use App\Http\Controllers\Tenant\ProductBulkImportController;
use App\Http\Controllers\Tenant\ProductController;
use App\Http\Controllers\Tenant\ProductVariantController;
use App\Http\Controllers\Tenant\RoleController;
use App\Http\Controllers\Tenant\ShiftController;
use App\Http\Controllers\Tenant\TenantUserController;
use App\Http\Controllers\Tenant\StockAdjustmentController;
use App\Http\Controllers\Tenant\StockTransferController;
use App\Http\Controllers\Tenant\TerminalController;
use App\Http\Controllers\Tenant\UnitController;
use App\Http\Controllers\Tenant\RestaurantFloorController;
use App\Http\Controllers\Tenant\RestaurantTableController;
use App\Http\Controllers\Tenant\RestaurantWaiterController;
use App\Http\Controllers\Tenant\RestaurantTableSessionController;
use App\Http\Controllers\Tenant\HeldSaleController;
use Illuminate\Support\Facades\Route;

Route::domain('{subdomain}.' . config('tenancy.tenant_base_domain'))
    ->middleware(['tenant.only'])
    ->group(function () {

        Route::get('/login', [AuthController::class, 'showLogin'])->name('tenant.login');
        Route::post('/login', [AuthController::class, 'login'])->name('tenant.login.post');

        Route::middleware(['auth:tenant'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('tenant.logout');

            Route::get('/locale/{locale}', [AuthController::class, 'switchLocale'])->name('tenant.locale.switch');

            Route::get('/password/change', [AuthController::class, 'showChangePassword'])
                ->name('tenant.password.change');
            Route::post('/password/change', [AuthController::class, 'changePassword'])
                ->name('tenant.password.update');

            Route::middleware(['route.permission'])->group(function () {
                Route::get('/', fn () => redirect('/dashboard'));
                Route::get('/dashboard', DashboardController::class)->name('tenant.dashboard');

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

                // Roles & Permissions
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

                // Suppliers
                Route::resource('suppliers', SupplierController::class)->names([
                    'index'   => 'tenant.suppliers.index',
                    'create'  => 'tenant.suppliers.create',
                    'store'   => 'tenant.suppliers.store',
                    'show'    => 'tenant.suppliers.show',
                    'edit'    => 'tenant.suppliers.edit',
                    'update'  => 'tenant.suppliers.update',
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
                Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('tenant.customers.show');
                Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('tenant.customers.edit');
                Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('tenant.customers.update');
                Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('tenant.customers.destroy');

                // Payment Methods
                Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('tenant.payment-methods.index');
                Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->name('tenant.payment-methods.store');
                Route::put('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])->name('tenant.payment-methods.update');
                Route::delete('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy'])->name('tenant.payment-methods.destroy');

                // POS
                Route::get('/pos', [POSController::class, 'index'])->name('tenant.pos.index');
                Route::post('/pos', [SalesOrderController::class, 'store'])->name('tenant.pos.store');
                Route::post('/pos/customers/quick-store', [CustomerController::class, 'quickStore'])
                    ->name('tenant.pos.customers.quick-store');

                // Sales Orders
                Route::get('/sales-orders', [SalesOrderController::class, 'index'])->name('tenant.sales-orders.index');
                Route::get('/sales-orders/create', [SalesOrderController::class, 'create'])->name('tenant.sales-orders.create');
                Route::post('/sales-orders', [SalesOrderController::class, 'store'])->name('tenant.sales-orders.store');
                Route::get('/sales-orders/{salesOrder}', [SalesOrderController::class, 'show'])->name('tenant.sales-orders.show');
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

                // Held Sales
                Route::get('/held-sales', [HeldSaleController::class, 'index'])->name('tenant.held-sales.index');
                Route::get('/held-sales/create', [HeldSaleController::class, 'create'])->name('tenant.held-sales.create');
                Route::post('/held-sales', [HeldSaleController::class, 'store'])->name('tenant.held-sales.store');
                Route::post('/held-sales/{salesOrder}/cancel', [HeldSaleController::class, 'cancel'])->name('tenant.held-sales.cancel');
            });
        });
    });
