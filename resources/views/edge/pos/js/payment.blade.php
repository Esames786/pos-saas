{{-- W0 js/payment (Team 2 owns from W2; tender/permission rules shared with Team 4): Review & Pay + Complete Sale. --}}
        // ---- Review & Pay: cash settlement — a new sale, or a held check (its OWN shift takes the cash). ----
        async function reviewAndPay() {
            if (!requireCart()) return;
            if (!state.terminalId) { toast('Select a terminal first.'); return; }
            if (!state.pendingClientUuid) state.pendingClientUuid = uuid(); // one identity per payment attempt (approval binding + idempotent retry)
            let totals = {};
            try {
                if (state.held) { if (state.dirty && !(await saveRound(state.held.is_draft))) return; totals = state.held; }
                else { const p = await api('POST', '/preview-bill', Object.assign({ order_type: state.orderType, lines: cartLines() }, commercial())); totals = p.totals || {}; }
            } catch (e) { toast(e.message); return; }
            state.lastTotals = totals;
            const grand = Number(totals.grand_total || 0);
            const cash = DATA.paymentMethods[0];
            const needsQuickSale = !state.held && state.orderType === 'quick_sale';
            openModal('<h2>Review &amp; Pay' + (state.held ? ' — ' + esc(state.held.sale_no) : '') + '</h2>' + rowsHtml(totals) + commercialPanelHtml() +
                (cash ? '' : '<div class="err">No cash payment method is configured.</div>') +
                (needsQuickSale ? '<div class="field"><label>Vehicle #</label><input type="text" id="rp-vehicle"></div><div class="field"><label>Waiter</label><select id="rp-waiter">' + DATA.waiters.map(w => '<option value="' + w.id + '">' + esc(w.name) + '</option>').join('') + '</select></div>' : '') +
                (DATA.canCompleteSale ? '<div class="field"><label>Cash tendered</label><input type="number" id="rp-tendered" value="' + money(grand) + '" min="' + money(grand) + '" step="0.01"></div>' : '') +
                '<div id="rp-err"></div>' +
                // COMPLETE SALE PERMISSION parity (Online f12f1fc): Review & Pay opens for everyone (preview, discount later);
                // taking the payment is gated on tenant.pos.store — the button is absent and the Online hint shows instead.
                '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Cancel</button>' +
                (DATA.canCompleteSale ? '<button class="ok" id="rp-complete"' + (cash ? '' : ' disabled') + '>Complete Sale</button>'
                    : '<span class="muted" style="align-self:center">Apply the discount, then <strong>Hold</strong> — a counter will close the bill.</span>') + '</div>');
            const btn = $('rp-complete'); if (btn) btn.addEventListener('click', () => completeSale(grand, cash));
            wireCommercialPanel(reviewAndPay); // Apply & recalculate re-opens the modal on the server's new totals
        }
        async function completeSale(grand, cash) {
            const btn = $('rp-complete'); btn.disabled = true;
            const tendered = Number($('rp-tendered').value || 0);
            if (tendered < grand) { showRpErr('Cash tendered is less than the total.'); btn.disabled = false; return; }
            const payments = [{ payment_method_id: cash.id, amount: grand, tendered_amount: tendered }];
            try {
                let sale;
                if (state.held) {
                    sale = await api('POST', '/held-sales/' + state.held.id + '/settle', { client_uuid: state.pendingClientUuid, payments });
                } else {
                    const payload = Object.assign({ order_type: state.orderType, client_uuid: state.pendingClientUuid, lines: cartLines(), payments }, commercial());
                    if (state.orderType === 'quick_sale') { payload.vehicle_number = ($('rp-vehicle').value || '').trim(); payload.restaurant_waiter_id = Number($('rp-waiter').value || 0) || null; }
                    sale = await api('POST', '/sales', payload);
                }
                closeModal();
                const syncNote = sale.edge_sync_state && sale.edge_sync_state !== 'acknowledged' ? ' · Pending sync' : '';
                state.held = null; state.session = null; state.cart = []; state.dirty = false; state.customer = null; state.pendingClientUuid = null;
                state.commercial = Object.assign({}, state.commercial, { discount_type: 'none', discount_value: 0, promo_code: '', manager_approval_id: null, delivery_address: '', delivery_rider_id: null, delivery_channel_id: null });
                $('customer-name').value = ''; unlockOrderType(); renderCart();
                toast('Sale ' + (sale.sale_no || '#' + sale.sale_id) + ' completed · change ' + money(sale.change_amount || 0) + syncNote);
                autoReceipt(sale.sale_id);
                refreshSync();
            } catch (e) {
                // DISCOUNT parity: the server demands a manager for this discount → the manager approves with their own credential.
                if (/manager approval/i.test(e.message) && !state.commercial.manager_approval_id) {
                    const t = state.lastTotals || {};
                    const approvalId = await askManagerApproval({ sales_order_id: state.held ? state.held.id : 0, branch_id: DATA.branchId, client_uuid: state.held ? '' : state.pendingClientUuid,
                        discount_type: state.commercial.discount_type, discount_value: Number(state.commercial.discount_value || 0), discount_amount: Number(t.manual_discount_amount ?? t.discount_amount ?? 0) });
                    if (approvalId) { state.commercial.manager_approval_id = approvalId; if (state.held) state.dirty = true; reviewAndPay(); return; }
                    reviewAndPay(); return;
                }
                showRpErr(e.message); btn.disabled = false;
            }
        }
        function showRpErr(m) { const e = $('rp-err'); if (e) e.innerHTML = '<div class="err">' + esc(m) + '</div>'; }
