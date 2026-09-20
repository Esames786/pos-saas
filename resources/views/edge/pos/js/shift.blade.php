{{-- W0 js/shift (Team 4 owns from W4): the Shift dialog — operating date, tender breakup, blind count, zero drawer, open/close. --}}
        // ---- Shift: the Online shift screen's truth — operating business date, tender breakup, blind count, zero drawer, terminal lock. ----
        async function shiftAction() {
            try {
                const s = await api('GET', '/shift/summary');
                const sh = s.shift, b = s.breakup;
                const amt = v => v === null || v === undefined ? '*****' : money(v);
                const row = (k, v) => '<div class="row"><span>' + k + '</span><span>' + v + '</span></div>';
                let html = '<h2>Shift</h2><p class="muted">Operating business date <strong>' + esc(s.operating_business_date) + '</strong>' + (s.operating_business_date !== s.current_business_date ? ' (clock says ' + esc(s.current_business_date) + ')' : '') + '</p>';
                if (sh) {
                    html += '<p class="muted">Open since ' + esc(new Date(sh.opened_at).toLocaleString()) + ' · business date ' + esc(sh.business_date) + '</p>' +
                        '<div class="totals">' + row('Opening cash', amt(b.opening_cash)) + row('Total sales', amt(b.total_sales)) +
                        row('Cash', amt(b.cash)) + row('Card', amt(b.card)) + row('Bank', amt(b.bank)) + (Number(b.cheque) ? row('Cheque', amt(b.cheque)) : '') +
                        row('Cancelled bills', b.cancelled_bills + (b.cancelled_amount === null ? '' : ' · ' + money(b.cancelled_amount))) +
                        row('Voided lines', b.voided_lines + ' (' + b.voided_units + ' units)') +
                        '<div class="row grand"><span>Expected cash</span><span>' + amt(b.expected_cash) + '</span></div></div>' +
                        (s.may_see_amounts ? '' : '<p class="muted">Blind count — amounts are hidden for your role. Count the drawer and enter what you have.</p>') +
                        (sh.zero_drawer ? '<p class="muted">Empty drawer — nothing to count; you can close without a count.</p>' : '') +
                        '<div class="field"><label>Counted cash' + (sh.zero_drawer ? ' (optional)' : '') + '</label><input type="number" id="sh-counted" min="0" step="0.01" placeholder="' + (sh.zero_drawer ? '0' : 'type the counted amount') + '"></div>';
                } else {
                    html += '<p class="muted">No open shift on this terminal.</p><div class="field"><label>Opening cash</label><input type="number" id="sh-opening" value="0" min="0" step="0.01"></div>';
                }
                if (s.branch_open_shifts.length) {
                    html += '<h3>Open shifts on this branch</h3>' + s.branch_open_shifts.map(x => '<div class="list-row" style="cursor:default"><span>' + esc(x.terminal_name || ('Terminal ' + x.terminal_id)) + (x.is_current ? ' (this counter)' : '') + '</span><span class="muted">' + esc(x.business_date) + '</span></div>').join('');
                }
                html += '<div id="sh-err"></div><div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button>' +
                    (sh ? '<button class="danger" id="sh-close">Close shift</button>' : '<button class="ok" id="sh-open">Open shift</button>') + '</div>';
                openModal(html);
                const o = $('sh-open'), c = $('sh-close');
                if (o) o.onclick = async () => { try { await api('POST', '/shift/open', { opening_cash: Number($('sh-opening').value || 0) }); toast('Shift opened.'); closeModal(); } catch (e) { $('sh-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; } };
                if (c) c.onclick = async () => {
                    try {
                        const v = $('sh-counted').value.trim();
                        const body = v === '' ? {} : { counted_cash: Number(v) }; // ZERO-DRAWER: no count typed → the server decides under the lock
                        const r = await api('POST', '/shift/close', body);
                        toast('Shift closed' + (s.may_see_amounts ? ' · variance ' + money(r.cash_variance) : '.')); closeModal();
                    } catch (e) { $('sh-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
                };
            } catch (e) { toast(e.message); }
        }
