@extends('layouts.app')
@section('title', 'New Held Sale')
@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col"><h3 class="page-title">New Held Sale</h3></div>
                    <div class="col-auto">
                        <a href="{{ url('/held-sales') }}" class="btn btn-outline-secondary">
                            <i class="ti ti-arrow-left me-1"></i> Back
                        </a>
                    </div>
                </div>
            </div>

            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ url('/held-sales') }}" id="held-sale-form">
                @csrf
                <div class="row g-3">

                    {{-- Left: product lines --}}
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header fw-semibold">Order Lines</div>
                            <div class="card-body p-2">
                                @include('tenant.sales.partials.product-lines', [
                                    'products' => $products,
                                    'tableId'  => 'held-lines-table',
                                    'caption'  => 'Held sale product lines',
                                ])
                            </div>
                            <div class="card-footer">
                                <button type="button" id="add-line-btn" class="btn btn-sm btn-outline-secondary">
                                    <i class="ti ti-plus me-1"></i> Add Line
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- Right: order details --}}
                    <div class="col-lg-4">

                        <div class="card mb-3">
                            <div class="card-header fw-semibold">Order Details</div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Branch <span class="text-danger">*</span></label>
                                    <select name="branch_id" id="branch-select" class="form-select" required>
                                        <option value="">— Select Branch —</option>
                                        @foreach($branches as $b)
                                            <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Terminal</label>
                                    <select name="terminal_id" class="form-select">
                                        <option value="">— None —</option>
                                        @foreach($terminals as $t)
                                            <option value="{{ $t->id }}" @selected(old('terminal_id') == $t->id)>{{ $t->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Order Type <span class="text-danger">*</span></label>
                                    <select name="order_type" id="order-type-select" class="form-select" required>
                                        <option value="quick_sale" @selected(old('order_type','quick_sale') === 'quick_sale')>Quick Sale</option>
                                        <option value="takeaway" @selected(old('order_type') === 'takeaway')>Takeaway</option>
                                        <option value="dine_in" @selected(old('order_type') === 'dine_in')>Dine-In</option>
                                        <option value="delivery" @selected(old('order_type') === 'delivery')>Delivery</option>
                                    </select>
                                </div>
                                <div class="mb-3" id="table-session-row"
                                     style="{{ old('order_type') === 'dine_in' ? '' : 'display:none' }}">
                                    <label class="form-label">Table Session</label>
                                    <select name="restaurant_table_session_id" class="form-select">
                                        <option value="">— None —</option>
                                        @foreach($tableSessions as $ts)
                                            <option value="{{ $ts->id }}"
                                                    @selected(old('restaurant_table_session_id') == $ts->id)>
                                                {{ $ts->table->floor->name ?? '' }} –
                                                {{ $ts->table->table_no ?? '' }}
                                                ({{ $ts->session_no }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-header fw-semibold">Customer</div>
                            <div class="card-body">
                                <div class="mb-2">
                                    <label class="form-label small">Registered Customer</label>
                                    <select name="customer_id" class="form-select form-select-sm">
                                        <option value="">Walk-in</option>
                                        @foreach($customers as $c)
                                            <option value="{{ $c->id }}" @selected(old('customer_id') == $c->id)>{{ $c->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <input type="text" name="customer_name" class="form-control form-control-sm"
                                           placeholder="Walk-in name (optional)" maxlength="200"
                                           value="{{ old('customer_name') }}">
                                </div>
                                <div class="mb-2">
                                    <input type="text" name="customer_phone" class="form-control form-control-sm"
                                           placeholder="Phone" maxlength="50"
                                           value="{{ old('customer_phone') }}">
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-header fw-semibold">Discount &amp; Notes</div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Discount Type</label>
                                    <select name="discount_type" class="form-select">
                                        <option value="none" @selected(old('discount_type','none') === 'none')>None</option>
                                        <option value="fixed" @selected(old('discount_type') === 'fixed')>Fixed Amount</option>
                                        <option value="percent" @selected(old('discount_type') === 'percent')>Percent (%)</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Discount Value</label>
                                    <input type="number" name="discount_value" step="0.01" min="0"
                                           class="form-control" placeholder="0.00"
                                           value="{{ old('discount_value', 0) }}">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2"
                                              placeholder="Order notes...">{{ old('notes') }}</textarea>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-warning btn-lg">
                                <i class="ti ti-player-pause me-1"></i> Hold Sale
                            </button>
                        </div>

                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.getElementById('order-type-select').addEventListener('change', function () {
    document.getElementById('table-session-row').style.display =
        this.value === 'dine_in' ? '' : 'none';
});

let lineCount = {{ max(count(old('lines', [])), 3) }};

document.getElementById('add-line-btn').addEventListener('click', function () {
    const tbody = document.querySelector('#held-lines-table tbody');
    const tpl   = tbody.querySelector('.line-row').cloneNode(true);
    tpl.querySelectorAll('[name]').forEach(function (el) {
        el.name  = el.name.replace(/\[\d+\]/, '[' + lineCount + ']');
        if (el.tagName === 'SELECT') {
            el.value = '';
        } else {
            el.value = el.classList.contains('qty-input') ? 1 : 0;
        }
    });
    tpl.querySelector('.line-total').textContent = '0.00';
    tbody.appendChild(tpl);
    lineCount++;
});

document.addEventListener('click', function (e) {
    if (e.target.closest('.remove-line')) {
        const rows = document.querySelectorAll('#held-lines-table .line-row');
        if (rows.length > 1) {
            e.target.closest('.line-row').remove();
        }
    }
});

document.addEventListener('input', function (e) {
    if (e.target.classList.contains('qty-input') || e.target.classList.contains('price-input')) {
        const row   = e.target.closest('.line-row');
        const qty   = parseFloat(row.querySelector('.qty-input').value)   || 0;
        const price = parseFloat(row.querySelector('.price-input').value) || 0;
        row.querySelector('.line-total').textContent = (qty * price).toFixed(2);
    }
});
</script>
@endpush
@endsection
