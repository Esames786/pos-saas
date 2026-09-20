{{-- W0 js/cart (Team 2 owns lines/totals, Team 3 owns renderActions from W2/W3): cart lines, chips, totals, the action grid, Preview Bill. --}}
        // ---- Cart. A carried line (from an open check) keeps its line id + captured price. ----
        function addToCart(item) {
            if (item.deal) {
                // DEAL parity: the client names the deal + quantity only; the server expands the synced combo book.
                const ex = state.cart.find(l => l.combo_id === item.id && !l.line_id);
                if (ex) ex.quantity += 1; else state.cart.push({ key: 'd' + item.id, combo_id: item.id, product_id: null, name: item.name, price: item.price, quantity: 1, line_id: null, kot_sent_quantity: 0, deal: true, components: [], component_lines: [] });
            } else {
                const ex = state.cart.find(l => l.product_id === item.id && !l.combo_id);
                if (ex) ex.quantity += 1; else state.cart.push({ key: 'p' + item.id, product_id: item.id, name: item.name, price: item.price, quantity: 1, line_id: null, kot_sent_quantity: 0 });
            }
            state.dirty = true; renderCart();
        }
        function changeQty(key, d) {
            const l = state.cart.find(x => x.key === key); if (!l) return;
            const next = l.quantity + d;
            if (next < l.kot_sent_quantity) { toast('Already sent to the kitchen — reducing needs a void with a reason.'); return; }
            l.quantity = next; if (l.quantity <= 0) state.cart = state.cart.filter(x => x.key !== key);
            state.dirty = true; renderCart();
        }
        function renderCart() {
            const wrap = $('cart-lines');
            if (!state.cart.length) { wrap.innerHTML = '<p class="muted" style="padding:.6rem">Cart is empty.</p>'; }
            else {
                wrap.innerHTML = '';
                state.cart.forEach(l => {
                    const row = document.createElement('div'); row.className = 'line';
                    const sub = (l.kot_sent_quantity > 0 ? '<div class="ln-sub">kitchen has ' + l.kot_sent_quantity + '</div>' : '') +
                        (l.components && l.components.length ? '<div class="ln-sub">' + esc(l.components.join(' · ')) + '</div>' : '');
                    row.innerHTML = '<div><div class="ln-nm">' + esc(l.name) + (l.deal ? ' <span class="chip hot" style="padding:.05rem .4rem">Deal</span>' : '') + '</div>' + sub + '</div><div class="ln-amt">' + money(l.price * l.quantity) + '</div>' +
                        '<div class="ln-ctl"><button data-m="-1">−</button><span>' + l.quantity + '</span><button data-m="1">+</button><span class="muted" style="font-size:.72rem">@ ' + money(l.price) + '</span></div>';
                    row.querySelector('[data-m="-1"]').addEventListener('click', () => changeQty(l.key, -1));
                    row.querySelector('[data-m="1"]').addEventListener('click', () => changeQty(l.key, 1));
                    wrap.appendChild(row);
                });
            }
            $('t-items').textContent = state.cart.reduce((s, l) => s + l.quantity, 0);
            $('t-grand').textContent = money(state.held && !state.dirty ? state.held.grand_total : state.cart.reduce((s, l) => s + l.price * l.quantity, 0));
            renderChips(); renderActions();
        }
        function renderChips() {
            const c = $('check-chip');
            if (state.held) {
                c.hidden = false; c.className = 'chip ' + (state.held.is_draft ? 'draft' : 'hot');
                c.textContent = (state.held.is_draft ? 'DRAFT ' : 'Held ') + (state.held.table_no ? 'Table ' + state.held.table_no + ' · ' : '') + (state.held.sale_no || '').slice(-8) + (state.held.waiter_name ? ' · ' + state.held.waiter_name : '');
            } else if (state.session) {
                c.hidden = false; c.className = 'chip hot';
                c.textContent = 'Table ' + state.session.table_no + (state.session.waiter_name ? ' · ' + state.session.waiter_name : '') + ' · new check';
            } else { c.hidden = true; }
            const cust = state.customer?.name || state.held?.customer_name || $('customer-name').value.trim();
            $('customer-chip').textContent = cust || 'Walk-in';
        }
        function cartLines() { return state.cart.map(l => Object.assign(l.combo_id ? { combo_id: l.combo_id, quantity: l.quantity } : { product_id: l.product_id, quantity: l.quantity }, l.line_id ? { sales_order_line_id: l.line_id } : {})); }
        // The commercial intent that travels with hold, preview and payment — the same fields as Online's Review & Pay.
        function commercial() {
            const c = state.commercial, out = {};
            if (c.discount_type && c.discount_type !== 'none') { out.discount_type = c.discount_type; out.discount_value = Number(c.discount_value || 0); }
            if (c.promo_code) out.promo_code = c.promo_code;
            if (c.manager_approval_id) out.manager_approval_id = c.manager_approval_id;
            if (state.customer) { out.customer_id = state.customer.id; out.customer_name = state.customer.name; out.customer_phone = state.customer.phone || null; }
            else { const nm = $('customer-name').value.trim(); if (nm) out.customer_name = nm; }
            if (state.orderType === 'delivery' && !state.session) {
                if (c.delivery_channel_id) out.delivery_channel_id = Number(c.delivery_channel_id);
                if (c.delivery_rider_id) out.delivery_rider_id = Number(c.delivery_rider_id);
                if (c.delivery_address) out.delivery_address = c.delivery_address;
                if (!DATA.deliveryChargeLocked) out.delivery_charge_amount = Number(c.delivery_charge_amount || 0);
            }
            return out;
        }
        function requireCart() { if (!state.cart.length) { toast('Add at least one item.'); return false; } return true; }

        // ---- Contextual action buttons (the Online layout: Hold/Draft/Recall, then KOT/Add Round for a check). ----
        // Every button carries a stable id (W0) so the control census + browser proofs can target it.
        function renderActions() {
            const a = $('actions'); a.innerHTML = '';
            const btn = (label, cls, fn, wide, id) => { const b = document.createElement('button'); b.textContent = label; b.className = cls + (wide ? ' wide' : ''); if (id) b.id = id; b.addEventListener('click', fn); a.appendChild(b); return b; };
            if (state.held) {
                btn(state.dirty ? 'Save round' : 'Saved', state.dirty ? 'primary' : '', () => saveRound(false), false, 'save-round-btn');
                btn('KOT', 'warn', sendKot, false, 'kot-btn');
                btn('Preview Bill', '', previewBill, false, 'preview-bill-btn');
                btn('Split Bill', '', splitBill, false, 'split-bill-btn');
                btn('Review & Pay', 'ok', reviewAndPay, true, 'review-pay-btn');
                btn('Cancel order', 'danger', cancelOrder, false, 'cancel-order-btn');
                btn('Leave check', 'ghost', leaveCheck, false, 'leave-check-btn');
            } else if (state.session) {
                btn('Hold (send later)', 'primary', () => holdSale(false), false, 'hold-sale-btn');
                btn('Draft', '', () => holdSale(true), false, 'draft-btn');
                btn('Preview Bill', '', previewBill, true, 'preview-bill-btn');
                btn('Leave table', 'ghost', leaveCheck, true, 'leave-check-btn');
            } else {
                btn('Hold', '', () => holdSale(false), false, 'hold-sale-btn');
                btn('Draft', '', () => holdSale(true), false, 'draft-btn');
                btn('Recall', '', recallList, false, 'recall-btn');
                btn('Preview Bill', '', previewBill, false, 'preview-bill-btn');
                btn('Review & Pay', 'primary', reviewAndPay, true, 'review-pay-btn');
            }
            btn('Quick Report', 'ghost', quickReport, false, 'quick-report-btn');
            btn('Recent Prints', 'ghost', recentPrints, false, 'recent-prints-btn');
        }

        // ---- Preview Bill: authoritative running bill, ZERO mutation. ----
        async function previewBill() {
            if (!requireCart()) return;
            try {
                let t;
                if (state.held && !state.dirty) { t = state.held; }
                else { const p = await api('POST', '/preview-bill', Object.assign({ order_type: state.orderType, lines: cartLines() }, commercial())); t = p.totals || {}; }
                openModal('<h2>Preview Bill</h2><p class="muted">Running bill — no payment, stock, KOT, or receipt is created.</p>' + rowsHtml(t) +
                    '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div>');
            } catch (e) { toast(e.message); }
        }
        function rowsHtml(t) {
            const r = (k, v) => '<div class="row"><span>' + k + '</span><span>' + money(v) + '</span></div>';
            return '<div class="totals">' + r('Subtotal', t.subtotal ?? t.sub_total ?? 0) +
                (Number(t.discount_amount) ? r('Discount' + (t.promo_code ? ' (' + esc(t.promo_code) + ')' : ''), -t.discount_amount) : '') +
                (Number(t.tax_amount) ? r('Tax', t.tax_amount) : '') +
                (Number(t.service_charge_amount ?? t.service_charge) ? r('Service charge', t.service_charge_amount ?? t.service_charge) : '') +
                (Number(t.delivery_charge_amount) ? r('Delivery charge', t.delivery_charge_amount) : '') +
                '<div class="row grand"><span>Grand total</span><span>' + money(t.grand_total ?? 0) + '</span></div></div>';
        }
