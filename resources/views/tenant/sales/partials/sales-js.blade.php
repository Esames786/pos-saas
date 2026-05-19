{{-- Shared sales JS: totals, branch pricing, tax auto-calc, barcode search --}}
<script>
(function () {
    let lineIndex = document.querySelectorAll('.line-row').length;
    let payIndex  = document.querySelectorAll('.payment-row').length;

    function fmt(n) { return parseFloat(n || 0).toFixed(2); }

    function getBranchId() {
        return (document.getElementById('branch_id') || {}).value || '';
    }

    function autoCalcTax(row) {
        const productSel = row.querySelector('.product-select');
        const opt = productSel?.options[productSel.selectedIndex];
        if (!opt || !opt.value) return;
        if (opt.dataset.taxable !== '1') return;
        const taxRate = parseFloat(opt.dataset.taxRate || 0);
        if (taxRate <= 0) return;
        const qty  = parseFloat(row.querySelector('.qty-input')?.value || 0);
        const price = parseFloat(row.querySelector('.price-input')?.value || 0);
        const disc = parseFloat(row.querySelector('.disc-input')?.value || 0);
        const taxInput = row.querySelector('.tax-input');
        if (taxInput) taxInput.value = fmt(Math.max(qty * price - disc, 0) * taxRate / 100);
    }

    function calcLineTotals() {
        let subtotal = 0, totalDisc = 0, totalTax = 0;
        document.querySelectorAll('.line-row').forEach(row => {
            const qty   = parseFloat(row.querySelector('.qty-input')?.value || 0);
            const price = parseFloat(row.querySelector('.price-input')?.value || 0);
            const disc  = parseFloat(row.querySelector('.disc-input')?.value || 0);
            const tax   = parseFloat(row.querySelector('.tax-input')?.value || 0);
            const lt    = qty * price - disc + tax;
            const el    = row.querySelector('.line-total');
            if (el) el.textContent = fmt(lt);
            subtotal  += qty * price;
            totalDisc += disc;
            totalTax  += tax;
        });
        const discType = (document.getElementById('discount_type') || {}).value || 'none';
        const discVal  = parseFloat((document.getElementById('discount_value') || {}).value || 0);
        let orderDisc  = 0;
        if (discType === 'fixed')   orderDisc = discVal;
        if (discType === 'percent') orderDisc = subtotal * discVal / 100;
        const discTotal  = totalDisc + orderDisc;
        const grandTotal = Math.max(subtotal - discTotal + totalTax, 0);
        const paidTotal  = Array.from(document.querySelectorAll('.pay-amount'))
            .reduce((s, el) => s + (parseFloat(el.value) || 0), 0);
        const change = Math.max(paidTotal - grandTotal, 0);
        const setEl = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
        setEl('summary-subtotal', fmt(subtotal));
        setEl('summary-discount', fmt(discTotal));
        setEl('summary-tax',      fmt(totalTax));
        setEl('summary-grand',    fmt(grandTotal));
        setEl('summary-paid',     fmt(paidTotal));
        setEl('summary-change',   fmt(change));
    }

    function resolvePriceFromOpt(opt, branchId) {
        const bp = JSON.parse(opt.dataset.branchPrices || '{}');
        return (branchId && bp[branchId]) ? bp[branchId] : (opt.dataset.price || 0);
    }

    function wireRow(row) {
        row.querySelectorAll('input').forEach(el => {
            el.addEventListener('input', () => { autoCalcTax(row); calcLineTotals(); });
        });
        const productSel = row.querySelector('.product-select');
        const variantSel = row.querySelector('.variant-select');
        const priceInput = row.querySelector('.price-input');

        if (productSel) {
            productSel.addEventListener('change', function () {
                const opt = this.options[this.selectedIndex];
                priceInput.value = fmt(resolvePriceFromOpt(opt, getBranchId()));
                if (variantSel) {
                    variantSel.innerHTML = '<option value="">—</option>';
                    try {
                        JSON.parse(opt.dataset.variants || '[]').forEach(v => {
                            const o = document.createElement('option');
                            o.value = v.id;
                            o.textContent = v.name;
                            o.dataset.price = v.selling_price || 0;
                            o.dataset.branchPrices = JSON.stringify(v.branch_prices || {});
                            variantSel.appendChild(o);
                        });
                    } catch (e) {}
                }
                autoCalcTax(row);
                calcLineTotals();
            });
        }

        if (variantSel) {
            variantSel.addEventListener('change', function () {
                const opt = this.options[this.selectedIndex];
                if (opt.value) priceInput.value = fmt(resolvePriceFromOpt(opt, getBranchId()));
                autoCalcTax(row);
                calcLineTotals();
            });
        }

        const removeBtn = row.querySelector('.remove-line');
        if (removeBtn) removeBtn.addEventListener('click', () => { row.remove(); calcLineTotals(); });
    }

    function wirePayment(row) {
        row.querySelectorAll('input').forEach(el => el.addEventListener('input', calcLineTotals));
        const methodSel = row.querySelector('.method-select');
        if (methodSel) {
            methodSel.addEventListener('change', function () {
                const type = this.options[this.selectedIndex].dataset.type;
                const twWrap = row.querySelector('.cash-tendered-wrap');
                const refWrap = row.querySelector('.ref-wrap');
                if (twWrap) twWrap.style.display = type === 'cash' ? '' : 'none';
                if (refWrap) refWrap.style.display = ['card','bank_transfer','cheque','wallet'].includes(type) ? '' : 'none';
            });
        }
        const removeBtn = row.querySelector('.remove-payment');
        if (removeBtn) removeBtn.addEventListener('click', () => { row.remove(); calcLineTotals(); });
    }

    // ── branch change: re-price all lines ────────────────────────────────────
    const branchSel = document.getElementById('branch_id');
    if (branchSel) {
        branchSel.addEventListener('change', function () {
            const branchId = this.value;
            document.querySelectorAll('.line-row').forEach(row => {
                const pSel = row.querySelector('.product-select');
                const vSel = row.querySelector('.variant-select');
                const pInp = row.querySelector('.price-input');
                if (!pSel || !pSel.value || !pInp) return;
                const pOpt = pSel.options[pSel.selectedIndex];
                const vOpt = vSel ? vSel.options[vSel.selectedIndex] : null;
                if (vOpt && vOpt.value) {
                    pInp.value = fmt(resolvePriceFromOpt(vOpt, branchId));
                } else {
                    pInp.value = fmt(resolvePriceFromOpt(pOpt, branchId));
                }
            });
            calcLineTotals();
        });
    }

    // ── barcode/product search ────────────────────────────────────────────────
    const barcodeIndex = {};
    document.querySelectorAll('.product-select option[value]').forEach(opt => {
        if (!opt.value) return;
        const barcodes = (opt.dataset.barcodes || '').split(',').map(s => s.trim()).filter(Boolean);
        barcodes.forEach(bc => { barcodeIndex[bc.toLowerCase()] = opt.value; });
        const text = opt.textContent.trim().toLowerCase();
        if (text) barcodeIndex[text] = opt.value;
    });

    function addProductToLine(productId) {
        let targetRow = null;
        document.querySelectorAll('.line-row').forEach(row => {
            if (!targetRow && !row.querySelector('.product-select')?.value) targetRow = row;
        });
        if (!targetRow) {
            const addBtn = document.getElementById('add-line');
            if (addBtn) addBtn.click();
            targetRow = document.querySelector('.lines-body .line-row:last-child')
                     || document.querySelector('#lines-body .line-row:last-child');
        }
        if (!targetRow) return;
        const sel = targetRow.querySelector('.product-select');
        if (sel) {
            sel.value = productId;
            sel.dispatchEvent(new Event('change'));
            const qty = targetRow.querySelector('.qty-input');
            if (qty && (!qty.value || parseFloat(qty.value) <= 0)) qty.value = 1;
        }
        calcLineTotals();
    }

    const barcodeInput = document.getElementById('barcode-search');
    if (barcodeInput) {
        barcodeInput.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const q = this.value.trim().toLowerCase();
            if (!q) return;
            const directId = barcodeIndex[q];
            if (directId) {
                addProductToLine(directId);
                this.value = '';
                return;
            }
            const partialId = Object.entries(barcodeIndex).find(([k]) => k.includes(q))?.[1];
            if (partialId) {
                addProductToLine(partialId);
                this.value = '';
            }
        });
    }

    // ── add line button ───────────────────────────────────────────────────────
    const addLineBtn = document.getElementById('add-line');
    if (addLineBtn) {
        addLineBtn.addEventListener('click', function () {
            const tmpl = document.querySelector('.line-row').cloneNode(true);
            tmpl.querySelectorAll('select').forEach(el => el.selectedIndex = 0);
            tmpl.querySelectorAll('input').forEach(el => {
                el.value = el.classList.contains('qty-input') ? 1 : 0;
            });
            const lt = tmpl.querySelector('.line-total');
            if (lt) lt.textContent = '0.00';
            if (tmpl.querySelector('.variant-select')) {
                tmpl.querySelector('.variant-select').innerHTML = '<option value="">—</option>';
            }
            tmpl.querySelectorAll('[name]').forEach(el => {
                el.name = el.name.replace(/\[\d+\]/, '[' + lineIndex + ']');
            });
            tmpl.querySelectorAll('[aria-label]').forEach(el => {
                el.setAttribute('aria-label', el.getAttribute('aria-label').replace(/\d+$/, lineIndex + 1));
            });
            const body = document.querySelector('.lines-body') || document.getElementById('lines-body');
            if (body) body.appendChild(tmpl);
            wireRow(tmpl);
            lineIndex++;
        });
    }

    // ── add payment button ────────────────────────────────────────────────────
    const addPayBtn = document.getElementById('add-payment');
    if (addPayBtn) {
        addPayBtn.addEventListener('click', function () {
            const tmpl = document.querySelector('.payment-row').cloneNode(true);
            tmpl.querySelectorAll('select').forEach(el => el.selectedIndex = 0);
            tmpl.querySelectorAll('input').forEach(el => el.value = '');
            const tw = tmpl.querySelector('.cash-tendered-wrap');
            const rw = tmpl.querySelector('.ref-wrap');
            if (tw) tw.style.display = 'none';
            if (rw) rw.style.display = 'none';
            tmpl.querySelectorAll('[name]').forEach(el => {
                el.name = el.name.replace(/\[\d+\]/, '[' + payIndex + ']');
            });
            tmpl.querySelectorAll('[aria-label]').forEach(el => {
                el.setAttribute('aria-label', el.getAttribute('aria-label').replace(/\d+$/, payIndex + 1));
            });
            const body = document.getElementById('payments-body');
            if (body) body.appendChild(tmpl);
            wirePayment(tmpl);
            payIndex++;
        });
    }

    // ── discount ─────────────────────────────────────────────────────────────
    const discType = document.getElementById('discount_type');
    const discVal  = document.getElementById('discount_value');
    if (discType) discType.addEventListener('change', calcLineTotals);
    if (discVal)  discVal.addEventListener('input', calcLineTotals);

    // ── init ─────────────────────────────────────────────────────────────────
    document.querySelectorAll('.line-row').forEach(wireRow);
    document.querySelectorAll('.payment-row').forEach(wirePayment);
    calcLineTotals();
})();
</script>
