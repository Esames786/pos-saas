@extends('layouts.app')

@section('title', 'Restaurant POS')

@section('content')
<style>
    .pos-shell {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 430px;
        gap: 1rem;
    }

    .pos-card {
        border: 1px solid #edf0f4;
        border-radius: 22px;
        background: #fff;
        box-shadow: 0 12px 34px rgba(15, 23, 42, .06);
    }

    .mode-tabs {
        display: flex;
        gap: .65rem;
        overflow-x: auto;
        padding-bottom: .25rem;
    }

    .mode-tab {
        border: 1px solid #e9ecef;
        background: #fff;
        border-radius: 999px;
        padding: .75rem 1.1rem;
        font-weight: 800;
        white-space: nowrap;
        cursor: pointer;
    }

    .mode-tab.active {
        background: #111827;
        color: #fff;
        border-color: #111827;
    }

    .category-strip {
        display: flex;
        gap: .6rem;
        overflow-x: auto;
        padding-bottom: .25rem;
    }

    .category-pill {
        border: 1px solid #e9ecef;
        background: #fff;
        border-radius: 999px;
        padding: .55rem .95rem;
        font-weight: 700;
        white-space: nowrap;
        cursor: pointer;
    }

    .category-pill.active {
        background: #111827;
        color: #fff;
        border-color: #111827;
    }

    .restaurant-board-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
        gap: .75rem;
        max-height: 360px;
        overflow-y: auto;
    }

    .restaurant-table-tile {
        border: 1px solid #edf0f4;
        border-radius: 20px;
        background: linear-gradient(180deg, #fff, #fbfcfd);
        padding: .85rem;
        text-align: left;
        transition: .15s ease;
    }

    .restaurant-table-tile:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 28px rgba(15, 23, 42, .10);
    }

    .restaurant-table-tile.available     { border-left: 6px solid #20c997; }
    .restaurant-table-tile.occupied      { border-left: 6px solid #fd7e14; }
    .restaurant-table-tile.bill_requested{ border-left: 6px solid #0d6efd; }

    .status-chip {
        border-radius: 999px;
        background: #f8fafc;
        padding: .25rem .55rem;
        font-size: .72rem;
        font-weight: 800;
    }

    .product-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(165px, 1fr));
        gap: .85rem;
        max-height: calc(100vh - 430px);
        overflow-y: auto;
        padding-right: .25rem;
    }

    /* Recalled-order lock */
    .pos-controls-locked {
        opacity: .45;
        pointer-events: none;
        user-select: none;
        position: relative;
    }
    .pos-controls-locked::after {
        content: '';
        position: absolute;
        inset: 0;
        cursor: not-allowed;
    }

    .product-tile {
        border: 1px solid #edf0f4;
        border-radius: 20px;
        background: linear-gradient(180deg, #ffffff, #fbfcfd);
        padding: .85rem;
        min-height: 155px;
        cursor: pointer;
        transition: .15s ease;
        text-align: left;
        width: 100%;
    }

    .product-tile:hover,
    .product-tile:focus {
        transform: translateY(-2px);
        box-shadow: 0 14px 30px rgba(15, 23, 42, .10);
        outline: 3px solid rgba(13, 110, 253, .18);
    }

    .product-avatar {
        width: 44px;
        height: 44px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        background: #f1f5f9;
        font-weight: 900;
        margin-bottom: .65rem;
    }

    .stock-badge {
        border-radius: 999px;
        background: #f8fafc;
        padding: .22rem .5rem;
        font-size: .75rem;
        font-weight: 800;
    }

    .stock-low  { background: #fff3cd; color: #7a5200; }
    .stock-out  { background: #fee2e2; color: #991b1b; }

    .cart-panel { position: sticky; top: 88px; }

    .cart-items { max-height: calc(100vh - 500px); overflow-y: auto; }

    .cart-row {
        border-bottom: 1px solid #eef0f3;
        padding: .75rem 0;
    }

    .qty-btn {
        width: 32px;
        height: 32px;
        border-radius: 10px;
        border: 1px solid #dee2e6;
        background: #fff;
        font-weight: 900;
        cursor: pointer;
    }

    .shortcut-chip {
        border-radius: 999px;
        background: #f8fafc;
        padding: .35rem .65rem;
        font-size: .75rem;
        font-weight: 800;
    }

    .pos-total-line {
        display: flex;
        justify-content: space-between;
        margin-bottom: .5rem;
    }

    .pos-grand-total { font-size: 1.55rem; font-weight: 900; }

    .keypad {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: .45rem;
    }

    .keypad button {
        border: 1px solid #e9ecef;
        background: #fff;
        border-radius: 14px;
        padding: .75rem;
        font-weight: 900;
        cursor: pointer;
    }

    @media (max-width: 1199px) {
        .pos-shell      { grid-template-columns: 1fr; }
        .cart-panel     { position: static; }
    }
</style>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Restaurant POS</h1>
        <p class="fw-medium mb-0">Dine-in, takeaway, quick sale, delivery, table orders, and fast checkout.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <span class="shortcut-chip">Ctrl+F Search</span>
        <span class="shortcut-chip">Ctrl+H Hold</span>
        <span class="shortcut-chip">Ctrl+L Orders</span>
        <span class="shortcut-chip">Ctrl+P Payment</span>
        <span class="shortcut-chip">Ctrl+Enter Pay</span>
        <span class="shortcut-chip">Ctrl+M Calc</span>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

@if(session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

@if($tableSession)
    <div class="pos-card p-3 mb-3 d-flex flex-wrap align-items-center gap-2">
        <div>
            <strong>Table {{ $tableSession->table?->table_no }}</strong>
            <span class="text-muted ms-1">{{ $tableSession->session_no }}</span>
            &middot; {{ $tableSession->waiter?->name ?? 'No waiter' }}
            &middot; {{ $tableSession->guest_count }} guests
        </div>
        <div class="d-flex gap-2 ms-auto flex-wrap">
            @can('tenant.restaurant.table-sessions.bill-preview')
                <a href="{{ url('/restaurant/table-sessions/' . $tableSession->id . '/bill-preview') }}" class="btn btn-sm btn-dark">Bill Preview</a>
            @endcan
            @can('tenant.restaurant.table-sessions.bill-requested')
                @if($tableSession->status === 'open')
                    <form method="POST" action="{{ url('/restaurant/table-sessions/' . $tableSession->id . '/bill-requested') }}" class="d-inline">
                        @csrf
                        <button class="btn btn-sm btn-info" type="submit">Request Bill</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>
@endif

@if($heldSale)
    <div class="alert alert-warning" role="status">
        Recalling held sale: <strong>{{ $heldSale->sale_no }}</strong>
    </div>
@endif

{{-- Mode tabs --}}
<div class="pos-card p-3 mb-3">
    <div class="mode-tabs" id="mode-tabs-wrapper" role="tablist" aria-label="POS Modes">
        <button type="button" class="mode-tab {{ $activeMode === 'dine_in'    ? 'active' : '' }}" data-mode-tab="dine_in">Dine In</button>
        <button type="button" class="mode-tab {{ $activeMode === 'takeaway'   ? 'active' : '' }}" data-mode-tab="takeaway">Takeaway</button>
        <button type="button" class="mode-tab {{ $activeMode === 'quick_sale' ? 'active' : '' }}" data-mode-tab="quick_sale">Quick Sale</button>
        <button type="button" class="mode-tab {{ $activeMode === 'delivery'   ? 'active' : '' }}" data-mode-tab="delivery">Delivery</button>
    </div>
</div>

{{-- Live table board --}}
<div id="dine-in-board" class="pos-card p-3 mb-3" style="{{ $activeMode === 'dine_in' ? '' : 'display:none;' }}">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Live Table Board</h2>
            <p class="text-muted mb-0">Open table, select an active table, then save order or close bill.</p>
        </div>
        @can('tenant.restaurant.board')
            <a href="{{ url('/restaurant/board?branch_id=' . $selectedBranchId) }}" class="btn btn-sm btn-light">Full Board</a>
        @endcan
    </div>

    @if($floors->count() > 1)
        <div class="category-strip mb-3" id="floor-tab-strip">
            <button type="button" class="category-pill active" data-floor-tab="">All Floors</button>
            @foreach($floors as $floor)
                <button type="button" class="category-pill" data-floor-tab="{{ $floor->id }}">{{ $floor->name }}</button>
            @endforeach
        </div>
    @endif

    @forelse($floors as $floor)
        <div data-floor-panel="{{ $floor->id }}">
        <div class="mb-3">
            <h3 class="h6 mb-2">{{ $floor->name }}</h3>
            <div class="restaurant-board-grid">
                @foreach($floor->tables->sortBy('sort_order') as $table)
                    @php
                        $session      = $table->openSession;
                        $sessionTotal = $session ? $session->salesOrders->sum('grand_total') : 0;
                    @endphp
                    <div class="restaurant-table-tile {{ $table->status }}">
                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <div>
                                <div class="fw-bold">{{ $table->table_no }}</div>
                                <div class="small text-muted">{{ $table->capacity }} seats</div>
                            </div>
                            <span class="status-chip">{{ str_replace('_', ' ', ucfirst($table->status)) }}</span>
                        </div>

                        @if($session)
                            <div class="small mb-2">
                                <div><strong>Session:</strong> {{ $session->session_no }}</div>
                                <div><strong>Waiter:</strong> {{ $session->waiter?->name ?? '-' }}</div>
                                <div><strong>Total:</strong> {{ number_format($sessionTotal, 2) }}</div>
                            </div>
                            <a href="{{ url('/pos?table_session_id=' . $session->id . '&mode=dine_in&branch_id=' . $selectedBranchId) }}"
                               class="btn btn-sm btn-primary w-100 mb-1">Select Table</a>
                            @can('tenant.restaurant.table-sessions.bill-preview')
                                <a href="{{ url('/restaurant/table-sessions/' . $session->id . '/bill-preview') }}"
                                   class="btn btn-sm btn-dark w-100 mb-1">Bill Preview</a>
                            @endcan
                            @php $firstHeld = $session->salesOrders->where('status', 'held')->first(); @endphp
                            @if($firstHeld)
                                @can('tenant.sales-orders.split-bill')
                                    <a href="{{ url('/sales-orders/' . $firstHeld->id . '/split-bill') }}"
                                       class="btn btn-sm btn-warning w-100 mb-1">Split Bill</a>
                                @endcan
                                <a href="{{ url('/held-sales?table_session_id=' . $session->id) }}"
                                   class="btn btn-sm btn-outline-dark w-100">Held Orders</a>
                            @endif
                        @else
                            @can('tenant.restaurant.table-sessions.open')
                                <button type="button" class="btn btn-sm btn-success w-100"
                                    data-bs-toggle="modal" data-bs-target="#openTableModal"
                                    data-table-id="{{ $table->id }}" data-table-no="{{ $table->table_no }}">
                                    Open Table
                                </button>
                            @else
                                <span class="text-muted small">Available</span>
                            @endcan
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
        </div>{{-- /data-floor-panel --}}
    @empty
        <div class="alert alert-info" role="status">No active floors/tables found for this branch.</div>
    @endforelse
</div>

{{-- POS form --}}
<form id="pos-sale-form" method="POST" action="{{ url('/pos') }}">
    @csrf
    <input type="hidden" name="order_source"                id="pos-order-source"      value="pos">
    <input type="hidden" name="held_sale_id"                                            value="{{ $heldSale?->id }}">
    <input type="hidden" name="restaurant_table_session_id" id="restaurant_table_session_id" value="{{ $tableSession?->id ?? $heldSale?->restaurant_table_session_id }}">
    <input type="hidden" name="restaurant_table_id"         id="restaurant_table_id"         value="{{ $heldSale?->restaurant_table_id }}">
    <input type="hidden" name="discount_type"                                           value="none">
    <input type="hidden" name="discount_value"                                          value="0">
    <input type="hidden" name="promo_code"          id="pos-promo-code"                 value="">
    <input type="hidden" name="tip_amount"          id="pos-tip-amount"                 value="0">
    <input type="hidden" name="manager_approval_id" id="pos-manager-approval-id"        value="">
    <div id="dynamic-pos-inputs"></div>

    <div class="pos-shell">
        {{-- LEFT: products --}}
        <section class="pos-card p-3" aria-labelledby="products_heading">
            <div class="row g-2 mb-3" id="order-controls-row">
                <div class="col-md-3">
                    <label for="branch_id" class="form-label required">Branch</label>
                    <select id="branch_id" name="branch_id" class="form-select" required>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="terminal_id" class="form-label">Terminal</label>
                    <select id="terminal_id" name="terminal_id" class="form-select">
                        <option value="">No Terminal</option>
                        @foreach($terminals as $terminal)
                            <option value="{{ $terminal->id }}">{{ $terminal->name }} &mdash; {{ $terminal->branch?->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="order_type" class="form-label required">Order Type</label>
                    <select id="order_type" name="order_type" class="form-select" required>
                        <option value="dine_in"    @selected($activeMode === 'dine_in')>Dine In</option>
                        <option value="takeaway"   @selected($activeMode === 'takeaway')>Takeaway</option>
                        <option value="quick_sale" @selected($activeMode === 'quick_sale')>Quick Sale</option>
                        <option value="delivery"   @selected($activeMode === 'delivery')>Delivery</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="customer_id" class="form-label">Customer</label>
                    <div class="input-group">
                        <select id="customer_id" name="customer_id" class="form-select">
                            <option value="">Walk-in</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" @selected($heldSale?->customer_id === $customer->id)>
                                    {{ $customer->name }}{{ $customer->phone ? ' — ' . $customer->phone : '' }}
                                </option>
                            @endforeach
                        </select>
                        <button class="btn btn-outline-primary" type="button"
                            data-bs-toggle="modal" data-bs-target="#quickCustomerModal">+</button>
                    </div>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-md-8">
                    <label for="pos_search" class="form-label">Barcode / Product Search</label>
                    <input id="pos_search" class="form-control form-control-lg" placeholder="Scan barcode or type product name / SKU">
                </div>
                <div class="col-md-4">
                    <label for="customer_phone" class="form-label">Optional Phone</label>
                    <input id="customer_phone" name="customer_phone" class="form-control form-control-lg"
                           value="{{ $heldSale?->customer_phone }}">
                </div>
            </div>

            <div class="mb-3">
                <div class="category-strip" id="parent-category-strip">
                    <button type="button" class="category-pill active" data-parent-category="">All</button>
                    @foreach($categories as $category)
                        <button type="button" class="category-pill" data-parent-category="{{ $category->id }}">
                            {{ $category->name }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="mb-3" id="child-category-wrap" style="display:none;">
                <div class="category-strip" id="child-category-strip"></div>
            </div>

            <h2 id="products_heading" class="h5 mb-3">Products</h2>
            <div class="product-grid" id="product-grid" aria-live="polite"></div>
        </section>

        {{-- RIGHT: cart + payment --}}
        <aside class="cart-panel">
            <section class="pos-card p-3 mb-3" aria-labelledby="cart_heading">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 id="cart_heading" class="h5 mb-0">{{ $tableSession ? 'Table Cart' : 'Cart' }}</h2>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="toggle-calc-btn" title="Toggle Calculator (Ctrl+M)">Calc</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="clear-cart-btn">Clear</button>
                    </div>
                </div>
                @if($tableSession)
                    <div class="alert alert-light border small mb-2">
                        Table <strong>{{ $tableSession->table?->table_no }}</strong>
                        &middot; {{ $tableSession->guest_count }} guests
                        &middot; {{ $tableSession->waiter?->name ?? 'No waiter' }}
                    </div>
                @endif
                <div class="cart-items" id="cart-items">
                    <p class="text-muted mb-0">No items added.</p>
                </div>
            </section>

            <section class="pos-card p-3 mb-3" aria-labelledby="payment_heading">
                <h2 id="payment_heading" class="h5 mb-3">Payment</h2>
                <div class="mb-3">
                    <label for="payment_method_id" class="form-label required">Payment Method</label>
                    <select id="payment_method_id" class="form-select" required>
                        @foreach($paymentMethods as $method)
                            <option value="{{ $method->id }}" data-type="{{ $method->method_type }}" @selected($method->method_type === 'cash')>{{ $method->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label for="tendered_amount" class="form-label">Tendered Amount</label>
                    <input id="tendered_amount" type="number" step="0.01" min="0" class="form-control form-control-lg">
                    <div class="d-flex gap-1 flex-wrap mt-1" id="quick-cash-buttons"></div>
                </div>
                <div class="mb-3">
                    <label for="transaction_ref" class="form-label">Reference / Card / Bank</label>
                    <input id="transaction_ref" class="form-control" placeholder="Optional reference">
                </div>
                {{-- Promo Code Input --}}
                <div class="mb-2 d-flex gap-1" id="promo-row">
                    <input type="text" id="promo-code-input" class="form-control form-control-sm" placeholder="Promo code" style="text-transform:uppercase">
                    <button type="button" class="btn btn-sm btn-outline-primary px-2" id="apply-promo-btn">Apply</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary px-2 d-none" id="remove-promo-btn">✕</button>
                </div>
                <div id="promo-feedback" class="small mb-2"></div>

                {{-- Tip Buttons --}}
                <div class="mb-2">
                    <div class="d-flex gap-1 flex-wrap">
                        <span class="small text-muted me-1 align-self-center">Tip:</span>
                        <button type="button" class="btn btn-xs btn-outline-secondary tip-btn" data-tip-type="percent" data-tip-value="0">No Tip</button>
                        <button type="button" class="btn btn-xs btn-outline-secondary tip-btn" data-tip-type="percent" data-tip-value="5">5%</button>
                        <button type="button" class="btn btn-xs btn-outline-secondary tip-btn" data-tip-type="percent" data-tip-value="10">10%</button>
                        <button type="button" class="btn btn-xs btn-outline-secondary tip-btn" data-tip-type="custom">Custom</button>
                    </div>
                </div>

                <div class="pos-total-line"><span>Subtotal</span><strong id="subtotal-view">0.00</strong></div>
                <div class="pos-total-line d-none" id="promo-discount-row"><span id="promo-discount-label">Promo</span><strong id="promo-discount-view" class="text-success">−0.00</strong></div>
                <div class="pos-total-line"><span>Discount</span><strong id="discount-view">0.00</strong></div>
                <div class="pos-total-line"><span>Tax</span><strong id="tax-view">0.00</strong></div>
                <div class="pos-total-line d-none" id="service-charge-row"><span>Service Charge</span><strong id="service-charge-view">0.00</strong></div>
                <div class="pos-total-line d-none" id="tip-row"><span>Tip</span><strong id="tip-view">0.00</strong></div>
                <hr>
                <div class="pos-total-line">
                    <span class="pos-grand-total">Total</span>
                    <strong class="pos-grand-total" id="grand-total-view">0.00</strong>
                </div>
                <div class="pos-total-line"><span>Change</span><strong id="change-view">0.00</strong></div>
            </section>

            <section class="pos-card p-3 mb-3" id="calculator-panel" style="display:none;" aria-labelledby="calculator_heading">
                <h2 id="calculator_heading" class="h6 mb-2">Touch Keypad / Calculator</h2>
                <input id="calc-display" class="form-control mb-2" readonly>
                <div class="keypad">
                    @foreach(['7','8','9','/','4','5','6','*','1','2','3','-','0','.','C','+'] as $key)
                        <button type="button" data-key="{{ $key }}">{{ $key }}</button>
                    @endforeach
                    <button type="button" data-key="=" class="btn btn-dark" style="grid-column: span 4;">=</button>
                </div>
            </section>

            {{-- Recalled order indicator --}}
            <div id="recalled-order-bar" style="display:none" class="rounded-3 mb-2 px-3 py-2 d-flex align-items-center justify-content-between gap-2"
                 style="background:#fff3cd;border:1px solid #ffc107;">
                <div class="small fw-semibold text-warning-emphasis">
                    <i class="ti ti-lock me-1"></i>Recalled: <span id="recalled-order-no">—</span>
                </div>
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-warning py-0 px-2" id="edit-order-btn"
                            style="font-size:.73rem;white-space:nowrap"
                            data-bs-toggle="modal" data-bs-target="#changeOrderModal"
                            disabled>
                        <i class="ti ti-settings me-1"></i>Edit Order
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2" id="start-fresh-btn"
                            style="font-size:.73rem;white-space:nowrap">
                        <i class="ti ti-plus me-1"></i>New Order
                    </button>
                </div>
            </div>

            <div class="d-grid gap-2">
                <div class="row g-2">
                    <div class="col">
                        <button type="button" class="btn btn-warning btn-lg w-100" id="hold-sale-btn">
                            {{ $tableSession ? 'Save Order' : 'Hold Sale' }}
                        </button>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-secondary btn-lg px-3" id="held-orders-btn"
                                title="Held Orders (Ctrl+L)">
                            <i class="ti ti-layout-list"></i>
                        </button>
                    </div>
                </div>
                <button type="button" class="btn btn-primary btn-lg" id="complete-sale-btn">
                    {{ $tableSession ? 'Close & Pay Table Bill' : 'Complete Sale' }}
                </button>
                <div class="row g-2">
                    <div class="col">
                        <button type="button" class="btn btn-outline-danger btn-lg w-100" id="cancel-order-btn">Cancel Order</button>
                    </div>
                    <div class="col" id="split-bill-wrap" style="display:none">
                        <a href="#" class="btn btn-outline-info btn-lg w-100" id="split-bill-link">Split Bill</a>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-secondary btn-lg px-3" id="last-print-btn"
                                title="Reprint Last KOT">
                            <i class="ti ti-printer"></i>
                        </button>
                    </div>
                </div>
                @if($tableSession)
                    @can('tenant.restaurant.table-sessions.bill-requested')
                        <form method="POST" action="{{ url('/restaurant/table-sessions/' . $tableSession->id . '/bill-requested') }}">
                            @csrf
                            <button class="btn btn-info btn-lg w-100" type="submit">Mark Bill Requested</button>
                        </form>
                    @endcan
                @endif
            </div>
        </aside>
    </div>
</form>

{{-- Open Table Modal --}}
<div class="modal fade" id="openTableModal" tabindex="-1" aria-labelledby="openTableModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form id="open-table-form" method="POST" action="#" class="modal-content">
            @csrf
            <div class="modal-header">
                <h2 class="modal-title h5" id="openTableModalLabel">Open Table</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-12">
                    <div class="alert alert-light border mb-0">
                        Opening table: <strong id="open-table-no">-</strong>
                    </div>
                </div>
                <div class="col-12">
                    <label for="restaurant_waiter_id" class="form-label">Waiter</label>
                    <select id="restaurant_waiter_id" name="restaurant_waiter_id" class="form-select">
                        <option value="">No Waiter</option>
                        @foreach($waiters as $waiter)
                            <option value="{{ $waiter->id }}">{{ $waiter->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label for="guest_count" class="form-label required">Guests</label>
                    <input id="guest_count" type="number" min="1" max="100" name="guest_count" value="1" class="form-control" required>
                </div>
                <div class="col-12">
                    <label for="table_notes" class="form-label">Notes</label>
                    <input id="table_notes" name="notes" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-success" type="submit" id="open-table-submit">Open Table</button>
            </div>
        </form>
    </div>
</div>

{{-- Held Sales Modal --}}
<div class="modal fade" id="heldSalesModal" tabindex="-1" aria-labelledby="heldSalesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="heldSalesModalLabel">
                    <i class="ti ti-layout-list me-2"></i>Held Orders
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="held-sales-modal-body">
                <div class="text-center py-5">
                    <div class="spinner-border text-secondary" role="status"></div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Recent Prints Modal --}}
<div class="modal fade" id="lastPrintModal" tabindex="-1" aria-labelledby="lastPrintModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title h5 mb-0" id="lastPrintModalLabel">
                        <i class="ti ti-printer me-2"></i>Recent Prints
                    </h2>
                    <p class="text-muted small mb-0 mt-1">Sale: <strong id="last-print-sale-no">—</strong></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="last-print-modal-body">
                <div class="text-center py-5">
                    <div class="spinner-border text-secondary" role="status"></div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-warning btn-sm" id="reprint-all-kot-btn">
                        <i class="ti ti-tool-kitchen-2 me-1"></i>Reprint All KOT
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="reprint-receipt-btn">
                        <i class="ti ti-receipt me-1"></i>Reprint Receipt
                    </button>
                </div>
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

{{-- Change Order Details Modal --}}
<div class="modal fade" id="changeOrderModal" tabindex="-1" aria-labelledby="changeOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="changeOrderModalLabel">
                    <i class="ti ti-settings me-2"></i>Edit Order Details
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body row g-3">
                {{-- Order Type --}}
                <div class="col-12">
                    <label class="form-label fw-semibold required">Order Type</label>
                    <div class="d-flex flex-wrap gap-2" id="co-type-btns">
                        @foreach(['quick_sale' => 'Quick Sale','takeaway' => 'Takeaway','dine_in' => 'Dine In','delivery' => 'Delivery'] as $val => $label)
                            <button type="button" class="btn btn-outline-secondary px-3 py-2 co-type-btn" data-co-type="{{ $val }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    <input type="hidden" id="co-order-type" value="">
                </div>
                {{-- Table Session (Dine In only) --}}
                <div class="col-12" id="co-table-wrap" style="display:none">
                    <label class="form-label required">Table Session</label>
                    <select id="co-table-session" class="form-select">
                        <option value="">— Select Table —</option>
                    </select>
                    <div class="text-muted small mt-1">Only open/active sessions are shown.</div>
                </div>
                {{-- Terminal --}}
                <div class="col-12">
                    <label class="form-label">Terminal</label>
                    <select id="co-terminal" class="form-select">
                        <option value="">No Terminal</option>
                        @foreach($terminals as $terminal)
                            <option value="{{ $terminal->id }}">{{ $terminal->name }} &mdash; {{ $terminal->branch?->name }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- Branch --}}
                <div class="col-12">
                    <label class="form-label">Branch</label>
                    <select id="co-branch" class="form-select">
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    <div class="text-muted small mt-1">
                        <i class="ti ti-alert-triangle text-warning me-1"></i>Changing branch reloads the page and clears the cart.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="co-apply-btn">
                    <i class="ti ti-check me-1"></i>Apply Changes
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Quick Customer Modal --}}
<div class="modal fade" id="quickCustomerModal" tabindex="-1" aria-labelledby="quickCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ url('/pos/customers/quick-store') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h2 class="modal-title h5" id="quickCustomerModalLabel">Quick Customer</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-12">
                    <label for="quick_customer_name" class="form-label required">Name</label>
                    <input id="quick_customer_name" name="name" class="form-control" required>
                </div>
                <div class="col-12">
                    <label for="quick_customer_phone" class="form-label">Phone</label>
                    <input id="quick_customer_phone" name="phone" class="form-control">
                </div>
                <div class="col-12">
                    <label for="quick_customer_email" class="form-label">Email</label>
                    <input id="quick_customer_email" type="email" name="email" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Create Customer</button>
            </div>
        </form>
    </div>
</div>

@php
    $heldSaleJson = $heldSale ? [
        'id'      => $heldSale->id,
        'sale_no' => $heldSale->sale_no,
        'lines'   => $heldSale->lines->map(fn ($l) => [
            'id'                 => (int) $l->id,
            'product_id'         => (int) $l->product_id,
            'product_variant_id' => $l->product_variant_id ? (int) $l->product_variant_id : null,
            'quantity'           => (float) $l->quantity,
            'unit_price'         => (float) $l->unit_price,
            'discount_amount'    => (float) $l->discount_amount,
            'tax_amount'         => (float) $l->tax_amount,
            'kot_sent'           => (bool) $l->kot_sent,
            'product_name'       => $l->product_name,
        ])->values()->toArray(),
    ] : null;
@endphp

<script>
document.addEventListener('DOMContentLoaded', function () {
    const products   = @json($productsPayload);
    const categories = @json($categories);
    const heldSale   = @json($heldSaleJson);

    const form             = document.getElementById('pos-sale-form');
    const productGrid      = document.getElementById('product-grid');
    const cartItemsEl      = document.getElementById('cart-items');
    const branchEl         = document.getElementById('branch_id');
    const searchEl         = document.getElementById('pos_search');
    const dynamicInputs    = document.getElementById('dynamic-pos-inputs');
    const paymentMethodEl  = document.getElementById('payment_method_id');
    const tenderedEl       = document.getElementById('tendered_amount');
    const transactionRefEl = document.getElementById('transaction_ref');
    const calculatorPanel  = document.getElementById('calculator-panel');
    const calcDisplay      = document.getElementById('calc-display');
    const orderTypeEl      = document.getElementById('order_type');
    const terminalEl       = document.getElementById('terminal_id');

    let selectedParentCategory = '';
    let selectedChildCategory  = '';
    let cart = [];

    /* helpers */

    function money(value) {
        return Number(value || 0).toFixed(2);
    }

    function selectedBranchId() {
        return Number(branchEl.value || 0);
    }

    function productPrice(product, variant) {
        const branchId = selectedBranchId();

        if (variant) {
            const exact = (product.branch_prices || []).find(function (p) {
                return Number(p.branch_id) === branchId && Number(p.product_variant_id || 0) === Number(variant.id);
            });
            if (exact) return Number(exact.selling_price || 0);
        }

        const base = (product.branch_prices || []).find(function (p) {
            return Number(p.branch_id) === branchId && !p.product_variant_id;
        });
        if (base) return Number(base.selling_price || 0);

        if (variant) return Number(variant.selling_price || product.price || 0);

        return Number(product.price || 0);
    }

    function availableQty(product, variant) {
        if (!product.is_stock_tracked) return null;
        const branchId = selectedBranchId();
        if (variant && variant.stock_by_branch) return Number(variant.stock_by_branch[branchId] || 0);
        return Number((product.stock_by_branch || {})[branchId] || 0);
    }

    function lineTax(product, qty, price, discount) {
        if (!product.is_taxable || Number(product.tax_rate_percent || 0) <= 0) return 0;
        return (Math.max((qty * price) - discount, 0) * Number(product.tax_rate_percent || 0)) / 100;
    }

    function initials(name) {
        return String(name || '?').split(' ').map(function (p) { return p[0]; }).join('').substring(0, 2).toUpperCase();
    }

    /* product grid */

    function renderProducts() {
        const query = searchEl.value.toLowerCase().trim();

        const filtered = products.filter(function (product) {
            const matchParent = !selectedParentCategory || Number(product.category_id) === Number(selectedParentCategory);
            const matchChild  = !selectedChildCategory  || Number(product.category_id) === Number(selectedChildCategory);

            const barcodeMatch = (product.barcodes || []).some(function (barcode) {
                return String(barcode).toLowerCase().includes(query);
            });

            const textMatch = !query
                || String(product.name).toLowerCase().includes(query)
                || String(product.sku || '').toLowerCase().includes(query)
                || barcodeMatch;

            return textMatch && (selectedChildCategory ? matchChild : matchParent);
        });

        productGrid.innerHTML = '';

        filtered.forEach(function (product) {
            const variant    = product.variants && product.variants.length ? product.variants[0] : null;
            const qty        = availableQty(product, variant);
            const price      = productPrice(product, variant);
            const stockClass = qty === null ? '' : qty <= 0 ? 'stock-out' : qty <= 5 ? 'stock-low' : '';
            const stockText  = qty === null ? 'Service' : 'Stock ' + qty;

            const button     = document.createElement('button');
            button.type      = 'button';
            button.className = 'product-tile';
            button.innerHTML =
                '<div class="product-avatar">' + initials(product.name) + '</div>' +
                '<div class="fw-bold mb-1">' + product.name + '</div>' +
                '<div class="text-muted small mb-2">' + (product.sku || 'No SKU') + '</div>' +
                '<div class="d-flex justify-content-between align-items-center">' +
                    '<span class="fw-bold">' + money(price) + '</span>' +
                    '<span class="stock-badge ' + stockClass + '">' + stockText + '</span>' +
                '</div>' +
                (product.is_taxable ? '<div class="small text-muted mt-2">Tax ' + product.tax_rate_percent + '%</div>' : '');

            button.addEventListener('click', function () { addToCart(product, variant); });
            productGrid.appendChild(button);
        });

        if (!filtered.length) {
            productGrid.innerHTML = '<div class="alert alert-info" role="status">No products found.</div>';
        }
    }

    /* cart */

    function addToCart(product, variant) {
        const key      = product.id + ':' + (variant ? variant.id : 0);
        const existing = cart.find(function (item) { return item.key === key; });
        const qty      = availableQty(product, variant);

        if (qty !== null && qty <= 0) { alert('This item is out of stock.'); return; }

        if (existing) {
            existing.quantity += 1;
        } else {
            const price = productPrice(product, variant);
            cart.push({
                key:                key,
                product_id:         product.id,
                product_variant_id: variant ? variant.id : null,
                name:               product.name,
                variant_name:       variant ? variant.name : null,
                quantity:           1,
                unit_price:         price,
                discount_amount:    0,
                tax_amount:         lineTax(product, 1, price, 0),
                product:            product,
                variant:            variant || null,
            });
        }

        renderCart();
    }

    function renderCart() {
        cartItemsEl.innerHTML = '';

        if (!cart.length) {
            cartItemsEl.innerHTML = '<p class="text-muted mb-0">No items added.</p>';
            updateTotals();
            return;
        }

        cart.forEach(function (item, index) {
            item.tax_amount = lineTax(item.product, item.quantity, item.unit_price, item.discount_amount);

            const row     = document.createElement('div');
            row.className = 'cart-row';
            row.innerHTML =
                '<div class="d-flex justify-content-between gap-2 mb-2">' +
                    '<div>' +
                        '<div class="fw-bold">' + item.name + '</div>' +
                        '<div class="small text-muted">' + (item.variant_name || 'Default') + ' &middot; ' + money(item.unit_price) + '</div>' +
                    '</div>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" data-remove="' + index + '">&times;</button>' +
                '</div>' +
                '<div class="d-flex align-items-center justify-content-between gap-2">' +
                    '<div class="d-flex align-items-center gap-2">' +
                        '<button type="button" class="qty-btn" data-minus="' + index + '">-</button>' +
                        '<strong>' + item.quantity + '</strong>' +
                        '<button type="button" class="qty-btn" data-plus="' + index + '">+</button>' +
                    '</div>' +
                    '<strong>' + money((item.quantity * item.unit_price) - item.discount_amount + item.tax_amount) + '</strong>' +
                '</div>';

            cartItemsEl.appendChild(row);
        });

        cartItemsEl.querySelectorAll('[data-plus]').forEach(function (btn) {
            btn.addEventListener('click', function () { cart[Number(btn.dataset.plus)].quantity += 1; renderCart(); });
        });
        cartItemsEl.querySelectorAll('[data-minus]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const i = Number(btn.dataset.minus);
                cart[i].quantity -= 1;
                if (cart[i].quantity <= 0) cart.splice(i, 1);
                renderCart();
            });
        });
        cartItemsEl.querySelectorAll('[data-remove]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const idx = Number(btn.dataset.remove);
                const item = cart[idx];
                // If KOT already sent for this item, require void reason
                if (item && (item.kot_sent || item._dbLineId) && voidReasons.length > 0) {
                    showVoidReasonModal(idx, function (voidData) {
                        if (voidData) {
                            // Record the void for submission
                            if (!window._voidItems) window._voidItems = [];
                            window._voidItems.push({
                                old_line_id:         item._dbLineId || null,
                                reason_id:           voidData.reason_id,
                                manager_approval_id: voidData.manager_approval_id,
                                product_name:        item.product_name || item.product?.name || '',
                            });
                        }
                        cart.splice(idx, 1);
                        renderCart();
                    });
                } else {
                    cart.splice(idx, 1);
                    renderCart();
                }
            });
        });

        updateTotals();
    }

    /* totals */

    let _promoDiscountAmount = 0;
    let _promoCode = '';
    let _promoName = '';
    let _tipAmount = 0;
    let _serviceChargeAmount = 0;

    function totals() {
        let subtotal = 0, discount = 0, tax = 0;
        cart.forEach(function (item) {
            subtotal += item.quantity * item.unit_price;
            discount += Number(item.discount_amount || 0);
            tax      += Number(item.tax_amount || 0);
        });
        const promoDiscount = _promoDiscountAmount;
        const totalDiscount = discount + promoDiscount;
        const total = Math.max(subtotal - totalDiscount + tax + _serviceChargeAmount + _tipAmount, 0);
        return {
            subtotal:       subtotal,
            discount:       discount,
            promoDiscount:  promoDiscount,
            tax:            tax,
            serviceCharge:  _serviceChargeAmount,
            tip:            _tipAmount,
            total:          total,
        };
    }

    function updateQuickCash(total) {
        const container = document.getElementById('quick-cash-buttons');
        if (!container) return;
        container.innerHTML = '';

        const amounts = [total];
        const roundings = [10, 50, 100, 500, 1000, 2000, 5000, 10000];

        for (const r of roundings) {
            const rounded = Math.ceil(total / r) * r;
            if (rounded > total && !amounts.includes(rounded)) {
                amounts.push(rounded);
                if (amounts.length >= 5) break;
            }
        }

        amounts.forEach(function (amount) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm btn-outline-secondary';
            b.textContent = money(amount);
            b.addEventListener('click', function () {
                tenderedEl.value = money(amount);
                tenderedEl.dataset.manual = '1';
                updateTotals();
            });
            container.appendChild(b);
        });
    }

    function updateTotals() {
        const t = totals();
        document.getElementById('subtotal-view').textContent    = money(t.subtotal);
        document.getElementById('discount-view').textContent    = money(t.discount);
        document.getElementById('tax-view').textContent         = money(t.tax);
        document.getElementById('grand-total-view').textContent = money(t.total);

        // Promo discount row
        const promoRow = document.getElementById('promo-discount-row');
        if (t.promoDiscount > 0) {
            document.getElementById('promo-discount-view').textContent = '−' + money(t.promoDiscount);
            document.getElementById('promo-discount-label').textContent = _promoName || 'Promo';
            promoRow.classList.remove('d-none');
        } else {
            promoRow.classList.add('d-none');
        }

        // Service charge row
        const scRow = document.getElementById('service-charge-row');
        if (t.serviceCharge > 0) {
            document.getElementById('service-charge-view').textContent = money(t.serviceCharge);
            scRow.classList.remove('d-none');
        } else {
            scRow.classList.add('d-none');
        }

        // Tip row
        const tipRow = document.getElementById('tip-row');
        if (t.tip > 0) {
            document.getElementById('tip-view').textContent = money(t.tip);
            tipRow.classList.remove('d-none');
        } else {
            tipRow.classList.add('d-none');
        }

        // Sync hidden inputs
        document.getElementById('pos-promo-code').value = _promoCode;
        document.getElementById('pos-tip-amount').value = _tipAmount.toFixed(2);

        if (!tenderedEl.dataset.manual) tenderedEl.value = money(t.total);
        document.getElementById('change-view').textContent =
            money(Math.max(Number(tenderedEl.value || 0) - t.total, 0));
        updateQuickCash(t.total);
    }

    /* form build + submit */

    function buildInputs(includePayment) {
        dynamicInputs.innerHTML = '';

        cart.forEach(function (item, index) {
            var fields = {
                product_id:         item.product_id,
                product_variant_id: item.product_variant_id || '',
                quantity:           item.quantity,
                unit_price:         item.unit_price,
                discount_amount:    item.discount_amount || 0,
                tax_amount:         item.tax_amount || 0,
            };
            Object.keys(fields).forEach(function (field) {
                var inp  = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'lines[' + index + '][' + field + ']';
                inp.value = fields[field];
                dynamicInputs.appendChild(inp);
            });
        });

        if (includePayment) {
            const t = totals();
            var payFields = {
                payment_method_id: paymentMethodEl.value,
                amount:            money(t.total),
                tendered_amount:   tenderedEl.value || money(t.total),
                transaction_ref:   transactionRefEl.value || '',
            };
            Object.keys(payFields).forEach(function (field) {
                var inp  = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'payments[0][' + field + ']';
                inp.value = payFields[field];
                dynamicInputs.appendChild(inp);
            });
        }

        // Append void_items collected during this session
        const voidItems = window._voidItems || [];
        voidItems.forEach(function (vi, i) {
            ['old_line_id', 'reason_id', 'manager_approval_id', 'product_name'].forEach(function (f) {
                if (vi[f] !== null && vi[f] !== undefined) {
                    var inp  = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'void_items[' + i + '][' + f + ']';
                    inp.value = vi[f];
                    dynamicInputs.appendChild(inp);
                }
            });
        });
    }

    /* ── SweetAlert2 toast helper ─────────────────────────────────────── */

    const Toast = (typeof Swal !== 'undefined') ? Swal.mixin({
        toast:             true,
        position:          'top-end',
        showConfirmButton: false,
        timer:             3000,
        timerProgressBar:  true,
    }) : null;

    function toast(icon, title) {
        if (Toast) { Toast.fire({ icon: icon, title: title }); }
    }

    /* ── Promo Code ────────────────────────────────────────────────── */

    document.getElementById('apply-promo-btn').addEventListener('click', function () {
        const code = document.getElementById('promo-code-input').value.trim().toUpperCase();
        if (!code) return;
        const t = totals();
        const branchId = document.getElementById('pos-branch-id')?.value || '';
        const orderType = document.getElementById('pos-order-type')?.value || 'quick_sale';
        fetch('{{ url('/api/pos/promotions/quote') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify({ promo_code: code, branch_id: branchId, order_type: orderType, subtotal: t.subtotal }),
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            const fb = document.getElementById('promo-feedback');
            if (data.valid) {
                _promoDiscountAmount = data.discount_amount;
                _promoCode = data.promo_code;
                _promoName = data.promotion_name || 'Promo';
                fb.innerHTML = '<span class="text-success"><i class="ti ti-check me-1"></i>' + data.promotion_name + ' applied</span>';
                document.getElementById('remove-promo-btn').classList.remove('d-none');
                document.getElementById('apply-promo-btn').classList.add('d-none');
            } else {
                fb.innerHTML = '<span class="text-danger">' + (data.message || 'Invalid promo code') + '</span>';
            }
            updateTotals();
        })
        .catch(function () {
            document.getElementById('promo-feedback').innerHTML = '<span class="text-danger">Failed to validate promo code.</span>';
        });
    });

    document.getElementById('remove-promo-btn').addEventListener('click', function () {
        _promoDiscountAmount = 0;
        _promoCode = '';
        _promoName = '';
        document.getElementById('promo-code-input').value = '';
        document.getElementById('promo-feedback').innerHTML = '';
        document.getElementById('remove-promo-btn').classList.add('d-none');
        document.getElementById('apply-promo-btn').classList.remove('d-none');
        updateTotals();
    });

    /* ── Tip Buttons ────────────────────────────────────────────────── */

    document.querySelectorAll('.tip-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tip-btn').forEach(function (b) { b.classList.remove('active', 'btn-primary'); b.classList.add('btn-outline-secondary'); });
            btn.classList.add('active', 'btn-primary');
            btn.classList.remove('btn-outline-secondary');

            if (btn.dataset.tipType === 'custom') {
                const custom = parseFloat(prompt('Enter tip amount:') || '0');
                _tipAmount = isNaN(custom) ? 0 : Math.max(custom, 0);
            } else if (btn.dataset.tipType === 'percent') {
                const pct = parseFloat(btn.dataset.tipValue || '0');
                const t = totals();
                _tipAmount = pct > 0 ? Math.round(t.subtotal * pct / 100 * 100) / 100 : 0;
            }
            updateTotals();
        });
    });

    /* ── Void Reason Modal (for KOT-sent items) ─────────────────────── */

    @php $voidReasons = \App\Models\Tenant\VoidReason::where('is_active', true)->get(['id','name','requires_manager_approval']); @endphp
    const voidReasons = @json($voidReasons->values());

    function showVoidReasonModal(lineIndex, callback) {
        const line = cart[lineIndex];
        let html = '<div class="mb-3"><strong>' + (line.product_name || line.product?.name || 'Item') + '</strong><br><small class="text-muted">This item was already sent to kitchen (KOT). Please select a void reason.</small></div>';
        if (!voidReasons.length) {
            callback({ reason_id: null, manager_approval_id: null });
            return;
        }
        html += '<div class="list-group">';
        voidReasons.forEach(function (r) {
            html += '<button type="button" class="list-group-item list-group-item-action void-reason-item" data-reason-id="' + r.id + '" data-requires-pin="' + (r.requires_manager_approval ? '1' : '0') + '">' +
                r.name + (r.requires_manager_approval ? ' <span class="badge bg-warning text-dark ms-1">PIN Required</span>' : '') +
                '</button>';
        });
        html += '</div>';

        Swal.fire({
            title: 'Void Reason',
            html: html,
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'Cancel',
            didOpen: function (popup) {
                popup.querySelectorAll('.void-reason-item').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const reasonId = btn.dataset.reasonId;
                        const requiresPin = btn.dataset.requiresPin === '1';
                        Swal.close();
                        if (requiresPin) {
                            showManagerPinModal('void_item', function (approvalId) {
                                callback({ reason_id: reasonId, manager_approval_id: approvalId });
                            }, function () { /* cancelled — do not remove */ });
                        } else {
                            callback({ reason_id: reasonId, manager_approval_id: null });
                        }
                    });
                });
            },
        });
    }

    /* ── Manager PIN Modal ──────────────────────────────────────────── */

    function showManagerPinModal(actionType, onSuccess, onCancel) {
        Swal.fire({
            title: 'Manager Approval',
            html: '<p class="text-muted small mb-3">Enter manager PIN to approve this action.</p>' +
                  '<input type="password" id="swal-manager-pin" class="swal2-input" placeholder="PIN" inputmode="numeric" maxlength="8">',
            confirmButtonText: 'Verify',
            cancelButtonText: 'Cancel',
            showCancelButton: true,
            preConfirm: function () {
                const pin = document.getElementById('swal-manager-pin').value;
                if (!pin) { Swal.showValidationMessage('Enter PIN'); return false; }
                return fetch('{{ url('/api/manager-approvals/verify') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    body: JSON.stringify({ pin: pin, action_type: actionType }),
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.ok) { Swal.showValidationMessage(data.message || 'Invalid PIN'); return false; }
                    return data;
                })
                .catch(function () { Swal.showValidationMessage('Verification failed'); return false; });
            },
        }).then(function (result) {
            if (result.isConfirmed && result.value) {
                onSuccess(result.value.approval_id);
            } else if (result.isDismissed) {
                if (onCancel) onCancel();
            }
        });
    }

    /* ── State ────────────────────────────────────────────────────────── */

    let _currentHeldSaleId = null;   // held sale ID currently loaded in cart
    let _currentHeldSaleNo = null;
    let _lastSaleId        = null;   // last processed sale (for reprint)
    let _lastSaleNo        = null;
    let _lastPrintModal    = null;

    /* ── Cart clear helper ────────────────────────────────────────────── */

    function clearCart() {
        cart = [];
        _currentHeldSaleId = null;
        _currentHeldSaleNo = null;
        window._voidItems  = [];
        _promoDiscountAmount = 0;
        _promoCode = '';
        _promoName = '';
        _tipAmount = 0;
        document.getElementById('promo-code-input').value = '';
        document.getElementById('promo-feedback').innerHTML = '';
        document.getElementById('remove-promo-btn').classList.add('d-none');
        document.getElementById('apply-promo-btn').classList.remove('d-none');
        document.querySelectorAll('.tip-btn').forEach(function (b) { b.classList.remove('active','btn-primary'); b.classList.add('btn-outline-secondary'); });
        const heldInput = document.querySelector('input[name="held_sale_id"]');
        if (heldInput) heldInput.value = '';
        const tblSessionInput = document.getElementById('restaurant_table_session_id');
        if (tblSessionInput) tblSessionInput.value = '';
        const tblIdInput = document.getElementById('restaurant_table_id');
        if (tblIdInput) tblIdInput.value = '';
        renderCart();
        updateSplitBillBtn();
        updateRecalledBar();
        unlockOrderControls();
    }

    function updateSplitBillBtn() {
        const wrap = document.getElementById('split-bill-wrap');
        const link = document.getElementById('split-bill-link');
        if (!wrap || !link) return;
        if (_currentHeldSaleId) {
            link.href       = '{{ url('/sales-orders') }}/' + _currentHeldSaleId + '/split-bill';
            wrap.style.display = '';
        } else {
            wrap.style.display = 'none';
        }
    }

    function updateRecalledBar() {
        const bar        = document.getElementById('recalled-order-bar');
        const noEl       = document.getElementById('recalled-order-no');
        const editBtn    = document.getElementById('edit-order-btn');
        if (!bar) return;
        if (_currentHeldSaleId) {
            if (noEl) noEl.textContent = _currentHeldSaleNo || ('#' + _currentHeldSaleId);
            bar.style.display = '';
            if (editBtn) editBtn.disabled = false;
        } else {
            bar.style.display = 'none';
            if (editBtn) editBtn.disabled = true;
        }
    }

    function lockOrderControls() {
        const modeTabs = document.getElementById('mode-tabs-wrapper');
        const ctrlRow  = document.getElementById('order-controls-row');
        if (modeTabs) modeTabs.classList.add('pos-controls-locked');
        if (ctrlRow)  ctrlRow.classList.add('pos-controls-locked');
    }

    function unlockOrderControls() {
        const modeTabs = document.getElementById('mode-tabs-wrapper');
        const ctrlRow  = document.getElementById('order-controls-row');
        if (modeTabs) modeTabs.classList.remove('pos-controls-locked');
        if (ctrlRow)  ctrlRow.classList.remove('pos-controls-locked');
    }

    /* ── Terminal auto-print config ───────────────────────────────────── */

    const terminalPrintConfig = @json($terminalPrintConfig);

    function terminalAutoKot(terminalId) {
        if (!terminalId) return false;  // No terminal selected → always ask
        const cfg = terminalPrintConfig[terminalId];
        return cfg ? cfg.auto_print_kot : false;
    }

    function fireKotSilently(saleId, terminalId) {
        const query = terminalId ? '?terminal_id=' + encodeURIComponent(terminalId) : '';
        fetch('{{ url('/printing/jobs/kot') }}/' + saleId + query, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            (data.jobs || []).forEach(function (job) {
                if (job.fallback || job.printer_type === 'browser') {
                    toast('warning', 'No printer found — opening KOT for manual print');
                    window.open(job.preview_url, '_blank');
                }
            });
        })
        .catch(function () {});
    }

    function handleKotAfterSale(saleId, saleNo, terminalId) {
        if (terminalAutoKot(terminalId)) {
            fireKotSilently(saleId, terminalId);
            toast('info', '<i class="ti ti-printer me-1"></i> KOT sent to kitchen');
        } else if (typeof Swal !== 'undefined') {
            Swal.fire({
                title:              'Print Kitchen Order?',
                html:               'Sale <strong>' + saleNo + '</strong> saved.',
                icon:               'question',
                showCancelButton:   true,
                confirmButtonText:  'Print KOT',
                cancelButtonText:   'Skip',
                confirmButtonColor: '#0d6efd',
                reverseButtons:     true,
            }).then(function (result) {
                if (result.isConfirmed) {
                    fireKotSilently(saleId, terminalId);
                    toast('success', 'KOT sent to kitchen');
                }
            });
        }
    }

    /* ── Complete sale ────────────────────────────────────────────────── */

    function submitPaidSale() {
        if (!cart.length) { toast('warning', 'Add at least one item'); return; }
        buildInputs(true);

        const submitBtn  = document.getElementById('complete-sale-btn');
        const origLabel  = submitBtn.textContent;
        const terminalId = (document.getElementById('terminal_id') || {}).value || '';
        const printQuery = terminalId ? '?terminal_id=' + encodeURIComponent(terminalId) : '';
        submitBtn.disabled    = true;
        submitBtn.textContent = 'Processing…';

        fetch('{{ url('/pos') }}', {
            method:  'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body:    new FormData(form),
        })
        .then(function (res) { return res.json().then(function (d) { return { ok: res.ok, data: d }; }); })
        .then(function (result) {
            submitBtn.disabled    = false;
            submitBtn.textContent = origLabel;

            if (!result.ok) {
                toast('error', result.data.message || 'Sale failed. Please try again.');
                return;
            }

            const saleId = result.data.sale_id;
            const saleNo = result.data.sale_no;

            _lastSaleId = saleId;
            _lastSaleNo = saleNo;

            // Receipt fires silently
            fetch('{{ url('/printing/jobs/receipt') }}/' + saleId + printQuery, {
                method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            }).catch(function () {});

            // KOT — auto or ask
            handleKotAfterSale(saleId, saleNo, terminalId);

            // Clear cart and stay on POS
            clearCart();
            toast('success', 'Sale complete! ' + saleNo);
        })
        .catch(function () {
            submitBtn.disabled    = false;
            submitBtn.textContent = origLabel;
            toast('error', 'Network error. Please try again.');
        });
    }

    /* ── Hold sale ────────────────────────────────────────────────────── */

    function submitHeldSale() {
        if (!cart.length) { toast('warning', 'Add at least one item'); return; }

        // Sync any current held sale ID into the form
        const heldInput = document.querySelector('input[name="held_sale_id"]');
        if (heldInput && _currentHeldSaleId) heldInput.value = _currentHeldSaleId;

        buildInputs(false);

        const holdBtn    = document.getElementById('hold-sale-btn');
        const origLabel  = holdBtn.textContent;
        const terminalId = (document.getElementById('terminal_id') || {}).value || '';
        holdBtn.disabled    = true;
        holdBtn.textContent = 'Saving…';

        fetch('{{ url('/held-sales') }}', {
            method:  'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body:    new FormData(form),
        })
        .then(function (res) { return res.json().then(function (d) { return { ok: res.ok, data: d }; }); })
        .then(function (result) {
            holdBtn.disabled    = false;
            holdBtn.textContent = origLabel;

            if (!result.ok) {
                toast('error', result.data.message || 'Failed to hold sale.');
                return;
            }

            const saleId = result.data.sale_id;
            const saleNo = result.data.sale_no;

            // Update held sale state — keep cart loaded so user can add more
            _currentHeldSaleId = saleId;
            _currentHeldSaleNo = saleNo;
            _lastSaleId        = saleId;
            _lastSaleNo        = saleNo;
            if (heldInput) heldInput.value = saleId;

            // If backend auto-created a table session, sync the hidden input now
            if (result.data.restaurant_table_session_id) {
                const tblSessInput = document.getElementById('restaurant_table_session_id');
                if (tblSessInput) tblSessInput.value = result.data.restaurant_table_session_id;
            }

            updateSplitBillBtn();
            updateRecalledBar();
            lockOrderControls();
            toast('success', 'Held: ' + saleNo);
            handleKotAfterSale(saleId, saleNo, terminalId);
        })
        .catch(function () {
            holdBtn.disabled    = false;
            holdBtn.textContent = origLabel;
            toast('error', 'Network error. Please try again.');
        });
    }

    /* ── Cancel order button ──────────────────────────────────────────── */

    document.getElementById('cancel-order-btn').addEventListener('click', function () {
        if (!cart.length && !_currentHeldSaleId) {
            toast('warning', 'Nothing to cancel');
            return;
        }
        const msg = _currentHeldSaleId
            ? 'Cancel held sale ' + _currentHeldSaleNo + '?'
            : 'Clear the current cart?';

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title:              'Cancel Order?',
                text:               msg,
                icon:               'warning',
                showCancelButton:   true,
                confirmButtonText:  'Yes, Cancel',
                cancelButtonText:   'No',
                confirmButtonColor: '#dc3545',
                reverseButtons:     true,
            }).then(function (res) {
                if (!res.isConfirmed) return;
                if (_currentHeldSaleId) {
                    fetch('{{ url('/held-sales') }}/' + _currentHeldSaleId + '/cancel', {
                        method:  'POST',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                        body:    '{}',
                    }).then(function () {
                        clearCart();
                        toast('success', 'Order cancelled');
                    }).catch(function () {
                        toast('error', 'Failed to cancel. Try again.');
                    });
                } else {
                    clearCart();
                    toast('info', 'Cart cleared');
                }
            });
        } else {
            if (!confirm(msg)) return;
            clearCart();
        }
    });

    /* ── Held orders modal ────────────────────────────────────────────── */

    const heldSalesModalEl = document.getElementById('heldSalesModal');

    heldSalesModalEl.addEventListener('show.bs.modal', loadHeldSales);

    function loadHeldSales() {
        const body     = document.getElementById('held-sales-modal-body');
        const branchId = document.getElementById('branch_id').value;
        body.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-secondary" role="status"></div></div>';

        fetch('{{ url('/api/pos/held-sales') }}?branch_id=' + encodeURIComponent(branchId), {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.sales || !data.sales.length) {
                body.innerHTML = '<p class="text-muted text-center py-5 mb-0">No held orders for this branch.</p>';
                return;
            }
            let html = '<div class="table-responsive"><table class="table table-hover align-middle mb-0">' +
                '<thead class="table-light"><tr>' +
                '<th>Sale No</th><th>Type</th><th>Customer</th><th class="text-end">Items</th>' +
                '<th class="text-end">Total</th><th>Time</th><th></th>' +
                '</tr></thead><tbody>';

            data.sales.forEach(function (s) {
                html += '<tr>' +
                    '<td><strong>' + s.sale_no + '</strong></td>' +
                    '<td><span class="badge bg-secondary text-capitalize">' + s.order_type.replace('_', ' ') + '</span></td>' +
                    '<td>' + s.customer + '</td>' +
                    '<td class="text-end">' + s.items + '</td>' +
                    '<td class="text-end fw-bold">' + s.total + '</td>' +
                    '<td class="text-muted small">' + s.time + '</td>' +
                    '<td class="text-end">' +
                        '<button class="btn btn-sm btn-primary me-1" data-recall-id="' + s.id + '">Recall</button>' +
                        '<button class="btn btn-sm btn-outline-danger" data-cancel-id="' + s.id + '" data-cancel-no="' + s.sale_no + '">Cancel</button>' +
                    '</td>' +
                    '</tr>';
            });
            html += '</tbody></table></div>';
            body.innerHTML = html;

            // Store full sales data for recall
            const salesMap = {};
            data.sales.forEach(function (s) { salesMap[s.id] = s; });

            body.querySelectorAll('[data-recall-id]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    recallHeldSale(salesMap[Number(btn.dataset.recallId)]);
                });
            });
            body.querySelectorAll('[data-cancel-id]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    cancelHeldSaleFromModal(Number(btn.dataset.cancelId), btn.dataset.cancelNo, btn);
                });
            });
        })
        .catch(function () {
            body.innerHTML = '<div class="alert alert-danger m-3">Failed to load held orders.</div>';
        });
    }

    function recallHeldSale(sale) {
        bootstrap.Modal.getInstance(heldSalesModalEl).hide();

        // Rebuild cart from recalled sale
        cart = [];
        sale.lines.forEach(function (line) {
            const product = products.find(function (p) { return Number(p.id) === Number(line.product_id); });
            if (!product) return;
            const variant = (product.variants || []).find(function (v) {
                return Number(v.id) === Number(line.product_variant_id);
            });
            cart.push({
                key:                product.id + ':' + (variant ? variant.id : 0),
                product_id:         product.id,
                product_variant_id: variant ? variant.id : null,
                name:               line.product_name || product.name,
                variant_name:       line.variant_name || (variant ? variant.name : null),
                quantity:           Number(line.quantity || 1),
                unit_price:         Number(line.unit_price || productPrice(product, variant)),
                discount_amount:    Number(line.discount_amount || 0),
                tax_amount:         Number(line.tax_amount || 0),
                product:            product,
                variant:            variant || null,
            });
        });

        _currentHeldSaleId = sale.id;
        _currentHeldSaleNo = sale.sale_no;
        _lastSaleId        = sale.id;
        _lastSaleNo        = sale.sale_no;
        const heldInput = document.querySelector('input[name="held_sale_id"]');
        if (heldInput) heldInput.value = sale.id;

        // Sync order type from the recalled sale
        if (sale.order_type && orderTypeEl) {
            orderTypeEl.value = sale.order_type;
            document.querySelectorAll('[data-mode-tab]').forEach(function (b) {
                b.classList.toggle('active', b.dataset.modeTab === sale.order_type);
            });
            const dineBoard = document.getElementById('dine-in-board');
            if (dineBoard) dineBoard.style.display = sale.order_type === 'dine_in' ? '' : 'none';
        }

        // Sync terminal if provided
        if (sale.terminal_id !== undefined && terminalEl) {
            terminalEl.value = sale.terminal_id || '';
        }

        // Sync table session and table for dine-in
        const tableSessionInput = document.querySelector('input[name="restaurant_table_session_id"]');
        if (tableSessionInput) tableSessionInput.value = sale.restaurant_table_session_id || '';
        const tableIdInput = document.querySelector('input[name="restaurant_table_id"]');
        if (tableIdInput) tableIdInput.value = sale.restaurant_table_id || '';

        renderCart();
        updateSplitBillBtn();
        updateRecalledBar();
        lockOrderControls();
        toast('info', 'Recalled: ' + sale.sale_no);
    }

    function cancelHeldSaleFromModal(saleId, saleNo, btn) {
        if (!confirm('Cancel ' + saleNo + '?')) return;
        btn.disabled    = true;
        btn.textContent = '…';

        fetch('{{ url('/held-sales') }}/' + saleId + '/cancel', {
            method:  'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body:    '{}',
        })
        .then(function (r) { return r.json(); })
        .then(function () {
            const row = btn.closest('tr');
            if (row) row.remove();
            // If we just cancelled the currently-recalled sale, clear the cart
            if (_currentHeldSaleId === saleId) clearCart();
            toast('success', saleNo + ' cancelled');
        })
        .catch(function () {
            btn.disabled    = false;
            btn.textContent = 'Cancel';
        });
    }

    document.getElementById('held-orders-btn').addEventListener('click', function () {
        new bootstrap.Modal(heldSalesModalEl).show();
    });

    document.getElementById('start-fresh-btn').addEventListener('click', function () {
        Swal.fire({
            title: 'Start Fresh Order?',
            html: 'This will unload recalled order <strong>' + _currentHeldSaleNo + '</strong> from the cart.<br>The held sale is still saved and can be recalled again.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, New Order',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
        }).then(function (r) { if (r.isConfirmed) clearCart(); });
    });

    /* ── Change Order Details modal ──────────────────────────────────── */

    const changeOrderModalEl = document.getElementById('changeOrderModal');
    const coOrderTypeEl      = document.getElementById('co-order-type');
    const coTableWrapEl      = document.getElementById('co-table-wrap');
    const coTableSessionEl   = document.getElementById('co-table-session');
    const coTerminalEl       = document.getElementById('co-terminal');
    const coBranchEl         = document.getElementById('co-branch');

    function coSetActiveType(type) {
        coOrderTypeEl.value = type;
        document.querySelectorAll('.co-type-btn').forEach(function (b) {
            b.classList.toggle('btn-primary',   b.dataset.coType === type);
            b.classList.toggle('btn-outline-secondary', b.dataset.coType !== type);
        });
        const isDine = type === 'dine_in';
        coTableWrapEl.style.display = isDine ? '' : 'none';
        if (isDine) coLoadTableSessions();
    }

    function coLoadTableSessions() {
        const branchId = coBranchEl.value;
        coTableSessionEl.innerHTML = '<option value="">Loading…</option>';
        fetch('{{ url('/api/pos/table-sessions') }}?branch_id=' + encodeURIComponent(branchId), {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            const currentTableId = (document.getElementById('restaurant_table_id') || {}).value || '';
            if (!data.sessions || !data.sessions.length) {
                coTableSessionEl.innerHTML = '<option value="">No tables found for this branch</option>';
                return;
            }
            coTableSessionEl.innerHTML = '<option value="">— Select Table —</option>' +
                (data.sessions || []).map(function (s) {
                    const waiter  = s.waiter ? ' (' + s.waiter + ')' : '';
                    const status  = s.has_session ? '' : ' ✦ New';
                    const sel     = s.table_id == currentTableId ? ' selected' : '';
                    return '<option value="' + s.table_id + '"' +
                           ' data-session-id="' + (s.session_id || '') + '"' +
                           sel + '>' + s.label + waiter + status + '</option>';
                }).join('');
        })
        .catch(function () {
            coTableSessionEl.innerHTML = '<option value="">Failed to load tables</option>';
        });
    }

    // Populate modal when it opens
    changeOrderModalEl.addEventListener('show.bs.modal', function () {
        coTerminalEl.value = terminalEl ? terminalEl.value : '';
        coBranchEl.value   = branchEl  ? branchEl.value  : '';

        const currentType = orderTypeEl ? orderTypeEl.value : 'quick_sale';
        coSetActiveType(currentType);
    });

    // Order type button clicks
    document.querySelectorAll('.co-type-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            coSetActiveType(btn.dataset.coType);
        });
    });

    // Branch change inside modal triggers table session reload when dine_in
    coBranchEl.addEventListener('change', function () {
        if (coOrderTypeEl.value === 'dine_in') coLoadTableSessions();
    });

    // Apply Changes
    document.getElementById('co-apply-btn').addEventListener('click', function () {
        const newType     = coOrderTypeEl.value;
        const newTerminal = coTerminalEl.value;
        const newBranch   = coBranchEl.value;

        // For dine_in: table_id is the option value; session_id is in the data attribute
        const isDineIn    = newType === 'dine_in';
        const newTableId  = isDineIn ? coTableSessionEl.value : '';
        const selectedOpt = isDineIn ? coTableSessionEl.options[coTableSessionEl.selectedIndex] : null;
        const newSessionId = (selectedOpt && selectedOpt.dataset.sessionId) ? selectedOpt.dataset.sessionId : '';

        if (!newType) { toast('warning', 'Please select an order type'); return; }
        if (isDineIn && !newTableId) { toast('warning', 'Please select a table'); return; }

        const branchChanged = branchEl && newBranch && newBranch !== branchEl.value;

        if (branchChanged) {
            bootstrap.Modal.getInstance(changeOrderModalEl).hide();
            Swal.fire({
                title: 'Change Branch?',
                html: 'Changing branch will reload the POS page.<br>The current cart will be cleared.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, change branch',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc3545',
                reverseButtons: true,
            }).then(function (r) {
                if (r.isConfirmed) {
                    window.location.href = '{{ url('/pos') }}?branch_id=' + newBranch + '&mode=' + newType;
                }
            });
            return;
        }

        // Apply order type
        if (orderTypeEl) orderTypeEl.value = newType;

        // Sync mode tabs visual state
        document.querySelectorAll('[data-mode-tab]').forEach(function (b) {
            b.classList.toggle('active', b.dataset.modeTab === newType);
        });

        // Show/hide dine-in board
        const dineBoard = document.getElementById('dine-in-board');
        if (dineBoard) dineBoard.style.display = newType === 'dine_in' ? '' : 'none';

        // Apply terminal
        if (terminalEl) terminalEl.value = newTerminal;

        // Apply table + session (session may be '' — backend auto-creates when table_id is set)
        const tableSessionInput = document.getElementById('restaurant_table_session_id');
        if (tableSessionInput) tableSessionInput.value = newSessionId;
        const tableIdInput = document.getElementById('restaurant_table_id');
        if (tableIdInput) tableIdInput.value = newTableId;

        // Re-lock controls (they should already be locked, but ensure)
        lockOrderControls();

        bootstrap.Modal.getInstance(changeOrderModalEl).hide();
        toast('success', 'Order details updated');
    });

    /* ── Recent Prints modal ─────────────────────────────────────────── */

    const lastPrintModalEl = document.getElementById('lastPrintModal');

    function openRecentPrints() {
        if (!_lastSaleId) { toast('warning', 'No recent sale to reprint'); return; }
        document.getElementById('last-print-sale-no').textContent = _lastSaleNo || _lastSaleId;
        if (!_lastPrintModal) _lastPrintModal = new bootstrap.Modal(lastPrintModalEl);
        loadRecentPrintJobs();
        _lastPrintModal.show();
    }

    function loadRecentPrintJobs() {
        const body = document.getElementById('last-print-modal-body');
        body.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-secondary" role="status"></div></div>';

        fetch('{{ url('/api/pos/print-jobs') }}/' + _lastSaleId, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.jobs || !data.jobs.length) {
                body.innerHTML = '<p class="text-muted text-center py-5 mb-0">No print jobs found for this order.</p>';
                return;
            }

            const statusBadge = function (s) {
                const map = { printed: 'success', queued: 'secondary', claimed: 'info', failed: 'danger', cancelled: 'warning' };
                return '<span class="badge bg-' + (map[s] || 'secondary') + '">' + s + '</span>';
            };
            const typeBadge = function (t) {
                return t === 'kot'
                    ? '<span class="badge bg-warning text-dark"><i class="ti ti-tool-kitchen-2 me-1"></i>KOT</span>'
                    : '<span class="badge bg-primary"><i class="ti ti-receipt me-1"></i>Receipt</span>';
            };

            let html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">' +
                '<thead class="table-light"><tr>' +
                '<th class="ps-3">Job No</th><th>Type</th><th>Status</th><th>Printer</th>' +
                '<th>Items</th><th>Time</th><th class="pe-3 text-end">Action</th>' +
                '</tr></thead><tbody>';

            let hasFailed = false;

            data.jobs.forEach(function (j) {
                if (j.print_status === 'failed') hasFailed = true;

                const itemsCell = j.document_type === 'kot'
                    ? (j.line_count > 0 ? j.line_count + ' item' + (j.line_count !== 1 ? 's' : '') : 'All items')
                    : '—';

                const viewBtn = j.fallback
                    ? '<a href="' + j.preview_url + '" target="_blank" class="btn btn-sm btn-outline-info py-0 me-1"><i class="ti ti-eye me-1"></i>View</a>'
                    : '';

                const retryBtn = j.print_status === 'failed'
                    ? '<button class="btn btn-sm btn-danger py-0 me-1" data-retry-job="' + j.id + '"><i class="ti ti-refresh me-1"></i>Retry</button>'
                    : '';

                html += '<tr' + (j.print_status === 'failed' ? ' class="table-danger"' : '') + '>' +
                    '<td class="ps-3 fw-semibold small">' + j.job_no + '</td>' +
                    '<td>' + typeBadge(j.document_type) + '</td>' +
                    '<td>' + statusBadge(j.print_status) + '</td>' +
                    '<td class="text-muted small">' + j.printer_name + '</td>' +
                    '<td class="text-muted small">' + itemsCell + '</td>' +
                    '<td class="text-muted small">' + j.created_at + '</td>' +
                    '<td class="pe-3 text-end">' +
                        viewBtn +
                        retryBtn +
                        '<button class="btn btn-sm btn-outline-secondary py-0" data-requeue-job="' + j.id + '" data-job-type="' + j.document_type + '">' +
                            '<i class="ti ti-printer me-1"></i>Reprint' +
                        '</button>' +
                    '</td>' +
                '</tr>';
            });

            html += '</tbody></table></div>';
            body.innerHTML = html;

            // Printer button turns red if any failed jobs exist for this sale
            const printerBtn = document.getElementById('last-print-btn');
            if (printerBtn) {
                printerBtn.classList.toggle('btn-outline-danger', hasFailed);
                printerBtn.classList.toggle('btn-outline-secondary', !hasFailed);
                printerBtn.title = hasFailed ? 'Failed print jobs — click to retry' : 'Reprint Last KOT';
            }

            // Wire per-row reprint buttons
            body.querySelectorAll('[data-requeue-job]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    requeueSingleJob(Number(btn.dataset.requeueJob), btn.dataset.jobType, btn);
                });
            });

            // Wire per-row retry buttons
            body.querySelectorAll('[data-retry-job]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    retryPrintJob(Number(btn.dataset.retryJob), btn);
                });
            });
        })
        .catch(function () {
            body.innerHTML = '<div class="alert alert-danger m-3">Failed to load print jobs.</div>';
        });
    }

    /* Open browser preview tabs for any fallback jobs in a server response.
       KOT responses: { jobs: [{fallback, preview_url, ...}] }
       Receipt responses: { fallback, preview_url, ... } */
    function openFallbackPreviews(data) {
        (data.jobs || []).forEach(function (job) {
            if (job.fallback || job.printer_type === 'browser') {
                toast('warning', 'No printer found — opening for manual print');
                window.open(job.preview_url, '_blank');
            }
        });
        if ((data.fallback || data.printer_type === 'browser') && data.preview_url) {
            toast('warning', 'No printer found — opening for manual print');
            window.open(data.preview_url, '_blank');
        }
    }

    function requeueSingleJob(jobId, jobType, btn) {
        const terminalId = (document.getElementById('terminal_id') || {}).value || '';
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        if (jobType === 'kot') {
            const base  = '{{ url('/printing/jobs/kot') }}/' + _lastSaleId;
            const query = '?reprint=1' + (terminalId ? '&terminal_id=' + encodeURIComponent(terminalId) : '');
            fetch(base + query, { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                btn.disabled = false; btn.innerHTML = orig;
                openFallbackPreviews(data);
                toast('success', 'KOT re-queued');
                loadRecentPrintJobs();
            }).catch(function () { btn.disabled = false; btn.innerHTML = orig; toast('error', 'Failed'); });
        } else {
            const q = terminalId ? '?terminal_id=' + encodeURIComponent(terminalId) : '';
            fetch('{{ url('/printing/jobs/receipt') }}/' + _lastSaleId + q, { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                btn.disabled = false; btn.innerHTML = orig;
                openFallbackPreviews(data);
                toast('success', 'Receipt re-queued');
                loadRecentPrintJobs();
            }).catch(function () { btn.disabled = false; btn.innerHTML = orig; toast('error', 'Failed'); });
        }
    }

    function retryPrintJob(jobId, btn) {
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        fetch('{{ url('/printing/jobs') }}/' + jobId + '/retry', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = orig;
            if (data.status === 'queued') {
                toast('success', 'Re-queued: ' + data.job_no);
                loadRecentPrintJobs();
            } else {
                toast('warning', data.message || 'Could not retry job');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = orig;
            toast('error', 'Retry failed');
        });
    }

    document.getElementById('last-print-btn').addEventListener('click', openRecentPrints);

    document.getElementById('reprint-all-kot-btn').addEventListener('click', function () {
        const terminalId = (document.getElementById('terminal_id') || {}).value || '';
        const base  = '{{ url('/printing/jobs/kot') }}/' + _lastSaleId;
        const query = '?reprint=1' + (terminalId ? '&terminal_id=' + encodeURIComponent(terminalId) : '');
        fetch(base + query, { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            openFallbackPreviews(data);
            toast('success', 'All KOT re-queued for ' + _lastSaleNo);
            loadRecentPrintJobs();
        })
        .catch(function () { toast('error', 'Failed to reprint KOT'); });
    });

    document.getElementById('reprint-receipt-btn').addEventListener('click', function () {
        const terminalId = (document.getElementById('terminal_id') || {}).value || '';
        const q = terminalId ? '?terminal_id=' + encodeURIComponent(terminalId) : '';
        fetch('{{ url('/printing/jobs/receipt') }}/' + _lastSaleId + q, { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            openFallbackPreviews(data);
            toast('success', 'Receipt re-queued for ' + _lastSaleNo);
            loadRecentPrintJobs();
        })
        .catch(function () { toast('error', 'Failed to reprint receipt'); });
    });

    document.getElementById('complete-sale-btn').addEventListener('click', submitPaidSale);
    document.getElementById('hold-sale-btn').addEventListener('click', submitHeldSale);
    document.getElementById('clear-cart-btn').addEventListener('click', function () {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Clear Cart?', icon: 'question',
                showCancelButton: true, confirmButtonText: 'Clear', cancelButtonText: 'No',
                confirmButtonColor: '#dc3545', reverseButtons: true,
            }).then(function (r) { if (r.isConfirmed) clearCart(); });
        } else if (confirm('Clear cart?')) {
            clearCart();
        }
    });

    tenderedEl.addEventListener('input', function () {
        tenderedEl.dataset.manual = '1';
        updateTotals();
    });

    /* branch change → reload (controls are CSS-locked when recalled; no Swal needed here) */

    if (branchEl) {
        branchEl.addEventListener('change', function () {
            window.location.href = '{{ url('/pos') }}?branch_id=' + branchEl.value + '&mode=' + orderTypeEl.value;
        });
    }

    /* search / barcode scan */

    searchEl.addEventListener('input', function () {
        const query = searchEl.value.trim().toLowerCase();

        const barcodeProduct = products.find(function (product) {
            return (product.barcodes || []).some(function (barcode) {
                return String(barcode).toLowerCase() === query;
            });
        });

        if (barcodeProduct) {
            addToCart(barcodeProduct, barcodeProduct.variants && barcodeProduct.variants.length ? barcodeProduct.variants[0] : null);
            searchEl.value = '';
        }

        renderProducts();
    });

    /* mode tabs — CSS-locked when recalled; click → apply directly when unlocked */

    function applyModeTab(button) {
        const mode = button.dataset.modeTab;
        orderTypeEl.value = mode;
        document.querySelectorAll('[data-mode-tab]').forEach(function (b) { b.classList.remove('active'); });
        button.classList.add('active');
        document.getElementById('dine-in-board').style.display = mode === 'dine_in' ? '' : 'none';
    }

    document.querySelectorAll('[data-mode-tab]').forEach(function (button) {
        button.addEventListener('click', function () { applyModeTab(button); });
    });

    /* parent category filter */

    document.querySelectorAll('[data-parent-category]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-parent-category]').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');

            selectedParentCategory = btn.dataset.parentCategory;
            selectedChildCategory  = '';

            const wrap  = document.getElementById('child-category-wrap');
            const strip = document.getElementById('child-category-strip');
            strip.innerHTML = '';

            const parent = categories.find(function (c) { return Number(c.id) === Number(selectedParentCategory); });

            if (parent && parent.children && parent.children.length) {
                wrap.style.display = '';

                const allBtn     = document.createElement('button');
                allBtn.type      = 'button';
                allBtn.className = 'category-pill active';
                allBtn.textContent = 'All';
                allBtn.addEventListener('click', function () {
                    selectedChildCategory = '';
                    strip.querySelectorAll('.category-pill').forEach(function (b) { b.classList.remove('active'); });
                    allBtn.classList.add('active');
                    renderProducts();
                });
                strip.appendChild(allBtn);

                parent.children.forEach(function (child) {
                    const childBtn     = document.createElement('button');
                    childBtn.type      = 'button';
                    childBtn.className = 'category-pill';
                    childBtn.textContent = child.name;
                    childBtn.addEventListener('click', function () {
                        selectedChildCategory = child.id;
                        strip.querySelectorAll('.category-pill').forEach(function (b) { b.classList.remove('active'); });
                        childBtn.classList.add('active');
                        renderProducts();
                    });
                    strip.appendChild(childBtn);
                });
            } else {
                wrap.style.display = 'none';
            }

            renderProducts();
        });
    });

    /* floor tabs */

    document.querySelectorAll('[data-floor-tab]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-floor-tab]').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            const floorId = btn.dataset.floorTab;
            document.querySelectorAll('[data-floor-panel]').forEach(function (panel) {
                panel.style.display = (!floorId || panel.dataset.floorPanel === floorId) ? '' : 'none';
            });
        });
    });

    /* open table modal — populate table info */

    document.getElementById('openTableModal').addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if (!trigger) return;
        document.getElementById('open-table-no').textContent = trigger.getAttribute('data-table-no');
        document.getElementById('open-table-form').action   = '{{ url('/restaurant/tables') }}/' + trigger.getAttribute('data-table-id') + '/open';
        const errEl = document.getElementById('open-table-error');
        if (errEl) errEl.remove();
        const openBtn = document.getElementById('open-table-submit');
        if (openBtn) { openBtn.disabled = false; openBtn.textContent = 'Open Table'; }
    });

    /* open table modal — AJAX submit (stay on POS, land on session) */

    document.getElementById('open-table-form').addEventListener('submit', function (event) {
        event.preventDefault();
        const form    = this;
        const openBtn = document.getElementById('open-table-submit');
        const errEl   = document.getElementById('open-table-error');
        if (errEl) errEl.remove();

        openBtn.disabled    = true;
        openBtn.textContent = 'Opening…';

        fetch(form.action, {
            method:  'POST',
            headers: {
                'Accept':       'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
            body: new FormData(form),
        })
        .then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, data: data }; });
        })
        .then(function (result) {
            if (result.ok && result.data.session_id) {
                window.location.href = '{{ url('/pos') }}?table_session_id=' + result.data.session_id +
                    '&mode=dine_in&branch_id=' + result.data.branch_id;
            } else {
                const errors  = result.data.errors || {};
                const message = Object.values(errors).flat().join(' ') || result.data.message || 'Failed to open table.';
                const div     = document.createElement('div');
                div.id        = 'open-table-error';
                div.className = 'alert alert-danger mt-2 mb-0';
                div.textContent = message;
                form.querySelector('.modal-body').appendChild(div);
                openBtn.disabled    = false;
                openBtn.textContent = 'Open Table';
            }
        })
        .catch(function () {
            openBtn.disabled    = false;
            openBtn.textContent = 'Open Table';
            alert('Network error. Please try again.');
        });
    });

    /* calculator */

    document.querySelectorAll('[data-key]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const key = btn.dataset.key;
            if (key === 'C') { calcDisplay.value = ''; return; }
            if (key === '=') {
                try {
                    calcDisplay.value = Function('"use strict"; return (' + calcDisplay.value + ')')();
                } catch (e) {
                    calcDisplay.value = 'Error';
                }
                return;
            }
            calcDisplay.value += key;
        });
    });

    /* keyboard shortcuts (Ctrl+key) */

    function toggleCalculator() {
        const isHidden = calculatorPanel.style.display === 'none';
        calculatorPanel.style.display = isHidden ? '' : 'none';
        if (isHidden) { calcDisplay.focus(); }
    }

    document.getElementById('toggle-calc-btn').addEventListener('click', toggleCalculator);

    document.addEventListener('keydown', function (event) {
        if (!event.ctrlKey) return;
        if (event.key === 'f')     { event.preventDefault(); searchEl.focus(); }
        if (event.key === 'h')     { event.preventDefault(); submitHeldSale(); }
        if (event.key === 'l')     { event.preventDefault(); new bootstrap.Modal(heldSalesModalEl).show(); }
        if (event.key === 'p')     { event.preventDefault(); paymentMethodEl.focus(); }
        if (event.key === 'Enter') { event.preventDefault(); submitPaidSale(); }
        if (event.key === 'm')     { event.preventDefault(); toggleCalculator(); }
    });

    /* preload held sale (page-load recall via ?held_sale_id=) */

    if (heldSale && heldSale.lines) {
        _currentHeldSaleId = heldSale.id;
        _currentHeldSaleNo = heldSale.sale_no || '';

        heldSale.lines.forEach(function (line) {
            const product = products.find(function (p) { return Number(p.id) === Number(line.product_id); });
            if (!product) return;

            const variant = (product.variants || []).find(function (v) { return Number(v.id) === Number(line.product_variant_id); });

            cart.push({
                key:                product.id + ':' + (variant ? variant.id : 0),
                product_id:         product.id,
                product_variant_id: variant ? variant.id : null,
                name:               product.name,
                product_name:       product.name,
                variant_name:       variant ? variant.name : null,
                quantity:           Number(line.quantity || 1),
                unit_price:         Number(line.unit_price || productPrice(product, variant)),
                discount_amount:    Number(line.discount_amount || 0),
                tax_amount:         Number(line.tax_amount || 0),
                product:            product,
                variant:            variant || null,
                _dbLineId:          line.id || null,
                kot_sent:           !!line.kot_sent,
            });
        });
    }

    /* preload: also track last sale for reprint if page-loaded with held sale */
    if (heldSale) {
        _lastSaleId = heldSale.id;
        _lastSaleNo = heldSale.sale_no || '';
    }

    /* initial render */
    renderProducts();
    renderCart();
    updateSplitBillBtn();
    updateRecalledBar();
    if (_currentHeldSaleId) lockOrderControls();
});
</script>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
@endpush
@endsection
