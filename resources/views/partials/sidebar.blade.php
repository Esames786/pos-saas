@php
    $isTenant = app()->bound('tenant');

    $planModuleKeys = [];
    $planCode = null;
    if ($isTenant) {
        $sub = app('tenant')->subscription;
        if ($sub) {
            $sub->loadMissing('plan.enabledModules');
            $planModuleKeys = $sub->plan ? $sub->plan->enabledModules->pluck('key')->all() : [];
            $planCode = $sub->plan?->code;
        }
    }
    $hasModule = fn (string $key) => in_array($key, $planModuleKeys, true);

    // ERP "Coming Soon" roadmap — shown only to enterprise/finance_erp/standard plans.
    $showErpComingSoon = in_array($planCode, ['enterprise', 'standard', 'finance_erp'], true);

    // Helper: true if current request matches ANY of the given patterns.
    $isIn = fn (string ...$patterns) => collect($patterns)->contains(fn ($p) => request()->is($p));
@endphp

{{-- ─────────────────────────────────────────────────────────────────────────
     Collapsible sidebar groups use li.submenu (jQuery subdrop/slideDown).
     All group <ul> start hidden; the JS init auto-expands whichever group
     contains an <a class="active"> child.  Active sub-items carry class="active"
     on BOTH the <li> and the <a> so the JS selector fires correctly.
     ───────────────────────────────────────────────────────────────────────── --}}

<div class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <a href="{{ url('/dashboard') }}" class="logo logo-normal">
            <img src="{{ asset('images/bingoo_new/bingoo-navbar-logo.webp') }}" alt="Bingoo">
        </a>
        <a href="{{ url('/dashboard') }}" class="logo logo-white">
            <img src="{{ asset('images/bingoo_new/bingoo-navbar-logo.webp') }}" alt="Bingoo">
        </a>
        <a href="{{ url('/dashboard') }}" class="logo-small">
            <img src="{{ asset('images/bingoo_new/bingoo-footer-icon.webp') }}" alt="Bingoo">
        </a>
        <a id="toggle_btn" href="javascript:void(0);">
            <i data-feather="chevrons-left" class="feather-16" width="16" height="16"></i>
        </a>
    </div>

    <div class="sidebar-inner slimscroll">
        <div id="sidebar-menu" class="sidebar-menu">
            <ul>

                {{-- ── MAIN (always visible, not collapsible) ─────────────────── --}}
                <li class="submenu-open">
                    <h6 class="submenu-hdr">{{ __('sidebar.main') }}</h6>
                    <ul>
                        @if(!$isTenant)
                            @can('central.dashboard')
                                @php $a = $isIn('dashboard'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/dashboard') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-layout-grid fs-16 me-2"></i>
                                        <span>{{ __('sidebar.central_dashboard') }}</span>
                                    </a>
                                </li>
                            @endcan
                        @endif
                        @if($isTenant)
                            @can('tenant.dashboard')
                                @php $a = $isIn('dashboard'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/dashboard') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-layout-grid fs-16 me-2"></i>
                                        <span>{{ __('sidebar.tenant_dashboard') }}</span>
                                    </a>
                                </li>
                            @endcan
                        @endif
                    </ul>
                </li>

                {{-- ── CENTRAL PLATFORM (static, not collapsible) ──────────────── --}}
                @if(!$isTenant)
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">{{ __('sidebar.platform') }}</h6>
                        <ul>
                            @can('central.tenants.index')
                                @php $a = $isIn('tenants*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/tenants') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-building-store fs-16 me-2"></i>
                                        <span>{{ __('sidebar.tenants') }}</span>
                                    </a>
                                </li>
                            @endcan
                            @can('central.plans.index')
                                @php $a = $isIn('plans*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/plans') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-credit-card fs-16 me-2"></i>
                                        <span>Plans</span>
                                    </a>
                                </li>
                            @endcan
                            @can('central.modules.index')
                                @php $a = $isIn('modules*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/modules') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-puzzle fs-16 me-2"></i>
                                        <span>Modules</span>
                                    </a>
                                </li>
                            @endcan
                            @can('central.invoices.index')
                                @php $a = $isIn('invoices*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/invoices') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-file-invoice fs-16 me-2"></i>
                                        <span>Invoices</span>
                                    </a>
                                </li>
                            @endcan
                            @can('central.subscription-requests.index')
                                @php $a = $isIn('subscription-requests*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/subscription-requests') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-arrow-up-circle fs-16 me-2"></i>
                                        <span>Upgrade Requests</span>
                                    </a>
                                </li>
                            @endcan
                            @can('central.routes.index')
                                @php $a = $isIn('routes*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/routes') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-route fs-16 me-2"></i>
                                        <span>Route Catalog</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endif

                {{-- ═══════════════════════════════════════════════════════════════
                     TENANT COLLAPSIBLE GROUPS
                     ═══════════════════════════════════════════════════════════════ --}}
                @if($isTenant)

                {{-- ── ADMINISTRATION ─────────────────────────────────────────── --}}
                @canany(['tenant.users.index', 'tenant.roles.index', 'tenant.billing.index'])
                <li class="submenu">
                    <a href="javascript:void(0);">
                        <i class="ti ti-settings fs-16 me-2"></i>
                        <span>Administration</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul style="display:none;">
                        @can('tenant.billing.index')
                            @php $a = $isIn('billing*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/billing') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-receipt fs-16 me-2"></i><span>Billing</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.users.index')
                            @php $a = $isIn('users*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/users') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-user-cog fs-16 me-2"></i><span>Users</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.roles.index')
                            @php $a = $isIn('roles*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/roles') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-shield-lock fs-16 me-2"></i><span>Roles &amp; Permissions</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>
                @endcanany

                {{-- ── OPERATIONS ──────────────────────────────────────────────── --}}
                <li class="submenu">
                    <a href="javascript:void(0);">
                        <i class="ti ti-building-store fs-16 me-2"></i>
                        <span>{{ __('sidebar.operations') }}</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul style="display:none;">
                        @can('tenant.branches.index')
                            @php $a = $isIn('branches*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/branches') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-building-store fs-16 me-2"></i><span>{{ __('sidebar.branches') }}</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.terminals.index')
                            @php $a = $isIn('terminals*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/terminals') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-device-desktop fs-16 me-2"></i><span>Terminals</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.shifts.index')
                            @php $a = $isIn('shifts*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/shifts') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-clock-hour-4 fs-16 me-2"></i><span>Shifts</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.daily-closings.index')
                            @php $a = $isIn('daily-closings*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/daily-closings') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-cash-banknote fs-16 me-2"></i><span>Daily Closing</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.currencies.index')
                            @php $a = $isIn('currencies*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/currencies') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-coins fs-16 me-2"></i><span>Currencies</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>

                {{-- ── INVENTORY ───────────────────────────────────────────────── --}}
                @if($hasModule('inventory') || $hasModule('stock_count'))
                @canany(['tenant.inventory.index','tenant.stock-adjustments.index','tenant.stock-transfers.index','tenant.stock-counts.index'])
                <li class="submenu">
                    <a href="javascript:void(0);">
                        <i class="ti ti-box fs-16 me-2"></i>
                        <span>Inventory</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul style="display:none;">
                        @can('tenant.inventory.index')
                            @php $a = $isIn('inventory'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/inventory') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-box fs-16 me-2"></i><span>Stock Balances</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.inventory.movements')
                            @php $a = $isIn('inventory/movements'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/inventory/movements') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-arrows-exchange fs-16 me-2"></i><span>Movements</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.inventory.batches')
                            @php $a = $isIn('inventory/batches'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/inventory/batches') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-stack fs-16 me-2"></i><span>Batches</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.inventory.low-stock')
                            @php $a = $isIn('inventory/low-stock'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/inventory/low-stock') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-alert-triangle fs-16 me-2"></i><span>Low Stock</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.inventory.expiry-alerts')
                            @php $a = $isIn('inventory/expiry-alerts'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/inventory/expiry-alerts') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-clock-exclamation fs-16 me-2"></i><span>Expiry Alerts</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.stock-adjustments.index')
                            @php $a = $isIn('stock-adjustments*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/stock-adjustments') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-adjustments-horizontal fs-16 me-2"></i><span>Adjustments</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.stock-transfers.index')
                            @php $a = $isIn('stock-transfers*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/stock-transfers') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-transfer fs-16 me-2"></i><span>Transfers</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.stock-counts.index')
                            @php $a = $isIn('stock-counts*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/stock-counts') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-clipboard-list fs-16 me-2"></i><span>Stock Counts</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>
                @endcanany
                @endif

                {{-- ── CATALOG ─────────────────────────────────────────────────── --}}
                @canany(['tenant.units.index', 'tenant.categories.index', 'tenant.products.index'])
                <li class="submenu">
                    <a href="javascript:void(0);">
                        <i class="ti ti-package fs-16 me-2"></i>
                        <span>Catalog</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul style="display:none;">
                        @can('tenant.products.index')
                            @php $a = $isIn('products*','product-variants*','product-barcodes*','products-bulk-import*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/products') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-package fs-16 me-2"></i><span>Products</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.categories.index')
                            @php $a = $isIn('categories*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/categories') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-category fs-16 me-2"></i><span>Categories</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.units.index')
                            @php $a = $isIn('units*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/units') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-ruler-measure fs-16 me-2"></i><span>Units of Measure</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>
                @endcanany

                {{-- ── PURCHASING ──────────────────────────────────────────────── --}}
                @if($hasModule('purchasing'))
                @canany(['tenant.suppliers.index','tenant.purchase-orders.index','tenant.goods-receipts.index','tenant.purchase-bills.index','tenant.supplier-payments.index'])
                <li class="submenu">
                    <a href="javascript:void(0);">
                        <i class="ti ti-truck-delivery fs-16 me-2"></i>
                        <span>Purchasing</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul style="display:none;">
                        @can('tenant.suppliers.index')
                            @php $a = $isIn('suppliers*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/suppliers') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-users fs-16 me-2"></i><span>Suppliers</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.purchase-orders.index')
                            @php $a = $isIn('purchase-orders*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/purchase-orders') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-clipboard-list fs-16 me-2"></i><span>Purchase Orders</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.goods-receipts.index')
                            @php $a = $isIn('goods-receipts*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/goods-receipts') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-truck-delivery fs-16 me-2"></i><span>GRN</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.purchase-bills.index')
                            @php $a = $isIn('purchase-bills*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/purchase-bills') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-file-invoice fs-16 me-2"></i><span>Purchase Bills</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.supplier-payments.index')
                            @php $a = $isIn('supplier-payments*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/supplier-payments') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-cash fs-16 me-2"></i><span>Supplier Payments</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>
                @endcanany
                @endif

                {{-- ── SALES ───────────────────────────────────────────────────── --}}
                @canany(['tenant.pos.index','tenant.sales-orders.index','tenant.customers.index','tenant.payment-methods.index','tenant.sales-ledger.index','tenant.sales-returns.index'])
                <li class="submenu">
                    <a href="javascript:void(0);">
                        <i class="ti ti-device-tablet fs-16 me-2"></i>
                        <span>Sales</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul style="display:none;">
                        @can('tenant.pos.index')
                            @php $a = $isIn('pos'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/pos') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-device-tablet fs-16 me-2"></i><span>POS</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.sales-orders.index')
                            @php $a = $isIn('sales-orders*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/sales-orders') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-receipt fs-16 me-2"></i><span>Sales Orders</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.sales-returns.index')
                            @php $a = $isIn('sales-returns*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/sales-returns') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-arrow-back-up fs-16 me-2"></i><span>Sales Returns</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.customers.index')
                            @php $a = $isIn('customers*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/customers') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-users fs-16 me-2"></i><span>Customers</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.payment-methods.index')
                            @php $a = $isIn('payment-methods*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/payment-methods') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-credit-card fs-16 me-2"></i><span>Payment Methods</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.sales-ledger.index')
                            @php $a = $isIn('sales-ledger*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/sales-ledger') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-report-money fs-16 me-2"></i><span>Sales Ledger</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>
                @endcanany

                {{-- ── RESTAURANT ──────────────────────────────────────────────── --}}
                @if($hasModule('restaurant') || $hasModule('kitchen_display'))
                @canany(['tenant.restaurant.board','tenant.restaurant.floors.index','tenant.restaurant.tables.index','tenant.restaurant.waiters.index','tenant.held-sales.index','tenant.kitchen-display.index'])
                <li class="submenu">
                    <a href="javascript:void(0);">
                        <i class="ti ti-tools-kitchen-2 fs-16 me-2"></i>
                        <span>Restaurant</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul style="display:none;">
                        @can('tenant.restaurant.board')
                            @php $a = $isIn('restaurant/board'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/restaurant/board') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-layout-board-split fs-16 me-2"></i><span>Table Board</span>
                                </a>
                            </li>
                        @endcan
                        @if($hasModule('kitchen_display'))
                        @can('tenant.kitchen-display.index')
                            @php $a = $isIn('kitchen-display*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/kitchen-display') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-tools-kitchen-2 fs-16 me-2"></i><span>Kitchen Display</span>
                                </a>
                            </li>
                        @endcan
                        @endif
                        @can('tenant.restaurant.floors.index')
                            @php $a = $isIn('restaurant/floors*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/restaurant/floors') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-building fs-16 me-2"></i><span>Floors</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.restaurant.tables.index')
                            @php $a = $isIn('restaurant/tables*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/restaurant/tables') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-armchair fs-16 me-2"></i><span>Tables</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.restaurant.waiters.index')
                            @php $a = $isIn('restaurant/waiters*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/restaurant/waiters') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-user-check fs-16 me-2"></i><span>Waiters</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.held-sales.index')
                            @php $a = $isIn('held-sales*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/held-sales') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-player-pause fs-16 me-2"></i><span>Held Sales</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>
                @endcanany
                @endif

                {{-- ── KITCHEN INVENTORY ───────────────────────────────────────── --}}
                @if($hasModule('kitchen_inventory'))
                @canany(['tenant.unit-conversions.index','tenant.recipes.index','tenant.kitchen.productions.index','tenant.kitchen.wastages.index'])
                <li class="submenu">
                    <a href="javascript:void(0);">
                        <i class="ti ti-clipboard-list fs-16 me-2"></i>
                        <span>Kitchen Inventory</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul style="display:none;">
                        @can('tenant.recipes.index')
                            @php $a = $isIn('recipes*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/recipes') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-clipboard-list fs-16 me-2"></i><span>Recipes / BOM</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.unit-conversions.index')
                            @php $a = $isIn('unit-conversions*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/unit-conversions') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-arrows-exchange fs-16 me-2"></i><span>Unit Conversions</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.kitchen.productions.index')
                            @php $a = $isIn('kitchen/productions*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/kitchen/productions') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-tools-kitchen-2 fs-16 me-2"></i><span>Productions</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.kitchen.wastages.index')
                            @php $a = $isIn('kitchen/wastages*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/kitchen/wastages') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-trash fs-16 me-2"></i><span>Wastages</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>
                @endcanany
                @endif

                {{-- ── FINANCE ─────────────────────────────────────────────────── --}}
                @if($hasModule('finance'))
                @canany(['tenant.finance.accounts.index','tenant.finance.cash-bank-accounts.index','tenant.finance.expense-categories.index','tenant.finance.expenses.index','tenant.finance.customer-payments.index','tenant.finance.opening-balances.index','tenant.finance.journal-entries.index','tenant.finance.general-ledger.index','tenant.finance.trial-balance.index','tenant.finance.profit-loss.index','tenant.finance.branch-profit-loss.index','tenant.finance.balance-sheet.index','tenant.finance.export.index'])
                <li class="submenu">
                    <a href="javascript:void(0);">
                        <i class="ti ti-calculator fs-16 me-2"></i>
                        <span>Finance</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul style="display:none;">
                        @can('tenant.finance.accounts.index')
                            @php $a = $isIn('finance/accounts*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/accounts') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-book-2 fs-16 me-2"></i><span>Chart of Accounts</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.finance.cash-bank-accounts.index')
                            @php $a = $isIn('finance/cash-bank-accounts*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/cash-bank-accounts') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-cash fs-16 me-2"></i><span>Cash &amp; Bank</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.finance.expense-categories.index')
                            @php $a = $isIn('finance/expense-categories*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/expense-categories') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-tags fs-16 me-2"></i><span>Expense Categories</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.finance.expenses.index')
                            @php $a = $isIn('finance/expenses*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/expenses') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-receipt-2 fs-16 me-2"></i><span>Expenses</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.finance.customer-payments.index')
                            @php $a = $isIn('finance/customer-payments*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/customer-payments') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-coin fs-16 me-2"></i><span>Customer Payments</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.finance.opening-balances.index')
                            @php $a = $isIn('finance/opening-balances*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/opening-balances') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-adjustments-dollar fs-16 me-2"></i><span>Opening Balances</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.finance.journal-entries.index')
                            @php $a = $isIn('finance/journal-entries*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/journal-entries') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-notebook fs-16 me-2"></i><span>Journal Entries</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.finance.general-ledger.index')
                            @php $a = $isIn('finance/general-ledger*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/general-ledger') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-book fs-16 me-2"></i><span>General Ledger</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.finance.trial-balance.index')
                            @php $a = $isIn('finance/trial-balance*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/trial-balance') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-scale fs-16 me-2"></i><span>Trial Balance</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.finance.profit-loss.index')
                            @php $a = $isIn('finance/profit-loss*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/profit-loss') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-chart-infographic fs-16 me-2"></i><span>Profit &amp; Loss</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.finance.branch-profit-loss.index')
                            @php $a = $isIn('finance/branch-profit-loss*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/branch-profit-loss') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-chart-bar fs-16 me-2"></i><span>Branch-wise P&amp;L</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.finance.balance-sheet.index')
                            @php $a = $isIn('finance/balance-sheet*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/balance-sheet') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-report-money fs-16 me-2"></i><span>Balance Sheet</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.finance.export.index')
                            @php $a = $isIn('finance/export*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/finance/export') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-file-export fs-16 me-2"></i><span>Accounting Export</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>
                @endcanany
                @endif

                {{-- ── ERP EXTENSIONS — Coming Soon ───────────────────────────── --}}
                @if($showErpComingSoon)
                    @can('tenant.finance.bank-reconciliation.index')
                    <li class="submenu">
                        <a href="javascript:void(0);">
                            <i class="ti ti-arrows-left-right fs-16 me-2"></i>
                            <span>ERP Extensions <span class="badge bg-warning text-dark" style="font-size:.6rem;padding:1px 4px;vertical-align:middle;">Soon</span></span>
                            <span class="menu-arrow"></span>
                        </a>
                        <ul style="display:none;">
                            @can('tenant.finance.bank-reconciliation.index')
                                @php $a = $isIn('finance/bank-reconciliation*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/finance/bank-reconciliation') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-arrows-left-right fs-16 me-2"></i>
                                        <span>Bank Reconciliation</span>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;">Soon</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.quotations.index')
                                @php $a = $isIn('quotations*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/quotations') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-file-dollar fs-16 me-2"></i>
                                        <span>Quotations</span>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;">Soon</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.purchase-requisitions.index')
                                @php $a = $isIn('purchase-requisitions*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/purchase-requisitions') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-clipboard-text fs-16 me-2"></i>
                                        <span>Purchase Requisitions</span>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;">Soon</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.purchase-returns.index')
                                @php $a = $isIn('purchase-returns*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/purchase-returns') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-arrow-back-up fs-16 me-2"></i>
                                        <span>Purchase Returns</span>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;">Soon</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                    @endcan

                    {{-- ── MANUFACTURING — live modules (Customers, Production Orders, BOM) + Coming Soon items ── --}}
                    @canany(['tenant.manufacturing.customers.index','tenant.manufacturing.bom.index','tenant.manufacturing.material-requisitions.index','tenant.manufacturing.production-orders.index','tenant.manufacturing.wip.index','tenant.manufacturing.finished-goods.index','tenant.manufacturing.scrap.index','tenant.manufacturing.rejections.index','tenant.manufacturing.consumption.index','tenant.manufacturing.reports.index'])
                    <li class="submenu">
                        <a href="javascript:void(0);">
                            <i class="ti ti-settings-cog fs-16 me-2"></i>
                            <span>Manufacturing</span>
                            <span class="menu-arrow"></span>
                        </a>
                        <ul style="display:none;">
                            {{-- Manufacturing Customers: real CRUD (MANUF-1) — no Soon badge --}}
                            @can('tenant.manufacturing.customers.index')
                                @php $a = $isIn('manufacturing/customers*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/manufacturing/customers') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-users-group fs-16 me-2"></i>
                                        <span>Manufacturing Customers</span>
                                    </a>
                                </li>
                            @endcan
                            {{-- Production Orders: real CRUD (MANUF-2) — no Soon badge --}}
                            @can('tenant.manufacturing.production-orders.index')
                                @php $a = $isIn('manufacturing/production-orders*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/manufacturing/production-orders') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-clipboard-check fs-16 me-2"></i>
                                        <span>Production Orders</span>
                                    </a>
                                </li>
                            @endcan
                            {{-- Bill of Materials: real CRUD (MANUF-3) — no Soon badge --}}
                            @can('tenant.manufacturing.bom.index')
                                @php $a = $isIn('manufacturing/bom*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/manufacturing/bom') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-sitemap fs-16 me-2"></i>
                                        <span>Bill of Materials</span>
                                    </a>
                                </li>
                            @endcan
                            {{-- Material Requisition (MRC): real CRUD (MANUF-4) — no Soon badge --}}
                            @can('tenant.manufacturing.material-requisitions.index')
                                @php $a = $isIn('manufacturing/material-requisitions*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/manufacturing/material-requisitions') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-clipboard-list fs-16 me-2"></i>
                                        <span>Material Requisition (MRC)</span>
                                    </a>
                                </li>
                            @endcan
                            {{-- Work in Process (WIP): real CRUD (MANUF-5) — no Soon badge --}}
                            @can('tenant.manufacturing.wip.index')
                                @php $a = $isIn('manufacturing/wip*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/manufacturing/wip') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-progress fs-16 me-2"></i>
                                        <span>Work in Process (WIP)</span>
                                    </a>
                                </li>
                            @endcan
                            {{-- Finished Goods: real CRUD (MANUF-6) — no Soon badge --}}
                            @can('tenant.manufacturing.finished-goods.index')
                                @php $a = $isIn('manufacturing/finished-goods*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/manufacturing/finished-goods') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-package fs-16 me-2"></i>
                                        <span>Finished Goods</span>
                                    </a>
                                </li>
                            @endcan
                            {{-- Scrap / Hard Waste: real CRUD (MANUF-7) — no Soon badge --}}
                            @can('tenant.manufacturing.scrap.index')
                                @php $a = $isIn('manufacturing/scrap*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/manufacturing/scrap') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-trash fs-16 me-2"></i>
                                        <span>Scrap / Hard Waste</span>
                                    </a>
                                </li>
                            @endcan
                            {{-- Rejections: real CRUD (MANUF-8) — no Soon badge --}}
                            @can('tenant.manufacturing.rejections.index')
                                @php $a = $isIn('manufacturing/rejections*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/manufacturing/rejections') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-ban fs-16 me-2"></i>
                                        <span>Rejections</span>
                                    </a>
                                </li>
                            @endcan
                            {{-- Consumption: real CRUD (MANUF-9) — no Soon badge --}}
                            @can('tenant.manufacturing.consumption.index')
                                @php $a = $isIn('manufacturing/consumption*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/manufacturing/consumption') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-flask fs-16 me-2"></i>
                                        <span>Consumption</span>
                                    </a>
                                </li>
                            @endcan
                            {{-- Production Reports: read-only analytics (MANUF-10) — no Soon badge --}}
                            @can('tenant.manufacturing.reports.index')
                                @php $a = $isIn('manufacturing/reports*'); @endphp
                                <li class="{{ $a ? 'active' : '' }}">
                                    <a href="{{ url('/manufacturing/reports') }}" class="{{ $a ? 'active' : '' }}">
                                        <i class="ti ti-chart-bar fs-16 me-2"></i>
                                        <span>Production Reports</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany
                @endif

                {{-- ── REPORTS ─────────────────────────────────────────────────── --}}
                @canany(['tenant.reports.sales.summary','tenant.reports.shifts','tenant.reports.inventory.valuation','tenant.reports.purchases.payables','tenant.reports.restaurant.tables','tenant.reports.kitchen.recipe-consumption','tenant.reports.audit.manager-approvals','tenant.reports.printing.jobs'])
                <li class="submenu">
                    <a href="javascript:void(0);">
                        <i class="ti ti-chart-bar fs-16 me-2"></i>
                        <span>Reports</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul style="display:none;">
                        @can('tenant.reports.sales.summary')
                            @php $a = $isIn('reports/sales*') && !$isIn('reports/sales/receivables'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/reports/sales/summary') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-report-money fs-16 me-2"></i><span>Sales Reports</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.reports.shifts')
                            @php $a = $isIn('reports/shifts*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/reports/shifts') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-clock-dollar fs-16 me-2"></i><span>Shift Reports</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.reports.inventory.valuation')
                            @php $a = $isIn('reports/inventory*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/reports/inventory/valuation') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-box fs-16 me-2"></i><span>Inventory Reports</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.reports.purchases.payables')
                            @php $a = $isIn('reports/purchases*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/reports/purchases/payables') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-file-invoice fs-16 me-2"></i><span>Purchase Reports</span>
                                </a>
                            </li>
                        @endcan
                        @if($hasModule('finance'))
                        @can('tenant.reports.sales.receivables')
                            @php $a = $isIn('reports/sales/receivables'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/reports/sales/receivables') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-cash-banknote fs-16 me-2"></i><span>Receivables Aging</span>
                                </a>
                            </li>
                        @endcan
                        @endif
                        @can('tenant.reports.restaurant.tables')
                            @php $a = $isIn('reports/restaurant*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/reports/restaurant/tables') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-utensils fs-16 me-2"></i><span>Restaurant Reports</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.reports.kitchen.recipe-consumption')
                            @php $a = $isIn('reports/kitchen*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/reports/kitchen/recipe-consumption') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-pot fs-16 me-2"></i><span>Kitchen Reports</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.reports.audit.manager-approvals')
                            @php $a = $isIn('reports/audit*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/reports/audit/manager-approvals') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-shield-check fs-16 me-2"></i><span>Audit Reports</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.reports.printing.jobs')
                            @php $a = $isIn('reports/printing*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/reports/printing/jobs') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-printer fs-16 me-2"></i><span>Print Reports</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>
                @endcanany

                {{-- ── SALES CONTROLS ──────────────────────────────────────────── --}}
                @if($hasModule('sales_controls'))
                @canany(['tenant.promotions.index','tenant.service-charge-settings.index','tenant.void-reasons.index'])
                <li class="submenu">
                    <a href="javascript:void(0);">
                        <i class="ti ti-discount-2 fs-16 me-2"></i>
                        <span>Sales Controls</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul style="display:none;">
                        @can('tenant.promotions.index')
                            @php $a = $isIn('promotions*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/promotions') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-discount-2 fs-16 me-2"></i><span>Promotions</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.service-charge-settings.index')
                            @php $a = $isIn('service-charge-settings*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/service-charge-settings') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-percentage fs-16 me-2"></i><span>Service Charge</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.void-reasons.index')
                            @php $a = $isIn('void-reasons*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/void-reasons') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-ban fs-16 me-2"></i><span>Void Reasons</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>
                @endcanany
                @endif

                {{-- ── PRINTING ─────────────────────────────────────────────────── --}}
                @canany(['tenant.printing.printers.index','tenant.printing.category-mappings.index','tenant.printing.layouts.index','tenant.printing.jobs.index','tenant.print-agents.index'])
                <li class="submenu">
                    <a href="javascript:void(0);">
                        <i class="ti ti-printer fs-16 me-2"></i>
                        <span>Printing</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul style="display:none;">
                        @can('tenant.printing.printers.index')
                            @php $a = $isIn('printing/printers*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/printing/printers') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-printer fs-16 me-2"></i><span>Printers</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.printing.category-mappings.index')
                            @php $a = $isIn('printing/category-mappings*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/printing/category-mappings') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-map-2 fs-16 me-2"></i><span>KOT Routing</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.printing.layouts.index')
                            @php $a = $isIn('printing/layouts*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/printing/layouts') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-layout fs-16 me-2"></i><span>Layouts</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.printing.jobs.index')
                            @php $a = $isIn('printing/jobs*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/printing/jobs') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-file-text fs-16 me-2"></i><span>Print Jobs</span>
                                </a>
                            </li>
                        @endcan
                        @can('tenant.print-agents.index')
                            @php $a = $isIn('print/agents*'); @endphp
                            <li class="{{ $a ? 'active' : '' }}">
                                <a href="{{ url('/print/agents') }}" class="{{ $a ? 'active' : '' }}">
                                    <i class="ti ti-device-desktop-cog fs-16 me-2"></i><span>Print Agents</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>
                @endcanany

                @endif {{-- end $isTenant --}}

            </ul>
        </div>
    </div>
</div>
