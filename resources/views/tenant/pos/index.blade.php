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

    .pos-products-panel { min-width: 0; }

    .pos-card {
        border: 1px solid #edf0f4;
        border-radius: 8px;
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
        min-height: 44px;
        padding: .6rem 1rem;
        font-size: .95rem;
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
        border-radius: 8px;
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
        grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
        gap: .7rem;
        /* POS-UX-1: tighter fit — more products visible per screen */
        max-height: calc(100vh - 300px);
        overflow-y: auto;
        padding-right: .25rem;
    }

    .table-workspace-panel { min-height: 420px; }
    .table-workspace-panel .restaurant-board-grid {
        grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
        max-height: none;
        overflow: visible;
    }
    .table-workspace-toolbar { position: sticky; top: 0; z-index: 2; background: #fff; }
    .table-workspace-view[hidden] { display: none !important; }
    .table-action-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: .75rem; }
    .pos-session-summary { min-width: 0; }
    .pos-session-summary .session-context { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

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
        border-radius: 8px;
        background: linear-gradient(180deg, #ffffff, #fbfcfd);
        padding: .85rem;
        min-height: 148px;
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

    .product-name { font-size: 1rem; line-height: 1.3; }
    .product-price { font-size: 1.08rem; }

    .product-avatar {
        width: 36px;
        height: 36px;
        border-radius: 12px;
        display: grid;
        place-items: center;
        background: #f1f5f9;
        font-weight: 900;
        font-size: .8rem;
        margin-bottom: .4rem;
        overflow: hidden;
    }

    /* POS-UX-1: real product photos on tiles */
    .product-avatar.has-img { background: #fff; padding: 0; }
    .product-avatar.has-img img { width: 100%; height: 100%; object-fit: cover; border-radius: 12px; }

    .stock-badge {
        border-radius: 999px;
        background: #f8fafc;
        padding: .22rem .5rem;
        font-size: .75rem;
        font-weight: 800;
    }

    /* POS-UX-1: color language — green in stock, yellow low, red out, blue service */
    .stock-ok   { background: #dcfce7; color: #166534; }
    .stock-low  { background: #fff3cd; color: #7a5200; }
    .stock-out  { background: #fee2e2; color: #991b1b; }
    .stock-svc  { background: #dbeafe; color: #1e40af; }
    .stock-backorder { background: #ffedd5; color: #9a3412; }

    /* POS-UX-1: visible keyboard shortcut bar */
    .pos-shortcut-bar {
        display: flex; flex-wrap: wrap; gap: .4rem;
        padding: .35rem .6rem; margin-bottom: .5rem;
        background: #f8fafc; border: 1px solid #edf0f4; border-radius: 12px;
        font-size: .72rem; color: #475569;
    }
    .pos-shortcut-bar kbd {
        background: #0f172a; color: #fff; border-radius: 5px;
        padding: .1rem .35rem; font-size: .68rem;
    }

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
    .pos-charge-bar { flex: 0 0 auto; display: flex; align-items: center; gap: .75rem; padding: .6rem .8rem; margin-bottom: 0 !important; }
    .pos-charge-bar .pos-charge-amt { font-size: 1.6rem; font-weight: 800; line-height: 1.1; }
    .pos-actions .btn { min-height: 42px; padding-top: .45rem; padding-bottom: .45rem; }

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

    @media (min-width: 1200px) and (min-height: 720px) {
        .pos-shell {
            height: calc(100vh - 188px);
            min-height: 520px;
            align-items: stretch;
        }
        .pos-products-panel {
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }
        .product-grid {
            flex: 1 1 auto;
            min-height: 0;
            max-height: none;
        }
        .cart-panel {
            position: static;
            top: auto;
            height: 100%;
            min-height: 0;
            overflow: hidden;
        }
        .cart-section { min-height: 0; }
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
<div id="pos-session-bar" class="pos-card px-3 py-2 mb-3 d-flex flex-wrap align-items-center gap-2 pos-session-summary"
     data-session-base="{{ url('/restaurant/table-sessions') }}"
     style="{{ $activeMode === 'dine_in' ? '' : 'display:none;' }}">
    <button type="button" id="view-tables-btn" class="btn btn-dark btn-sm">
        <i class="ti ti-layout-grid me-1"></i>View Tables
    </button>
    <div class="session-context {{ $tableSession ? '' : 'd-none' }}" id="pos-session-details">
        <strong>Table <span id="pos-session-table-no">{{ $tableSession?->table?->table_no }}</span></strong>
        <span class="text-muted ms-1" id="pos-session-no">{{ $tableSession?->session_no }}</span>
        &middot; <span id="pos-session-waiter">{{ $tableSession?->waiter?->name ?? 'No waiter' }}</span>
        &middot; <span id="pos-session-guests">{{ $tableSession?->guest_count }}</span> guests
        &middot; Open check <strong id="pos-session-open-check">{{ number_format((float) ($tableSession?->salesOrders?->where('status', 'held')->sum('grand_total') ?? 0), 2) }}</strong>
    </div>
    <div class="d-flex gap-2 ms-auto flex-wrap {{ $tableSession ? '' : 'd-none' }}" id="pos-session-actions">
        @can('tenant.restaurant.table-sessions.bill-preview')
            <button type="button" id="pos-session-bill-preview" class="btn btn-sm btn-outline-dark"
                    data-session-id="{{ $tableSession?->id }}">Bill Preview</button>
        @endcan
        @can('tenant.restaurant.table-sessions.bill-requested')
            <form method="POST" id="pos-session-request-bill-form" class="d-inline"
                  action="{{ $tableSession ? url('/restaurant/table-sessions/' . $tableSession->id . '/bill-requested') : '#' }}"
                  style="{{ $tableSession && $tableSession->status === 'open' ? '' : 'display:none;' }}">
                @csrf
                <button class="btn btn-sm btn-info" type="submit"
                        title="Signal that the guest wants their bill. Marks this table as 'Bill Requested' so the cashier knows to prepare and close it — it does not charge anything.">Request Bill</button>
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
        @foreach(\App\Models\Tenant\User::ORDER_TYPES as $type => $label)
            @if(in_array($type, $allowedOrderTypes, true))
                <button type="button" class="mode-tab {{ $activeMode === $type ? 'active' : '' }}" data-mode-tab="{{ $type }}">{{ $label }}</button>
            @endif
        @endforeach
    </div>
</div>

{{-- POS form --}}
<form id="pos-sale-form" method="POST" action="{{ url('/pos') }}">
    @csrf
    <input type="hidden" name="order_source"                id="pos-order-source"      value="pos">
    {{-- SALE-IDEMPOTENCY-1: one logical sale = one client_uuid (survives retry/refresh) --}}
    <input type="hidden" name="client_uuid"                 id="client_uuid"                 value="">
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
        <section class="pos-card p-3 pos-products-panel" aria-labelledby="products_heading">
            {{-- POS-UX-2: compact header — small controls, order type lives in the tabs only --}}
            <div class="row g-2 mb-2" id="order-controls-row">
                <div class="col-md-3">
                    <label for="branch_id" class="form-label small mb-1 required">Branch</label>
                    <select id="branch_id" name="branch_id" class="form-select form-select-sm" required>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}"
                                data-allow-negative="{{ $branch->allow_negative_stock ? 1 : 0 }}"
                                @selected((int) $selectedBranchId === (int) $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="terminal_id" class="form-label small mb-1">Terminal</label>
                    <select id="terminal_id" name="terminal_id" class="form-select form-select-sm">
                        <option value="">No Terminal</option>
                        @foreach($terminals as $terminal)
                            <option value="{{ $terminal->id }}" data-branch="{{ $terminal->branch_id ?? '' }}">{{ $terminal->name }} &mdash; {{ $terminal->branch?->name }}</option>
                        @endforeach
                    </select>
                    {{-- Auto-print is terminal-driven; warn when none is selected --}}
                    <div id="no-terminal-warning" class="small text-warning-emphasis mt-1" style="display:none">
                        <i class="ti ti-alert-triangle me-1"></i>No terminal — auto receipt/KOT print is off
                    </div>
                    {{-- SHIFT-TIMEZONE-BUSINESS-DATE-1 (R/S): per-terminal shift status. No open shift
                         means POS operations on this terminal are blocked. --}}
                    <div id="pos-shift-status" class="small mt-1" style="display:none">
                        <span class="badge bg-secondary" id="pos-shift-badge"></span>
                        <span id="pos-shift-detail" class="text-muted ms-1"></span>
                        <a href="{{ url('/shifts/open') }}" id="pos-shift-open-link" class="ms-1" style="display:none">Open shift</a>
                    </div>
                </div>
                {{-- Order type is driven by the mode tabs above; keep the select for the
                     form payload + existing JS, but never show the duplicate control. --}}
                <div class="d-none">
                    <select id="order_type" name="order_type">
                        <option value="dine_in"    @selected($activeMode === 'dine_in')>Dine In</option>
                        <option value="takeaway"   @selected($activeMode === 'takeaway')>Takeaway</option>
                        <option value="quick_sale" @selected($activeMode === 'quick_sale')>Quick Sale</option>
                        <option value="delivery"   @selected($activeMode === 'delivery')>Delivery</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="customer_id" class="form-label small mb-1">Customer</label>
                    <div class="input-group input-group-sm">
                        <select id="customer_id" name="customer_id" class="form-select form-select-sm">
                            <option value="">Walk-in</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" @selected($heldSale?->customer_id === $customer->id)>
                                    {{ $customer->name }}{{ $customer->phone ? ' — ' . $customer->phone : '' }}
                                </option>
                            @endforeach
                        </select>
                        <button class="btn btn-outline-primary btn-sm" type="button"
                            data-bs-toggle="modal" data-bs-target="#quickCustomerModal">+</button>
                    </div>
                </div>
                <div class="col-md-3">
                    <label for="customer_phone" class="form-label small mb-1"><span id="phone-label-text">Customer Phone</span></label>
                    <input id="customer_phone" name="customer_phone" class="form-control form-control-sm"
                           placeholder="03xx-xxxxxxx" value="{{ $heldSale?->customer_phone }}"
                           title="Optional. Stored on the sale — useful for delivery orders (contacting the customer) and for looking the sale up later by phone. Leave blank for walk-ins.">
                </div>
            </div>

            {{-- DELIVERY-CHANNELS-1 + POS-UX-2: channel, rider + delivery address (delivery orders only) --}}
            <div class="row g-2 mb-2" id="delivery-panel" style="display:none">
                <div class="col-md-3">
                    <label for="delivery_channel_id" class="form-label small mb-1">Delivery Channel</label>
                    <select id="delivery_channel_id" name="delivery_channel_id" class="form-select form-select-sm">
                        <option value="">Select channel</option>
                        @foreach($deliveryChannels as $channel)
                            <option value="{{ $channel->id }}" data-type="{{ $channel->type }}" @selected($heldSale?->delivery_channel_id === $channel->id)>{{ $channel->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3" id="delivery-rider-wrap" style="display:none">
                    <label for="delivery_rider_id" class="form-label small mb-1">Rider</label>
                    <select id="delivery_rider_id" name="delivery_rider_id" class="form-select form-select-sm">
                        <option value="">Select rider</option>
                        @foreach($deliveryRiders as $rider)
                            <option value="{{ $rider->id }}" data-branch="{{ $rider->branch_id ?? '' }}" @selected($heldSale?->delivery_rider_id === $rider->id)>{{ $rider->name }}{{ $rider->phone ? ' - ' . $rider->phone : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="delivery_address" class="form-label small mb-1">Delivery Address</label>
                    <input id="delivery_address" name="delivery_address" class="form-control form-control-sm"
                           maxlength="500" placeholder="House / street / area for the rider"
                           value="{{ $heldSale?->delivery_address }}">
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-12">
                    <label for="pos_search" class="form-label small mb-1 visually-hidden">Barcode / Product Search</label>
                    <input id="pos_search" class="form-control form-control-lg" placeholder="Scan barcode or type product name / SKU">
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

            {{-- POS-UX-2: heading + shortcuts on one slim line --}}
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                <h2 id="products_heading" class="h6 mb-0">Products</h2>
            </div>
            <div class="product-grid" id="product-grid" aria-live="polite"></div>
        </section>

        {{-- RIGHT: cart + payment --}}
        <aside class="cart-panel">
            <section class="pos-card p-3 cart-section" aria-labelledby="cart_heading">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 id="cart_heading" class="h5 mb-0">{{ $tableSession ? 'Table Cart' : 'Cart' }}</h2>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="toggle-calc-btn" title="Calculator"><i class="ti ti-calculator"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="clear-cart-btn" title="Clear cart"><i class="ti ti-trash"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-dark" id="start-fresh-btn" title="Start another order">
                            <i class="ti ti-plus me-1"></i><span id="start-fresh-label">New Order</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="new-sale-btn"
                                title="Start a completely new sale. Any open table check stays on its table and can be recalled later.">
                            <i class="ti ti-file-plus me-1"></i>New Sale
                        </button>
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
                    <p class="text-muted mb-0"><i class="ti ti-scan me-1"></i>No items yet. Scan a barcode or search for a product.</p>
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
            <div id="recalled-order-bar" class="rounded-3 mb-2 px-3 py-2 d-flex align-items-center justify-content-between gap-2"
                 style="display:none;background:#fff3cd;border:1px solid #ffc107;">
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
                </div>
            </div>

            <div class="d-grid gap-2 pos-actions">
                <div class="row g-2">
                    <div class="col">
                        <button type="button" class="btn btn-warning btn-lg w-100 text-dark fw-semibold" id="hold-sale-btn">
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
                                title="Recent completed orders — reprint receipt / KOT">
                            <i class="ti ti-receipt-2 me-1"></i>Recent Orders
                        </button>
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col">
                        <button type="button" class="btn btn-outline-danger btn-lg w-100" id="cancel-order-btn">Cancel Order</button>
                    </div>
                    <div class="col" id="split-bill-wrap" style="display:none">
                        <button type="button" class="btn btn-outline-info btn-lg w-100" id="split-bill-link">Split Bill</button>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-secondary btn-lg px-3" id="last-print-btn"
                                title="Print history">
                            <i class="ti ti-printer"></i>
                        </button>
                    </div>
                </div>
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
                                        <div class="small text-muted mt-2">
                                            Reminder follows every accepted KOT round. Additional rounds use each printer's Auto/Ask setting.
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
<div class="modal fade" id="tableWorkspaceModal" tabindex="-1" aria-labelledby="tableWorkspaceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-fullscreen-lg-down modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title h5 mb-0" id="tableWorkspaceModalLabel">Table Workspace</h2>
                    <p class="text-muted small mb-0">Open, continue and manage active table checks without leaving POS.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body table-workspace-panel">
                <div class="table-workspace-toolbar d-flex align-items-center justify-content-between gap-2 pb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="table-workspace-back">
                        <i class="ti ti-arrow-left me-1"></i>Tables
                    </button>
                    <div class="ms-auto d-flex gap-2">
                        @can('tenant.restaurant.floors.index')
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-management-url="{{ url('/restaurant/floors?embed=1') }}">
                                <i class="ti ti-layers me-1"></i>Manage Floors
                            </button>
                        @endcan
                        @can('tenant.restaurant.tables.index')
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    data-management-url="{{ url('/restaurant/tables?branch_id=' . $selectedBranchId . '&embed=1') }}">
                                <i class="ti ti-settings me-1"></i>Manage Tables
                            </button>
                        @endcan
                    </div>
                </div>

                <section class="table-workspace-view" id="table-workspace-board">
                    <div id="table-board-body" data-board-url="{{ url('/api/pos/table-board') }}">
                        @include('tenant.pos.partials.table-board')
                    </div>
                </section>

                <section class="table-workspace-view" id="table-workspace-open" hidden>
                    <form id="open-table-form" method="POST" action="#" class="row g-3 mx-auto" style="max-width:780px">
                        @csrf
                        {{-- SHIFT-POS-INTEGRATION-CLOSURE-1: the table binds to the POS-selected terminal's
                             open shift; synced from #terminal_id on submit. --}}
                        <input type="hidden" name="terminal_id" id="open-table-terminal-id">
                        <div class="col-12"><h3 class="h5 mb-0">Open Table <span id="open-table-no">-</span></h3></div>
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
                        <div class="col-12 d-flex justify-content-end gap-2">
                            <button class="btn btn-light" type="button" data-table-workspace-home>Cancel</button>
                            <button class="btn btn-success" type="submit" id="open-table-submit">Open Table</button>
                        </div>
                    </form>
                </section>

                <section class="table-workspace-view" id="table-workspace-held" hidden>
                    <div id="table-workspace-held-body"></div>
                </section>
                <section class="table-workspace-view" id="table-workspace-move" hidden>
                    <div id="table-workspace-move-body"></div>
                </section>
                <section class="table-workspace-view" id="table-workspace-split" hidden>
                    <div id="table-workspace-split-body"></div>
                </section>
                <section class="table-workspace-view" id="table-workspace-manage" hidden>
                    <iframe id="table-management-frame" title="Table management" class="w-100 border-0" style="min-height:620px"></iframe>
                </section>
            </div>
        </div>
    </div>
</div>

{{-- One preview shell; live cart and authoritative table session remain separate data sources. --}}
<div class="modal fade" id="billPreviewModal" tabindex="-1" aria-labelledby="billPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="billPreviewModalLabel">Bill Preview</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="bill-preview-modal-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-primary" id="send-network-receipt-btn"
                        title="Send this order's receipt to the network printer (via the Print Agent).">
                    <i class="ti ti-broadcast me-1"></i>Send to network
                </button>
                <button type="button" class="btn btn-primary" id="print-bill-preview-btn"
                        title="Open your browser print dialog to print on the printer attached to this screen.">
                    <i class="ti ti-printer me-1"></i>Print here
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="splitBillModal" tabindex="-1" aria-labelledby="splitBillModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-fullscreen-lg-down modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="splitBillModalLabel">Split Bill</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="split-bill-modal-body"></div>
        </div>
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
                    <i class="ti ti-receipt-2 me-2"></i>Recent Orders
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
                            @if(in_array($val, $allowedOrderTypes, true))
                                <button type="button" class="btn btn-outline-secondary px-3 py-2 co-type-btn" data-co-type="{{ $val }}">
                                    {{ $label }}
                                </button>
                            @endif
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
    const branchCancellationModes = @json($branches->mapWithKeys(fn ($branch) => [(string) $branch->id => $branch->held_kot_cancellation_approval_mode ?? 'manager_required']));

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
        var bar = document.getElementById('pos-session-bar');
        if (bar) { bar.classList.remove('d-none'); bar.style.display = ''; }
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
            put('pos-session-open-check', money(session.open_check || 0));
            var bp = document.getElementById('pos-session-bill-preview');
            if (bp) bp.dataset.sessionId = session.id;
            var rb = document.getElementById('pos-session-request-bill-form');
            if (rb && base) {
                rb.action = base + '/' + session.id + '/bill-requested';
                rb.style.display = (!session.status || session.status === 'open') ? '' : 'none';
            }
            bar.classList.remove('d-none');
            bar.style.display = '';
            var details = document.getElementById('pos-session-details');
            var actions = document.getElementById('pos-session-actions');
            if (details) details.classList.remove('d-none');
            if (actions) actions.classList.remove('d-none');
        }
        forceDineInMode();
        setHidden('restaurant_table_session_id', session.id);
        setHidden('restaurant_table_id', session.table_id || '');
        setCompleteSaleLabel(true);
        updateStartFreshLabel();
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
                closeTableWorkspace();
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
        closeTableWorkspace();
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
    const customerEl       = document.getElementById('customer_id');

    /* ── DELIVERY-CHANNELS-1: channel + rider panel ─────────────────── */

    const deliveryPanelEl   = document.getElementById('delivery-panel');
    const deliveryChannelEl = document.getElementById('delivery_channel_id');
    const deliveryRiderWrap = document.getElementById('delivery-rider-wrap');
    const deliveryRiderEl   = document.getElementById('delivery_rider_id');
    const deliveryAddressEl = document.getElementById('delivery_address');

    function updateDeliveryPanel() {
        if (!deliveryPanelEl || !orderTypeEl) return;
        const isDelivery = orderTypeEl.value === 'delivery';
        deliveryPanelEl.style.display = isDelivery ? '' : 'none';
        if (deliveryChannelEl) deliveryChannelEl.required = isDelivery;

        // POS-UX-2: for delivery orders the phone stops being "optional" in spirit —
        // the rider needs a contact number. Label reflects that.
        const phoneLabel = document.getElementById('phone-label-text');
        if (phoneLabel) phoneLabel.textContent = isDelivery ? 'Customer Phone (needed for delivery)' : 'Customer Phone';

        if (!isDelivery) {
            // Never post stale channel/rider/address on a non-delivery sale.
            if (deliveryChannelEl) deliveryChannelEl.value = '';
            if (deliveryRiderEl)   deliveryRiderEl.value = '';
            if (deliveryRiderEl)   deliveryRiderEl.required = false;
            if (deliveryRiderWrap) deliveryRiderWrap.style.display = 'none';
            if (deliveryAddressEl) deliveryAddressEl.value = '';
            return;
        }

        // Rider applies to own-delivery channels only.
        const opt = deliveryChannelEl ? deliveryChannelEl.selectedOptions[0] : null;
        const isOwn = !!(opt && opt.dataset.type === 'own');
        if (deliveryRiderWrap) deliveryRiderWrap.style.display = isOwn ? '' : 'none';
        if (deliveryRiderEl) deliveryRiderEl.required = isOwn;
        if (!isOwn && deliveryRiderEl) deliveryRiderEl.value = '';

        // Riders are branch-scoped: hide riders belonging to OTHER branches.
        if (deliveryRiderEl) {
            const branchId = (document.getElementById('branch_id') || {}).value || '';
            Array.prototype.forEach.call(deliveryRiderEl.options, function (o) {
                if (!o.value) return;
                const riderBranch = o.dataset.branch || '';
                o.hidden = !!(riderBranch && branchId && riderBranch !== branchId);
            });
            const sel = deliveryRiderEl.selectedOptions[0];
            if (sel && sel.hidden) deliveryRiderEl.value = '';
        }
    }

    if (orderTypeEl)      orderTypeEl.addEventListener('change', updateDeliveryPanel);
    if (branchEl)         branchEl.addEventListener('change', updateDeliveryPanel);
    if (deliveryChannelEl) deliveryChannelEl.addEventListener('change', updateDeliveryPanel);
    updateDeliveryPanel();

    /* ── POS-UX-2: terminal auto-select + remember ─────────────────────────
       Auto receipt/KOT printing is terminal-driven; a forgotten "No Terminal"
       silently disables printing. Remember the cashier's choice per branch and
       pre-select the branch's terminal when nothing is chosen. */

    const noTerminalWarning = document.getElementById('no-terminal-warning');

    function updateTerminalWarning() {
        if (noTerminalWarning) {
            noTerminalWarning.style.display = (terminalEl && terminalEl.value) ? 'none' : '';
        }
    }

    // SHIFT-TIMEZONE-BUSINESS-DATE-1 (R/S): reflect the selected terminal's open-shift status so
    // the cashier knows before ringing anything whether POS operations are allowed here.
    var _shiftStatusSeq = 0;
    function refreshShiftStatus() {
        var wrap = document.getElementById('pos-shift-status');
        if (!wrap || !terminalEl) return;
        var badge  = document.getElementById('pos-shift-badge');
        var detail = document.getElementById('pos-shift-detail');
        var link   = document.getElementById('pos-shift-open-link');
        var tid    = terminalEl.value || '';

        if (!tid) { wrap.style.display = 'none'; return; }

        var seq = ++_shiftStatusSeq;
        fetch('{{ url('/api/pos/shift-status') }}?terminal_id=' + encodeURIComponent(tid), {
            headers: { 'Accept': 'application/json' }, credentials: 'same-origin'
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || seq !== _shiftStatusSeq) return; // ignore stale responses
                wrap.style.display = '';
                if (d.open) {
                    badge.className = 'badge bg-success';
                    badge.textContent = 'Shift open';
                    detail.textContent = 'Business date ' + (d.business_date || '') +
                        (d.opened_at ? ' · opened ' + d.opened_at : '');
                    if (link) link.style.display = 'none';
                } else {
                    badge.className = 'badge bg-danger';
                    badge.textContent = 'No open shift';
                    detail.textContent = 'POS operations are blocked on this terminal.';
                    if (link) link.style.display = '';
                }
                // One source of truth: retune the shop clock to THIS terminal's shift timezone +
                // business date + server epoch. Falls back to the branch business tz when closed.
                if (window.__setPosClock) {
                    window.__setPosClock({ tz: d.timezone, businessDate: d.business_date, serverEpochMs: d.server_epoch_ms });
                }
            })
            .catch(function () { /* status is advisory; never block the POS on a fetch error */ });
    }

    // Terminal-aware periodic resync: refresh shift status (tz + business date + server epoch) so a
    // long POS session stays aligned to the server for the currently selected terminal.
    setInterval(function () { refreshShiftStatus(); }, 300000);

    function autoSelectTerminal() {
        if (!terminalEl) return;
        if (terminalEl.value) { updateTerminalWarning(); return; }

        var branchId = branchEl ? branchEl.value : '';
        var saved = null;
        try { saved = localStorage.getItem('pos_terminal_' + branchId); } catch (e) {}

        var candidate = '';
        for (var i = 0; i < terminalEl.options.length; i++) {
            var o = terminalEl.options[i];
            if (!o.value) continue;
            if (saved && o.value === saved && (!o.dataset.branch || o.dataset.branch === branchId)) {
                candidate = o.value;
                break;
            }
            if (!candidate && o.dataset.branch === branchId) {
                candidate = o.value; // first terminal of this branch as fallback
            }
        }

        if (candidate) terminalEl.value = candidate;
        updateTerminalWarning();
    }

    if (terminalEl) {
        terminalEl.addEventListener('change', function () {
            var branchId = branchEl ? branchEl.value : '';
            if (terminalEl.value) {
                try { localStorage.setItem('pos_terminal_' + branchId, terminalEl.value); } catch (e) {}
            }
            updateTerminalWarning();
            refreshShiftStatus();
        });
    }
    autoSelectTerminal();
    refreshShiftStatus();

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

    // NEGATIVE-STOCK-SETTING-1B: does the currently selected branch allow
    // selling stock-out items (official stock may go negative)? Read live from
    // the branch option so it always matches the selected branch.
    function branchAllowsNegative() {
        var sel = document.getElementById('branch_id');
        var opt = sel && sel.selectedOptions ? sel.selectedOptions[0] : null;
        return !!(opt && opt.dataset.allowNegative === '1');
    }

    function backorderToast(name) {
        toast('warning', 'Backorder — ' + name + ' stock will go negative');
    }

    // How many of a combo can be made right now = the lowest of its components'
    // availability ÷ the qty each combo needs. Returns { makeable, limiting }:
    //   makeable = null → unlimited (all components are plain services)
    //   makeable = 0    → unavailable; `limiting` names the blocking component.
    function comboAvailability(combo) {
        var makeable = Infinity;
        var limiting = null;
        (combo.components || []).forEach(function (component) {
            var product = products.find(function (p) { return Number(p.id) === Number(component.product_id); });
            if (!product) { makeable = 0; limiting = component.product_name || 'a component'; return; }
            var variant = (product.variants || []).find(function (v) {
                return Number(v.id) === Number(component.product_variant_id);
            }) || null;
            var avail = availableQty(product, variant);   // null = unlimited service
            if (avail === null) return;
            var perCombo = Number(component.quantity || 1) || 1;
            var canMake  = Math.floor(avail / perCombo);
            if (canMake < makeable) { makeable = canMake; limiting = product.name; }
        });
        if (makeable === Infinity) return { makeable: null, limiting: null };
        return { makeable: Math.max(0, makeable), limiting: limiting };
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
            const avail      = comboAvailability(combo);
            const isOut      = avail.makeable !== null && avail.makeable <= 0;
            const isLow      = !isOut && avail.makeable !== null && avail.makeable <= 5;
            const negOk      = branchAllowsNegative();
            const badgeClass = isOut ? (negOk ? 'stock-backorder' : 'stock-out') : (isLow ? 'stock-low' : '');
            const badgeText  = isOut ? (negOk ? 'Backorder' : 'Unavailable') : 'Combo';
            const note       = isOut
                ? '<div class="small ' + (negOk ? 'text-warning' : 'text-danger') + ' mt-2">' + escapeHtml(avail.limiting || 'A component') + ' out of stock</div>'
                : '<div class="small text-muted mt-2">' + combo.components.length + ' items' + (isLow ? ' &middot; makes ' + avail.makeable : '') + '</div>';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-tile' + (isOut && !negOk ? ' stock-out' : '');
            button.innerHTML =
                '<div class="product-avatar"><i class="ti ti-package"></i></div>' +
                '<div class="fw-bold mb-1 product-name">' + escapeHtml(combo.name) + '</div>' +
                '<div class="text-muted small mb-2">' + escapeHtml(combo.code || 'Combo') + '</div>' +
                '<div class="d-flex justify-content-between align-items-center">' +
                    '<span class="fw-bold product-price">' + money(combo.price) + '</span>' +
                    '<span class="stock-badge ' + badgeClass + '">' + badgeText + '</span>' +
                '</div>' +
                note;

            button.addEventListener('click', function () { addComboToCart(combo); });
            productGrid.appendChild(button);
        });

        filtered.forEach(function (product) {
            const variant    = product.variants && product.variants.length ? product.variants[0] : null;
            const qty        = availableQty(product, variant);
            const price      = productPrice(product, variant);
            const isRecipe   = !product.is_stock_tracked && product.is_recipe;
            const negAllowed = branchAllowsNegative();
            const stockClass = qty === null ? 'stock-svc' : qty <= 0 ? (negAllowed ? 'stock-backorder' : 'stock-out') : qty <= 5 ? 'stock-low' : 'stock-ok';
            const stockText  = qty === null
                ? 'Service'
                : (qty <= 0 ? (negAllowed ? 'Backorder' : 'Out') : (isRecipe ? 'Makes ' + qty : 'Stock ' + qty));

            // POS-UX-1: real product image on the tile when available.
            const avatarHtml = product.image_url
                ? '<div class="product-avatar has-img"><img src="' + escapeHtml(product.image_url) + '" alt="" loading="lazy"></div>'
                : '<div class="product-avatar">' + escapeHtml(initials(product.name)) + '</div>';

            const button     = document.createElement('button');
            button.type      = 'button';
            button.className = 'product-tile';
            button.innerHTML =
                avatarHtml +
                '<div class="fw-bold mb-1 product-name">' + escapeHtml(product.name) + '</div>' +
                '<div class="text-muted small mb-2">' + escapeHtml(product.sku || 'No SKU') + '</div>' +
                '<div class="d-flex justify-content-between align-items-center">' +
                    '<span class="fw-bold product-price">' + money(price) + '</span>' +
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
        var avail = comboAvailability(combo);
        if (avail.makeable !== null && avail.makeable <= 0) {
            if (!branchAllowsNegative()) {
                blockAlert(combo.name + ' is unavailable — '
                    + (avail.limiting ? avail.limiting + ' is out of stock' : 'a component is out of stock') + '.');
                return;
            }
            backorderToast(combo.name);
        }

        var key = 'combo:' + combo.id;
        var existing = cart.find(function (item) { return item.key === key; });

        if (existing) {
            var nextQty = Number(existing.quantity || 0) + 1;
            if (avail.makeable !== null && nextQty > avail.makeable) {
                if (!branchAllowsNegative()) {
                    blockAlert('Only ' + avail.makeable + ' × ' + combo.name + ' can be made right now'
                        + (avail.limiting ? ' (' + avail.limiting + ')' : '') + '.');
                    return;
                }
                backorderToast(combo.name);
            }
            existing.quantity = nextQty;
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
        ensureSaleUuid();  // SALE-IDEMPOTENCY-1: a logical sale begins at first item
        var modifiers = normalizeModifiers(selectedModifiers || []);
        var key       = cartKey(product, variant, modifiers);
        var existing  = cart.find(function (item) { return item.key === key; });
        var stockQty  = availableQty(product, variant);
        var measurable = isMeasurableProduct(product);

        if (stockQty !== null && stockQty <= 0) {
            if (!branchAllowsNegative()) { blockAlert(unavailableMessage(product, 0)); return; }
            backorderToast(product.name);
        }

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
                if (!branchAllowsNegative()) {
                    blockAlert(unavailableMessage(product, stockQty));
                    return;
                }
                backorderToast(product.name);
            }
            existing.quantity = newQty;
        } else {
            if (stockQty !== null && addQty > stockQty + 0.0001) {
                if (!branchAllowsNegative()) {
                    blockAlert(unavailableMessage(product, stockQty));
                    return;
                }
                backorderToast(product.name);
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
                _dbLineId:          null,
                kot_sent:           false,
                kot_sent_quantity:  0,
            });
        }

        renderCart();
    }

    // POS-UX-2: primary actions only make sense with items in the cart —
    // dim them on empty so the cashier's eye goes to scanning first.
    function updateCartActionStates() {
        var empty = !cart.length;
        ['complete-sale-btn', 'hold-sale-btn', 'bill-preview-btn'].forEach(function (id) {
            var b = document.getElementById(id);
            if (b) {
                b.disabled = empty;
                b.classList.toggle('opacity-50', empty);
            }
        });
    }

    function upsertVoidItem(item, quantity, voidData) {
        window._voidItems = (window._voidItems || []).filter(function (entry) {
            return Number(entry.old_line_id) !== Number(item._dbLineId);
        });
        if (quantity > 0) {
            window._voidItems.push({
                old_line_id: item._dbLineId,
                quantity: quantity,
                reason_id: voidData.reason_id,
                manager_approval_id: voidData.manager_approval_id,
                product_name: item.product_name || item.product?.name || '',
            });
        }
    }

    function applyItemQuantity(index, newQuantity) {
        const item = cart[index];
        if (!item) return;
        if (newQuantity <= 0.0001) {
            if (item.line_kind === 'combo_header') {
                cart = cart.filter(function (row) { return row.key !== item.key && row.parent_key !== item.key; });
            } else {
                cart.splice(index, 1);
            }
        } else {
            item.quantity = newQuantity;
            if (item.line_kind === 'combo_header') updateComboComponents(item.key);
        }
        renderCart();
    }

    function requestComboQuantity(index, newQuantity) {
        const header = cart[index];
        const components = cart.filter(function (row) {
            return row.line_kind === 'component' && row.parent_key === header.key;
        });
        const cancellations = components.map(function (component) {
            const sent = Number(component.kot_sent_quantity || (component.kot_sent ? component.quantity : 0) || 0);
            const target = Math.max(Number(component.combo_component_qty || 0) * Math.max(newQuantity, 0), 0);
            return {
                item: component,
                line_id: Number(component._dbLineId || 0),
                quantity: Math.max(sent - Math.min(target, sent), 0),
            };
        }).filter(function (entry) { return entry.quantity > 0.000001; });

        if (!cancellations.length) {
            applyItemQuantity(index, newQuantity);
            return;
        }
        if (!_currentHeldSaleId || cancellations.some(function (entry) { return !entry.line_id; })) {
            toast('error', 'Recall the held order before cancelling a deal already sent to kitchen.');
            return;
        }
        if (!voidReasons.length) {
            toast('error', 'Configure an active void reason before cancelling KOT items.');
            return;
        }

        const options = {};
        voidReasons.forEach(function (reason) { options[reason.id] = reason.name; });
        Swal.fire({
            title: 'Cancel Sent Deal Quantity',
            text: 'Select a reason. The kitchen will receive cancellation lines for every affected deal component.',
            input: 'select',
            inputOptions: options,
            inputPlaceholder: 'Select reason',
            showCancelButton: true,
            confirmButtonText: 'Continue',
            inputValidator: function (value) { return value ? undefined : 'Select a cancellation reason'; },
        }).then(function (result) {
            if (!result.isConfirmed) return;
            const reasonId = result.value;
            const finish = function (approvalId) {
                cancellations.forEach(function (entry) {
                    upsertVoidItem(entry.item, entry.quantity, {
                        reason_id: reasonId,
                        manager_approval_id: approvalId,
                    });
                });
                applyItemQuantity(index, newQuantity);
            };

            if (currentCancellationMode() === 'auto_approve') {
                finish(null);
                return;
            }

            const approvalLines = cancellations.map(function (entry) {
                return { line_id: entry.line_id, quantity: entry.quantity };
            }).sort(function (a, b) { return a.line_id - b.line_id; });
            showManagerPinModal('void_kot_items', {
                sales_order_id: _currentHeldSaleId,
                cancellations: approvalLines,
            }, finish);
        });
    }

    function requestItemQuantity(index, newQuantity) {
        const item = cart[index];
        if (!item) return;
        if (item.line_kind === 'combo_header') {
            requestComboQuantity(index, newQuantity);
            return;
        }
        const sentQuantity = Number(item.kot_sent_quantity || (item.kot_sent ? item.quantity : 0) || 0);
        const cancelQuantity = Math.max(sentQuantity - Math.min(Math.max(newQuantity, 0), sentQuantity), 0);

        if (cancelQuantity <= 0.000001) {
            if (item._dbLineId) upsertVoidItem(item, 0, {});
            applyItemQuantity(index, newQuantity);
            return;
        }
        if (!_currentHeldSaleId || !item._dbLineId) {
            toast('error', 'Recall the held order before cancelling an item already sent to kitchen.');
            return;
        }
        showVoidReasonModal(index, cancelQuantity, function (voidData) {
            upsertVoidItem(item, cancelQuantity, voidData);
            applyItemQuantity(index, newQuantity);
        });
    }

    function renderCart() {
        cartItemsEl.innerHTML = '';
        updateCartActionStates();

        if (!cart.length) {
            cartItemsEl.innerHTML = '<p class="text-muted mb-0"><i class="ti ti-scan me-1"></i>No items yet. Scan a barcode or search for a product.</p>';
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
            var sentToKitchen = Number(item.kot_sent_quantity || 0) > 0;
            if (item.line_kind === 'combo_header') {
                sentToKitchen = cart.some(function (child) {
                    return child.parent_key === item.key
                        && child.line_kind === 'component'
                        && Number(child.kot_sent_quantity || 0) > 0;
                });
            }
            var removeBtnHtml = sentToKitchen
                ? '<button type="button" class="btn btn-sm btn-outline-warning" data-remove="' + index + '" title="Cancel kitchen item"><i class="ti ti-receipt-off"></i></button>'
                : '<button type="button" class="btn btn-sm btn-outline-danger" data-remove="' + index + '" title="Remove item"><i class="ti ti-x"></i></button>';
            row.innerHTML =
                '<div class="d-flex justify-content-between gap-2 mb-2">' +
                    '<div>' +
                        '<div class="fw-bold">' + escapeHtml(item.name) + '</div>' +
                        '<div class="small text-muted">' + escapeHtml(item.variant_name || 'Default') + ' &middot; ' + money(item.unit_price) + (lineUnitLabel(item) ? ' / ' + escapeHtml(lineUnitLabel(item)) : '') + '</div>' +
                        modifierHtml +
                        componentHtml +
                    '</div>' +
                    '<div class="d-flex gap-1">' + editBtnHtml + removeBtnHtml +
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
                if (newQty <= 0.0001) { requestItemQuantity(i, 0); return; }
                var stockQty = availableQty(item.product, item.variant);
                if (stockQty !== null && newQty > stockQty + 0.0001) {
                    blockAlert(unavailableMessage(item.product, stockQty));
                    input.value = formatQty(item.quantity, item.product);
                    return;
                }
                requestItemQuantity(i, newQty);
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
                const newQuantity = isMeasurableProduct(item.product)
                    ? parseFloat((Number(item.quantity || 0) - step).toFixed(3))
                    : Number(item.quantity || 0) - 1;
                requestItemQuantity(i, newQuantity);
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
                const hasSentComboComponent = item && item.line_kind === 'combo_header' && cart.some(function (child) {
                    return child.parent_key === item.key
                        && child.line_kind === 'component'
                        && Number(child.kot_sent_quantity || 0) > 0;
                });
                if (item && (Number(item.kot_sent_quantity || 0) > 0 || hasSentComboComponent)) {
                    requestItemQuantity(idx, 0);
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

    /* ── SALE-IDEMPOTENCY-1: one logical sale = one client_uuid ─────────────
       Generated when a sale begins, persisted so a refresh/retry/timeout reuses
       the SAME uuid, rotated only after a successful/replayed sale or a clear. */
    /* HARDEN-1: the mid-sale uuid lives in sessionStorage (per-TAB, so two tabs never
       share one), under a key scoped to origin + branch + terminal (so two branches /
       terminals never collide). Two registers open in two tabs each get their own
       logical sale; a refresh in the SAME tab reuses the same uuid (no double-post). */
    function saleUuidKey() {
        var b = (document.getElementById('branch_id')   || {}).value || '0';
        var t = (document.getElementById('terminal_id') || {}).value || '0';
        return 'pos_sale_uuid:' + (location.host || '') + ':' + b + ':' + t;
    }

    function genUuid() {
        if (window.crypto && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    function ensureSaleUuid() {
        var el = document.getElementById('client_uuid');
        if (!el) return '';
        var key = saleUuidKey();
        var u = el.value;
        if (!u) { try { u = sessionStorage.getItem(key) || ''; } catch (e) {} }
        if (!u) { u = genUuid(); try { sessionStorage.setItem(key, u); } catch (e) {} }
        el.value = u;
        return u;
    }

    function rotateSaleUuid() {
        var el = document.getElementById('client_uuid');
        if (el) el.value = '';
        try { sessionStorage.removeItem(saleUuidKey()); } catch (e) {}
    }

    // Restore a mid-sale uuid on page load (refresh in this tab must not rotate it).
    (function () {
        try {
            var u = sessionStorage.getItem(saleUuidKey());
            var el = document.getElementById('client_uuid');
            if (u && el) el.value = u;
        } catch (e) {}
    })();

    function buildInputs(includePayment) {
        dynamicInputs.innerHTML = '';

        cart.forEach(function (item, index) {
            var fields = {
                sales_order_line_id: item._dbLineId || '',
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

            var printIntents = {
                kot_print_intent: _directPayKotIntent || 'skip',
                receipt_print_intent: _directPayReceiptIntent || 'skip',
            };
            Object.keys(printIntents).forEach(function (field) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = field;
                inp.value = printIntents[field];
                dynamicInputs.appendChild(inp);
            });
        }

        // Append void_items collected during this session
        const voidItems = window._voidItems || [];
        voidItems.forEach(function (vi, i) {
            ['old_line_id', 'quantity', 'reason_id', 'manager_approval_id', 'product_name'].forEach(function (f) {
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
                fb.innerHTML = '<span class="text-success"><i class="ti ti-check me-1"></i>' + escapeHtml(data.promotion_name || 'Promo') + ' applied</span>';
                document.getElementById('remove-promo-btn').classList.remove('d-none');
                document.getElementById('apply-promo-btn').classList.add('d-none');
            } else {
                fb.innerHTML = '<span class="text-danger">' + escapeHtml(data.message || 'Invalid promo code') + '</span>';
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

    function currentCancellationMode() {
        const branchId = document.getElementById('branch_id')?.value || '';
        return branchCancellationModes[String(branchId)] || 'manager_required';
    }

    function showVoidReasonModal(lineIndex, cancelQuantity, callback) {
        const line = cart[lineIndex];
        let html = '<div class="mb-3"><strong>' + (line.product_name || line.product?.name || 'Item') + '</strong><br><small class="text-muted">This item was already sent to kitchen (KOT). Please select a void reason.</small></div>';
        if (!voidReasons.length) {
            toast('error', 'Configure an active void reason before cancelling KOT items.');
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
                        const requiresPin = currentCancellationMode() !== 'auto_approve';
                        Swal.close();
                        if (requiresPin) {
                            showManagerPinModal('void_kot_item', {
                                sales_order_id: _currentHeldSaleId,
                                sales_order_line_id: line._dbLineId,
                                quantity: cancelQuantity,
                            }, function (approvalId) {
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

    function showManagerPinModal(actionType, payload, onSuccess, onCancel) {
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
                    body: JSON.stringify({ pin: pin, action_type: actionType, payload: payload || {} }),
                })
                .then(function (res) {
                    return res.json().then(function (data) {
                        if (!res.ok) throw new Error(data.message || 'Approval failed');
                        return data;
                    });
                })
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
    // Recent-print pointer persists across reloads so the Recent Prints / reprint
    // modal still works after the POS page is refreshed (the jobs themselves live
    // server-side; only the "which sale" pointer was being lost in memory).
    let _lastSaleId        = localStorage.getItem('pos_last_sale_id') || null;
    let _lastSaleNo        = localStorage.getItem('pos_last_sale_no') || null;
    let _lastPrintModal    = null;

    function rememberLastSale(id, no) {
        _lastSaleId = id || null;
        _lastSaleNo = no || null;
        try {
            if (id) { localStorage.setItem('pos_last_sale_id', id); }
            localStorage.setItem('pos_last_sale_no', no || '');
        } catch (e) { /* localStorage unavailable — in-memory still works this session */ }
    }

    /* ── Cart clear helper ────────────────────────────────────────────── */

    function clearCart(options) {
        options = options || {};
        _directPayKotIntent = null;
        _directPayReceiptIntent = null;
        const preservedSessionId = options.preserveTable ? (document.getElementById('restaurant_table_session_id')?.value || '') : '';
        const preservedTableId = options.preserveTable ? (document.getElementById('restaurant_table_id')?.value || '') : '';
        rotateSaleUuid();          // SALE-IDEMPOTENCY-1: next sale gets a fresh uuid
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
        if (options.preserveTable) {
            if (tblSessionInput) tblSessionInput.value = preservedSessionId;
            if (tblIdInput) tblIdInput.value = preservedTableId;
        }
        renderCart();
        updateSplitBillBtn();
        updateRecalledBar();
        unlockOrderControls();
        updateStartFreshLabel();
    }

    function updateStartFreshLabel() {
        const label = document.getElementById('start-fresh-label');
        const btn = document.getElementById('start-fresh-btn');
        const sessionId = document.getElementById('restaurant_table_session_id')?.value || '';
        if (label) label.textContent = sessionId ? 'Add Round' : 'New Order';
        // #7: make the button's meaning obvious in each state.
        if (btn) btn.title = sessionId
            ? 'Add Round — start another order for THIS table without closing it. Previously sent items stay; the new items become a fresh round on the same table.'
            : 'New Order — clear the screen and start a brand-new sale.';
    }

    function updateSplitBillBtn() {
        const wrap = document.getElementById('split-bill-wrap');
        const link = document.getElementById('split-bill-link');
        if (!wrap || !link) return;
        if (_currentHeldSaleId) {
            link.dataset.saleId = _currentHeldSaleId;
            wrap.style.display = '';
        } else {
            delete link.dataset.saleId;
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
    const receiptLayouts = @json($receiptLayouts);

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
        return fetch('{{ url('/printing/jobs/kot') }}/' + saleId + query, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        })
        .then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) throw new Error(data.message || 'KOT could not be queued.');
                return data;
            });
        })
        .then(function (data) {
            (data.jobs || []).forEach(function (job) {
                if (job.fallback || job.printer_type === 'browser') {
                    toast('warning', 'No printer found — opening KOT for manual print');
                    openPreviewTab(job.preview_url);
                    return;
                }
                Object.keys(job.line_quantities || {}).forEach(function (lineId) {
                    const item = cart.find(function (candidate) {
                        return Number(candidate._dbLineId) === Number(lineId);
                    });
                    if (item) {
                        item.kot_sent = true;
                        item.kot_sent_quantity = Math.min(
                            Number(item.quantity) || 0,
                            Number(item.kot_sent_quantity || 0) + Number(job.line_quantities[lineId] || 0)
                        );
                    }
                });
            });
            renderCart();
            handleReminderPlan(saleId, data.reminder || {});
            return data;
        })
        .catch(function (error) {
            toast('error', error.message || 'KOT could not be queued.');
            return null;
        });
    }

    function handleReminderPlan(saleId, reminder) {
        if (reminder.warning) toast('warning', reminder.warning);
        const printers = reminder.ask_printers || [];
        if (!printers.length || !reminder.confirmation_token || typeof Swal === 'undefined') return Promise.resolve();

        return Swal.fire({
            title: 'Resend updated Reminder?',
            html: 'Send the complete updated order to:<br><strong>'
                + printers.map(function (printer) { return escapeHtml(printer.name); }).join('<br>')
                + '</strong>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Send',
            cancelButtonText: 'No',
            reverseButtons: true,
        }).then(function (result) {
            var decision = result.isConfirmed ? 'confirm' : 'decline';
            return fetch('{{ url('/printing/jobs/reminder') }}/' + saleId + '/confirm', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    confirmation_token: reminder.confirmation_token,
                    decision: decision,
                }),
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) throw new Error(data.message || 'Reminder could not be queued.');
                    return data;
                });
            }).then(function (data) {
                if (result.isConfirmed) {
                    toast('success', (data.jobs || []).length + ' updated Reminder job(s) queued');
                }
            }).catch(function (error) {
                toast('error', error.message || 'Reminder could not be queued.');
            });
        });
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
                openPreviewTab(data.preview_url);
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

    var _backorderConfirmed = false;
    var _directPayKotIntent = null;
    var _directPayReceiptIntent = null;

    function resolveDirectPayKotIntent() {
        if (_directPayKotIntent !== null) return Promise.resolve(true);
        if (kotPending().pending <= 0) {
            _directPayKotIntent = 'skip';
            return Promise.resolve(true);
        }
        if (terminalAutoKot()) {
            _directPayKotIntent = 'print';
            return Promise.resolve(true);
        }
        if (typeof Swal === 'undefined') {
            _directPayKotIntent = 'skip';
            return Promise.resolve(true);
        }

        return Swal.fire({
            title: 'Print Kitchen Order?',
            html: 'Choose before payment is completed. Reminder follows an accepted KOT round.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Print KOT',
            cancelButtonText: 'Skip',
            confirmButtonColor: '#0d6efd',
            reverseButtons: true,
            allowOutsideClick: false,
            allowEscapeKey: false,
        }).then(function (result) {
            _directPayKotIntent = result.isConfirmed ? 'print' : 'skip';
            return true;
        });
    }

    function processDirectPayPrinting(saleId, printing) {
        printing = printing || {};
        (printing.kot_jobs || []).forEach(function (job) {
            if ((job.fallback || job.printer_type === 'browser') && job.preview_url) {
                toast('warning', 'No printer found - opening KOT for manual print');
                openPreviewTab(job.preview_url);
            }
        });
        if (printing.receipt && (printing.receipt.fallback || printing.receipt.printer_type === 'browser')) {
            toast('warning', 'No printer found - opening receipt for manual print');
            openPreviewTab(printing.receipt.preview_url);
        }

        return handleReminderPlan(saleId, printing.reminder || {}).then(function () {
            if (!printing.retry_available) return printing;
            if (typeof Swal === 'undefined') {
                toast('warning', 'Sale paid. Printing is pending and can be retried from Recent Prints.');
                return printing;
            }

            return Swal.fire({
                icon: 'warning',
                title: 'Payment Successful',
                html: 'The sale is paid, but one or more print instructions are pending.',
                showCancelButton: true,
                confirmButtonText: 'Retry Printing',
                cancelButtonText: 'Continue',
                confirmButtonColor: '#d4a72c',
            }).then(function (choice) {
                if (!choice.isConfirmed) return printing;
                return fetch('{{ url('/pos') }}/' + saleId + '/printing/retry', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                }).then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok) throw new Error(data.message || 'Printing retry failed.');
                        return data.printing || {};
                    });
                }).then(function (retried) {
                    return processDirectPayPrinting(saleId, retried);
                }).catch(function (error) {
                    toast('error', error.message || 'Printing retry failed.');
                    return printing;
                });
            });
        });
    }

    function submitPaidSale() {
        if (!cart.length) { toast('warning', 'Add at least one item'); return; }

        if (_directPayKotIntent === null) {
            resolveDirectPayKotIntent().then(function () { submitPaidSale(); });
            return;
        }

        // NEGATIVE-STOCK-SETTING-1B: warn once when the cart will push official
        // stock negative on a branch that allows it. Backend remains authoritative.
        if (!_backorderConfirmed && branchAllowsNegative() && typeof Swal !== 'undefined') {
            var shortfall = cart.filter(function (item) {
                if (!item.product) return false;
                var avail = availableQty(item.product, item.variant);
                return avail !== null && Number(item.quantity || 0) > avail + 0.0001;
            });

            if (shortfall.length) {
                var listHtml = shortfall.map(function (item) {
                    return '<li>' + escapeHtml(item.name || '') + ' — qty ' + item.quantity + '</li>';
                }).join('');
                Swal.fire({
                    icon: 'warning',
                    title: 'Backorder sale',
                    html: 'This sale includes items with insufficient stock. Official inventory for this branch may go negative and COGS will use the last known cost. Continue?'
                        + '<ul class="text-start small mt-2 mb-0">' + listHtml + '</ul>',
                    showCancelButton: true,
                    confirmButtonText: 'Continue',
                    confirmButtonColor: '#d97706',
                }).then(function (res) {
                    if (res.isConfirmed) {
                        _backorderConfirmed = true;
                        submitPaidSale();
                    } else {
                        _directPayKotIntent = null;
                    }
                });
                return;
            }
        }
        _backorderConfirmed = false;
        _directPayReceiptIntent = autoPrintEnabled('receipt') ? 'print' : 'skip';

        const submitBtn  = document.getElementById('complete-sale-btn');
        const origLabel  = submitBtn.textContent;
        submitBtn.disabled    = true;
        submitBtn.textContent = 'Processing…';

        ensureSaleUuid();  // SALE-IDEMPOTENCY-1: stamp the sale's uuid before submit

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
                if (!result.ok) {
                    submitBtn.disabled    = false;
                    submitBtn.textContent = origLabel;
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

                rememberLastSale(saleId, saleNo);

                // SALE-IDEMPOTENCY-HARDEN-1: a replay means this sale already POSTED on
                // an earlier attempt (retry / network timeout). The accounting is NOT
                // repeated, but we still ENSURE the receipt/KOT print — the earlier
                // attempt may have died before printing. Both print endpoints are
                // idempotent (receipt = ensure-once, KOT = un-sent delta), so this
                // recovers a missed copy without ever duplicating one.
                var isReplay = !!result.data.idempotent_replay;

                return processDirectPayPrinting(saleId, result.data.printing || {}).then(function () {
                    clearCart();
                    _directPayKotIntent = null;
                    _directPayReceiptIntent = null;
                    toast(isReplay ? 'info' : 'success',
                          isReplay ? ('Sale ' + saleNo + ' already completed - printing re-checked.')
                                   : ('Sale complete! ' + saleNo));
                    var pmEl = document.getElementById('paymentModal');
                    if (pmEl && window.bootstrap) {
                        var pmInst = bootstrap.Modal.getInstance(pmEl);
                        if (pmInst) pmInst.hide();
                    }
                }).finally(function () {
                    submitBtn.disabled = false;
                    submitBtn.textContent = origLabel;
                });
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
                rememberLastSale(saleId, saleNo);
                if (heldInput) heldInput.value = saleId;

                (result.data.lines || []).forEach(function (savedLine) {
                    const item = cart.find(function (candidate) {
                        return (candidate.client_line_key || candidate.key || '') === (savedLine.client_line_key || '');
                    });
                    if (!item) return;
                    item._dbLineId = Number(savedLine.id);
                    item.kot_sent = !!savedLine.kot_sent;
                    item.kot_sent_quantity = Number(savedLine.kot_sent_quantity || 0);
                });

                if (result.data.restaurant_table_session_id) {
                    const tblSessInput = document.getElementById('restaurant_table_session_id');
                    if (tblSessInput) tblSessInput.value = result.data.restaurant_table_session_id;
                    const openCheck = document.getElementById('pos-session-open-check');
                    if (openCheck) openCheck.textContent = money(totals().total);
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
            closeTableWorkspace();
            return;
        }

        var html = '<p class="text-muted mb-3">This table already has an open check. Continue it to add the next kitchen round.</p>';
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
            confirmButtonText: 'Continue Latest Order',
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
            }
        });
    }

    /* ── Cancel order button ──────────────────────────────────────────── */

    function requestOrderCancellationDetails(saleId, callback) {
        if (!voidReasons.length) {
            toast('error', 'Configure an active void reason before cancelling held orders.');
            return;
        }
        const options = {};
        voidReasons.forEach(function (reason) { options[reason.id] = reason.name; });
        Swal.fire({
            title: 'Cancel Held Order',
            text: 'Select the reason. Sent quantities will produce a Cancel KOT.',
            input: 'select',
            inputOptions: options,
            inputPlaceholder: 'Select reason',
            showCancelButton: true,
            confirmButtonText: 'Continue',
            confirmButtonColor: '#dc3545',
            inputValidator: function (value) { return value ? undefined : 'Select a cancellation reason'; },
        }).then(function (result) {
            if (!result.isConfirmed) return;
            const reasonId = result.value;
            if (currentCancellationMode() === 'auto_approve') {
                callback({ reason_id: reasonId, manager_approval_id: null });
                return;
            }
            showManagerPinModal('cancel_held_order', { sales_order_id: saleId }, function (approvalId) {
                callback({ reason_id: reasonId, manager_approval_id: approvalId });
            });
        });
    }

    function submitHeldOrderCancellation(saleId, details) {
        return fetch('{{ url('/held-sales') }}/' + saleId + '/cancel', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify(details),
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) throw new Error(data.message || Object.values(data.errors || {})[0] || 'Cancellation failed');
                (data.cancel_kot_jobs || []).forEach(function (job) {
                    if (job.fallback && job.preview_url) openPreviewTab(job.preview_url);
                });
                return data;
            });
        });
    }

    document.getElementById('cancel-order-btn').addEventListener('click', function () {
        if (!cart.length && !_currentHeldSaleId) {
            toast('warning', 'Nothing to cancel');
            return;
        }
        if (_currentHeldSaleId && typeof Swal !== 'undefined') {
            requestOrderCancellationDetails(_currentHeldSaleId, function (details) {
                submitHeldOrderCancellation(_currentHeldSaleId, details).then(function () {
                    clearCart();
                    toast('success', 'Order cancelled and kitchen notified');
                }).catch(function (error) {
                    toast('error', error.message || 'Failed to cancel. Try again.');
                });
            });
        } else if (_currentHeldSaleId) {
            alert('Cancellation controls are unavailable. Reload the POS and try again.');
        } else if (typeof Swal !== 'undefined') {
            Swal.fire({
                title:              'Clear Cart?',
                text:               'Clear the current unsaved cart?',
                icon:               'warning',
                showCancelButton:   true,
                confirmButtonText:  'Yes, Cancel',
                cancelButtonText:   'No',
                confirmButtonColor: '#dc3545',
                reverseButtons:     true,
            }).then(function (res) {
                if (!res.isConfirmed) return;
                clearCart();
                toast('info', 'Cart cleared');
            });
        } else {
            if (!confirm('Clear the current unsaved cart?')) return;
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
                    '<td><strong>' + escapeHtml(s.sale_no) + '</strong></td>' +
                    '<td><span class="badge bg-secondary text-capitalize">' + escapeHtml(String(s.order_type || '').replace('_', ' ')) + '</span></td>' +
                    '<td>' + escapeHtml(s.customer || 'Walk-in') + '</td>' +
                    '<td class="text-end">' + escapeHtml(s.items) + '</td>' +
                    '<td class="text-end fw-bold">' + escapeHtml(s.total) + '</td>' +
                    '<td class="text-muted small">' + escapeHtml(s.time) + '</td>' +
                    '<td class="text-end">' +
                        '<button class="btn btn-sm btn-primary me-1" data-recall-id="' + Number(s.id) + '">Recall</button>' +
                        '<button class="btn btn-sm btn-outline-danger" data-cancel-id="' + Number(s.id) + '" data-cancel-no="' + escapeHtml(s.sale_no) + '">Cancel</button>' +
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
            const key = (lineKind === 'combo_header' || lineKind === 'component')
                ? 'held-line:' + line.id
                : cartKey(product, variant, modifiers);
            const parentKey = line.parent_sales_order_line_id ? recalledLineKeys[line.parent_sales_order_line_id] : '';
            // Honour the STORED price exactly — combo components are 0, and a falsy-OR
            // would wrongly re-price them to full catalog price (inflating the total).
            const unitPrice = (line.unit_price !== undefined && line.unit_price !== null)
                ? Number(line.unit_price)
                : productPrice(product, variant);
            // The combo header carries the bundle price and must never be taxed.
            const lineProduct = (lineKind === 'combo_header')
                ? Object.assign({}, product, { is_taxable: false, tax_rate_percent: 0 })
                : product;
            // Per-unit component qty so changing the combo qty after recall rescales components.
            const parentHeader = parentKey ? cart.find(function (i) { return i.key === parentKey; }) : null;
            const comboCompQty = (lineKind === 'component' && parentHeader && Number(parentHeader.quantity) > 0)
                ? Number(line.quantity) / Number(parentHeader.quantity)
                : undefined;
            cart.push({
                key:                key,
                client_line_key:    key,
                parent_key:         parentKey,
                parent_client_line_key: parentKey,
                line_kind:          lineKind,
                combo_id:           line.combo_id || '',
                combo_component_qty: comboCompQty,
                product_id:         product.id,
                product_variant_id: variant ? variant.id : null,
                name:               line.product_name || product.name,
                variant_name:       line.variant_name || (variant ? variant.name : null),
                unit_code:          line.unit_code || product.unit_code || '',
                quantity:           Number(line.quantity || 1),
                unit_price:         unitPrice,
                base_unit_price:    Math.max(unitPrice - modifierPriceDelta(modifiers), 0),
                modifiers:          modifiers,
                discount_amount:    Number(line.discount_amount || 0),
                tax_amount:         Number(line.tax_amount || 0),
                product:            lineProduct,
                variant:            variant || null,
                _dbLineId:          line.id || null,
                kot_sent:           !!line.kot_sent,
                kot_sent_quantity:  Number(line.kot_sent_quantity || 0),
            });
            recalledLineKeys[line.id] = key;
        });

        _currentHeldSaleId = sale.id;
        _currentHeldSaleNo = sale.sale_no;
        rememberLastSale(sale.id, sale.sale_no);
        const heldInput = document.querySelector('input[name="held_sale_id"]');
        if (heldInput) heldInput.value = sale.id;

        // Sync order type from the recalled sale
        if (sale.order_type && orderTypeEl) {
            orderTypeEl.value = sale.order_type;
            document.querySelectorAll('[data-mode-tab]').forEach(function (b) {
                b.classList.toggle('active', b.dataset.modeTab === sale.order_type);
            });
            const sessionBar = document.getElementById('pos-session-bar');
            if (sessionBar) sessionBar.style.display = sale.order_type === 'dine_in' ? '' : 'none';
        }
        updateDeliveryPanel();
        if (sale.delivery_channel_id && deliveryChannelEl) {
            deliveryChannelEl.value = String(sale.delivery_channel_id);
            updateDeliveryPanel();
            if (sale.delivery_rider_id && deliveryRiderEl) {
                deliveryRiderEl.value = String(sale.delivery_rider_id);
                updateDeliveryPanel();
            }
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
        requestOrderCancellationDetails(saleId, function (details) {
            btn.disabled = true;
            submitHeldOrderCancellation(saleId, details).then(function () {
                const row = btn.closest('tr');
                if (row) row.remove();
                if (Number(_currentHeldSaleId) === Number(saleId)) clearCart();
                toast('success', saleNo + ' cancelled and kitchen notified');
            }).catch(function (error) {
                btn.disabled = false;
                toast('error', error.message || 'Cancellation failed');
            });
        });
    }

    document.getElementById('held-orders-btn').addEventListener('click', function () {
        new bootstrap.Modal(heldSalesModalEl).show();
    });

    // #8: an always-available escape to a brand-new sale, even mid-table, so the cashier
    // is never stuck. Any open table check stays HELD on its table and can be recalled.
    document.getElementById('new-sale-btn')?.addEventListener('click', function () {
        const hasContext = (cart && cart.length > 0)
            || !!(document.getElementById('restaurant_table_session_id')?.value)
            || _currentHeldSaleId;
        if (hasContext && !confirm('Start a completely new sale?\n\nAny open table check stays on its table and can be recalled later. Unsaved cart items will be discarded.')) {
            return;
        }
        window.location.href = '{{ url('/pos') }}';
    });

    document.getElementById('start-fresh-btn').addEventListener('click', function () {
        const preserveTable = !!(document.getElementById('restaurant_table_session_id')?.value);
        if (preserveTable) {
            searchEl.focus();
            toast('info', 'Add the next round; only new quantities will print on the next KOT.');
            return;
        }
        Swal.fire({
            title: 'Start Fresh Order?',
            html: 'This unloads the recalled order. The held sale remains saved.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'New Order',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
        }).then(function (r) {
            if (!r.isConfirmed) return;
            clearCart();
            setHidden('create_separate_order', '0');
        });
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
                    const waiter  = s.waiter ? ' (' + escapeHtml(s.waiter) + ')' : '';
                    const status  = s.has_session ? '' : ' ✦ New';
                    const sel     = s.table_id == currentTableId ? ' selected' : '';
                    return '<option value="' + escapeHtml(s.table_id) + '"' +
                           ' data-session-id="' + escapeHtml(s.session_id || '') + '"' +
                           sel + '>' + escapeHtml(s.label) + waiter + status + '</option>';
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

        if (orderTypeEl && newType !== orderTypeEl.value) {
            bootstrap.Modal.getInstance(changeOrderModalEl).hide();
            const targetTab = document.querySelector('[data-mode-tab="' + newType + '"]');
            if (targetTab) applyModeTab(targetTab);
            return;
        }

        // Apply order type
        if (orderTypeEl) orderTypeEl.value = newType;

        // Sync mode tabs visual state
        document.querySelectorAll('[data-mode-tab]').forEach(function (b) {
            b.classList.toggle('active', b.dataset.modeTab === newType);
        });

        // Show/hide dine-in board
        const sessionBar = document.getElementById('pos-session-bar');
        if (sessionBar) sessionBar.style.display = newType === 'dine_in' ? '' : 'none';

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
                return '<span class="badge bg-' + (map[s] || 'secondary') + '">' + escapeHtml(s) + '</span>';
            };
            const typeBadge = function (t) {
                if (t === 'kot') return '<span class="badge bg-warning text-dark"><i class="ti ti-tool-kitchen-2 me-1"></i>KOT</span>';
                if (t === 'reminder') return '<span class="badge bg-info text-dark"><i class="ti ti-bell me-1"></i>Reminder</span>';
                return '<span class="badge bg-primary"><i class="ti ti-receipt me-1"></i>Receipt</span>';
            };

            let html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">' +
                '<thead class="table-light"><tr>' +
                '<th class="ps-3">Job No</th><th>Type</th><th>Status</th><th>Printer</th>' +
                '<th>Items</th><th>Time</th><th class="pe-3 text-end">Action</th>' +
                '</tr></thead><tbody>';

            let hasFailed = false;

            data.jobs.forEach(function (j) {
                if (j.print_status === 'failed') hasFailed = true;

                let itemsCell = ['kot', 'reminder'].includes(j.document_type)
                    ? (j.line_count > 0 ? j.line_count + ' item' + (j.line_count !== 1 ? 's' : '') : 'All items')
                    : '—';
                if (j.document_type === 'reminder') {
                    itemsCell += ' · Rev ' + Number(j.revision || 1);
                    if (Number(j.copy_no || 0) > 0) itemsCell += ' · Duplicate ' + Number(j.copy_no);
                }

                const viewBtn = j.fallback
                    ? '<a href="' + escapeHtml(j.preview_url) + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info py-0 me-1"><i class="ti ti-eye me-1"></i>View</a>'
                    : '';

                const retryBtn = j.print_status === 'failed'
                    ? '<button class="btn btn-sm btn-danger py-0 me-1" data-retry-job="' + Number(j.id) + '"><i class="ti ti-refresh me-1"></i>Retry</button>'
                    : '';

                html += '<tr' + (j.print_status === 'failed' ? ' class="table-danger"' : '') + '>' +
                    '<td class="ps-3 fw-semibold small">' + escapeHtml(j.job_no) + '</td>' +
                    '<td>' + typeBadge(j.document_type) + '</td>' +
                    '<td>' + statusBadge(j.print_status) + '</td>' +
                    '<td class="text-muted small">' + escapeHtml(j.printer_name) + '</td>' +
                    '<td class="text-muted small">' + escapeHtml(itemsCell) + '</td>' +
                    '<td class="text-muted small">' + escapeHtml(j.created_at) + '</td>' +
                    '<td class="pe-3 text-end">' +
                        viewBtn +
                        retryBtn +
                        '<button class="btn btn-sm btn-outline-secondary py-0" data-requeue-job="' + Number(j.id) + '" data-job-type="' + escapeHtml(j.document_type) + '" title="Reprint ' + escapeHtml(j.printer_name) + (j.document_type === 'reminder' ? ', revision ' + Number(j.revision || 1) : '') + '">' +
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
    // Open print-preview tab(s) even when the browser pop-up blocker fires. Auto-print
    // runs from the async "sale complete" response, so window.open isn't tied to the
    // click → Chrome may block it silently. We collect any blocked previews and show one
    // "Open prints" prompt (that click IS a user gesture, so the browser allows it).
    var _blockedPreviews = [];
    var _blockedPreviewTimer = null;
    function openPreviewTab(url) {
        if (!url) return;
        var w = window.open(url, '_blank');
        if (!w || w.closed || typeof w.closed === 'undefined') {
            _blockedPreviews.push(url);
            clearTimeout(_blockedPreviewTimer);
            _blockedPreviewTimer = setTimeout(flushBlockedPreviews, 200);
        }
    }
    function flushBlockedPreviews() {
        if (!_blockedPreviews.length) return;
        var urls = _blockedPreviews.slice();
        _blockedPreviews = [];
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'info',
                title: 'Allow pop-ups to print',
                html: 'Your browser blocked the print tab' + (urls.length > 1 ? 's' : '') + '. Click to open '
                    + urls.length + ' print preview' + (urls.length > 1 ? 's' : '')
                    + ', or allow pop-ups for this site so prints open automatically.',
                confirmButtonText: 'Open print' + (urls.length > 1 ? 's' : ''),
                confirmButtonColor: '#0d6efd',
            }).then(function (r) {
                if (r.isConfirmed) { urls.forEach(function (u) { window.open(u, '_blank'); }); }
            });
        } else {
            toast('warning', 'Pop-up blocked — allow pop-ups for this site to print.');
        }
    }

    function openFallbackPreviews(data) {
        (data.jobs || []).forEach(function (job) {
            if (job.fallback || job.printer_type === 'browser') {
                toast('warning', 'No printer found — opening for manual print');
                openPreviewTab(job.preview_url);
            }
        });
        if ((data.fallback || data.printer_type === 'browser') && data.preview_url) {
            toast('warning', 'No printer found — opening for manual print');
            openPreviewTab(data.preview_url);
        }
    }

    function requeueSingleJob(jobId, jobType, btn) {
        const terminalId = (document.getElementById('terminal_id') || {}).value || '';
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        if (jobType === 'reminder') {
            fetch('{{ url('/printing/jobs') }}/' + jobId + '/reminder-reprint', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            }).then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) throw new Error(data.message || 'Reminder could not be reprinted.');
                    return data;
                });
            }).then(function (data) {
                btn.disabled = false; btn.innerHTML = orig;
                toast('success', 'Reminder Duplicate ' + data.copy_no + ' queued');
                loadRecentPrintJobs();
            }).catch(function (error) { btn.disabled = false; btn.innerHTML = orig; toast('error', error.message || 'Failed'); });
        } else if (jobType === 'kot') {
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
            const q = '?reprint=1' + (terminalId ? '&terminal_id=' + encodeURIComponent(terminalId) : '');
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
                var printStatus = '';
                if (s.printing && s.printing.resume_available) {
                    printStatus = '<div class="small text-warning mt-1"><i class="ti ti-alert-circle me-1"></i>Printing needs attention</div>';
                }
                return '<tr>' +
                    '<td><strong>' + escapeHtml(s.sale_no) + '</strong><div class="text-muted small">' + escapeHtml(s.time || s.ago || '') + '</div>' + printStatus + '</td>' +
                    '<td>' + escapeHtml(s.customer || 'Walk-in') + '<div class="text-muted small text-capitalize">' + escapeHtml(String(s.order_type || '').replace(/_/g, ' ')) + '</div></td>' +
                    '<td class="text-end fw-semibold">' + escapeHtml(s.total) + '</td>' +
                    '<td class="text-end text-nowrap">' +
                        '<button type="button" class="btn btn-sm btn-outline-primary me-1" data-reprint-receipt="' + Number(s.id) + '"><i class="ti ti-printer me-1"></i>Receipt</button>' +
                        '<button type="button" class="btn btn-sm btn-outline-warning me-1" data-reprint-kot="' + Number(s.id) + '"><i class="ti ti-tool-kitchen-2 me-1"></i>KOT</button>' +
                        (s.printing && s.printing.resume_available
                            ? '<button type="button" class="btn btn-sm btn-warning me-1" data-resume-printing="' + Number(s.id) + '"><i class="ti ti-refresh me-1"></i>Resume</button>'
                            : '') +
                        '<a href="{{ url('/sales-orders') }}/' + Number(s.id) + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="View"><i class="ti ti-eye"></i></a>' +
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
            body.querySelectorAll('[data-resume-printing]').forEach(function (b) {
                b.addEventListener('click', function () { resumeDirectPayPrinting(Number(b.dataset.resumePrinting), b); });
            });
        })
        .catch(function () { body.innerHTML = '<div class="alert alert-danger m-3 mb-0">Failed to load completed orders.</div>'; });
    }

    function resumeDirectPayPrinting(saleId, btn) {
        var orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        fetch('{{ url('/pos') }}/' + saleId + '/printing/retry', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) throw new Error(data.message || 'Printing could not be resumed.');
                return data.printing || {};
            });
        }).then(function (printing) {
            return processDirectPayPrinting(saleId, printing);
        }).then(function () {
            toast('success', 'Printing state re-checked');
            loadRecentSales();
        }).catch(function (error) {
            btn.disabled = false;
            btn.innerHTML = orig;
            toast('error', error.message || 'Printing could not be resumed.');
        });
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
            return '<tr><td>' + escapeHtml(it.name || it.product_name || 'Item') + '</td><td style="text-align:right">' + qty + '</td><td style="text-align:right">' + money(price) + '</td><td style="text-align:right">' + money(lt) + '</td></tr>';
        }).join('');
        // Use the branch's RECEIPT layout so this matches the receipt / table-bill look.
        const lay = (receiptLayouts && receiptLayouts[selectedBranchId()]) || {};
        const paper = lay.paper_size || '80mm';
        const printW = paper === '58mm' ? '52mm' : (paper === '80mm' ? '72mm' : '180mm');
        const headerName = (lay.show_branch_name === false) ? '' : branchName;
        const html = '<html><head><title>Bill Preview</title><style>'
            + 'body{font-family:\'Courier New\',Courier,monospace;width:' + printW + ';margin:0 auto;padding:8px;font-size:13px;color:#000}'
            + '@media screen{body{width:320px}}'
            + 'h3{text-align:center;margin:4px 0}table{width:100%;border-collapse:collapse}td,th{padding:2px 0}'
            + '.tot{border-top:1px dashed #000;margin-top:6px;padding-top:6px}.muted{text-align:center;color:#666;font-size:11px}</style></head><body>'
            + (headerName ? '<h3>' + escapeHtml(headerName) + '</h3>' : '')
            + (lay.header_text ? '<div style="text-align:center">' + escapeHtml(lay.header_text) + '</div>' : '')
            + '<div class="muted">BILL PREVIEW — NOT A TAX RECEIPT</div>'
            + '<div class="muted">' + new Date().toLocaleString() + '</div><hr>'
            + '<table><thead><tr><th style="text-align:left">Item</th><th style="text-align:right">Qty</th><th style="text-align:right">Price</th><th style="text-align:right">Amt</th></tr></thead><tbody>'
            + rowsHtml + '</tbody></table>'
            + '<div class="tot"><table>'
            + '<tr><td>Subtotal</td><td style="text-align:right">' + money(t.subtotal) + '</td></tr>'
            + ((t.discount > 0) ? '<tr><td>Discount</td><td style="text-align:right">' + money(t.discount) + '</td></tr>' : '')
            + ((t.tax > 0) ? '<tr><td>Tax</td><td style="text-align:right">' + money(t.tax) + '</td></tr>' : '')
            + ((t.serviceCharge > 0) ? '<tr><td>Service Charge</td><td style="text-align:right">' + money(t.serviceCharge) + '</td></tr>' : '')
            + ((t.tip > 0) ? '<tr><td>Tip</td><td style="text-align:right">' + money(t.tip) + '</td></tr>' : '')
            + '<tr><td><strong>Total</strong></td><td style="text-align:right"><strong>' + money(t.total) + '</strong></td></tr>'
            + '</table></div>'
            + (lay.footer_text ? '<hr><div style="text-align:center">' + escapeHtml(lay.footer_text) + '</div>' : '')
            + '</body></html>';
        const body = document.getElementById('bill-preview-modal-body');
        body.innerHTML = '<iframe id="bill-preview-frame" title="Current cart bill preview" class="w-100 border-0" style="min-height:560px"></iframe>';
        body.querySelector('iframe').srcdoc = html;
        document.getElementById('billPreviewModalLabel').textContent = 'Current Cart Preview';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('billPreviewModal')).show();
    }

    function showTableBillPreview(sessionId) {
        fetch('{{ url('/restaurant/table-sessions') }}/' + sessionId + '/bill-preview', { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json().then(function (data) { if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to load table bill.'); return data; }); })
            .then(function (data) {
                document.getElementById('billPreviewModalLabel').textContent = 'Table Bill Preview';
                document.getElementById('bill-preview-modal-body').innerHTML = data.html;
                showModalAfterWorkspace(document.getElementById('billPreviewModal'));
            }).catch(function (error) { toast('error', error.message); });
    }

    // #4/#12: "Send to network" prints this order's receipt on the network printer via the
    // Print Agent (reuses the hardened receipt queue). It needs a SAVED order (a recalled
    // held sale or a just-paid sale); on the unsaved current cart, ask to hold/pay first.
    // If no network printer is mapped, the endpoint returns fallback -> browser preview.
    document.getElementById('send-network-receipt-btn')?.addEventListener('click', function () {
        const saleId = _currentHeldSaleId || _lastSaleId;
        if (!saleId) {
            toast('warning', 'Hold or pay this order first — sending to a network printer needs a saved order.');
            return;
        }
        const terminalId = (document.getElementById('terminal_id') || {}).value || '';
        const q = '?reprint=1' + (terminalId ? '&terminal_id=' + encodeURIComponent(terminalId) : '');
        const btn = this, orig = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        fetch('{{ url('/printing/jobs/receipt') }}/' + saleId + q, {
            method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            btn.disabled = false; btn.innerHTML = orig;
            if (data.fallback) {
                toast('warning', 'No network receipt printer is mapped — opening a browser preview instead.');
                openFallbackPreviews(data);
            } else {
                toast('success', 'Receipt sent to the network printer.');
            }
        })
        .catch(function () { btn.disabled = false; btn.innerHTML = orig; toast('error', 'Could not send to the network printer.'); });
    });

    document.getElementById('pos-session-bill-preview')?.addEventListener('click', function () {
        if (this.dataset.sessionId) showTableBillPreview(this.dataset.sessionId);
    });

    document.getElementById('print-bill-preview-btn')?.addEventListener('click', function () {
        var frame = document.getElementById('bill-preview-frame');
        if (frame) { frame.contentWindow.focus(); frame.contentWindow.print(); return; }
        var printable = document.getElementById('bill-preview-modal-body').innerHTML;
        var printFrame = document.createElement('iframe');
        printFrame.hidden = true;
        document.body.appendChild(printFrame);
        printFrame.contentWindow.document.write('<html><head><title>Table Bill Preview</title><style>body{font-family:Arial,sans-serif;padding:16px}table{width:100%;border-collapse:collapse}th,td{padding:6px;border-bottom:1px solid #ddd}.text-end{text-align:right}.d-flex{display:flex}.justify-content-between{justify-content:space-between}</style></head><body>' + printable + '</body></html>');
        printFrame.contentWindow.document.close();
        setTimeout(function () { printFrame.contentWindow.focus(); printFrame.contentWindow.print(); setTimeout(function () { printFrame.remove(); }, 1000); }, 250);
    });
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
        const q = '?reprint=1' + (terminalId ? '&terminal_id=' + encodeURIComponent(terminalId) : '');
        fetch('{{ url('/printing/jobs/receipt') }}/' + _lastSaleId + q, { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            openFallbackPreviews(data);
            toast('success', 'Receipt re-queued for ' + _lastSaleNo);
            loadRecentPrintJobs();
        })
        .catch(function () { toast('error', 'Failed to reprint receipt'); });
    });

    /* Delegated Table Workspace actions remain live after board refreshes. */

    var tableWorkspaceEl = document.getElementById('tableWorkspaceModal');
    var tableBoardEl = document.getElementById('table-board-body');
    var tableWorkspaceBack = document.getElementById('table-workspace-back');

    function showTableWorkspaceView(viewId) {
        document.querySelectorAll('.table-workspace-view').forEach(function (view) { view.hidden = view.id !== viewId; });
        if (tableWorkspaceBack) tableWorkspaceBack.classList.toggle('d-none', viewId === 'table-workspace-board');
    }

    function openTableWorkspace() {
        showTableWorkspaceView('table-workspace-board');
        refreshTableBoard(document.getElementById('restaurant_table_session_id')?.value || '');
        bootstrap.Modal.getOrCreateInstance(tableWorkspaceEl).show();
    }

    function closeTableWorkspace() {
        var modal = bootstrap.Modal.getInstance(tableWorkspaceEl);
        if (modal) modal.hide();
    }

    function showModalAfterWorkspace(modalElement) {
        var workspace = bootstrap.Modal.getInstance(tableWorkspaceEl);
        if (workspace && tableWorkspaceEl.classList.contains('show')) {
            tableWorkspaceEl.addEventListener('hidden.bs.modal', function () {
                bootstrap.Modal.getOrCreateInstance(modalElement).show();
            }, { once: true });
            workspace.hide();
            return;
        }
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }

    document.getElementById('view-tables-btn')?.addEventListener('click', openTableWorkspace);
    tableWorkspaceBack?.addEventListener('click', function () { showTableWorkspaceView('table-workspace-board'); });
    document.querySelectorAll('[data-table-workspace-home]').forEach(function (button) {
        button.addEventListener('click', function () { showTableWorkspaceView('table-workspace-board'); });
    });
    document.querySelectorAll('[data-management-url]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('table-management-frame').src = this.dataset.managementUrl;
            showTableWorkspaceView('table-workspace-manage');
        });
    });
    document.getElementById('pos-session-request-bill-form')?.addEventListener('submit', function (event) {
        event.preventDefault();
        var requestForm = this;
        fetch(requestForm.action, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: new FormData(requestForm) })
            .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || 'Unable to request bill.'); return data; }); })
            .then(function (data) { requestForm.style.display = 'none'; refreshTableBoard(document.getElementById('restaurant_table_session_id')?.value || ''); toast('success', data.message); })
            .catch(function (error) { toast('error', error.message); });
    });

    function loadTableOrders(sessionId, callback) {
        fetch('{{ url('/api/pos/table-sessions') }}/' + sessionId + '/open-orders', { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json().then(function (data) { if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to load table orders.'); return data; }); })
            .then(callback).catch(function (error) { toast('error', error.message); });
    }

    function showTableHeldOrders(sessionId) {
        showTableWorkspaceView('table-workspace-held');
        var body = document.getElementById('table-workspace-held-body');
        body.innerHTML = '<div class="text-center py-5"><span class="spinner-border"></span></div>';
        loadTableOrders(sessionId, function (data) {
            if (!data.orders.length) { body.innerHTML = '<div class="alert alert-light border">This table has no held orders.</div>'; return; }
            body.innerHTML = '<h3 class="h5 mb-3">Held Orders - Table ' + escapeHtml(data.session.table_no || '') + '</h3><div class="table-action-list">' + data.orders.map(function (order, index) {
                return '<article class="border rounded p-3"><div class="d-flex justify-content-between gap-2 mb-2"><strong>' + escapeHtml(order.sale_no) + '</strong><strong>' + escapeHtml(order.grand_total_formatted) + '</strong></div><div class="text-muted small mb-3">' + escapeHtml(order.items_count) + ' items &middot; ' + escapeHtml(order.updated_at || '') + '</div><button type="button" class="btn btn-primary w-100" data-workspace-recall="' + index + '">Recall / Continue</button></article>';
            }).join('') + '</div>';
            body.querySelectorAll('[data-workspace-recall]').forEach(function (button) {
                button.addEventListener('click', function () { continueExistingOrder(data.orders[Number(button.dataset.workspaceRecall)], data.session); });
            });
        });
    }

    function postTableOperation(url, fields, callback) {
        var data = new FormData();
        Object.keys(fields).forEach(function (key) { data.append(key, fields[key]); });
        fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: data })
            .then(function (response) { return response.json().then(function (payload) { if (!response.ok) throw new Error(payload.message || 'Operation failed.'); return payload; }); })
            .then(function (payload) { toast('success', payload.message || 'Updated'); callback(payload); })
            .catch(function (error) { toast('error', error.message); });
    }

    function showTableMove(sessionId, sourceTableId) {
        showTableWorkspaceView('table-workspace-move');
        var body = document.getElementById('table-workspace-move-body');
        var targets = Array.from(tableBoardEl.querySelectorAll('[data-open-table="1"]')).filter(function (button) { return String(button.dataset.tableId) !== String(sourceTableId); });
        body.innerHTML = '<h3 class="h5 mb-3">Move Table</h3>' + (targets.length ? '<div class="table-action-list">' + targets.map(function (button) { return '<button type="button" class="btn btn-outline-primary p-3" data-move-target="' + escapeHtml(button.dataset.tableId) + '">' + escapeHtml(button.dataset.tableNo) + '</button>'; }).join('') + '</div>' : '<div class="alert alert-light border">No eligible destination table is currently available.</div>');
        body.querySelectorAll('[data-move-target]').forEach(function (button) {
            button.addEventListener('click', function () { postTableOperation('{{ url('/restaurant/table-sessions') }}/' + sessionId + '/move', { target_table_id: button.dataset.moveTarget }, function (data) { applyTableSession(data.session); refreshTableBoard(data.session.id); showTableWorkspaceView('table-workspace-board'); }); });
        });
    }

    function openSplitForSession(sessionId) {
        loadTableOrders(sessionId, function (data) {
            if (!data.orders.length) { toast('warning', 'No held order is available to split.'); return; }
            if (data.orders.length === 1) { openSplitSale(data.orders[0]); return; }
            showTableWorkspaceView('table-workspace-split');
            var body = document.getElementById('table-workspace-split-body');
            body.innerHTML = '<h3 class="h5 mb-3">Select the exact held order to split</h3><div class="table-action-list">' + data.orders.map(function (order, index) { return '<button type="button" class="btn btn-outline-primary p-3 text-start" data-split-order="' + index + '"><strong>' + escapeHtml(order.sale_no) + '</strong><br><span class="text-muted">' + escapeHtml(order.items_count) + ' items &middot; ' + escapeHtml(order.grand_total_formatted) + '</span></button>'; }).join('') + '</div>';
            body.querySelectorAll('[data-split-order]').forEach(function (button) { button.addEventListener('click', function () { openSplitSale(data.orders[Number(button.dataset.splitOrder)]); }); });
        });
    }

    function openSplitSale(order) {
        document.getElementById('split-bill-modal-body').innerHTML = '<iframe title="Split bill" class="w-100 border-0" style="min-height:720px" src="{{ url('/sales-orders') }}/' + Number(order.id) + '/split-bill"></iframe>';
        showModalAfterWorkspace(document.getElementById('splitBillModal'));
    }

    document.getElementById('split-bill-link')?.addEventListener('click', function () {
        if (this.dataset.saleId) openSplitSale({ id: Number(this.dataset.saleId) });
    });

    if (tableBoardEl) {
        tableBoardEl.addEventListener('click', function (event) {
            // Continue / select an active table — no page reload.
            var sel = event.target.closest('[data-table-session-select="1"]');
            if (sel && tableBoardEl.contains(sel)) {
                event.preventDefault();
                continueTableSession(sel.dataset.sessionId, sel.dataset.branchId, sel.dataset.fallbackHref);
                return;
            }
            var open = event.target.closest('[data-open-table="1"]');
            if (open) { document.getElementById('open-table-no').textContent = open.dataset.tableNo; document.getElementById('open-table-form').action = '{{ url('/restaurant/tables') }}/' + open.dataset.tableId + '/open'; selectWaiterChoice(''); showTableWorkspaceView('table-workspace-open'); return; }
            var held = event.target.closest('[data-table-held-orders]');
            if (held) { showTableHeldOrders(held.dataset.tableHeldOrders); return; }
            var preview = event.target.closest('[data-table-bill-preview]');
            if (preview) { showTableBillPreview(preview.dataset.tableBillPreview); return; }
            var split = event.target.closest('[data-table-split]');
            if (split) { openSplitForSession(split.dataset.tableSplit); return; }
            var move = event.target.closest('[data-table-move]');
            if (move) { showTableMove(move.dataset.tableMove, move.dataset.sourceTableId); return; }
            // Floor filter tabs.
            var tab = event.target.closest('[data-floor-tab]');
            if (tab && tableBoardEl.contains(tab)) {
                tableBoardEl.querySelectorAll('[data-floor-tab]').forEach(function (b) { b.classList.remove('active'); });
                tab.classList.add('active');
                var floorId = tab.dataset.floorTab;
                tableBoardEl.querySelectorAll('[data-floor-panel]').forEach(function (panel) {
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
            _directPayKotIntent = null;
            _directPayReceiptIntent = null;
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

    function applyModeTab(button, confirmed) {
        var mode     = button.dataset.modeTab;
        var branchId = branchEl ? branchEl.value : '{{ $selectedBranchId }}';
        var isDineIn = mode === 'dine_in';
        var currentMode = orderTypeEl ? orderTypeEl.value : '';

        if (mode === currentMode) return;

        var hasOrderState = cart.length > 0 || !!_currentHeldSaleId
            || !!document.getElementById('restaurant_table_session_id')?.value;
        if (hasOrderState && !confirmed) {
            Swal.fire({
                icon: 'warning',
                title: 'Start a fresh ' + button.textContent.trim() + ' order?',
                text: 'The current cart, recalled order, table, customer and payment details will be cleared.',
                showCancelButton: true,
                confirmButtonText: 'Start Fresh',
                confirmButtonColor: '#d4a72c',
            }).then(function (result) {
                if (result.isConfirmed) applyModeTab(button, true);
            });
            return;
        }

        clearCart();
        clearTableStateInputs();

        if (customerEl) customerEl.value = '';
        var customerName = document.getElementById('customer_name');
        var customerPhone = document.getElementById('customer_phone');
        if (customerName) customerName.value = '';
        if (customerPhone) customerPhone.value = '';
        if (deliveryChannelEl) deliveryChannelEl.value = '';
        if (deliveryRiderEl) deliveryRiderEl.value = '';
        if (deliveryAddressEl) deliveryAddressEl.value = '';
        if (transactionRefEl) transactionRefEl.value = '';
        if (tenderedEl) tenderedEl.value = '0.00';

        // Switching mode de-selects any active table → hide its bar + reset the pay button.
        var sessionBar = document.getElementById('pos-session-bar');
        if (sessionBar) {
            sessionBar.style.display = isDineIn ? '' : 'none';
            sessionBar.classList.toggle('d-none', !isDineIn);
        }
        document.getElementById('pos-session-details')?.classList.add('d-none');
        document.getElementById('pos-session-actions')?.classList.add('d-none');
        setCompleteSaleLabel(false);
        updateRecalledBar();
        updateStartFreshLabel();

        // Hidden order_type drives checkout + the totals/service-charge quote.
        if (orderTypeEl) orderTypeEl.value = mode;
        updateDeliveryPanel();

        // Active tab highlight.
        document.querySelectorAll('[data-mode-tab]').forEach(function (b) { b.classList.remove('active'); });
        button.classList.add('active');

        // Table board is only relevant for dine-in.
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

    /* Floor tabs are handled by Table Workspace delegation above. */

    /* open table modal — populate table info */

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

    /* open table modal — AJAX submit (stay on POS, land on session) */

    document.getElementById('open-table-form').addEventListener('submit', function (event) {
        event.preventDefault();
        const form    = this;
        const openBtn = document.getElementById('open-table-submit');
        const errEl   = document.getElementById('open-table-error');
        if (errEl) errEl.remove();

        // SHIFT-POS-INTEGRATION-CLOSURE-1: bind the table to the POS-selected terminal's shift.
        const termHidden = document.getElementById('open-table-terminal-id');
        const termSel    = document.getElementById('terminal_id');
        if (termHidden) termHidden.value = termSel ? (termSel.value || '') : '';
        if (termHidden && !termHidden.value) {
            openBtn.insertAdjacentHTML('beforebegin', '<div id="open-table-error" class="alert alert-warning w-100 mb-2">Select a terminal (with an open shift) before opening a table.</div>');
            return;
        }

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
                closeTableWorkspace();
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
                form.appendChild(div);
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
            const key = (lineKind === 'combo_header' || lineKind === 'component')
                ? 'held-line:' + line.id
                : cartKey(product, variant, modifiers);
            const parentKey = line.parent_sales_order_line_id ? preloadedLineKeys[line.parent_sales_order_line_id] : '';
            // Honour the STORED price exactly (combo components are 0; a falsy-OR re-prices them).
            const unitPrice = (line.unit_price !== undefined && line.unit_price !== null)
                ? Number(line.unit_price)
                : productPrice(product, variant);
            // Combo header carries the bundle price + the combo name, and is never taxed.
            const lineProduct = (lineKind === 'combo_header')
                ? Object.assign({}, product, { is_taxable: false, tax_rate_percent: 0 })
                : product;
            const displayName = line.product_name || product.name;
            const parentHeader = parentKey ? cart.find(function (i) { return i.key === parentKey; }) : null;
            const comboCompQty = (lineKind === 'component' && parentHeader && Number(parentHeader.quantity) > 0)
                ? Number(line.quantity) / Number(parentHeader.quantity)
                : undefined;

            cart.push({
                key:                key,
                client_line_key:    key,
                parent_key:         parentKey,
                parent_client_line_key: parentKey,
                line_kind:          lineKind,
                combo_id:           line.combo_id || '',
                combo_component_qty: comboCompQty,
                product_id:         product.id,
                product_variant_id: variant ? variant.id : null,
                name:               displayName,
                product_name:       displayName,
                variant_name:       variant ? variant.name : null,
                unit_code:          line.unit_code || product.unit_code || '',
                quantity:           Number(line.quantity || 1),
                unit_price:         unitPrice,
                base_unit_price:    Math.max(unitPrice - modifierPriceDelta(modifiers), 0),
                modifiers:          modifiers,
                discount_amount:    Number(line.discount_amount || 0),
                tax_amount:         Number(line.tax_amount || 0),
                product:            lineProduct,
                variant:            variant || null,
                _dbLineId:          line.id || null,
                kot_sent:           !!line.kot_sent,
                kot_sent_quantity:  Number(line.kot_sent_quantity || 0),
            });
            preloadedLineKeys[line.id] = key;
        });
    }

    /* preload: also track last sale for reprint if page-loaded with held sale */
    if (heldSale) {
        rememberLastSale(heldSale.id, heldSale.sale_no || '');
    }

    /* initial render */
    renderProducts();
    renderCart();
    updateSplitBillBtn();
    updateRecalledBar();
    updateStartFreshLabel();
    if (_currentHeldSaleId) lockOrderControls();
});
</script>

@endsection
