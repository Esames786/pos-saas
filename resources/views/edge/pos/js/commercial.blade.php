{{-- W0 js/commercial (Team 2 owns from W2): the Review & Pay commercial panel (promo, manual discount, customer, delivery) + manager approval prompts. --}}
        // ---- Commercial panel (Online Review & Pay parity): promo code, manual discount, delivery details, customer. ----
        function commercialPanelHtml() {
            const c = state.commercial, isDelivery = state.orderType === 'delivery' && !state.session && !state.held;
            let html = '<details ' + ((c.discount_type !== 'none' || c.promo_code || isDelivery) ? 'open' : '') + '><summary class="muted">Discount · Promo · Customer' + (isDelivery ? ' · Delivery' : '') + '</summary>' +
                '<div class="field"><label>Promo code</label><div style="display:flex;gap:.4rem"><input type="text" id="cm-promo" value="' + esc(c.promo_code || '') + '" placeholder="Promo code" style="flex:1;text-transform:uppercase"></div></div>' +
                '<div class="field"><label>Manual discount' + (DATA.manualDiscountNeedsManager ? ' (manager approval required)' : '') + '</label><div style="display:flex;gap:.4rem">' +
                '<select id="cm-disc-type"><option value="none"' + (c.discount_type === 'none' ? ' selected' : '') + '>None</option><option value="fixed"' + (c.discount_type === 'fixed' ? ' selected' : '') + '>Rs</option><option value="percent"' + (c.discount_type === 'percent' ? ' selected' : '') + '>%</option></select>' +
                '<input type="number" id="cm-disc-value" min="0" step="0.01" value="' + esc(c.discount_value || 0) + '" style="flex:1"></div></div>' +
                '<div class="field"><label>Customer</label><div style="display:flex;gap:.4rem"><input type="search" id="cm-cust-q" placeholder="Search name / phone…" style="flex:1"><button type="button" class="sm ghost" id="cm-cust-clear">Walk-in</button></div>' +
                '<div id="cm-cust-results"></div><div class="muted" id="cm-cust-picked">' + (state.customer ? esc(state.customer.name) + (state.customer.phone ? ' · ' + esc(state.customer.phone) : '') : 'Walk-in') + '</div></div>';
            if (isDelivery) {
                html += '<div class="field"><label>Delivery channel</label><select id="cm-channel"><option value="">—</option>' + DATA.deliveryChannels.map(ch => '<option value="' + ch.id + '"' + (Number(c.delivery_channel_id) === ch.id ? ' selected' : '') + '>' + esc(ch.name) + (ch.type === 'aggregator' ? ' (aggregator)' : '') + '</option>').join('') + '</select></div>' +
                    '<div class="field"><label>Rider</label><select id="cm-rider"><option value="">—</option>' + DATA.deliveryRiders.map(r => '<option value="' + r.id + '"' + (Number(c.delivery_rider_id) === r.id ? ' selected' : '') + '>' + esc(r.name) + '</option>').join('') + '</select></div>' +
                    '<div class="field"><label>Address</label>' + (state.customer && state.customer.addresses && state.customer.addresses.length ? '<select id="cm-addr-pick"><option value="">Saved addresses…</option>' + state.customer.addresses.map(a => '<option value="' + esc(a.address) + '">' + esc((a.label ? a.label + ': ' : '') + a.address) + '</option>').join('') + '</select>' : '') +
                    '<input type="text" id="cm-address" value="' + esc(c.delivery_address || '') + '" placeholder="Delivery address"></div>' +
                    '<div class="field"><label>Delivery charge' + (DATA.deliveryChargeLocked ? ' (fixed by the branch)' : '') + '</label><input type="number" id="cm-charge" min="0" step="1" value="' + esc(DATA.deliveryChargeLocked ? DATA.defaultDeliveryCharge : (c.delivery_charge_amount ?? 0)) + '"' + (DATA.deliveryChargeLocked ? ' disabled' : '') + '></div>';
            }
            html += '<div class="btn-row left"><button type="button" class="sm" id="cm-apply">Apply &amp; recalculate</button></div></details>';
            return html;
        }
        function readCommercialPanel() {
            const c = state.commercial;
            c.promo_code = ($('cm-promo')?.value || '').trim().toUpperCase();
            c.discount_type = $('cm-disc-type')?.value || 'none';
            c.discount_value = Number($('cm-disc-value')?.value || 0);
            if ($('cm-channel')) c.delivery_channel_id = $('cm-channel').value || null;
            if ($('cm-rider')) c.delivery_rider_id = $('cm-rider').value || null;
            if ($('cm-address')) c.delivery_address = $('cm-address').value.trim();
            if ($('cm-charge') && !DATA.deliveryChargeLocked) c.delivery_charge_amount = Number($('cm-charge').value || 0);
            c.manager_approval_id = null; // a changed discount needs a fresh approval
        }
        function wireCommercialPanel(onChange) {
            $('cm-apply')?.addEventListener('click', () => { readCommercialPanel(); state.dirty = true; onChange(); });
            $('cm-addr-pick')?.addEventListener('change', e => { if (e.target.value) $('cm-address').value = e.target.value; });
            $('cm-cust-clear')?.addEventListener('click', () => { state.customer = null; readCommercialPanel(); state.dirty = true; onChange(); });
            let timer = null;
            $('cm-cust-q')?.addEventListener('input', e => {
                clearTimeout(timer); const q = e.target.value.trim(); if (q.length < 2) { $('cm-cust-results').innerHTML = ''; return; }
                timer = setTimeout(async () => {
                    try {
                        const r = await api('GET', '/customers?q=' + encodeURIComponent(q));
                        $('cm-cust-results').innerHTML = r.customers.map(cu => '<div class="list-row" data-cid="' + cu.id + '"><span>' + esc(cu.name) + '</span><span class="muted">' + esc(cu.phone || '') + '</span></div>').join('') || '<div class="muted">No customer found in the book. Adding a new customer needs the Online POS.</div>';
                        document.querySelectorAll('#cm-cust-results [data-cid]').forEach(row => row.addEventListener('click', () => {
                            state.customer = r.customers.find(cu => cu.id === Number(row.dataset.cid)); readCommercialPanel(); state.dirty = true; renderChips(); onChange();
                        }));
                    } catch (err) { toast(err.message); }
                }, 250);
            });
        }
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
