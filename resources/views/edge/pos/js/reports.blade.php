{{-- W0 js/reports (Team 4 owns from W4): Quick Report — view / print here / send to network (e-mail is Internet-required). --}}
        // ---- Quick Report: the canonical report authority (view / print here / network); email is Internet-required. ----
        async function quickReport() {
            let o;
            try { o = await api('GET', '/quick-report/options'); }
            catch (e) { toast(e.message.includes('Permission') ? 'Quick Report is not enabled for your account.' : e.message); return; }
            const secLabel = { overview: 'Overview', categories: 'Categories', items: 'Items', category_items: 'Items by Category', deals: 'Deals', waiters: 'Waiters', order_types: 'Order Types', order_type_combos: 'Order Type × Combos', cancellations: 'Cancellations', cash_bank: 'Cash / Bank' };
            openModal('<h2>Quick Report</h2>' +
                '<div class="field"><label>Business date</label><input type="text" id="qr-date" value="' + esc(o.date) + '" placeholder="YYYY-MM-DD"></div>' +
                '<div class="field"><label>Sections</label><div style="display:flex;flex-wrap:wrap;gap:.4rem">' + o.sections.map(s => '<label class="chip"><input type="checkbox" class="qr-sec" value="' + s + '" checked> ' + esc(secLabel[s] || s) + '</label>').join('') + '</div></div>' +
                '<div class="field"><label>Categories (blank = all)</label><select id="qr-cats" multiple size="4">' + o.categories.map(c => '<option value="' + c.id + '">' + esc((c.parent_id ? '— ' : '') + c.name) + '</option>').join('') + '</select></div>' +
                '<div class="field"><label>Paper</label><select id="qr-paper"><option value="80mm">80mm</option><option value="58mm">58mm</option></select></div>' +
                '<div class="field"><label>Network printer</label><select id="qr-printer"><option value="">—</option>' + o.printers.map(p => '<option value="' + p.id + '">' + esc(p.name) + '</option>').join('') + '</select></div>' +
                '<p class="muted" id="qr-email-note">Email: ' + esc(o.email.reason) + '</p><div id="qr-err"></div>' +
                '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button>' +
                '<button class="ghost" id="qr-email" title="' + esc(o.email.reason) + '">Email (Internet required)</button>' +
                '<button class="ghost" id="qr-network">Send to network</button>' +
                '<button class="primary" id="qr-view">View / Print here</button></div>');
            const params = () => {
                const secs = Array.from(document.querySelectorAll('.qr-sec:checked')).map(x => x.value);
                const cats = Array.from($('qr-cats').selectedOptions).map(x => x.value);
                const p = new URLSearchParams(); p.set('date', $('qr-date').value.trim()); p.set('paper', $('qr-paper').value);
                secs.forEach(s => p.append('sections[]', s)); cats.forEach(c => p.append('category_ids[]', c));
                return p;
            };
            $('qr-view').onclick = () => { const w = window.open(BASE + '/quick-report/view?' + params().toString(), '_blank', 'width=460,height=760'); if (!w) toast('Allow pop-ups to view the report.'); };
            $('qr-network').onclick = async () => {
                const printer = Number($('qr-printer').value || 0); if (!printer) { $('qr-err').innerHTML = '<div class="err">Choose a network printer.</div>'; return; }
                try {
                    const p = params(); const body = { printer_id: printer, date: p.get('date'), sections: p.getAll('sections[]'), category_ids: p.getAll('category_ids[]') };
                    const r = await api('POST', '/quick-report/network', body); toast('Report → ' + r.printer);
                } catch (e) { $('qr-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
            $('qr-email').onclick = async () => {
                try { await api('POST', '/quick-report/email', {}); }
                catch (e) { $('qr-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
        }
