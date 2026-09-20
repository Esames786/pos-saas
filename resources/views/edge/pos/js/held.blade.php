{{-- W0 js/held (Team 3 owns from W3): Hold / Draft / Recall / Add Round / KOT / Split Bill / Cancel order / Leave check. --}}
        // ---- Hold / Draft (new check) — on a table session this is Round 1 of a dine-in check. ----
        async function holdSale(asDraft) {
            if (!requireCart()) return;
            const payload = Object.assign({ order_type: state.session ? 'dine_in' : state.orderType, save_as_draft: !!asDraft, lines: cartLines() }, commercial());
            if (state.session) payload.restaurant_table_session_id = state.session.id;
            if (payload.order_type === 'quick_sale') { const q = await askQuickSaleAttribution(); if (!q) return; Object.assign(payload, q); }
            try {
                const s = await api('POST', '/held-sales', payload);
                await loadHeld(s.sale_id);
                toast(asDraft ? 'Saved as draft ' + s.sale_no : 'Order held ' + s.sale_no + (state.session ? ' — send the KOT when ready.' : ''));
            } catch (e) { toast(e.message); }
        }
        function askQuickSaleAttribution() {
            return new Promise(resolve => {
                openModal('<h2>Quick Sale</h2><div class="field"><label>Vehicle #</label><input type="text" id="qs-vehicle"></div>' +
                    '<div class="field"><label>Waiter</label><select id="qs-waiter">' + DATA.waiters.map(w => '<option value="' + w.id + '">' + esc(w.name) + '</option>').join('') + '</select></div>' +
                    '<div class="btn-row"><button class="ghost" id="qs-cancel">Cancel</button><button class="primary" id="qs-ok">Continue</button></div>');
                $('qs-cancel').onclick = () => { closeModal(); resolve(null); };
                $('qs-ok').onclick = () => { const v = $('qs-vehicle').value.trim(), w = Number($('qs-waiter').value || 0) || null; closeModal(); resolve({ vehicle_number: v, restaurant_waiter_id: w }); };
            });
        }

        // ---- Recall: list the open checks, load one into the cart. Never touches the terminal selection. ----
        async function recallList() {
            try {
                const r = await api('GET', '/held-sales');
                let html = '<h2>Recall</h2>';
                if (!r.held_sales.length) html += '<p class="muted">No open checks.</p>';
                r.held_sales.forEach(h => {
                    html += '<div class="list-row" data-id="' + h.id + '"><div><strong>' + esc(h.sale_no) + '</strong> ' + (h.is_draft ? '<span class="chip draft">DRAFT</span>' : '') +
                        '<div class="muted">' + esc(DATA.orderTypeLabels[h.order_type] || h.order_type) + (h.table_no ? ' · Table ' + esc(h.table_no) : '') + (h.waiter_name ? ' · ' + esc(h.waiter_name) : '') + (h.customer_name ? ' · ' + esc(h.customer_name) : '') + '</div></div>' +
                        '<div>' + money(h.grand_total) + '<div class="muted" style="font-size:.72rem">' + h.item_count + ' items</div></div></div>';
                });
                html += '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div>';
                openModal(html);
                document.querySelectorAll('#modal .list-row').forEach(row => row.addEventListener('click', () => { closeModal(); loadHeld(Number(row.dataset.id)); }));
            } catch (e) { toast(e.message); }
        }
        async function loadHeld(id) {
            const d = await api('GET', '/held-sales/' + id);
            const h = d.held_sale;
            state.held = h; state.session = h.restaurant_table_session_id ? { id: h.restaurant_table_session_id, table_no: h.table_no, waiter_name: h.waiter_name } : null;
            // A deal is ONE cart row (its header); its components ride underneath for display + split.
            state.cart = h.lines.filter(l => l.line_kind !== 'component').map(l => l.line_kind === 'combo_header'
                ? { key: 'l' + l.id, combo_id: l.combo_id, product_id: null, name: l.product_name, price: l.unit_price, quantity: l.quantity, line_id: l.id, kot_sent_quantity: 0, deal: true,
                    components: h.lines.filter(c => c.parent_line_id === l.id).map(c => c.quantity + ' × ' + c.product_name),
                    component_lines: h.lines.filter(c => c.parent_line_id === l.id).map(c => ({ id: c.id, per_unit: l.quantity > 0 ? c.quantity / l.quantity : c.quantity })) }
                : { key: 'l' + l.id, product_id: l.product_id, name: l.product_name, price: l.unit_price, quantity: l.quantity, line_id: l.id, kot_sent_quantity: l.kot_sent_quantity });
            state.commercial = Object.assign({}, state.commercial, { discount_type: h.discount_type || 'none', discount_value: h.discount_value || 0, promo_code: h.promo_code || '', manager_approval_id: null,
                delivery_channel_id: h.delivery_channel_id, delivery_rider_id: h.delivery_rider_id, delivery_address: h.delivery_address || '', delivery_charge_amount: h.delivery_charge_amount ?? DATA.defaultDeliveryCharge });
            state.customer = h.customer_id ? { id: h.customer_id, name: h.customer_name, phone: h.customer_phone, addresses: [] } : null;
            state.dirty = false;
            lockOrderType(h.order_type);
            if (h.customer_name) $('customer-name').value = h.customer_name;
            renderCart();
        }
        function leaveCheck() {
            if (state.dirty && !confirm('Unsaved changes will be discarded. Leave?')) return;
            state.held = null; state.session = null; state.cart = []; state.dirty = false; $('customer-name').value = ''; unlockOrderType(); renderCart();
        }

        // ---- Add Round: re-submit carried lines by id + new lines; the server keeps captured prices + KOT-sent state. ----
        async function saveRound(asDraft) {
            if (!state.held) return; if (!requireCart()) return;
            try {
                const payload = Object.assign({ held_sale_id: state.held.id, order_type: state.held.order_type, save_as_draft: !!asDraft, lines: cartLines() }, commercial());
                if (state.held.restaurant_table_session_id) payload.restaurant_table_session_id = state.held.restaurant_table_session_id;
                if (state.held.order_type === 'quick_sale') { payload.vehicle_number = state.held.vehicle_number || ''; payload.restaurant_waiter_id = state.held.waiter_id; }
                const s = await api('POST', '/held-sales', payload);
                await loadHeld(s.sale_id);
                toast('Round saved on ' + s.sale_no);
                return true;
            } catch (e) { toast(e.message); return false; }
        }
        // ---- KOT: the unsent delta only (server sent-pool). Saves first when the cart changed. ----
        async function sendKot() {
            if (!state.held) return;
            if (state.dirty && !(await saveRound(state.held.is_draft))) return;
            try {
                const k = await api('POST', '/held-sales/' + state.held.id + '/kot');
                if (!k.batch) { toast(k.message || 'Nothing new for the kitchen.'); }
                else { toast('KOT #' + k.batch.sequence_no + ' sent · ' + k.batch.lines.length + ' line(s)'); await loadHeld(state.held.id); }
            } catch (e) { toast(e.message); }
        }

        // ---- Split Bill (Online parity): move quantities onto a new held check on the same table; each pays on its own. ----
        async function splitBill() {
            if (!state.held) return;
            if (state.dirty && !(await saveRound(state.held.is_draft))) return;
            const rows = state.cart.filter(l => l.line_id);
            openModal('<h2>Split Bill — ' + esc(state.held.sale_no) + '</h2><p class="muted">Choose how much of each item moves to the new check. The kitchen is not told again.</p>' +
                rows.map(l => '<div class="line"><div><div class="ln-nm">' + esc(l.name) + (l.deal ? ' <span class="chip hot" style="padding:.05rem .4rem">Deal</span>' : '') + '</div><div class="ln-sub">' + l.quantity + ' on this check @ ' + money(l.price) + '</div></div>' +
                    '<div class="ln-ctl"><input type="number" class="sb-qty" data-key="' + esc(l.key) + '" min="0" max="' + l.quantity + '" step="1" value="0" style="width:5rem"></div></div>').join('') +
                '<div id="sb-err"></div><div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Cancel</button><button class="primary" id="sb-ok">Split</button></div>');
            $('sb-ok').onclick = async () => {
                const lines = [];
                document.querySelectorAll('.sb-qty').forEach(inp => {
                    const q = Number(inp.value || 0); if (q <= 0) return;
                    const l = state.cart.find(x => x.key === inp.dataset.key); if (!l) return;
                    lines.push({ sales_order_line_id: l.line_id, quantity: q });
                    // a deal moves with its components (per-unit quantities × deals moved)
                    (l.component_lines || []).forEach(c => lines.push({ sales_order_line_id: c.id, quantity: +(c.per_unit * q).toFixed(3) }));
                });
                if (!lines.length) { $('sb-err').innerHTML = '<div class="err">Choose at least one quantity to split.</div>'; return; }
                try {
                    const r = await api('POST', '/held-sales/' + state.held.id + '/split', { lines });
                    closeModal();
                    toast('Split into ' + r.child.sale_no + ' — pay each check from Recall or the Table Board.');
                    await loadHeld(r.parent.status === 'held' ? r.parent.id : r.child.id);
                } catch (e) { $('sb-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
        }

        // ---- Cancel the whole open check (reason required; the server applies the branch approval mode). ----
        async function cancelOrder() {
            if (!state.held) return;
            try {
                const r = await api('GET', '/void-reasons');
                if (!r.reasons.length) { toast('No cancellation reasons are configured.'); return; }
                openModal('<h2>Cancel order ' + esc(state.held.sale_no) + '</h2><div class="field"><label>Reason</label><select id="cx-reason">' + r.reasons.map(x => '<option value="' + x.id + '">' + esc(x.name) + '</option>').join('') + '</select></div>' +
                    '<div id="cx-err"></div><div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Back</button><button class="danger" id="cx-ok">Cancel order</button></div>');
                $('cx-ok').onclick = async () => {
                    try {
                        await api('POST', '/held-sales/' + state.held.id + '/cancel', { reason_id: Number($('cx-reason').value) });
                        closeModal(); toast('Order cancelled — the table is free.');
                        state.held = null; state.session = null; state.cart = []; state.dirty = false; unlockOrderType(); renderCart();
                    } catch (e) { $('cx-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
                };
            } catch (e) { toast(e.message); }
        }
