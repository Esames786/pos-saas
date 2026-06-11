@php
    $isTenant = app()->bound('tenant');
@endphp

<div class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <a href="{{ url('/dashboard') }}" class="logo logo-normal">
            <img src="{{ asset('assets/img/logo.svg') }}" alt="Logo">
        </a>
        <a href="{{ url('/dashboard') }}" class="logo logo-white">
            <img src="{{ asset('assets/img/logo-white.svg') }}" alt="Logo">
        </a>
        <a href="{{ url('/dashboard') }}" class="logo-small">
            <img src="{{ asset('assets/img/logo-small.png') }}" alt="Logo">
        </a>

        <a id="toggle_btn" href="javascript:void(0);">
            <i data-feather="chevrons-left" class="feather-16" width="16" height="16"></i>
        </a>
    </div>

    <div class="sidebar-inner slimscroll">
        <div id="sidebar-menu" class="sidebar-menu">
            <ul>
                {{-- Main section --}}
                <li class="submenu-open">
                    <h6 class="submenu-hdr">{{ __('sidebar.main') }}</h6>
                    <ul>
                        @if(!$isTenant)
                            @can('central.dashboard')
                                <li class="{{ request()->is('dashboard') ? 'active' : '' }}">
                                    <a href="{{ url('/dashboard') }}">
                                        <i class="ti ti-layout-grid fs-16 me-2"></i>
                                        <span>{{ __('sidebar.central_dashboard') }}</span>
                                    </a>
                                </li>
                            @endcan
                        @endif

                        @if($isTenant)
                            @can('tenant.dashboard')
                                <li class="{{ request()->is('dashboard') ? 'active' : '' }}">
                                    <a href="{{ url('/dashboard') }}">
                                        <i class="ti ti-layout-grid fs-16 me-2"></i>
                                        <span>{{ __('sidebar.tenant_dashboard') }}</span>
                                    </a>
                                </li>
                            @endcan
                        @endif
                    </ul>
                </li>

                {{-- Central platform section --}}
                @if(!$isTenant)
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">{{ __('sidebar.platform') }}</h6>
                        <ul>
                            @can('central.tenants.index')
                                <li class="{{ request()->is('tenants*') ? 'active' : '' }}">
                                    <a href="{{ url('/tenants') }}">
                                        <i class="ti ti-building-store fs-16 me-2"></i>
                                        <span>{{ __('sidebar.tenants') }}</span>
                                    </a>
                                </li>
                            @endcan

                            @can('central.routes.index')
                                <li class="{{ request()->is('routes*') ? 'active' : '' }}">
                                    <a href="{{ url('/routes') }}">
                                        <i class="ti ti-route fs-16 me-2"></i>
                                        <span>Route Catalog</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endif

                {{-- Tenant operations section --}}
                @if($isTenant)
                    {{-- Administration section --}}
                    @canany(['tenant.users.index', 'tenant.roles.index'])
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">Administration</h6>
                        <ul>
                            @can('tenant.users.index')
                                <li class="{{ request()->is('users*') ? 'active' : '' }}">
                                    <a href="{{ url('/users') }}">
                                        <i class="ti ti-user-cog fs-16 me-2"></i>
                                        <span>Users</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.roles.index')
                                <li class="{{ request()->is('roles*') ? 'active' : '' }}">
                                    <a href="{{ url('/roles') }}">
                                        <i class="ti ti-shield-lock fs-16 me-2"></i>
                                        <span>Roles &amp; Permissions</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany

                    <li class="submenu-open">
                        <h6 class="submenu-hdr">{{ __('sidebar.operations') }}</h6>
                        <ul>
                            @can('tenant.branches.index')
                                <li class="{{ request()->is('branches*') ? 'active' : '' }}">
                                    <a href="{{ url('/branches') }}">
                                        <i class="ti ti-building-store fs-16 me-2"></i>
                                        <span>{{ __('sidebar.branches') }}</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.terminals.index')
                                <li class="{{ request()->is('terminals*') ? 'active' : '' }}">
                                    <a href="{{ url('/terminals') }}">
                                        <i class="ti ti-device-desktop fs-16 me-2"></i>
                                        <span>Terminals</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.shifts.index')
                                <li class="{{ request()->is('shifts*') ? 'active' : '' }}">
                                    <a href="{{ url('/shifts') }}">
                                        <i class="ti ti-clock-hour-4 fs-16 me-2"></i>
                                        <span>Shifts</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.daily-closings.index')
                                <li class="{{ request()->is('daily-closings*') ? 'active' : '' }}">
                                    <a href="{{ url('/daily-closings') }}">
                                        <i class="ti ti-cash-banknote fs-16 me-2"></i>
                                        <span>Daily Closing</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.currencies.index')
                                <li class="{{ request()->is('currencies*') ? 'active' : '' }}">
                                    <a href="{{ url('/currencies') }}">
                                        <i class="ti ti-coins fs-16 me-2"></i>
                                        <span>Currencies</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>

                    {{-- Inventory section --}}
                    @canany([
                        'tenant.inventory.index',
                        'tenant.stock-adjustments.index',
                        'tenant.stock-transfers.index',
                        'tenant.stock-counts.index',
                    ])
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">Inventory</h6>
                        <ul>
                            @can('tenant.inventory.index')
                                <li class="{{ request()->is('inventory') ? 'active' : '' }}">
                                    <a href="{{ url('/inventory') }}">
                                        <i class="ti ti-box fs-16 me-2"></i>
                                        <span>Stock Balances</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.inventory.movements')
                                <li class="{{ request()->is('inventory/movements') ? 'active' : '' }}">
                                    <a href="{{ url('/inventory/movements') }}">
                                        <i class="ti ti-arrows-exchange fs-16 me-2"></i>
                                        <span>Movements</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.inventory.batches')
                                <li class="{{ request()->is('inventory/batches') ? 'active' : '' }}">
                                    <a href="{{ url('/inventory/batches') }}">
                                        <i class="ti ti-stack fs-16 me-2"></i>
                                        <span>Batches</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.inventory.low-stock')
                                <li class="{{ request()->is('inventory/low-stock') ? 'active' : '' }}">
                                    <a href="{{ url('/inventory/low-stock') }}">
                                        <i class="ti ti-alert-triangle fs-16 me-2"></i>
                                        <span>Low Stock</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.inventory.expiry-alerts')
                                <li class="{{ request()->is('inventory/expiry-alerts') ? 'active' : '' }}">
                                    <a href="{{ url('/inventory/expiry-alerts') }}">
                                        <i class="ti ti-clock-exclamation fs-16 me-2"></i>
                                        <span>Expiry Alerts</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.stock-adjustments.index')
                                <li class="{{ request()->is('stock-adjustments*') ? 'active' : '' }}">
                                    <a href="{{ url('/stock-adjustments') }}">
                                        <i class="ti ti-adjustments-horizontal fs-16 me-2"></i>
                                        <span>Adjustments</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.stock-transfers.index')
                                <li class="{{ request()->is('stock-transfers*') ? 'active' : '' }}">
                                    <a href="{{ url('/stock-transfers') }}">
                                        <i class="ti ti-transfer fs-16 me-2"></i>
                                        <span>Transfers</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.stock-counts.index')
                                <li class="{{ request()->is('stock-counts*') ? 'active' : '' }}">
                                    <a href="{{ url('/stock-counts') }}">
                                        <i class="ti ti-clipboard-list fs-16 me-2"></i>
                                        <span>Stock Counts</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany

                    {{-- Sales section --}}
                    @canany([
                        'tenant.pos.index',
                        'tenant.sales-orders.index',
                        'tenant.customers.index',
                        'tenant.payment-methods.index',
                        'tenant.sales-ledger.index',
                        'tenant.sales-returns.index',
                    ])
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">Sales</h6>
                        <ul>
                            @can('tenant.pos.index')
                                <li class="{{ request()->is('pos') ? 'active' : '' }}">
                                    <a href="{{ url('/pos') }}">
                                        <i class="ti ti-device-tablet fs-16 me-2" aria-hidden="true"></i>
                                        <span>POS</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.sales-orders.index')
                                <li class="{{ request()->is('sales-orders*') ? 'active' : '' }}">
                                    <a href="{{ url('/sales-orders') }}">
                                        <i class="ti ti-receipt fs-16 me-2" aria-hidden="true"></i>
                                        <span>Sales Orders</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.customers.index')
                                <li class="{{ request()->is('customers*') ? 'active' : '' }}">
                                    <a href="{{ url('/customers') }}">
                                        <i class="ti ti-users fs-16 me-2" aria-hidden="true"></i>
                                        <span>Customers</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.payment-methods.index')
                                <li class="{{ request()->is('payment-methods*') ? 'active' : '' }}">
                                    <a href="{{ url('/payment-methods') }}">
                                        <i class="ti ti-credit-card fs-16 me-2" aria-hidden="true"></i>
                                        <span>Payment Methods</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.sales-ledger.index')
                                <li class="{{ request()->is('sales-ledger*') ? 'active' : '' }}">
                                    <a href="{{ url('/sales-ledger') }}">
                                        <i class="ti ti-report-money fs-16 me-2" aria-hidden="true"></i>
                                        <span>Sales Ledger</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.sales-returns.index')
                                <li class="{{ request()->is('sales-returns*') ? 'active' : '' }}">
                                    <a href="{{ url('/sales-returns') }}">
                                        <i class="ti ti-arrow-back-up fs-16 me-2" aria-hidden="true"></i>
                                        <span>Sales Returns</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany

                    {{-- Purchasing section --}}
                    @canany([
                        'tenant.suppliers.index',
                        'tenant.purchase-orders.index',
                        'tenant.goods-receipts.index',
                        'tenant.purchase-bills.index',
                        'tenant.supplier-payments.index',
                    ])
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">Purchasing</h6>
                        <ul>
                            @can('tenant.suppliers.index')
                                <li class="{{ request()->is('suppliers*') ? 'active' : '' }}">
                                    <a href="{{ url('/suppliers') }}">
                                        <i class="ti ti-users fs-16 me-2" aria-hidden="true"></i>
                                        <span>Suppliers</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.purchase-orders.index')
                                <li class="{{ request()->is('purchase-orders*') ? 'active' : '' }}">
                                    <a href="{{ url('/purchase-orders') }}">
                                        <i class="ti ti-clipboard-list fs-16 me-2" aria-hidden="true"></i>
                                        <span>Purchase Orders</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.goods-receipts.index')
                                <li class="{{ request()->is('goods-receipts*') ? 'active' : '' }}">
                                    <a href="{{ url('/goods-receipts') }}">
                                        <i class="ti ti-truck-delivery fs-16 me-2" aria-hidden="true"></i>
                                        <span>GRN</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.purchase-bills.index')
                                <li class="{{ request()->is('purchase-bills*') ? 'active' : '' }}">
                                    <a href="{{ url('/purchase-bills') }}">
                                        <i class="ti ti-file-invoice fs-16 me-2" aria-hidden="true"></i>
                                        <span>Purchase Bills</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.supplier-payments.index')
                                <li class="{{ request()->is('supplier-payments*') ? 'active' : '' }}">
                                    <a href="{{ url('/supplier-payments') }}">
                                        <i class="ti ti-cash fs-16 me-2" aria-hidden="true"></i>
                                        <span>Supplier Payments</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany

                    {{-- Restaurant section --}}
                    @canany([
                        'tenant.restaurant.board',
                        'tenant.restaurant.floors.index',
                        'tenant.restaurant.tables.index',
                        'tenant.restaurant.waiters.index',
                        'tenant.held-sales.index',
                        'tenant.kitchen-display.index',
                    ])
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">Restaurant</h6>
                        <ul>
                            @can('tenant.restaurant.board')
                                <li class="{{ request()->is('restaurant/board') ? 'active' : '' }}">
                                    <a href="{{ url('/restaurant/board') }}">
                                        <i class="ti ti-layout-board-split fs-16 me-2" aria-hidden="true"></i>
                                        <span>Table Board</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.kitchen-display.index')
                                <li class="{{ request()->is('kitchen-display*') ? 'active' : '' }}">
                                    <a href="{{ url('/kitchen-display') }}">
                                        <i class="ti ti-tools-kitchen-2 fs-16 me-2" aria-hidden="true"></i>
                                        <span>Kitchen Display</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.restaurant.floors.index')
                                <li class="{{ request()->is('restaurant/floors*') ? 'active' : '' }}">
                                    <a href="{{ url('/restaurant/floors') }}">
                                        <i class="ti ti-building fs-16 me-2" aria-hidden="true"></i>
                                        <span>Floors</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.restaurant.tables.index')
                                <li class="{{ request()->is('restaurant/tables*') ? 'active' : '' }}">
                                    <a href="{{ url('/restaurant/tables') }}">
                                        <i class="ti ti-armchair fs-16 me-2" aria-hidden="true"></i>
                                        <span>Tables</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.restaurant.waiters.index')
                                <li class="{{ request()->is('restaurant/waiters*') ? 'active' : '' }}">
                                    <a href="{{ url('/restaurant/waiters') }}">
                                        <i class="ti ti-user-check fs-16 me-2" aria-hidden="true"></i>
                                        <span>Waiters</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.held-sales.index')
                                <li class="{{ request()->is('held-sales*') ? 'active' : '' }}">
                                    <a href="{{ url('/held-sales') }}">
                                        <i class="ti ti-player-pause fs-16 me-2" aria-hidden="true"></i>
                                        <span>Held Sales</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany

                    {{-- Kitchen Inventory section --}}
                    @canany(['tenant.unit-conversions.index', 'tenant.recipes.index', 'tenant.kitchen.productions.index', 'tenant.kitchen.wastages.index'])
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">Kitchen Inventory</h6>
                        <ul>
                            @can('tenant.recipes.index')
                                <li class="{{ request()->is('recipes*') ? 'active' : '' }}">
                                    <a href="{{ url('/recipes') }}">
                                        <i class="ti ti-clipboard-list fs-16 me-2"></i>
                                        <span>Recipes / BOM</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.unit-conversions.index')
                                <li class="{{ request()->is('unit-conversions*') ? 'active' : '' }}">
                                    <a href="{{ url('/unit-conversions') }}">
                                        <i class="ti ti-arrows-exchange fs-16 me-2"></i>
                                        <span>Unit Conversions</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.kitchen.productions.index')
                                <li class="{{ request()->is('kitchen/productions*') ? 'active' : '' }}">
                                    <a href="{{ url('/kitchen/productions') }}">
                                        <i class="ti ti-tools-kitchen-2 fs-16 me-2"></i>
                                        <span>Productions</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.kitchen.wastages.index')
                                <li class="{{ request()->is('kitchen/wastages*') ? 'active' : '' }}">
                                    <a href="{{ url('/kitchen/wastages') }}">
                                        <i class="ti ti-trash fs-16 me-2"></i>
                                        <span>Wastages</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany

                    {{-- Printing section --}}
                    @canany(['tenant.printing.printers.index', 'tenant.printing.category-mappings.index', 'tenant.printing.layouts.index', 'tenant.printing.jobs.index', 'tenant.print-agents.index'])
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">Printing</h6>
                        <ul>
                            @can('tenant.printing.printers.index')
                                <li class="{{ request()->is('printing/printers*') ? 'active' : '' }}">
                                    <a href="{{ url('/printing/printers') }}">
                                        <i class="ti ti-printer fs-16 me-2"></i>
                                        <span>Printers</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.printing.category-mappings.index')
                                <li class="{{ request()->is('printing/category-mappings*') ? 'active' : '' }}">
                                    <a href="{{ url('/printing/category-mappings') }}">
                                        <i class="ti ti-map-2 fs-16 me-2"></i>
                                        <span>KOT Routing</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.printing.layouts.index')
                                <li class="{{ request()->is('printing/layouts*') ? 'active' : '' }}">
                                    <a href="{{ url('/printing/layouts') }}">
                                        <i class="ti ti-layout fs-16 me-2"></i>
                                        <span>Layouts</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.printing.jobs.index')
                                <li class="{{ request()->is('printing/jobs*') ? 'active' : '' }}">
                                    <a href="{{ url('/printing/jobs') }}">
                                        <i class="ti ti-file-text fs-16 me-2"></i>
                                        <span>Print Jobs</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.print-agents.index')
                                <li class="{{ request()->is('print/agents*') ? 'active' : '' }}">
                                    <a href="{{ url('/print/agents') }}">
                                        <i class="ti ti-device-desktop-cog fs-16 me-2"></i>
                                        <span>Print Agents</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany

                    {{-- Reports section --}}
                    @canany(['tenant.reports.sales.summary', 'tenant.reports.shifts', 'tenant.reports.inventory.valuation', 'tenant.reports.purchases.payables', 'tenant.reports.restaurant.tables', 'tenant.reports.kitchen.recipe-consumption', 'tenant.reports.audit.manager-approvals', 'tenant.reports.printing.jobs'])
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">Reports</h6>
                        <ul>
                            @can('tenant.reports.sales.summary')
                                <li class="{{ request()->is('reports/sales*') ? 'active' : '' }}">
                                    <a href="{{ url('/reports/sales/summary') }}">
                                        <i class="ti ti-report-money fs-16 me-2" aria-hidden="true"></i>
                                        <span>Sales Reports</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.reports.shifts')
                                <li class="{{ request()->is('reports/shifts*') ? 'active' : '' }}">
                                    <a href="{{ url('/reports/shifts') }}">
                                        <i class="ti ti-clock-dollar fs-16 me-2" aria-hidden="true"></i>
                                        <span>Shift Reports</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.reports.inventory.valuation')
                                <li class="{{ request()->is('reports/inventory*') ? 'active' : '' }}">
                                    <a href="{{ url('/reports/inventory/valuation') }}">
                                        <i class="ti ti-box fs-16 me-2" aria-hidden="true"></i>
                                        <span>Inventory Reports</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.reports.purchases.payables')
                                <li class="{{ request()->is('reports/purchases*') ? 'active' : '' }}">
                                    <a href="{{ url('/reports/purchases/payables') }}">
                                        <i class="ti ti-file-invoice fs-16 me-2" aria-hidden="true"></i>
                                        <span>Purchase Reports</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.reports.restaurant.tables')
                                <li class="{{ request()->is('reports/restaurant*') ? 'active' : '' }}">
                                    <a href="{{ url('/reports/restaurant/tables') }}">
                                        <i class="ti ti-utensils fs-16 me-2" aria-hidden="true"></i>
                                        <span>Restaurant Reports</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.reports.kitchen.recipe-consumption')
                                <li class="{{ request()->is('reports/kitchen*') ? 'active' : '' }}">
                                    <a href="{{ url('/reports/kitchen/recipe-consumption') }}">
                                        <i class="ti ti-pot fs-16 me-2" aria-hidden="true"></i>
                                        <span>Kitchen Reports</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.reports.audit.manager-approvals')
                                <li class="{{ request()->is('reports/audit*') ? 'active' : '' }}">
                                    <a href="{{ url('/reports/audit/manager-approvals') }}">
                                        <i class="ti ti-shield-check fs-16 me-2" aria-hidden="true"></i>
                                        <span>Audit Reports</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.reports.printing.jobs')
                                <li class="{{ request()->is('reports/printing*') ? 'active' : '' }}">
                                    <a href="{{ url('/reports/printing/jobs') }}">
                                        <i class="ti ti-printer fs-16 me-2" aria-hidden="true"></i>
                                        <span>Print Reports</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany

                    {{-- Sales Controls section --}}
                    @canany(['tenant.promotions.index', 'tenant.service-charge-settings.index', 'tenant.void-reasons.index'])
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">Sales Controls</h6>
                        <ul>
                            @can('tenant.promotions.index')
                                <li class="{{ request()->is('promotions*') ? 'active' : '' }}">
                                    <a href="{{ url('/promotions') }}">
                                        <i class="ti ti-discount-2 fs-16 me-2" aria-hidden="true"></i>
                                        <span>Promotions</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.service-charge-settings.index')
                                <li class="{{ request()->is('service-charge-settings*') ? 'active' : '' }}">
                                    <a href="{{ url('/service-charge-settings') }}">
                                        <i class="ti ti-percentage fs-16 me-2" aria-hidden="true"></i>
                                        <span>Service Charge</span>
                                    </a>
                                </li>
                            @endcan
                            @can('tenant.void-reasons.index')
                                <li class="{{ request()->is('void-reasons*') ? 'active' : '' }}">
                                    <a href="{{ url('/void-reasons') }}">
                                        <i class="ti ti-ban fs-16 me-2" aria-hidden="true"></i>
                                        <span>Void Reasons</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany

                    {{-- Catalog section --}}
                    @canany(['tenant.units.index', 'tenant.categories.index', 'tenant.products.index'])
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">Catalog</h6>
                        <ul>
                            @can('tenant.units.index')
                                <li class="{{ request()->is('units*') ? 'active' : '' }}">
                                    <a href="{{ url('/units') }}">
                                        <i class="ti ti-ruler-measure fs-16 me-2"></i>
                                        <span>Units of Measure</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.categories.index')
                                <li class="{{ request()->is('categories*') ? 'active' : '' }}">
                                    <a href="{{ url('/categories') }}">
                                        <i class="ti ti-category fs-16 me-2"></i>
                                        <span>Categories</span>
                                    </a>
                                </li>
                            @endcan

                            @can('tenant.products.index')
                                <li class="{{ request()->is('products*') || request()->is('product-variants*') || request()->is('product-barcodes*') || request()->is('products-bulk-import*') ? 'active' : '' }}">
                                    <a href="{{ url('/products') }}">
                                        <i class="ti ti-package fs-16 me-2"></i>
                                        <span>Products</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                    @endcanany
                @endif
            </ul>
        </div>
    </div>
</div>
