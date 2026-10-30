// W3/W4 S4 — the purchase workspace's own script. The entry area (the Goods Receipt screen's) does the goods, its review
// and its Post; this script shows the parts that apply, adds the invoice and payment to the review, posts "goods
// already received" (no lines), and suggests a payment. The server checks and decides everything again.
(function () {
    var form = document.getElementById('pw-form');
    if (!form) return;
    var $ = function (id) { return document.getElementById(id); };
    var BOX = @json($supplierBox);
    var RECEIPT_URL = @json(url('/purchases/receipts/__ID__'));
    var preview = null;   // the picked receipt's invoice, as the server works it out

    function val(name) { var r = form.querySelector('input[name="' + name + '"]:checked'); return r ? r.value : ''; }
    function start() { return val('start') || 'new'; }
    function invoiceOn() { return start() === 'received' || val('record_invoice') === '1'; }
    function payOn() { return val('pay') === '1'; }
    function money(n) { return 'Rs ' + (Math.round(n * 100) / 100).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function num(id) { var el = $(id); return el ? (parseFloat((el.value || '').replace(/,/g, '')) || 0) : 0; }

    function goodsTotal() {
        if (start() === 'received') return preview ? preview.received : 0;
        var ea = $('ea'); return ea ? (parseFloat(ea.dataset.total || '0') || 0) : 0;
    }
    function supplierCredit() {
        if (start() === 'received') return preview && preview.credit && preview.credit.enabled ? (preview.credit.separate || 0) : 0;
        var id = parseInt(($('supplier_id') || {}).value || '0', 10);
        var s = (BOX.suppliers || []).filter(function (x) { return x.id === id; })[0];
        return s && s.balance < 0 ? -s.balance : 0;
    }
    function invoiceTotal() { return goodsTotal() + num('pw-inv-tax'); }
    // Only a suggestion: the server pays against the invoice's own balance after Supplier Credit, read when it posts.
    function suggested() { return Math.max(0, Math.round((invoiceTotal() - supplierCredit()) * 100) / 100); }

    var amountTouched = !!(($('pw-pay-amt') || {}).value);
    function refresh() {
        var s = start();
        form.querySelectorAll('[data-for]').forEach(function (el) { el.hidden = el.dataset.for !== s; });
        var inv = invoiceOn();
        if ($('pw-inv-fields')) $('pw-inv-fields').hidden = !inv;
        if ($('pw-inv-later')) $('pw-inv-later').hidden = inv || s !== 'new';
        // A payment pays this purchase's invoice: no invoice, no payment.
        if ($('pw-pay-card')) $('pw-pay-card').hidden = !inv;
        if ($('pw-pay-fields')) $('pw-pay-fields').hidden = !(inv && payOn());
        if ($('pw-pay-amt') && !amountTouched) $('pw-pay-amt').value = suggested() > 0 ? suggested().toFixed(2) : '';
        if ($('pw-pay-says')) {
            var c = supplierCredit();
            $('pw-pay-says').textContent = 'Invoice about ' + money(invoiceTotal()) + (c > 0 ? ' − Supplier Credit ' + money(c) : '') + ' = up to ' + money(suggested()) + '. The server checks it again when you Post.';
        }
        $('grn-post-btn').textContent = 'Post';
    }

    function loadReceipt() {
        var id = ($('pw-grn') || {}).value, box = $('pw-grn-info');
        preview = null;
        if ($('pw-returns')) $('pw-returns').hidden = true;
        if (!id) { box.innerHTML = ''; refresh(); return; }
        box.textContent = 'Reading the goods receipt…';
        fetch(RECEIPT_URL.replace('__ID__', id), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                preview = d.preview;
                var p = d.preview;
                box.innerHTML = '<dl class="pw-sum"><dt>Supplier</dt><dd>' + esc(d.supplier) + '</dd><dt>Goods received</dt><dd>' + money(p.goods) + '</dd>'
                    + (p.charges > 0 ? '<dt>Extra charges</dt><dd>' + money(p.charges) + '</dd>' : '') + '<dt>The invoice comes to</dt><dd>' + money(p.received) + '</dd></dl>';
                if (p.returns && p.returns.length && $('pw-returns')) {
                    $('pw-returns').hidden = false;
                    $('pw-ret-list').textContent = 'Returned: ' + p.returns.map(function (r) { return r.return_no + ' ' + money(r.amount); }).join(', ') + '.';
                }
                refresh();
            }, function () { box.textContent = 'Could not read the goods receipt — check the internet and choose it again.'; });
    }

    // The review (the entry area's dialog for goods arriving now): what else this Post records.
    window.eaReviewTitle = 'Check this purchase';
    window.eaPostLabel = 'Post';
    window.eaReviewExtra = function (goods, what) {
        var parts = ['<b>Stock</b> goes up by these quantities.'];
        if (invoiceOn()) {
            parts.push('<b>Supplier Invoice</b> ' + money(goods + num('pw-inv-tax')) + (num('pw-inv-tax') > 0 ? ' (with tax ' + money(num('pw-inv-tax')) + ')' : '') + ': the supplier is owed this' + (supplierCredit() > 0 ? ', less Supplier Credit up to ' + money(supplierCredit()) : '') + '.');
            if (payOn()) parts.push('<b>Payment</b> ' + money(num('pw-pay-amt')) + ' from ' + esc(($('pw-pay-acc').selectedOptions[0] || {}).text || '—') + ': cash / bank goes down, the supplier is owed less.');
        } else {
            parts.push('<b>No invoice yet</b> — the supplier\'s balance does not change until it is recorded.');
        }
        return parts.join('<br>') + '<br><span class="text-muted">One Post records all of these together; if any part fails, nothing is saved, the screen says which part, and everything you typed stays.</span>';
    };
    // A refusal of the invoice or payment part: say which part, at the top.
    var STEP = { receipt: 'Goods received', invoice: 'Supplier Invoice', payment: 'Payment' };
    window.eaFailed = function (x) {
        var box = $('pw-errors');
        if (x.status === 422 && x.d && x.d.step) {
            var errs = x.d.errors || {}, list = [];
            Object.keys(errs).forEach(function (k) { list.push(errs[k][0]); });
            box.innerHTML = '<b>' + esc(STEP[x.d.step] || 'This purchase') + ' — nothing was saved.</b><br>' + list.map(esc).join('<br>');
            box.hidden = false;
        } else box.hidden = true;
    };
    window.eaDone = function () { $('pw-errors').hidden = true; };

    // Goods already received: no lines here, so this page posts it (after its own short check).
    var busy = false;
    window.eaOwnSubmit = function (e) {
        if (start() !== 'received') return false;
        if (busy) return true;
        var box = $('pw-errors');
        if (!($('pw-grn') || {}).value) { box.innerHTML = 'Choose the goods receipt this invoice is for.'; box.hidden = false; $('pw-grn').focus(); return true; }
        var sum = 'Supplier Invoice ' + money(invoiceTotal()) + ' for ' + (($('pw-grn').selectedOptions[0] || {}).text || '') + (payOn() ? ', and a payment of ' + money(num('pw-pay-amt')) : '') + '. Its stock is not received again.';
        var done = $('pw-done');
        if (form.dataset.reviewed !== '1') {
            done.className = 'alert alert-info mt-3';
            done.innerHTML = '<b>Check:</b> ' + esc(sum) + ' <button type="button" class="btn btn-sm btn-primary ms-2" id="pw-confirm">Post</button>';
            done.hidden = false; $('pw-confirm').focus();
            $('pw-confirm').onclick = function () { form.dataset.reviewed = '1'; if (form.requestSubmit) form.requestSubmit($('grn-post-btn')); else form.submit(); };
            return true;
        }
        form.dataset.reviewed = '';
        busy = true; $('grn-post-btn').disabled = true;
        fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, d: d }; }, function () { return { ok: false, status: r.status, d: {} }; }); })
            .then(function (x) {
                busy = false; $('grn-post-btn').disabled = false;
                if (x.d && x.d.next_key && $('ea-key')) $('ea-key').value = x.d.next_key;
                if (x.ok && x.d && x.d.ok) {
                    box.hidden = true;
                    done.className = 'alert alert-success mt-3';
                    done.innerHTML = '<b>✓ ' + (x.d.already ? 'Already posted — nothing was posted twice:' : 'Posted together:') + '</b> '
                        + x.d.documents.map(function (d) { return '<a class="btn btn-sm btn-success ms-1" href="' + esc(d.url) + '">' + esc(d.kind) + ' ' + esc(d.number) + '</a>'; }).join('')
                        + ' <a class="btn btn-sm btn-outline-dark ms-1" href="' + @json(url('/purchases/new')) + '">New purchase</a>';
                    done.hidden = false; done.focus();
                    var opt = $('pw-grn').selectedOptions[0]; if (opt && opt.value) { opt.remove(); $('pw-grn').value = ''; loadReceipt(); }
                    return;
                }
                done.hidden = true;
                window.eaFailed(x.status ? x : { status: 0, d: {} });
                if (!x.d || !x.d.step) { box.innerHTML = x.status === 419 ? 'Your login has expired. Log in again in another tab, then press Post again — nothing is lost.' : 'Could not save — check the internet and press Post again. Nothing is lost.'; box.hidden = false; }
                box.focus();
            }, function () { busy = false; $('grn-post-btn').disabled = false; box.innerHTML = 'Could not save — check the internet and press Post again. Nothing is lost.'; box.hidden = false; });
        return true;
    };

    form.addEventListener('change', function (e) {
        if (e.target.id === 'pw-grn') { loadReceipt(); return; }
        if (e.target.id === 'pw-pay-amt') amountTouched = true;
        if (e.target.name === 'start') { $('pw-done').hidden = true; $('pw-errors').hidden = true; }
        refresh();
    });
    form.addEventListener('input', function (e) { if (e.target.id === 'pw-pay-amt') amountTouched = true; if (e.target.id === 'pw-inv-tax' || e.target.id === 'extra_charges') refresh(); });
    var ea = $('ea'); if (ea) ea.addEventListener('ea:total', refresh);
    if (($('pw-grn') || {}).value) loadReceipt(); else refresh();
})();
