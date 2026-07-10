@php
    $quantityField   = $quantityField   ?? 'quantity_ordered';
    $showBatch       = $showBatch       ?? false;
    $showDiscountTax = $showDiscountTax ?? true;
    $showNotes       = $showNotes       ?? false;
    $prefillLines    = $prefillLines    ?? [];
    $stockEffect     = $stockEffect     ?? '';          // note shown under the table
    $products        = $products        ?? collect();   // used only to hydrate prefilled rows

    $oldLines = old('lines');
    if ($oldLines) {
        $rows = array_values($oldLines);
    } elseif (!empty($prefillLines)) {
        $rows = array_values($prefillLines);
    } else {
        $rows = array_fill(0, 3, []);
    }

    // Index prefilled/old products so we can hydrate their picker option + variants.
    $productById = $products instanceof \Illuminate\Support\Collection ? $products->keyBy('id') : collect();

    $colspan = 4 + ($showBatch ? 2 : 0) + ($showDiscountTax ? 2 : 0) + ($showNotes ? 1 : 0) + 1; // Product,Variant,Stock,Qty,UnitCost + extras + actions
@endphp

<div class="purchase-lines-widget"
     data-quantity-field="{{ $quantityField }}"
     data-show-batch="{{ $showBatch ? 1 : 0 }}"
     data-lookup-url="{{ url('/api/catalog/barcode/lookup') }}"
     data-search-url="{{ url('/ajax/products') }}">

    {{-- Header-first gate: product entry is locked until Supplier + Branch are chosen. --}}
    <div class="purchase-gate-warn alert alert-warning d-flex align-items-center gap-2 py-2 px-3">
        <i class="ti ti-lock"></i>
        <span>Select <strong>Supplier</strong> and <strong>Branch</strong> above first — then you can search and add products.</span>
    </div>

    <fieldset class="purchase-lines-fieldset" disabled>
    <div class="border rounded p-3 mb-3 bg-light">
        <label class="form-label small fw-semibold" for="purchase-line-scanner-{{ $quantityField }}">
            <i class="ti ti-barcode me-1"></i>Scan Barcode / SKU
        </label>
        <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="ti ti-barcode"></i></span>
            <input type="text" id="purchase-line-scanner-{{ $quantityField }}"
                   class="form-control purchase-line-scanner"
                   placeholder="Scan barcode or type SKU then press Enter" autocomplete="off">
            <button class="btn btn-outline-primary purchase-line-scan-btn" type="button">Add</button>
        </div>
        <div class="form-text">Scan a barcode/SKU to add a line, or use the <strong>Search product</strong> box on each row.</div>
    </div>

    <div class="table-responsive">
        <table class="table table-nowrap align-middle purchase-lines-table">
            <caption class="visually-hidden">{{ $caption ?? 'Product lines' }}</caption>
            <thead class="table-light">
            <tr>
                <th scope="col" style="min-width:240px;">Product</th>
                <th scope="col">Variant</th>
                <th scope="col" class="text-end">Current Stock</th>
                @if($showBatch)<th scope="col">Batch No</th><th scope="col">Expiry</th>@endif
                <th scope="col" class="text-end">Quantity</th>
                <th scope="col" class="text-end">Unit Cost</th>
                @if($showDiscountTax)<th scope="col" class="text-end">Discount</th><th scope="col" class="text-end">Tax</th>@endif
                @if($showNotes)<th scope="col">Notes</th>@endif
                <th scope="col" class="text-end">Line Total</th>
                <th scope="col" class="text-end" style="width:44px;"><span class="visually-hidden">Actions</span></th>
            </tr>
            </thead>
            <tbody class="purchase-lines-body">
            @foreach($rows as $i => $row)
                @php
                    $rowProduct = $row['product_id'] ?? '';
                    $rowVariant = $row['product_variant_id'] ?? '';
                    $rowQty     = $row[$quantityField] ?? ($row['quantity'] ?? '');
                    $rowCost    = $row['unit_cost'] ?? '';
                    $rowBatch   = $row['batch_no'] ?? '';
                    $rowExpiry  = $row['expiry_date'] ?? '';
                    $rowPoLine  = $row['purchase_order_line_id'] ?? '';
                    $rowDisc    = $row['discount_amount'] ?? 0;
                    $rowTax     = $row['tax_amount'] ?? 0;
                    $rowNotes   = $row['notes'] ?? '';
                    $pf         = $rowProduct ? $productById->get($rowProduct) : null;
                    $pfVariants = $pf ? $pf->variants->map(fn($v) => ['id'=>(int)$v->id,'name'=>$v->name,'sku'=>$v->sku,'purchase_price'=>(string)($v->purchase_price ?? 0),'is_default'=>(bool)$v->is_default,'is_active'=>(bool)$v->is_active])->values() : collect();
                @endphp
                <tr class="purchase-line-row">
                    <td>
                        <input type="hidden" name="lines[{{ $i }}][purchase_order_line_id]" class="purchase-po-line-input" value="{{ $rowPoLine }}">
                        <select name="lines[{{ $i }}][product_id]" class="form-select form-select-sm purchase-product-select"
                                data-requires-batch="{{ $pf && $pf->requires_batch ? 1 : 0 }}"
                                data-has-expiry="{{ $pf && $pf->has_expiry ? 1 : 0 }}"
                                data-variants='@json($pfVariants)'>
                            <option value="">— Search product (name / SKU / barcode) —</option>
                            @if($pf)
                                <option value="{{ $pf->id }}" selected>{{ $pf->sku ? $pf->sku.' — ' : '' }}{{ $pf->name }}</option>
                            @endif
                        </select>
                    </td>
                    <td>
                        <select name="lines[{{ $i }}][product_variant_id]" class="form-select form-select-sm purchase-variant-select" data-selected="{{ $rowVariant }}">
                            <option value="">—</option>
                        </select>
                        <span class="purchase-variant-label text-muted small d-none"></span>
                    </td>
                    <td class="text-end"><span class="purchase-stock-badge badge bg-light text-muted border">—</span></td>

                    @if($showBatch)
                        <td>
                            <input type="text" name="lines[{{ $i }}][batch_no]" class="form-control form-control-sm purchase-batch-input" maxlength="100" value="{{ $rowBatch }}">
                            <span class="purchase-batch-na text-muted small d-none">Not tracked</span>
                        </td>
                        <td>
                            <input type="date" name="lines[{{ $i }}][expiry_date]" class="form-control form-control-sm purchase-expiry-input" value="{{ $rowExpiry }}">
                            <span class="purchase-expiry-na text-muted small d-none">Not tracked</span>
                        </td>
                    @endif

                    <td><input type="number" step="0.001" min="0" name="lines[{{ $i }}][{{ $quantityField }}]" class="form-control form-control-sm text-end purchase-qty-input" value="{{ $rowQty }}"></td>
                    <td><input type="number" step="0.0001" min="0" name="lines[{{ $i }}][unit_cost]" class="form-control form-control-sm text-end purchase-cost-input" value="{{ $rowCost }}"></td>

                    @if($showDiscountTax)
                        <td><input type="number" step="0.01" min="0" name="lines[{{ $i }}][discount_amount]" class="form-control form-control-sm text-end purchase-discount-input" value="{{ $rowDisc }}"></td>
                        <td><input type="number" step="0.01" min="0" name="lines[{{ $i }}][tax_amount]" class="form-control form-control-sm text-end purchase-tax-input" value="{{ $rowTax }}"></td>
                    @endif

                    @if($showNotes)
                        <td><input type="text" name="lines[{{ $i }}][notes]" class="form-control form-control-sm purchase-notes-input" value="{{ $rowNotes }}"></td>
                    @endif

                    <td class="text-end"><span class="purchase-line-total fw-semibold">0.00</span></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-danger purchase-line-remove" title="Remove line"><i class="ti ti-trash"></i></button>
                    </td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light">
                <tr class="fw-semibold">
                    <td colspan="{{ $showBatch ? 5 : 3 }}" class="text-end">
                        <span class="purchase-summary-count text-muted small">0 items · qty 0</span>
                    </td>
                    <td class="text-end">Subtotal</td>
                    <td class="text-end"><span class="purchase-summary-subtotal">0.00</span></td>
                    @if($showDiscountTax)
                        <td class="text-end"><span class="purchase-summary-discount">0.00</span></td>
                        <td class="text-end"><span class="purchase-summary-tax">0.00</span></td>
                    @endif
                    @if($showNotes)<td></td>@endif
                    <td class="text-end"><span class="purchase-summary-grand text-primary">0.00</span></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span class="badge bg-light text-muted border d-none d-md-inline me-2">Alt+N = add line · Enter: Qty → Cost → next line</span>
        <button type="button" class="btn btn-sm btn-outline-primary purchase-add-line"><i class="ti ti-plus me-1"></i>Add Product Line</button>
        @if($stockEffect)
            <div class="small text-muted"><i class="ti ti-info-circle me-1"></i>{{ $stockEffect }}</div>
        @endif
    </div>
    </fieldset>
</div>{{-- /.purchase-lines-widget --}}

@push('scripts')
<script>
(function () {
    var $ = window.jQuery;

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        var t = document.querySelector('input[name="_token"]');
        return t ? t.value : '';
    }
    function notify(msg, type) {
        if (window.Swal) { Swal.fire({ toast:true, position:'top-end', timer:2200, showConfirmButton:false, icon:type||'info', title:msg }); return; }
        console.warn(msg);
    }
    function money(v){ var n = Number(v||0); return Number.isFinite(n)?n:0; }
    function parseJson(v, f){ try { return JSON.parse(v||'[]'); } catch(e){ return f||[]; } }
    function lineIndex(name){ var m = String(name||'').match(/lines\[(\d+)\]/); return m?Number(m[1]):0; }
    function nextIndex(widget){ var mx=-1; widget.querySelectorAll('[name^="lines["]').forEach(function(el){ mx=Math.max(mx,lineIndex(el.name)); }); return mx+1; }

    function selectedBranchId(){ var b=document.querySelector('[name="branch_id"]'); return b?b.value:''; }
    function selectedSupplierId(){ var s=document.querySelector('[name="supplier_id"]'); return s?s.value:''; }

    function reindexRow(row, index){
        row.querySelectorAll('[name]').forEach(function(el){ el.name = el.name.replace(/lines\[\d+\]/g,'lines['+index+']'); });
    }

    // ── Product picker (AJAX Select2) ──────────────────────────────────────
    function initPicker(select){
        if (!$ || !$.fn || !$.fn.select2) return;
        var $el = $(select);
        if ($el.hasClass('select2-hidden-accessible')) return;
        $el.select2({
            width: '100%',
            placeholder: 'Search product (name / SKU / barcode)',
            allowClear: true,
            ajax: {
                url: select.closest('.purchase-lines-widget').dataset.searchUrl,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return { q: params.term || '', page: params.page || 1, context: 'purchase', branch_id: selectedBranchId() };
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    return { results: data.results || [], pagination: { more: !!(data.pagination && data.pagination.more) } };
                },
                cache: true
            },
            minimumInputLength: 0,
            templateResult: function (item) {
                if (!item.id) return item.text;
                var parts = [];
                if (item.unit_code) parts.push(item.unit_code);
                if (item.purchase_price != null) parts.push('Cost ' + money(item.purchase_price).toFixed(2));
                if (item.stock_label != null) parts.push('Stock ' + item.stock_label);
                var meta = parts.length ? '<div class="small text-muted">' + parts.join(' · ') + '</div>' : '';
                return $('<div><div>' + (item.text || '') + '</div>' + meta + '</div>');
            }
        });
        // Populate the row from the selected product (ALWAYS overwrite — fixes stale cost).
        $el.on('select2:select', function (e) {
            populateFromResult(select.closest('.purchase-line-row'), e.params.data);
        });
        $el.on('select2:clear', function () {
            var row = select.closest('.purchase-line-row');
            resetRowData(row);
            recalcTotals();
        });
    }

    function populateFromResult(row, data){
        if (!row || !data) return;
        var productSelect = row.querySelector('.purchase-product-select');
        var costInput     = row.querySelector('.purchase-cost-input');
        var qtyInput      = row.querySelector('.purchase-qty-input');
        var stockBadge    = row.querySelector('.purchase-stock-badge');

        // Variants + batch/expiry data now come from the AJAX result.
        productSelect.dataset.variants     = JSON.stringify(data.variants || []);
        productSelect.dataset.requiresBatch = data.requires_batch ? '1' : '0';
        productSelect.dataset.hasExpiry     = data.has_expiry ? '1' : '0';
        fillVariants(row, data.variant_id || null);
        applyBatchExpiry(row);

        // ALWAYS set unit cost to the new product's price (never keep the previous one).
        if (costInput) {
            costInput.value = money(data.purchase_price).toFixed(4);
            if (!money(data.purchase_price)) {
                costInput.classList.add('border-warning');
                notify('No purchase price set for ' + (data.name || 'product') + ' — enter unit cost manually.', 'warning');
            } else {
                costInput.classList.remove('border-warning');
            }
        }
        if (qtyInput && !money(qtyInput.value)) { qtyInput.value = '1'; }

        if (stockBadge) {
            if (data.current_stock == null) { stockBadge.textContent = 'n/a'; stockBadge.className = 'purchase-stock-badge badge bg-light text-muted border'; }
            else {
                stockBadge.textContent = data.stock_label || (money(data.current_stock).toFixed(2));
                stockBadge.className = 'purchase-stock-badge badge ' + (money(data.current_stock) > 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis');
            }
        }
        recalcTotals();
        setTimeout(function(){ if (qtyInput) { qtyInput.focus(); qtyInput.select(); } }, 40);
    }

    function resetRowData(row){
        var costInput = row.querySelector('.purchase-cost-input');
        var stockBadge = row.querySelector('.purchase-stock-badge');
        var variantSelect = row.querySelector('.purchase-variant-select');
        if (costInput) { costInput.value = ''; costInput.classList.remove('border-warning'); }
        if (stockBadge) { stockBadge.textContent = '—'; stockBadge.className = 'purchase-stock-badge badge bg-light text-muted border'; }
        if (variantSelect) { variantSelect.innerHTML = '<option value="">—</option>'; variantSelect.dataset.selected=''; }
    }

    // ── Variant display (from data-variants) ───────────────────────────────
    function applyVariantDisplay(row, variants){
        var variantSelect = row.querySelector('.purchase-variant-select');
        var variantLabel  = row.querySelector('.purchase-variant-label');
        if (!variantSelect) return;
        var active = (variants||[]).filter(function(v){ return v.is_active !== false; });
        if (active.length <= 1) {
            variantSelect.classList.add('d-none');
            if (variantLabel) { var only=active[0]; variantLabel.textContent = only ? (only.name||only.sku||'Default') : 'Default'; variantLabel.classList.remove('d-none'); }
        } else {
            variantSelect.classList.remove('d-none');
            if (variantLabel) variantLabel.classList.add('d-none');
        }
    }
    function fillVariants(row, selectedVariantId){
        var productSelect = row.querySelector('.purchase-product-select');
        var variantSelect = row.querySelector('.purchase-variant-select');
        if (!productSelect || !variantSelect) return;
        var variants = parseJson(productSelect.dataset.variants, []);
        variantSelect.innerHTML = '<option value="">—</option>';
        variants.forEach(function(v){
            if (v.is_active === false) return;
            var o = document.createElement('option');
            o.value = v.id; o.textContent = v.name || v.sku || ('Variant #'+v.id);
            o.dataset.purchasePrice = v.purchase_price || '0';
            variantSelect.appendChild(o);
        });
        var target = selectedVariantId ? String(selectedVariantId) : (variantSelect.dataset.selected||'');
        if (target) variantSelect.value = target;
        if (!variantSelect.value){ var def = variants.find(function(v){ return !!v.is_default; }); if (def) variantSelect.value = String(def.id); }
        applyVariantDisplay(row, variants);
    }

    // ── Batch / Expiry (GRN only) ──────────────────────────────────────────
    function applyBatchExpiry(row){
        var productSelect = row.querySelector('.purchase-product-select');
        var batchInput = row.querySelector('.purchase-batch-input');
        var expiryInput = row.querySelector('.purchase-expiry-input');
        if (!batchInput && !expiryInput) return;
        var requiresBatch = Number(productSelect.dataset.requiresBatch) === 1;
        var hasExpiry = Number(productSelect.dataset.hasExpiry) === 1;
        if (batchInput){ var bn=row.querySelector('.purchase-batch-na');
            if (requiresBatch){ batchInput.disabled=false; batchInput.classList.remove('d-none'); batchInput.classList.add('border-warning'); batchInput.placeholder='Required'; if(bn) bn.classList.add('d-none'); }
            else { batchInput.value=''; batchInput.disabled=true; batchInput.classList.add('d-none'); batchInput.classList.remove('border-warning'); if(bn) bn.classList.remove('d-none'); } }
        if (expiryInput){ var en=row.querySelector('.purchase-expiry-na');
            if (hasExpiry){ expiryInput.disabled=false; expiryInput.classList.remove('d-none'); expiryInput.classList.add('border-warning'); if(en) en.classList.add('d-none'); }
            else { expiryInput.value=''; expiryInput.disabled=true; expiryInput.classList.add('d-none'); expiryInput.classList.remove('border-warning'); if(en) en.classList.remove('d-none'); } }
    }

    // ── Totals ─────────────────────────────────────────────────────────────
    function recalcTotals(){
        document.querySelectorAll('.purchase-lines-widget').forEach(function(widget){
            var items=0, totalQty=0, subtotal=0, discount=0, tax=0;
            widget.querySelectorAll('.purchase-line-row').forEach(function(row){
                var ps=row.querySelector('.purchase-product-select');
                if (!ps || !ps.value) { var lt0=row.querySelector('.purchase-line-total'); if(lt0) lt0.textContent='0.00'; return; }
                var qty=money(row.querySelector('.purchase-qty-input') && row.querySelector('.purchase-qty-input').value);
                var cost=money(row.querySelector('.purchase-cost-input') && row.querySelector('.purchase-cost-input').value);
                var disc=money(row.querySelector('.purchase-discount-input') && row.querySelector('.purchase-discount-input').value);
                var tx=money(row.querySelector('.purchase-tax-input') && row.querySelector('.purchase-tax-input').value);
                var line=(qty*cost)-disc+tx;
                items++; totalQty+=qty; subtotal+=(qty*cost); discount+=disc; tax+=tx;
                var lt=row.querySelector('.purchase-line-total'); if(lt) lt.textContent=line.toFixed(2);
            });
            var grand=subtotal-discount+tax;
            var set=function(cls,val){ var el=widget.querySelector(cls); if(el) el.textContent=val; };
            set('.purchase-summary-count', items+' item'+(items===1?'':'s')+' · qty '+ (Math.round(totalQty*1000)/1000));
            set('.purchase-summary-subtotal', subtotal.toFixed(2));
            set('.purchase-summary-discount', discount.toFixed(2));
            set('.purchase-summary-tax', tax.toFixed(2));
            set('.purchase-summary-grand', grand.toFixed(2));
        });
    }

    // ── Row add / remove ───────────────────────────────────────────────────
    function newRow(widget){
        var body = widget.querySelector('.purchase-lines-body');
        var last = body.querySelector('.purchase-line-row:last-child');
        var row = last.cloneNode(true);
        // Strip cloned Select2 artifacts before re-init.
        row.querySelectorAll('.select2-container').forEach(function(c){ c.remove(); });
        var ps = row.querySelector('.purchase-product-select');
        if (ps) { ps.classList.remove('select2-hidden-accessible'); ps.removeAttribute('data-select2-id'); ps.removeAttribute('tabindex'); ps.removeAttribute('aria-hidden');
            ps.innerHTML='<option value="">— Search product (name / SKU / barcode) —</option>'; ps.dataset.variants='[]'; ps.dataset.requiresBatch='0'; ps.dataset.hasExpiry='0'; }
        reindexRow(row, nextIndex(widget));
        // reset fields
        row.querySelectorAll('input').forEach(function(el){
            if (el.classList.contains('purchase-qty-input')) el.value='1';
            else if (el.classList.contains('purchase-cost-input') || el.classList.contains('purchase-discount-input') || el.classList.contains('purchase-tax-input')) el.value='0';
            else el.value='';
            el.disabled=false; el.classList.remove('border-warning');
        });
        var vs=row.querySelector('.purchase-variant-select'); if(vs){ vs.innerHTML='<option value="">—</option>'; vs.dataset.selected=''; }
        var vl=row.querySelector('.purchase-variant-label'); if(vl) vl.classList.add('d-none');
        var sb=row.querySelector('.purchase-stock-badge'); if(sb){ sb.textContent='—'; sb.className='purchase-stock-badge badge bg-light text-muted border'; }
        var lt=row.querySelector('.purchase-line-total'); if(lt) lt.textContent='0.00';
        body.appendChild(row);
        initPicker(ps);
        applyBatchExpiry(row);
        return row;
    }

    function rowCount(widget){ return widget.querySelectorAll('.purchase-line-row').length; }

    // ── Barcode scan → fill a reusable/new row ─────────────────────────────
    function firstEmptyRow(widget){
        var rows=widget.querySelectorAll('.purchase-line-row');
        for (var i=0;i<rows.length;i++){ var ps=rows[i].querySelector('.purchase-product-select'); if(ps && !ps.value) return rows[i]; }
        return null;
    }
    function setFromBarcode(widget, result){
        var row = firstEmptyRow(widget) || newRow(widget);
        var ps = row.querySelector('.purchase-product-select');
        // Add the selected product as an option + select it (so Select2 shows it).
        if (ps){ var exists=ps.querySelector('option[value="'+result.product_id+'"]');
            if(!exists){ var o=document.createElement('option'); o.value=String(result.product_id); o.textContent=(result.sku?result.sku+' — ':'')+(result.name||'Product'); o.selected=true; ps.appendChild(o); }
            if ($) $(ps).val(String(result.product_id)).trigger('change'); else ps.value=String(result.product_id);
        }
        populateFromResult(row, {
            variants: result.variants || [], variant_id: result.variant_id, requires_batch: result.requires_batch,
            has_expiry: result.has_expiry, purchase_price: result.purchase_price, name: result.name,
            current_stock: result.current_stock, stock_label: result.stock_label
        });
    }
    function lookupBarcode(widget, code){
        var branchId = selectedBranchId();
        if (!branchId){ notify('Select branch before scanning.', 'warning'); return; }
        fetch(widget.dataset.lookupUrl, { method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrfToken()}, body:JSON.stringify({branch_id:branchId, code:code}) })
        .then(function(r){ if(!r.ok) throw new Error('Lookup failed'); return r.json(); })
        .then(function(res){ if(!res || !res.found){ notify(res && res.message ? res.message : 'Barcode not found.', 'warning'); return; } setFromBarcode(widget, res); })
        .catch(function(e){ console.error(e); notify('Barcode lookup failed.', 'error'); });
    }

    // ── Header-first gate ──────────────────────────────────────────────────
    function headerReady(){
        var supplier = document.querySelector('[name="supplier_id"]');
        var branch = document.querySelector('[name="branch_id"]');
        // Supplier is optional on some screens (none here rendered without it) → require whichever exist.
        var okSupplier = !supplier || !!supplier.value;
        var okBranch = !branch || !!branch.value;
        return okSupplier && okBranch;
    }
    function applyGate(){
        var ready = headerReady();
        document.querySelectorAll('.purchase-lines-widget').forEach(function(widget){
            var fs = widget.querySelector('.purchase-lines-fieldset');
            var warn = widget.querySelector('.purchase-gate-warn');
            if (fs) fs.disabled = !ready;
            if (warn) warn.classList.toggle('d-none', ready);
        });
    }

    function setupWidget(widget){
        // init pickers on existing rows
        widget.querySelectorAll('.purchase-product-select').forEach(function(ps){
            // hydrate prefilled variants (data-variants already embedded server-side)
            initPicker(ps);
        });
        widget.querySelectorAll('.purchase-line-row').forEach(function(row){
            var ps = row.querySelector('.purchase-product-select');
            if (ps && ps.value) { fillVariants(row, row.querySelector('.purchase-variant-select') && row.querySelector('.purchase-variant-select').dataset.selected); applyBatchExpiry(row); }
        });

        // recalc on any qty/cost/disc/tax change
        widget.addEventListener('input', function(e){
            if (e.target.matches('.purchase-qty-input,.purchase-cost-input,.purchase-discount-input,.purchase-tax-input')) recalcTotals();
        });
        // variant change → cost from variant
        widget.addEventListener('change', function(e){
            if (e.target.classList.contains('purchase-variant-select')){
                var sel = e.target.options[e.target.selectedIndex];
                var cost = e.target.closest('.purchase-line-row').querySelector('.purchase-cost-input');
                if (sel && cost && Number(sel.dataset.purchasePrice||0)>0){ cost.value = money(sel.dataset.purchasePrice).toFixed(4); recalcTotals(); }
            }
        });
        // add line
        var addBtn = widget.querySelector('.purchase-add-line');
        if (addBtn) addBtn.addEventListener('click', function(){ if(!headerReady()){ notify('Select Supplier and Branch first.', 'warning'); return; } var r=newRow(widget); var ps=r.querySelector('.purchase-product-select'); if($ && ps) $(ps).select2('open'); });
        // remove line
        widget.addEventListener('click', function(e){
            var btn = e.target.closest('.purchase-line-remove'); if(!btn) return;
            var row = btn.closest('.purchase-line-row'); if(!row) return;
            if (rowCount(widget) > 1){ if($){ var ps=row.querySelector('.purchase-product-select'); if(ps) $(ps).select2('destroy'); } row.remove(); recalcTotals(); }
            else { notify('At least one product line is required.', 'info'); }
        });
        // scanner
        var scanner = widget.querySelector('.purchase-line-scanner');
        var scanBtn = widget.querySelector('.purchase-line-scan-btn');
        if (scanner && scanBtn){
            var handle = function(){ var code=scanner.value.trim(); if(!code){ scanner.focus(); return; } scanner.value=''; lookupBarcode(widget, code); };
            scanner.addEventListener('keydown', function(ev){ if(ev.key==='Enter'){ ev.preventDefault(); handle(); } });
            scanBtn.addEventListener('click', handle);
        }
        // PURCHASING-UX-2: keyboard flow — Alt+N adds a line; Enter walks
        // Qty → Cost → next row's product picker (scanner-first data entry).
        document.addEventListener('keydown', function(ev){
            if (ev.altKey && (ev.key === 'n' || ev.key === 'N')) { ev.preventDefault(); if (addBtn) addBtn.click(); }
        });
        widget.addEventListener('keydown', function(ev){
            if (ev.key !== 'Enter') return;
            if (ev.target.classList.contains('purchase-qty-input')) {
                ev.preventDefault();
                var cost = ev.target.closest('.purchase-line-row').querySelector('.purchase-cost-input');
                if (cost) { cost.focus(); cost.select(); }
            } else if (ev.target.classList.contains('purchase-cost-input')) {
                ev.preventDefault();
                var row = ev.target.closest('.purchase-line-row');
                var next = row.nextElementSibling;
                if (next && next.classList.contains('purchase-line-row')) {
                    var ps = next.querySelector('.purchase-product-select');
                    if ($ && ps) $(ps).select2('open');
                } else if (addBtn) { addBtn.click(); }
            }
        });
        // disable blank rows on submit
        var form = widget.closest('form');
        if (form) form.addEventListener('submit', function(){
            widget.querySelectorAll('.purchase-line-row').forEach(function(row){
                var ps=row.querySelector('.purchase-product-select'); if(!ps || ps.value) return;
                row.querySelectorAll('input,select,textarea').forEach(function(el){ el.disabled=true; });
            });
        });
    }

    // When the branch changes, re-fetch each selected row's stock for the new branch
    // (so a badge never shows another branch's figure). Uses product_id lookup.
    function refreshBranchStock(){
        var branchId = selectedBranchId();
        document.querySelectorAll('.purchase-lines-widget').forEach(function(widget){
            var url = widget.dataset.searchUrl;
            widget.querySelectorAll('.purchase-line-row').forEach(function(row){
                var ps = row.querySelector('.purchase-product-select');
                var badge = row.querySelector('.purchase-stock-badge');
                if (!ps || !ps.value || !badge) return;
                if (!branchId){ badge.textContent = '—'; badge.className = 'purchase-stock-badge badge bg-light text-muted border'; return; }
                badge.textContent = '↻'; badge.className = 'purchase-stock-badge badge bg-light text-muted border';
                fetch(url + '?rich=1&branch_id=' + encodeURIComponent(branchId) + '&product_id=' + encodeURIComponent(ps.value), { headers: { 'Accept':'application/json' } })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    var item = (d.results || [])[0];
                    if (!item || item.current_stock == null){ badge.textContent = 'n/a'; badge.className = 'purchase-stock-badge badge bg-light text-muted border'; return; }
                    badge.textContent = item.stock_label || money(item.current_stock).toFixed(2);
                    badge.className = 'purchase-stock-badge badge ' + (money(item.current_stock) > 0 ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis');
                })
                .catch(function(){ badge.textContent = '—'; });
            });
        });
    }

    document.querySelectorAll('.purchase-lines-widget').forEach(setupWidget);

    // Gate reacts to supplier/branch; branch change also refreshes selected rows' stock.
    ['supplier_id','branch_id'].forEach(function(name){
        var el = document.querySelector('[name="'+name+'"]');
        if (!el) return;
        var onChange = function(){ applyGate(); if (name === 'branch_id') refreshBranchStock(); };
        el.addEventListener('change', onChange);
        if ($ && $(el).hasClass('select2-hidden-accessible')) $(el).on('select2:select select2:clear', onChange);
    });
    applyGate();
    recalcTotals();
})();
</script>
@endpush
