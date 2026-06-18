@php
    $quantityField   = $quantityField   ?? 'quantity_ordered';
    $showBatch       = $showBatch       ?? false;
    $showDiscountTax = $showDiscountTax ?? true;
    $showNotes       = $showNotes       ?? false;
    $prefillLines    = $prefillLines    ?? [];

    // Rows to render: re-submitted values (validation error) win, then PO prefill,
    // otherwise a few blank rows for manual entry.
    $oldLines = old('lines');
    if ($oldLines) {
        $rows = array_values($oldLines);
    } elseif (!empty($prefillLines)) {
        $rows = array_values($prefillLines);
    } else {
        $rows = array_fill(0, 5, []);
    }
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

                <th scope="col" class="text-end" style="width:48px;"><span class="visually-hidden">Actions</span></th>
            </tr>
            </thead>
            <tbody class="purchase-lines-body">
            @foreach($rows as $i => $row)
                @php
                    $rowProduct = $row['product_id'] ?? '';
                    $rowVariant = $row['product_variant_id'] ?? '';
                    $rowQty     = $row[$quantityField] ?? ($row['quantity'] ?? '');
                    $rowCost    = $row['unit_cost'] ?? 0;
                    $rowBatch   = $row['batch_no'] ?? '';
                    $rowExpiry  = $row['expiry_date'] ?? '';
                    $rowPoLine  = $row['purchase_order_line_id'] ?? '';
                    $rowDisc    = $row['discount_amount'] ?? 0;
                    $rowTax     = $row['tax_amount'] ?? 0;
                    $rowNotes   = $row['notes'] ?? '';
                @endphp
                <tr class="purchase-line-row">
                    <td>
                        <input type="hidden" name="lines[{{ $i }}][purchase_order_line_id]"
                               class="purchase-po-line-input" value="{{ $rowPoLine }}">
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
                                        @selected($rowProduct == $product->id)>
                                    {{ $product->name }}{{ $product->sku ? ' — ' . $product->sku : '' }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <label for="lines_{{ $i }}_product_variant_id" class="visually-hidden">Variant line {{ $i + 1 }}</label>
                        <select id="lines_{{ $i }}_product_variant_id" name="lines[{{ $i }}][product_variant_id]"
                                class="form-select form-select-sm purchase-variant-select"
                                data-selected="{{ $rowVariant }}">
                            <option value="">—</option>
                        </select>
                        <span class="purchase-variant-label text-muted small d-none"></span>
                    </td>

                    @if($showBatch)
                        <td>
                            <label for="lines_{{ $i }}_batch_no" class="visually-hidden">Batch number line {{ $i + 1 }}</label>
                            <input id="lines_{{ $i }}_batch_no" type="text" name="lines[{{ $i }}][batch_no]"
                                   class="form-control form-control-sm purchase-batch-input" maxlength="100"
                                   value="{{ $rowBatch }}">
                            <span class="purchase-batch-na text-muted small d-none">Not tracked</span>
                        </td>
                        <td>
                            <label for="lines_{{ $i }}_expiry_date" class="visually-hidden">Expiry date line {{ $i + 1 }}</label>
                            <input id="lines_{{ $i }}_expiry_date" type="date" name="lines[{{ $i }}][expiry_date]"
                                   class="form-control form-control-sm purchase-expiry-input"
                                   value="{{ $rowExpiry }}">
                            <span class="purchase-expiry-na text-muted small d-none">Not tracked</span>
                        </td>
                    @endif

                    <td>
                        <label for="lines_{{ $i }}_quantity" class="visually-hidden">Quantity line {{ $i + 1 }}</label>
                        <input id="lines_{{ $i }}_quantity" type="number" step="0.001" min="0"
                               name="lines[{{ $i }}][{{ $quantityField }}]"
                               class="form-control form-control-sm purchase-qty-input"
                               value="{{ $rowQty }}">
                    </td>
                    <td>
                        <label for="lines_{{ $i }}_unit_cost" class="visually-hidden">Unit cost line {{ $i + 1 }}</label>
                        <input id="lines_{{ $i }}_unit_cost" type="number" step="0.0001" min="0"
                               name="lines[{{ $i }}][unit_cost]"
                               class="form-control form-control-sm purchase-cost-input"
                               value="{{ $rowCost }}">
                    </td>

                    @if($showDiscountTax)
                        <td>
                            <label for="lines_{{ $i }}_discount_amount" class="visually-hidden">Discount line {{ $i + 1 }}</label>
                            <input id="lines_{{ $i }}_discount_amount" type="number" step="0.01" min="0"
                                   name="lines[{{ $i }}][discount_amount]"
                                   class="form-control form-control-sm purchase-discount-input"
                                   value="{{ $rowDisc }}">
                        </td>
                        <td>
                            <label for="lines_{{ $i }}_tax_amount" class="visually-hidden">Tax line {{ $i + 1 }}</label>
                            <input id="lines_{{ $i }}_tax_amount" type="number" step="0.01" min="0"
                                   name="lines[{{ $i }}][tax_amount]"
                                   class="form-control form-control-sm purchase-tax-input"
                                   value="{{ $rowTax }}">
                        </td>
                    @endif

                    @if($showNotes)
                        <td>
                            <label for="lines_{{ $i }}_notes" class="visually-hidden">Notes line {{ $i + 1 }}</label>
                            <input id="lines_{{ $i }}_notes" type="text" name="lines[{{ $i }}][notes]"
                                   class="form-control form-control-sm purchase-notes-input"
                                   value="{{ $rowNotes }}">
                        </td>
                    @endif

                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-danger purchase-line-remove"
                                title="Remove line" aria-label="Remove line {{ $i + 1 }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                                <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3h11V2h-11v1z"/>
                            </svg>
                        </button>
                    </td>
                </tr>
            @endforeach
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
        console.warn(message);
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
            if (el.classList.contains('purchase-po-line-input')) { el.value = ''; el.disabled = false; return; }
            if (el.tagName === 'SELECT') {
                el.value = '';
            } else if (el.type === 'number') {
                el.value = el.classList.contains('purchase-qty-input') ? '1' : '0';
                el.disabled = false;
            } else {
                el.value = '';
                el.disabled = false;
            }
        });
        var variantSelect = row.querySelector('.purchase-variant-select');
        if (variantSelect) {
            variantSelect.innerHTML = '<option value="">—</option>';
            variantSelect.dataset.selected = '';
            variantSelect.classList.remove('d-none');
        }
        var variantLabel = row.querySelector('.purchase-variant-label');
        if (variantLabel) variantLabel.classList.add('d-none');
        // reset batch/expiry display
        applyBatchExpiry(row);
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

    // Variant UX: hide the dropdown when a product has only one (default) variant,
    // show a muted label instead, but keep the value submitting via the select.
    function applyVariantDisplay(row, variants) {
        var variantSelect = row.querySelector('.purchase-variant-select');
        var variantLabel  = row.querySelector('.purchase-variant-label');
        if (!variantSelect) return;

        var active = (variants || []).filter(function (v) { return v.is_active !== false; });

        if (active.length <= 1) {
            // single/default variant — auto-select and simplify
            variantSelect.classList.add('d-none');
            if (variantLabel) {
                var only = active[0];
                variantLabel.textContent = only ? (only.name || only.sku || 'Default') : '—';
                variantLabel.classList.remove('d-none');
            }
        } else {
            variantSelect.classList.remove('d-none');
            if (variantLabel) variantLabel.classList.add('d-none');
        }
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

        applyVariantDisplay(row, variants);
    }

    // Conditional Batch/Expiry: only relevant on GRN (batch inputs present).
    function applyBatchExpiry(row) {
        var productSelect = row.querySelector('.purchase-product-select');
        var batchInput    = row.querySelector('.purchase-batch-input');
        var expiryInput   = row.querySelector('.purchase-expiry-input');
        if (!batchInput && !expiryInput) return; // PO has no batch columns

        var opt = productSelect ? productSelect.options[productSelect.selectedIndex] : null;
        var requiresBatch = !!(opt && Number(opt.dataset.requiresBatch) === 1);
        var hasExpiry     = !!(opt && Number(opt.dataset.hasExpiry) === 1);

        if (batchInput) {
            var batchNa = row.querySelector('.purchase-batch-na');
            if (requiresBatch) {
                batchInput.disabled = false;
                batchInput.classList.remove('d-none');
                batchInput.classList.add('border-warning');
                batchInput.placeholder = 'Required';
                if (batchNa) batchNa.classList.add('d-none');
            } else {
                batchInput.value = '';
                batchInput.disabled = true;
                batchInput.classList.add('d-none');
                batchInput.classList.remove('border-warning');
                if (batchNa) batchNa.classList.remove('d-none');
            }
        }

        if (expiryInput) {
            var expNa = row.querySelector('.purchase-expiry-na');
            if (hasExpiry) {
                expiryInput.disabled = false;
                expiryInput.classList.remove('d-none');
                expiryInput.classList.add('border-warning');
                if (expNa) expNa.classList.add('d-none');
            } else {
                expiryInput.value = '';
                expiryInput.disabled = true;
                expiryInput.classList.add('d-none');
                expiryInput.classList.remove('border-warning');
                if (expNa) expNa.classList.remove('d-none');
            }
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

        if (!productSelect || !qtyInput || !costInput) {
            notify('Product line fields are missing on this page.', 'error');
            return;
        }

        productSelect.value = String(result.product_id || '');
        fillVariants(row, result.variant_id || null);
        applyBatchExpiry(row);
        if (variantSelect && result.variant_id) variantSelect.value = String(result.variant_id);

        qtyInput.value = result.allow_decimal ? '1.000' : '1';
        qtyInput.step  = result.allow_decimal ? '0.001' : '1';
        qtyInput.min   = '0.001';

        costInput.value = moneyValue(result.purchase_price).toFixed(4);

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

    function rowCount(widget) {
        return widget.querySelectorAll('.purchase-line-row').length;
    }

    function setupWidget(widget) {
        var scanner = widget.querySelector('.purchase-line-scanner');
        var button  = widget.querySelector('.purchase-line-scan-btn');

        // Product change → variants + cost + conditional batch/expiry.
        widget.addEventListener('change', function (event) {
            if (event.target.classList.contains('purchase-product-select')) {
                var row = event.target.closest('.purchase-line-row');
                if (!row) return;
                fillVariants(row);
                applyBatchExpiry(row);
                var selectedOption = event.target.options[event.target.selectedIndex];
                var costInput = row.querySelector('.purchase-cost-input');
                if (selectedOption && costInput && !Number(costInput.value || 0)) {
                    costInput.value = moneyValue(selectedOption.dataset.purchasePrice).toFixed(4);
                }
            }
            if (event.target.classList.contains('purchase-variant-select')) {
                var selected  = event.target.options[event.target.selectedIndex];
                var costInput2 = event.target.closest('.purchase-line-row') &&
                                event.target.closest('.purchase-line-row').querySelector('.purchase-cost-input');
                if (selected && costInput2 && Number(selected.dataset.purchasePrice || 0) > 0) {
                    costInput2.value = moneyValue(selected.dataset.purchasePrice).toFixed(4);
                }
            }
        });

        // Remove-row button (delegated). Keep at least one row.
        widget.addEventListener('click', function (event) {
            var removeBtn = event.target.closest('.purchase-line-remove');
            if (!removeBtn) return;
            var row = removeBtn.closest('.purchase-line-row');
            if (!row) return;
            if (rowCount(widget) > 1) {
                row.remove();
            } else {
                clearRow(row);
                notify('At least one product line is required.', 'info');
            }
        });

        // Init each existing row (prefill / old values): variants + batch/expiry.
        widget.querySelectorAll('.purchase-line-row').forEach(function (row) {
            fillVariants(row);
            applyBatchExpiry(row);
        });

        if (scanner && button) {
            (function () {
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
                setTimeout(function () { scanner.focus(); }, 200);
            })();
        }

        // Disable blank rows on submit so the controller only sees filled lines.
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
    }

    document.querySelectorAll('.purchase-lines-widget').forEach(setupWidget);
})();
</script>
@endpush
