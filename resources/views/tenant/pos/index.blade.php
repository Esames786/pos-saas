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
        <span class="shortcut-chip">F2 Search</span>
        <span class="shortcut-chip">F4 Save Order</span>
        <span class="shortcut-chip">F6 Payment</span>
        <span class="shortcut-chip">F8 Pay Bill</span>
        <span class="shortcut-chip">F9 Calculator</span>
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
    <div class="mode-tabs" role="tablist" aria-label="POS Modes">
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
    <input type="hidden" name="restaurant_table_session_id"                             value="{{ $tableSession?->id ?? $heldSale?->restaurant_table_session_id }}">
    <input type="hidden" name="discount_type"                                           value="none">
    <input type="hidden" name="discount_value"                                          value="0">
    <div id="dynamic-pos-inputs"></div>

    <div class="pos-shell">
        {{-- LEFT: products --}}
        <section class="pos-card p-3" aria-labelledby="products_heading">
            <div class="row g-2 mb-3">
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
                    <button type="button" class="btn btn-sm btn-outline-danger" id="clear-cart-btn">Clear</button>
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
                <div class="pos-total-line"><span>Subtotal</span><strong id="subtotal-view">0.00</strong></div>
                <div class="pos-total-line"><span>Discount</span><strong id="discount-view">0.00</strong></div>
                <div class="pos-total-line"><span>Tax</span><strong id="tax-view">0.00</strong></div>
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

            <div class="d-grid gap-2">
                <button type="button" class="btn btn-warning btn-lg" id="hold-sale-btn">
                    {{ $tableSession ? 'Save Order To Table' : 'Hold Sale' }}
                </button>
                <button type="button" class="btn btn-primary btn-lg" id="complete-sale-btn">
                    {{ $tableSession ? 'Close & Pay Table Bill' : 'Complete Sale' }}
                </button>
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
                <button class="btn btn-success" type="submit">Open Table</button>
            </div>
        </form>
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
        'id'    => $heldSale->id,
        'lines' => $heldSale->lines->map(fn ($l) => [
            'product_id'         => (int) $l->product_id,
            'product_variant_id' => $l->product_variant_id ? (int) $l->product_variant_id : null,
            'quantity'           => (float) $l->quantity,
            'unit_price'         => (float) $l->unit_price,
            'discount_amount'    => (float) $l->discount_amount,
            'tax_amount'         => (float) $l->tax_amount,
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
            btn.addEventListener('click', function () { cart.splice(Number(btn.dataset.remove), 1); renderCart(); });
        });

        updateTotals();
    }

    /* totals */

    function totals() {
        let subtotal = 0, discount = 0, tax = 0;
        cart.forEach(function (item) {
            subtotal += item.quantity * item.unit_price;
            discount += Number(item.discount_amount || 0);
            tax      += Number(item.tax_amount || 0);
        });
        return { subtotal: subtotal, discount: discount, tax: tax, total: Math.max(subtotal - discount + tax, 0) };
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
    }

    function submitPaidSale() {
        if (!cart.length) { alert('Please add at least one item.'); return; }
        buildInputs(true);
        form.action = '{{ url('/pos') }}';
        form.submit();
    }

    function submitHeldSale() {
        if (!cart.length) { alert('Please add at least one item.'); return; }
        buildInputs(false);
        form.action = '{{ url('/held-sales') }}';
        form.submit();
    }

    document.getElementById('complete-sale-btn').addEventListener('click', submitPaidSale);
    document.getElementById('hold-sale-btn').addEventListener('click', submitHeldSale);
    document.getElementById('clear-cart-btn').addEventListener('click', function () {
        if (confirm('Clear cart?')) { cart = []; renderCart(); }
    });

    tenderedEl.addEventListener('input', function () {
        tenderedEl.dataset.manual = '1';
        updateTotals();
    });

    /* branch change → reload */

    branchEl.addEventListener('change', function () {
        window.location.href = '{{ url('/pos') }}?branch_id=' + branchEl.value + '&mode=' + orderTypeEl.value;
    });

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

    /* mode tabs */

    document.querySelectorAll('[data-mode-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            const mode = button.dataset.modeTab;
            orderTypeEl.value = mode;
            document.querySelectorAll('[data-mode-tab]').forEach(function (b) { b.classList.remove('active'); });
            button.classList.add('active');
            document.getElementById('dine-in-board').style.display = mode === 'dine_in' ? '' : 'none';
        });
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

    /* open table modal */

    document.getElementById('openTableModal').addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if (!trigger) return;
        document.getElementById('open-table-no').textContent     = trigger.getAttribute('data-table-no');
        document.getElementById('open-table-form').action        = '{{ url('/restaurant/tables') }}/' + trigger.getAttribute('data-table-id') + '/open';
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

    /* keyboard shortcuts */

    document.addEventListener('keydown', function (event) {
        if (event.key === 'F2')  { event.preventDefault(); searchEl.focus(); }
        if (event.key === 'F4')  { event.preventDefault(); submitHeldSale(); }
        if (event.key === 'F6')  { event.preventDefault(); paymentMethodEl.focus(); }
        if (event.key === 'F8')  { event.preventDefault(); submitPaidSale(); }
        if (event.key === 'F9')  { event.preventDefault(); calculatorPanel.style.display = calculatorPanel.style.display === 'none' ? '' : 'none'; }
    });

    /* preload held sale */

    if (heldSale && heldSale.lines) {
        heldSale.lines.forEach(function (line) {
            const product = products.find(function (p) { return Number(p.id) === Number(line.product_id); });
            if (!product) return;

            const variant = (product.variants || []).find(function (v) { return Number(v.id) === Number(line.product_variant_id); });

            cart.push({
                key:                product.id + ':' + (variant ? variant.id : 0),
                product_id:         product.id,
                product_variant_id: variant ? variant.id : null,
                name:               product.name,
                variant_name:       variant ? variant.name : null,
                quantity:           Number(line.quantity || 1),
                unit_price:         Number(line.unit_price || productPrice(product, variant)),
                discount_amount:    Number(line.discount_amount || 0),
                tax_amount:         Number(line.tax_amount || 0),
                product:            product,
                variant:            variant || null,
            });
        });
    }

    /* initial render */
    renderProducts();
    renderCart();
});
</script>
@endsection
