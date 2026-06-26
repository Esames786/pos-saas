@extends('layouts.app')

@section('title', 'Restaurant POS')

@section('content')
<style>
    .pos-shell {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 500px;
        gap: 1rem;
        align-items: start;
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
        padding: .15rem 0;
    }

    .mode-tab {
        border: 1px solid #e9ecef;
        background: #fff;
        border-radius: 999px;
        padding: .6rem 1rem;
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
        max-height: 330px;
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
    .restaurant-table-tile.selected      { border: 2px solid #111827; border-left: 6px solid #111827; background: #f8fafc; box-shadow: 0 14px 34px rgba(15,23,42,.16); }
    .restaurant-table-tile.selected .status-chip { background: #111827; color: #fff; }

    .status-chip {
        border-radius: 999px;
        background: #f8fafc;
        padding: .25rem .55rem;
        font-size: .72rem;
        font-weight: 800;
    }

    .product-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(145px, 1fr));
        gap: .7rem;
        max-height: calc(100vh - 340px);
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
        min-height: 138px;
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

    .cart-panel {
        position: sticky;
        top: 88px;
        height: calc(100vh - 104px);
        display: flex;
        flex-direction: column;
        gap: .75rem;
    }

    .cart-section {
        display: flex;
        flex: 1 1 380px;
        min-height: 360px;
        flex-direction: column;
        margin-bottom: 0 !important;
    }

    .cart-items { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding-right: .25rem; }

    .payment-section,
    .pos-actions { flex: 0 0 auto; margin-bottom: 0 !important; }

    /* Charge bar — replaces the inline payment panel; opens the payment modal */
    .pos-charge-bar { flex: 0 0 auto; display: flex; align-items: center; gap: .9rem; padding: .85rem 1.1rem; margin-bottom: 0 !important; }
    .pos-charge-bar .pos-charge-amt { font-size: 1.6rem; font-weight: 800; line-height: 1.1; }

    details.payment-section { overflow: hidden; }

    details.payment-section summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
        list-style: none;
        padding: .8rem 1rem;
        font-weight: 800;
    }

    details.payment-section summary::-webkit-details-marker { display: none; }
    details.payment-section summary::after { content: '+'; font-size: 1.25rem; color: #667085; }
    details.payment-section[open] summary::after { content: '-'; }

    .cart-row {
        border-bottom: 1px solid #eef0f3;
        padding: .55rem 0;
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
        .cart-panel     { position: static; height: auto; }
        .cart-section   { min-height: 280px; }
    }

    @media (min-width: 1200px) {
        #dine-in-board.is-collapsed { display: none !important; }
    }

    .waiter-roster {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(145px, 1fr));
        gap: .5rem;
    }

    .waiter-choice {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fff;
        color: #172033;
        display: flex;
        align-items: center;
        gap: .55rem;
        padding: .55rem;
        text-align: left;
    }

    .waiter-choice:hover,
    .waiter-choice.is-selected { border-color: #15244d; background: #f3f6ff; }

    .waiter-initials {
        width: 32px;
        height: 32px;
        display: grid;
        place-items: center;
        border-radius: 50%;
        background: #eaf0fb;
        color: #15244d;
        font-size: .75rem;
        font-weight: 800;
        flex: 0 0 auto;
    }

    .session-context { min-width: 0; }
    .session-context strong { font-size: 1rem; }

    #pos-sidebar-toggle {
        width: 38px;
        height: 38px;
        display: inline-grid;
        place-items: center;
    }
</style>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-outline-secondary" id="pos-sidebar-toggle" title="Show navigation" aria-label="Show navigation">
            <i class="ti ti-layout-sidebar-left-expand"></i>
        </button>
        <h1 class="h3 mb-0">Restaurant POS</h1>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

@if(session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

{{-- Selected table-session bar — JS-managed: always in the DOM, shown when a dine-in
     session is active. Filled by applyTableSession() so switching tables needs no reload. --}}
<div id="pos-session-bar" class="pos-card p-3 mb-3 d-flex flex-wrap align-items-center gap-2 {{ $tableSession ? '' : 'd-none' }}"
     data-session-base="{{ url('/restaurant/table-sessions') }}"
     style="{{ $tableSession ? '' : 'display:none;' }}">
    <div class="session-context">
        <strong>Table <span id="pos-session-table-no">{{ $tableSession?->table?->table_no }}</span></strong>
        <span class="text-muted ms-1" id="pos-session-no">{{ $tableSession?->session_no }}</span>
        &middot; <span id="pos-session-waiter">{{ $tableSession?->waiter?->name ?? 'No waiter' }}</span>
        &middot; <span id="pos-session-guests">{{ $tableSession?->guest_count }}</span> guests
    </div>
    <div class="d-flex gap-2 ms-auto flex-wrap">
        <button type="button" id="change-table-btn" class="btn btn-sm btn-outline-secondary" title="Change table">
            <i class="ti ti-layout-grid"></i>
        </button>
        @can('tenant.restaurant.table-sessions.bill-preview')
            <a href="{{ $tableSession ? url('/restaurant/table-sessions/' . $tableSession->id . '/bill-preview') : '#' }}"
               id="pos-session-bill-preview" target="_blank" rel="noopener" class="btn btn-sm btn-dark">Bill Preview</a>
        @endcan
        @can('tenant.restaurant.table-sessions.bill-requested')
            <form method="POST" id="pos-session-request-bill-form" class="d-inline"
                  action="{{ $tableSession ? url('/restaurant/table-sessions/' . $tableSession->id . '/bill-requested') : '#' }}"
                  style="{{ $tableSession && $tableSession->status === 'open' ? '' : 'display:none;' }}">
                @csrf
                <button class="btn btn-sm btn-info" type="submit">Request Bill</button>
            </form>
        @endcan
    </div>
</div>

@if($heldSale)
    <div class="alert alert-warning" role="status">
        Recalling held sale: <strong>{{ $heldSale->sale_no }}</strong>
    </div>
@endif

{{-- Mode tabs --}}
<div class="mb-3">
    <div class="mode-tabs" id="mode-tabs-wrapper" role="tablist" aria-label="POS Modes">
        <button type="button" class="mode-tab {{ $activeMode === 'dine_in'    ? 'active' : '' }}" data-mode-tab="dine_in">Dine In</button>
        <button type="button" class="mode-tab {{ $activeMode === 'takeaway'   ? 'active' : '' }}" data-mode-tab="takeaway">Takeaway</button>
        <button type="button" class="mode-tab {{ $activeMode === 'quick_sale' ? 'active' : '' }}" data-mode-tab="quick_sale">Quick Sale</button>
        <button type="button" class="mode-tab {{ $activeMode === 'delivery'   ? 'active' : '' }}" data-mode-tab="delivery">Delivery</button>
    </div>
</div>

{{-- Live table board --}}
<div id="dine-in-board" class="pos-card p-3 mb-3 {{ $tableSession ? 'is-collapsed' : '' }}" style="{{ $activeMode === 'dine_in' && !$tableSession ? '' : 'display:none;' }}">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Live Table Board</h2>
            <p class="text-muted mb-0">Open table, select an active table, then save order or close bill.</p>
        </div>
        @can('tenant.restaurant.board')
            <a href="{{ url('/restaurant/board?branch_id=' . $selectedBranchId) }}" class="btn btn-sm btn-light">Full Board</a>
        @endcan
    </div>

    {{-- Refreshable region: re-rendered in place by refreshTableBoard() after
         open / continue / select, so the board stays accurate with no full reload. --}}
    <div id="table-board-body" data-board-url="{{ url('/api/pos/table-board') }}">
        @include('tenant.pos.partials.table-board')
    </div>
</div>

{{-- POS form --}}
<form id="pos-sale-form" method="POST" action="{{ url('/pos') }}">
    @csrf
    <input type="hidden" name="order_source"                id="pos-order-source"      value="pos">
    <input type="hidden" name="held_sale_id"                                            value="{{ $heldSale?->id }}">
    <input type="hidden" name="restaurant_table_session_id" id="restaurant_table_session_id" value="{{ $tableSession?->id ?? $heldSale?->restaurant_table_session_id }}">
    <input type="hidden" name="restaurant_table_id"         id="restaurant_table_id"         value="{{ $heldSale?->restaurant_table_id }}">
    <input type="hidden" name="create_separate_order"       id="create_separate_order"       value="{{ request()->boolean('create_separate_order') ? '1' : '0' }}">
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
                    @if(count($combosPayload))
                        <button type="button" class="category-pill" data-parent-category="__deals__">Deals</button>
                    @endif
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
            <section class="pos-card p-3 cart-section" aria-labelledby="cart_heading">
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

            {{-- Charge bar: always-visible total + opens the Payment modal (payment moved to a modal for space) --}}
            <div class="pos-card pos-charge-bar">
                <div class="flex-shrink-0">
                    <div class="text-muted small">Total</div>
                    <div class="pos-charge-amt" id="pos-charge-total">0.00</div>
                </div>
                <button type="button" class="btn btn-primary btn-lg flex-grow-1" id="review-pay-btn">
                    <i class="ti ti-cash-register me-1"></i>{{ $tableSession ? 'Close & Pay Bill' : 'Review & Pay' }}
                </button>
            </div>

            <section class="pos-card p-3 payment-section" id="calculator-panel" style="display:none;" aria-labelledby="calculator_heading">
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

            <div class="d-grid gap-2 pos-actions">
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
                <div class="row g-2">
                    <div class="col">
                        <button type="button" class="btn btn-outline-secondary btn-lg w-100" id="bill-preview-btn"
                                title="Show / print the current bill (preview — not a tax receipt)">
                            <i class="ti ti-file-text me-1"></i>Bill / Preview
                        </button>
                    </div>
                    <div class="col">
                        <button type="button" class="btn btn-outline-secondary btn-lg w-100" id="completed-orders-btn"
                                title="Completed orders — reprint receipt / KOT">
                            <i class="ti ti-receipt-2 me-1"></i>Completed
                        </button>
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col">
                        <button type="button" class="btn btn-outline-danger btn-lg w-100" id="cancel-order-btn">Cancel Order</button>
                    </div>
                    <div class="col" id="split-bill-wrap" style="display:none">
                        <a href="#" target="_blank" rel="noopener" class="btn btn-outline-info btn-lg w-100" id="split-bill-link">Split Bill</a>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-secondary btn-lg px-3" id="last-print-btn"
                                title="Print history">
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

    {{-- Payment modal (opened by "Review & Pay"). Kept inside #pos-sale-form; all IDs
         preserved so the existing POS JS reads values by id regardless of location. --}}
    <div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="paymentModalLabel"><span id="payment_heading">Payment</span></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-4">
                        {{-- LEFT: payment inputs --}}
                        <div class="col-lg-7">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label for="payment_method_id" class="form-label required">Payment Method</label>
                                    <select id="payment_method_id" class="form-select" required>
                                        @foreach($paymentMethods as $method)
                                            <option value="{{ $method->id }}" data-type="{{ $method->method_type }}" @selected($method->method_type === 'cash')>{{ $method->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <label for="tendered_amount" class="form-label">Tendered Amount</label>
                                    <input id="tendered_amount" type="number" step="0.01" min="0" class="form-control form-control-lg">
                                    <div class="d-flex gap-1 flex-wrap mt-1" id="quick-cash-buttons"></div>
                                </div>
                                <div class="col-12">
                                    <label for="transaction_ref" class="form-label">Reference / Card / Bank</label>
                                    <input id="transaction_ref" class="form-control" placeholder="Optional reference">
                                </div>
                                <div class="col-12">
                                    {{-- Promo Code Input --}}
                                    <div class="d-flex gap-1" id="promo-row">
                                        <input type="text" id="promo-code-input" class="form-control form-control-sm" placeholder="Promo code" style="text-transform:uppercase">
                                        <button type="button" class="btn btn-sm btn-outline-primary px-2" id="apply-promo-btn">Apply</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary px-2 d-none" id="remove-promo-btn">✕</button>
                                    </div>
                                    <div id="promo-feedback" class="small mt-1"></div>
                                </div>
                                <div class="col-12">
                                    {{-- Tip Buttons --}}
                                    <div class="d-flex gap-1 flex-wrap">
                                        <span class="small text-muted me-1 align-self-center">Tip:</span>
                                        <button type="button" class="btn btn-xs btn-outline-secondary tip-btn" data-tip-type="percent" data-tip-value="0">No Tip</button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary tip-btn" data-tip-type="percent" data-tip-value="5">5%</button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary tip-btn" data-tip-type="percent" data-tip-value="10">10%</button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary tip-btn" data-tip-type="custom">Custom</button>
                                    </div>
                                </div>
                                {{-- Printing panel: live status + temporary (this-device) auto-print overrides --}}
                                <div class="col-12">
                                    <div class="border rounded p-2" id="print-pref-panel">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="fw-semibold small"><i class="ti ti-printer me-1"></i>Printing</span>
                                            <span class="text-muted small" id="print-terminal-label">No terminal</span>
                                        </div>
                                        <div class="form-check form-switch mb-1">
                                            <input class="form-check-input" type="checkbox" id="auto-kot-toggle">
                                            <label class="form-check-label small" for="auto-kot-toggle">
                                                Auto-print Kitchen Ticket (KOT)
                                                <span class="text-muted d-block" id="kot-status-hint">—</span>
                                            </label>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="auto-receipt-toggle">
                                            <label class="form-check-label small" for="auto-receipt-toggle">
                                                Auto-print Receipt
                                                <span class="text-muted d-block" id="receipt-status-hint">—</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- RIGHT: totals summary --}}
                        <div class="col-lg-5">
                            <div class="border rounded p-3 bg-light h-100">
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
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">Back</button>
                    <button type="button" class="btn btn-primary btn-lg flex-grow-1" id="complete-sale-btn">
                        {{ $tableSession ? 'Close & Pay Table Bill' : 'Complete Sale' }}
                    </button>
                </div>
            </div>
        </div>
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
                    <select id="restaurant_waiter_id" name="restaurant_waiter_id" class="form-select visually-hidden">
                        <option value="">No Waiter</option>
                        @foreach($waiters as $waiter)
                            <option value="{{ $waiter->id }}">{{ $waiter->name }}</option>
                        @endforeach
                    </select>
                    @if($waiters->isNotEmpty())
                        <div class="waiter-roster" id="waiter-roster" role="listbox" aria-label="Waiter selection">
                            @foreach($waiters as $waiter)
                                @php $initials = collect(explode(' ', $waiter->name))->filter()->map(fn ($part) => strtoupper(substr($part, 0, 1)))->take(2)->implode(''); @endphp
                                <button type="button" class="waiter-choice" data-waiter-choice="{{ $waiter->id }}" role="option" aria-selected="false">
                                    <span class="waiter-initials">{{ $initials }}</span>
                                    <span class="fw-semibold small">{{ $waiter->name }}</span>
                                </button>
                            @endforeach
                        </div>
                    @else
                        <div class="alert alert-light border mb-0 small">No active waiters are assigned to this branch.</div>
                    @endif
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

{{-- Completed Orders Modal — recent paid sales, reprint receipt/KOT/view --}}
<div class="modal fade" id="completedOrdersModal" tabindex="-1" aria-labelledby="completedOrdersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="completedOrdersModalLabel">
                    <i class="ti ti-receipt-2 me-2"></i>Completed Orders
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="completed-orders-modal-body">
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

{{-- Quantity Entry Modal (measurable/weighted items) --}}
<div class="modal fade" id="qtyEntryModal" tabindex="-1" aria-labelledby="qtyEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="qtyEntryModalLabel">Enter Quantity</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3 py-2">
                    <strong id="qty-modal-product-name"></strong>
                    <div class="text-muted" id="qty-modal-price-hint"></div>
                </div>
                <div class="mb-3">
                    <label for="qty-modal-input" class="form-label">
                        Quantity <span id="qty-modal-unit" class="text-muted fw-normal"></span>
                    </label>
                    <input type="number" id="qty-modal-input"
                           class="form-control form-control-lg text-end"
                           step="0.001" min="0.001" placeholder="0.000">
                </div>
                <div class="mb-0">
                    <label for="qty-modal-amount-input" class="form-label small text-muted">Or enter amount</label>
                    <input type="number" id="qty-modal-amount-input"
                           class="form-control text-end"
                           step="0.01" min="0" placeholder="Amount (Rs)">
                    <div class="form-text">Amount ÷ price/unit = quantity</div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="button" id="qty-modal-confirm">Add to Cart</button>
            </div>
        </div>
    </div>
</div>

{{-- Modifier Entry Modal (MOD-2) --}}
<div class="modal fade" id="modifierEntryModal" tabindex="-1" aria-labelledby="modifierEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="modifierEntryModalLabel">Choose Modifiers</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3 py-2">
                    <strong id="modifier-modal-product-name"></strong>
                    <div class="text-muted" id="modifier-modal-price-hint"></div>
                </div>
                <div id="modifier-modal-groups"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="button" id="modifier-modal-confirm">Add to Cart</button>
            </div>
        </div>
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
            'parent_sales_order_line_id' => $l->parent_sales_order_line_id ? (int) $l->parent_sales_order_line_id : null,
            'line_kind'          => $l->line_kind ?? 'standard',
            'combo_id'           => $l->combo_id ? (int) $l->combo_id : null,
            'quantity'           => (float) $l->quantity,
            'unit_price'         => (float) $l->unit_price,
            'discount_amount'    => (float) $l->discount_amount,
            'tax_amount'         => (float) $l->tax_amount,
            'kot_sent'           => (bool) $l->kot_sent,
            'product_name'       => $l->product_name,
            'unit_code'          => $l->unit_code,
            'modifiers'          => $l->modifiers ?? [],
        ])->values()->toArray(),
    ] : null;
@endphp

<script>
document.body.classList.remove('mini-sidebar', 'expand-menu');
document.body.classList.add('nosidebar');
document.addEventListener('DOMContentLoaded', function () {
    const products   = @json($productsPayload);
    const combos     = @json($combosPayload);
    const categories = @json($categories);
    const heldSale   = @json($heldSaleJson);

    function buildPosUrl(params) {
        params = params || {};
        var url = new URL('{{ url('/pos') }}', window.location.origin);
        Object.keys(params).forEach(function (key) {
            var val = params[key];
            if (val !== null && val !== undefined && val !== '') {
                url.searchParams.set(key, val);
            }
        });
        return url.toString();
    }

    function clearTableStateInputs() {
        var heldInput        = document.querySelector('input[name="held_sale_id"]');
        var tableSessionInput = document.getElementById('restaurant_table_session_id');
        var tableIdInput     = document.getElementById('restaurant_table_id');
        var separateInput    = document.getElementById('create_separate_order');
        if (heldInput)        heldInput.value        = '';
        if (tableSessionInput) tableSessionInput.value = '';
        if (tableIdInput)     tableIdInput.value     = '';
        if (separateInput)    separateInput.value    = '0';
        _currentHeldSaleId = null;
        _currentHeldSaleNo = null;
    }

    /* ── No-reload dine-in session state ──────────────────────────────────
       Drive the POS into / out of "table session active" mode entirely on the
       client — mirrors what a fresh /pos?table_session_id= render would show,
       so opening / continuing / selecting a table never reloads the page. */

    function setHidden(name, value) {
        var el = document.getElementById(name) || document.querySelector('input[name="' + name + '"]');
        if (el) el.value = (value === null || value === undefined) ? '' : value;
    }

    function setCompleteSaleLabel(isTableSession) {
        var btn = document.getElementById('complete-sale-btn');
        if (btn) btn.textContent = isTableSession ? 'Close & Pay Table Bill' : 'Complete Sale';
    }

    function forceDineInMode() {
        if (orderTypeEl) orderTypeEl.value = 'dine_in';
        document.querySelectorAll('[data-mode-tab]').forEach(function (b) {
            b.classList.toggle('active', b.dataset.modeTab === 'dine_in');
        });
        var board = document.getElementById('dine-in-board');
        if (board) board.style.display = '';
    }

    function applyTableSession(session) {
        if (!session) return;
        var bar = document.getElementById('pos-session-bar');
        if (bar) {
            var base = bar.dataset.sessionBase;
            var put  = function (id, val) { var e = document.getElementById(id); if (e) e.textContent = (val == null ? '' : val); };
            put('pos-session-table-no', session.table_no);
            put('pos-session-no',       session.session_no);
            put('pos-session-waiter',   session.waiter_name || 'No waiter');
            put('pos-session-guests',   session.guest_count);
            var bp = document.getElementById('pos-session-bill-preview');
            if (bp && base) bp.href = base + '/' + session.id + '/bill-preview';
            var rb = document.getElementById('pos-session-request-bill-form');
            if (rb && base) {
                rb.action = base + '/' + session.id + '/bill-requested';
                rb.style.display = (!session.status || session.status === 'open') ? '' : 'none';
            }
            bar.classList.remove('d-none');
            bar.style.display = '';
        }
        forceDineInMode();
        var board = document.getElementById('dine-in-board');
        if (board) {
            board.classList.add('is-collapsed');
            board.style.display = 'none';
        }
        setHidden('restaurant_table_session_id', session.id);
        setHidden('restaurant_table_id', session.table_id || '');
        setCompleteSaleLabel(true);
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, '', buildPosUrl({
                table_session_id: session.id,
                mode:             'dine_in',
                branch_id:        session.branch_id || (branchEl ? branchEl.value : ''),
            }));
        }
    }

    function refreshTableBoard(selectedSessionId) {
        var body = document.getElementById('table-board-body');
        if (!body || !body.dataset.boardUrl) return;
        var bid = branchEl ? branchEl.value : '{{ $selectedBranchId }}';
        var qs  = '?branch_id=' + encodeURIComponent(bid);
        if (selectedSessionId) qs += '&selected_session_id=' + encodeURIComponent(selectedSessionId);
        fetch(body.dataset.boardUrl + qs, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) { if (data && data.ok) body.innerHTML = data.html; })
        .catch(function () { /* leave the board as-is on failure */ });
    }

    function continueTableSession(sessionId, branchId, fallbackHref) {
        fetch('{{ url('/api/pos/table-sessions') }}/' + sessionId + '/open-orders', {
            headers: { 'Accept': 'application/json' },
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.ok) { if (fallbackHref) window.location.href = fallbackHref; return; }
            var session = data.session || { id: sessionId, branch_id: branchId };
            if (!data.orders || !data.orders.length) {
                clearCart();                 // table is open but has no held order yet
                applyTableSession(session);
                refreshTableBoard(session.id);
                return;
            }
            showOpenOrdersChoice(data.orders, session);
        })
        .catch(function () { if (fallbackHref) window.location.href = fallbackHref; });
    }

    function continueExistingOrder(order, session) {
        applyTableSession(session);
        recallHeldSale(order);               // rebuilds cart + sets held id, in place
        refreshTableBoard(session.id);
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, '', buildPosUrl({
                held_sale_id:     order.id,
                table_session_id: session.id,
                mode:             'dine_in',
                branch_id:        session.branch_id || (branchEl ? branchEl.value : ''),
            }));
        }
    }

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

    const posSidebarToggle = document.getElementById('pos-sidebar-toggle');
    if (posSidebarToggle) {
        posSidebarToggle.addEventListener('click', function () {
            const hidden = document.body.classList.toggle('nosidebar');
            posSidebarToggle.title = hidden ? 'Show navigation' : 'Hide navigation';
            posSidebarToggle.setAttribute('aria-label', posSidebarToggle.title);
            posSidebarToggle.querySelector('i').className = hidden
                ? 'ti ti-layout-sidebar-left-expand'
                : 'ti ti-layout-sidebar-left-collapse';
        });
    }

    let selectedParentCategory = '';
    let selectedChildCategory  = '';
    let cart = [];

    /* helpers */

    function money(value) {
        return Number(value || 0).toFixed(2);
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
        });
    }

    function isMeasurableProduct(product) {
        return !!(product && (product.allow_decimal_qty || ['weight', 'volume', 'length'].indexOf(product.unit_type) !== -1));
    }

    function qtyStep(product) {
        return isMeasurableProduct(product) ? 0.001 : 1;
    }

    function formatQty(qty, product) {
        return isMeasurableProduct(product)
            ? Number(qty || 0).toFixed(3)
            : String(Math.round(Number(qty || 0)));
    }

    function lineUnitLabel(item) {
        return item.unit_code || (item.product && item.product.unit_code) || '';
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

    function activeModifierGroups(product) {
        var branchId = selectedBranchId();
        return (product.modifier_groups || [])
            .filter(function (group) {
                return !group.branch_id || Number(group.branch_id) === branchId;
            })
            .filter(function (group) {
                return (group.modifiers || []).length > 0;
            })
            .sort(function (a, b) {
                return Number(a.sort_order || 0) - Number(b.sort_order || 0);
            });
    }

    function hasModifierGroups(product) {
        return activeModifierGroups(product).length > 0;
    }

    function normalizeModifiers(modifiers) {
        return (modifiers || []).map(function (modifier) {
            return {
                modifier_group_id: Number(modifier.modifier_group_id || 0),
                modifier_group_name: modifier.modifier_group_name || '',
                modifier_id: Number(modifier.modifier_id || 0),
                name: modifier.name || '',
                price_delta: Number(modifier.price_delta || 0),
            };
        }).filter(function (modifier) {
            return modifier.modifier_id > 0 && modifier.name;
        });
    }

    function modifierSignature(modifiers) {
        return normalizeModifiers(modifiers)
            .map(function (modifier) { return modifier.modifier_group_id + ':' + modifier.modifier_id; })
            .sort()
            .join('|');
    }

    function modifierPriceDelta(modifiers) {
        return normalizeModifiers(modifiers).reduce(function (sum, modifier) {
            return sum + Number(modifier.price_delta || 0);
        }, 0);
    }

    function cartKey(product, variant, modifiers) {
        return product.id + ':' + (variant ? variant.id : 0) + ':' + modifierSignature(modifiers);
    }

    function availableQty(product, variant) {
        const branchId = selectedBranchId();
        if (product.is_stock_tracked) {
            if (variant && variant.stock_by_branch) return Number(variant.stock_by_branch[branchId] || 0);
            return Number((product.stock_by_branch || {})[branchId] || 0);
        }
        // Recipe/service product: availability = how many can be MADE from ingredient stock.
        if (product.is_recipe && product.makeable_by_branch) {
            return Number(product.makeable_by_branch[branchId] || 0);
        }
        return null; // plain service (no ingredients) — unlimited
    }

    // Name of the ingredient limiting a recipe product at the current branch (for messages).
    function limitingIngredient(product) {
        if (product && product.is_recipe && product.limiting_ingredient_by_branch) {
            return product.limiting_ingredient_by_branch[selectedBranchId()] || null;
        }
        return null;
    }

    // Blocking message when a product can't be added / increased beyond availability.
    function unavailableMessage(product, available) {
        if (product.is_recipe) {
            var ing = limitingIngredient(product);
            if (available <= 0) {
                return product.name + ' cannot be added. ' + (ing ? ing + ' stock is insufficient.' : 'Ingredients are insufficient.');
            }
            return 'Only ' + available + ' of ' + product.name + ' can be made. ' + (ing ? ing + ' stock is insufficient.' : '');
        }
        if (available <= 0) {
            return product.name + ' is out of stock.';
        }
        return 'Insufficient stock for ' + product.name + '. Available: ' + available;
    }

    // Prominent blocking message (SweetAlert when present, else a toast — never a native alert).
    function blockAlert(message) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'warning', title: 'Not available', text: message, confirmButtonColor: '#caa23f' });
        } else {
            toast('warning', message);
        }
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
        const dealsOnly = selectedParentCategory === '__deals__';

        const filtered = dealsOnly ? [] : products.filter(function (product) {
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

        // Combos appear on the "All" view and on the dedicated "Deals" view only —
        // never inside a specific product category.
        const showCombos = dealsOnly || (!selectedParentCategory && !selectedChildCategory);
        const filteredCombos = (showCombos ? combos : []).filter(function (combo) {
            const textMatch = !query
                || String(combo.name).toLowerCase().includes(query)
                || String(combo.code || '').toLowerCase().includes(query);
            return textMatch;
        });

        filteredCombos.forEach(function (combo) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-tile';
            button.innerHTML =
                '<div class="product-avatar"><i class="ti ti-package"></i></div>' +
                '<div class="fw-bold mb-1">' + escapeHtml(combo.name) + '</div>' +
                '<div class="text-muted small mb-2">' + escapeHtml(combo.code || 'Combo') + '</div>' +
                '<div class="d-flex justify-content-between align-items-center">' +
                    '<span class="fw-bold">' + money(combo.price) + '</span>' +
                    '<span class="stock-badge">Combo</span>' +
                '</div>' +
                '<div class="small text-muted mt-2">' + combo.components.length + ' items</div>';

            button.addEventListener('click', function () { addComboToCart(combo); });
            productGrid.appendChild(button);
        });

        filtered.forEach(function (product) {
            const variant    = product.variants && product.variants.length ? product.variants[0] : null;
            const qty        = availableQty(product, variant);
            const price      = productPrice(product, variant);
            const isRecipe   = !product.is_stock_tracked && product.is_recipe;
            const stockClass = qty === null ? '' : qty <= 0 ? 'stock-out' : qty <= 5 ? 'stock-low' : '';
            const stockText  = qty === null
                ? 'Service'
                : (qty <= 0 ? 'Out of stock' : (isRecipe ? 'Makes ' + qty : 'Stock ' + qty));

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
                (product.is_taxable ? '<div class="small text-muted mt-2">Tax ' + product.tax_rate_percent + '%</div>' : '') +
                (hasModifierGroups(product) ? '<div class="small text-primary mt-1"><i class="ti ti-adjustments-horizontal me-1"></i>Customizable</div>' : '');

            button.addEventListener('click', function () { addToCart(product, variant); });
            productGrid.appendChild(button);
        });

        if (!filtered.length && !filteredCombos.length) {
            productGrid.innerHTML = '<div class="alert alert-info" role="status">No products found.</div>';
        }
    }

    /* cart */

    var _qtyModal = null;
    var _qtyModalProduct = null;
    var _qtyModalVariant = null;
    var _modifierModal = null;
    var _modifierModalProduct = null;
    var _modifierModalVariant = null;
    var _modifierModalQty = 1;
    var _modifierModalEditKey = null;   // when set, confirm edits this cart line instead of adding

    function openQtyModal(product, variant) {
        _qtyModalProduct = product;
        _qtyModalVariant = variant || null;

        var price = productPrice(product, variant);
        var unit  = product.unit_code || 'unit';

        document.getElementById('qty-modal-product-name').textContent = product.name;
        document.getElementById('qty-modal-unit').textContent = unit ? '(' + unit + ')' : '';
        document.getElementById('qty-modal-price-hint').textContent = money(price) + ' per ' + unit;

        var qtyInput    = document.getElementById('qty-modal-input');
        var amountInput = document.getElementById('qty-modal-amount-input');
        qtyInput.step   = product.quantity_step || 0.001;
        qtyInput.min    = product.quantity_step || 0.001;
        qtyInput.value  = '';
        amountInput.value = '';

        if (!_qtyModal) {
            _qtyModal = new bootstrap.Modal(document.getElementById('qtyEntryModal'));
        }
        _qtyModal.show();
        setTimeout(function () { qtyInput.focus(); }, 250);
    }

    document.getElementById('qty-modal-amount-input').addEventListener('input', function () {
        if (!_qtyModalProduct) return;
        var amount = parseFloat(this.value) || 0;
        var price  = productPrice(_qtyModalProduct, _qtyModalVariant);
        if (price > 0 && amount > 0) {
            document.getElementById('qty-modal-input').value = (amount / price).toFixed(3);
        }
    });

    document.getElementById('qty-modal-confirm').addEventListener('click', function () {
        var qty = parseFloat(document.getElementById('qty-modal-input').value) || 0;
        if (qty <= 0) { document.getElementById('qty-modal-input').focus(); return; }
        if (_qtyModal) _qtyModal.hide();
        addToCart(_qtyModalProduct, _qtyModalVariant, qty);
    });

    document.getElementById('qty-modal-input').addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); document.getElementById('qty-modal-confirm').click(); }
    });

    function openModifierModal(product, variant, qty, preselectedIds, editKey) {
        _modifierModalProduct = product;
        _modifierModalVariant = variant || null;
        _modifierModalQty = qty || 1;
        _modifierModalEditKey = editKey || null;
        var preselect = Array.isArray(preselectedIds) ? preselectedIds.map(Number) : null;

        var groups = activeModifierGroups(product);
        var basePrice = productPrice(product, variant);
        var body = document.getElementById('modifier-modal-groups');
        document.getElementById('modifier-modal-product-name').textContent = product.name;
        document.getElementById('modifier-modal-price-hint').textContent = money(basePrice) + ' base price';

        body.innerHTML = groups.map(function (group) {
            var maxText = group.max_select ? group.max_select : 'Any';
            var rules = (group.is_required ? 'Required' : 'Optional') + ' · ' + Number(group.min_select || 0) + ' min / ' + maxText + ' max';
            var inputType = Number(group.max_select || 0) === 1 ? 'radio' : 'checkbox';
            var options = (group.modifiers || []).map(function (modifier) {
                var inputName = inputType === 'radio'
                    ? 'modifier_group_' + group.id
                    : 'modifier_group_' + group.id + '[]';
                var checked = (preselect ? preselect.indexOf(Number(modifier.id)) !== -1 : modifier.is_default) ? ' checked' : '';
                var price = Number(modifier.price_delta || 0);
                var priceText = price === 0 ? '' : ' <span class="text-muted">(' + (price > 0 ? '+' : '') + money(price) + ')</span>';

                return '<label class="list-group-item d-flex align-items-center justify-content-between gap-3">' +
                    '<span><input class="form-check-input me-2" type="' + inputType + '" name="' + inputName + '" value="' + modifier.id + '"' +
                        ' data-modifier-input data-group-id="' + group.id + '"' +
                        ' data-group-name="' + escapeHtml(group.name) + '"' +
                        ' data-modifier-name="' + escapeHtml(modifier.name) + '"' +
                        ' data-price-delta="' + price + '"' + checked + '> ' +
                        escapeHtml(modifier.name) + priceText + '</span>' +
                    '</label>';
            }).join('');

            return '<div class="mb-3" data-modifier-group="' + group.id + '" data-min="' + Number(group.min_select || 0) + '" data-max="' + (group.max_select || '') + '">' +
                '<div class="d-flex align-items-center justify-content-between mb-1">' +
                    '<strong>' + escapeHtml(group.name) + '</strong>' +
                    '<span class="small text-muted">' + rules + '</span>' +
                '</div>' +
                '<div class="list-group">' + options + '</div>' +
                '<div class="small text-danger mt-1 d-none" data-modifier-error></div>' +
            '</div>';
        }).join('');

        if (!_modifierModal) {
            _modifierModal = new bootstrap.Modal(document.getElementById('modifierEntryModal'));
        }
        _modifierModal.show();
    }

    function selectedModifiersFromModal() {
        var selected = [];
        document.querySelectorAll('#modifier-modal-groups [data-modifier-input]:checked').forEach(function (input) {
            selected.push({
                modifier_group_id: Number(input.dataset.groupId || 0),
                modifier_group_name: input.dataset.groupName || '',
                modifier_id: Number(input.value || 0),
                name: input.dataset.modifierName || '',
                price_delta: Number(input.dataset.priceDelta || 0),
            });
        });
        return selected;
    }

    function validateModifierModal() {
        var ok = true;
        document.querySelectorAll('#modifier-modal-groups [data-modifier-group]').forEach(function (groupEl) {
            var min = Number(groupEl.dataset.min || 0);
            var max = groupEl.dataset.max === '' ? null : Number(groupEl.dataset.max || 0);
            var count = groupEl.querySelectorAll('[data-modifier-input]:checked').length;
            var error = groupEl.querySelector('[data-modifier-error]');
            error.classList.add('d-none');
            error.textContent = '';

            if (count < min) {
                ok = false;
                error.textContent = 'Select at least ' + min + ' option' + (min === 1 ? '' : 's') + '.';
                error.classList.remove('d-none');
            } else if (max !== null && count > max) {
                ok = false;
                error.textContent = 'Select no more than ' + max + ' option' + (max === 1 ? '' : 's') + '.';
                error.classList.remove('d-none');
            }
        });
        return ok;
    }

    document.getElementById('modifier-modal-confirm').addEventListener('click', function () {
        if (!validateModifierModal()) return;
        var modifiers = selectedModifiersFromModal();
        if (_modifierModal) _modifierModal.hide();
        if (_modifierModalEditKey) {
            // Editing an existing line: drop it, then re-add with the new modifiers.
            // addToCart re-keys by modifier signature and re-folds the price delta.
            var idx = cart.findIndex(function (row) { return row.key === _modifierModalEditKey; });
            if (idx !== -1) cart.splice(idx, 1);
            _modifierModalEditKey = null;
        }
        addToCart(_modifierModalProduct, _modifierModalVariant, _modifierModalQty, modifiers);
    });

    function updateComboComponents(parentKey) {
        var header = cart.find(function (item) { return item.key === parentKey; });
        if (!header) return;

        cart.forEach(function (item) {
            if (item.parent_key === parentKey && item.line_kind === 'component') {
                item.quantity = Number(item.combo_component_qty || 0) * Number(header.quantity || 0);
            }
        });
    }

    function addComboToCart(combo) {
        var key = 'combo:' + combo.id;
        var existing = cart.find(function (item) { return item.key === key; });

        if (existing) {
            existing.quantity = Number(existing.quantity || 0) + 1;
            updateComboComponents(key);
            renderCart();
            return;
        }

        var headerProduct = products.find(function (product) {
            return Number(product.id) === Number(combo.header_product_id);
        });

        if (!headerProduct) {
            blockAlert('Combo cannot be added because its header product is unavailable.');
            return;
        }

        cart.push({
            key: key,
            client_line_key: key,
            line_kind: 'combo_header',
            combo_id: combo.id,
            product_id: headerProduct.id,
            product_variant_id: null,
            name: combo.name,
            variant_name: null,
            unit_code: '',
            quantity: 1,
            unit_price: Number(combo.price || 0),
            base_unit_price: Number(combo.price || 0),
            modifiers: [],
            discount_amount: 0,
            tax_amount: 0,
            product: Object.assign({}, headerProduct, { is_taxable: false, tax_rate_percent: 0 }),
            variant: null,
            combo_components: combo.components || [],
        });

        (combo.components || []).forEach(function (component) {
            var product = products.find(function (item) {
                return Number(item.id) === Number(component.product_id);
            });
            if (!product) return;

            var variant = (product.variants || []).find(function (item) {
                return Number(item.id) === Number(component.product_variant_id);
            }) || null;

            cart.push({
                key: key + ':component:' + component.id,
                client_line_key: key + ':component:' + component.id,
                parent_key: key,
                parent_client_line_key: key,
                line_kind: 'component',
                combo_id: combo.id,
                combo_component_qty: Number(component.quantity || 1),
                product_id: product.id,
                product_variant_id: variant ? variant.id : null,
                name: product.name,
                variant_name: variant ? variant.name : null,
                unit_code: component.unit_code || product.unit_code || '',
                quantity: Number(component.quantity || 1),
                unit_price: 0,
                base_unit_price: 0,
                modifiers: [],
                discount_amount: 0,
                tax_amount: 0,
                product: product,
                variant: variant,
            });
        });

        renderCart();
    }

    function addToCart(product, variant, forceQty, selectedModifiers) {
        var modifiers = normalizeModifiers(selectedModifiers || []);
        var key       = cartKey(product, variant, modifiers);
        var existing  = cart.find(function (item) { return item.key === key; });
        var stockQty  = availableQty(product, variant);
        var measurable = isMeasurableProduct(product);

        if (stockQty !== null && stockQty <= 0) { blockAlert(unavailableMessage(product, 0)); return; }

        if (forceQty === undefined && measurable) {
            openQtyModal(product, variant);
            return;
        }

        var addQty = forceQty !== undefined ? parseFloat(forceQty) : 1;
        if (!measurable) { addQty = Math.max(Math.round(addQty || 1), 1); }
        if (!addQty || addQty <= 0) return;

        if (!selectedModifiers && hasModifierGroups(product)) {
            openModifierModal(product, variant, addQty);
            return;
        }

        if (existing) {
            var newQty = measurable
                ? parseFloat((Number(existing.quantity || 0) + addQty).toFixed(3))
                : Number(existing.quantity || 0) + addQty;
            if (stockQty !== null && newQty > stockQty + 0.0001) {
                blockAlert(unavailableMessage(product, stockQty));
                return;
            }
            existing.quantity = newQty;
        } else {
            if (stockQty !== null && addQty > stockQty + 0.0001) {
                blockAlert(unavailableMessage(product, stockQty));
                return;
            }
            var price = productPrice(product, variant) + modifierPriceDelta(modifiers);
            cart.push({
                key:                key,
                product_id:         product.id,
                product_variant_id: variant ? variant.id : null,
                name:               product.name,
                variant_name:       variant ? variant.name : null,
                unit_code:          product.unit_code || '',
                quantity:           measurable ? parseFloat(addQty.toFixed(3)) : addQty,
                unit_price:         price,
                base_unit_price:    productPrice(product, variant),
                modifiers:          modifiers,
                discount_amount:    0,
                tax_amount:         lineTax(product, addQty, price, 0),
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
            if (item.line_kind === 'component') return;

            item.tax_amount = lineTax(item.product, item.quantity, item.unit_price, item.discount_amount);

            const row     = document.createElement('div');
            row.className = 'cart-row';
            var modifierHtml = normalizeModifiers(item.modifiers).map(function (modifier) {
                var delta = Number(modifier.price_delta || 0);
                var deltaText = delta === 0 ? '' : ' <span>(' + (delta > 0 ? '+' : '') + money(delta) + ')</span>';
                return '<div class="small text-muted ps-2">+ ' + escapeHtml(modifier.name) + deltaText + '</div>';
            }).join('');
            var componentHtml = '';
            if (item.line_kind === 'combo_header') {
                componentHtml = cart.filter(function (child) {
                    return child.parent_key === item.key && child.line_kind === 'component';
                }).map(function (child) {
                    return '<div class="small text-muted ps-2">- ' + formatQty(child.quantity, child.product) + ' x ' + escapeHtml(child.name) + '</div>';
                }).join('');
            }
            var canEditModifiers = item.line_kind !== 'combo_header' && item.line_kind !== 'component'
                && !item.kot_sent && item.product && hasModifierGroups(item.product);
            var editBtnHtml = canEditModifiers
                ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-edit-mod="' + index + '" title="Edit options"><i class="ti ti-adjustments-horizontal"></i></button>'
                : '';
            row.innerHTML =
                '<div class="d-flex justify-content-between gap-2 mb-2">' +
                    '<div>' +
                        '<div class="fw-bold">' + escapeHtml(item.name) + '</div>' +
                        '<div class="small text-muted">' + escapeHtml(item.variant_name || 'Default') + ' &middot; ' + money(item.unit_price) + (lineUnitLabel(item) ? ' / ' + escapeHtml(lineUnitLabel(item)) : '') + '</div>' +
                        modifierHtml +
                        componentHtml +
                    '</div>' +
                    '<div class="d-flex gap-1">' + editBtnHtml +
                        '<button type="button" class="btn btn-sm btn-outline-danger" data-remove="' + index + '">&times;</button>' +
                    '</div>' +
                '</div>' +
                '<div class="d-flex align-items-center justify-content-between gap-2">' +
                    '<div class="d-flex align-items-center gap-2">' +
                        '<button type="button" class="qty-btn" data-minus="' + index + '">-</button>' +
                        '<input type="number" class="form-control form-control-sm text-end" style="width:80px" ' +
                            'data-qty-input="' + index + '" ' +
                            'step="' + qtyStep(item.product) + '" ' +
                            'min="' + qtyStep(item.product) + '" ' +
                            'value="' + formatQty(item.quantity, item.product) + '">' +
                        (lineUnitLabel(item) ? '<span class="small text-muted">' + lineUnitLabel(item) + '</span>' : '') +
                        '<button type="button" class="qty-btn" data-plus="' + index + '">+</button>' +
                    '</div>' +
                    '<strong>' + money((item.quantity * item.unit_price) - item.discount_amount + item.tax_amount) + '</strong>' +
                '</div>';

            cartItemsEl.appendChild(row);
        });

        requestAnimationFrame(function () {
            cartItemsEl.scrollTo({ top: cartItemsEl.scrollHeight, behavior: 'smooth' });
        });

        cartItemsEl.querySelectorAll('[data-qty-input]').forEach(function (input) {
            input.addEventListener('change', function () {
                var i    = Number(input.dataset.qtyInput);
                var item = cart[i];
                if (!item) return;
                var newQty = parseFloat(input.value) || 0;
                if (!isMeasurableProduct(item.product)) {
                    newQty = Math.round(newQty);
                } else {
                    newQty = parseFloat(newQty.toFixed(3));
                }
                if (newQty <= 0.0001) { cart.splice(i, 1); renderCart(); return; }
                var stockQty = availableQty(item.product, item.variant);
                if (stockQty !== null && newQty > stockQty + 0.0001) {
                    blockAlert(unavailableMessage(item.product, stockQty));
                    input.value = formatQty(item.quantity, item.product);
                    return;
                }
                item.quantity = newQty;
                if (item.line_kind === 'combo_header') updateComboComponents(item.key);
                renderCart();
            });
        });

        cartItemsEl.querySelectorAll('[data-plus]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var i    = Number(btn.dataset.plus);
                var item = cart[i];
                var step = qtyStep(item.product);
                item.quantity = isMeasurableProduct(item.product)
                    ? parseFloat((Number(item.quantity || 0) + step).toFixed(3))
                    : Number(item.quantity || 0) + 1;
                if (item.line_kind === 'combo_header') updateComboComponents(item.key);
                renderCart();
            });
        });
        cartItemsEl.querySelectorAll('[data-minus]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var i    = Number(btn.dataset.minus);
                var item = cart[i];
                var step = qtyStep(item.product);
                item.quantity = isMeasurableProduct(item.product)
                    ? parseFloat((Number(item.quantity || 0) - step).toFixed(3))
                    : Number(item.quantity || 0) - 1;
                if (item.quantity <= 0.0001) {
                    if (item.line_kind === 'combo_header') {
                        cart = cart.filter(function (row) { return row.key !== item.key && row.parent_key !== item.key; });
                    } else {
                        cart.splice(i, 1);
                    }
                } else if (item.line_kind === 'combo_header') {
                    updateComboComponents(item.key);
                }
                renderCart();
            });
        });
        cartItemsEl.querySelectorAll('[data-edit-mod]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var item = cart[Number(btn.dataset.editMod)];
                if (!item || !item.product) return;
                var preselected = normalizeModifiers(item.modifiers).map(function (m) { return Number(m.modifier_id); });
                openModifierModal(item.product, item.variant, item.quantity, preselected, item.key);
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
                        if (item.line_kind === 'combo_header') {
                            cart = cart.filter(function (row) { return row.key !== item.key && row.parent_key !== item.key; });
                        } else {
                            cart.splice(idx, 1);
                        }
                        renderCart();
                    });
                } else {
                    if (item.line_kind === 'combo_header') {
                        cart = cart.filter(function (row) { return row.key !== item.key && row.parent_key !== item.key; });
                    } else {
                        cart.splice(idx, 1);
                    }
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

    function updateTotals(quoteServer) {
        if (quoteServer === undefined) quoteServer = true;
        const t = totals();
        document.getElementById('subtotal-view').textContent    = money(t.subtotal);
        document.getElementById('discount-view').textContent    = money(t.discount);
        document.getElementById('tax-view').textContent         = money(t.tax);
        document.getElementById('grand-total-view').textContent = money(t.total);
        var chargeTotalEl = document.getElementById('pos-charge-total');
        if (chargeTotalEl) { chargeTotalEl.textContent = money(t.total); }

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

        if (quoteServer) {
            scheduleServerTotalsQuote();
        }
    }

    /* server totals quote — fetches service charge from backend */

    var _totalsQuoteTimer = null;

    function collectQuoteLines() {
        return cart.map(function (item) {
            return {
                product_id:       item.product_id || 0,
                category_id:      item.product?.category_id || 0,
                quantity:        item.quantity || 0,
                unit_price:      item.unit_price || 0,
                discount_amount: item.discount_amount || 0,
                tax_amount:      item.tax_amount || 0,
            };
        });
    }

    function refreshServerTotals() {
        if (!cart.length || !branchEl || !orderTypeEl) {
            _serviceChargeAmount = 0;
            return Promise.resolve();
        }

        return fetch('{{ url('/api/pos/totals/quote') }}', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept':       'application/json',
            },
            body: JSON.stringify({
                branch_id:      branchEl.value,
                order_type:     orderTypeEl.value,
                discount_type:  document.querySelector('input[name="discount_type"]')?.value || 'none',
                discount_value: document.querySelector('input[name="discount_value"]')?.value || 0,
                promo_code:     _promoCode || '',
                tip_amount:     _tipAmount || 0,
                lines:          collectQuoteLines(),
            }),
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.ok) return;

            _serviceChargeAmount = Number(data.service_charge_amount || 0);

            if (_promoCode && !data.promo_code) {
                _promoDiscountAmount = 0;
                _promoCode           = '';
                _promoName           = '';
                var promoInput    = document.getElementById('promo-code-input');
                var promoFeedback = document.getElementById('promo-feedback');
                if (promoInput)    promoInput.value = '';
                if (promoFeedback) promoFeedback.innerHTML = '<span class="text-warning">Promo no longer applies.</span>';
                document.getElementById('remove-promo-btn')?.classList.add('d-none');
                document.getElementById('apply-promo-btn')?.classList.remove('d-none');
            } else if (_promoCode) {
                _promoDiscountAmount = Number(data.promotion_discount_amount || 0);
            }

            updateTotals(false);
        })
        .catch(function () { /* keep POS usable if quote fails */ });
    }

    function scheduleServerTotalsQuote() {
        clearTimeout(_totalsQuoteTimer);
        _totalsQuoteTimer = setTimeout(refreshServerTotals, 250);
    }

    /* form build + submit */

    function buildInputs(includePayment) {
        dynamicInputs.innerHTML = '';

        cart.forEach(function (item, index) {
            var fields = {
                product_id:         item.product_id,
                product_variant_id: item.product_variant_id || '',
                client_line_key:    item.client_line_key || item.key || '',
                parent_client_line_key: item.parent_client_line_key || '',
                line_kind:          item.line_kind || 'standard',
                combo_id:           item.combo_id || '',
                line_name:          item.name || '',
                quantity:           item.quantity,
                unit_price:         item.unit_price,
                discount_amount:    item.discount_amount || 0,
                tax_amount:         item.tax_amount || 0,
                modifiers:          JSON.stringify(normalizeModifiers(item.modifiers)),
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
        const branchId = document.getElementById('branch_id')?.value || '';
        const orderType = document.getElementById('order_type')?.value || 'quick_sale';
        fetch('{{ url('/api/pos/promotions/quote') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify({
                promo_code: code,
                branch_id: branchId,
                order_type: orderType,
                subtotal: t.subtotal,
                lines: collectQuoteLines(),
            }),
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

    // Session-only print overrides (this device): '1' force on, '0' force off, null = follow terminal.
    const PRINT_OVERRIDE_KEY = { kot: 'pos_auto_kot', receipt: 'pos_auto_receipt' };

    function currentTerminalId() {
        return (document.getElementById('terminal_id') || {}).value || '';
    }

    function terminalAuto(kind, terminalId) {
        if (!terminalId) return false;            // No terminal → ask / manual fallback
        const cfg = terminalPrintConfig[terminalId];
        if (!cfg) return false;
        return kind === 'kot' ? !!cfg.auto_print_kot : !!cfg.auto_print_receipt;
    }

    // Effective auto-print: a session override wins, else the terminal's saved setting.
    function autoPrintEnabled(kind) {
        const ov = localStorage.getItem(PRINT_OVERRIDE_KEY[kind]);
        if (ov === '1') return true;
        if (ov === '0') return false;
        return terminalAuto(kind, currentTerminalId());
    }

    // Back-compat alias used by handleKotAfterSale (now honours session override too).
    function terminalAutoKot() { return autoPrintEnabled('kot'); }

    // Units already sent to the kitchen vs not-yet-sent, from the current cart (delta guard).
    function kotPending() {
        let sent = 0, pending = 0;
        cart.forEach(function (it) {
            const q = Number(it.quantity) || 0;
            const s = Number(it.kot_sent_quantity || 0);
            sent    += Math.min(s, q);
            pending += Math.max(q - s, 0);
        });
        return { sent: sent, pending: pending };
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

    // Print the receipt on complete, honouring the Auto-Receipt toggle + no-printer fallback.
    function maybePrintReceipt(saleId, terminalId) {
        if (!autoPrintEnabled('receipt')) { return; }   // "No receipt" (toggle off)
        const query = terminalId ? '?terminal_id=' + encodeURIComponent(terminalId) : '';
        fetch('{{ url('/printing/jobs/receipt') }}/' + saleId + query, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && (data.fallback || data.printer_type === 'browser') && data.preview_url) {
                toast('warning', 'No printer found — opening receipt for manual print');
                window.open(data.preview_url, '_blank');
            }
        })
        .catch(function () {});
    }

    // Live "Printing" panel in the payment modal: status chips + session toggles.
    function refreshPrintPanel() {
        const tid     = currentTerminalId();
        const cfg     = tid ? terminalPrintConfig[tid] : null;
        const labelEl = document.getElementById('print-terminal-label');
        const kotTog  = document.getElementById('auto-kot-toggle');
        const rcpTog  = document.getElementById('auto-receipt-toggle');
        const kotHint = document.getElementById('kot-status-hint');
        const rcpHint = document.getElementById('receipt-status-hint');
        if (!kotTog || !rcpTog) { return; }

        if (labelEl) {
            const tName = tid && document.getElementById('terminal_id')
                ? document.getElementById('terminal_id').selectedOptions[0].textContent.trim()
                : 'No terminal';
            labelEl.textContent = tName;
        }

        const kotAuto = autoPrintEnabled('kot');
        const rcpAuto = autoPrintEnabled('receipt');
        kotTog.checked = kotAuto;
        rcpTog.checked = rcpAuto;

        const pend = kotPending().pending;
        if (kotHint) {
            if (pend <= 0) {
                kotHint.textContent = 'Kitchen: all items already sent ✓';
            } else if (!tid) {
                kotHint.textContent = pend + ' new item(s) — no terminal → opens KOT for manual print';
            } else if (kotAuto) {
                kotHint.textContent = pend + ' new item(s) → auto-send to kitchen';
            } else {
                kotHint.textContent = pend + ' new item(s) → will ask before sending';
            }
        }
        if (rcpHint) {
            if (!rcpAuto) {
                rcpHint.textContent = 'Off — no receipt will print';
            } else if (!tid) {
                rcpHint.textContent = 'No terminal → opens receipt for manual print';
            } else {
                rcpHint.textContent = 'Prints to receipt printer on complete';
            }
        }
    }

    /* ── Complete sale ────────────────────────────────────────────────── */

    function submitPaidSale() {
        if (!cart.length) { toast('warning', 'Add at least one item'); return; }

        const submitBtn  = document.getElementById('complete-sale-btn');
        const origLabel  = submitBtn.textContent;
        submitBtn.disabled    = true;
        submitBtn.textContent = 'Processing…';

        refreshServerTotals().finally(function () {
            buildInputs(true);

            const terminalId = (document.getElementById('terminal_id') || {}).value || '';
            const printQuery = terminalId ? '?terminal_id=' + encodeURIComponent(terminalId) : '';

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
                    var failMsg = result.data.message || 'Sale failed. Please try again.';
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Cannot complete sale', text: failMsg, confirmButtonColor: '#dc3545' });
                    } else {
                        toast('error', failMsg);
                    }
                    return;
                }

                const saleId = result.data.sale_id;
                const saleNo = result.data.sale_no;

                _lastSaleId = saleId;
                _lastSaleNo = saleNo;

                // Receipt — honours the Auto-Receipt toggle + opens preview if no printer
                maybePrintReceipt(saleId, terminalId);

                // KOT — only the un-sent delta; nothing new → no print, no prompt, no double
                if (kotPending().pending > 0) {
                    handleKotAfterSale(saleId, saleNo, terminalId);
                }

                clearCart();
                toast('success', 'Sale complete! ' + saleNo);
                var pmEl = document.getElementById('paymentModal');
                if (pmEl && window.bootstrap) { var pmInst = bootstrap.Modal.getInstance(pmEl); if (pmInst) { pmInst.hide(); } }
            })
            .catch(function () {
                submitBtn.disabled    = false;
                submitBtn.textContent = origLabel;
                toast('error', 'Network error. Please try again.');
            });
        });
    }

    /* ── Hold sale ────────────────────────────────────────────────────── */

    function submitHeldSale() {
        if (!cart.length) { toast('warning', 'Add at least one item'); return; }

        const holdBtn    = document.getElementById('hold-sale-btn');
        const origLabel  = holdBtn.textContent;
        holdBtn.disabled    = true;
        holdBtn.textContent = 'Saving…';

        refreshServerTotals().finally(function () {
            // Sync any current held sale ID into the form
            const heldInput = document.querySelector('input[name="held_sale_id"]');
            if (heldInput && _currentHeldSaleId) heldInput.value = _currentHeldSaleId;

            buildInputs(false);

            const terminalId = (document.getElementById('terminal_id') || {}).value || '';

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
                    if (result.data && result.data.code === 'TABLE_HAS_OPEN_ORDERS') {
                        showOpenOrdersChoice(result.data.orders || [], result.data.session || {
                            id: result.data.table_session_id, branch_id: result.data.branch_id,
                        });
                        return;
                    }
                    toast('error', result.data.message || 'Failed to hold sale.');
                    return;
                }

                const saleId = result.data.sale_id;
                const saleNo = result.data.sale_no;

                _currentHeldSaleId = saleId;
                _currentHeldSaleNo = saleNo;
                _lastSaleId        = saleId;
                _lastSaleNo        = saleNo;
                if (heldInput) heldInput.value = saleId;

                if (result.data.restaurant_table_session_id) {
                    const tblSessInput = document.getElementById('restaurant_table_session_id');
                    if (tblSessInput) tblSessInput.value = result.data.restaurant_table_session_id;
                }

                updateSplitBillBtn();
                updateRecalledBar();
                lockOrderControls();
                toast('success', 'Held: ' + saleNo);
                // KOT — only the un-sent delta (re-holding without new items won't reprint)
                if (kotPending().pending > 0) {
                    handleKotAfterSale(saleId, saleNo, terminalId);
                }
            })
            .catch(function () {
                holdBtn.disabled    = false;
                holdBtn.textContent = origLabel;
                toast('error', 'Network error. Please try again.');
            });
        });
    }

    /* ── Open orders choice modal ────────────────────────────────────── */

    function showOpenOrdersChoice(orders, session) {
        if (!orders || !orders.length) {
            clearCart();
            applyTableSession(session);
            refreshTableBoard(session.id);
            return;
        }

        var html = '<p class="text-muted mb-3">This table has open held orders. Continue an existing order or create a separate one.</p>';
        html += '<div class="list-group text-start mb-2">';
        orders.forEach(function (order, idx) {
            html += '<button type="button" class="list-group-item list-group-item-action open-order-choice" data-order-index="' + idx + '">' +
                '<strong>' + order.sale_no + '</strong>' +
                '<span class="float-end">Rs ' + order.grand_total_formatted + '</span>' +
                '<br><small class="text-muted">' + order.items_count + ' items &middot; ' + (order.updated_at || '') + '</small>' +
                '</button>';
        });
        html += '</div>';

        Swal.fire({
            title:             'Open Orders Found',
            html:              html,
            icon:              'info',
            showCancelButton:  true,
            showDenyButton:    true,
            confirmButtonText: 'Continue Latest Order',
            denyButtonText:    'Create Separate Order',
            cancelButtonText:  'Cancel',
            didOpen: function (popup) {
                popup.querySelectorAll('.open-order-choice').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        Swal.close();
                        continueExistingOrder(orders[Number(btn.dataset.orderIndex)], session);
                    });
                });
            },
        }).then(function (result) {
            if (result.isConfirmed && orders[0]) {
                continueExistingOrder(orders[0], session);
            } else if (result.isDenied) {
                // Start a fresh, separate order on this same table — no reload.
                clearCart();
                applyTableSession(session);
                setHidden('create_separate_order', '1');
                refreshTableBoard(session.id);
                if (window.history && window.history.replaceState) {
                    window.history.replaceState({}, '', buildPosUrl({
                        table_session_id:      session.id,
                        mode:                  'dine_in',
                        branch_id:             session.branch_id || (branchEl ? branchEl.value : ''),
                        create_separate_order: 1,
                    }));
                }
            }
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
        var _hsModal = bootstrap.Modal.getInstance(heldSalesModalEl);
        if (_hsModal) _hsModal.hide();

        // Rebuild cart from recalled sale
        cart = [];
        var recalledLineKeys = {};
        sale.lines.forEach(function (line) {
            const product = products.find(function (p) { return Number(p.id) === Number(line.product_id); });
            if (!product) return;
            const variant = (product.variants || []).find(function (v) {
                return Number(v.id) === Number(line.product_variant_id);
            });
            const modifiers = normalizeModifiers(line.modifiers || []);
            const lineKind = line.line_kind || 'standard';
            const key = lineKind === 'combo_header'
                ? 'held-line:' + line.id
                : (lineKind === 'component' ? 'held-line:' + line.id : cartKey(product, variant, modifiers));
            const parentKey = line.parent_sales_order_line_id ? recalledLineKeys[line.parent_sales_order_line_id] : '';
            cart.push({
                key:                key,
                client_line_key:    key,
                parent_key:         parentKey,
                parent_client_line_key: parentKey,
                line_kind:          lineKind,
                combo_id:           line.combo_id || '',
                product_id:         product.id,
                product_variant_id: variant ? variant.id : null,
                name:               line.product_name || product.name,
                variant_name:       line.variant_name || (variant ? variant.name : null),
                unit_code:          line.unit_code || product.unit_code || '',
                quantity:           Number(line.quantity || 1),
                unit_price:         Number(line.unit_price || productPrice(product, variant)),
                base_unit_price:    Number(line.base_unit_price || 0) || Math.max(Number(line.unit_price || productPrice(product, variant)) - modifierPriceDelta(modifiers), 0),
                modifiers:          modifiers,
                discount_amount:    Number(line.discount_amount || 0),
                tax_amount:         Number(line.tax_amount || 0),
                product:            product,
                variant:            variant || null,
            });
            recalledLineKeys[line.id] = key;
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

        // Apply table + session; clear both for non-dine-in modes
        const tableSessionInput = document.getElementById('restaurant_table_session_id');
        if (tableSessionInput) tableSessionInput.value = isDineIn ? newSessionId : '';
        const tableIdInput = document.getElementById('restaurant_table_id');
        if (tableIdInput) tableIdInput.value = isDineIn ? newTableId : '';
        const separateInput = document.getElementById('create_separate_order');
        if (separateInput) separateInput.value = '0';

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

    /* ── Completed Orders (recent paid sales) — reprint receipt/KOT / view ── */
    const completedOrdersModalEl = document.getElementById('completedOrdersModal');
    const completedOrdersBtn     = document.getElementById('completed-orders-btn');
    if (completedOrdersModalEl) {
        completedOrdersModalEl.addEventListener('show.bs.modal', loadRecentSales);
    }
    if (completedOrdersBtn && completedOrdersModalEl && window.bootstrap) {
        completedOrdersBtn.addEventListener('click', function () {
            bootstrap.Modal.getOrCreateInstance(completedOrdersModalEl).show();
        });
    }

    function loadRecentSales() {
        const body = document.getElementById('completed-orders-modal-body');
        const branchId = (branchEl ? branchEl.value : '') || '';
        body.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-secondary" role="status"></div></div>';
        fetch('{{ url('/api/pos/recent-sales') }}?branch_id=' + encodeURIComponent(branchId), { headers: { 'Accept': 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            const sales = data.sales || [];
            if (!sales.length) {
                body.innerHTML = '<div class="alert alert-light border m-3 mb-0 text-center">No completed orders yet for this branch.</div>';
                return;
            }
            const rows = sales.map(function (s) {
                return '<tr>' +
                    '<td><strong>' + s.sale_no + '</strong><div class="text-muted small">' + (s.time || s.ago || '') + '</div></td>' +
                    '<td>' + (s.customer || 'Walk-in') + '<div class="text-muted small text-capitalize">' + String(s.order_type || '').replace(/_/g, ' ') + '</div></td>' +
                    '<td class="text-end fw-semibold">' + s.total + '</td>' +
                    '<td class="text-end text-nowrap">' +
                        '<button type="button" class="btn btn-sm btn-outline-primary me-1" data-reprint-receipt="' + s.id + '"><i class="ti ti-printer me-1"></i>Receipt</button>' +
                        '<button type="button" class="btn btn-sm btn-outline-warning me-1" data-reprint-kot="' + s.id + '"><i class="ti ti-tool-kitchen-2 me-1"></i>KOT</button>' +
                        '<a href="{{ url('/sales-orders') }}/' + s.id + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="View"><i class="ti ti-eye"></i></a>' +
                    '</td>' +
                '</tr>';
            }).join('');
            body.innerHTML = '<div class="table-responsive"><table class="table table-hover mb-0 align-middle">' +
                '<thead class="thead-light"><tr><th>Order</th><th>Customer</th><th class="text-end">Total</th><th class="text-end">Reprint</th></tr></thead>' +
                '<tbody>' + rows + '</tbody></table></div>';
            body.querySelectorAll('[data-reprint-receipt]').forEach(function (b) {
                b.addEventListener('click', function () { reprintSale(Number(b.dataset.reprintReceipt), 'receipt', b); });
            });
            body.querySelectorAll('[data-reprint-kot]').forEach(function (b) {
                b.addEventListener('click', function () { reprintSale(Number(b.dataset.reprintKot), 'kot', b); });
            });
        })
        .catch(function () { body.innerHTML = '<div class="alert alert-danger m-3 mb-0">Failed to load completed orders.</div>'; });
    }

    // Reprint receipt/KOT for a chosen completed sale (reuses print endpoints + fallback).
    function reprintSale(saleId, type, btn) {
        const terminalId = (document.getElementById('terminal_id') || {}).value || '';
        const orig = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        let url, q;
        if (type === 'kot') {
            url = '{{ url('/printing/jobs/kot') }}/' + saleId;
            q   = '?reprint=1' + (terminalId ? '&terminal_id=' + encodeURIComponent(terminalId) : '');
        } else {
            url = '{{ url('/printing/jobs/receipt') }}/' + saleId;
            q   = terminalId ? '?terminal_id=' + encodeURIComponent(terminalId) : '';
        }
        fetch(url + q, { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            btn.disabled = false; btn.innerHTML = orig;
            openFallbackPreviews(data);
            toast('success', (type === 'kot' ? 'KOT' : 'Receipt') + ' re-queued');
        })
        .catch(function () { btn.disabled = false; btn.innerHTML = orig; toast('error', 'Reprint failed'); });
    }

    /* ── Bill / Preview — client-side proforma of the current cart (no save) ── */
    function billPreview() {
        if (!cart.length) { toast('warning', 'Cart is empty'); return; }
        const t = totals();
        const branchName = (branchEl && branchEl.selectedOptions[0]) ? branchEl.selectedOptions[0].textContent.trim() : '';
        const rowsHtml = cart.map(function (it) {
            const qty = Number(it.quantity) || 0;
            const price = Number(it.unit_price) || 0;
            const lt = (it.line_total != null) ? Number(it.line_total) : qty * price;
            return '<tr><td>' + (it.name || it.product_name || 'Item') + '</td><td style="text-align:right">' + qty + '</td><td style="text-align:right">' + money(price) + '</td><td style="text-align:right">' + money(lt) + '</td></tr>';
        }).join('');
        const html = '<html><head><title>Bill Preview</title><style>'
            + 'body{font-family:monospace;max-width:320px;margin:0 auto;padding:8px;font-size:13px}'
            + 'h3{text-align:center;margin:4px 0}table{width:100%;border-collapse:collapse}td,th{padding:2px 0}'
            + '.tot{border-top:1px dashed #000;margin-top:6px;padding-top:6px}.muted{text-align:center;color:#666;font-size:11px}</style></head><body>'
            + '<h3>' + branchName + '</h3>'
            + '<div class="muted">BILL PREVIEW — NOT A TAX RECEIPT</div>'
            + '<div class="muted">' + new Date().toLocaleString() + '</div><hr>'
            + '<table><thead><tr><th style="text-align:left">Item</th><th style="text-align:right">Qty</th><th style="text-align:right">Price</th><th style="text-align:right">Amt</th></tr></thead><tbody>'
            + rowsHtml + '</tbody></table>'
            + '<div class="tot"><table>'
            + '<tr><td>Subtotal</td><td style="text-align:right">' + money(t.subtotal) + '</td></tr>'
            + ((t.discount > 0) ? '<tr><td>Discount</td><td style="text-align:right">' + money(t.discount) + '</td></tr>' : '')
            + ((t.tax > 0) ? '<tr><td>Tax</td><td style="text-align:right">' + money(t.tax) + '</td></tr>' : '')
            + '<tr><td><strong>Total</strong></td><td style="text-align:right"><strong>' + money(t.total) + '</strong></td></tr>'
            + '</table></div></body></html>';
        const w = window.open('', '_blank');
        if (!w) { toast('warning', 'Allow pop-ups to preview the bill'); return; }
        w.document.write(html); w.document.close(); w.focus();
        setTimeout(function () { try { w.print(); } catch (e) {} }, 300);
    }
    const billPreviewBtn = document.getElementById('bill-preview-btn');
    if (billPreviewBtn) { billPreviewBtn.addEventListener('click', billPreview); }

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

    /* ── Table board interactions (delegated on #dine-in-board so they keep
          working after refreshTableBoard() swaps the tile markup) ────────── */

    var dineBoardEl = document.getElementById('dine-in-board');
    if (dineBoardEl) {
        dineBoardEl.addEventListener('click', function (event) {
            // Continue / select an active table — no page reload.
            var sel = event.target.closest('[data-table-session-select="1"]');
            if (sel && dineBoardEl.contains(sel)) {
                event.preventDefault();
                continueTableSession(sel.dataset.sessionId, sel.dataset.branchId, sel.href);
                return;
            }
            // Floor filter tabs.
            var tab = event.target.closest('[data-floor-tab]');
            if (tab && dineBoardEl.contains(tab)) {
                dineBoardEl.querySelectorAll('[data-floor-tab]').forEach(function (b) { b.classList.remove('active'); });
                tab.classList.add('active');
                var floorId = tab.dataset.floorTab;
                dineBoardEl.querySelectorAll('[data-floor-panel]').forEach(function (panel) {
                    panel.style.display = (!floorId || panel.dataset.floorPanel === floorId) ? '' : 'none';
                });
            }
        });
    }

    document.getElementById('complete-sale-btn').addEventListener('click', submitPaidSale);

    // "Review & Pay" opens the payment modal (guarded on empty cart); focus tendered when shown.
    var paymentModalEl = document.getElementById('paymentModal');
    var reviewPayBtn   = document.getElementById('review-pay-btn');
    if (reviewPayBtn && paymentModalEl && window.bootstrap) {
        reviewPayBtn.addEventListener('click', function () {
            if (!cart.length) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'info', title: 'Cart is empty', text: 'Add items before taking payment.', timer: 1600, showConfirmButton: false });
                }
                return;
            }
            bootstrap.Modal.getOrCreateInstance(paymentModalEl).show();
        });
        paymentModalEl.addEventListener('shown.bs.modal', function () {
            refreshPrintPanel();
            (tenderedEl || paymentMethodEl).focus();
            if (tenderedEl && tenderedEl.select) { tenderedEl.select(); }
        });

        // Session (this-device) auto-print overrides — temporary fallback to manual.
        var kotTog = document.getElementById('auto-kot-toggle');
        var rcpTog = document.getElementById('auto-receipt-toggle');
        if (kotTog) { kotTog.addEventListener('change', function () {
            localStorage.setItem(PRINT_OVERRIDE_KEY.kot, kotTog.checked ? '1' : '0');
            refreshPrintPanel();
        }); }
        if (rcpTog) { rcpTog.addEventListener('change', function () {
            localStorage.setItem(PRINT_OVERRIDE_KEY.receipt, rcpTog.checked ? '1' : '0');
            refreshPrintPanel();
        }); }
        var termSel = document.getElementById('terminal_id');
        if (termSel) { termSel.addEventListener('change', refreshPrintPanel); }
    }
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

    function handlePosBarcodeScan(rawQuery) {
        var query = (rawQuery || '').trim().toLowerCase();
        if (!query) return false;

        var matched = null;
        var matchedVariant = null;

        for (var _i = 0; _i < products.length; _i++) {
            var _p = products[_i];

            // 1. Product-level barcodes
            if ((_p.barcodes || []).some(function (b) { return String(b).toLowerCase() === query; })) {
                matched = _p;
                matchedVariant = _p.variants && _p.variants.length ? _p.variants[0] : null;
                break;
            }

            // 2. Variant-level barcodes (picks the exact matching variant)
            for (var _j = 0; _j < (_p.variants || []).length; _j++) {
                var _v = _p.variants[_j];
                if ((_v.barcodes || []).some(function (b) { return String(b).toLowerCase() === query; })) {
                    matched = _p;
                    matchedVariant = _v;
                    break;
                }
            }
            if (matched) break;
        }

        if (matched) {
            addToCart(matched, matchedVariant);
            searchEl.value = '';
            searchEl.focus();
            renderProducts();
            return true;
        }

        return false;
    }

    searchEl.addEventListener('input', function () {
        handlePosBarcodeScan(searchEl.value);
        renderProducts();
    });

    // Enter key: explicit trigger for scanners that send Enter as terminator
    searchEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            handlePosBarcodeScan(searchEl.value);
        }
    });

    /* mode tabs — CSS-locked when recalled; click → apply directly when unlocked */

    function applyModeTab(button) {
        var mode     = button.dataset.modeTab;
        var branchId = branchEl ? branchEl.value : '{{ $selectedBranchId }}';
        var isDineIn = mode === 'dine_in';

        // In-place switch for EVERY mode — no full page reload (cart is preserved).
        // Always clear table-session / held-sale state so stale data is never posted
        // when changing order type; dine-in users re-pick a table from the board.
        clearTableStateInputs();

        // Switching mode de-selects any active table → hide its bar + reset the pay button.
        var sessionBar = document.getElementById('pos-session-bar');
        if (sessionBar) {
            sessionBar.style.display = 'none';
            sessionBar.classList.add('d-none');
        }
        setCompleteSaleLabel(false);

        // Hidden order_type drives checkout + the totals/service-charge quote.
        if (orderTypeEl) orderTypeEl.value = mode;

        // Active tab highlight.
        document.querySelectorAll('[data-mode-tab]').forEach(function (b) { b.classList.remove('active'); });
        button.classList.add('active');

        // Table board is only relevant for dine-in.
        var board = document.getElementById('dine-in-board');
        if (board) {
            board.classList.toggle('is-collapsed', !isDineIn);
            board.style.display = isDineIn ? '' : 'none';
        }

        // Recompute service charge / totals for the new order type.
        if (typeof refreshServerTotals === 'function') { refreshServerTotals(); }

        // Keep ?mode= in the URL without reloading (shareable / refresh-safe).
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, '', buildPosUrl({ branch_id: branchId, mode: mode }));
        }
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

    /* floor tabs handled by delegation on #dine-in-board (see above) */

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
        selectWaiterChoice('');
    });

    function selectWaiterChoice(waiterId) {
        var value = String(waiterId || '');
        var waiterSelect = document.getElementById('restaurant_waiter_id');
        if (waiterSelect) waiterSelect.value = value;
        document.querySelectorAll('[data-waiter-choice]').forEach(function (choice) {
            var selected = String(choice.dataset.waiterChoice) === value;
            choice.classList.toggle('is-selected', selected);
            choice.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
    }

    document.querySelectorAll('[data-waiter-choice]').forEach(function (choice) {
        choice.addEventListener('click', function () {
            selectWaiterChoice(choice.dataset.waiterChoice);
        });
    });

    var changeTableBtn = document.getElementById('change-table-btn');
    if (changeTableBtn) {
        changeTableBtn.addEventListener('click', function () {
            var board = document.getElementById('dine-in-board');
            if (!board) return;
            board.classList.remove('is-collapsed');
            board.style.display = '';
            board.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

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
                // Close the modal and drop straight into the fresh session — no reload.
                var modalEl = document.getElementById('openTableModal');
                var modal   = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
                openBtn.disabled    = false;
                openBtn.textContent = 'Open Table';
                form.reset();

                clearCart();                 // fresh table → empty cart
                applyTableSession(result.data.session || {
                    id: result.data.session_id, branch_id: result.data.branch_id,
                });
                refreshTableBoard(result.data.session_id);
                toast('success', 'Table opened');
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
            toast('error', 'Network error. Please try again.');
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
        var preloadedLineKeys = {};

        heldSale.lines.forEach(function (line) {
            const product = products.find(function (p) { return Number(p.id) === Number(line.product_id); });
            if (!product) return;

            const variant = (product.variants || []).find(function (v) { return Number(v.id) === Number(line.product_variant_id); });
            const modifiers = normalizeModifiers(line.modifiers || []);
            const lineKind = line.line_kind || 'standard';
            const key = lineKind === 'combo_header'
                ? 'held-line:' + line.id
                : (lineKind === 'component' ? 'held-line:' + line.id : cartKey(product, variant, modifiers));
            const parentKey = line.parent_sales_order_line_id ? preloadedLineKeys[line.parent_sales_order_line_id] : '';

            cart.push({
                key:                key,
                client_line_key:    key,
                parent_key:         parentKey,
                parent_client_line_key: parentKey,
                line_kind:          lineKind,
                combo_id:           line.combo_id || '',
                product_id:         product.id,
                product_variant_id: variant ? variant.id : null,
                name:               product.name,
                product_name:       product.name,
                variant_name:       variant ? variant.name : null,
                unit_code:          line.unit_code || product.unit_code || '',
                quantity:           Number(line.quantity || 1),
                unit_price:         Number(line.unit_price || productPrice(product, variant)),
                base_unit_price:    Number(line.base_unit_price || 0) || Math.max(Number(line.unit_price || productPrice(product, variant)) - modifierPriceDelta(modifiers), 0),
                modifiers:          modifiers,
                discount_amount:    Number(line.discount_amount || 0),
                tax_amount:         Number(line.tax_amount || 0),
                product:            product,
                variant:            variant || null,
                _dbLineId:          line.id || null,
                kot_sent:           !!line.kot_sent,
            });
            preloadedLineKeys[line.id] = key;
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

@endsection
