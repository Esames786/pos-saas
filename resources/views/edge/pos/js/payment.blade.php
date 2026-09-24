{{-- W2 js/payment (Team 2; tender/permission rules shared with Team 4): the Online paymentModal (O:857-1013) — payment method select
     listing the branch's methods (CASH is taken; card/provider = the accepted Cloud-only exclusion; bank transfer / cheque / other =
     owner decision — listed disabled with the truthful hint, never enabled), tendered amount with quick-cash buttons (updateQuickCash
     O:3496-3524), reference, the commercial controls (js/commercial), live Change row, short-tender helper (O:3889-3900), the Team 5
     print-preference panel, the Online totals column (#subtotal-view … #change-view), Complete Sale (busy state, manager-approval
     retry, idempotent client_uuid) and Preview Bill. Pre-checks run BEFORE the modal opens like Online (empty cart, delivery
     customer, quick-sale vehicle + waiter). Kept names: reviewAndPay, completeSale, showRpErr. --}}
        // Online requireDeliveryCustomer (O:4461-4493): an OWN-delivery order needs a customer before payment / hold.
        function requireDeliveryCustomer() {
            if (effectiveOrderType() !== 'delivery' || state.session || state.held) return true;
            const c = state.commercial;
            if (!c.delivery_channel_id) { toast('Select a delivery channel for delivery orders.', 'warning'); $('delivery_channel_id').focus(); return false; }
            const ch = DATA.deliveryChannels.find(x => x.id === Number(c.delivery_channel_id));
            if (ch && ch.type !== 'aggregator') {
                if (!c.delivery_rider_id) { toast('Select a rider for own-delivery orders.', 'warning'); $('delivery_rider_id').focus(); return false; }
                if (!state.customer) { toast('Attach a customer before saving a delivery order.', 'warning'); openCustomerModal(); return false; }
            }
            return true;
        }
        function selectedTender() { const sel = $('payment_method_id'); const id = Number(sel ? sel.value : 0); return DATA.tenderMethods.find(m => m.id === id) || null; }
        function quickCashAmounts(total) {
            const out = [total];
            for (const r of [10, 50, 100, 500, 1000, 2000, 5000, 10000]) { const x = Math.ceil(total / r) * r; if (x > total && !out.includes(x)) { out.push(x); if (out.length >= 5) break; } }
            return out;
        }
        // ---- Review & Pay: cash settlement — a new sale, or a held check (its OWN shift takes the cash). ----
        async function reviewAndPay() {
            if (!requireCart()) return;
            if (!state.terminalId) { toast('Select a terminal first.'); return; }
            if (!requireDeliveryCustomer()) return;
            if (!requireQuickSaleFields()) return;
            ensureClientUuid(); // one identity per payment attempt (approval binding + idempotent retry)
            let totals = {};
            try {
                if (state.held) { if (state.dirty && !(await saveRound(state.held.is_draft))) return; totals = Object.assign({}, state.held); }
                else { const p = await api('POST', '/preview-bill', quotePayload()); totals = p.totals || {}; state.lastPromo = p.promo || null; state.lastQuote = totals; }
            } catch (e) { toast(e.message); return; }
            const tip = state.held ? 0 : Number(state.commercial.tip_amount || 0);
            if (state.held && Number(state.commercial.tip_amount || 0) > 0) totals.tip_amount = Number(state.commercial.tip_amount);
            state.lastTotals = totals;
            const grand = Number(totals.grand_total || 0);
            const cash = DATA.tenderMethods.find(m => m.offline) || null;
            const tipBlocked = Number(totals.tip_amount || tip || 0) > 0 && (!DATA.tipsSyncable || !!state.held);
            const r = (label, id, v, show, rowId) => '<div class="row"' + (rowId ? ' id="' + rowId + '"' : '') + (show ? '' : ' hidden') + '><span>' + label + '</span><strong id="' + id + '">' + v + '</strong></div>';
            const promoAmt = Number(totals.promotion_discount_amount || 0), manualAmt = Number(totals.manual_discount_amount ?? (Number(totals.discount_amount || 0) - promoAmt));
            openModal('<div id="paymentModal"><h2 id="paymentModalLabel"><span id="payment_heading">Review &amp; Pay' + (state.held ? ' — ' + esc(state.held.sale_no) : '') + '</span></h2>' +
                '<div class="w2-pay" style="display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:1rem">' +
                '<div>' +
                (DATA.canCompleteSale ? '<div class="field"><label for="payment_method_id">Payment Method</label><select id="payment_method_id">' +
                    DATA.tenderMethods.map(m => '<option value="' + m.id + '" data-type="' + esc(m.method_type) + '"' + (m.offline ? '' : ' disabled') + (cash && m.id === cash.id ? ' selected' : '') + '>' + esc(m.name) + (m.offline ? '' : ' — ' + esc(m.hint || '')) + '</option>').join('') + '</select>' +
                    '<span class="muted" style="font-size:.72rem" id="payment-method-hint">Cash is taken on the Branch Server. Card / provider payments run on the Online POS; bank transfer, cheque and other manual methods await the owner\'s decision.</span></div>' +
                    '<div class="field"><label for="tendered_amount">Tendered Amount</label><input type="number" id="tendered_amount" value="' + money(grand) + '" min="0" step="0.01" style="font-size:1.2rem;text-align:right">' +
                    '<div id="quick-cash-buttons" style="display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.3rem">' + quickCashAmounts(grand).map(a => '<button type="button" class="sm" data-quick-cash="' + money(a) + '">' + money(a) + '</button>').join('') + '</div></div>' +
                    '<div class="field"><label for="transaction_ref">Reference</label><input type="text" id="transaction_ref" maxlength="190" placeholder="Optional reference"></div>' : '') +
                commercialPanelHtml() +
                (cash ? '' : '<div class="err">No cash payment method is configured.</div>') +
                (typeof printPrefsHtml === 'function' ? printPrefsHtml() : '') +
                '</div>' +
                '<div class="totals" id="payment-totals" style="border:1px solid var(--line);border-radius:10px;align-self:start">' +
                r('Subtotal', 'subtotal-view', money(totals.subtotal ?? totals.sub_total ?? 0), true) +
                '<div class="row" id="promo-discount-row"' + (promoAmt > 0 ? '' : ' hidden') + '><span id="promo-discount-label">' + esc((state.lastPromo && state.lastPromo.promotion_name) || (totals.promo_code ? 'Promo (' + totals.promo_code + ')' : 'Promo')) + '</span><strong id="promo-discount-view">−' + money(promoAmt) + '</strong></div>' +
                r('Discount', 'discount-view', money(state.held && !totals.manual_discount_amount ? Number(totals.discount_amount || 0) : manualAmt), true) +
                r('Tax', 'tax-view', money(totals.tax_amount || 0), true) +
                r('Service charge', 'service-charge-view', money(totals.service_charge_amount ?? totals.service_charge ?? 0), Number(totals.service_charge_amount ?? totals.service_charge ?? 0) > 0, 'service-charge-row') +
                r('Tip', 'tip-view', money(totals.tip_amount || 0), Number(totals.tip_amount || 0) > 0, 'tip-row') +
                r('Delivery charge', 'delivery-charge-view', money(totals.delivery_charge_amount || 0), Number(totals.delivery_charge_amount || 0) > 0, 'delivery-charge-row') +
                '<div class="row grand"><span>Grand total</span><strong id="grand-total-view">' + money(grand) + '</strong></div>' +
                r('Change', 'change-view', money(0), true) + '</div>' +
                '</div>' +
                '<div id="rp-err"></div>' +
                // COMPLETE SALE PERMISSION parity (Online f12f1fc): Review & Pay opens for everyone (preview, discount later);
                // taking the payment is gated on tenant.pos.store — the button is absent and the Online hint shows instead.
                '<div class="btn-row"><button type="button" class="ghost" onclick="EdgePOS.closeModal()">Back</button>' +
                (DATA.canCompleteSale ? '<button type="button" class="ok" id="complete-sale-btn"' + (cash && !tipBlocked ? '' : ' disabled') + '>' + (state.held && state.held.table_no ? 'Close &amp; Pay Table Bill' : 'Complete Sale') + '</button>'
                    : '<span class="muted" style="align-self:center">Apply the discount, then <strong>Hold</strong> — a counter will close the bill.</span>') +
                '<button type="button" id="payment-bill-preview-btn">Preview Bill</button></div></div>');
            const tendered = $('tendered_amount');
            const refresh = () => {
                if (!tendered) return;
                const tv = Number(tendered.value || 0), short = grand - tv, tender = selectedTender();
                $('change-view').textContent = money(Math.max(tv - grand, 0));
                const row = $('short-tender-row');
                if (row) { if (tender && tender.offline && short > 0.009) { $('short-tender-message').textContent = 'Short by ' + money(short) + '. Apply a discount or collect the balance.'; row.hidden = false; } else row.hidden = true; }
            };
            if (tendered) {
                tendered.addEventListener('input', refresh);
                tendered.addEventListener('keydown', e => { if (e.key === 'Enter' && $('complete-sale-btn') && !$('complete-sale-btn').disabled) { e.preventDefault(); $('complete-sale-btn').click(); } });
                document.querySelectorAll('#quick-cash-buttons [data-quick-cash]').forEach(b => b.addEventListener('click', () => { tendered.value = b.dataset.quickCash; refresh(); }));
                $('payment_method_id').addEventListener('change', refresh);
                setTimeout(() => { tendered.focus(); tendered.select(); }, 50);
            }
            refresh(); if (typeof refreshPrintPanel === 'function') refreshPrintPanel();
            const btn = $('complete-sale-btn'); if (btn) btn.addEventListener('click', () => completeSale(grand, selectedTender() || cash));
            $('payment-bill-preview-btn').addEventListener('click', previewBill);
            wireCommercialPanel(reviewAndPay); // any commercial change re-opens the modal on the server's new totals
        }
        async function completeSale(grand, cash) {
            const btn = $('complete-sale-btn'); if (btn) btn.disabled = true;
            const tendered = Number(($('tendered_amount') || {}).value || 0);
            if (!cash || !cash.offline) { showRpErr('Only cash can be taken on the Branch Server.'); if (btn) btn.disabled = false; return; }
            if (tendered < grand) { showRpErr('Cash tendered is less than the total.'); if (btn) btn.disabled = false; return; }
            const ref = (($('transaction_ref') || {}).value || '').trim();
            const payments = [Object.assign({ payment_method_id: cash.id, amount: grand, tendered_amount: tendered }, ref ? { transaction_ref: ref } : {})];
            // Team 5 print preferences → the Online Direct Pay intents (default = the current Edge behaviour: receipt, no Direct-Pay KOT).
            const prefs = Object.assign({ kot_print_intent: 'skip', receipt_print_intent: 'print' }, typeof readPrintPrefs === 'function' ? (readPrintPrefs() || {}) : {});
            try {
                let sale;
                if (state.held) {
                    sale = await api('POST', '/held-sales/' + state.held.id + '/settle', { client_uuid: state.pendingClientUuid, payments });
                } else {
                    const payload = Object.assign({ order_type: state.orderType, client_uuid: state.pendingClientUuid, lines: cartLines(), payments }, commercial(), prefs);
                    if (state.orderType === 'quick_sale') Object.assign(payload, quickSaleAttribution());
                    sale = await api('POST', '/sales', payload);
                }
                closeModal();
                const soldLines = state.cart.slice();
                const syncNote = sale.edge_sync_state && sale.edge_sync_state !== 'acknowledged' ? ' · Pending sync' : '';
                state.held = null; state.session = null; state.cart = []; state.dirty = false; state.customer = null; state.pendingClientUuid = null; state.lastQuote = null; state.lastPromo = null;
                state.commercial = Object.assign({}, state.commercial, { discount_type: 'none', discount_value: 0, promo_code: '', manager_approval_id: null, tip_amount: 0, tip_mode: 'none', delivery_address: '', delivery_rider_id: null, delivery_channel_id: null });
                $('customer-name').value = ''; $('vehicle_number').value = ''; $('qs-waiter-select').value = '';
                // a sold item leaves the operational stock: reflect it on the tiles at once (the server already decremented it)
                applySoldToTileStock(soldLines);
                unlockOrderType(); renderCart();
                toast('Sale ' + (sale.sale_no || '#' + sale.sale_id) + ' completed · change ' + money(sale.change_amount || 0) + syncNote, 'success');
                // T2-1: Team 5's afterSalePrinting runs Direct Pay printing (sale.printing / print_intents), else the auto receipt.
                if (typeof afterSalePrinting === 'function') afterSalePrinting(sale);
                else if ((sale.print_intents ? sale.print_intents.receipt : prefs.receipt_print_intent) !== 'skip' && typeof autoReceipt === 'function') autoReceipt(sale.sale_id);
                if (typeof refreshSync === 'function') refreshSync();
            } catch (e) {
                // DISCOUNT parity: the server demands a manager for this discount → the manager approves with their own credential.
                if (/manager approval/i.test(e.message) && !state.commercial.manager_approval_id) {
                    const t = state.lastTotals || {};
                    const approvalId = await askManagerApproval({ sales_order_id: state.held ? state.held.id : 0, branch_id: DATA.branchId, client_uuid: state.held ? '' : state.pendingClientUuid,
                        discount_type: state.commercial.discount_type, discount_value: Number(state.commercial.discount_value || 0), discount_amount: Number(t.manual_discount_amount ?? t.discount_amount ?? 0) });
                    if (approvalId) { state.commercial.manager_approval_id = approvalId; if (state.held) state.dirty = true; reviewAndPay(); return; }
                    reviewAndPay(); return;
                }
                showRpErr(e.message); if (btn) btn.disabled = false;
            }
        }
        // The page's copy of the operational balances follows a completed sale (the server is the authority; a reload re-reads it).
        function applySoldToTileStock(lines) {
            const take = (pid, vid, qty) => {
                const p = productById(pid); if (!p || p.stock_kind !== 'tracked') return;
                const v = vid ? productVariants(p).find(x => x.id === Number(vid)) : defaultVariant(p);
                if (v && v.stock !== null && v.stock !== undefined) v.stock = Number(v.stock) - qty;
                if (!v || (p.default_variant_id && v.id === p.default_variant_id)) { if (p.stock !== null && p.stock !== undefined) p.stock = Number(p.stock) - qty; }
            };
            (lines || []).forEach(l => {
                if (l.combo_id) { const c = DATA.combos.find(x => x.id === Number(l.combo_id)); (c ? c.components : []).forEach(cc => take(cc.product_id, cc.product_variant_id, Number(cc.quantity || 0) * Number(l.quantity || 0))); }
                else take(l.product_id, l.product_variant_id, Number(l.quantity || 0));
            });
        }
        function showRpErr(m) { const e = $('rp-err'); if (e) e.innerHTML = '<div class="err">' + esc(m) + '</div>'; }
