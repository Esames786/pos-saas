{{-- W4 js/shift (Team 4): the Shift dialog — Online open-shift (opening cash + notes, terminal shown) and close-shift
     (denomination count grid with live counted total, live difference vs expected unless blind count, closing notes),
     operating date, tender breakup, zero drawer, post-close summary + links to the Edge shift history / detail screens.
     Interface: openShiftDialog() (Team 1 wires #pos-shift-open-link / #shift-btn); shiftAction() stays as an alias. --}}
        // ---- Shift: the Online shift screens' truth — operating business date, tender breakup, blind count, zero drawer, terminal lock. ----
        async function openShiftDialog() {
            let s;
            try { s = await api('GET', '/shift/summary'); } catch (e) { toast(e.message); return; }
            const sh = s.shift, b = s.breakup, perms = s.permissions || {};
            const amt = v => v === null || v === undefined ? '*****' : money(v);
            const row = (k, v, id) => '<div class="row"' + (id ? ' id="' + id + '"' : '') + '><span>' + k + '</span><span>' + v + '</span></div>';
            let html = '<h2 id="shift-dialog-title">' + (sh ? 'Close Shift' : 'Open Shift') + '</h2>' +
                '<p class="muted">' + esc(s.branch_name || '') + ' — <strong id="sh-terminal-name">' + esc(s.terminal_name || '') + '</strong></p>' +
                '<p class="muted">Operating business date <strong id="sh-operating-date">' + esc(s.operating_business_date) + '</strong>' + (s.operating_business_date !== s.current_business_date ? ' (clock says ' + esc(s.current_business_date) + ')' : '') + '</p>';
            if (sh) {
                html += '<p class="muted">Open since ' + esc(sh.opened_at_display || new Date(sh.opened_at).toLocaleString()) + ' · business date ' + esc(sh.business_date) + (sh.opening_notes ? ' · ' + esc(sh.opening_notes) : '') + '</p>' +
                    '<div class="totals" id="sh-breakup">' + row('Opening cash', amt(b.opening_cash)) + row('Total sales', amt(b.total_sales)) +
                    row('Cash', amt(b.cash)) + row('Card', amt(b.card)) + row('Bank', amt(b.bank)) + (Number(b.cheque) ? row('Cheque', amt(b.cheque)) : '') +
                    (Number(b.refunds) ? row('Refunds', amt(b.refunds)) : '') +
                    row('Cancelled bills', b.cancelled_bills + (b.cancelled_amount === null ? '' : ' · ' + money(b.cancelled_amount))) +
                    row('Voided lines', b.voided_lines + ' (' + b.voided_units + ' units)') +
                    '<div class="row grand"><span>Expected cash</span><span id="sh-expected">' + amt(b.expected_cash) + '</span></div></div>' +
                    (s.may_see_amounts ? '' : '<p class="muted" id="sh-blind-note">Blind count — amounts are hidden for your role. Count the drawer and enter what you have.</p>') +
                    (sh.zero_drawer ? '<p class="muted" id="sh-zero-drawer">Empty drawer — nothing to count; you can close without a count.</p>' : '');
                if (s.currency && s.currency.denominations.length) {
                    html += '<fieldset class="field" id="sh-denominations" style="border:1px solid var(--line);border-radius:8px;padding:.5rem .7rem"><legend class="muted" style="font-size:.8rem;padding:0 .3rem">Optional Cash Denomination Count</legend>' +
                        '<p class="muted" style="margin:.1rem 0 .4rem;font-size:.8rem">Leave all quantities empty if you want to enter counted cash manually.</p>' +
                        '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(8.5rem,1fr));gap:.4rem">' +
                        s.currency.denominations.map(d => '<label style="font-size:.8rem">' + esc(s.currency.symbol || '') + ' ' + money(d.value) + ' <span class="muted">' + esc(d.type) + '</span>' +
                            '<input type="number" min="0" step="1" class="cash-denomination" id="denomination_' + d.id + '" data-id="' + d.id + '" data-value="' + d.value + '" value="0" aria-label="' + esc(money(d.value)) + ' quantity"></label>').join('') +
                        '</div><div class="muted" style="margin-top:.4rem" aria-live="polite">Calculated Cash Count: <strong id="cash-count-total">0.00</strong></div></fieldset>';
                } else {
                    html += '<p class="muted" id="sh-no-denominations">No default-currency denominations on this branch server — enter the counted total below.</p>';
                }
                html += '<div class="field"><label for="counted_cash">Manual Counted Cash' + (sh.zero_drawer ? ' (optional)' : '') + '</label><input type="number" id="counted_cash" min="0" step="0.01" placeholder="Count the drawer"></div>' +
                    '<p class="muted" style="font-size:.8rem;margin-top:-.3rem">Enter the counted total here, or count denominations above — one of the two is required' + (sh.zero_drawer ? ' unless the drawer is empty' : '') + '.</p>' +
                    '<p id="counted-diff" class="muted" style="font-size:.85rem">' + (s.may_see_amounts ? 'Expected ' + money(b.expected_cash) : 'Count the drawer and enter the total.') + '</p>' +
                    '<div class="field"><label for="closing_notes">Closing Notes</label><input type="text" id="closing_notes" maxlength="500"></div>' +
                    '<p class="muted" style="font-size:.8rem" id="sh-shortage-note">If the counted cash is less than expected, the shift still closes and the shortage is recorded on it.</p>';
            } else {
                html += '<p class="muted" id="sh-no-shift">No open shift on this terminal.</p>' +
                    '<div class="field"><label for="opening_cash">Opening Cash</label><input type="number" id="opening_cash" value="0" min="0" step="0.01" required></div>' +
                    '<div class="field"><label for="opening_notes">Opening Notes</label><input type="text" id="opening_notes" maxlength="500" placeholder="Optional"></div>';
            }
            if (s.branch_open_shifts.length) {
                html += '<h3>Open shifts on this branch</h3>' + s.branch_open_shifts.map(x => '<div class="list-row" style="cursor:default"><span>' + esc(x.terminal_name || ('Terminal ' + x.terminal_id)) + (x.is_current ? ' (this counter)' : '') + '</span><span class="muted">' + esc(x.business_date) + '</span></div>').join('');
            }
            html += '<div id="sh-err"></div><div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button>' +
                (perms.can_view_history ? '<a class="navbtn" id="sh-history-link" href="' + esc(s.history_url) + '">Shift history</a>' : '') +
                (sh ? (perms.can_close === false ? '' : '<button class="danger" id="sh-close">Close Shift</button>') : (perms.can_open === false ? '' : '<button class="ok" id="sh-open">Open Shift</button>')) + '</div>';
            openModal(html);
            const err = m => { $('sh-err').innerHTML = '<div class="err">' + esc(m) + '</div>'; };
            const o = $('sh-open'), c = $('sh-close');
            if (o) o.onclick = async () => {
                const v = $('opening_cash').value.trim();
                if (v === '') { err('Enter the opening cash in the drawer (type 0 for an empty drawer).'); return; }
                try {
                    const r = await api('POST', '/shift/open', { opening_cash: Number(v), opening_notes: $('opening_notes').value.trim() || null });
                    toast('Shift opened on ' + (r.terminal_name || 'this terminal') + ' · business date ' + r.business_date + '.'); closeModal();
                    if (typeof refreshShiftBadge === 'function') refreshShiftBadge();
                } catch (e) { err(e.message); }
            };
            if (!c) return;
            // Live counted total (denominations) + live difference vs expected — never for a blind count (no expected on the page).
            const denomTotal = () => Array.from(document.querySelectorAll('.cash-denomination')).reduce((t, i) => t + (parseInt(i.value || '0', 10) || 0) * Number(i.dataset.value || 0), 0);
            const anyDenom = () => Array.from(document.querySelectorAll('.cash-denomination')).some(i => (parseInt(i.value || '0', 10) || 0) > 0);
            const counted = () => anyDenom() ? denomTotal() : ($('counted_cash').value.trim() === '' ? null : Number($('counted_cash').value));
            const refreshDiff = () => {
                if ($('cash-count-total')) $('cash-count-total').textContent = money(denomTotal());
                const out = $('counted-diff');
                if (!s.may_see_amounts || b.expected_cash === null) return;
                const exp = Number(b.expected_cash), v = counted();
                if (v === null) { out.textContent = 'Expected ' + money(exp); out.style.color = ''; return; }
                const diff = v - exp;
                if (Math.abs(diff) < 0.005) { out.textContent = 'Exact — matches expected ' + money(exp); out.style.color = ''; }
                else if (diff < 0) { out.textContent = 'Short by ' + money(-diff) + ' (expected ' + money(exp) + ')'; out.style.color = '#fca5a5'; }
                else { out.textContent = 'Over by ' + money(diff) + ' (expected ' + money(exp) + ')'; out.style.color = '#fcd34d'; }
            };
            document.querySelectorAll('.cash-denomination').forEach(i => i.addEventListener('input', refreshDiff));
            $('counted_cash').addEventListener('input', refreshDiff);
            refreshDiff();
            c.onclick = async () => {
                const body = { closing_notes: $('closing_notes').value.trim() || null };
                if (anyDenom()) {
                    body.denominations = {};
                    document.querySelectorAll('.cash-denomination').forEach(i => { const q = parseInt(i.value || '0', 10) || 0; if (q > 0) body.denominations[i.dataset.id] = q; });
                } else if ($('counted_cash').value.trim() !== '') {
                    body.counted_cash = Number($('counted_cash').value); // ZERO-DRAWER: no count typed → the server decides under the lock
                }
                try {
                    const r = await api('POST', '/shift/close', body);
                    shiftClosedSummary(r, s);
                    if (typeof refreshShiftBadge === 'function') refreshShiftBadge();
                } catch (e) { err(e.message); }
            };
        }
        // Post-close summary (Online lands on /shifts/{id}): the figures when allowed, the shortage note, a link to the Edge shift detail.
        function shiftClosedSummary(r, s) {
            const row = (k, v) => '<div class="row"><span>' + k + '</span><span>' + v + '</span></div>';
            openModal('<h2 id="shift-closed-title">Shift closed</h2><p class="muted">' + esc(s.terminal_name || '') + ' · shift #' + r.shift_id + '</p>' +
                (r.may_see_amounts ? '<div class="totals" id="shift-closed-figures">' + row('Expected cash', money(r.expected_cash)) + row('Counted cash', money(r.counted_cash)) +
                    '<div class="row grand"><span>Variance</span><span>' + money(r.cash_variance) + '</span></div></div>' : '<p class="muted">Your count is recorded. Amounts are hidden for your role.</p>') +
                (r.closing_notes ? '<p class="muted">Notes: ' + esc(r.closing_notes) + '</p>' : '') +
                (r.shortage_voucher && r.shortage_voucher.message ? '<div class="err" id="shift-closed-shortage">' + esc(r.shortage_voucher.message) + '</div>' : '') +
                '<div class="btn-row">' + ((s.permissions || {}).can_view_detail ? '<a class="navbtn" id="shift-closed-detail-link" href="' + esc(r.detail_url) + '">View shift</a>' : '') +
                '<button class="primary" onclick="EdgePOS.closeModal()">Done</button></div>');
        }
        // Backward-compatible name (js/boot and older callers use shiftAction).
        function shiftAction() { return openShiftDialog(); }
