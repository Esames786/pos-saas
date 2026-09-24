{{-- W4 js/reports (Team 4): Quick Report — the Online #quickReportModal on the canonical report authority: business date,
     branch (the bound branch), "Save my selection" (per-user, stored on this branch server), 10 sections with the
     categories / items (All items + search + chips) / waiters / order-types sub-filters, Print here (opens the thermal
     page and auto-prints, like Online), View, Send to network; e-mail is Internet-required (truthful 422).
     Interface: openQuickReport() (Team 1 wires #pos-quick-report-btn); quickReport() stays (js/actions uses it). --}}
        // ---- Quick Report: the canonical report authority (view / print here / network); email is Internet-required. ----
        async function openQuickReport() {
            if (DATA.canQuickReport === false) { toast('Quick Report is not enabled for your account.'); return; }
            let o;
            try { o = await api('GET', '/quick-report/options'); }
            catch (e) { toast(e.message.includes('Permission') ? 'Quick Report is not enabled for your account.' : e.message); return; }
            const secLabel = { overview: 'Overview', categories: 'Categories', items: 'Items', category_items: 'Items by Category', deals: 'Deals', waiters: 'Waiters', order_types: 'Order Types', order_type_combos: 'Order-Type Combos', cancellations: 'Cancellations', cash_bank: 'Cash & Bank' };
            const box = (cls, value, id, label, sub) => '<label class="chip" style="' + (sub ? 'margin-left:1rem;' : '') + '"><input type="checkbox" class="' + cls + '" value="' + esc(value) + '" id="' + id + '"> ' + esc(label) + '</label>';
            const parents = o.categories.filter(c => !c.parent_id), kids = pid => o.categories.filter(c => Number(c.parent_id) === Number(pid));
            const orphans = o.categories.filter(c => c.parent_id && !o.categories.some(p => Number(p.id) === Number(c.parent_id)));
            const panel = key => {
                if (key === 'categories') return '<div class="qr-panel" id="qr-panel-categories" style="margin:.2rem 0 .5rem 1.4rem"><div class="muted" style="font-size:.78rem">Leave all unticked = every category. Picking a category also includes its sub-categories &amp; items.</div><div id="qr-cats" style="display:flex;flex-wrap:wrap;gap:.3rem">' +
                    parents.map(c => box('qr-category', c.id, 'qr-cat-' + c.id, c.name) + kids(c.id).map(k => box('qr-category', k.id, 'qr-cat-' + k.id, '↳ ' + k.name, true)).join('')).join('') + orphans.map(c => box('qr-category', c.id, 'qr-cat-' + c.id, c.name)).join('') + '</div></div>';
                if (key === 'items') return '<div class="qr-panel" id="qr-panel-items" style="margin:.2rem 0 .5rem 1.4rem"><label class="chip"><input type="checkbox" id="qr-all-items" checked> All items</label>' +
                    '<div id="qr-item-picker" hidden style="position:relative;margin-top:.3rem"><input type="text" id="qr-item-search" placeholder="Search product name / SKU…" autocomplete="off">' +
                    '<div id="qr-item-suggest" class="list" hidden style="position:absolute;z-index:5;max-height:220px;overflow:auto;min-width:260px;background:var(--panel);border:1px solid var(--line)"></div>' +
                    '<div id="qr-item-chips" style="display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.3rem"></div></div></div>';
                if (key === 'waiters') return '<div class="qr-panel" id="qr-panel-waiters" style="margin:.2rem 0 .5rem 1.4rem"><div class="muted" style="font-size:.78rem">Leave all unticked = every waiter.</div><div style="display:flex;flex-wrap:wrap;gap:.3rem">' +
                    (o.waiters.length ? o.waiters.map(w => box('qr-waiter', w.id, 'qr-w-' + w.id, w.name)).join('') : '<span class="muted">No waiters on this branch.</span>') + '</div></div>';
                if (key === 'order_types') return '<div class="qr-panel" id="qr-panel-order_types" style="margin:.2rem 0 .5rem 1.4rem"><div class="muted" style="font-size:.78rem">Leave all unticked = every order type.</div><div style="display:flex;flex-wrap:wrap;gap:.3rem">' +
                    Object.keys(o.order_types).map(k => box('qr-ordertype', k, 'qr-ot-' + k, o.order_types[k])).join('') + '</div></div>';
                return '';
            };
            openModal('<h2 id="quickReportModalLabel">Quick Report</h2><div id="qr-toast" hidden></div>' +
                '<div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end">' +
                '<div class="field" style="flex:1;min-width:9rem"><label for="qr-date">Business date</label><input type="date" id="qr-date" value="' + esc(o.date) + '"></div>' +
                '<div class="field" style="flex:1;min-width:9rem"><label for="qr-branch">Branch</label><select id="qr-branch" disabled><option value="' + DATA.branchId + '" selected>' + esc(DATA.branchName || '') + '</option></select></div>' +
                '<label class="chip" style="margin-bottom:.8rem"><input type="checkbox" id="qr-save"> Save my selection</label></div>' +
                '<div class="field"><label>Sections — tick what to include</label><div style="border:1px solid var(--line);border-radius:8px;padding:.4rem .6rem">' +
                o.sections.map(s => '<div class="qr-section-row"><label class="chip"><input type="checkbox" class="qr-sec qr-section" value="' + s + '" id="qr-sec-' + s + '" checked' + (['categories', 'items', 'waiters', 'order_types'].includes(s) ? ' data-panel="qr-panel-' + s + '"' : '') + '> ' + esc(secLabel[s] || s) + '</label>' + panel(s) + '</div>').join('') + '</div></div>' +
                '<div style="display:flex;flex-wrap:wrap;gap:.6rem"><div class="field" style="flex:1;min-width:7rem"><label for="qr-paper">Paper (print here)</label><select id="qr-paper"><option value="80mm">80mm</option><option value="58mm">58mm</option></select></div>' +
                '<div class="field" style="flex:3;min-width:12rem"><label for="qr-printer">Network printer (for “Send to network”)</label><select id="qr-printer"><option value="">— choose a network printer —</option>' + o.printers.map(p => '<option value="' + p.id + '">' + esc(p.name) + (p.paper_size ? ' (' + esc(p.paper_size) + ')' : '') + '</option>').join('') + '</select></div></div>' +
                '<p class="muted" id="qr-header-note" style="font-size:.78rem">Report header: ' + esc(o.business_name || '') + '</p>' +
                '<p class="muted" id="qr-email-note">Email: ' + esc(o.email.reason) + '</p><div id="qr-err"></div>' +
                '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button>' +
                '<button class="ghost" id="qr-email" title="' + esc(o.email.reason) + '">Email (Internet required)</button>' +
                '<button class="ghost" id="qr-network">Send to network</button>' +
                '<button class="ghost" id="qr-view">View</button>' +
                '<button class="primary" id="qr-print">Print here</button></div>');
            const note = (msg, ok) => { const t = $('qr-toast'); t.hidden = false; t.className = ok ? 'muted' : 'err'; t.textContent = msg; };
            const checked = sel => Array.from(document.querySelectorAll(sel + ':checked')).map(x => x.value);
            let chosen = {}; // product id -> name
            document.querySelectorAll('.qr-section[data-panel]').forEach(cb => { const p = $(cb.dataset.panel); const sync = () => { if (p) p.hidden = !cb.checked; }; cb.addEventListener('change', sync); sync(); });
            const allItems = $('qr-all-items'), picker = $('qr-item-picker');
            const syncItems = () => { if (picker && allItems) picker.hidden = allItems.checked; };
            if (allItems) allItems.addEventListener('change', syncItems); syncItems();
            const chips = () => {
                const c = $('qr-item-chips'); if (!c) return;
                c.innerHTML = Object.keys(chosen).map(id => '<span class="chip">' + esc(chosen[id]) + ' <a href="#" data-id="' + id + '" style="color:#fca5a5">×</a></span>').join('');
                c.querySelectorAll('a[data-id]').forEach(a => a.onclick = e => { e.preventDefault(); delete chosen[a.dataset.id]; chips(); });
            };
            const search = $('qr-item-search'), suggest = $('qr-item-suggest');
            if (search) search.addEventListener('input', () => {
                const q = search.value.trim().toLowerCase();
                if (q.length < 2) { suggest.hidden = true; return; }
                const hits = o.items.filter(p => String(p.name || '').toLowerCase().includes(q) || String(p.sku || '').toLowerCase().includes(q)).slice(0, 12);
                suggest.innerHTML = hits.map(p => '<div class="list-row" data-id="' + p.id + '">' + esc(p.name) + (p.sku ? ' · ' + esc(p.sku) : '') + '</div>').join('');
                suggest.querySelectorAll('[data-id]').forEach(r => r.onclick = () => { const p = o.items.find(x => String(x.id) === r.dataset.id); chosen[r.dataset.id] = p ? p.name : ('#' + r.dataset.id); chips(); suggest.hidden = true; search.value = ''; });
                suggest.hidden = hits.length === 0;
            });
            const collect = () => ({
                date: $('qr-date').value, sections: checked('.qr-section'), category_ids: checked('.qr-category'), waiter_ids: checked('.qr-waiter'),
                order_types: checked('.qr-ordertype'), all_items: allItems && allItems.checked ? 1 : 0,
                product_ids: allItems && allItems.checked ? [] : Object.keys(chosen), printer_id: $('qr-printer').value, paper: $('qr-paper').value,
            });
            const toQuery = p => {
                const q = new URLSearchParams(); q.set('date', p.date); q.set('paper', p.paper); q.set('all_items', String(p.all_items));
                ['sections', 'category_ids', 'waiter_ids', 'order_types', 'product_ids'].forEach(k => (p[k] || []).forEach(v => q.append(k + '[]', v)));
                return q.toString();
            };
            const saveNow = () => { const p = collect(); api('POST', '/quick-report/save-settings', { sections: p.sections, category_ids: p.category_ids, product_ids: p.product_ids, waiter_ids: p.waiter_ids, order_types: p.order_types, all_items: !!p.all_items }).catch(() => {}); };
            const maybeSave = () => { if ($('qr-save').checked) saveNow(); };
            $('qr-save').addEventListener('change', () => { if ($('qr-save').checked) saveNow(); });
            const guard = p => { if (!p.sections.length) { note('Tick at least one section.', false); return false; } return true; };
            // Print here (Online): open the thermal page and print it on load. View: the same page without printing.
            const openReport = print => {
                const p = collect(); if (!guard(p)) return; maybeSave();
                const w = window.open(BASE + '/quick-report/view?' + toQuery(p), '_blank', 'width=460,height=760');
                if (!w) { toast('Allow pop-ups to view the report.'); return; }
                if (print) w.addEventListener('load', () => { try { w.print(); } catch (e) { /* the page has its own Print button */ } });
            };
            $('qr-print').onclick = () => openReport(true);
            $('qr-view').onclick = () => openReport(false);
            $('qr-network').onclick = async () => {
                const p = collect(); if (!guard(p)) return;
                const printer = Number(p.printer_id || 0); if (!printer) { $('qr-err').innerHTML = '<div class="err">Choose a network printer first.</div>'; return; }
                maybeSave();
                try {
                    const r = await api('POST', '/quick-report/network', { printer_id: printer, date: p.date, sections: p.sections, category_ids: p.category_ids, waiter_ids: p.waiter_ids, order_types: p.order_types, product_ids: p.product_ids, all_items: !!p.all_items });
                    note('Queued to ' + r.printer + '.', true); toast('Report → ' + r.printer);
                } catch (e) { $('qr-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
            $('qr-email').onclick = async () => {
                try { await api('POST', '/quick-report/email', {}); }
                catch (e) { $('qr-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
            // Pre-fill from the saved selection (Online: on modal open).
            try {
                const res = await api('GET', '/quick-report/settings');
                const s = res && res.settings;
                if (s) {
                    $('qr-save').checked = true;
                    document.querySelectorAll('.qr-section').forEach(c => { c.checked = (s.sections || []).includes(c.value); c.dispatchEvent(new Event('change')); });
                    document.querySelectorAll('.qr-category').forEach(c => { c.checked = (s.category_ids || []).map(String).includes(c.value); });
                    document.querySelectorAll('.qr-waiter').forEach(c => { c.checked = (s.waiter_ids || []).map(String).includes(c.value); });
                    document.querySelectorAll('.qr-ordertype').forEach(c => { c.checked = (s.order_types || []).map(String).includes(c.value); });
                    if (allItems) { allItems.checked = s.all_items !== false; syncItems(); }
                    chosen = {};
                    (s.product_ids || []).forEach(id => { const hit = o.items.find(p => String(p.id) === String(id)); chosen[id] = hit ? hit.name : ('#' + id); });
                    chips();
                }
            } catch (e) { /* no saved selection */ }
        }
        // Backward-compatible name (js/actions renders the Quick Report button with quickReport).
        function quickReport() { return openQuickReport(); }
