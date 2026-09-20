{{-- W0 js/tables (Team 3 owns from W3): Table Board — open / recall / new check / close / reserve / cancel reservation. --}}
        // ---- Table Board: open / recall / new check / close (the server decides under a lock). ----
        async function viewTables() {
            try {
                const b = await api('GET', '/restaurant/board');
                let html = '<h2>Table Board</h2>';
                (b.floors || []).forEach(f => {
                    html += '<h3>' + esc(f.name) + '</h3><div class="board">';
                    (f.tables || []).forEach(t => {
                        html += '<div class="tbl ' + esc(t.status) + '" data-table=\'' + esc(JSON.stringify(t)) + '\'><strong>' + esc(t.table_no || t.name) + '</strong><div class="muted">' + esc(t.status.replace('_', ' ')) +
                            (t.session?.waiter_name ? ' · ' + esc(t.session.waiter_name) : '') + (t.reservation?.customer_name ? ' · ' + esc(t.reservation.customer_name) : '') + '</div></div>';
                    });
                    html += '</div>';
                });
                if (!(b.floors || []).length) html += '<p class="muted">No floors configured.</p>';
                html += '<div id="table-actions"></div><div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div>';
                openModal(html);
                document.querySelectorAll('#modal .tbl').forEach(el => el.addEventListener('click', () => { document.querySelectorAll('#modal .tbl').forEach(x => x.classList.remove('selected')); el.classList.add('selected'); tableActions(JSON.parse(el.dataset.table)); }));
            } catch (e) { toast(e.message); }
        }
        function tableActions(t) {
            const box = $('table-actions'); let html = '<h3>Table ' + esc(t.table_no || t.name) + '</h3>';
            if (t.session) {
                const held = t.session.held_orders || [];
                html += '<p class="muted">' + esc(t.session.status) + (t.session.waiter_name ? ' · ' + esc(t.session.waiter_name) : '') + ' · ' + t.session.guest_count + ' guests</p>';
                held.forEach(h => { html += '<div class="list-row" data-recall="' + h.id + '"><span>Open check ' + esc(h.sale_no) + '</span><span>' + money(h.grand_total) + '</span></div>'; });
                html += '<div class="btn-row left">' +
                    (held.length ? '' : '<button class="primary" id="ta-new">New check</button><button class="danger" id="ta-close">Close table (empty)</button>') + '</div>';
            } else {
                const r = t.reservation;
                if (r) {
                    // ONLINE-POS PARITY: reserved table — who / when / note, Open (customer carries onto the order), Cancel.
                    const when = r.reserved_for ? new Date(r.reserved_for).toLocaleString() : 'no time set';
                    html += '<div class="chip hot" style="display:inline-block;margin-bottom:.4rem">Reserved</div>' +
                        '<p><strong>' + esc(r.customer_name || 'Walk-in') + '</strong>' + (r.customer_phone ? ' · ' + esc(r.customer_phone) : '') + '<br><span class="muted">' + esc(when) + (r.note ? ' · ' + esc(r.note) : '') + '</span></p>';
                }
                html += '<div class="field"><label>Waiter</label><select id="ta-waiter"><option value="">—</option>' + DATA.waiters.map(w => '<option value="' + w.id + '">' + esc(w.name) + '</option>').join('') + '</select></div>' +
                    '<div class="field"><label>Guests</label><input type="number" id="ta-guests" value="' + (t.capacity || 2) + '" min="1" max="100"></div>' +
                    '<div class="btn-row left"><button class="primary" id="ta-open">' + (r ? 'Open reserved table' : 'Open table') + '</button>' +
                    (r ? '<button class="danger" id="ta-unreserve">Cancel reservation</button>' : '<button class="ghost" id="ta-reserve-toggle">Reserve…</button>') + '</div>' +
                    (r ? '' : '<div id="ta-reserve-form" hidden><h3>Reserve table ' + esc(t.table_no || t.name) + '</h3>' +
                        '<div class="field"><label>Customer (blank = walk-in)</label><input type="text" id="rs-name" placeholder="Name"></div>' +
                        '<div class="field"><label>Phone</label><input type="text" id="rs-phone"></div>' +
                        '<div class="field"><label>Reserved for</label><input type="datetime-local" id="rs-when"></div>' +
                        '<div class="field"><label>Note</label><input type="text" id="rs-note" placeholder="e.g. birthday, window seat"></div>' +
                        '<div id="rs-err"></div><div class="btn-row left"><button class="primary" id="ta-reserve">Reserve</button></div></div>');
            }
            box.innerHTML = html;
            document.querySelectorAll('#table-actions [data-recall]').forEach(r => r.addEventListener('click', () => { closeModal(); loadHeld(Number(r.dataset.recall)); }));
            const openBtn = $('ta-open'); if (openBtn) openBtn.onclick = () => openTable(t);
            const newBtn = $('ta-new'); if (newBtn) newBtn.onclick = () => { closeModal(); startCheckOnSession(t); };
            const closeBtn = $('ta-close'); if (closeBtn) closeBtn.onclick = () => closeEmptyTable(t);
            const tog = $('ta-reserve-toggle'); if (tog) tog.onclick = () => { $('ta-reserve-form').hidden = false; tog.hidden = true; };
            const rsv = $('ta-reserve'); if (rsv) rsv.onclick = () => reserveTable(t);
            const unr = $('ta-unreserve'); if (unr) unr.onclick = () => cancelReservation(t);
        }
        // ---- Reservations: Edge-owned authority (survives config refresh + recovery; fenced on Cloud during Local Mode). ----
        async function reserveTable(t) {
            try {
                const payload = { customer_name: $('rs-name').value.trim() || null, customer_phone: $('rs-phone').value.trim() || null, note: $('rs-note').value.trim() || null };
                const when = $('rs-when').value; if (when) payload.reserved_for = new Date(when).toISOString();
                await api('POST', '/restaurant/tables/' + t.id + '/reserve', payload);
                toast('Table ' + (t.table_no || t.name) + ' reserved' + (payload.customer_name ? ' for ' + payload.customer_name : '') + '.');
                viewTables();
            } catch (e) { const el = $('rs-err'); if (el) el.innerHTML = '<div class="err">' + esc(e.message) + '</div>'; else toast(e.message); }
        }
        async function cancelReservation(t) {
            try { await api('POST', '/restaurant/tables/' + t.id + '/unreserve', {}); toast('Reservation cancelled.'); viewTables(); }
            catch (e) { toast(e.message); }
        }
        async function openTable(t) {
            try {
                const waiter = Number($('ta-waiter').value || 0) || null, guests = Number($('ta-guests').value || 1);
                const s = await api('POST', '/restaurant/tables/' + t.id + '/open', { restaurant_waiter_id: waiter, guest_count: guests });
                closeModal();
                state.held = null; state.cart = []; state.dirty = false;
                state.session = { id: s.session_id, table_id: t.id, table_no: t.table_no || t.name, waiter_name: waiter ? (DATA.waiters.find(w => w.id === waiter) || {}).name : null };
                lockOrderType('dine_in'); renderCart();
                toast('Table ' + (t.table_no || t.name) + ' opened' + (t.reservation?.customer_name ? ' for ' + t.reservation.customer_name : '') + ' — add items, then Hold + KOT.');
                if (t.reservation?.customer_name) $('customer-name').value = t.reservation.customer_name;
            } catch (e) { toast(e.message); }
        }
        function startCheckOnSession(t) {
            state.held = null; state.cart = []; state.dirty = false;
            state.session = { id: t.session.id, table_id: t.id, table_no: t.table_no || t.name, waiter_name: t.session.waiter_name };
            lockOrderType('dine_in'); renderCart();
        }
        async function closeEmptyTable(t) {
            try {
                await api('POST', '/restaurant/table-sessions/' + t.session.id + '/close', { status: 'closed' });
                toast('Table ' + (t.table_no || t.name) + ' closed and freed.');
                if (state.session && state.session.id === t.session.id) { state.session = null; unlockOrderType(); renderCart(); }
                viewTables();
            } catch (e) { toast(e.message); }
        }
