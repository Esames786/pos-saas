@extends('layouts.app')

@section('title', 'Modern POS')

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
        padding: .6rem 1rem;
        font-weight: 700;
        white-space: nowrap;
        cursor: pointer;
    }

    .category-pill.active {
        background: #111827;
        color: #fff;
        border-color: #111827;
    }

    .product-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(165px, 1fr));
        gap: .85rem;
        max-height: calc(100vh - 340px);
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
        font-weight: 800;
        margin-bottom: .65rem;
    }

    .stock-badge {
        border-radius: 999px;
        background: #f8fafc;
        padding: .22rem .5rem;
        font-size: .75rem;
        font-weight: 700;
    }

    .stock-low { background: #fff3cd; color: #7a5200; }
    .stock-out { background: #fee2e2; color: #991b1b; }

    .cart-panel {
        position: sticky;
        top: 88px;
    }

    .cart-items {
        max-height: calc(100vh - 475px);
        overflow-y: auto;
    }

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
        font-weight: 800;
        cursor: pointer;
    }

    .shortcut-chip {
        border-radius: 999px;
        background: #f8fafc;
        padding: .35rem .65rem;
        font-size: .75rem;
        font-weight: 700;
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
        font-weight: 800;
        cursor: pointer;
    }

    @media (max-width: 1199px) {
        .pos-shell { grid-template-columns: 1fr; }
        .cart-panel { position: static; }
    }
</style>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Modern POS</h1>
        <p class="fw-medium mb-0">Quick sale, takeaway, barcode search, hold and recall.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <span class="shortcut-chip">F2 Search</span>
        <span class="shortcut-chip">F4 Hold</span>
        <span class="shortcut-chip">F6 Payment</span>
        <span class="shortcut-chip">F8 Complete</span>
        <span class="shortcut-chip">F9 Calc</span>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

@if(session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

@if($tableSession)
    <div class="alert alert-info" role="status">
        Table session loaded: <strong>{{ $tableSession->session_no }}</strong>
        · Table <strong>{{ $tableSession->table?->table_no }}</strong>
        · Waiter <strong>{{ $tableSession->waiter?->name ?? 'No waiter' }}</strong>
    </div>
@endif

@if($heldSale)
    <div class="alert alert-warning" role="status">
        Recalling held sale: <strong>{{ $heldSale->sale_no }}</strong>
    </div>
@endif

<form id="pos-sale-form" method="POST" action="{{ url('/pos') }}">
    @csrf

    <input type="hidden" name="order_source" value="pos">
    <input type="hidden" name="held_sale_id" value="{{ $heldSale?->id }}">
    <input type="hidden" name="restaurant_table_session_id" value="{{ $tableSession?->id ?? $heldSale?->restaurant_table_session_id }}">
    <input type="hidden" name="discount_type" value="none">
    <input type="hidden" name="discount_value" value="0">

    <div id="dynamic-pos-inputs"></div>

    <div class="pos-shell">
        {{-- Left: product browser --}}
        <section class="pos-card p-3" aria-labelledby="products_heading">
            <div class="row g-2 mb-3">
                <div class="col-md-3">
                    <label for="branch_id" class="form-label">Branch</label>
                    <select id="branch_id" name="branch_id" class="form-select" required>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}"
                                    @selected((int) $selectedBranchId === (int) $branch->id)>
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
                            <option value="{{ $terminal->id }}">
                                {{ $terminal->name }} — {{ $terminal->branch?->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="order_type" class="form-label">Mode</label>
                    <select id="order_type" name="order_type" class="form-select" required>
                        <option value="quick_sale" @selected(!$tableSession && !$heldSale)>Quick Sale</option>
                        <option value="takeaway">Takeaway</option>
                        <option value="delivery">Delivery</option>
                        <option value="dine_in" @selected($tableSession || $heldSale?->restaurant_table_session_id)>Dine In</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="customer_id" class="form-label">Customer</label>
                    <div class="input-group">
                        <select id="customer_id" name="customer_id" class="form-select">
                            <option value="">Walk-in</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}"
                                        @selected($heldSale?->customer_id === $customer->id)>
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
                    <label for="pos_search" class="form-label">Barcode / Search</label>
                    <input id="pos_search" class="form-control form-control-lg"
                           placeholder="Scan barcode or type product name / SKU" autocomplete="off">
                </div>
                <div class="col-md-4">
                    <label for="customer_phone" class="form-label">Phone (optional)</label>
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

        {{-- Right: cart + payment --}}
        <aside class="cart-panel">
            <section class="pos-card p-3 mb-3" aria-labelledby="cart_heading">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 id="cart_heading" class="h5 mb-0">Cart</h2>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="clear-cart-btn">Clear</button>
                </div>
                <div class="cart-items" id="cart-items">
                    <p class="text-muted mb-0">No items added.</p>
                </div>
            </section>

            <section class="pos-card p-3 mb-3" aria-labelledby="payment_heading">
                <h2 id="payment_heading" class="h5 mb-3">Payment</h2>
                <div class="mb-3">
                    <label for="payment_method_id" class="form-label">Method</label>
                    <select id="payment_method_id" class="form-select">
                        @foreach($paymentMethods as $method)
                            <option value="{{ $method->id }}" data-type="{{ $method->method_type }}">
                                {{ $method->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label for="tendered_amount" class="form-label">Tendered</label>
                    <input id="tendered_amount" type="number" step="0.01" min="0" class="form-control form-control-lg">
                </div>
                <div class="mb-3">
                    <label for="transaction_ref" class="form-label">Reference</label>
                    <input id="transaction_ref" class="form-control" placeholder="Card / bank ref (optional)">
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

            <section class="pos-card p-3 mb-3" id="calculator-panel" style="display:none;" aria-labelledby="calc_heading">
                <h2 id="calc_heading" class="h6 mb-2">Calculator</h2>
                <input id="calc-display" class="form-control mb-2" readonly>
                <div class="keypad">
                    @foreach(['7','8','9','/','4','5','6','*','1','2','3','-','0','.','C','+'] as $key)
                        <button type="button" data-key="{{ $key }}">{{ $key }}</button>
                    @endforeach
                    <button type="button" data-key="=" style="grid-column: span 4; background:#111827; color:#fff; border-color:#111827;">=</button>
                </div>
            </section>

            <div class="d-grid gap-2">
                <button type="button" class="btn btn-warning btn-lg" id="hold-sale-btn">
                    <i class="ti ti-player-pause me-1"></i> Hold Sale (F4)
                </button>
                <button type="button" class="btn btn-primary btn-lg" id="complete-sale-btn">
                    <i class="ti ti-circle-check me-1"></i> Complete Sale (F8)
                </button>
            </div>
        </aside>
    </div>
</form>

{{-- Quick customer modal --}}
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
                    <label for="quick_customer_name" class="form-label">Name <span class="text-danger">*</span></label>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const products   = @json($productsPayload);
    const categories = @json($categories);
    const heldSale   = @json($heldSale ? [
        'id'    => $heldSale->id,
        'lines' => $heldSale->lines->map(fn ($l) => [
            'product_id'         => (int)   $l->product_id,
            'product_variant_id' => $l->product_variant_id ? (int) $l->product_variant_id : null,
            'quantity'           => (float) $l->quantity,
            'unit_price'         => (float) $l->unit_price,
            'discount_amount'    => (float) $l->discount_amount,
            'tax_amount'         => (float) $l->tax_amount,
        ])->values(),
    ] : null);

    const form              = document.getElementById('pos-sale-form');
    const productGrid       = document.getElementById('product-grid');
    const cartItems         = document.getElementById('cart-items');
    const branchEl          = document.getElementById('branch_id');
    const searchEl          = document.getElementById('pos_search');
    const dynamicInputs     = document.getElementById('dynamic-pos-inputs');
    const paymentMethodEl   = document.getElementById('payment_method_id');
    const tenderedEl        = document.getElementById('tendered_amount');
    const transactionRefEl  = document.getElementById('transaction_ref');
    const calculatorPanel   = document.getElementById('calculator-panel');
    const calcDisplay       = document.getElementById('calc-display');

    let selectedParentCategory = '';
    let selectedChildCategory  = '';
    let cart = [];

    function money(v) { return Number(v || 0).toFixed(2); }
    function selectedBranchId() { return Number(branchEl.value || 0); }

    function productPrice(product, variant) {
        const bid = selectedBranchId();

        if (variant) {
            const vp = (product.branch_prices || []).find(p =>
                Number(p.branch_id) === bid && Number(p.product_variant_id || 0) === Number(variant.id));
            if (vp) return Number(vp.selling_price);
        }

        const pp = (product.branch_prices || []).find(p =>
            Number(p.branch_id) === bid && !p.product_variant_id);
        if (pp) return Number(pp.selling_price);

        return Number((variant ? variant.selling_price : null) || product.price || 0);
    }

    function availableQty(product, variant) {
        if (!product.is_stock_tracked) return null;
        const bid = selectedBranchId();
        if (variant && variant.stock_by_branch) return Number(variant.stock_by_branch[bid] || 0);
        return Number((product.stock_by_branch || {})[bid] || 0);
    }

    function calcTax(product, qty, price, discount) {
        if (!product.is_taxable || !(product.tax_rate_percent > 0)) return 0;
        return ((Math.max(qty * price - discount, 0)) * product.tax_rate_percent) / 100;
    }

    function initials(name) {
        return String(name || '?').split(' ').map(p => p[0]).join('').substring(0, 2).toUpperCase();
    }

    function renderProducts() {
        const query = searchEl.value.toLowerCase().trim();

        const filtered = products.filter(function (p) {
            const matchParent = !selectedParentCategory || Number(p.category_id) === Number(selectedParentCategory);
            const matchChild  = !selectedChildCategory  || Number(p.category_id) === Number(selectedChildCategory);

            const barcodeHit = (p.barcodes || []).some(b => String(b).toLowerCase().includes(query));
            const textHit    = !query || p.name.toLowerCase().includes(query)
                || String(p.sku || '').toLowerCase().includes(query) || barcodeHit;

            return textHit && (selectedChildCategory ? matchChild : matchParent);
        });

        productGrid.innerHTML = '';

        if (!filtered.length) {
            productGrid.innerHTML = '<div class="alert alert-info">No products found.</div>';
            return;
        }

        filtered.forEach(function (product) {
            const variant = product.variants && product.variants.length ? product.variants[0] : null;
            const qty     = availableQty(product, variant);
            const price   = productPrice(product, variant);

            const stockClass = qty === null ? '' : qty <= 0 ? 'stock-out' : qty <= 5 ? 'stock-low' : '';
            const stockText  = qty === null ? 'Service' : 'Qty ' + qty;

            const btn = document.createElement('button');
            btn.type      = 'button';
            btn.className = 'product-tile';
            btn.dataset.productId  = product.id;
            btn.dataset.variantId  = variant ? variant.id : '';

            btn.innerHTML = `
                <div class="product-avatar">${initials(product.name)}</div>
                <div class="fw-bold mb-1">${product.name}</div>
                <div class="text-muted small mb-2">${product.sku || '–'}</div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold">${money(price)}</span>
                    <span class="stock-badge ${stockClass}">${stockText}</span>
                </div>
                ${product.is_taxable ? `<div class="small text-muted mt-1">Tax ${product.tax_rate_percent}%</div>` : ''}
            `;

            btn.addEventListener('click', () => addToCart(product, variant));
            productGrid.appendChild(btn);
        });
    }

    function addToCart(product, variant) {
        const key = product.id + ':' + (variant ? variant.id : 0);
        const existing = cart.find(i => i.key === key);
        const qtyAvail = availableQty(product, variant);

        if (qtyAvail !== null && qtyAvail <= 0) {
            alert('This item is out of stock.');
            return;
        }

        if (existing) {
            existing.quantity += 1;
        } else {
            const price = productPrice(product, variant);
            cart.push({
                key, product_id: product.id,
                product_variant_id: variant ? variant.id : null,
                name: product.name,
                variant_name: variant ? variant.name : null,
                quantity: 1, unit_price: price, discount_amount: 0,
                tax_amount: calcTax(product, 1, price, 0),
                product, variant
            });
        }

        renderCart();
    }

    function renderCart() {
        cartItems.innerHTML = '';

        if (!cart.length) {
            cartItems.innerHTML = '<p class="text-muted mb-0">No items added.</p>';
            updateTotals();
            return;
        }

        cart.forEach(function (item, idx) {
            item.tax_amount = calcTax(item.product, item.quantity, item.unit_price, item.discount_amount);

            const row = document.createElement('div');
            row.className = 'cart-row';
            row.innerHTML = `
                <div class="d-flex justify-content-between gap-2 mb-2">
                    <div>
                        <div class="fw-bold">${item.name}</div>
                        <div class="small text-muted">${item.variant_name || 'Default'} · ${money(item.unit_price)}</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger px-2" data-remove="${idx}">×</button>
                </div>
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="qty-btn" data-minus="${idx}">−</button>
                        <strong>${item.quantity}</strong>
                        <button type="button" class="qty-btn" data-plus="${idx}">+</button>
                    </div>
                    <strong>${money((item.quantity * item.unit_price) - item.discount_amount + item.tax_amount)}</strong>
                </div>
            `;
            cartItems.appendChild(row);
        });

        cartItems.querySelectorAll('[data-plus]').forEach(btn =>
            btn.addEventListener('click', () => { cart[+btn.dataset.plus].quantity++; renderCart(); }));
        cartItems.querySelectorAll('[data-minus]').forEach(btn =>
            btn.addEventListener('click', () => {
                cart[+btn.dataset.minus].quantity--;
                if (cart[+btn.dataset.minus].quantity <= 0) cart.splice(+btn.dataset.minus, 1);
                renderCart();
            }));
        cartItems.querySelectorAll('[data-remove]').forEach(btn =>
            btn.addEventListener('click', () => { cart.splice(+btn.dataset.remove, 1); renderCart(); }));

        updateTotals();
    }

    function totals() {
        let subtotal = 0, discount = 0, tax = 0;
        cart.forEach(i => {
            subtotal += i.quantity * i.unit_price;
            discount += Number(i.discount_amount || 0);
            tax      += Number(i.tax_amount || 0);
        });
        return { subtotal, discount, tax, total: Math.max(subtotal - discount + tax, 0) };
    }

    function updateTotals() {
        const t = totals();
        document.getElementById('subtotal-view').textContent   = money(t.subtotal);
        document.getElementById('discount-view').textContent   = money(t.discount);
        document.getElementById('tax-view').textContent        = money(t.tax);
        document.getElementById('grand-total-view').textContent = money(t.total);

        if (!tenderedEl.dataset.manual) tenderedEl.value = money(t.total);

        const change = Math.max(Number(tenderedEl.value || 0) - t.total, 0);
        document.getElementById('change-view').textContent = money(change);
    }

    function buildInputs(includePayment) {
        dynamicInputs.innerHTML = '';

        cart.forEach(function (item, i) {
            const fields = {
                product_id: item.product_id,
                product_variant_id: item.product_variant_id || '',
                quantity: item.quantity,
                unit_price: item.unit_price,
                discount_amount: item.discount_amount || 0,
                tax_amount: item.tax_amount || 0
            };
            Object.keys(fields).forEach(function (k) {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = `lines[${i}][${k}]`; inp.value = fields[k];
                dynamicInputs.appendChild(inp);
            });
        });

        if (includePayment) {
            const t = totals();
            const pf = {
                payment_method_id: paymentMethodEl.value,
                amount: money(t.total),
                tendered_amount: tenderedEl.value || money(t.total),
                transaction_ref: transactionRefEl.value || ''
            };
            Object.keys(pf).forEach(function (k) {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = `payments[0][${k}]`; inp.value = pf[k];
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

    branchEl.addEventListener('change', function () { renderProducts(); renderCart(); });

    searchEl.addEventListener('input', function () {
        const query = searchEl.value.trim().toLowerCase();

        const exact = products.find(p =>
            (p.barcodes || []).some(b => String(b).toLowerCase() === query));
        if (exact) {
            addToCart(exact, exact.variants && exact.variants.length ? exact.variants[0] : null);
            searchEl.value = '';
        }

        renderProducts();
    });

    document.querySelectorAll('[data-parent-category]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-parent-category]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            selectedParentCategory = btn.dataset.parentCategory;
            selectedChildCategory  = '';

            const wrap  = document.getElementById('child-category-wrap');
            const strip = document.getElementById('child-category-strip');
            strip.innerHTML = '';

            const parent = categories.find(c => Number(c.id) === Number(selectedParentCategory));

            if (parent && parent.children && parent.children.length) {
                wrap.style.display = '';

                const allBtn = document.createElement('button');
                allBtn.type = 'button'; allBtn.className = 'category-pill active';
                allBtn.textContent = 'All';
                allBtn.addEventListener('click', function () {
                    selectedChildCategory = '';
                    strip.querySelectorAll('.category-pill').forEach(b => b.classList.remove('active'));
                    allBtn.classList.add('active');
                    renderProducts();
                });
                strip.appendChild(allBtn);

                parent.children.forEach(function (child) {
                    const cb = document.createElement('button');
                    cb.type = 'button'; cb.className = 'category-pill';
                    cb.textContent = child.name;
                    cb.addEventListener('click', function () {
                        selectedChildCategory = child.id;
                        strip.querySelectorAll('.category-pill').forEach(b => b.classList.remove('active'));
                        cb.classList.add('active');
                        renderProducts();
                    });
                    strip.appendChild(cb);
                });
            } else {
                wrap.style.display = 'none';
            }

            renderProducts();
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'F2') { e.preventDefault(); searchEl.focus(); }
        if (e.key === 'F4') { e.preventDefault(); submitHeldSale(); }
        if (e.key === 'F6') { e.preventDefault(); paymentMethodEl.focus(); }
        if (e.key === 'F8') { e.preventDefault(); submitPaidSale(); }
        if (e.key === 'F9') {
            e.preventDefault();
            calculatorPanel.style.display = calculatorPanel.style.display === 'none' ? '' : 'none';
        }
    });

    document.querySelectorAll('[data-key]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const k = btn.dataset.key;
            if (k === 'C') { calcDisplay.value = ''; return; }
            if (k === '=') {
                try { calcDisplay.value = Function('"use strict"; return (' + calcDisplay.value + ')')(); }
                catch (err) { calcDisplay.value = 'Error'; }
                return;
            }
            calcDisplay.value += k;
        });
    });

    if (heldSale && heldSale.lines) {
        heldSale.lines.forEach(function (line) {
            const p = products.find(x => Number(x.id) === Number(line.product_id));
            if (!p) return;
            const v = (p.variants || []).find(x => Number(x.id) === Number(line.product_variant_id));
            cart.push({
                key: p.id + ':' + (v ? v.id : 0),
                product_id: p.id, product_variant_id: v ? v.id : null,
                name: p.name, variant_name: v ? v.name : null,
                quantity: Number(line.quantity || 1),
                unit_price: Number(line.unit_price || productPrice(p, v)),
                discount_amount: Number(line.discount_amount || 0),
                tax_amount: Number(line.tax_amount || 0),
                product: p, variant: v || null
            });
        });
    }

    renderProducts();
    renderCart();
});
</script>
@endsection
