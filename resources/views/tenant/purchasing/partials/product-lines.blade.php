@php
    $quantityField   = $quantityField   ?? 'quantity_ordered';
    $showBatch       = $showBatch       ?? false;
    $showDiscountTax = $showDiscountTax ?? true;
    $showNotes       = $showNotes       ?? false;
@endphp

<div class="purchase-lines-widget"
     data-quantity-field="{{ $quantityField }}"
     data-show-batch="{{ $showBatch ? 1 : 0 }}"
     data-show-discount-tax="{{ $showDiscountTax ? 1 : 0 }}"
     data-show-notes="{{ $showNotes ? 1 : 0 }}"
     data-lookup-url="{{ url('/api/catalog/barcode/lookup') }}">

    <div class="border rounded p-3 mb-3 bg-light">
        <label class="form-label small fw-semibold" for="purchase-line-scanner-{{ $quantityField }}">
            Scan Barcode / SKU
        </label>
        <div class="input-group input-group-sm">
            <span class="input-group-text">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                    <path d="M1 1h2v14H1V1zm3 0h1v14H4V1zm2 0h2v14H6V1zm3 0h1v14H9V1zm2 0h1v14h-1V1zm2 0h2v14h-2V1z"/>
                </svg>
            </span>
            <input type="text"
                   id="purchase-line-scanner-{{ $quantityField }}"
                   class="form-control purchase-line-scanner"
                   placeholder="Scan barcode or type SKU then press Enter"
                   autocomplete="off"
                   inputmode="text">
            <button class="btn btn-outline-primary purchase-line-scan-btn" type="button">
                Add
            </button>
        </div>
        <div class="form-text">
            Scan product barcode/SKU to add a line, then enter {{ $showBatch ? 'received' : 'ordered' }} quantity.
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">{{ $caption ?? 'Product lines' }}</caption>
            <thead>
            <tr>
                <th scope="col">Product</th>
                <th scope="col">Variant</th>

                @if($showBatch)
                    <th scope="col">Batch No</th>
                    <th scope="col">Expiry</th>
                @endif

                <th scope="col">Quantity</th>
                <th scope="col">Unit Cost</th>

                @if($showDiscountTax)
                    <th scope="col">Discount</th>
                    <th scope="col">Tax</th>
                @endif

                @if($showNotes)
                    <th scope="col">Notes</th>
                @endif
            </tr>
            </thead>
            <tbody class="purchase-lines-body">
            @for($i = 0; $i < 5; $i++)
                @php
                    $variantData = collect([]);
                    // will be overwritten per-product inside the select loop
                @endphp
                <tr class="purchase-line-row">
                    <td>
                        <label for="lines_{{ $i }}_product_id" class="visually-hidden">Product line {{ $i + 1 }}</label>
                        <select id="lines_{{ $i }}_product_id" name="lines[{{ $i }}][product_id]"
                                class="form-select form-select-sm purchase-product-select">
                            <option value="">— Select —</option>
                            @foreach($products as $product)
                                @php
                                    $variantData = $product->variants->map(fn($v) => [
                                        'id'             => (int) $v->id,
                                        'name'           => $v->name,
                                        'sku'            => $v->sku,
                                        'barcode'        => $v->barcode,
                                        'purchase_price' => (string) ($v->purchase_price ?? 0),
                                        'selling_price'  => (string) ($v->selling_price ?? 0),
                                        'is_default'     => (bool) $v->is_default,
                                        'is_active'      => (bool) $v->is_active,
                                    ])->values();
                                @endphp
                                <option value="{{ $product->id }}"
                                        data-purchase-price="{{ $product->default_purchase_price ?? 0 }}"
                                        data-unit-code="{{ $product->unit?->code }}"
                                        data-unit-type="{{ $product->unit?->unit_type ?? 'quantity' }}"
                                        data-requires-batch="{{ $product->requires_batch ? 1 : 0 }}"
                                        data-has-expiry="{{ $product->has_expiry ? 1 : 0 }}"
                                        data-variants="{{ $variantData->toJson() }}"
                                        @selected(old("lines.$i.product_id") == $product->id)>
                                    {{ $product->name }}{{ $product->sku ? ' — ' . $product->sku : '' }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <label for="lines_{{ $i }}_product_variant_id" class="visually-hidden">Variant line {{ $i + 1 }}</label>
                        <select id="lines_{{ $i }}_product_variant_id" name="lines[{{ $i }}][product_variant_id]"
                                class="form-select form-select-sm purchase-variant-select"
                                data-selected="{{ old("lines.$i.product_variant_id") }}">
                            <option value="">—</option>
                        </select>
                    </td>

                    @if($showBatch)
                        <td>
                            <label for="lines_{{ $i }}_batch_no" class="visually-hidden">Batch number line {{ $i + 1 }}</label>
                            <input id="lines_{{ $i }}_batch_no" type="text" name="lines[{{ $i }}][batch_no]"
                                   class="form-control form-control-sm purchase-batch-input" maxlength="100"
                                   value="{{ old("lines.$i.batch_no") }}">
                        </td>
                        <td>
                            <label for="lines_{{ $i }}_expiry_date" class="visually-hidden">Expiry date line {{ $i + 1 }}</label>
                            <input id="lines_{{ $i }}_expiry_date" type="date" name="lines[{{ $i }}][expiry_date]"
                                   class="form-control form-control-sm purchase-expiry-input"
                                   value="{{ old("lines.$i.expiry_date") }}">
                        </td>
                    @endif

                    <td>
                        <label for="lines_{{ $i }}_quantity" class="visually-hidden">Quantity line {{ $i + 1 }}</label>
                        <input id="lines_{{ $i }}_quantity" type="number" step="0.001" min="0"
                               name="lines[{{ $i }}][{{ $quantityField }}]"
                               class="form-control form-control-sm purchase-qty-input"
                               value="{{ old("lines.$i.$quantityField") }}">
                    </td>
                    <td>
                        <label for="lines_{{ $i }}_unit_cost" class="visually-hidden">Unit cost line {{ $i + 1 }}</label>
                        <input id="lines_{{ $i }}_unit_cost" type="number" step="0.0001" min="0"
                               name="lines[{{ $i }}][unit_cost]"
                               class="form-control form-control-sm purchase-cost-input"
                               value="{{ old("lines.$i.unit_cost", 0) }}">
                    </td>

                    @if($showDiscountTax)
                        <td>
                            <label for="lines_{{ $i }}_discount_amount" class="visually-hidden">Discount line {{ $i + 1 }}</label>
                            <input id="lines_{{ $i }}_discount_amount" type="number" step="0.01" min="0"
                                   name="lines[{{ $i }}][discount_amount]"
                                   class="form-control form-control-sm purchase-discount-input"
                                   value="{{ old("lines.$i.discount_amount", 0) }}">
                        </td>
                        <td>
                            <label for="lines_{{ $i }}_tax_amount" class="visually-hidden">Tax line {{ $i + 1 }}</label>
                            <input id="lines_{{ $i }}_tax_amount" type="number" step="0.01" min="0"
                                   name="lines[{{ $i }}][tax_amount]"
                                   class="form-control form-control-sm purchase-tax-input"
                                   value="{{ old("lines.$i.tax_amount", 0) }}">
                        </td>
                    @endif

                    @if($showNotes)
                        <td>
                            <label for="lines_{{ $i }}_notes" class="visually-hidden">Notes line {{ $i + 1 }}</label>
                            <input id="lines_{{ $i }}_notes" type="text" name="lines[{{ $i }}][notes]"
                                   class="form-control form-control-sm purchase-notes-input"
                                   value="{{ old("lines.$i.notes") }}">
                        </td>
                    @endif
                </tr>
            @endfor
            </tbody>
        </table>
    </div>

</div>{{-- /.purchase-lines-widget --}}

@push('scripts')
<script>
(function () {
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        var tokenInput = document.querySelector('input[name="_token"]');
        return tokenInput ? tokenInput.value : '';
    }

    function notify(message, type) {
        if (window.Swal) {
            Swal.fire({
                toast: true,
                position: 'top-end',
                timer: 2200,
                showConfirmButton: false,
                icon: type || 'info',
                title: message
            });
            return;
        }
        alert(message);
    }

    function parseJson(value, fallback) {
        try { return JSON.parse(value || '[]'); }
        catch (e) { return fallback || []; }
    }

    function moneyValue(value) {
        var num = Number(value || 0);
        return Number.isFinite(num) ? num : 0;
    }

    function lineIndexFromName(name) {
        var match = String(name || '').match(/lines\[(\d+)\]/);
        return match ? Number(match[1]) : 0;
    }

    function nextLineIndex(widget) {
        var maxIndex = -1;
        widget.querySelectorAll('[name^="lines["]').forEach(function (el) {
            maxIndex = Math.max(maxIndex, lineIndexFromName(el.name));
        });
        return maxIndex + 1;
    }

    function reindexRow(row, index) {
        row.querySelectorAll('[name]').forEach(function (el) {
            el.name = el.name.replace(/lines\[\d+\]/g, 'lines[' + index + ']');
        });
        row.querySelectorAll('[id]').forEach(function (el) {
            el.id = el.id.replace(/lines_\d+_/g, 'lines_' + index + '_');
        });
        row.querySelectorAll('label[for]').forEach(function (label) {
            label.setAttribute('for', label.getAttribute('for').replace(/lines_\d+_/g, 'lines_' + index + '_'));
        });
        row.querySelectorAll('[aria-label]').forEach(function (el) {
            el.setAttribute('aria-label', el.getAttribute('aria-label').replace(/line \d+/i, 'line ' + (index + 1)));
        });
    }

    function clearRow(row) {
        row.querySelectorAll('input, textarea, select').forEach(function (el) {
            if (el.tagName === 'SELECT') {
                el.value = '';
            } else if (el.type === 'number') {
                el.value = el.classList.contains('purchase-qty-input') ? '1' : '0';
            } else {
                el.value = '';
            }
        });
        var variantSelect = row.querySelector('.purchase-variant-select');
        if (variantSelect) {
            variantSelect.innerHTML = '<option value="">—</option>';
            variantSelect.dataset.selected = '';
        }
        var poLineInput = row.querySelector('.purchase-po-line-input');
        if (poLineInput) poLineInput.value = '';
    }

    function findReusableRow(widget) {
        var rows = widget.querySelectorAll('.purchase-line-row');
        for (var i = 0; i < rows.length; i++) {
            var productSelect = rows[i].querySelector('.purchase-product-select');
            if (productSelect && !productSelect.value) return rows[i];
        }
        return null;
    }

    function createRow(widget) {
        var body = widget.querySelector('.purchase-lines-body');
        var last = body ? body.querySelector('.purchase-line-row:last-child') : null;
        if (!body || !last) throw new Error('Product lines table not found.');
        var row = last.cloneNode(true);
        reindexRow(row, nextLineIndex(widget));
        clearRow(row);
        body.appendChild(row);
        return row;
    }

    function ensureRow(widget) {
        return findReusableRow(widget) || createRow(widget);
    }

    function fillVariants(row, selectedVariantId) {
        var productSelect = row.querySelector('.purchase-product-select');
        var variantSelect = row.querySelector('.purchase-variant-select');
        if (!productSelect || !variantSelect) return;

        var selectedOption = productSelect.options[productSelect.selectedIndex];
        var variants = selectedOption ? parseJson(selectedOption.dataset.variants, []) : [];

        variantSelect.innerHTML = '<option value="">—</option>';
        variants.forEach(function (variant) {
            if (variant.is_active === false) return;
            var option = document.createElement('option');
            option.value = variant.id;
            option.textContent = variant.name || variant.sku || ('Variant #' + variant.id);
            option.dataset.purchasePrice = variant.purchase_price || '0';
            option.dataset.sku = variant.sku || '';
            option.dataset.barcode = variant.barcode || '';
            variantSelect.appendChild(option);
        });

        var target = selectedVariantId ? String(selectedVariantId) : (variantSelect.dataset.selected || '');
        if (target) variantSelect.value = target;

        if (!variantSelect.value) {
            var def = variants.find(function (v) { return !!v.is_default; });
            if (def) variantSelect.value = String(def.id);
        }
    }

    function selectedBranchId() {
        var branch = document.querySelector('[name="branch_id"]');
        return branch ? branch.value : '';
    }

    function setLineFromLookup(widget, result) {
        var row = ensureRow(widget);
        var productSelect = row.querySelector('.purchase-product-select');
        var variantSelect = row.querySelector('.purchase-variant-select');
        var qtyInput      = row.querySelector('.purchase-qty-input');
        var costInput     = row.querySelector('.purchase-cost-input');
        var batchInput    = row.querySelector('.purchase-batch-input');
        var expiryInput   = row.querySelector('.purchase-expiry-input');

        if (!productSelect || !qtyInput || !costInput) {
            notify('Product line fields are missing on this page.', 'error');
            return;
        }

        productSelect.value = String(result.product_id || '');
        fillVariants(row, result.variant_id || null);
        if (variantSelect && result.variant_id) variantSelect.value = String(result.variant_id);

        qtyInput.value = result.allow_decimal ? '1.000' : '1';
        qtyInput.step  = result.allow_decimal ? '0.001' : '1';
        qtyInput.min   = '0.001';

        costInput.value = moneyValue(result.purchase_price).toFixed(4);

        if (batchInput && result.requires_batch) batchInput.placeholder = 'Required';
        if (expiryInput && result.has_expiry) expiryInput.classList.add('border-warning');

        var label = result.name || result.sku || 'Product';
        var unit  = result.unit_code ? (' ' + result.unit_code) : '';
        notify(label + unit + ' added. Enter quantity.', 'success');

        setTimeout(function () { qtyInput.focus(); qtyInput.select(); }, 50);
    }

    function lookupBarcode(widget, code) {
        var branchId = selectedBranchId();
        if (!branchId) { notify('Select branch before scanning.', 'warning'); return; }

        fetch(widget.dataset.lookupUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken()
            },
            body: JSON.stringify({ branch_id: branchId, code: code })
        })
        .then(function (response) {
            if (!response.ok) throw new Error('Lookup failed.');
            return response.json();
        })
        .then(function (result) {
            if (!result || !result.found) {
                notify(result && result.message ? result.message : 'Barcode not found.', 'warning');
                return;
            }
            setLineFromLookup(widget, result);
        })
        .catch(function (error) {
            console.error(error);
            notify('Barcode lookup failed.', 'error');
        });
    }

    function setupWidget(widget) {
        var scanner = widget.querySelector('.purchase-line-scanner');
        var button  = widget.querySelector('.purchase-line-scan-btn');
        if (!scanner || !button) return;

        // Auto-fill cost when product manually selected from dropdown
        widget.addEventListener('change', function (event) {
            if (event.target.classList.contains('purchase-product-select')) {
                var row = event.target.closest('.purchase-line-row');
                if (!row) return;
                fillVariants(row);
                var selectedOption = event.target.options[event.target.selectedIndex];
                var costInput = row.querySelector('.purchase-cost-input');
                if (selectedOption && costInput && !Number(costInput.value || 0)) {
                    costInput.value = moneyValue(selectedOption.dataset.purchasePrice).toFixed(4);
                }
            }
            if (event.target.classList.contains('purchase-variant-select')) {
                var selected  = event.target.options[event.target.selectedIndex];
                var costInput = event.target.closest('.purchase-line-row') &&
                                event.target.closest('.purchase-line-row').querySelector('.purchase-cost-input');
                if (selected && costInput && Number(selected.dataset.purchasePrice || 0) > 0) {
                    costInput.value = moneyValue(selected.dataset.purchasePrice).toFixed(4);
                }
            }
        });

        // Init variant dropdowns on any rows that have a product pre-selected (e.g. old() values)
        widget.querySelectorAll('.purchase-line-row').forEach(function (row) {
            fillVariants(row);
        });

        function handleScan() {
            var code = scanner.value.trim();
            if (!code) { scanner.focus(); return; }
            scanner.value = '';
            lookupBarcode(widget, code);
        }

        scanner.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); handleScan(); }
        });
        button.addEventListener('click', handleScan);

        // Disable blank rows on submit so controller only sees filled lines
        var form = widget.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                widget.querySelectorAll('.purchase-line-row').forEach(function (row) {
                    var productSelect = row.querySelector('.purchase-product-select');
                    if (!productSelect || productSelect.value) return;
                    row.querySelectorAll('input, textarea, select').forEach(function (el) {
                        el.disabled = true;
                    });
                });
            });
        }

        // Auto-focus scanner on page load
        setTimeout(function () { scanner.focus(); }, 200);
    }

    document.querySelectorAll('.purchase-lines-widget').forEach(setupWidget);
})();
</script>
@endpush
