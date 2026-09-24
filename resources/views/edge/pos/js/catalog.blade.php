{{-- W2 js/catalog (Team 2): the Online menu — parent pills + child strip (A3, O:698-722 + O:6433-6495, POSController pill rules),
     Online product tiles with image/initials, SKU, branch price, stock / Low / Out / Backorder / Service badge, tax, "Customizable"
     (A4, renderProducts O:2711-2835), deal tiles with component availability (A9, comboAvailability O:2655-2676), one search box that
     filters by name / SKU / barcode and adds on an exact barcode (input) or barcode / SKU (Enter) match (A5, O:6304-6357), and the
     order-type-dependent inline controls: Quick Sale vehicle + waiter (A39) and the delivery panel (A14, updateDeliveryPanel
     O:2329-2392). Stock is the Edge OPERATIONAL balance (what this appliance refuses on), shipped by EdgeLocalPosController@screen. --}}
        // ---- menu lookups ----
        const productById = id => DATA.products.find(p => p.id === Number(id)) || null;
        function isMeasurableProduct(p) { return !!(p && (p.allow_decimal_qty || ['weight', 'volume', 'length'].indexOf(p.unit_type) !== -1)); }
        function qtyStep(p) { return isMeasurableProduct(p) ? 0.001 : 1; }
        function formatQty(q, p) { return isMeasurableProduct(p) ? Number(q || 0).toFixed(3) : String(Math.round(Number(q || 0))); }
        function activeModifierGroups(p) {
            return ((p && p.modifier_groups) || []).filter(g => !g.branch_id || Number(g.branch_id) === Number(DATA.branchId))
                .filter(g => (g.modifiers || []).length > 0).sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
        }
        function hasModifierGroups(p) { return activeModifierGroups(p).length > 0; }
        function productVariants(p) { return (p && p.variants) || []; }
        function defaultVariant(p) { const vs = productVariants(p); return vs.find(v => v.is_default) || null; }
        function productPrice(p, v) { return Number(v ? v.price : (p ? p.price : 0)) || 0; }
        // Online availableQty: a stock-tracked item → on-hand of the variant (or the product); anything else is not counted here.
        function availableQty(p, v) {
            if (!p || p.stock_kind !== 'tracked') return null;
            const cur = v ? v.stock : p.stock;
            // what this cart already holds of the same product/variant (the server refuses on the whole sale)
            const inCart = state.cart.filter(l => !l.line_id && l.product_id === p.id && Number(l.product_variant_id || 0) === Number(v ? v.id : (p.default_variant_id || 0)))
                .reduce((s, l) => s + Number(l.quantity || 0), 0);
            return cur === null || cur === undefined ? null : Number(cur) - inCart;
        }
        function comboAvailability(combo) {
            let makeable = Infinity, limiting = null;
            (combo.components || []).forEach(c => {
                const p = productById(c.product_id);
                if (!p) { makeable = 0; limiting = c.product_name || 'a component'; return; }
                const v = productVariants(p).find(x => x.id === Number(c.product_variant_id)) || null;
                const avail = p.stock_kind === 'tracked' ? Number(v ? v.stock : p.stock) : null;
                if (avail === null || isNaN(avail)) return;
                const can = Math.floor(avail / (Number(c.quantity || 1) || 1));
                if (can < makeable) { makeable = can; limiting = p.name; }
            });
            return makeable === Infinity ? { makeable: null, limiting: null } : { makeable: Math.max(0, makeable), limiting };
        }
        function tileInitials(name) { return String(name || '?').split(' ').map(x => x[0] || '').join('').substring(0, 2).toUpperCase(); }

        // ---- category pills (Online: "All", the flat "Deals" pill only while uncategorised deals exist, parent pills only for
        //      categories holding content; a parent matches its own AND its children's items; child strip for content children) ----
        let _childIds = [], _childCategory = null;
        function dealsCategoryId() {
            // A category literally named "Deals" IS the deals entry — never a second "Deals" pill beside it.
            const c = DATA.categories.find(x => (DATA.pillCategoryIds || []).includes(x.id) && String(x.name).trim().toLowerCase() === 'deals');
            return c ? c.id : null;
        }
        function renderTabs() {
            const tabs = $('parent-category-strip'); tabs.innerHTML = '';
            const all = tabBtn('All', null); tabs.appendChild(all);
            if (DATA.combos.length && DATA.hasUncategorizedCombos && dealsCategoryId() === null) tabs.appendChild(tabBtn('Deals', 'deals'));
            DATA.categories.filter(c => (DATA.pillCategoryIds || []).includes(c.id)).forEach(c => tabs.appendChild(tabBtn(c.name, c.id)));
            all.classList.add('active');
        }
        function tabBtn(label, id) {
            const b = document.createElement('button'); b.type = 'button'; b.className = 'pill'; b.textContent = label; b.dataset.parentCategory = id === null ? '' : String(id);
            b.addEventListener('click', () => {
                state.category = id; _childCategory = null;
                document.querySelectorAll('#parent-category-strip .pill').forEach(x => x.classList.remove('active')); b.classList.add('active');
                renderChildStrip(); renderTiles();
            });
            return b;
        }
        function renderChildStrip() {
            const wrap = $('child-category-wrap'), strip = $('child-category-strip'); strip.innerHTML = '';
            const parent = typeof state.category === 'number' ? DATA.categories.find(c => c.id === state.category) : null;
            _childIds = parent && parent.children ? parent.children.map(ch => Number(ch.id)) : [];
            const kids = (parent && parent.children ? parent.children : []).filter(ch => (DATA.contentCategoryIds || []).includes(Number(ch.id)));
            if (!kids.length) { wrap.hidden = true; return; }
            wrap.hidden = false;
            const mk = (label, id) => {
                const b = document.createElement('button'); b.type = 'button'; b.className = 'pill' + (id === _childCategory ? ' active' : ''); b.textContent = label;
                b.addEventListener('click', () => { _childCategory = id; strip.querySelectorAll('.pill').forEach(x => x.classList.remove('active')); b.classList.add('active'); renderTiles(); });
                strip.appendChild(b);
            };
            mk('All', null);
            kids.forEach(ch => mk(ch.name, Number(ch.id)));
        }
        function searchQuery() { const el = $('pos_search'); return el ? el.value.trim().toLowerCase() : ''; }
        function visibleItems() {
            const q = searchQuery();
            const dealsCat = dealsCategoryId();
            const dealsOnly = state.category === 'deals';
            const inCat = catId => {
                if (_childCategory !== null) return Number(catId) === Number(_childCategory);
                if (state.category === null || state.category === undefined) return true;
                return Number(catId) === Number(state.category) || _childIds.includes(Number(catId));
            };
            // a `hidden` product (kept only because it sits on an open bill / inside a deal) is never offered on the grid.
            const products = dealsOnly ? [] : DATA.products.filter(p => !p.hidden && inCat(p.category_id)).filter(p => !q
                || String(p.name).toLowerCase().includes(q) || String(p.sku || '').toLowerCase().includes(q)
                || (p.barcodes || []).some(b => String(b).toLowerCase().includes(q))
                || productVariants(p).some(v => String(v.sku || '').toLowerCase().includes(q) || (v.barcodes || []).some(b => String(b).toLowerCase().includes(q))));
            const allOrDeals = dealsOnly || ((state.category === null || state.category === undefined) && _childCategory === null);
            const combos = DATA.combos.filter(c => allOrDeals || (c.category_id && inCat(c.category_id))
                    || (!c.category_id && dealsCat !== null && state.category === dealsCat && _childCategory === null))
                .filter(c => !q || String(c.name).toLowerCase().includes(q) || String(c.code || '').toLowerCase().includes(q));
            return combos.map(c => Object.assign({ deal: true }, c)).concat(products.map(p => Object.assign({ deal: false }, p)));
        }
        function renderTiles() {
            const wrap = $('product-grid'); wrap.innerHTML = '';
            const items = visibleItems();
            if (!items.length) { wrap.innerHTML = '<p class="muted">No products found.</p>'; return; }
            const negOk = !!DATA.allowNegativeStock;
            items.forEach(i => {
                const b = document.createElement('button'); b.type = 'button';
                if (i.deal) {
                    const a = comboAvailability(i), out = a.makeable !== null && a.makeable <= 0, low = !out && a.makeable !== null && a.makeable <= 5;
                    b.className = 'tile w2 deal' + (out && !negOk ? ' stock-out' : ''); b.dataset.dealId = i.id;
                    b.innerHTML = '<div class="av">&#128230;</div><span class="nm">' + esc(i.name) + '</span><span class="sku">' + esc(i.code || 'Combo') + '</span>' +
                        '<div class="ft"><span class="pr">' + money(i.price) + '</span><span class="stock-badge ' + (out ? (negOk ? 'stock-backorder' : 'stock-out') : (low ? 'stock-low' : '')) + '">' + (out ? (negOk ? 'Backorder' : 'Unavailable') : 'Combo') + '</span></div>' +
                        (out ? '<span class="note">' + esc(a.limiting || 'A component') + ' out of stock</span>' : '<span class="note">' + (i.components || []).length + ' items' + (low ? ' · makes ' + a.makeable : '') + '</span>');
                } else {
                    const v = defaultVariant(i), qty = availableQty(i, v), price = productPrice(i, v);
                    const cls = qty === null ? 'stock-svc' : (qty <= 0 ? (negOk ? 'stock-backorder' : 'stock-out') : (qty <= 5 ? 'stock-low' : 'stock-ok'));
                    const txt = qty === null ? (i.stock_kind === 'recipe' ? 'Recipe' : 'Service') : (qty <= 0 ? (negOk ? 'Backorder' : 'Out') : 'Stock ' + formatQty(qty, i));
                    b.className = 'tile w2' + (qty !== null && qty <= 0 && !negOk ? ' stock-out' : ''); b.dataset.productId = i.id;
                    b.innerHTML = (i.image_url ? '<div class="av"><img src="' + esc(i.image_url) + '" alt="" loading="lazy"></div>' : '<div class="av">' + esc(tileInitials(i.name)) + '</div>') +
                        '<span class="nm">' + esc(i.name) + '</span><span class="sku">' + esc(i.sku || 'No SKU') + '</span>' +
                        '<div class="ft"><span class="pr">' + money(price) + (i.unit_code && isMeasurableProduct(i) ? '<span class="sku"> / ' + esc(i.unit_code) + '</span>' : '') + '</span><span class="stock-badge ' + cls + '">' + txt + '</span></div>' +
                        (i.is_taxable ? '<span class="note">Tax ' + esc(i.tax_rate_percent) + '%</span>' : '') +
                        (productVariants(i).length > 1 ? '<span class="note">' + productVariants(i).length + ' options</span>' : '') +
                        (hasModifierGroups(i) ? '<span class="note cust">Customizable</span>' : '');
                }
                b.addEventListener('click', () => addToCart(i));
                wrap.appendChild(b);
            });
        }

        // ---- barcode / SKU scan (Online handlePosBarcodeScan): exact product barcode → the product (default variant); exact
        //      variant barcode → THAT variant; on Enter an exact product / variant SKU also adds. The box clears + refocuses. ----
        function findScanMatch(raw, withSku) {
            const q = String(raw || '').trim().toLowerCase(); if (!q) return null;
            for (const p of DATA.products) {
                if (p.hidden) continue;
                for (const v of productVariants(p)) { if ((v.barcodes || []).some(b => String(b).toLowerCase() === q)) return { product: p, variant: v }; }
                if ((p.barcodes || []).some(b => String(b).toLowerCase() === q)) return { product: p, variant: undefined };
            }
            if (withSku) {
                for (const p of DATA.products) {
                    if (p.hidden) continue;
                    const v = productVariants(p).find(x => String(x.sku || '').toLowerCase() === q); if (v) return { product: p, variant: v };
                    if (String(p.sku || '').toLowerCase() === q) return { product: p, variant: undefined };
                }
            }
            return null;
        }
        function handlePosBarcodeScan(raw, withSku) {
            const m = findScanMatch(raw, withSku); if (!m) return false;
            const box = $('pos_search'); box.value = '';
            addToCart(Object.assign({ deal: false }, m.product), m.variant);
            renderTiles(); box.focus();
            return true;
        }
        (function wireSearch() {
            const box = $('pos_search'); if (!box) return;
            box.addEventListener('input', () => { handlePosBarcodeScan(box.value, false); renderTiles(); });
            box.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); if (!handlePosBarcodeScan(box.value, true) && box.value.trim()) toast('No product matches ' + box.value.trim() + '.', 'warning'); } });
        })();

        // ---- order-type-dependent inline controls (Online updateDeliveryPanel): Quick Sale vehicle + waiter, delivery panel ----
        (function fillInlineSelects() {
            const w = $('qs-waiter-select'); if (w) DATA.waiters.forEach(x => { const o = document.createElement('option'); o.value = x.id; o.textContent = x.name; w.appendChild(o); });
            const ch = $('delivery_channel_id');
            if (ch) DATA.deliveryChannels.forEach(x => { const o = document.createElement('option'); o.value = x.id; o.dataset.type = x.type; o.textContent = x.name + (x.type === 'aggregator' ? ' (aggregator)' : ''); ch.appendChild(o); });
            const r = $('delivery_rider_id'); if (r) DATA.deliveryRiders.forEach(x => { const o = document.createElement('option'); o.value = x.id; o.textContent = x.name; r.appendChild(o); });
        })();
        function effectiveOrderType() { return state.held ? state.held.order_type : (state.session ? 'dine_in' : state.orderType); }
        function onOrderTypeChanged() {
            const type = effectiveOrderType(), c = state.commercial;
            // Quick Sale (A39): inline + required; any other type hides AND clears them (never a stale value).
            const isQs = type === 'quick_sale';
            $('w2-quick-sale-row').hidden = !isQs; $('vehicle-wrap').hidden = !isQs; $('qs-waiter-wrap').hidden = !isQs;
            if (isQs && state.held) {
                if (!$('vehicle_number').value && state.held.vehicle_number) $('vehicle_number').value = state.held.vehicle_number;
                if (!$('qs-waiter-select').value && state.held.waiter_id) $('qs-waiter-select').value = String(state.held.waiter_id);
            }
            if (!isQs) { $('vehicle_number').value = ''; $('qs-waiter-select').value = ''; }
            // Delivery (A14): channel / rider (own only) / charge on the main pane; a recalled check shows what it carries.
            const isDel = type === 'delivery' && !state.session;
            $('delivery-panel').hidden = !isDel;
            if (!isDel) { c.delivery_channel_id = null; c.delivery_rider_id = null; if (!state.held) c.delivery_address = ''; return; }
            const ch = $('delivery_channel_id'), rider = $('delivery_rider_id'), charge = $('delivery_charge_amount');
            ch.value = c.delivery_channel_id ? String(c.delivery_channel_id) : '';
            const opt = ch.selectedOptions[0], isOwn = !!(opt && opt.dataset.type === 'own');
            $('delivery-rider-wrap').hidden = !isOwn;
            if (!isOwn) c.delivery_rider_id = null;
            rider.value = c.delivery_rider_id ? String(c.delivery_rider_id) : '';
            charge.disabled = !!DATA.deliveryChargeLocked || !!state.held;
            charge.value = DATA.deliveryChargeLocked ? money(DATA.defaultDeliveryCharge) : (c.delivery_charge_amount ?? '');
            [ch, rider].forEach(el => el.disabled = !!state.held);
            $('delivery_address').value = c.delivery_address || '';
            $('delivery-panel-note').textContent = state.held ? 'Delivery details were fixed when the order was held.'
                : (DATA.deliveryChargeLocked ? 'Charge fixed by the branch.' : '') + (isOwn && !state.customer ? ' Own delivery needs a customer — use Add / Search Customer.' : '');
        }
        (function wireInlineControls() {
            $('delivery_channel_id').addEventListener('change', e => { state.commercial.delivery_channel_id = e.target.value || null; state.dirty = state.dirty || !!state.held; onOrderTypeChanged(); scheduleQuote(); });
            $('delivery_rider_id').addEventListener('change', e => { state.commercial.delivery_rider_id = e.target.value || null; });
            $('delivery_charge_amount').addEventListener('input', e => { if (!DATA.deliveryChargeLocked) { state.commercial.delivery_charge_amount = Number(e.target.value || 0); scheduleQuote(); } });
            const ot = $('order-type'); if (ot) ot.addEventListener('change', () => setTimeout(() => { onOrderTypeChanged(); scheduleQuote(); }, 0));
            document.querySelectorAll('[data-mode-tab]').forEach(b => b.addEventListener('click', () => setTimeout(() => { onOrderTypeChanged(); scheduleQuote(); }, 0)));
            document.addEventListener('edge:order-type-changed', () => { onOrderTypeChanged(); scheduleQuote(); }); // Team 1's tab switch event
        })();
        // The Quick Sale attribution the page will send (inline fields — Online requireQuickSaleFields O:4495-4511).
        function quickSaleAttribution() {
            return { vehicle_number: ($('vehicle_number').value || '').trim(), restaurant_waiter_id: Number($('qs-waiter-select').value || 0) || null };
        }
        function requireQuickSaleFields() {
            if (effectiveOrderType() !== 'quick_sale' || state.held) return true;
            const q = quickSaleAttribution();
            if (!q.vehicle_number) { toast('Enter the vehicle number for this quick sale.', 'warning'); $('vehicle_number').focus(); return false; }
            if (!q.restaurant_waiter_id) { toast('Select the waiter for this quick sale.', 'warning'); $('qs-waiter-select').focus(); return false; }
            return true;
        }
