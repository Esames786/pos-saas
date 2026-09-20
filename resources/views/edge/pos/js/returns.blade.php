{{-- W0 js/returns (Team 4 owns from W4): F1 sales returns — find the paid sale, return part or all, refund from this till. --}}
        // ---- F1 SALES RETURNS — the Online Return screen, on the Branch Server (post-settlement void = return). ----
        async function returnsFlow() {
            openModal('<h2>Sales Return</h2><p class="muted">Find the paid sale — made here or on the Online POS — then return part or all of it.</p>' +
                '<div class="field"><label>Sale number / customer</label><input type="text" id="rt-q" autocomplete="off" placeholder="SO-…"></div>' +
                '<div id="rt-results" class="list"></div><div id="rt-err"></div>' +
                '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button><button class="primary" id="rt-search">Search</button></div>');
            const search = async () => {
                try {
                    const r = await api('GET', '/returns/search?q=' + encodeURIComponent($('rt-q').value.trim()));
                    $('rt-results').innerHTML = r.sales.length ? r.sales.map(s => '<div class="list-row" data-id="' + s.id + '"><div><strong>' + esc(s.sale_no) + '</strong> <span class="chip ' + (s.origin === 'online' ? 'hot' : '') + '" style="padding:.05rem .4rem">' + (s.origin === 'online' ? 'Online sale' : 'This counter') + '</span>' +
                        '<div class="ln-sub">' + esc(s.business_date || '') + ' · ' + esc(s.order_type) + (s.customer_name ? ' · ' + esc(s.customer_name) : '') + '</div></div><div class="ln-amt">' + money(s.grand_total) + '</div></div>').join('') : '<p class="muted">No returnable sale matches.</p>';
                    document.querySelectorAll('#rt-results .list-row').forEach(row => row.onclick = () => returnScreen(Number(row.dataset.id)));
                } catch (e) { $('rt-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
            $('rt-search').onclick = search;
            $('rt-q').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); search(); } });
            search();
        }
        async function returnScreen(saleId) {
            let v;
            try { v = await api('GET', '/returns/sales/' + saleId); } catch (e) { toast(e.message); return; }
            const allowed = v.offline_refund_methods;
            const methodLabel = { cash: 'Cash', card: 'Card', bank_transfer: 'Bank transfer', other: 'Other' };
            const methods = ['cash', 'card', 'bank_transfer', 'other'].map(m => '<option value="' + m + '"' + (allowed.includes(m) ? '' : ' disabled') + (v.default_refund_method === m && allowed.includes(m) ? ' selected' : '') + '>' + methodLabel[m] + (allowed.includes(m) ? '' : ' — needs the Online POS') + '</option>').join('');
            const rows = v.lines.map((l, i) => '<div class="line" style="align-items:center"><div><div class="ln-nm">' + esc(l.name) + '</div><div class="ln-sub">Sold ' + l.quantity + ' ' + esc(l.unit_code || '') + ' @ ' + money(l.unit_price) + (l.returned_quantity > 0 ? ' · returned ' + l.returned_quantity : '') + ' · <span class="chip" style="padding:.05rem .4rem">' + l.returnable + ' returnable</span></div></div>' +
                '<div style="display:flex;gap:.3rem;align-items:center"><button type="button" class="ghost rt-step" data-i="' + i + '" data-dir="-1"' + (l.returnable > 0 ? '' : ' disabled') + '>−</button>' +
                '<input type="number" class="rt-qty" data-i="' + i + '" value="0" min="0" max="' + l.returnable + '" step="' + l.qty_step + '" style="width:5.5rem"' + (l.returnable > 0 ? '' : ' disabled') + '>' +
                '<button type="button" class="ghost rt-step" data-i="' + i + '" data-dir="1"' + (l.returnable > 0 ? '' : ' disabled') + '>+</button></div></div>').join('');
            openModal('<h2>Return — ' + esc(v.sale.sale_no) + ' <span class="chip ' + (v.sale.origin === 'online' ? 'hot' : '') + '" style="padding:.05rem .4rem">' + (v.sale.origin === 'online' ? 'Online sale' : 'This counter') + '</span></h2>' +
                (v.fresh ? '' : '<div class="err">' + esc(v.freshness_message) + '</div>') +
                (v.online_required_refund ? '<p class="muted">This sale was paid by ' + esc(methodLabel[v.default_refund_method] || v.default_refund_method) + '. Refunding it the same way needs the Online POS; a cash refund from this till is possible where the business allows it.</p>' : '') +
                '<div class="lines" style="max-height:40vh;overflow:auto">' + rows + '</div>' +
                '<div class="field"><label>Refund method</label><select id="rt-method">' + methods + '</select></div>' +
                '<div class="field"><label>Reason (optional)</label><input type="text" id="rt-reason" maxlength="500"></div>' +
                '<div id="rt-breakdown" class="muted"></div><div id="rt-err2"></div>' +
                '<div class="btn-row"><button class="ghost" id="rt-back">Back</button><button class="primary" id="rt-post"' + (v.fresh && v.can_return ? '' : ' disabled') + '>Refund</button></div>');
            const qtys = () => v.lines.map((l, i) => Math.min(Math.max(Number(document.querySelector('.rt-qty[data-i="' + i + '"]').value || 0), 0), l.returnable));
            // Online arithmetic for the preview: qty × price − qty × discount/unit + qty × tax/unit; the delivery charge
            // comes back only when this return leaves nothing of the order (every line fully returned).
            const compute = () => {
                const q = qtys(); let sub = 0, disc = 0, tax = 0, completes = v.lines.length > 0;
                v.lines.forEach((l, i) => { sub += q[i] * l.unit_price; disc += q[i] * l.discount_per_unit; tax += q[i] * l.tax_per_unit; if (q[i] + 1e-6 < l.returnable) completes = false; });
                const delivery = completes && q.some(x => x > 0) ? v.outstanding_delivery : 0;
                const r2 = x => Math.round(x * 100) / 100;
                return { sub: r2(sub), disc: r2(disc), tax: r2(tax), delivery: r2(delivery), total: r2(r2(sub) - r2(disc) + r2(tax) + r2(delivery)), q };
            };
            const render = () => {
                const c = compute();
                $('rt-breakdown').innerHTML = 'Items ' + money(c.sub) + (c.disc ? ' − discount ' + money(c.disc) : '') + (c.tax ? ' + tax ' + money(c.tax) : '') + (c.delivery ? ' + delivery charge ' + money(c.delivery) : '') + ' → <strong>Refund ' + money(c.total) + '</strong>' + (v.needs_manager_approval ? ' · manager approval required' : '');
            };
            document.querySelectorAll('.rt-qty').forEach(inp => inp.addEventListener('input', render));
            document.querySelectorAll('.rt-step').forEach(b => b.onclick = () => {
                const i = Number(b.dataset.i), l = v.lines[i], inp = document.querySelector('.rt-qty[data-i="' + i + '"]');
                const step = Number(l.qty_step), next = Math.min(Math.max((Number(inp.value || 0) + Number(b.dataset.dir) * step), 0), l.returnable);
                inp.value = String(Math.round(next * 1000) / 1000); render();
            });
            render();
            $('rt-back').onclick = returnsFlow;
            $('rt-post').onclick = async () => {
                const c = compute();
                if (c.total <= 0) { $('rt-err2').innerHTML = '<div class="err">Enter a return quantity for at least one item.</div>'; return; }
                const method = $('rt-method').value;
                const body = { sales_order_id: v.sale.id, refund_method: method, refund_amount: c.total, reason: $('rt-reason').value.trim() || null, lines: v.lines.map((l, i) => ({ sales_order_line_id: l.sales_order_line_id, quantity: c.q[i] })) };
                if (v.needs_manager_approval) {
                    const approvalId = await askManagerApprovalFor('sales_return', 'Manager approval — sales return', 'Refund ' + money(c.total) + ' on ' + v.sale.sale_no + ' needs a manager.', { sales_order_id: v.sale.id, branch_id: DATA.branchId, refund_method: method, refund_amount: c.total });
                    if (!approvalId) { returnScreen(saleId); return; }
                    body.manager_approval_id = approvalId;
                    await returnScreen(saleId); // re-render after the approval modal closed
                    document.querySelectorAll('.rt-qty').forEach((inp, i) => { inp.value = String(c.q[i]); }); render();
                }
                try {
                    const r = await api('POST', '/returns', body);
                    const ret = r.return;
                    openModal('<h2>Return posted</h2><p><strong>' + esc(ret.return_no) + '</strong> · ' + esc(ret.sale.sale_no) + '</p>' +
                        '<div class="lines">' + ret.lines.map(l => '<div class="line"><div class="ln-sub">' + l.quantity + ' × ' + money(l.unit_price) + '</div><div class="ln-amt">' + money(l.line_total) + '</div></div>').join('') + '</div>' +
                        '<p>Refund <strong>' + money(ret.refund_amount) + '</strong> by ' + esc(methodLabel[ret.refund_method] || ret.refund_method) + (ret.totals.delivery_charge_amount ? ' (incl. delivery charge ' + money(ret.totals.delivery_charge_amount) + ')' : '') + '</p>' +
                        '<p class="muted">' + (ret.sync === 'synced' ? 'Synced to the Cloud.' : 'Saved here — syncs to the Cloud when the connection returns.') + '</p>' +
                        '<div class="btn-row"><button class="primary" onclick="EdgePOS.closeModal()">Done</button></div>');
                    refreshSync();
                } catch (e) { $('rt-err2').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
        }
