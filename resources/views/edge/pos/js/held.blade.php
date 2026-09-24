{{-- W3 js/held (Team 3 owns): the order lifecycle — Hold / Draft / Held Orders (Recall) / Add Round (+ sent-line voids) / KOT /
     Split Bill / Cancel order (+ manager approval) / Recent Orders / Change Order Details / dead-session recovery / Clear / New Order / New Sale.
     Online reference: tenant/pos/index.blade.php heldSalesModal, completedOrdersModal, changeOrderModal, deadSessionModal,
     showVoidReasonModal, requestOrderCancellationDetails, clearCart, start-fresh / new-sale. Every call is an Edge-local route. --}}
        // ---- W3 shared state: pending sent-line voids (Online window._voidItems) + the void-reason book with the branch modes. ----
        state.voidItems = {};            // sales_order_line_id → { old_line_id, sent, reason_id, reason_name, product_name }
        let W3_VOID_META = null;         // { reasons[], line_approval_mode, order_approval_mode }
        async function voidMeta() {
            if (!W3_VOID_META) W3_VOID_META = await api('GET', '/void-reasons');
            return W3_VOID_META;
        }
        const w3NeedsManager = mode => mode && mode !== 'auto_approve';
        const orderTypeLabel = t => (DATA.orderTypeLabels && DATA.orderTypeLabels[t]) || String(t || '').replace('_', ' ');
        const w3When = iso => { if (!iso) return ''; const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleString([], { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); };
        // Compact order meta for the Held / Recent lists (Online posOrderMeta): table · waiter · vehicle · channel · rider.
        function orderMeta(s) {
            const bits = [];
            if (s.table || s.table_no) bits.push('Table ' + esc(s.table || s.table_no));
            if (s.waiter || s.waiter_name) bits.push(esc(s.waiter || s.waiter_name));
            if (s.vehicle_number) bits.push(esc(s.vehicle_number));
            if (s.delivery_channel) bits.push(esc(s.delivery_channel) + (s.delivery_channel_type === 'aggregator' ? '' : (s.delivery_rider ? ' · ' + esc(s.delivery_rider) : ' · no rider yet')));
            return bits.length ? '<div class="muted" style="font-size:.72rem">' + bits.join(' · ') + '</div>' : '';
        }

        // ---- Hold / Draft (new check) — on a table session this is Round 1 of a dine-in check. ----
        async function holdSale(asDraft) {
            if (!requireCart()) return;
            const payload = Object.assign({ order_type: state.session ? 'dine_in' : state.orderType, save_as_draft: !!asDraft, lines: cartLines() }, commercial());
            if (state.session) payload.restaurant_table_session_id = state.session.id;
            if (payload.order_type === 'quick_sale') {
                // Online requireQuickSaleFields: the INLINE vehicle / waiter fields (Team 2's grid) when the page has them;
                // the old prompt only as a fallback. Never a silently preselected waiter.
                let q = null;
                if (typeof quickSaleAttribution === 'function' && $('vehicle_number') && $('qs-waiter-select')) {
                    if (typeof requireQuickSaleFields === 'function' && !requireQuickSaleFields()) return;
                    q = quickSaleAttribution();
                } else { q = await askQuickSaleAttribution(); }
                if (!q) return; Object.assign(payload, q);
            }
            try {
                const s = await api('POST', '/held-sales', payload);
                await loadHeld(s.sale_id);
                toast(asDraft ? 'Saved as draft ' + s.sale_no : 'Order held ' + s.sale_no);
                if (!asDraft) await kotAfterHold(s.sale_id);
            } catch (e) {
                // A table has ONE cashier-facing open check (Online TABLE_HAS_OPEN_ORDERS; its create_separate_order flag is
                // hard-coded false). Offer to continue that check with these items as the next round instead of refusing.
                if (state.session && /already has an open order/i.test(e.message)) { await continueOpenCheckWithCart(state.session.id); return; }
                toast(e.message);
            }
        }
        // Online showOpenOrdersChoice → continueExistingOrder: load the table's open check and carry the unsaved items as NEW lines.
        async function continueOpenCheckWithCart(sessionId) {
            try {
                const r = await api('GET', '/restaurant/table-sessions');
                const row = (r.sessions || []).find(x => Number(x.session_id) === Number(sessionId));
                const heldId = row && row.held_sale_ids && row.held_sale_ids[0];
                if (!heldId) { toast('This table already has an open check — open it from the Table Board.'); return; }
                const carry = state.cart.filter(l => !l.line_id).map(l => Object.assign({}, l));
                await loadHeld(heldId);
                carry.forEach(l => { l.key = 'n' + Math.random().toString(36).slice(2); state.cart.push(l); });
                state.dirty = carry.length > 0; renderCart();
                toast('This table already has an open check — your items were added to it as the next round. Save round, then KOT.');
            } catch (e) { toast(e.message); }
        }
        function askQuickSaleAttribution() {
            return new Promise(resolve => {
                openModal('<h2>Quick Sale</h2><div class="field"><label>Vehicle #</label><input type="text" id="qs-vehicle"></div>' +
                    '<div class="field"><label>Waiter</label><select id="qs-waiter"><option value="">Select waiter…</option>' + DATA.waiters.map(w => '<option value="' + w.id + '">' + esc(w.name) + '</option>').join('') + '</select></div>' +
                    '<div class="btn-row"><button class="ghost" id="qs-cancel">Cancel</button><button class="primary" id="qs-ok">Continue</button></div>');
                $('qs-cancel').onclick = () => { closeModal(); resolve(null); };
                $('qs-ok').onclick = () => { const v = $('qs-vehicle').value.trim(), w = Number($('qs-waiter').value || 0) || null; closeModal(); resolve({ vehicle_number: v, restaurant_waiter_id: w }); };
            });
        }

        // ---- A26 Held Orders (Online heldSalesModal): type filters, Recall + Cancel per row. Never touches the terminal selection. ----
        let W3_HELD_TYPE = '';
        function typeFilterHtml(id, attr, current) {
            if (!DATA.orderTypes || DATA.orderTypes.length <= 1) return '';
            return '<div class="w3-filter" id="' + id + '"><button type="button" class="sm' + (current === '' ? ' active' : '') + '" ' + attr + '="">All</button>' +
                DATA.orderTypes.map(t => '<button type="button" class="sm' + (current === t ? ' active' : '') + '" ' + attr + '="' + esc(t) + '">' + esc(orderTypeLabel(t)) + '</button>').join('') + '</div>';
        }
        async function openHeldOrders() {
            openModal('<div id="heldSalesModal" class="w3-wide"><h2 id="heldSalesModalLabel">Held Orders</h2>' + typeFilterHtml('held-type-filters', 'data-held-type', W3_HELD_TYPE) +
                '<div id="held-sales-modal-body"><p class="muted">Loading…</p></div>' +
                '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div></div>');
            document.querySelectorAll('#held-type-filters [data-held-type]').forEach(b => b.onclick = () => { W3_HELD_TYPE = b.dataset.heldType || ''; openHeldOrders(); });
            try {
                const r = await api('GET', '/held-sales' + (W3_HELD_TYPE ? '?order_type=' + encodeURIComponent(W3_HELD_TYPE) : ''));
                const body = $('held-sales-modal-body'); if (!body) return;
                if (!r.held_sales.length) { body.innerHTML = '<p class="muted">No open checks.</p>'; return; }
                body.innerHTML = '<table class="w3-table"><thead><tr><th>Sale No</th><th>Type</th><th>Customer</th><th>Items</th><th>Total</th><th>Time</th><th></th></tr></thead><tbody>' +
                    r.held_sales.map(h => '<tr><td><strong>' + esc((h.sale_no || '').slice(-10)) + '</strong></td><td>' + esc(orderTypeLabel(h.order_type)) + (h.is_draft ? ' <span class="chip draft">DRAFT</span>' : '') + '</td>' +
                        '<td>' + esc(h.customer_name || 'Walk-in') + orderMeta(h) + '</td><td>' + esc(h.item_count) + '</td><td><strong>' + money(h.grand_total) + '</strong></td>' +
                        '<td class="muted">' + esc(w3When(h.updated_at || h.created_at)) + '</td>' +
                        '<td style="white-space:nowrap"><button class="sm primary" data-recall-id="' + h.id + '">Recall</button> <button class="sm danger" data-cancel-id="' + h.id + '" data-cancel-no="' + esc(h.sale_no) + '">Cancel</button></td></tr>').join('') +
                    '</tbody></table>';
                body.querySelectorAll('[data-recall-id]').forEach(b => b.onclick = () => { closeModal(); loadHeld(Number(b.dataset.recallId)).catch(e => toast(e.message)); });
                body.querySelectorAll('[data-cancel-id]').forEach(b => b.onclick = () => cancelHeldCheck(Number(b.dataset.cancelId), b.dataset.cancelNo, () => openHeldOrders()));
            } catch (e) { const body = $('held-sales-modal-body'); if (body) body.innerHTML = '<div class="err">' + esc(e.message) + '</div>'; else toast(e.message); }
        }
        function recallList() { return openHeldOrders(); }   // pre-W3 name (kept for every caller)

        async function loadHeld(id) {
            const d = await api('GET', '/held-sales/' + id);
            const h = d.held_sale;
            state.held = h;
            state.voidItems = {};
            state.session = h.restaurant_table_session_id
                ? Object.assign({ id: h.restaurant_table_session_id, table_no: h.table_no, waiter_name: h.waiter_name }, h.table_session || {}, { id: h.restaurant_table_session_id })
                : null;
            // A deal is ONE cart row (its header); its components ride underneath for display + split.
            state.cart = h.lines.filter(l => l.line_kind !== 'component').map(l => l.line_kind === 'combo_header'
                ? (() => {
                    const comps = h.lines.filter(c => c.parent_line_id === l.id).map(c => ({ id: c.id, name: c.product_name, sent: Number(c.kot_sent_quantity || 0), per_unit: l.quantity > 0 ? c.quantity / l.quantity : c.quantity }));
                    // R24/R26: a deal is LOCKED at the deals the kitchen already has (derived from its components' sent quantities),
                    // so reducing it routes through voidSentLine instead of failing at save.
                    const sentDeals = Math.max(Number(l.kot_sent_quantity || 0), ...comps.map(c => c.per_unit > 0 ? Math.ceil(c.sent / c.per_unit - 1e-9) : 0), 0);
                    return { key: 'l' + l.id, combo_id: l.combo_id, product_id: null, name: l.product_name, price: l.unit_price, quantity: l.quantity, line_id: l.id, kot_sent_quantity: sentDeals, deal: true,
                        header_sent: Number(l.kot_sent_quantity || 0), components: comps.map(c => (c.per_unit * l.quantity) + ' × ' + c.name), component_lines: comps };
                })()
                : { key: 'l' + l.id, product_id: l.product_id, name: l.product_name, price: l.unit_price, quantity: l.quantity, line_id: l.id, kot_sent_quantity: l.kot_sent_quantity,
                    // W2 re-hydration: options / variant / unit / kitchen note / line discount ride the carried line.
                    product_variant_id: l.product_variant_id || null, variant_name: l.variant_name || null, unit_code: l.unit_code || null,
                    modifiers: l.modifiers || [], kitchen_note: l.kitchen_note || '', discount_amount: Number(l.discount_amount || 0) });
            state.commercial = Object.assign({}, state.commercial, { discount_type: h.discount_type || 'none', discount_value: h.discount_value || 0, promo_code: h.promo_code || '', manager_approval_id: null,
                delivery_channel_id: h.delivery_channel_id, delivery_rider_id: h.delivery_rider_id, delivery_address: h.delivery_address || '', delivery_charge_amount: h.delivery_charge_amount ?? DATA.defaultDeliveryCharge });
            state.customer = h.customer_id ? { id: h.customer_id, name: h.customer_name, phone: h.customer_phone, addresses: [] } : null;
            state.dirty = false;
            lockOrderType(h.order_type);
            $('customer-name').value = h.customer_name || '';
            renderCart();
            // A29/R31 — HELD-SALE-DEAD-SESSION-1: the bill's table session was closed → reopen / move before it can continue.
            if (h.dead_session) openDeadSession(h.dead_session);
            return h;
        }
        // Unload the check (it stays saved on its table / in Held Orders) — the pre-W3 "Leave check / Leave table".
        function leaveCheck() {
            if (state.dirty && !confirm('Unsaved changes will be discarded. Leave?')) return;
            clearCart({ silent: true });
        }

        // ---- A37 Clear cart / New Order (Add Round) / New Sale (Online clearCart + start-fresh + new-sale). ----
        function clearCart(opts) {
            const fromClick = typeof Event !== 'undefined' && opts instanceof Event;
            opts = fromClick ? {} : (opts || {});
            if (fromClick && (state.cart.length || state.held || state.session) && !confirm('Clear cart?')) return;
            const keepSession = opts.preserveTable ? state.session : null;
            state.cart = []; state.held = null; state.dirty = false; state.voidItems = {}; state.pendingClientUuid = null;
            state.session = keepSession;
            state.commercial = { discount_type: 'none', discount_value: 0, promo_code: '', manager_approval_id: null,
                delivery_channel_id: null, delivery_rider_id: null, delivery_address: '', delivery_charge_amount: DATA.defaultDeliveryCharge };
            if (!opts.preserveTable) { state.customer = null; $('customer-name').value = ''; }
            if (state.session) lockOrderType('dine_in'); else unlockOrderType();
            renderCart();
        }
        function startFresh() {
            if (state.session && !state.held) { const s = $('search'); if (s) s.focus(); toast('Add the next round; only new quantities will print on the next KOT.'); return; }
            if (state.held && state.session) {
                // "Add Round" on a loaded table check: keep the table, the check stays saved; new items become the next round.
                if (state.dirty && !confirm('Unsaved changes will be discarded. Continue?')) return;
                const s = $('search'); if (s) s.focus();
                toast('Add the next round; only new quantities will print on the next KOT.'); return;
            }
            if ((state.cart.length || state.held) && !confirm('Start Fresh Order?\n\nThis unloads the recalled order. The held sale remains saved.')) return;
            clearCart({ silent: true });
        }
        function newSale() {
            const hasContext = state.cart.length > 0 || !!state.session || !!state.held;
            if (hasContext && !confirm('Start a completely new sale?\n\nAny open table check stays on its table and can be recalled later. Unsaved cart items will be discarded.')) return;
            closeModal(); clearCart({ silent: true }); unlockOrderType();
        }

        // ---- R25 — reduce a KITCHEN-SENT line: reason now (Online showVoidReasonModal), manager approval at Save round. ----
        // Team 2's changeQty() calls voidSentLine(line, newQty) when the new quantity drops below kot_sent_quantity.
        async function voidSentLine(line, newQty) {
            if (!line || !line.line_id) { toast('Only a line already sent to the kitchen needs a void.'); return; }
            newQty = Math.max(0, Number(newQty) || 0);
            let meta;
            try { meta = await voidMeta(); } catch (e) { toast(e.message); return; }
            if (!meta.reasons.length) { toast('Configure an active void reason before cancelling KOT items.'); return; }
            const pin = w3NeedsManager(meta.line_approval_mode);
            openModal('<h2>Void Reason</h2><p><strong>' + esc(line.name) + '</strong><br><span class="muted">This item was already sent to kitchen (KOT). ' +
                esc(line.kot_sent_quantity) + ' sent → ' + esc(newQty) + ' on the check. Please select a void reason.</span></p>' +
                '<div id="void-reason-list">' + meta.reasons.map(r => '<div class="list-row" data-void-reason="' + r.id + '"><span>' + esc(r.name) + '</span>' + (pin ? '<span class="chip draft">Manager code required</span>' : '') + '</div>').join('') + '</div>' +
                '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Cancel</button></div>');
            document.querySelectorAll('#void-reason-list [data-void-reason]').forEach(row => row.onclick = () => {
                const reason = meta.reasons.find(r => r.id === Number(row.dataset.voidReason));
                state.voidItems[line.line_id] = line.deal
                    // A DEAL: its kitchen-sent quantities live on the header + component rows; each becomes a void entry and the
                    // whole deal is ONE grouped void_kot_items approval (Online requestComboQuantity; MANAGER-APPROVAL-COMBO-VOID-1).
                    ? { deal: true, old_line_id: line.line_id, reason_id: reason.id, reason_name: reason.name, product_name: line.name,
                        parts: [{ old_line_id: line.line_id, sent: Number(line.header_sent || 0), per_unit: 1, name: line.name }]
                            .concat((line.component_lines || []).map(c => ({ old_line_id: c.id, sent: Number(c.sent || 0), per_unit: c.per_unit, name: c.name }))) }
                    : { old_line_id: line.line_id, sent: Number(line.kot_sent_quantity), reason_id: reason.id, reason_name: reason.name, product_name: line.name };
                if (newQty <= 0) state.cart = state.cart.filter(x => x.key !== line.key); else line.quantity = newQty;
                state.dirty = true; closeModal(); renderCart();
                toast('Void recorded (' + reason.name + ') — Save round to send the cancellation to the kitchen' + (pin ? ' (a manager approves it).' : '.'));
            });
        }
        // The void_items the server expects: exactly (sent − new quantity) per reduced sent line; a line raised back is dropped.
        function buildVoidItems() {
            const out = [];
            Object.values(state.voidItems || {}).forEach(v => {
                const row = state.cart.find(l => l.line_id === v.old_line_id);
                if (v.deal) {
                    const deals = row ? Number(row.quantity) : 0;
                    v.parts.forEach(p => {
                        const cancel = +(p.sent - Math.min(+(deals * p.per_unit).toFixed(3), p.sent)).toFixed(3);
                        if (cancel > 0) out.push({ old_line_id: p.old_line_id, quantity: cancel, reason_id: v.reason_id, product_name: p.name, deal: true });
                    });
                    return;
                }
                const cancel = +(v.sent - Math.min(row ? Number(row.quantity) : 0, v.sent)).toFixed(3);
                if (cancel > 0) out.push({ old_line_id: v.old_line_id, quantity: cancel, reason_id: v.reason_id, product_name: v.product_name });
            });
            return out;
        }
        // One manager approval per save: single line → void_kot_item; several → ONE grouped void_kot_items (shared service rule).
        async function approveVoids(items) {
            // A deal always asks for the GROUPED approval (Online requestComboQuantity), even when one row resolves — the
            // one-row case is accepted by the shared service once the canonical MANAGER-APPROVAL-COMBO-VOID-1 is reconciled (R26).
            const single = items.length === 1 && !items[0].deal;
            const payload = single
                ? { sales_order_id: state.held.id, sales_order_line_id: items[0].old_line_id, quantity: items[0].quantity }
                : { sales_order_id: state.held.id, cancellations: items.map(v => ({ line_id: v.old_line_id, quantity: v.quantity })).sort((a, b) => a.line_id - b.line_id) };
            const note = items.map(v => v.quantity + ' × ' + v.product_name).join(', ') + ' — already sent to the kitchen.';
            return askManagerApprovalFor(single ? 'void_kot_item' : 'void_kot_items', 'Manager approval — void kitchen item' + (single ? '' : 's'), note, payload);
        }

        // ---- Add Round: re-submit carried lines by id + new lines (+ voids); the server keeps captured prices + KOT-sent state. ----
        async function saveRound(asDraft, opts) {
            opts = opts || {};
            if (!state.held) return; if (!requireCart()) return;
            try {
                const payload = Object.assign({ held_sale_id: state.held.id, order_type: state.held.order_type, save_as_draft: !!asDraft, lines: cartLines() }, commercial());
                if (state.held.restaurant_table_session_id) payload.restaurant_table_session_id = state.held.restaurant_table_session_id;
                if (state.held.order_type === 'quick_sale') { payload.vehicle_number = state.held.vehicle_number || ''; payload.restaurant_waiter_id = state.held.waiter_id; }
                const voids = buildVoidItems();
                if (voids.length) {
                    payload.void_items = voids.map(v => ({ old_line_id: v.old_line_id, quantity: v.quantity, reason_id: v.reason_id }));
                    const meta = await voidMeta().catch(() => ({}));
                    if (w3NeedsManager(meta.line_approval_mode)) {
                        const approval = await approveVoids(voids);
                        if (!approval) { toast('Manager approval cancelled — the round was not saved.'); return false; }
                        payload.void_items.forEach(v => { v.manager_approval_id = approval; });
                    }
                }
                let s;
                try { s = await api('POST', '/held-sales', payload); }
                catch (e) {
                    // The branch mode changed since the reason book was read → ask once and retry (the server stays the judge).
                    if (voids.length && /manager approval is required|one manager approval is required/i.test(e.message)) {
                        const approval = await approveVoids(voids);
                        if (!approval) throw new Error('Manager approval cancelled — the round was not saved.');
                        payload.void_items.forEach(v => { v.manager_approval_id = approval; });
                        s = await api('POST', '/held-sales', payload);
                    } else { throw e; }
                }
                await loadHeld(s.sale_id);
                toast('Round saved on ' + s.sale_no + (voids.length ? ' — cancellation sent to the kitchen.' : ''));
                // T3-3 (D-06): the line-void CANCEL KOT / correction Reminder — a browser ticket opens in Print Here.
                if (typeof handlePrintJobs === 'function' && (s.void_print_jobs || []).length) handlePrintJobs(s.void_print_jobs, 'Reminder');
                if (!asDraft && !opts.noAutoKot) await kotAfterHold(s.sale_id);
                return true;
            } catch (e) { toast(e.message); return false; }
        }
        // ---- KOT: the unsent delta only (server sent-pool). Saves first when the cart changed. ----
        async function sendKot() {
            if (!state.held) return;
            if (state.dirty && !(await saveRound(state.held.is_draft, { noAutoKot: true }))) return;
            const id = state.held.id;
            // T3-1 (D-01/D-02/D-04): Team 5's KOT — routed at THIS counter, Online sent-bookkeeping, Print Here for browser
            // tickets, then the Reminder question. The pre-W5 held-sales KOT route stays as the fallback.
            if (typeof fireKot === 'function') { await fireKot(id); await loadHeld(id).catch(() => {}); return; }
            try {
                const k = await api('POST', '/held-sales/' + id + '/kot');
                if (!k.batch) { toast(k.message || 'Nothing new for the kitchen.'); }
                else { toast('KOT #' + k.batch.sequence_no + ' sent · ' + k.batch.lines.length + ' line(s)'); await loadHeld(id); }
            } catch (e) { toast(e.message); }
        }
        // Online handleKotAfterSale: after a Hold / Save round with unsent food → auto-KOT when the counter prints KOTs
        // automatically, otherwise ask "Print Kitchen Order?". A draft never sends (server-enforced too).
        async function kotAfterHold(saleId) {
            if (!state.held || state.held.is_draft || typeof fireKot !== 'function') { if (state.session) toast('Send the KOT when ready.'); return; }
            const pending = state.cart.some(l => Number(l.quantity) > Number(l.kot_sent_quantity || 0));
            if (!pending) return;
            const auto = typeof autoPrintEnabled === 'function' && autoPrintEnabled('kot');
            if (auto || confirm('Print Kitchen Order?\n\nSend the new items to the kitchen now.')) { await fireKot(saleId); await loadHeld(saleId).catch(() => {}); }
        }

        // ---- Split Bill (Online parity): move quantities onto a new held check on the same table; each pays on its own. ----
        async function splitBill() {
            if (!state.held) return;
            if (state.dirty && !(await saveRound(state.held.is_draft, { noAutoKot: true }))) return;
            const rows = state.cart.filter(l => l.line_id);
            openModal('<h2>Split Bill — ' + esc(state.held.sale_no) + '</h2><p class="muted">Choose how much of each item moves to the new check. The kitchen is not told again.</p>' +
                rows.map(l => '<div class="line"><div><div class="ln-nm">' + esc(l.name) + (l.deal ? ' <span class="chip hot" style="padding:.05rem .4rem">Deal</span>' : '') + '</div><div class="ln-sub">' + l.quantity + ' on this check @ ' + money(l.price) + '</div></div>' +
                    '<div class="ln-ctl"><input type="number" class="sb-qty" data-key="' + esc(l.key) + '" min="0" max="' + l.quantity + '" step="1" value="0" style="width:5rem"></div></div>').join('') +
                '<div class="field"><label>Notes (optional)</label><input type="text" id="sb-notes" maxlength="500" placeholder="e.g. guest 2 pays separately"></div>' +
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
                    const notes = ($('sb-notes')?.value || '').trim();
                    const r = await api('POST', '/held-sales/' + state.held.id + '/split', notes ? { lines, notes } : { lines });
                    closeModal();
                    toast('Split into ' + r.child.sale_no + ' — pay each check from Held Orders or the Table Board.');
                    await loadHeld(r.parent.status === 'held' ? r.parent.id : r.child.id);
                } catch (e) { $('sb-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
        }

        // ---- R27 / A37 Cancel Order: a held check → reason (+ manager approval in manager_required mode); an unsaved cart → Clear Cart?. ----
        async function cancelOrder() {
            if (!state.held) {
                if (!state.cart.length && !state.session) { toast('Nothing to cancel'); return; }
                if (confirm('Clear the current unsaved cart?')) { clearCart({ silent: true, preserveTable: !!state.session }); toast('Cart cleared'); }
                return;
            }
            const held = state.held;
            await cancelHeldCheck(held.id, held.sale_no, () => { if (state.held && state.held.id === held.id) clearCart({ silent: true }); });
        }
        async function cancelHeldCheck(saleId, saleNo, onDone) {
            let meta;
            try { meta = await voidMeta(); } catch (e) { toast(e.message); return; }
            if (!meta.reasons.length) { toast('Configure an active void reason before cancelling held orders.'); return; }
            const pin = w3NeedsManager(meta.order_approval_mode);
            openModal('<h2>Cancel Held Order ' + esc((saleNo || '').slice(-10)) + '</h2><p class="muted">Select the reason. Sent quantities will produce a Cancel KOT' + (pin ? '; a manager approves when food was already sent.' : '.') + '</p>' +
                '<div class="field"><label>Reason</label><select id="cx-reason">' + meta.reasons.map(x => '<option value="' + x.id + '">' + esc(x.name) + '</option>').join('') + '</select></div>' +
                '<div id="cx-err"></div><div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Back</button><button class="danger" id="cx-ok">Cancel order</button></div>');
            $('cx-ok').onclick = async () => {
                const reasonId = Number($('cx-reason').value);
                const submit = body => api('POST', '/held-sales/' + saleId + '/cancel', body);
                try {
                    let r;
                    try { r = await submit({ reason_id: reasonId }); }
                    catch (e) {
                        // manager_required branch + food already sent: obtain the approval and retry (the Online PIN step).
                        if (!/manager approval/i.test(e.message)) throw e;
                        const approval = await askManagerApprovalFor('cancel_held_order', 'Manager approval — cancel order', 'Order ' + (saleNo || '') + ' has food the kitchen already has. A manager must approve the cancellation.', { sales_order_id: saleId });
                        if (!approval) { toast('Manager approval cancelled — the order was NOT cancelled.'); return; }
                        r = await submit({ reason_id: reasonId, manager_approval_id: approval });
                    }
                    closeModal();
                    // T3-2 (D-05): a browser/fallback CANCEL KOT opens in Print Here.
                    if (typeof handlePrintJobs === 'function' && r && (r.jobs || []).length) handlePrintJobs(r.jobs, 'CANCEL KOT');
                    toast((saleNo ? saleNo.slice(-10) + ' ' : '') + 'cancelled and kitchen notified.');
                    if (onDone) onDone();
                } catch (e) { const el = $('cx-err'); if (el) el.innerHTML = '<div class="err">' + esc(e.message) + '</div>'; else toast(e.message); }
            };
        }

        // ---- A27 Recent / Completed Orders (Online completedOrdersModal): filters, reprint receipt / KOT, resume printing. ----
        let W3_RECENT_TYPE = '';
        async function openCompletedOrders() {
            openModal('<div id="completedOrdersModal" class="w3-wide"><h2 id="completedOrdersModalLabel">Recent Orders</h2>' + typeFilterHtml('recent-type-filters', 'data-recent-type', W3_RECENT_TYPE) +
                '<div id="completed-orders-modal-body"><p class="muted">Loading…</p></div>' +
                '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div></div>');
            document.querySelectorAll('#recent-type-filters [data-recent-type]').forEach(b => b.onclick = () => { W3_RECENT_TYPE = b.dataset.recentType || ''; openCompletedOrders(); });
            try {
                const r = await api('GET', '/recent-sales' + (W3_RECENT_TYPE ? '?order_type=' + encodeURIComponent(W3_RECENT_TYPE) : ''));
                const body = $('completed-orders-modal-body'); if (!body) return;
                if (!r.sales.length) { body.innerHTML = '<p class="muted">No recent orders on this branch.</p>'; return; }
                body.innerHTML = '<table class="w3-table"><thead><tr><th>Sale No</th><th>Type</th><th>Customer</th><th>Total</th><th>Time</th><th></th></tr></thead><tbody>' +
                    r.sales.map(s => '<tr><td><strong>' + esc((s.sale_no || '').slice(-10)) + '</strong></td><td>' + esc(orderTypeLabel(s.order_type)) +
                        (s.status !== 'paid' ? ' <span class="chip draft">' + esc(s.status) + '</span>' : '') + '</td><td>' + esc(s.customer) + orderMeta(s) + '</td>' +
                        '<td><strong>' + money(s.grand_total) + '</strong></td><td class="muted">' + esc(w3When(s.time)) + '</td><td style="white-space:nowrap">' +
                        (s.status === 'paid' ? '<button class="sm" data-rc-receipt="' + s.id + '">Receipt</button> ' : '') +
                        '<button class="sm" data-rc-kot="' + s.id + '">KOT</button> ' +
                        '<button class="sm" data-rc-prints="' + s.id + '" data-sale-no="' + esc(s.sale_no) + '">Prints</button> ' +
                        (s.printing && s.printing.resume_available ? '<button class="sm warn" data-rc-resume="' + s.id + '" data-sale-no="' + esc(s.sale_no) + '">Resume</button>' : '') + '</td></tr>').join('') +
                    '</tbody></table>';
                body.querySelectorAll('[data-rc-receipt]').forEach(b => b.onclick = () => recentReprint(Number(b.dataset.rcReceipt), 'receipt'));
                body.querySelectorAll('[data-rc-kot]').forEach(b => b.onclick = () => recentReprint(Number(b.dataset.rcKot), 'kot'));
                // T3-4: the sale's prints (Team 5 lastPrintModal: retry / reprint KOT / reprint receipt / Reminder).
                const lastPrint = (id, no) => { if (typeof openLastPrint === 'function') openLastPrint(id, no); else recentPrints(); };
                body.querySelectorAll('[data-rc-prints]').forEach(b => b.onclick = () => lastPrint(Number(b.dataset.rcPrints), b.dataset.saleNo));
                body.querySelectorAll('[data-rc-resume]').forEach(b => b.onclick = () => lastPrint(Number(b.dataset.rcResume), b.dataset.saleNo));
            } catch (e) { const body = $('completed-orders-modal-body'); if (body) body.innerHTML = '<div class="err">' + esc(e.message) + '</div>'; else toast(e.message); }
        }
        // Reprint through the existing Edge print endpoints (Team 5 owns printing; openLastPrint(saleId) takes over when defined).
        async function recentReprint(saleId, kind) {
            if (typeof openLastPrint === 'function') { openLastPrint(saleId); return; }
            try {
                if (kind === 'kot') { const k = await api('POST', '/sales/' + saleId + '/kot-reprint', {}); toast('KOT reprint → ' + ((k.jobs && k.jobs[0] && k.jobs[0].printer_name) || 'queued')); if (k.jobs && k.jobs[0] && k.jobs[0].fallback) printHere(k.jobs[0]); }
                else { const j = await api('POST', '/sales/' + saleId + '/receipt', { reprint: true }); toast('Receipt reprint → ' + (j.printer_name || 'queued')); if (j.fallback) printHere(j); }
            } catch (e) { toast(e.message); }
        }

        // ---- A29 / R31 dead-session recovery (Online deadSessionModal → POST reattach-table). ----
        async function openDeadSession(info) {
            let freeHtml = '';
            try {
                const b = await api('GET', '/restaurant/board');
                (b.floors || []).forEach(f => {
                    const free = (f.tables || []).filter(t => !t.session && !t.reservation && ['available', 'cleaning'].includes(t.status));
                    if (free.length) freeHtml += '<optgroup label="' + esc(f.name) + '">' + free.map(t => '<option value="' + t.id + '">Table ' + esc(t.table_no || t.name) + '</option>').join('') + '</optgroup>';
                });
            } catch (e) { /* the picker stays empty; the server refuses anything unsafe */ }
            openModal('<div id="deadSessionModal"><h2 id="deadSessionModalLabel">This table is closed</h2>' +
                '<p><strong>Table ' + esc(info.table_no || '—') + '</strong><br><span class="muted">' + (info.closed_by ? 'Closed by <strong>' + esc(info.closed_by) + '</strong>' : '') + (info.closed_at ? ' · ' + esc(w3When(info.closed_at)) : '') + '</span></p>' +
                '<div class="row" style="display:flex;justify-content:space-between;border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:.4rem 0"><span class="muted">' + esc(info.sale_no) + '</span><strong>Rs ' + money(info.total) + '</strong></div>' +
                '<p class="muted" style="font-size:.8rem">The kitchen ticket already printed with the old table (' + esc(info.table_no || '—') + '). Moving this bill does <strong>not</strong> reprint it — the food is already made.</p>' +
                (info.can_reopen && info.table_id ? '<div class="btn-row left"><button class="primary" id="dead-reopen" data-table-id="' + info.table_id + '">Reopen table ' + esc(info.table_no) + '</button></div>'
                    : '<div class="err">Table ' + esc(info.table_no || '—') + ' has new guests on it now, so this bill cannot go back there. Pick a free table below.</div>') +
                '<div style="display:flex;gap:.4rem;margin-top:.6rem"><select id="dead-table-pick" style="flex:1"><option value="">— Pick a free table —</option>' + freeHtml + '</select><button id="dead-move">Move here</button></div>' +
                '<div id="dead-err"></div><div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Not now</button></div></div>');
            const move = async (tableId, btn) => {
                if (!tableId) { $('dead-err').innerHTML = '<div class="err">Pick a free table.</div>'; return; }
                if (btn) btn.disabled = true;
                try {
                    const r = await api('POST', '/held-sales/' + info.sale_id + '/reattach-table', { restaurant_table_id: Number(tableId) });
                    closeModal(); toast(r.message || 'Bill moved.');
                    await loadHeld(info.sale_id);
                } catch (e) { if (btn) btn.disabled = false; $('dead-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
            const re = $('dead-reopen'); if (re) re.onclick = () => move(re.dataset.tableId, re);
            $('dead-move').onclick = () => move($('dead-table-pick').value, $('dead-move'));
        }

        // ---- R21 / A28 Change Order Details (Online changeOrderModal): order type · table session · terminal · branch (bound). ----
        async function openChangeOrder() {
            const held = state.held, curType = held ? held.order_type : (state.session ? 'dine_in' : state.orderType);
            const types = DATA.orderTypes || [];
            openModal('<div id="changeOrderModal"><h2 id="changeOrderModalLabel">Edit Order Details</h2>' +
                '<div class="field"><label>Order Type</label><div class="w3-filter" id="co-type-btns">' + types.map(t => '<button type="button" class="sm co-type-btn" data-co-type="' + esc(t) + '"' +
'>' + esc(orderTypeLabel(t)) + '</button>').join('') +
                '</div><input type="hidden" id="co-order-type" value=""></div>' +
                '<div class="field" id="co-table-wrap" hidden><label>Table Session</label><select id="co-table-session"><option value="">— Select Table —</option></select>' +
                '<div class="muted" style="font-size:.75rem" id="co-table-hint"></div></div>' +
                '<div class="field"><label>Terminal</label><select id="co-terminal"' + (!DATA.canChangeTerminal && DATA.terminals.length <= 1 ? ' disabled' : '') + '>' +
                    DATA.terminals.map(t => '<option value="' + t.id + '"' + (Number(state.terminalId) === t.id ? ' selected' : '') + '>' + esc(t.name) + '</option>').join('') + '</select></div>' +
                '<div class="field"><label>Branch</label><select id="co-branch" disabled><option>' + esc(DATA.branchName || '') + '</option></select>' +
                '<div class="muted" style="font-size:.75rem">This Branch Server is bound to its branch — a different branch is served by its own Branch Server.</div></div>' +
                '<div id="co-err"></div><div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Cancel</button><button class="primary" id="co-apply-btn">Apply Changes</button></div></div>');
            let tables = [];
            const setType = async type => {
                $('co-order-type').value = type;
                document.querySelectorAll('.co-type-btn').forEach(b => b.classList.toggle('active', b.dataset.coType === type));
                const dine = type === 'dine_in';
                $('co-table-wrap').hidden = !dine;
                if (!dine || tables.length) return;
                try {
                    tables = (await api('GET', '/restaurant/table-sessions')).sessions || [];
                    const curTable = state.session ? Number(state.session.table_id || 0) : 0;
                    $('co-table-session').innerHTML = '<option value="">— Select Table —</option>' + tables.map(t => '<option value="' + t.table_id + '"' + (t.table_id === curTable ? ' selected' : '') + '>' +
                        esc(t.label) + (t.waiter ? ' (' + esc(t.waiter) + ')' : '') + (t.has_session ? (t.held_sale_ids.length ? ' · open check' : ' · open') : (t.reserved ? ' · reserved' : ' ✦ New')) + '</option>').join('');
                    $('co-table-hint').textContent = held ? 'This check moves to the chosen table (a free table is opened for it); the rest of the old table stays.' : 'A free table is opened for this order; an open table takes it (as the next round when it already has a check).';
                } catch (e) { $('co-table-session').innerHTML = '<option value="">' + esc(e.message) + '</option>'; }
            };
            document.querySelectorAll('.co-type-btn').forEach(b => b.onclick = () => { if (!b.disabled) setType(b.dataset.coType); });
            setType(curType);
            $('co-apply-btn').onclick = async () => {
                const err = m => { $('co-err').innerHTML = '<div class="err">' + esc(m) + '</div>'; };
                const type = $('co-order-type').value, termId = Number($('co-terminal').value || 0);
                if (!type) { err('Please select an order type'); return; }
                try {
                    if (termId && termId !== Number(state.terminalId)) { const sel = $('terminal'); if (sel) sel.value = String(termId); await selectTerminal(termId); }
                    const tableId = Number($('co-table-session').value || 0);
                    const pick = tables.find(t => t.table_id === tableId);
                    if (type === 'dine_in') {
                        if (!pick) { err('Please select a table'); return; }
                        if (held) {
                            if (held.order_type === 'dine_in' && state.session && pick.table_id === Number(state.session.table_id)) { closeModal(); toast('Order details updated'); return; }
                            // Online Change Order + Hold: THIS check is re-targeted to the chosen table's session (a free table is
                            // opened first); the rest of the old table stays where it is. Server: EdgeLocalPosService change_order_details.
                            let sid = pick.session_id;
                            if (!pick.has_session) {
                                if (pick.reserved && !confirm('Table ' + pick.table_no + ' is reserved — open it for this order?')) return;
                                sid = (await api('POST', '/restaurant/tables/' + pick.table_id + '/open', { guest_count: 1 })).session_id;
                            } else if (pick.held_sale_ids.length && !confirm(pick.label + ' already has an open check — add this check to that table as a separate check?')) return;
                            await retargetHeld('dine_in', sid);
                            closeModal(); toast('Order moved to ' + pick.label + '.'); return;
                        }
                        // A plain / unsaved cart: attach it to the table WITHOUT losing the punched items.
                        if (pick.has_session && pick.held_sale_ids.length) { closeModal(); await continueOpenCheckWithCart(pick.session_id); return; }
                        let session;
                        if (pick.has_session) session = { id: pick.session_id, table_id: pick.table_id, table_no: pick.table_no, waiter_name: pick.waiter };
                        else {
                            if (pick.reserved && !confirm('Table ' + pick.table_no + ' is reserved — open it for this order?')) return;
                            const o = await api('POST', '/restaurant/tables/' + pick.table_id + '/open', { guest_count: 1 });
                            session = Object.assign({ table_id: pick.table_id, table_no: pick.table_no }, o.session || {}, { id: o.session_id });
                        }
                        state.session = session; lockOrderType('dine_in');
                    } else {
                        if (held) { if (type !== held.order_type) await retargetHeld(type, null); closeModal(); toast('Order details updated'); return; }
                        state.session = null; unlockOrderType(); state.orderType = type; const sel = $('order-type'); if (sel) sel.value = type;
                    }
                    closeModal(); renderCart(); toast('Order details updated');
                } catch (e) { err(e.message); }
            };
        }
        // R21 — re-target a HELD check (Online Change Order → Hold writes the posted order type + table session): a revision with
        // change_order_details; carried lines keep their ids / captured prices / KOT-sent state (server-enforced).
        async function retargetHeld(orderType, sessionId) {
            const held = state.held; if (!held) return;
            const payload = Object.assign({ held_sale_id: held.id, order_type: orderType, change_order_details: true, save_as_draft: !!held.is_draft, lines: cartLines() }, commercial());
            if (sessionId) payload.restaurant_table_session_id = sessionId;
            if (orderType === 'quick_sale') {
                const q = typeof quickSaleAttribution === 'function' && $('vehicle_number') ? quickSaleAttribution() : { vehicle_number: held.vehicle_number || '', restaurant_waiter_id: held.waiter_id };
                if (!q.vehicle_number || !q.restaurant_waiter_id) { const a = await askQuickSaleAttribution(); if (!a) throw new Error('A Quick Sale needs a vehicle number and a waiter.'); Object.assign(payload, a); }
                else Object.assign(payload, q);
            }
            const s = await api('POST', '/held-sales', payload);
            await loadHeld(s.sale_id);
        }
