@extends('layouts.app')

@section('title', 'Stock Count ' . $session->count_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $session->count_no }}</h1>
        <p class="text-muted mb-0">{{ $session->branch?->name }} &mdash; <strong>{{ ucfirst($session->status) }}</strong></p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ url('/stock-counts') }}" class="btn btn-light">Back</a>

        @if($session->canEdit())
            <form method="POST"
                  action="{{ url('/stock-counts/' . $session->id . '/cancel') }}"
                  onsubmit="return confirm('Cancel this stock count?');">
                @csrf
                <button class="btn btn-outline-danger">Cancel Count</button>
            </form>

            <form method="POST" action="{{ url('/stock-counts/' . $session->id . '/post') }}">
                @csrf
                <button class="btn btn-success">Post Variance</button>
            </form>
        @endif
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('warning'))
    <div class="alert alert-warning">{{ session('warning') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

@if($session->status === 'posted' && ($session->increaseAdjustment || $session->decreaseAdjustment))
    <div class="alert alert-info">
        <strong>Posted adjustments:</strong>

        @if($session->increaseAdjustment)
            <a href="{{ url('/stock-adjustments/' . $session->increaseAdjustment->id) }}" class="alert-link">
                Increase {{ $session->increaseAdjustment->adjustment_no }}
            </a>
        @endif

        @if($session->increaseAdjustment && $session->decreaseAdjustment)
            <span class="mx-1">|</span>
        @endif

        @if($session->decreaseAdjustment)
            <a href="{{ url('/stock-adjustments/' . $session->decreaseAdjustment->id) }}" class="alert-link">
                Decrease {{ $session->decreaseAdjustment->adjustment_no }}
            </a>
        @endif
    </div>
@endif

@if($session->canEdit())
{{-- Scanner + Manual Add --}}
<div class="card mb-3">
    <div class="card-body">
        <div class="border rounded p-3 mb-3 bg-light"
             id="stock-count-scanner-widget"
             data-lookup-url="{{ url('/api/catalog/barcode/lookup') }}"
             data-add-url="{{ url('/stock-counts/' . $session->id . '/lines') }}"
             data-branch-id="{{ $session->branch_id }}">
            <label class="form-label small fw-semibold" for="stock-count-scanner">
                Scan Barcode / SKU
            </label>
            <div class="input-group input-group-sm">
                <span class="input-group-text">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                        <path d="M1 1h2v14H1V1zm3 0h1v14H4V1zm2 0h2v14H6V1zm3 0h1v14H9V1zm2 0h1v14h-1V1zm2 0h2v14h-2V1z"/>
                    </svg>
                </span>
                <input type="text"
                       id="stock-count-scanner"
                       class="form-control"
                       placeholder="Scan barcode or type SKU then press Enter"
                       autocomplete="off"
                       inputmode="text">
                <button class="btn btn-outline-primary" type="button" id="stock-count-scan-btn">Add</button>
            </div>
            <div class="form-text">Scan product barcode/SKU to add to count. Duplicate products are skipped.</div>
        </div>

        <form method="POST"
              action="{{ url('/stock-counts/' . $session->id . '/lines') }}"
              class="row g-2 align-items-end">
            @csrf
            <div class="col-md-5">
                <label class="form-label">Product</label>
                <select name="product_id" id="manual-product" class="form-select" required>
                    <option value="">— Select product —</option>
                    @foreach($products as $product)
                        @php
                            $variantsJson = $product->variants->map(fn($v) => [
                                'id'         => (int) $v->id,
                                'name'       => $v->name,
                                'sku'        => $v->sku,
                                'is_default' => (bool) $v->is_default,
                                'is_active'  => (bool) $v->is_active,
                            ])->values();
                        @endphp
                        <option value="{{ $product->id }}"
                                data-variants="{{ $variantsJson->toJson() }}">
                            {{ $product->name }}{{ $product->sku ? ' — ' . $product->sku : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Variant</label>
                <select name="product_variant_id" id="manual-variant" class="form-select">
                    <option value="">Default</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary w-100">Add Line</button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- Lines Table --}}
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="text-end">System Qty</th>
                    <th style="min-width:160px">Counted Qty</th>
                    <th class="text-end">Variance</th>
                    <th class="text-end">Value</th>
                    <th>Notes</th>
                    @if($session->canEdit())
                        <th class="text-end">Action</th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @forelse($session->lines as $line)
                <tr>
                    <td>
                        <strong>{{ $line->product?->name }}</strong>
                        @if($line->variant && !$line->variant->is_default)
                            <div class="small text-muted">{{ $line->variant->name }}</div>
                        @endif
                        @if($line->unit?->code)
                            <span class="badge bg-light text-dark">{{ $line->unit->code }}</span>
                        @endif
                    </td>

                    <td class="text-end">{{ number_format((float) $line->system_quantity, 3) }}</td>

                    <td>
                        @if($session->canEdit())
                            <form method="POST"
                                  action="{{ url('/stock-counts/' . $session->id . '/lines/' . $line->id) }}"
                                  class="d-flex gap-1 align-items-center counted-form">
                                @csrf
                                @method('PATCH')
                                <input id="counted-{{ $line->id }}"
                                       type="number"
                                       name="counted_quantity"
                                       step="0.001"
                                       min="0"
                                       class="form-control form-control-sm"
                                       style="max-width:110px"
                                       value="{{ $line->counted_quantity !== null ? (float) $line->counted_quantity : '' }}"
                                       placeholder="Enter qty">
                                <input type="hidden" name="notes" class="notes-hidden" value="{{ $line->notes }}">
                                <button class="btn btn-sm btn-outline-primary" title="Save">✓</button>
                            </form>
                        @else
                            {{ $line->counted_quantity !== null ? number_format((float) $line->counted_quantity, 3) : '—' }}
                        @endif
                    </td>

                    <td class="text-end fw-semibold">
                        @php $v = (float) $line->variance_quantity; @endphp
                        <span class="{{ $v > 0 ? 'text-success' : ($v < 0 ? 'text-danger' : 'text-muted') }}">
                            {{ $v >= 0 ? '+' : '' }}{{ number_format($v, 3) }}
                        </span>
                    </td>

                    <td class="text-end">
                        @php $vv = (float) $line->variance_value; @endphp
                        <span class="{{ $vv > 0 ? 'text-success' : ($vv < 0 ? 'text-danger' : 'text-muted') }}">
                            {{ $vv >= 0 ? '+' : '' }}{{ number_format($vv, 2) }}
                        </span>
                    </td>

                    <td>
                        @if($session->canEdit())
                            <input type="text"
                                   class="form-control form-control-sm notes-input"
                                   style="min-width:140px"
                                   data-line-id="{{ $line->id }}"
                                   value="{{ $line->notes }}"
                                   placeholder="Optional note">
                        @else
                            {{ $line->notes }}
                        @endif
                    </td>

                    @if($session->canEdit())
                        <td class="text-end">
                            <form method="POST"
                                  action="{{ url('/stock-counts/' . $session->id . '/lines/' . $line->id) }}"
                                  onsubmit="return confirm('Remove this line?');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">✕</button>
                            </form>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $session->canEdit() ? 7 : 6 }}" class="text-center text-muted py-4">
                        No products added yet. Scan or select a product above.
                    </td>
                </tr>
            @endforelse
            </tbody>

            @if($session->lines->count() > 0)
            <tfoot class="table-light">
                <tr>
                    <td colspan="3" class="text-end fw-semibold">Totals:</td>
                    <td class="text-end fw-semibold">
                        @php $totalVarianceQty = $session->lines->sum('variance_quantity'); @endphp
                        <span class="{{ $totalVarianceQty >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ $totalVarianceQty >= 0 ? '+' : '' }}{{ number_format($totalVarianceQty, 3) }}
                        </span>
                    </td>
                    <td class="text-end fw-semibold">
                        @php $totalVarianceVal = $session->lines->sum('variance_value'); @endphp
                        <span class="{{ $totalVarianceVal >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ $totalVarianceVal >= 0 ? '+' : '' }}{{ number_format($totalVarianceVal, 2) }}
                        </span>
                    </td>
                    <td colspan="{{ $session->canEdit() ? 2 : 1 }}"></td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        var inp = document.querySelector('input[name="_token"]');
        return inp ? inp.value : '';
    }

    function notify(message, type) {
        if (window.Swal) {
            Swal.fire({ toast: true, position: 'top-end', timer: 2200,
                showConfirmButton: false, icon: type || 'info', title: message });
            return;
        }
        alert(message);
    }

    // Sync notes input into the hidden field on the save form before submit
    document.querySelectorAll('.notes-input').forEach(function (input) {
        input.addEventListener('input', function () {
            var lineId = input.dataset.lineId;
            var form = document.querySelector('form.counted-form input[id="counted-' + lineId + '"]');
            if (form) {
                var hidden = form.closest('form').querySelector('.notes-hidden');
                if (hidden) hidden.value = input.value;
            }
        });
    });

    // Scanner
    var widget = document.getElementById('stock-count-scanner-widget');
    var scanner = document.getElementById('stock-count-scanner');
    var scanBtn = document.getElementById('stock-count-scan-btn');

    if (widget && scanner && scanBtn) {
        function submitAddLine(productId, variantId) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = widget.dataset.addUrl;

            [['_token', csrfToken()], ['product_id', productId || ''], ['product_variant_id', variantId || '']].forEach(function (pair) {
                var el = document.createElement('input');
                el.type = 'hidden';
                el.name = pair[0];
                el.value = pair[1];
                form.appendChild(el);
            });

            document.body.appendChild(form);
            form.submit();
        }

        function lookupAndAdd(code) {
            fetch(widget.dataset.lookupUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken()
                },
                body: JSON.stringify({ branch_id: widget.dataset.branchId, code: code })
            })
            .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
            .then(function (result) {
                if (!result || !result.found) {
                    notify(result && result.message ? result.message : 'Barcode not found.', 'warning');
                    return;
                }
                submitAddLine(result.product_id, result.variant_id);
            })
            .catch(function () { notify('Barcode lookup failed.', 'error'); });
        }

        function handleScan() {
            var code = scanner.value.trim();
            if (!code) { scanner.focus(); return; }
            scanner.value = '';
            lookupAndAdd(code);
        }

        scanner.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); handleScan(); }
        });
        scanBtn.addEventListener('click', handleScan);
        setTimeout(function () { scanner.focus(); }, 200);
    }

    // Manual product → variant dropdown
    var manualProduct = document.getElementById('manual-product');
    var manualVariant = document.getElementById('manual-variant');

    if (manualProduct && manualVariant) {
        manualProduct.addEventListener('change', function () {
            var option = manualProduct.options[manualProduct.selectedIndex];
            var variants = [];
            try { variants = JSON.parse(option.dataset.variants || '[]'); } catch (e) {}

            manualVariant.innerHTML = '<option value="">Default</option>';
            variants.forEach(function (v) {
                if (v.is_active === false) return;
                var opt = document.createElement('option');
                opt.value = v.id;
                opt.textContent = v.name || v.sku || ('Variant #' + v.id);
                if (v.is_default) opt.selected = true;
                manualVariant.appendChild(opt);
            });
        });
    }

    // Auto-focus the counted qty input for the newly added line
    @if(session('focus_line_id'))
    var focusInput = document.getElementById('counted-{{ session('focus_line_id') }}');
    if (focusInput) { focusInput.focus(); focusInput.select(); }
    @endif
})();
</script>
@endpush
