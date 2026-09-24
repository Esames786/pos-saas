{{-- W4 js/returns (Team 4): F1 sales returns — find the paid sale, return part or all, refund from this till. The Online
     create screen (tenant/sales-returns/create) is the reference: per-line Sold / Returned / Returnable + unit-aware
     stepper + line refund, Suggested Refund (+ delivery when the whole order comes back), Refund Method (default = original
     tender), Calculated Refund Amount (readonly), Reason, "Post Return" with a confirm step, manager approval where the
     branch requires it. Interface: openReturns() (Team 1 wires #pos-return-btn / #returns-btn); returnsFlow() and
     returnScreen() stay. --}}
        // ---- F1 SALES RETURNS — the Online Return screen, on the Branch Server (post-settlement void = return). ----
        async function openReturns() {
            if (DATA.canSalesReturn === false) { toast('You are not allowed to post sales returns.'); return; }
            openModal('<h2 id="posReturnModalLabel">Sales Return</h2><p class="muted">Find the paid sale — made here or on the Online POS — then return part or all of it.</p>' +
                '<div class="field"><label for="rt-q">Sale number / customer</label><input type="text" id="rt-q" autocomplete="off" placeholder="SO-…"></div>' +
                '<div id="rt-results" class="list"></div><div id="rt-err"></div>' +
                '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button><a class="navbtn" id="pos-return-newtab" href="' + BASE + '/sales-returns" hidden>Sales Returns list</a><button class="primary" id="rt-search">Search</button></div>');
            const search = async () => {
                try {
                    const r = await api('GET', '/returns/search?q=' + encodeURIComponent($('rt-q').value.trim()));
                    if (r.can_view_list && $('pos-return-newtab')) $('pos-return-newtab').hidden = false;
                    $('rt-results').innerHTML = r.sales.length ? r.sales.map(s => '<div class="list-row" data-id="' + s.id + '"><div><strong>' + esc(s.sale_no) + '</strong> <span class="chip ' + (s.origin === 'online' ? 'hot' : '') + '" style="padding:.05rem .4rem">' + (s.origin === 'online' ? 'Online sale' : 'This counter') + '</span>' +
                        '<div class="ln-sub">' + esc(s.business_date || '') + ' · ' + esc(s.order_type) + (s.customer_name ? ' · ' + esc(s.customer_name) : '') + '</div></div><div class="ln-amt">' + money(s.grand_total) + '</div></div>').join('') : '<p class="muted">No returnable sale matches.</p>';
                    document.querySelectorAll('#rt-results .list-row').forEach(row => row.onclick = () => returnScreen(Number(row.dataset.id)));
                } catch (e) { $('rt-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
            $('rt-search').onclick = search;
            $('rt-q').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); search(); } });
            search();
        }
        // Backward-compatible name (js/boot wires #returns-btn to returnsFlow).
        function returnsFlow() { return openReturns(); }
        async function returnScreen(saleId, keep) {
            let v;
            try { v = await api('GET', '/returns/sales/' + saleId); } catch (e) { toast(e.message); return; }
            const allowed = v.offline_refund_methods;
            const methodLabel = { cash: 'Cash', card: 'Card', bank_transfer: 'Bank Transfer', other: 'Other' };
            const methods = '<option value="" disabled' + (v.default_refund_method && allowed.includes(v.default_refund_method) ? '' : ' selected') + '>Select refund method</option>' +
                ['cash', 'bank_transfer', 'card', 'other'].map(m => '<option value="' + m + '"' + (allowed.includes(m) ? '' : ' disabled') + (v.default_refund_method === m && allowed.includes(m) ? ' selected' : '') + '>' + methodLabel[m] + (allowed.includes(m) ? '' : ' — needs the Online POS') + '</option>').join('');
            const rows = v.lines.map((l, i) => '<div class="line" style="align-items:center"><div><div class="ln-nm">' + esc(l.name) + '</div><div class="ln-sub">Sold ' + l.quantity + ' ' + esc(l.unit_code || '') + ' @ ' + money(l.unit_price) + ' · returned ' + l.returned_quantity + ' · <span class="chip" style="padding:.05rem .4rem">' + l.returnable + ' returnable</span>' + (l.returnable > 0 ? '' : ' · Fully returned') + '</div></div>' +
                '<div style="display:flex;gap:.3rem;align-items:center"><button type="button" class="ghost rt-step" data-i="' + i + '" data-dir="-1" aria-label="Decrease return quantity"' + (l.returnable > 0 ? '' : ' disabled') + '>−</button>' +
                '<input type="number" class="rt-qty return-qty" data-i="' + i + '" value="0" min="0" max="' + l.returnable + '" step="' + l.qty_step + '" style="width:5.5rem" aria-label="Return quantity for ' + esc(l.name) + '"' + (l.returnable > 0 ? '' : ' readonly') + '>' +
                '<button type="button" class="ghost rt-step" data-i="' + i + '" data-dir="1" aria-label="Increase return quantity"' + (l.returnable > 0 ? '' : ' disabled') + '>+</button>' +
                '<span class="ln-amt line-refund" data-i="' + i + '" style="min-width:4.5rem;text-align:right">0.00</span></div></div>').join('');
            openModal('<h2>Return — ' + esc(v.sale.sale_no) + ' <span class="chip ' + (v.sale.origin === 'online' ? 'hot' : '') + '" style="padding:.05rem .4rem">' + (v.sale.origin === 'online' ? 'Online sale' : 'This counter') + '</span></h2>' +
                '<p class="muted">' + esc(v.sale.business_date || '') + ' · ' + esc(v.sale.order_type) + ' · total ' + money(v.sale.grand_total) + (v.sale.customer_name ? ' · ' + esc(v.sale.customer_name) : '') + '</p>' +
                (v.fresh ? '' : '<div class="err">' + esc(v.freshness_message) + '</div>') +
                (v.online_required_refund ? '<p class="muted">This sale was paid by ' + esc(methodLabel[v.default_refund_method] || v.default_refund_method) + '. Refunding it the same way needs the Online POS; a cash refund from this till is possible where the business allows it.</p>' : '') +
                '<div class="lines" style="max-height:40vh;overflow:auto">' + rows + '</div>' +
                '<div class="totals"><div class="row" id="delivery-refund-row" hidden><span>Plus Delivery Charge (whole order returned)</span><span class="amt">0.00</span></div>' +
                '<div class="row grand"><span>Suggested Refund</span><span id="suggested-refund">0.00</span></div></div>' +
                '<div class="field"><label for="refund_method">Refund Method</label><select id="refund_method" class="rt-method">' + methods + '</select></div>' +
                '<div class="field"><label for="refund_amount">Calculated Refund Amount</label><input id="refund_amount" type="number" step="0.01" min="0" value="0" readonly>' +
                '<div class="muted" style="font-size:.78rem">Based on selected item quantities, proportional line discount, and line tax.</div></div>' +
                '<div class="field"><label for="reason">Reason</label><textarea id="reason" rows="2" maxlength="500" style="width:100%"></textarea></div>' +
                '<div id="rt-breakdown" class="muted"></div><div id="rt-err2"></div>' +
                '<div class="btn-row"><button class="ghost" id="rt-back">Back</button><button class="primary" id="rt-post"' + (v.fresh && v.can_return ? '' : ' disabled') + '>Post Return</button></div>');
            const qtys = () => v.lines.map((l, i) => Math.min(Math.max(Number(document.querySelector('.rt-qty[data-i="' + i + '"]').value || 0), 0), l.returnable));
            // Online arithmetic for the preview: qty × price − qty × discount/unit + qty × tax/unit; the delivery charge
            // comes back only when this return leaves nothing of the order (every line fully returned).
            const r2 = x => Math.round(x * 100) / 100;
            const compute = () => {
                const q = qtys(); let sub = 0, disc = 0, tax = 0, completes = v.lines.length > 0; const perLine = [];
                v.lines.forEach((l, i) => { sub += q[i] * l.unit_price; disc += q[i] * l.discount_per_unit; tax += q[i] * l.tax_per_unit; perLine.push(r2(q[i] * l.unit_price - q[i] * l.discount_per_unit + q[i] * l.tax_per_unit)); if (q[i] + 1e-6 < l.returnable) completes = false; });
                const delivery = completes && q.some(x => x > 0) ? v.outstanding_delivery : 0;
                return { sub: r2(sub), disc: r2(disc), tax: r2(tax), delivery: r2(delivery), total: r2(r2(sub) - r2(disc) + r2(tax) + r2(delivery)), q, perLine };
            };
            const render = () => {
                const c = compute();
                c.perLine.forEach((x, i) => { const el = document.querySelector('.line-refund[data-i="' + i + '"]'); if (el) el.textContent = money(x); });
                $('delivery-refund-row').hidden = !c.delivery; $('delivery-refund-row').querySelector('.amt').textContent = money(c.delivery);
                $('suggested-refund').textContent = money(c.total); $('refund_amount').value = c.total.toFixed(2);
                $('rt-breakdown').innerHTML = 'Items ' + money(c.sub) + (c.disc ? ' − discount ' + money(c.disc) : '') + (c.tax ? ' + tax ' + money(c.tax) : '') + (c.delivery ? ' + delivery charge ' + money(c.delivery) : '') + ' → <strong>Refund ' + money(c.total) + '</strong>' + (v.needs_manager_approval ? ' · manager approval required' : '');
            };
            document.querySelectorAll('.rt-qty').forEach(inp => inp.addEventListener('input', render));
            document.querySelectorAll('.rt-step').forEach(b => b.onclick = () => {
                const i = Number(b.dataset.i), l = v.lines[i], inp = document.querySelector('.rt-qty[data-i="' + i + '"]');
                const step = Number(l.qty_step), next = Math.min(Math.max((Number(inp.value || 0) + Number(b.dataset.dir) * step), 0), l.returnable);
                inp.value = String(Math.round(next * 1000) / 1000); render();
            });
            if (keep) {
                (keep.q || []).forEach((x, i) => { const inp = document.querySelector('.rt-qty[data-i="' + i + '"]'); if (inp) inp.value = String(x); });
                if (keep.method) $('refund_method').value = keep.method;
                if (keep.reason) $('reason').value = keep.reason;
            }
            render();
            $('rt-back').onclick = openReturns;
            $('rt-post').onclick = async () => {
                const c = compute();
                if (c.total <= 0) { $('rt-err2').innerHTML = '<div class="err">Enter a return quantity for at least one item.</div>'; return; }
                const method = $('refund_method').value;
                if (!method) { $('rt-err2').innerHTML = '<div class="err">Select how the refund is paid.</div>'; return; }
                const reason = $('reason').value.trim();
                const body = { sales_order_id: v.sale.id, refund_method: method, refund_amount: c.total, reason: reason || null, lines: v.lines.map((l, i) => ({ sales_order_line_id: l.sales_order_line_id, quantity: c.q[i] })) };
                // Online: "Post this sales return?" confirm — returned stock goes back into the branch; the refund figure.
                const ok = await new Promise(resolve => {
                    openModal('<h2 id="rt-confirm-title">Post this sales return?</h2><p>Returned stock goes back into the branch.<br>Refund: <strong>' + money(c.total) + '</strong> by ' + esc(methodLabel[method] || method) + '</p>' +
                        '<div class="btn-row"><button class="ghost" id="rt-confirm-no">Cancel</button><button class="primary" id="rt-confirm-yes">' + (v.needs_manager_approval ? 'Continue to manager approval' : 'Post Return') + '</button></div>');
                    $('rt-confirm-no').onclick = () => resolve(false); $('rt-confirm-yes').onclick = () => resolve(true);
                });
                const keepState = { q: c.q, method, reason };
                if (!ok) { returnScreen(saleId, keepState); return; }
                if (v.needs_manager_approval) {
                    const approvalId = await askManagerApprovalFor('sales_return', 'Manager approval — sales return', 'This branch needs a manager to approve a return. Refund ' + money(c.total) + ' on ' + v.sale.sale_no + '.', { sales_order_id: v.sale.id, branch_id: DATA.branchId, refund_method: method, refund_amount: c.total });
                    if (!approvalId) { returnScreen(saleId, keepState); return; }
                    body.manager_approval_id = approvalId;
                }
                try {
                    const r = await api('POST', '/returns', body);
                    const ret = r.return;
                    openModal('<h2 id="rt-posted-title">Return posted</h2><p><strong>' + esc(ret.return_no) + '</strong> · ' + esc(ret.sale.sale_no) + '</p>' +
                        '<div class="lines">' + ret.lines.map(l => '<div class="line"><div class="ln-sub">' + l.quantity + ' × ' + money(l.unit_price) + '</div><div class="ln-amt">' + money(l.line_total) + '</div></div>').join('') + '</div>' +
                        '<p>Refund <strong>' + money(ret.refund_amount) + '</strong> by ' + esc(methodLabel[ret.refund_method] || ret.refund_method) + (ret.totals.delivery_charge_amount ? ' (incl. delivery charge ' + money(ret.totals.delivery_charge_amount) + ')' : '') + '</p>' +
                        '<p class="muted">' + (ret.sync === 'synced' ? 'Synced to the Cloud.' : 'Saved here — syncs to the Cloud when the connection returns.') + '</p>' +
                        '<div class="btn-row">' + (ret.detail_url ? '<a class="navbtn" id="rt-posted-view" href="' + esc(ret.detail_url) + '">View return</a>' : '') + '<button class="primary" onclick="EdgePOS.closeModal()">Done</button></div>');
                    refreshSync();
                } catch (e) {
                    await returnScreen(saleId, keepState);
                    $('rt-err2').innerHTML = '<div class="err">' + esc(e.message) + '</div>';
                }
            };
        }
