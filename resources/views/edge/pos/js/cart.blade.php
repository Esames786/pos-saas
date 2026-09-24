{{-- W2 js/cart (Team 2): Online addToCart (O:3090-3160 — stock block / backorder toast, measurable → qtyEntryModal, options →
     modifierEntryModal, variants → picker), the qty / modifier / variant dialogs (A6/A7/A8, O:2028-2088 + O:2836-2981), cart rows
     with direct qty input, − / +, remove or "Cancel kitchen item", edit options (A10, renderCart O:3299-3457),
     the live server-quoted totals on the main pane (A18, refreshServerTotals O:3609-3656 → Edge POST /preview-bill, zero mutation),
     the commercial payload, and Preview Bill (A30) with Print here / Send to network entry points (Team 5 printBillPreview).
     Kept names (other fragments call them): addToCart, changeQty, renderCart, renderChips, cartLines, commercial, requireCart,
     previewBill, rowsHtml. Interface: openQtyEntry(item), openModifierEntry(item), openVariantPicker(item). --}}
        // ---- Cart. A carried line (from an open check) keeps its line id + captured price. ----
        function modifierSignature(mods) { return (mods || []).map(m => Number(m.modifier_group_id || 0) + ':' + Number(m.modifier_id || 0)).sort().join('|'); }
        function modifierDelta(mods) { return (mods || []).reduce((s, m) => s + Number(m.price_delta || 0), 0); }
        function lineKey(p, v, mods) { return 'p' + p.id + ':' + (v ? v.id : 0) + ':' + modifierSignature(mods); }

        // addToCart(item[, variant, qty, modifiers]) — `item` is a grid item (product or {deal:true,…}); later arguments are filled by
        // the dialogs the Online flow opens in turn (variant picker → qty entry → options), each re-entering here.
        function addToCart(item, variant, forceQty, modifiers) {
            if (item.deal) { addDealToCart(item); return; }
            const p = productById(item.id) || item;
            if (variant === undefined) {
                const vs = productVariants(p);
                if (vs.length > 1) { openVariantPicker(p); return; }
                variant = vs.length === 1 ? vs[0] : null;
            }
            const measurable = isMeasurableProduct(p);
            const avail = availableQty(p, variant);
            if (avail !== null && avail <= 0) {
                if (!DATA.allowNegativeStock) { toast(p.name + ' is out of stock.', 'warning'); return; }
                toast('Backorder — ' + p.name + ' stock will go negative', 'warning');
            }
            if (forceQty === undefined && measurable) { openQtyEntry(p, variant, modifiers); return; }
            let qty = forceQty !== undefined ? parseFloat(forceQty) : 1;
            if (!measurable) qty = Math.max(Math.round(qty || 1), 1);
            if (!qty || qty <= 0) return;
            if (modifiers === undefined && hasModifierGroups(p)) { openModifierEntry(p, variant, qty); return; }
            const mods = modifiers || [];
            if (avail !== null && qty > avail + 0.0001) {
                if (!DATA.allowNegativeStock) { toast('Insufficient stock for ' + p.name + '. Available: ' + formatQty(Math.max(avail, 0), p), 'warning'); return; }
                toast('Backorder — ' + p.name + ' stock will go negative', 'warning');
            }
            ensureClientUuid();
            const key = lineKey(p, variant, mods);
            const ex = state.cart.find(l => l.key === key && !l.line_id);
            if (ex) { ex.quantity = measurable ? +(Number(ex.quantity) + qty).toFixed(3) : Number(ex.quantity) + qty; }
            else {
                state.cart.push({ key, product_id: p.id, product_variant_id: variant ? variant.id : null, variant_name: variant ? variant.name : null,
                    name: p.name, unit_code: p.unit_code || '', base_price: productPrice(p, variant), price: +(productPrice(p, variant) + modifierDelta(mods)).toFixed(2),
                    quantity: measurable ? +qty.toFixed(3) : qty, modifiers: mods, kitchen_note: '', line_id: null, kot_sent_quantity: 0 });
            }
            state.dirty = true; renderCart();
        }
        function addDealToCart(item) {
            const combo = DATA.combos.find(c => c.id === Number(item.id)) || item;
            const a = comboAvailability(combo);
            if (a.makeable !== null && a.makeable <= 0) {
                if (!DATA.allowNegativeStock) { toast(combo.name + ' is unavailable — ' + (a.limiting ? a.limiting + ' is out of stock' : 'a component is out of stock') + '.', 'warning'); return; }
                toast('Backorder — ' + combo.name + ' stock will go negative', 'warning');
            }
            // DEAL parity: the client names the deal + quantity only; the server expands the synced combo book.
            ensureClientUuid();
            const ex = state.cart.find(l => l.combo_id === combo.id && !l.line_id);
            if (ex) ex.quantity += 1; else state.cart.push({ key: 'd' + combo.id, combo_id: combo.id, product_id: null, name: combo.name, price: combo.price, quantity: 1, line_id: null, kot_sent_quantity: 0, deal: true,
                components: (combo.components || []).map(c => c.quantity + ' × ' + (c.product_name || '')), component_lines: [] });
            state.dirty = true; renderCart();
        }
        function ensureClientUuid() { if (!state.pendingClientUuid) state.pendingClientUuid = uuid(); return state.pendingClientUuid; } // Online ensureSaleUuid

        // ---- A6 qtyEntryModal (measurable items: weight / volume / length — qty, or amount ÷ price) ----
        function openQtyEntry(item, variant, modifiers) {
            const p = productById(item.id) || item, v = variant === undefined ? defaultVariant(p) : variant, price = productPrice(p, v) + modifierDelta(modifiers), unit = p.unit_code || 'unit';
            openModal('<div id="qtyEntryModal" class="w2-form"><h2 id="qtyEntryModalLabel">Enter Quantity</h2>' +
                '<div class="hint"><strong id="qty-modal-product-name">' + esc(p.name + (v ? ' — ' + v.name : '')) + '</strong><div class="muted" id="qty-modal-price-hint">' + money(price) + ' per ' + esc(unit) + '</div></div>' +
                '<div class="field"><label for="qty-modal-input">Quantity <span id="qty-modal-unit" class="muted">(' + esc(unit) + ')</span></label><input type="number" id="qty-modal-input" step="' + (p.quantity_step || 0.001) + '" min="' + (p.quantity_step || 0.001) + '" placeholder="0.000" style="text-align:right;font-size:1.2rem"></div>' +
                '<div class="field"><label for="qty-modal-amount-input">Or enter amount</label><input type="number" id="qty-modal-amount-input" step="0.01" min="0" placeholder="Amount (Rs)" style="text-align:right"><span class="muted" style="font-size:.72rem">Amount ÷ price/unit = quantity</span></div>' +
                '<div id="qty-modal-err"></div><div class="btn-row"><button type="button" class="ghost" id="qty-modal-cancel">Cancel</button><button type="button" class="primary" id="qty-modal-confirm">Add to Cart</button></div></div>');
            const qi = $('qty-modal-input'), ai = $('qty-modal-amount-input');
            ai.addEventListener('input', () => { const amt = parseFloat(ai.value) || 0; if (price > 0 && amt > 0) qi.value = (amt / price).toFixed(3); });
            const confirm = () => {
                const q = +(parseFloat(qi.value) || 0).toFixed(3);
                if (q <= 0) { $('qty-modal-err').innerHTML = '<div class="err">Enter a quantity greater than zero.</div>'; qi.focus(); return; }
                closeModal(); addToCart(Object.assign({ deal: false }, p), v, q, modifiers);
            };
            $('qty-modal-confirm').onclick = confirm; $('qty-modal-cancel').onclick = closeModal;
            [qi, ai].forEach(el => el.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); confirm(); } }));
            setTimeout(() => qi.focus(), 50);
        }

        // ---- A7 modifierEntryModal (groups, radio when max = 1, defaults preselected, min/max per group, price deltas) ----
        function openModifierEntry(item, variant, qty, preselectedIds, editKey) {
            const p = productById(item.id) || item, v = variant === undefined ? defaultVariant(p) : variant, groups = activeModifierGroups(p);
            const pre = Array.isArray(preselectedIds) ? preselectedIds.map(Number) : null;
            const body = groups.map(g => {
                const type = Number(g.max_select || 0) === 1 ? 'radio' : 'checkbox';
                const rules = (g.is_required ? 'Required' : 'Optional') + ' · ' + Number(g.min_select || 0) + ' min / ' + (g.max_select ? g.max_select : 'Any') + ' max';
                return '<div class="grp" data-modifier-group="' + g.id + '" data-min="' + Number(g.min_select || 0) + '" data-max="' + (g.max_select || '') + '">' +
                    '<div class="grp-head"><strong>' + esc(g.name) + '</strong><span class="muted">' + rules + '</span></div>' +
                    (g.modifiers || []).map(m => {
                        const d = Number(m.price_delta || 0), on = pre ? pre.indexOf(Number(m.id)) !== -1 : !!m.is_default;
                        return '<label class="opt"><input type="' + type + '" name="mg_' + g.id + '" value="' + m.id + '" data-modifier-input data-group-id="' + g.id + '" data-group-name="' + esc(g.name) + '" data-modifier-name="' + esc(m.name) + '" data-price-delta="' + d + '"' + (on ? ' checked' : '') + '> ' +
                            esc(m.name) + (d ? ' <span class="muted">(' + (d > 0 ? '+' : '') + money(d) + ')</span>' : '') + '</label>';
                    }).join('') + '<div class="grp-err" data-modifier-error hidden></div></div>';
            }).join('');
            openModal('<div id="modifierEntryModal" class="w2-form"><h2 id="modifierEntryModalLabel">Choose Modifiers</h2>' +
                '<div class="hint"><strong id="modifier-modal-product-name">' + esc(p.name + (v ? ' — ' + v.name : '')) + '</strong><div class="muted" id="modifier-modal-price-hint">' + money(productPrice(p, v)) + ' base price</div></div>' +
                '<div id="modifier-modal-groups">' + body + '</div>' +
                '<div class="btn-row"><button type="button" class="ghost" id="modifier-modal-cancel">Cancel</button><button type="button" class="primary" id="modifier-modal-confirm">' + (editKey ? 'Update item' : 'Add to Cart') + '</button></div></div>');
            $('modifier-modal-cancel').onclick = closeModal;
            $('modifier-modal-confirm').onclick = () => {
                let ok = true;
                document.querySelectorAll('#modifier-modal-groups [data-modifier-group]').forEach(gEl => {
                    const min = Number(gEl.dataset.min || 0), max = gEl.dataset.max === '' ? null : Number(gEl.dataset.max || 0);
                    const n = gEl.querySelectorAll('[data-modifier-input]:checked').length, err = gEl.querySelector('[data-modifier-error]');
                    err.hidden = true; err.textContent = '';
                    if (n < min) { ok = false; err.textContent = 'Select at least ' + min + ' option' + (min === 1 ? '' : 's') + '.'; err.hidden = false; }
                    else if (max !== null && max > 0 && n > max) { ok = false; err.textContent = 'Select no more than ' + max + ' option' + (max === 1 ? '' : 's') + '.'; err.hidden = false; }
                });
                if (!ok) return;
                const mods = [];
                document.querySelectorAll('#modifier-modal-groups [data-modifier-input]:checked').forEach(inp => mods.push({
                    modifier_group_id: Number(inp.dataset.groupId), modifier_group_name: inp.dataset.groupName || '', modifier_id: Number(inp.value), name: inp.dataset.modifierName || '', price_delta: Number(inp.dataset.priceDelta || 0) }));
                closeModal();
                if (editKey) {
                    // Editing a line: drop it, then re-add with the new options (re-keyed by the option signature, delta re-folded).
                    const old = state.cart.find(l => l.key === editKey);
                    state.cart = state.cart.filter(l => l.key !== editKey);
                    addToCart(Object.assign({ deal: false }, p), v, qty, mods);
                    if (old && old.kitchen_note) { const nl = state.cart.find(l => l.key === lineKey(p, v, mods) && !l.line_id); if (nl) nl.kitchen_note = old.kitchen_note; }
                } else { addToCart(Object.assign({ deal: false }, p), v, qty, mods); }
            };
        }

        // ---- A8 variant picker (the synced variants with their own price + stock; a barcode picks the exact variant instead) ----
        function openVariantPicker(item) {
            const p = productById(item.id) || item, negOk = !!DATA.allowNegativeStock;
            openModal('<div id="variantPickerModal" class="w2-form"><h2>' + esc(p.name) + '</h2><p class="muted">Choose an option.</p><div class="vgrid" id="variant-picker-list">' +
                productVariants(p).map(v => {
                    const q = availableQty(p, v), out = q !== null && q <= 0;
                    return '<button type="button" data-variant-id="' + v.id + '"' + (out && !negOk ? ' class="ghost"' : '') + '><strong>' + esc(v.name) + (v.is_default ? ' <span class="muted">(default)</span>' : '') + '</strong><span>' + money(v.price) + '</span>' +
                        '<span class="stock-badge ' + (q === null ? 'stock-svc' : (out ? (negOk ? 'stock-backorder' : 'stock-out') : (q <= 5 ? 'stock-low' : 'stock-ok'))) + '">' + (q === null ? 'Service' : (out ? (negOk ? 'Backorder' : 'Out') : 'Stock ' + formatQty(q, p))) + '</span></button>';
                }).join('') + '</div><div class="btn-row"><button type="button" class="ghost" id="variant-picker-cancel">Cancel</button></div></div>');
            $('variant-picker-cancel').onclick = closeModal;
            document.querySelectorAll('#variant-picker-list [data-variant-id]').forEach(b => b.onclick = () => {
                const v = productVariants(p).find(x => x.id === Number(b.dataset.variantId)); closeModal(); addToCart(Object.assign({ deal: false }, p), v);
            });
        }

        // ---- quantity changes. Below the kitchen-sent quantity the Online void-with-reason flow runs (Team 3 voidSentLine). ----
        function changeQty(key, d) {
            const l = state.cart.find(x => x.key === key); if (!l) return;
            const p = l.product_id ? productById(l.product_id) : null, step = isMeasurableProduct(p) ? 0.001 : 1;
            setLineQty(key, isMeasurableProduct(p) ? +(Number(l.quantity) + d * step).toFixed(3) : Number(l.quantity) + d);
        }
        function setLineQty(key, nextQty) {
            const l = state.cart.find(x => x.key === key); if (!l) return;
            const p = l.product_id ? productById(l.product_id) : null;
            nextQty = isMeasurableProduct(p) ? +Number(nextQty || 0).toFixed(3) : Math.round(Number(nextQty || 0));
            if (nextQty < Number(l.kot_sent_quantity || 0) - 0.000001) {
                if (typeof voidSentLine === 'function') voidSentLine(l, Math.max(nextQty, 0));
                else toast('Already sent to the kitchen — reducing needs a void with a reason.', 'warning');
                renderCart(); return;
            }
            if (nextQty > Number(l.quantity) && p && !l.line_id) {
                const v = productVariants(p).find(x => x.id === Number(l.product_variant_id)) || null, avail = availableQty(p, v);
                if (avail !== null && nextQty - Number(l.quantity) > avail + 0.0001 && !DATA.allowNegativeStock) { toast('Insufficient stock for ' + p.name + '. Available: ' + formatQty(Math.max(avail, 0) + Number(l.quantity), p), 'warning'); renderCart(); return; }
            }
            l.quantity = nextQty; if (l.quantity <= 0) state.cart = state.cart.filter(x => x.key !== key);
            state.dirty = true; renderCart();
        }
        function removeLine(key) { const l = state.cart.find(x => x.key === key); if (!l) return; setLineQty(key, 0); }
        // A10 kitchen note: no till capture (the Online POS has none — re-checked, Team 6); a recalled line still SHOWS its stored note.
        // A carried line (recalled check) gets its variant / options / note from the held-sale payload when js/held did not copy them.
        function hydrateCarriedLines() {
            if (!state.held || !Array.isArray(state.held.lines)) return;
            state.cart.forEach(l => {
                if (!l.line_id || l._hydrated) return;
                const src = state.held.lines.find(x => Number(x.id) === Number(l.line_id)); l._hydrated = true; if (!src) return;
                if (l.product_variant_id === undefined) l.product_variant_id = src.product_variant_id ?? null;
                if (l.variant_name === undefined && src.variant_name !== undefined) l.variant_name = src.variant_name;
                if (l.modifiers === undefined && Array.isArray(src.modifiers)) l.modifiers = src.modifiers;
                if (l.kitchen_note === undefined && src.kitchen_note !== undefined) l.kitchen_note = src.kitchen_note || '';
                if (l.unit_code === undefined && src.unit_code !== undefined) l.unit_code = src.unit_code;
            });
        }
        function renderCart() {
            hydrateCarriedLines();
            const wrap = $('cart-lines');
            if (!state.cart.length) { wrap.innerHTML = '<p class="muted" style="padding:.6rem">No items yet. Scan a barcode or search for a product.</p>'; }
            else {
                wrap.innerHTML = '';
                state.cart.forEach(l => {
                    const p = l.product_id ? productById(l.product_id) : null, sent = Number(l.kot_sent_quantity || 0);
                    const row = document.createElement('div'); row.className = 'cart-line'; row.dataset.key = l.key;
                    const unit = l.unit_code || (p && p.unit_code) || '';
                    const mods = (l.modifiers || []).map(m => { const d = Number(m.price_delta || 0); return '<div class="ln-mod">+ ' + esc(m.name) + (d ? ' (' + (d > 0 ? '+' : '') + money(d) + ')' : '') + '</div>'; }).join('');
                    const canEditMods = !l.deal && !l.line_id && sent <= 0 && p && hasModifierGroups(p);
                    row.innerHTML = '<div><div class="ln-nm">' + esc(l.name) + (l.deal ? ' <span class="chip hot" style="padding:.05rem .4rem">Deal</span>' : '') + '</div>' +
                        '<div class="ln-sub">' + (l.deal ? 'Deal' : esc(l.variant_name || 'Default')) + ' · ' + money(l.price) + (unit ? ' / ' + esc(unit) : '') + '</div>' + mods +
                        (l.kitchen_note ? '<div class="ln-note">Note: ' + esc(l.kitchen_note) + '</div>' : '') +
                        (sent > 0 ? '<div class="ln-sub">kitchen has ' + formatQty(sent, p) + '</div>' : '') +
                        (l.components && l.components.length ? '<div class="ln-sub">' + esc(l.components.join(' · ')) + '</div>' : '') + '</div>' +
                        '<div class="ln-tools">' + (canEditMods ? '<button type="button" data-edit-mod title="Edit options">Options</button>' : '') +
                        (sent > 0 ? '<button type="button" class="warn" data-remove title="Cancel kitchen item">Cancel</button>' : '<button type="button" class="danger" data-remove title="Remove item">✕</button>') + '</div>' +
                        '<div class="ln-ctl"><div class="qty"><button type="button" data-m="-1">−</button><input type="number" data-qty-input step="' + qtyStep(p) + '" min="' + qtyStep(p) + '" value="' + formatQty(l.quantity, p) + '" aria-label="Quantity">' +
                        (unit && isMeasurableProduct(p) ? '<span class="muted" style="font-size:.72rem">' + esc(unit) + '</span>' : '') + '<button type="button" data-m="1">+</button></div>' +
                        '<span class="ln-amt">' + money(l.price * l.quantity - Number(l.discount_amount || 0)) + '</span></div>';
                    row.querySelector('[data-m="-1"]').addEventListener('click', () => changeQty(l.key, -1));
                    row.querySelector('[data-m="1"]').addEventListener('click', () => changeQty(l.key, 1));
                    row.querySelector('[data-qty-input]').addEventListener('change', e => setLineQty(l.key, parseFloat(e.target.value) || 0));
                    row.querySelector('[data-remove]').addEventListener('click', () => removeLine(l.key));
                    const em = row.querySelector('[data-edit-mod]'); if (em) em.addEventListener('click', () => openModifierEntry(p, productVariants(p).find(x => x.id === Number(l.product_variant_id)) || null, l.quantity, (l.modifiers || []).map(m => Number(m.modifier_id)), l.key));

                    wrap.appendChild(row);
                });
                requestAnimationFrame(() => { wrap.scrollTop = wrap.scrollHeight; });
            }
            $('t-items').textContent = formatQty(state.cart.reduce((s, l) => s + Number(l.quantity || 0), 0), { allow_decimal_qty: state.cart.some(l => isMeasurableProduct(productById(l.product_id))) });
            if (typeof onOrderTypeChanged === 'function') onOrderTypeChanged();
            renderTotals(localTotals()); scheduleQuote();
            renderChips(); renderActions();
            if (typeof renderTiles === 'function' && $('product-grid') && $('product-grid').children.length) renderTiles(); // stock badges follow the cart
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
            const phone = state.customer?.phone || state.held?.customer_phone || '';
            $('customer-chip').textContent = cust ? cust + (phone ? ' · ' + phone : '') : 'Walk-in';
        }
        // The line payload the server resolves (price / tax / options / stock are ALWAYS re-resolved server-side).
        function cartLines() {
            return state.cart.map(l => {
                const out = l.combo_id ? { combo_id: l.combo_id, quantity: l.quantity } : { product_id: l.product_id, quantity: l.quantity };
                if (!l.combo_id) {
                    if (l.product_variant_id !== undefined && l.product_variant_id !== null) out.product_variant_id = l.product_variant_id;
                    if (Array.isArray(l.modifiers) && (!l.line_id || l.modifiers.length)) out.modifiers = l.modifiers.map(m => ({ modifier_group_id: m.modifier_group_id, modifier_id: m.modifier_id }));
                    if (l.kitchen_note !== undefined && (l.kitchen_note || !l.line_id)) out.kitchen_note = l.kitchen_note || null;
                }
                if (l.line_id) out.sales_order_line_id = l.line_id;
                return out;
            });
        }
        // The commercial intent that travels with hold, preview and payment — the same fields as Online's Review & Pay.
        function commercial() {
            const c = state.commercial, out = {};
            if (c.discount_type && c.discount_type !== 'none') { out.discount_type = c.discount_type; out.discount_value = Number(c.discount_value || 0); }
            if (c.promo_code) out.promo_code = c.promo_code;
            if (c.manager_approval_id) out.manager_approval_id = c.manager_approval_id;
            if (Number(c.tip_amount || 0) > 0) out.tip_amount = Number(c.tip_amount);
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

        // (renderActions — the contextual action grid — lives in js/actions, Team 3.)

        // ---- A18 live totals: the client subtotal at once, then the SERVER quote (POST /preview-bill — the same SalesTotalsService
        //      the sale uses, zero mutation) for tax / service charge / discount / promo / tip / delivery, debounced like Online. ----
        let _quoteTimer = null, _quoteSeq = 0;
        state.lastQuote = null;
        function localTotals() {
            const sub = state.cart.reduce((s, l) => s + Number(l.price || 0) * Number(l.quantity || 0), 0);
            return { subtotal: sub, discount_amount: 0, tax_amount: 0, service_charge_amount: 0, tip_amount: Number(state.commercial.tip_amount || 0), delivery_charge_amount: 0, grand_total: sub + Number(state.commercial.tip_amount || 0), _local: true };
        }
        function quotePayload() { return Object.assign({ order_type: effectiveOrderType(), lines: cartLines() }, commercial()); }
        function scheduleQuote() {
            clearTimeout(_quoteTimer);
            if (!state.cart.length) { state.lastQuote = null; renderTotals(localTotals()); return; }
            if (state.held && !state.dirty) { state.lastQuote = state.held; renderTotals(Object.assign({}, state.held, { tip_amount: Number(state.commercial.tip_amount || 0) })); return; }
            if (!state.terminalId) { renderTotals(localTotals(), 'Select a terminal to see tax, service charge and discounts.'); return; }
            _quoteTimer = setTimeout(async () => {
                const seq = ++_quoteSeq;
                try {
                    const r = await api('POST', '/preview-bill', quotePayload(), { quiet: true });
                    if (seq !== _quoteSeq) return;
                    state.lastQuote = r.totals || null; state.lastPromo = r.promo || null;
                    renderTotals(r.totals || localTotals());
                } catch (e) { if (seq === _quoteSeq) renderTotals(localTotals(), 'Estimate only — ' + e.message); }
            }, 300);
        }
        function renderTotals(t, note) {
            const set = (id, v, show) => { $(id).textContent = money(v); if ($(id + '-row')) $(id + '-row').hidden = !show; };
            set('t-subtotal', t.subtotal ?? t.sub_total ?? 0, true);
            const disc = Number(t.discount_amount || 0);
            $('t-discount').textContent = '-' + money(disc); $('t-discount-row').hidden = !(disc > 0.009);
            $('t-discount-label').textContent = 'Discount' + (t.promo_code ? ' (' + t.promo_code + ')' : '');
            set('t-tax', t.tax_amount || 0, Number(t.tax_amount || 0) > 0.009);
            set('t-service', t.service_charge_amount ?? t.service_charge ?? 0, Number(t.service_charge_amount ?? t.service_charge ?? 0) > 0.009);
            set('t-tip', t.tip_amount || 0, Number(t.tip_amount || 0) > 0.009);
            set('t-delivery', t.delivery_charge_amount || 0, Number(t.delivery_charge_amount || 0) > 0.009);
            $('t-grand').textContent = money(t.grand_total ?? 0);
            const n = $('t-quote-note'); n.hidden = !note; n.textContent = note || '';
        }

        // ---- Preview Bill (A30): the running bill with its lines, ZERO mutation; print entry points belong to Team 5. ----
        async function previewBill() {
            if (!requireCart()) return;
            try {
                let t, lines = null, payload = null;
                if (state.held && !state.dirty) { t = state.held; }
                else { payload = quotePayload(); const p = await api('POST', '/preview-bill', payload); t = p.totals || {}; lines = p.lines || null; }
                const lineRows = (lines || []).filter(l => l.line_kind !== 'component').map(l => '<div class="row"><span>' + formatQty(l.quantity, productById(l.product_id)) + ' × ' + esc(l.product_name) + (l.variant_name ? ' (' + esc(l.variant_name) + ')' : '') +
                    (l.modifiers || []).map(m => '<br><small class="muted">+ ' + esc(m.name) + '</small>').join('') + (l.kitchen_note ? '<br><small class="muted">* ' + esc(l.kitchen_note) + '</small>' : '') + '</span><span>' + money(l.line_total) + '</span></div>').join('');
                const canPrint = typeof printBillPreview === 'function';
                openModal('<div id="billPreviewModal"><h2>Preview Bill</h2><p class="muted">Running bill — no payment, stock, KOT, or receipt is created.</p>' +
                    (lineRows ? '<div class="totals" id="bill-preview-lines">' + lineRows + '</div>' : '') + rowsHtml(t) +
                    '<div class="btn-row"><button type="button" class="ghost" onclick="EdgePOS.closeModal()">Close</button>' +
                    (canPrint ? '<button type="button" id="bill-preview-print-here">Print here</button><button type="button" id="bill-preview-network">Send to network</button>' : '') + '</div></div>');
                if (canPrint) {
                    const job = Object.assign({ held_sale_id: state.held ? state.held.id : null, totals: t, lines }, payload || {});
                    $('bill-preview-print-here').onclick = () => printBillPreview(Object.assign({ target: 'here' }, job));
                    $('bill-preview-network').onclick = () => printBillPreview(Object.assign({ target: 'network' }, job));
                }
            } catch (e) { toast(e.message); }
        }
        function rowsHtml(t) {
            const r = (k, v, id) => '<div class="row"' + (id ? ' id="' + id + '"' : '') + '><span>' + k + '</span><span>' + money(v) + '</span></div>';
            return '<div class="totals">' + r('Subtotal', t.subtotal ?? t.sub_total ?? 0) +
                (Number(t.discount_amount) ? r('Discount' + (t.promo_code ? ' (' + esc(t.promo_code) + ')' : ''), -t.discount_amount) : '') +
                (Number(t.tax_amount) ? r('Tax', t.tax_amount) : '') +
                (Number(t.service_charge_amount ?? t.service_charge) ? r('Service charge', t.service_charge_amount ?? t.service_charge) : '') +
                (Number(t.tip_amount) ? r('Tip', t.tip_amount) : '') +
                (Number(t.delivery_charge_amount) ? r('Delivery charge', t.delivery_charge_amount) : '') +
                '<div class="row grand"><span>Grand total</span><span>' + money(t.grand_total ?? 0) + '</span></div></div>';
        }
