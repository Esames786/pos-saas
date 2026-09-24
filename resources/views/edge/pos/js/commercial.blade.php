{{-- W2 js/commercial (Team 2): the Online payment-modal commercial controls — promo row with Apply / ✕ / feedback (A16, O:887-895 +
     O:3800-3850), the manual-discount panel with manager approval AT APPLY, Remove, feedback and the short-tender "Discount balance"
     shortcut (A15 / R2.2, O:896-929 + O:3883-3982), tip buttons (A17, O:930-939 + O:3854-3872), and the customer modal (A12,
     O:1966-2026 + O:6697-7058: one search box, click / Enter attaches, delivery → saved-address chooser → Attach to Order). Adding a
     NEW customer or address at the till stays Cloud-only (accepted, audit §G) and is said so where Online shows its form.
     Kept names: commercialPanelHtml, readCommercialPanel, wireCommercialPanel, askManagerApproval, askManagerApprovalFor.
     Interface: openCustomerModal() (Team 1 wires #pos-customer-btn to it). --}}
        // ---- Commercial panel (Online Review & Pay left column). ----
        function commercialPanelHtml() {
            const c = state.commercial, promo = state.lastPromo, discOn = c.discount_type && c.discount_type !== 'none', percent = (c.discount_type || 'fixed') === 'percent';
            const tip = Number(c.tip_amount || 0), tipMode = c.tip_mode || (tip > 0 ? 'custom' : 'none');
            const tipBtn = (mode, label, pct) => '<button type="button" class="sm tip-btn' + (tipMode === mode ? ' primary' : '') + '" data-tip-type="' + (mode === 'custom' ? 'custom' : 'percent') + '" data-tip-value="' + (pct ?? '') + '" data-tip-mode="' + mode + '">' + label + '</button>';
            return '<div class="w2-form" id="commercial-panel">' +
                // A16 promo
                '<div class="field"><label for="promo-code-input">Promo code</label><div id="promo-row" style="display:flex;gap:.4rem">' +
                '<input type="text" id="promo-code-input" value="' + esc(c.promo_code || '') + '" placeholder="Promo code" style="flex:1;text-transform:uppercase"' + (c.promo_code ? ' disabled' : '') + '>' +
                '<button type="button" class="sm" id="apply-promo-btn"' + (c.promo_code ? ' hidden' : '') + '>Apply</button>' +
                '<button type="button" class="sm ghost" id="remove-promo-btn"' + (c.promo_code ? '' : ' hidden') + ' title="Remove promo">✕</button></div>' +
                '<div id="promo-feedback" class="muted" style="font-size:.8rem">' + (c.promo_code && promo ? (promo.valid ? '✓ ' + esc(promo.promotion_name || 'Promo') + ' applied' : '<span style="color:#fca5a5">' + esc(promo.message || 'Invalid promo code') + '</span>') : '') + '</div></div>' +
                // A15 manual discount
                '<div class="grp" id="manual-discount-panel"><div class="grp-head"><strong>Manual Discount' + (DATA.manualDiscountNeedsManager ? ' <span class="muted">(manager approval)</span>' : '') + '</strong>' +
                '<button type="button" class="sm danger" id="remove-discount-btn"' + (discOn ? '' : ' hidden') + '>Remove</button></div>' +
                '<div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">' +
                '<select id="manual-discount-type" aria-label="Discount type"><option value="fixed"' + (!percent ? ' selected' : '') + '>Fixed amount</option><option value="percent"' + (percent ? ' selected' : '') + '>Percentage</option></select>' +
                '<span id="manual-discount-prefix"' + (percent ? ' hidden' : '') + '>Rs</span><input type="number" id="manual-discount-value" min="0" step="0.01" placeholder="0.00" value="' + (discOn ? esc(c.discount_value) : '') + '" style="width:110px"' + (percent ? ' max="100"' : '') + '><span id="manual-discount-suffix"' + (percent ? '' : ' hidden') + '>%</span>' +
                '<button type="button" class="sm" id="apply-discount-btn">Apply Discount</button></div>' +
                '<div id="short-tender-row" style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-top:.4rem" hidden><span id="short-tender-message" style="color:#fca5a5;font-size:.8rem"></span>' +
                '<button type="button" class="sm warn" id="discount-shortfall-btn">Discount balance</button></div>' +
                '<div id="manual-discount-feedback" style="font-size:.8rem;margin-top:.3rem">' + (discOn ? '✓ Discount applied: ' + (percent ? esc(c.discount_value) + '%' : money(c.discount_value)) : '') + '</div></div>' +
                // A17 tips
                '<div class="field"><label>Tip</label><div id="tip-buttons" style="display:flex;gap:.3rem;flex-wrap:wrap">' + tipBtn('none', 'No Tip', 0) + tipBtn('p5', '5%', 5) + tipBtn('p10', '10%', 10) + tipBtn('custom', 'Custom') + '</div>' +
                '<div id="tip-hint" class="muted" style="font-size:.75rem"' + (tip > 0 && !DATA.tipsSyncable ? '' : ' hidden') + '>A tip cannot be recorded on a Branch Server sale until the Cloud sync contract carries tips — choose No Tip to complete this sale.</div></div>' +
                // A14 delivery summary (the fields live on the main pane, Online #delivery-panel)
                (effectiveOrderType() === 'delivery' && !state.session ? '<div class="muted" style="font-size:.8rem">Delivery: ' + esc(deliverySummary()) + '</div>' : '') +
                // the Apply button kept for the recalculation path (proof tools + keyboard); Online recalculates live
                '<div class="btn-row left" style="margin-top:.3rem"><button type="button" class="sm ghost" id="cm-apply">Recalculate</button></div></div>';
        }
        function deliverySummary() {
            const c = state.commercial, ch = DATA.deliveryChannels.find(x => x.id === Number(c.delivery_channel_id)), r = DATA.deliveryRiders.find(x => x.id === Number(c.delivery_rider_id));
            return (ch ? ch.name : 'no channel') + (r ? ' · ' + r.name : '') + (c.delivery_address ? ' · ' + c.delivery_address : '');
        }
        function readCommercialPanel() {
            const c = state.commercial;
            if ($('promo-code-input') && !$('promo-code-input').disabled) c.promo_code = ($('promo-code-input').value || '').trim().toUpperCase();
        }
        // Proposed manual discount on the latest server subtotal (Online proposedManualDiscount) — the amount the approval binds.
        function proposedManualDiscount(type, value) {
            const q = state.lastQuote || localTotals(), sub = Number(q.subtotal ?? q.sub_total ?? 0);
            const amount = type === 'percent' ? sub * Math.min(Math.max(value, 0), 100) / 100 : Math.max(value, 0);
            return Math.round(Math.min(amount, sub) * 100) / 100;
        }
        // Online applyManualDiscount: validate → manager approval FIRST (unless the branch auto-approves) → commit + feedback.
        async function applyManualDiscount(type, value, onChange) {
            value = Math.round(Number(value || 0) * 100) / 100;
            const fb = $('manual-discount-feedback');
            if (!(value > 0) || (type === 'percent' && value > 100)) { fb.innerHTML = '<span style="color:#fca5a5">Enter a valid discount' + (type === 'percent' ? ' from 0.01% to 100%' : ' amount') + '.</span>'; return; }
            const amount = proposedManualDiscount(type, value);
            if (!(amount > 0)) { fb.innerHTML = '<span style="color:#fca5a5">This order has no remaining amount available to discount.</span>'; return; }
            let approvalId = null;
            if (DATA.manualDiscountNeedsManager) {
                const payload = { sales_order_id: state.held ? state.held.id : 0, branch_id: DATA.branchId, client_uuid: state.held ? '' : ensureClientUuid(), discount_type: type, discount_value: value, discount_amount: amount };
                approvalId = await askManagerApproval(payload);
                if (!approvalId) { onChange(); return; } // cancelled — the discount is NOT applied (Online: nothing changes)
            }
            Object.assign(state.commercial, { discount_type: type, discount_value: value, manager_approval_id: approvalId });
            state.dirty = true; onChange();
            const f = $('manual-discount-feedback'); if (f) f.innerHTML = '✓ Discount applied: ' + money(amount);
        }
        function wireCommercialPanel(onChange) {
            const c = state.commercial;
            $('cm-apply')?.addEventListener('click', () => { readCommercialPanel(); state.dirty = true; onChange(); });
            $('apply-promo-btn')?.addEventListener('click', async () => {
                const code = ($('promo-code-input').value || '').trim().toUpperCase(); if (!code) return;
                try {
                    const r = await api('POST', '/preview-bill', Object.assign(quotePayload(), { promo_code: code }));
                    if (r.promo && r.promo.valid) { c.promo_code = r.promo.promo_code; state.lastPromo = r.promo; state.dirty = true; onChange(); }
                    else { $('promo-feedback').innerHTML = '<span style="color:#fca5a5">' + esc((r.promo && r.promo.message) || 'Invalid promo code') + '</span>'; }
                } catch (e) { $('promo-feedback').innerHTML = '<span style="color:#fca5a5">' + esc(e.message) + '</span>'; }
            });
            $('remove-promo-btn')?.addEventListener('click', () => { c.promo_code = ''; state.lastPromo = null; state.dirty = true; onChange(); });
            $('manual-discount-type')?.addEventListener('change', e => { const pc = e.target.value === 'percent'; $('manual-discount-prefix').hidden = pc; $('manual-discount-suffix').hidden = !pc; $('manual-discount-value').max = pc ? '100' : ''; });
            $('apply-discount-btn')?.addEventListener('click', () => applyManualDiscount($('manual-discount-type').value, $('manual-discount-value').value, onChange));
            $('remove-discount-btn')?.addEventListener('click', () => { Object.assign(c, { discount_type: 'none', discount_value: 0, manager_approval_id: null }); state.dirty = true; onChange(); });
            $('discount-shortfall-btn')?.addEventListener('click', () => {
                const t = state.lastTotals || {}, grand = Number(t.grand_total || 0), shortfall = Math.max(grand - Number(($('tendered_amount') || {}).value || 0), 0);
                if (!(shortfall > 0.009)) return;
                const current = Number(t.manual_discount_amount ?? (c.discount_type !== 'none' ? t.discount_amount : 0) ?? 0);
                const fixed = Math.round((current + shortfall) * 100) / 100;
                $('manual-discount-type').value = 'fixed'; $('manual-discount-value').value = fixed.toFixed(2);
                applyManualDiscount('fixed', fixed, onChange);
            });
            document.querySelectorAll('#tip-buttons .tip-btn').forEach(b => b.addEventListener('click', () => {
                const t = state.lastTotals || state.lastQuote || localTotals();
                if (b.dataset.tipType === 'custom') {
                    const v = parseFloat(window.prompt('Enter tip amount:', String(c.tip_amount || '')) || '0');
                    c.tip_amount = isNaN(v) ? 0 : Math.max(v, 0);
                } else {
                    const pct = parseFloat(b.dataset.tipValue || '0');
                    c.tip_amount = pct > 0 ? Math.round(Number(t.subtotal || 0) * pct) / 100 : 0;
                }
                c.tip_mode = c.tip_amount > 0 ? b.dataset.tipMode : 'none';
                onChange();
            }));
        }

        // ---- A12 customer modal (Online #customerModal) ----
        let _custResults = [], _custPicked = null, _custTimer = null;
        function openCustomerModal() {
            const isDel = effectiveOrderType() === 'delivery' && !state.session;
            openModal('<div id="customerModal" class="w2-form"><h2 id="customerModalLabel">Customer</h2>' +
                '<input type="search" id="cust-search-input" autocomplete="off" placeholder="Phone number or name…" aria-label="Search customer by phone or name" style="width:100%;font-size:1.05rem">' +
                '<div id="cust-search-results" style="max-height:230px;overflow-y:auto;margin:.4rem 0"><div class="muted" style="font-size:.8rem">Start typing a phone number or name…</div></div>' +
                '<div id="cust-new-hint" class="hint" hidden>No exact match in the synced customer book. Adding a new customer needs the Online POS.</div>' +
                '<div id="cust-selected-panel" class="grp" hidden><div class="grp-head"><div><strong id="sel-cust-name"></strong> <span id="sel-cust-phone" class="muted"></span></div>' +
                '<button type="button" class="primary sm" id="cust-attach-btn">Attach to Order</button></div>' +
                '<label class="muted" style="font-size:.78rem">Delivery address</label><div id="cust-address-list"></div>' +
                '<div class="muted" style="font-size:.75rem;margin-top:.3rem">Saving another address for this customer needs the Online POS — the order can still go to a saved address.</div></div>' +
                '<div class="btn-row">' + (state.customer && !state.held ? '<button type="button" class="ghost" id="cust-walkin-btn">Walk-in (remove customer)</button>' : '') + '<button type="button" class="ghost" id="cust-close-btn">Close</button></div></div>');
            _custResults = []; _custPicked = null;
            const box = $('cust-search-input');
            box.addEventListener('input', () => { clearTimeout(_custTimer); const q = box.value.trim(); _custTimer = setTimeout(() => runCustomerSearch(q, isDel), 250); });
            box.addEventListener('keydown', e => {
                if (e.key !== 'Enter') return; e.preventDefault();
                if (_custResults.length === 1) { chooseCustomer(_custResults[0], isDel); return; }
                if (box.value.trim()) $('cust-new-hint').hidden = false;
            });
            $('cust-close-btn').onclick = closeModal;
            if ($('cust-walkin-btn')) $('cust-walkin-btn').onclick = () => { attachCustomer(null, ''); };
            $('cust-attach-btn').onclick = () => { if (!_custPicked) return; const r = document.querySelector('input[name="cust-addr-pick"]:checked'); attachCustomer(_custPicked, r ? r.value : ''); };
            setTimeout(() => box.focus(), 50);
        }
        function addressesOf(cu) { const list = (cu.addresses || []).slice(); if (!list.length && cu.address) list.push({ id: 'legacy', label: null, address: cu.address, is_default: true }); return list; }
        async function runCustomerSearch(q, isDel) {
            const box = $('cust-search-results'); if (!box) return;
            if (q.length < 2) { _custResults = []; box.innerHTML = '<div class="muted" style="font-size:.8rem">Start typing a phone number or name…</div>'; $('cust-new-hint').hidden = true; return; }
            try {
                const r = await api('GET', '/customers?q=' + encodeURIComponent(q), undefined, { quiet: true });
                _custResults = r.customers || [];
                box.innerHTML = _custResults.map(cu => { const a = addressesOf(cu); return '<div class="list-row" data-cid="' + cu.id + '"><div><strong>' + esc(cu.name || '(no name)') + '</strong>' + (cu.phone ? ' <span class="muted">· ' + esc(cu.phone) + '</span>' : '') +
                    (a.length ? '<div class="muted" style="font-size:.75rem">' + esc(a[0].address) + (a.length > 1 ? ' (+' + (a.length - 1) + ' more)' : '') + '</div>' : '') + '</div></div>'; }).join('');
                const exact = _custResults.some(cu => (cu.phone || '').replace(/\D/g, '') === q.replace(/\D/g, '') || (cu.name || '').toLowerCase() === q.toLowerCase());
                $('cust-new-hint').hidden = exact;
                box.querySelectorAll('[data-cid]').forEach(row => row.addEventListener('click', () => chooseCustomer(_custResults.find(cu => cu.id === Number(row.dataset.cid)), isDel)));
            } catch (e) { box.innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
        }
        // Online chooseCustomer: non-delivery → one click attaches; delivery → the address chooser (default preselected).
        function chooseCustomer(cu, isDel) {
            if (!cu) return;
            const a = addressesOf(cu);
            if (!isDel) { attachCustomer(cu, a.length ? a[0].address : ''); return; }
            _custPicked = cu;
            $('sel-cust-name').textContent = cu.name || ''; $('sel-cust-phone').textContent = cu.phone || '';
            $('cust-selected-panel').hidden = false; $('cust-new-hint').hidden = true;
            $('cust-address-list').innerHTML = a.length ? a.map((x, i) => '<label class="opt"><input type="radio" name="cust-addr-pick" value="' + esc(x.address) + '"' + (x.is_default || i === 0 ? ' checked' : '') + '> ' +
                (x.label ? '<strong>' + esc(x.label) + ':</strong> ' : '') + esc(x.address) + (x.is_default ? ' <span class="muted">(default)</span>' : '') + '</label>').join('')
                : '<div class="muted" style="font-size:.8rem">No saved address — the order carries no address.</div>';
        }
        function attachCustomer(cu, address) {
            state.customer = cu ? { id: cu.id, name: cu.name, phone: cu.phone || null, addresses: cu.addresses || [] } : null;
            if (effectiveOrderType() === 'delivery' && !state.session) state.commercial.delivery_address = cu ? (address || '') : '';
            const cn = $('customer-name'); if (cn && !cu) cn.value = '';
            state.dirty = state.dirty || !!state.held;
            closeModal(); renderChips(); if (typeof renderCustomerChip === 'function') renderCustomerChip();
            onOrderTypeChanged(); scheduleQuote();
            toast(cu ? 'Customer attached: ' + (cu.name || cu.phone || '') : 'Walk-in — customer removed.', 'success');
        }
        (function wireCartChip() {
            const chip = $('customer-chip'); if (!chip) return;
            chip.addEventListener('click', () => { if (!state.held) openCustomerModal(); });
            chip.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && !state.held) { e.preventDefault(); openCustomerModal(); } });
        })();

        // Manager approval for a manual discount — the manager authenticates with THEIR OWN Edge credential.
        function askManagerApproval(payload) {
            return new Promise(resolve => {
                openModal('<h2>Manager approval — manual discount</h2><p class="muted">Discount ' + esc(payload.discount_type) + ' ' + esc(payload.discount_value) + ' (' + money(payload.discount_amount) + ') needs a manager.</p>' +
                    '<div class="field"><label>Manager employee code</label><input type="text" id="ma-code" autocomplete="off"></div><div class="field"><label>Manager Edge credential</label><input type="password" id="ma-cred" autocomplete="off"></div><div id="ma-err"></div>' +
                    '<div class="btn-row"><button class="ghost" id="ma-cancel">Cancel</button><button class="ok" id="ma-ok">Approve</button></div>');
                $('ma-cancel').onclick = () => { closeModal(); resolve(null); };
                $('ma-ok').onclick = async () => {
                    try {
                        const r = await api('POST', '/manager-approvals/verify', { manager_employee_code: $('ma-code').value.trim(), manager_credential: $('ma-cred').value, action_type: 'manual_discount', payload });
                        closeModal(); resolve(r.approval_id);
                    } catch (e) { $('ma-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
                };
                setTimeout(() => $('ma-code') && $('ma-code').focus(), 50);
            });
        }
        // Generic manager approval prompt (returns, cancellations, voids) — same credential rule as above.
        function askManagerApprovalFor(actionType, title, note, payload) {
            return new Promise(resolve => {
                openModal('<h2>' + esc(title) + '</h2><p class="muted">' + esc(note) + '</p>' +
                    '<div class="field"><label>Manager employee code</label><input type="text" id="ma-code" autocomplete="off"></div><div class="field"><label>Manager Edge credential</label><input type="password" id="ma-cred" autocomplete="off"></div><div id="ma-err"></div>' +
                    '<div class="btn-row"><button class="ghost" id="ma-cancel">Cancel</button><button class="ok" id="ma-ok">Approve</button></div>');
                $('ma-cancel').onclick = () => { closeModal(); resolve(null); };
                $('ma-ok').onclick = async () => {
                    try {
                        const r = await api('POST', '/manager-approvals/verify', { manager_employee_code: $('ma-code').value.trim(), manager_credential: $('ma-cred').value, action_type: actionType, payload });
                        closeModal(); resolve(r.approval_id);
                    } catch (e) { $('ma-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
                };
            });
        }
