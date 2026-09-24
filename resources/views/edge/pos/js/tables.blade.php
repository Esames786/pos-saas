{{-- W3 js/tables (Team 3 owns): the Table Workspace (Online #tableWorkspaceModal — board / open / held / move / split / merge /
     session detail / reserve / reservation details / manage) rendered into the shared #modal, the session bar (#pos-session-bar),
     the recalled-order bar (#recalled-order-bar) and the per-table Bill Preview document. Online reference:
     tenant/pos/index.blade.php (tableWorkspaceModal, pos-session-bar, reserveTableModal, reservationDetailsModal, billPreviewModal),
     tenant/pos/partials/table-board.blade.php, RestaurantTableSessionController. Every call is an Edge-local route. --}}
        // ---- W3 look (scoped to W3 ids/classes; injected once so this fragment never edits the shared stylesheet). ----
        (function w3Styles() {
            if (document.getElementById('w3-styles')) return;
            const st = document.createElement('style'); st.id = 'w3-styles';
            st.textContent = [
                '.modal .box:has(.w3-wide){width:min(1120px,96vw)}',
                '.tw-head{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.4rem}',
                '.tw-head h2{margin:0}',
                '.w3-filter{display:flex;gap:.3rem;flex-wrap:wrap;margin:.4rem 0}',
                '.w3-filter button.active{background:var(--accent);border-color:var(--accent);color:#fff}',
                '#tableWorkspaceModal .board{grid-template-columns:repeat(auto-fill,minmax(170px,1fr))}',
                '#tableWorkspaceModal .tbl{text-align:left;cursor:default;display:flex;flex-direction:column;gap:.2rem}',
                '.tbl .tt{display:flex;justify-content:space-between;align-items:center;gap:.3rem}',
                '.tbl .st{font-size:.68rem;border-radius:999px;padding:.08rem .45rem;border:1px solid var(--line);text-transform:capitalize}',
                '.tbl.available{border-color:var(--ok)}.tbl.available .st{border-color:var(--ok);color:var(--ok)}',
                '.tbl.occupied{border-color:var(--primary)}.tbl.occupied .st{border-color:var(--primary);color:var(--primary-dark)}',
                '.tbl.bill_requested{border-color:var(--info)}.tbl.bill_requested .st{border-color:var(--info);color:var(--info)}',
                '.tbl.reserved{border-color:var(--navy)}.tbl.reserved .st{border-color:var(--navy);color:var(--navy)}',
                '.tbl.cleaning{border-color:var(--muted)}.tbl.cleaning .st{color:var(--muted)}',
                '.tbl .acts{display:flex;flex-direction:column;gap:.25rem;margin-top:.35rem}',
                '.tbl .acts button{padding:.32rem .5rem;font-size:.78rem}',
                '.tbl .acts .pair{display:flex;gap:.25rem}.tbl .acts .pair button{flex:1}',
                '.waiter-roster{display:flex;flex-wrap:wrap;gap:.4rem}',
                '.waiter-choice{display:flex;align-items:center;gap:.4rem}',
                '.waiter-choice.selected{background:var(--accent);border-color:var(--accent);color:#fff}',
                '.waiter-choice.selected .waiter-initials{background:rgba(255,255,255,.25);color:#fff}',
                '.waiter-initials{display:inline-grid;place-items:center;width:1.7rem;height:1.7rem;border-radius:50%;background:var(--panel);font-size:.72rem;font-weight:700}',
                '#pos-session-bar,#recalled-order-bar{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;padding:.35rem .7rem;border-bottom:1px solid var(--line);font-size:.8rem}',
                '#pos-session-actions{margin-left:auto;display:flex;gap:.3rem;flex-wrap:wrap}',
                '#pos-session-actions button,#recalled-order-bar button{padding:.25rem .55rem;font-size:.76rem}',
                '#recalled-order-bar{background:#fff3cd;border:1px solid #ffc107;border-radius:8px;color:#664d03}',
                '.w3-table{width:100%;border-collapse:collapse;font-size:.82rem}',
                '.w3-table th,.w3-table td{padding:.35rem .3rem;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}',
                '.w3-round{border:1px solid var(--line);border-radius:8px;padding:.4rem .5rem;margin:.35rem 0}',
                '.w3-bill-frame{width:100%;min-height:420px;border:0;background:#fff;border-radius:6px}',
                '.w3-kv{display:grid;grid-template-columns:auto 1fr;gap:.2rem .8rem;font-size:.82rem}',
                '.err{color:var(--danger);font-size:.82rem;margin:.3rem 0}'
            ].join('\n');
            document.head.appendChild(st);
        })();

        // ---- Table Workspace state: the last board read + the sub-view history (Back returns to the board). ----
        const TW = { board: null, view: 'board' };
        const tableLabel = t => (t && (t.table_no || t.name)) || '';
        const statusLabel = s => String(s || '').replace('_', ' ');
        function findTable(id) { for (const f of ((TW.board && TW.board.floors) || [])) for (const t of (f.tables || [])) if (Number(t.id) === Number(id)) return t; return null; }
        function findTableBySession(sid) { for (const f of ((TW.board && TW.board.floors) || [])) for (const t of (f.tables || [])) if (t.session && Number(t.session.id) === Number(sid)) return t; return null; }
        function allTables() { const out = []; ((TW.board && TW.board.floors) || []).forEach(f => (f.tables || []).forEach(t => out.push(Object.assign({ floor_name: f.name }, t)))); return out; }

        // ---- R1 openTableWorkspace (Online tableWorkspaceModal). viewTables is the pre-W3 name — kept for every caller. ----
        function viewTables() { return openTableWorkspace('board'); }
        async function openTableWorkspace(view, arg) {
            if (typeof view !== 'string') view = 'board';   // wired straight to a click → the Event is ignored
            try { TW.board = await api('GET', '/restaurant/board'); } catch (e) { toast(e.message); return; }
            openModal('<div id="tableWorkspaceModal" class="w3-wide">' +
                '<div class="tw-head"><button type="button" class="sm ghost" id="table-workspace-back" hidden>← Tables</button>' +
                '<div style="flex:1"><h2 id="tableWorkspaceModalLabel">Table Workspace</h2><div class="muted" style="font-size:.75rem">Table Board — open, continue and manage active table checks without leaving POS.</div></div>' +
                '<button type="button" class="sm ghost" id="table-workspace-refresh">Refresh</button>' +
                '<button type="button" class="sm ghost" id="table-workspace-manage-btn">Manage Floors / Tables</button>' +
                '<button type="button" class="sm ghost" onclick="EdgePOS.closeModal()">Close</button></div>' +
                '<section id="table-workspace-board"><div id="table-board-body"></div></section>' +
                '<section id="table-workspace-open" hidden></section>' +
                '<section id="table-workspace-held" hidden><div id="table-workspace-held-body"></div></section>' +
                '<section id="table-workspace-move" hidden><div id="table-workspace-move-body"></div></section>' +
                '<section id="table-workspace-split" hidden><div id="table-workspace-split-body"></div></section>' +
                '<section id="table-workspace-merge" hidden><div id="table-workspace-merge-body"></div></section>' +
                '<section id="table-workspace-detail" hidden><div id="table-workspace-detail-body"></div></section>' +
                '<section id="table-workspace-reserve" hidden></section>' +
                '<section id="table-workspace-reservation" hidden></section>' +
                // R32–R35 ACCEPTED ONLINE_REQUIRED: floors / tables / waiters are Cloud configuration.
                '<section id="table-workspace-manage" hidden><p class="muted">Floors, tables and waiters are Cloud configuration. Manage them on the Online POS ' +
                '(Restaurant → Floors / Tables / Waiters); changes reach this Branch Server with the next configuration refresh.</p></section>' +
                '</div>');
            $('table-workspace-back').onclick = () => showTwView('board');
            $('table-workspace-refresh').onclick = () => openTableWorkspace(TW.view === 'board' ? 'board' : TW.view, TW.arg);
            $('table-workspace-manage-btn').onclick = () => showTwView('manage');
            renderBoardBody();
            showTwView(view, arg);
        }
        function showTwView(view, arg) {
            if (!$('tableWorkspaceModal')) return openTableWorkspace(view, arg);
            TW.view = view; TW.arg = arg;
            document.querySelectorAll('#tableWorkspaceModal > section').forEach(s => { s.hidden = s.id !== 'table-workspace-' + view; });
            $('table-workspace-back').hidden = view === 'board';
            const render = { open: renderOpenView, held: renderHeldView, move: renderMoveView, split: renderSplitView, merge: renderMergeView,
                             detail: renderDetailView, reserve: renderReserveView, reservation: renderReservationView }[view];
            if (render) render(arg);
        }

        // ---- R1/R2 board: floor tabs, per-state tiles, direct tile actions (Online table-board partial). ----
        function renderBoardBody() {
            const floors = (TW.board && TW.board.floors) || [];
            let html = '';
            if (floors.length > 1) html += '<div class="w3-filter" id="floor-tab-strip"><button type="button" class="sm active" data-floor-tab="">All Floors</button>' +
                floors.map(f => '<button type="button" class="sm" data-floor-tab="' + f.id + '">' + esc(f.name) + '</button>').join('') + '</div>';
            floors.forEach(f => {
                html += '<div data-floor-panel="' + f.id + '"><h3>' + esc(f.name) + '</h3><div class="board">';
                (f.tables || []).forEach(t => { html += tileHtml(t); });
                html += '</div></div>';
            });
            if (!floors.length) html += '<p class="muted">No active floors/tables found for this branch.</p>';
            html += '<div id="table-actions"></div>';
            const body = $('table-board-body'); body.innerHTML = html;
            body.querySelectorAll('[data-floor-tab]').forEach(b => b.onclick = () => {
                body.querySelectorAll('[data-floor-tab]').forEach(x => x.classList.toggle('active', x === b));
                body.querySelectorAll('[data-floor-panel]').forEach(p => { p.hidden = !!b.dataset.floorTab && p.dataset.floorPanel !== b.dataset.floorTab; });
            });
            body.querySelectorAll('[data-tile-act]').forEach(b => b.onclick = ev => { ev.stopPropagation(); tileAction(b.dataset.tileAct, findTable(b.dataset.tid)); });
        }
        function tileHtml(t) {
            const s = t.session, r = t.reservation;
            const btn = (act, label, cls) => '<button type="button" class="' + (cls || '') + '" data-tile-act="' + act + '" data-tid="' + t.id + '">' + label + '</button>';
            let html = '<div class="tbl ' + esc(t.status) + '" data-table-id="' + t.id + '"><div class="tt"><strong>' + esc(tableLabel(t)) + '</strong><span class="st">' + esc(statusLabel(t.status)) + '</span></div>' +
                '<div class="muted" style="font-size:.74rem">' + (t.capacity ? esc(t.capacity) + ' seats' : '&nbsp;') + '</div>';
            if (s) {
                const held = s.held_orders || [];
                html += '<div style="font-size:.76rem"><div class="muted">Session: ' + esc((s.session_no || '').slice(-8)) + '</div><div>' + esc(s.waiter_name || 'No waiter') + ' · ' + esc(s.guest_count) + ' guests</div>' +
                    '<div>Total: <strong>' + money(s.open_check) + '</strong>' + (held.length > 1 ? ' · ' + held.length + ' checks' : '') + '</div></div><div class="acts">' +
                    btn('continue', 'Continue Table', 'primary') +
                    (s.order_count === 0 ? btn('close', 'Close Table', 'danger') : '') +
                    (held.length ? btn('split', 'Split Bill', 'warn') + btn('held', 'Held Orders') : '') +
                    '<div class="pair">' + btn('move', 'Move') + btn('detail', 'Details') + '</div></div>';
            } else if (r) {
                html += '<div style="font-size:.76rem"><strong>' + esc(r.customer_name || 'Reserved') + '</strong>' + (r.customer_phone ? '<br><span class="muted">' + esc(r.customer_phone) + '</span>' : '') +
                    (r.reserved_for ? '<br><span class="muted">' + esc(w3When(r.reserved_for)) + '</span>' : '') + '</div><div class="acts">' +
                    btn('reservation', 'Details') + btn('open', 'Open Table', 'ok') + btn('unreserve', 'Cancel Reservation', 'danger') + '</div>';
            } else if (t.reservation_details_missing) {
                // R19: reserved on the ONLINE POS before handover — the bootstrap carries only the status, not who/when.
                html += '<div class="muted" style="font-size:.74rem">Reserved on the Online POS — guest details did not reach this Branch Server.</div><div class="acts">' +
                    btn('open', 'Open Table', 'ok') + '</div>';
            } else {
                html += '<div class="acts">' + btn('open', 'Open Table', 'ok') + btn('reserve', 'Reserve', 'ghost') + '</div>';
            }
            return html + '</div>';
        }
        function tileAction(act, t) {
            if (!t) return;
            switch (act) {
                case 'continue': return continueTable(t);
                case 'close': return closeEmptyTable(t);
                case 'split': return tableSplit(t);
                case 'held': return showTwView('held', t);
                case 'move': return showTwView('move', t);
                case 'detail': return showTwView('detail', t);
                case 'open': return showTwView('open', t);
                case 'reserve': return showTwView('reserve', t);
                case 'reservation': return showTwView('reservation', t);
                case 'unreserve': return cancelReservation(t);
            }
        }
        // Pre-W3 entry: "select a tile → its actions" — now the tile's own sub-view.
        function tableActions(t) { if (!t) return; if (t.session) return showTwView('detail', t); if (t.reservation) return showTwView('reservation', t); return showTwView('open', t); }

        // ---- R6 Continue Table: no check → the session bar with an empty (or kept) cart; one check → recall it; several → choose. ----
        async function continueTable(t) {
            const held = (t.session && t.session.held_orders) || [];
            if (!held.length) { closeModal(); startCheckOnSession(t); toast('Table ' + tableLabel(t) + ' — add items, then Hold + KOT.'); return; }
            if (held.length === 1) { closeModal(); await loadHeld(held[0].id).catch(e => toast(e.message)); return; }
            showTwView('held', t);
        }
        function startCheckOnSession(t) {
            const keepCart = !state.held;   // an unsaved cart is carried onto the table (never silently dropped)
            if (!keepCart) state.cart = [];
            state.held = null; state.voidItems = {}; state.dirty = keepCart && state.cart.length > 0;
            state.session = Object.assign({ table_id: t.id, table_no: tableLabel(t) }, t.session || {}, { id: t.session.id, table_id: t.id, table_no: tableLabel(t) });
            lockOrderType('dine_in'); renderCart();
        }

        // ---- R7 Held Orders per table (Online #table-workspace-held): items, time, total → Recall. ----
        function renderHeldView(t) {
            const held = (t && t.session && t.session.held_orders) || [];
            $('table-workspace-held-body').innerHTML = '<h3>Table ' + esc(tableLabel(t)) + ' — open checks</h3>' +
                (held.length ? held.map(h => '<div class="list-row" data-recall="' + h.id + '"><div><strong>Open check ' + esc((h.sale_no || '').slice(-10)) + '</strong>' + (h.is_draft ? ' <span class="chip draft">DRAFT</span>' : '') +
                    '<div class="muted" style="font-size:.74rem">' + esc(h.items_count) + ' items · ' + esc(w3When(h.updated_at)) + '</div></div><div><strong>' + money(h.grand_total) + '</strong> <button class="sm primary">Recall</button></div></div>').join('')
                    : '<p class="muted">No open checks.</p>');
            document.querySelectorAll('#table-workspace-held-body [data-recall]').forEach(r => r.onclick = () => { closeModal(); loadHeld(Number(r.dataset.recall)).catch(e => toast(e.message)); });
        }

        // ---- R3/R4/R18 Open Table (Online #open-table-form: waiter roster, guests, notes; terminal = the POS-selected one). ----
        function renderOpenView(t) {
            const r = t.reservation;
            const initials = n => String(n || '').split(' ').filter(Boolean).map(p => p[0].toUpperCase()).slice(0, 2).join('');
            $('table-workspace-open').innerHTML = '<form id="open-table-form" style="max-width:640px"><h3>Open table <span id="open-table-no">' + esc(tableLabel(t)) + '</span></h3>' +
                (r ? '<div class="chip hot" style="display:inline-block">Reserved</div> <strong>' + esc(r.customer_name || 'Walk-in') + '</strong>' + (r.customer_phone ? ' · ' + esc(r.customer_phone) : '') + ' <span class="muted">— carried onto the check</span>' : '') +
                (t.reservation_details_missing ? '<p class="muted">Reserved on the Online POS — guest details did not reach this Branch Server.</p>' : '') +
                '<div class="field"><label for="restaurant_waiter_id">Waiter</label><select id="restaurant_waiter_id" hidden><option value="">No Waiter</option>' +
                DATA.waiters.map(w => '<option value="' + w.id + '">' + esc(w.name) + '</option>').join('') + '</select>' +
                (DATA.waiters.length ? '<div class="waiter-roster" id="waiter-roster" role="listbox" aria-label="Waiter selection">' +
                    DATA.waiters.map(w => '<button type="button" class="waiter-choice" data-waiter-choice="' + w.id + '" role="option" aria-selected="false"><span class="waiter-initials">' + esc(initials(w.name)) + '</span><span>' + esc(w.name) + '</span></button>').join('') + '</div>'
                    : '<p class="muted">No active waiters are assigned to this branch.</p>') + '</div>' +
                '<div class="field"><label for="guest_count">Guests</label><input id="guest_count" type="number" min="1" max="100" value="1" required></div>' +
                '<div class="field"><label for="table_notes">Notes</label><input id="table_notes" type="text" maxlength="255"></div>' +
                '<div id="open-table-error" hidden></div><div class="btn-row"><button type="button" class="ghost" data-table-workspace-home>Cancel</button><button type="submit" class="primary" id="open-table-submit">' + (r ? 'Open reserved table' : 'Open table') + '</button></div></form>';
            document.querySelectorAll('#waiter-roster [data-waiter-choice]').forEach(b => b.onclick = () => {
                const sel = $('restaurant_waiter_id'), same = sel.value === b.dataset.waiterChoice;
                sel.value = same ? '' : b.dataset.waiterChoice;
                document.querySelectorAll('#waiter-roster .waiter-choice').forEach(x => { const on = !same && x === b; x.classList.toggle('selected', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
            });
            document.querySelector('#open-table-form [data-table-workspace-home]').onclick = () => showTwView('board');
            $('open-table-form').onsubmit = ev => { ev.preventDefault(); openTable(t); };
        }
        async function openTable(t) {
            const guests = Number(($('guest_count') && $('guest_count').value) || 1);
            const waiter = Number(($('restaurant_waiter_id') && $('restaurant_waiter_id').value) || 0) || null;
            const notes = (($('table_notes') && $('table_notes').value) || '').trim();
            if (!(guests >= 1 && guests <= 100)) { openTableError('Guests must be between 1 and 100.'); return; }
            const btn = $('open-table-submit'); if (btn) btn.disabled = true;
            try {
                const body = { restaurant_waiter_id: waiter, guest_count: guests }; if (notes) body.notes = notes;
                const s = await api('POST', '/restaurant/tables/' + t.id + '/open', body);
                closeModal();
                // R21: an unsaved cart is KEPT and continues on the table; a loaded check (other context) is unloaded.
                const keepCart = !state.held;
                if (!keepCart) state.cart = [];
                state.held = null; state.voidItems = {}; state.dirty = keepCart && state.cart.length > 0;
                state.session = Object.assign({ table_id: t.id, table_no: tableLabel(t), waiter_name: waiter ? (DATA.waiters.find(w => w.id === waiter) || {}).name : null, guest_count: guests, status: 'open' },
                    s.session || {}, { id: s.session_id, table_id: t.id, table_no: tableLabel(t) });
                // R18: the reservation's customer rides the check (id + name + phone — the server carries it on the first hold too).
                const r = t.reservation;
                if (r && (r.customer_id || r.customer_name || r.customer_phone)) {
                    state.customer = { id: r.customer_id || null, name: r.customer_name || '', phone: r.customer_phone || null, addresses: [] };
                    $('customer-name').value = r.customer_name || '';
                }
                lockOrderType('dine_in'); renderCart();
                toast('Table ' + tableLabel(t) + ' opened' + (r && r.customer_name ? ' for ' + r.customer_name : '') + ' — add items, then Hold + KOT.');
            } catch (e) { if (btn) btn.disabled = false; openTableError(e.message); toast(e.message); }
        }
        // Online #open-table-error: the refusal shows INLINE in the form (Team 1 showInlineError) and as a toast.
        function openTableError(msg) {
            const el = $('open-table-error'); if (el) el.hidden = !msg;
            if (typeof showInlineError === 'function') showInlineError('open-table-error', msg); else if (el) el.innerHTML = '<div class="err">' + esc(msg) + '</div>';
        }

        // ---- R8/R9 close an EMPTY session as closed (paid) or cancelled (Online close with status). ----
        async function closeEmptyTable(t, status) {
            status = status === 'cancelled' ? 'cancelled' : 'closed';
            if (!confirm((status === 'cancelled' ? 'Cancel the session on table ' : 'Close table ') + tableLabel(t) + '? It has no order on it.')) return;
            try {
                const r = await api('POST', '/restaurant/table-sessions/' + t.session.id + '/close', { status });
                toast(r.message || ('Table ' + tableLabel(t) + ' closed and freed.'));
                if (state.session && state.session.id === t.session.id) { state.session = null; unlockOrderType(); renderCart(); }
                openTableWorkspace('board');
            } catch (e) { toast(e.message); }
        }

        // ---- R12 Move (Online #table-workspace-move: target = available tables). ----
        function renderMoveView(t) {
            const free = allTables().filter(x => !x.session && !x.reservation && !x.reservation_details_missing && ['available', 'cleaning'].includes(x.status) && x.id !== t.id);
            $('table-workspace-move-body').innerHTML = '<h3>Move table ' + esc(tableLabel(t)) + ' to…</h3><p class="muted">The open check(s) move with the table; the kitchen is not told again.</p>' +
                (free.length ? '<div class="board">' + free.map(x => '<button type="button" class="tbl available" data-move-to="' + x.id + '"><strong>' + esc(tableLabel(x)) + '</strong><div class="muted" style="font-size:.72rem">' + esc(x.floor_name) + (x.capacity ? ' · ' + x.capacity + ' seats' : '') + '</div></button>').join('') + '</div>'
                    : '<p class="muted">No available table to move to.</p>') + '<div id="mv-err"></div>';
            document.querySelectorAll('#table-workspace-move-body [data-move-to]').forEach(b => b.onclick = () => moveTable(t, Number(b.dataset.moveTo)));
        }
        async function moveTable(t, targetTableId) {
            // Called with no argument (header / session-bar button) → open the Move view for the current table.
            if (!t || !targetTableId) {
                const sid = t && t.session ? t.session.id : (state.session && state.session.id);
                if (!sid) { toast('Open or recall a table first.'); return; }
                await openTableWorkspace('board'); const tt = findTableBySession(sid); if (tt) showTwView('move', tt); return;
            }
            try {
                const r = await api('POST', '/restaurant/table-sessions/' + t.session.id + '/move', { target_table_id: targetTableId });
                toast(r.message || 'Table moved successfully.');
                if (state.session && Number(state.session.id) === Number(t.session.id)) {
                    Object.assign(state.session, r.session || {});
                    if (state.held) { await loadHeld(state.held.id).catch(() => renderCart()); } else renderCart();
                }
                openTableWorkspace('board');
            } catch (e) { const el = $('mv-err'); if (el) el.innerHTML = '<div class="err">' + esc(e.message) + '</div>'; else toast(e.message); }
        }

        // ---- R13 Merge (Online merge: the source's open checks move to the target session; paid history stays). ----
        function renderMergeView(t) {
            const targets = allTables().filter(x => x.session && x.id !== t.id);
            $('table-workspace-merge-body').innerHTML = '<h3>Merge table ' + esc(tableLabel(t)) + ' into…</h3><p class="muted">The open check(s) of table ' + esc(tableLabel(t)) +
                ' move onto the chosen table\'s session (its waiter); paid history stays; table ' + esc(tableLabel(t)) + ' is freed.</p>' +
                (targets.length ? targets.map(x => '<div class="list-row" data-merge-to="' + x.session.id + '"><span><strong>Table ' + esc(tableLabel(x)) + '</strong> <span class="muted">' + esc(x.session.waiter_name || 'No waiter') + ' · ' + esc(statusLabel(x.status)) + '</span></span><span>' + money(x.session.open_check) + '</span></div>').join('')
                    : '<p class="muted">No other open table to merge into.</p>') + '<div id="mg-err"></div>';
            document.querySelectorAll('#table-workspace-merge-body [data-merge-to]').forEach(b => b.onclick = () => mergeTables(t, Number(b.dataset.mergeTo)));
        }
        async function mergeTables(t, targetSessionId) {
            if (!t || !targetSessionId) {
                const sid = t && t.session ? t.session.id : (state.session && state.session.id);
                if (!sid) { toast('Open or recall a table first.'); return; }
                await openTableWorkspace('board'); const tt = findTableBySession(sid); if (tt) showTwView('merge', tt); return;
            }
            const target = findTableBySession(targetSessionId);
            if (!confirm('Merge table ' + tableLabel(t) + ' into table ' + tableLabel(target) + '?')) return;
            try {
                const r = await api('POST', '/restaurant/table-sessions/' + t.session.id + '/merge', { target_session_id: targetSessionId });
                toast(r.message || 'Table sessions merged successfully.');
                if (state.session && Number(state.session.id) === Number(t.session.id)) {
                    if (state.held) await loadHeld(state.held.id).catch(() => clearCart({ silent: true }));
                    else { state.session = Object.assign({}, r.session); renderCart(); }
                }
                openTableWorkspace('board');
            } catch (e) { const el = $('mg-err'); if (el) el.innerHTML = '<div class="err">' + esc(e.message) + '</div>'; else toast(e.message); }
        }

        // ---- R29/A42 Split from the tile (Online data-table-split: one check → split it; several → pick which). ----
        async function tableSplit(t) {
            const held = (t.session && t.session.held_orders) || [];
            if (held.length === 1) { closeModal(); try { await loadHeld(held[0].id); splitBill(); } catch (e) { toast(e.message); } return; }
            showTwView('split', t);
        }
        function renderSplitView(t) {
            const held = (t.session && t.session.held_orders) || [];
            $('table-workspace-split-body').innerHTML = '<h3>Split Bill — table ' + esc(tableLabel(t)) + '</h3><p class="muted">Choose the check to split.</p>' +
                held.map(h => '<div class="list-row" data-split-sale="' + h.id + '"><span>Open check ' + esc((h.sale_no || '').slice(-10)) + ' · ' + esc(h.items_count) + ' items</span><span>' + money(h.grand_total) + '</span></div>').join('');
            document.querySelectorAll('#table-workspace-split-body [data-split-sale]').forEach(r => r.onclick = async () => { closeModal(); try { await loadHeld(Number(r.dataset.splitSale)); splitBill(); } catch (e) { toast(e.message); } });
        }

        // ---- R20 session detail (Online /restaurant/table-sessions/{s}) + Request Bill / Bill Preview / Move / Merge / Close / Cancel. ----
        async function renderDetailView(t) {
            const box = $('table-workspace-detail-body');
            box.innerHTML = '<p class="muted">Loading…</p>';
            let d;
            try { d = await api('GET', '/restaurant/table-sessions/' + t.session.id); }
            catch (e) { d = { session: Object.assign({ table_no: tableLabel(t) }, t.session), orders: null, error: e.message }; }
            const s = d.session, open = (d.orders || []).filter(o => o.status === 'held');
            const hasOpen = d.orders ? open.length > 0 : ((t.session.held_orders || []).length > 0);
            box.innerHTML = '<h3>Table ' + esc(s.table_no || tableLabel(t)) + ' — session</h3><div class="w3-kv">' +
                '<span class="muted">Session</span><span>' + esc(s.session_no) + '</span><span class="muted">Status</span><span>' + esc(statusLabel(s.status)) + '</span>' +
                '<span class="muted">Waiter</span><span>' + esc(s.waiter_name || 'No waiter') + '</span><span class="muted">Guests</span><span>' + esc(s.guest_count) + '</span>' +
                (s.opened_at ? '<span class="muted">Opened</span><span>' + esc(w3When(s.opened_at)) + (s.opened_by ? ' by ' + esc(s.opened_by) : '') + '</span>' : '') +
                (s.notes ? '<span class="muted">Notes</span><span>' + esc(s.notes) + '</span>' : '') +
                '<span class="muted">Open check</span><span><strong>' + money(s.open_check) + '</strong></span></div>' +
                (d.error ? '<p class="muted">' + esc(d.error) + '</p>' : '') +
                (d.orders ? '<table class="w3-table" style="margin-top:.5rem"><thead><tr><th>Order</th><th>Status</th><th>Items</th><th>Total</th></tr></thead><tbody>' +
                    (d.orders.length ? d.orders.map(o => '<tr><td>' + esc((o.sale_no || '').slice(-10)) + (o.is_draft ? ' <span class="chip draft">DRAFT</span>' : '') + '</td><td>' + esc(o.status) + '</td><td class="muted" style="font-size:.74rem">' +
                        o.items.map(i => esc(i.quantity) + ' × ' + esc(i.name)).join(', ') + '</td><td>' + money(o.grand_total) + '</td></tr>').join('') : '<tr><td colspan="4" class="muted">No orders yet.</td></tr>') + '</tbody></table>' : '') +
                '<div class="btn-row left">' +
                '<button type="button" class="primary" id="sd-continue">Continue Table</button>' +
                '<button type="button" id="sd-bill-preview">Bill Preview</button>' +
                (s.status === 'open' ? '<button type="button" class="warn" id="sd-request-bill">Request Bill</button>' : '') +
                '<button type="button" id="sd-move">Move</button><button type="button" id="sd-merge"' + (hasOpen ? '' : ' disabled title="Only a table with an open check can be merged"') + '>Merge</button>' +
                (!hasOpen ? '<button type="button" class="danger" id="sd-close">Close Table</button><button type="button" class="ghost" id="sd-cancel">Cancel session</button>' : '') + '</div>';
            $('sd-continue').onclick = () => continueTable(t);
            $('sd-bill-preview').onclick = () => openTableBillPreview(t.session.id);
            const rb = $('sd-request-bill'); if (rb) rb.onclick = () => requestBill(t.session.id);
            $('sd-move').onclick = () => showTwView('move', t);
            $('sd-merge').onclick = () => showTwView('merge', t);
            const c1 = $('sd-close'); if (c1) c1.onclick = () => closeEmptyTable(t, 'closed');
            const c2 = $('sd-cancel'); if (c2) c2.onclick = () => closeEmptyTable(t, 'cancelled');
        }

        // ---- R11 Request Bill (Online bill-requested: marks the table "Bill Requested"; charges nothing). ----
        async function requestBill(sessionId) {
            const sid = (typeof sessionId === 'number' || typeof sessionId === 'string') && sessionId !== '' ? Number(sessionId) : (state.session && state.session.id);
            if (!sid) { toast('Open or recall a table first.'); return; }
            try {
                const r = await api('POST', '/restaurant/table-sessions/' + sid + '/bill-requested', {});
                toast(r.message || 'Bill requested.');
                if (state.session && Number(state.session.id) === sid) { Object.assign(state.session, r.session || { status: 'bill_requested' }); renderCart(); }
                if ($('tableWorkspaceModal')) openTableWorkspace('board');
            } catch (e) { toast(e.message); }
        }

        // ---- R14 per-table Bill Preview document: rounds + OPEN CHECK + previously paid (+ the receipt document). Print = Team 5. ----
        async function openTableBillPreview(sessionId) {
            const sid = (typeof sessionId === 'number' || typeof sessionId === 'string') && sessionId !== '' ? Number(sessionId) : (state.session && state.session.id);
            if (!sid) { toast('Open or recall a table first.'); return; }
            let p;
            try { p = await api('GET', '/restaurant/table-sessions/' + sid + '/bill-preview'); } catch (e) { toast(e.message); return; }
            const s = p.session, t = p.totals || {};
            const r = (k, v) => '<div class="row"><span>' + k + '</span><span>' + money(v) + '</span></div>';
            const rounds = (p.rounds || []).map((rd, i) => '<div class="w3-round"><div class="muted" style="font-size:.74rem">Round ' + (i + 1) + ' · ' + esc((rd.sale_no || '').slice(-10)) + (rd.is_draft ? ' · DRAFT' : '') + '</div>' +
                rd.lines.filter(l => l.line_kind !== 'component').map(l => '<div class="row" style="display:flex;justify-content:space-between"><span>' + esc(l.quantity) + ' × ' + esc(l.name) + (l.variant_name ? ' (' + esc(l.variant_name) + ')' : '') + '</span><span>' + money(l.line_total) + '</span></div>' +
                    rd.lines.filter(c => c.parent_line_id === l.id).map(c => '<div class="muted" style="font-size:.72rem;padding-left:1rem">' + esc(c.quantity) + ' × ' + esc(c.name) + '</div>').join('')).join('') + '</div>').join('');
            const paid = (p.previously_paid || []).length ? '<h3>Previously paid</h3>' + p.previously_paid.map(x => '<div class="row" style="display:flex;justify-content:space-between"><span>' + esc((x.sale_no || '').slice(-10)) + (x.methods && x.methods.length ? ' · ' + esc(x.methods.join(', ')) : '') + '</span><span>' + money(x.grand_total) + '</span></div>').join('') +
                '<div class="row" style="display:flex;justify-content:space-between"><span class="muted">Previously paid total</span><span>' + money(p.previously_paid_total) + '</span></div>' : '';
            openModal('<div id="table-bill-preview" class="w3-wide"><h2>Bill Preview — Table ' + esc(s.table_no || '') + '</h2>' +
                '<p class="muted">' + esc(s.session_no) + ' · ' + esc(s.waiter_name || 'No waiter') + ' · ' + esc(s.guest_count) + ' guests. Preview — not a tax receipt; nothing is charged.</p>' +
                (rounds || '<p class="muted">No open check on this table.</p>') +
                '<div class="totals">' + r('Subtotal', t.subtotal) + (Number(t.discount_amount) ? r('Discount', -t.discount_amount) : '') + (Number(t.tax_amount) ? r('Tax', t.tax_amount) : '') +
                (Number(t.service_charge_amount) ? r('Service charge', t.service_charge_amount) : '') + '<div class="row grand"><span>OPEN CHECK</span><span>' + money(t.grand_total) + '</span></div></div>' + paid +
                (p.html ? '<details style="margin-top:.5rem"><summary class="muted">Receipt document</summary><iframe class="w3-bill-frame" id="table-bill-preview-frame" title="Table bill document"></iframe></details>' : '') +
                '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button>' +
                ((s.status === 'open') ? '<button class="warn" id="table-bill-request-btn">Request Bill</button>' : '') +
                ((p.held_sale_ids || []).length ? '<button class="ghost" id="table-bill-network-btn" title="Send this table\'s open check(s) to the counter\'s network receipt printer.">Send to network</button>' : '') +
                '<button class="primary" id="table-bill-print-btn"' + (p.html || typeof printBillPreview === 'function' ? '' : ' disabled title="The receipt document could not be rendered"') + '>Print here</button></div></div>');
            const f = $('table-bill-preview-frame'); if (f) f.srcdoc = p.html;
            const rq = $('table-bill-request-btn'); if (rq) rq.onclick = () => { closeModal(); requestBill(s.id); };
            // Print here = the table bill document itself (the receipt layout over ALL open rounds of this session).
            // T3-5 (R14/D-10): Team 5's printBillPreview with the TABLE payload — its Print here / Send to network flow, the
            // print target = this session's held checks. The own-iframe print stays only as a fallback.
            const tablePayload = target => ({ restaurant_table_session_id: s.id, held_sale_ids: p.held_sale_ids || [], target });
            $('table-bill-print-btn').onclick = () => {
                if (typeof printBillPreview === 'function') { printBillPreview(tablePayload('here')); return; }
                const fr = $('table-bill-preview-frame');
                if (fr && fr.contentWindow) { fr.closest('details').open = true; try { fr.contentWindow.focus(); fr.contentWindow.print(); } catch (e) { toast('The print dialog was blocked.'); } }
            };
            // BILL-PREVIEW-WRONG-PRINT-1: the network target is THIS table's held checks (p.held_sale_ids) — never the cart, never a
            // previous sale. One saved check → Team 5's printBillPreview({sale_id}); several → each check's bill via the receipt route.
            const nb = $('table-bill-network-btn');
            if (nb) nb.onclick = async () => {
                const ids = p.held_sale_ids || [];
                if (typeof printBillPreview === 'function') { printBillPreview(tablePayload('network')); return; }
                for (const id of ids) {
                    try { const j = await api('POST', '/sales/' + id + '/receipt', { reprint: true }); toast(j.fallback ? 'No network receipt printer mapped — use Print here.' : 'Bill sent → ' + (j.printer_name || 'network printer')); }
                    catch (e) { toast('Could not send to the network printer: ' + e.message); }
                }
            };
        }

        // ---- R15/R16/R17 reservations (Edge-owned; survive config refresh + recovery; fenced on Cloud during Local Mode). ----
        function renderReserveView(t) {
            $('table-workspace-reserve').innerHTML = '<div id="reserveTableModal" style="max-width:640px"><h3>Reserve table <span id="reserve-table-no">' + esc(tableLabel(t)) + '</span></h3>' +
                '<input type="hidden" id="reserve-table-id" value="' + t.id + '"><input type="hidden" id="reserve-customer-id" value="">' +
                '<div class="field"><label>Customer book (optional)</label><input type="search" id="reserve-customer-search" placeholder="Search name / phone…">' +
                '<div id="reserve-customer-suggest"></div><div id="reserve-customer-chip" hidden><span class="chip hot">Attached: <span id="reserve-customer-name"></span></span> <button type="button" class="sm ghost" id="reserve-customer-clear">Remove</button></div></div>' +
                '<div class="field"><label>Name (blank = walk-in)</label><input type="text" id="reserve-name" maxlength="190" placeholder="Name"></div>' +
                '<div class="field"><label>Phone</label><input type="text" id="reserve-phone" maxlength="40"></div>' +
                '<div class="field"><label>Reserved for</label><input type="datetime-local" id="reserve-for"></div>' +
                '<div class="field"><label>Note</label><input type="text" id="reserve-note" maxlength="1000" placeholder="e.g. birthday, window seat"></div>' +
                '<div id="reserve-toast" hidden></div><div class="btn-row"><button type="button" class="ghost" data-table-workspace-home>Cancel</button><button type="button" class="primary" id="reserve-save-btn">Reserve</button></div></div>';
            document.querySelector('#reserveTableModal [data-table-workspace-home]').onclick = () => showTwView('board');
            let timer = null;
            const attach = cu => {
                $('reserve-customer-id').value = cu ? cu.id : ''; $('reserve-customer-chip').hidden = !cu; $('reserve-customer-name').textContent = cu ? cu.name + (cu.phone ? ' · ' + cu.phone : '') : '';
                $('reserve-customer-suggest').innerHTML = ''; if (cu) { $('reserve-customer-search').value = ''; }
            };
            $('reserve-customer-clear').onclick = () => attach(null);
            $('reserve-customer-search').oninput = e => {
                clearTimeout(timer); const q = e.target.value.trim(); if (q.length < 2) { $('reserve-customer-suggest').innerHTML = ''; return; }
                timer = setTimeout(async () => {
                    try {
                        const r = await api('GET', '/customers?q=' + encodeURIComponent(q));
                        $('reserve-customer-suggest').innerHTML = r.customers.map(cu => '<div class="list-row" data-rcid="' + cu.id + '"><span>' + esc(cu.name) + '</span><span class="muted">' + esc(cu.phone || '') + '</span></div>').join('') ||
                            '<div class="muted">No customer found in the book — type the name / phone below (walk-in).</div>';
                        document.querySelectorAll('#reserve-customer-suggest [data-rcid]').forEach(row => row.onclick = () => attach(r.customers.find(cu => cu.id === Number(row.dataset.rcid))));
                    } catch (err) { toast(err.message); }
                }, 250);
            };
            $('reserve-save-btn').onclick = () => reserveTable(t);
        }
        async function reserveTable(t) {
            try {
                const cid = Number(($('reserve-customer-id') && $('reserve-customer-id').value) || 0) || null;
                const payload = { customer_id: cid, customer_name: $('reserve-name').value.trim() || null, customer_phone: $('reserve-phone').value.trim() || null, note: $('reserve-note').value.trim() || null };
                const when = $('reserve-for').value; if (when) payload.reserved_for = new Date(when).toISOString();
                const r = await api('POST', '/restaurant/tables/' + t.id + '/reserve', payload);
                const msg = 'Table ' + tableLabel(t) + ' reserved' + (r.customer_name ? ' for ' + r.customer_name : '') + '.';
                reserveNotice(msg, 'success'); toast(msg);
                setTimeout(() => openTableWorkspace('board'), 700);
            } catch (e) { reserveNotice(e.message, 'danger'); }
        }
        // Online #reserve-toast: the reserve result shows INLINE in the reserve dialog (Team 1 showInlineToast).
        function reserveNotice(msg, level) {
            const el = $('reserve-toast'); if (el) el.hidden = !msg;
            if (typeof showInlineToast === 'function') showInlineToast('reserve-toast', msg, level); else if (el) el.innerHTML = '<div class="err">' + esc(msg) + '</div>'; else toast(msg);
        }
        function renderReservationView(t) {
            const r = t.reservation || {};
            $('table-workspace-reservation').innerHTML = '<div id="reservationDetailsModal"><h3>Reserved — table ' + esc(tableLabel(t)) + '</h3><div class="w3-kv" id="reservation-details-body">' +
                '<span class="muted">Name</span><span>' + esc(r.customer_name || 'Walk-in') + (r.customer_id ? ' <span class="chip">customer book</span>' : '') + '</span>' +
                '<span class="muted">Phone</span><span>' + esc(r.customer_phone || '—') + '</span>' +
                '<span class="muted">Reserved for</span><span>' + esc(r.reserved_for ? w3When(r.reserved_for) : 'no time set') + '</span>' +
                '<span class="muted">Note</span><span>' + esc(r.note || '—') + '</span>' +
                '<span class="muted">Reserved by</span><span>' + esc(r.reserved_by || '—') + '</span>' +
                '<span class="muted">Marked at</span><span>' + esc(r.reserved_at ? w3When(r.reserved_at) : '—') + '</span></div>' +
                '<div class="btn-row left"><button type="button" class="ok" id="rd-open">Open Table</button><button type="button" class="danger" id="ta-unreserve">Cancel reservation</button></div></div>';
            $('rd-open').onclick = () => showTwView('open', t);
            $('ta-unreserve').onclick = () => cancelReservation(t);
        }
        async function cancelReservation(t) {
            if (!confirm('Cancel the reservation on table ' + tableLabel(t) + '?')) return;
            try { await api('POST', '/restaurant/tables/' + t.id + '/unreserve', {}); toast('Reservation cancelled.'); openTableWorkspace('board'); }
            catch (e) { toast(e.message); }
        }

        // ---- R10 session bar (Online #pos-session-bar) + A38 recalled-order bar (Online #recalled-order-bar). ----
        // Team 1 may lay these out in the page shell; when the ids are absent they are created under the cart head.
        function w3CartBar(id, html) {
            let el = document.getElementById(id);
            if (!el) {
                el = document.createElement('div'); el.id = id; el.innerHTML = html; el.hidden = true;
                const head = document.querySelector('.cart-pane .cart-head');
                if (head) head.insertAdjacentElement('afterend', el); else document.body.appendChild(el);
            }
            return el;
        }
        function renderSessionBar() {
            const bar = w3CartBar('pos-session-bar',
                '<div id="pos-session-details"><strong>Table <span id="pos-session-table-no"></span></strong> <span class="muted" id="pos-session-no"></span> · <span id="pos-session-waiter"></span> · ' +
                '<span id="pos-session-guests"></span> guests · Open check <strong id="pos-session-open-check"></strong> <span class="chip" id="pos-session-status" hidden>Bill requested</span></div>' +
                '<div id="pos-session-actions"><button type="button" id="pos-session-bill-preview">Bill Preview</button>' +
                '<form id="pos-session-request-bill-form" style="margin:0;display:inline"><button type="submit" class="warn" title="Signal that the guest wants their bill. Marks this table as \'Bill Requested\' — it does not charge anything.">Request Bill</button></form>' +
                '<button type="button" id="pos-session-move-btn">Move</button><button type="button" id="pos-session-merge-btn">Merge</button></div>');
            if (!bar.dataset.w3Wired) {
                bar.dataset.w3Wired = '1';
                const bp = $('pos-session-bill-preview'); if (bp) bp.addEventListener('click', () => openTableBillPreview());
                const rf = $('pos-session-request-bill-form'); if (rf) rf.addEventListener('submit', ev => { ev.preventDefault(); requestBill(); });
                const mv = $('pos-session-move-btn'); if (mv) mv.addEventListener('click', () => moveTable());
                const mg = $('pos-session-merge-btn'); if (mg) mg.addEventListener('click', () => mergeTables());
            }
            const s = state.session;
            bar.hidden = !s; bar.style.display = s ? '' : 'none';
            if (!s) return;
            const set = (id, v) => { const el = $(id); if (el) el.textContent = v; };
            const openCheck = state.held && state.dirty ? null : s.open_check;
            set('pos-session-table-no', s.table_no || '');
            set('pos-session-no', s.session_no ? s.session_no.slice(-8) : '');
            set('pos-session-waiter', s.waiter_name || 'No waiter');
            set('pos-session-guests', s.guest_count ?? '—');
            set('pos-session-open-check', openCheck == null ? (state.held ? money(state.held.grand_total) : money(0)) : money(openCheck));
            const st = $('pos-session-status'); if (st) st.hidden = s.status !== 'bill_requested';
            const rf = $('pos-session-request-bill-form'); if (rf) rf.style.display = (s.status || 'open') === 'open' ? '' : 'none';
            const details = $('pos-session-details'); if (details) details.classList.remove('d-none');
            const acts = $('pos-session-actions'); if (acts) acts.classList.remove('d-none');
        }
        function renderRecalledBar() {
            const bar = w3CartBar('recalled-order-bar', '<span>Recalled: <strong id="recalled-order-no">—</strong> <span class="chip draft" id="pos-draft-badge" hidden>DRAFT</span></span>' +
                '<span style="margin-left:auto"><button type="button" class="warn" id="edit-order-btn" disabled>Edit Order</button></span>');
            if (!bar.dataset.w3Wired) { bar.dataset.w3Wired = '1'; const eb = $('edit-order-btn'); if (eb && !eb.dataset.w3Wired) { eb.dataset.w3Wired = '1'; eb.addEventListener('click', () => openChangeOrder()); } }
            const h = state.held;
            bar.hidden = !h; bar.style.display = h ? '' : 'none';
            const no = $('recalled-order-no'); if (no) no.textContent = h ? (h.sale_no || ('#' + h.id)).slice(-10) : '—';
            const badge = $('pos-draft-badge'); if (badge) { badge.hidden = !(h && h.is_draft); badge.style.display = h && h.is_draft ? '' : 'none'; }
            const eb = $('edit-order-btn'); if (eb) eb.disabled = !h;
        }
