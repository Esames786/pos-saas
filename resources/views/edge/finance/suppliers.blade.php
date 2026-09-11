{{--
  OFFLINE EDGE — F2 SUPPLIER FINANCE: Suppliers → Supplier Ledger → Record Payment on the Branch Server.

  The current Online Bingoo POS is the specification: the Supplier Ledger (Date / Type / Reference / Description /
  Debit / Credit / Balance / User) with "Record Payment", and the Record Supplier Payment form (Supplier, Against Bill
  OPTIONAL, Branch, Payment Date, Pay From Cash/Bank REQUIRED, Payment Method, Amount, Reference No, bank/cheque
  details, Notes). Offline the page shows the Cloud's OFFICIAL position from the warm projection adjusted by this
  branch server's PENDING SYNC events, and never offers a usable "no cash/bank effect" option.

  Self-contained (inline CSS/JS, no build assets) so it renders with NO Internet. Every mutation targets the
  Edge-local JSON API (edge.local.pos.suppliers.*); the Cloud posts the official AP / GL / cash-bank later, exactly once.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Bingoo Edge — Suppliers</title>
    <style>
        :root { --bg:#0f172a; --panel:#1e293b; --panel2:#172033; --line:#334155; --ink:#e2e8f0; --muted:#94a3b8; --accent:#4f46e5; --ok:#16a34a; --warn:#d97706; --danger:#dc2626; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:system-ui,Segoe UI,sans-serif; background:var(--bg); color:var(--ink); height:100vh; display:flex; flex-direction:column; }
        header { background:var(--panel); border-bottom:1px solid var(--line); padding:.5rem .9rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
        header h1 { font-size:1.05rem; margin:0; }
        header .who { color:var(--muted); font-size:.8rem; }
        header .spacer { flex:1; }
        a.navbtn, button { font:inherit; color:var(--ink); cursor:pointer; border:1px solid var(--line); background:var(--panel2); border-radius:8px; padding:.5rem .8rem; text-decoration:none; font-size:.9rem; }
        button.primary { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; }
        button.ghost, a.navbtn { background:transparent; }
        button.sm { padding:.3rem .6rem; font-size:.8rem; }
        button:disabled { opacity:.5; cursor:not-allowed; }
        select, input, textarea { font:inherit; color:var(--ink); background:var(--panel2); border:1px solid var(--line); border-radius:8px; padding:.45rem .6rem; width:100%; }
        .chip { font-size:.78rem; background:var(--panel); border:1px solid var(--line); border-radius:999px; padding:.3rem .6rem; color:var(--muted); }
        .chip.fresh { border-color:var(--ok); color:#86efac; }
        .chip.stale { border-color:var(--danger); color:#fca5a5; }
        main { flex:1; display:grid; grid-template-columns: 360px 1fr; min-height:0; }
        .pane { display:flex; flex-direction:column; min-height:0; }
        .list-pane { border-right:1px solid var(--line); }
        .toolbar { padding:.6rem .7rem; border-bottom:1px solid var(--line); display:flex; gap:.5rem; }
        .scroll { flex:1; overflow-y:auto; }
        table { width:100%; border-collapse:collapse; font-size:.85rem; }
        th, td { padding:.45rem .55rem; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
        th { color:var(--muted); font-weight:500; font-size:.75rem; text-transform:uppercase; letter-spacing:.03em; position:sticky; top:0; background:var(--bg); }
        td.num, th.num { text-align:right; white-space:nowrap; }
        tr.sup { cursor:pointer; }
        tr.sup:hover, tr.sup.active { background:var(--panel2); }
        tr.pending td { background:rgba(217,119,6,.12); }
        .badge { display:inline-block; font-size:.7rem; border-radius:999px; padding:.15rem .5rem; border:1px solid var(--line); color:var(--muted); white-space:nowrap; }
        .badge.pending { border-color:var(--warn); color:#fcd34d; }
        .badge.synced { border-color:var(--ok); color:#86efac; }
        .badge.failed { border-color:var(--danger); color:#fca5a5; }
        .badge.edge { border-color:var(--accent); color:#c7d2fe; }
        .ledger-head { padding:.8rem .9rem; border-bottom:1px solid var(--line); display:flex; gap:1.2rem; align-items:flex-end; flex-wrap:wrap; }
        .ledger-head h2 { margin:0; font-size:1.1rem; }
        .kv { color:var(--muted); font-size:.8rem; }
        .kv strong { color:var(--ink); font-size:1rem; display:block; }
        .empty { color:var(--muted); padding:2rem; text-align:center; }
        .modal { position:fixed; inset:0; background:rgba(2,6,23,.75); display:flex; align-items:center; justify-content:center; padding:1rem; }
        .modal .box { background:var(--panel); border:1px solid var(--line); border-radius:12px; width:min(860px, 100%); max-height:92vh; overflow-y:auto; padding:1rem 1.1rem; }
        .box h3 { margin:0 0 .3rem; }
        .form-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:.6rem .8rem; margin-top:.8rem; }
        .form-grid .wide { grid-column: span 3; }
        label { display:block; font-size:.75rem; color:var(--muted); margin-bottom:.2rem; }
        label.required::after { content:' *'; color:#fca5a5; }
        .help { color:var(--muted); font-size:.75rem; margin-top:.2rem; }
        .btn-row { display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem; }
        .banner { padding:.5rem .9rem; font-size:.85rem; border-bottom:1px solid var(--line); }
        .banner.warn { background:#3b2a12; color:#fcd34d; }
        .banner.err { background:#3b1212; color:#fca5a5; }
        .result dl { display:grid; grid-template-columns:auto 1fr; gap:.3rem .8rem; font-size:.9rem; }
        .result dt { color:var(--muted); }
        #toast { position:fixed; bottom:1rem; left:50%; transform:translateX(-50%); background:var(--panel); border:1px solid var(--danger); color:#fca5a5; padding:.6rem 1rem; border-radius:10px; max-width:min(720px, 92vw); font-size:.9rem; }
    </style>
</head>
<body>
    <header>
        <h1>Suppliers</h1>
        <span class="who">{{ $branchName }} · {{ $userName }}</span>
        <span class="spacer"></span>
        <span class="chip" id="fresh-chip">loading…</span>
        <span class="chip" id="mode-chip" hidden></span>
        <a class="navbtn" href="{{ url('/edge/local/pos') }}">POS</a>
        @if($canJournal)
            <a class="navbtn" id="journal-link" href="{{ url('/edge/local/pos/finance/journal') }}">General Journal</a>
        @endif
        <form method="POST" action="{{ url('/edge/local/logout') }}" style="margin:0">@csrf<button class="ghost">Logout</button></form>
    </header>
    <div class="banner warn" id="stale-banner" hidden>Supplier finance information is not current on this branch server — payments and journals are refused until the Cloud position is refreshed (or record them on the Online POS).</div>
    <div class="banner err" id="mode-banner" hidden>The Cloud is the branch writer right now — supplier finance is recorded on the Online POS. This page is read-only until the branch server takes over.</div>

    <main>
        <section class="pane list-pane">
            <div class="toolbar"><input type="search" id="search" placeholder="Search supplier code or name…" autocomplete="off"></div>
            <div class="scroll">
                <table id="suppliers">
                    <thead><tr><th>Supplier</th><th class="num">Payable (Cloud)</th><th class="num">Pending</th><th class="num">Available</th></tr></thead>
                    <tbody id="suppliers-body"></tbody>
                </table>
            </div>
        </section>
        <section class="pane">
            <div class="empty" id="ledger-empty">Select a supplier to open the Supplier Ledger.</div>
            <div id="ledger" hidden style="display:flex;flex-direction:column;min-height:0;flex:1">
                <div class="ledger-head">
                    <div>
                        <h2 id="lg-name">—</h2>
                        <div class="kv" id="lg-code"></div>
                    </div>
                    <div class="kv">Current Payable (Cloud official)<strong id="lg-cloud">0.00</strong></div>
                    <div class="kv">Pending on this branch server<strong id="lg-pending">0.00</strong></div>
                    <div class="kv">Available payable<strong id="lg-available">0.00</strong></div>
                    <div class="kv">Open bills<strong id="lg-bills">0</strong></div>
                    <span class="spacer"></span>
                    <button class="primary" id="pay-btn" @unless($canPay) disabled title="You are not allowed to record supplier payments" @endunless>Record Payment</button>
                </div>
                <div class="scroll">
                    <table id="ledger-table">
                        <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Description</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th><th>User / Status</th></tr></thead>
                        <tbody id="ledger-body"></tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <div class="modal" id="pay-modal" hidden>
        <form class="box" id="pay-form" autocomplete="off">
            <h3>Record Supplier Payment</h3>
            <div class="help">A purchase bill is <strong>not</strong> required — a supplier can be paid straight against their outstanding balance. A Cash/Bank account <strong>is</strong> required: the payment always moves cash or bank (Dr Accounts Payable / Cr Cash/Bank at the Cloud).</div>
            <div class="form-grid">
                <div><label class="required">Supplier</label><input id="pay-supplier" readonly></div>
                <div><label>Against Bill <span style="font-weight:normal">(optional)</span></label>
                    <select id="pay-bill" name="cloud_bill_id"><option value="">No specific bill (general payment)</option></select></div>
                <div><label class="required">Branch</label><input id="pay-branch" readonly value="{{ $branchName }}"></div>
                <div><label class="required">Payment Date</label><input type="date" id="pay-date" name="payment_date" required></div>
                <div><label class="required">Pay From (Cash/Bank)</label>
                    <select id="pay-cb" name="cloud_cash_bank_account_id" required><option value="" disabled selected>— Select the Cash/Bank account (required) —</option></select>
                    <div class="help">No "none / no cash/bank effect" option exists: a ledger-only supplier payment is not possible.</div></div>
                <div><label class="required">Payment Method</label><select id="pay-method" name="payment_method" required></select></div>
                <div><label class="required">Amount</label><input type="number" step="0.01" min="0.01" id="pay-amount" name="amount" required>
                    <div class="help" id="pay-available"></div></div>
                <div><label>Reference No</label><input id="pay-ref" name="reference_no" maxlength="100"></div>
                <div><label>Bank Name</label><input id="pay-bank" name="bank_name" maxlength="100"></div>
                <div><label>Account No</label><input id="pay-account" name="account_no" maxlength="100"></div>
                <div><label>Transaction Ref</label><input id="pay-txn" name="transaction_ref" maxlength="100"></div>
                <div><label>Cheque No</label><input id="pay-cheque" name="cheque_no" maxlength="100"></div>
                <div><label>Cheque Date</label><input type="date" id="pay-cheque-date" name="cheque_date"></div>
                <div class="wide"><label>Notes</label><textarea id="pay-notes" name="notes" rows="2" maxlength="1000"></textarea></div>
            </div>
            <div class="help" id="pay-error" style="color:#fca5a5"></div>
            <div class="btn-row"><button type="button" class="ghost" id="pay-cancel">Cancel</button><button type="submit" class="primary" id="pay-submit">Post Payment (pending sync)</button></div>
        </form>
    </div>

    <div class="modal" id="result-modal" hidden>
        <div class="box result">
            <h3>Supplier payment recorded on this branch server</h3>
            <dl id="result-dl"></dl>
            <div class="btn-row"><button type="button" class="primary" id="result-close">Done</button></div>
        </div>
    </div>
    <div id="toast" hidden></div>

    <script>
    (function () {
        const CSRF = document.querySelector('meta[name=csrf-token]').content;
        const BASE = '/edge/local/pos';
        const $ = (id) => document.getElementById(id);
        const fmt = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        const canPay = @json((bool) $canPay);

        let options = null;
        let current = null;   // cloud_supplier_id
        let ledger = null;
        let toastTimer = null;

        function toast(msg) {
            const t = $('toast');
            t.textContent = msg;
            t.hidden = false;
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => { t.hidden = true; }, 6000);
        }

        async function api(method, path, body) {
            const res = await fetch(BASE + path, {
                method: method,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                body: body ? JSON.stringify(body) : undefined,
            });
            let data = null;
            try { data = await res.json(); } catch (e) { data = null; }
            if (!res.ok) {
                const err = new Error((data && data.message) || ('HTTP ' + res.status));
                err.status = res.status;
                err.data = data;
                throw err;
            }
            return data;
        }

        function renderStatus() {
            const chip = $('fresh-chip');
            const f = options.freshness || {};
            chip.className = 'chip ' + (f.ok ? 'fresh' : 'stale');
            chip.textContent = f.ok ? ('Cloud position current · as of ' + (f.as_of ? new Date(f.as_of).toLocaleString() : '—')) : 'Cloud position NOT current';
            chip.title = f.ok ? '' : (f.reasons || []).join('; ');
            $('stale-banner').hidden = !!f.ok;
            const mode = $('mode-chip');
            mode.hidden = false;
            mode.textContent = options.local_mode ? 'Branch server is the writer' : 'Cloud is the writer';
            $('mode-banner').hidden = !!options.local_mode;
        }

        function renderSuppliers(filter) {
            const q = (filter || '').trim().toLowerCase();
            const body = $('suppliers-body');
            body.innerHTML = '';
            (options.suppliers || []).filter((s) => !q || s.name.toLowerCase().includes(q) || s.code.toLowerCase().includes(q)).forEach((s) => {
                const tr = document.createElement('tr');
                tr.className = 'sup' + (current === s.cloud_supplier_id ? ' active' : '');
                tr.dataset.id = s.cloud_supplier_id;
                tr.innerHTML = '<td><div>' + esc(s.name) + '</div><div class="kv">' + esc(s.code) + (s.status !== 'active' ? ' · <span class="badge">inactive</span>' : '') + '</div></td>'
                    + '<td class="num">' + fmt(s.cloud_payable) + '</td>'
                    + '<td class="num">' + (s.pending_events > 0 ? '<span class="badge pending">' + s.pending_events + ' pending</span><div>' + fmt(s.pending_delta) + '</div>' : '—') + '</td>'
                    + '<td class="num"><strong>' + fmt(s.available_payable) + '</strong></td>';
                tr.addEventListener('click', () => openLedger(s.cloud_supplier_id));
                body.appendChild(tr);
            });
        }

        async function openLedger(id) {
            current = id;
            try {
                ledger = await api('GET', '/suppliers/' + id + '/ledger');
            } catch (e) {
                toast(e.message);
                return;
            }
            renderSuppliers($('search').value);
            renderLedger();
        }

        function syncBadge(sync) {
            if (!sync) { return ''; }
            const cls = sync.state === 'pending' ? 'pending' : (sync.state === 'synced' || sync.state === 'official' ? 'synced' : 'failed');
            const text = sync.state === 'pending' ? 'PENDING SYNC' : (sync.state === 'synced' ? 'POSTED AT CLOUD · awaiting ledger refresh' : (sync.state === 'official' ? 'OFFICIAL' : (sync.state === 'failed' ? 'REFUSED BY CLOUD' : 'NOT QUEUED')));
            return '<span class="badge ' + cls + '" title="' + esc(sync.label) + '">' + text + '</span>';
        }

        function renderLedger() {
            const s = ledger.supplier;
            $('ledger-empty').hidden = true;
            $('ledger').hidden = false;
            $('lg-name').textContent = s.name;
            $('lg-code').textContent = s.code + (s.status !== 'active' ? ' · inactive' : '');
            $('lg-cloud').textContent = fmt(s.cloud_payable);
            $('lg-pending').textContent = fmt(s.pending_delta) + (s.pending_events ? ' (' + s.pending_events + ' event' + (s.pending_events === 1 ? '' : 's') + ')' : '');
            $('lg-available').textContent = fmt(s.available_payable);
            $('lg-bills').textContent = String((ledger.open_bills || []).length);
            $('pay-btn').disabled = !canPay || !(ledger.freshness && ledger.freshness.ok) || !options.local_mode || s.status !== 'active';
            const body = $('ledger-body');
            body.innerHTML = '';
            if (!ledger.rows.length) {
                body.innerHTML = '<tr><td colspan="8" class="empty">No ledger rows in the mirrored window.</td></tr>';
                return;
            }
            ledger.rows.forEach((r) => {
                const tr = document.createElement('tr');
                if (r.kind === 'provisional') { tr.className = 'pending'; }
                const type = String(r.entry_type || '').replace(/_/g, ' ');
                tr.innerHTML = '<td>' + esc(r.date ? new Date(r.date).toLocaleString() : '') + '</td>'
                    + '<td>' + esc(type.charAt(0).toUpperCase() + type.slice(1)) + '</td>'
                    + '<td>' + esc(r.reference_no || '—') + '</td>'
                    + '<td>' + esc(r.description || '') + (r.kind === 'official' && r.edge_event_uuid ? ' <span class="badge edge" title="Posted at the Cloud from a branch-server event">Edge event</span>' : '') + '</td>'
                    + '<td class="num">' + (r.debit ? fmt(r.debit) : '') + '</td>'
                    + '<td class="num">' + (r.credit ? fmt(r.credit) : '') + '</td>'
                    + '<td class="num">' + fmt(r.balance_after) + (r.kind === 'provisional' ? ' <span class="kv">(provisional)</span>' : '') + '</td>'
                    + '<td>' + (r.kind === 'official' ? esc(r.user || '') : syncBadge(r.sync)) + '</td>';
                body.appendChild(tr);
            });
        }

        function openPayForm() {
            if (!ledger) { return; }
            const s = ledger.supplier;
            $('pay-supplier').value = s.name + ' (' + s.code + ')';
            $('pay-date').value = options.today;
            const bill = $('pay-bill');
            bill.innerHTML = '<option value="">No specific bill (general payment)</option>';
            (ledger.open_bills || []).forEach((b) => {
                const o = document.createElement('option');
                o.value = b.cloud_bill_id;
                o.textContent = b.bill_no + ' · outstanding ' + fmt(b.balance_due) + (b.pending_allocated ? ' · available ' + fmt(b.available) : '') + (b.bill_date ? ' · ' + b.bill_date : '');
                bill.appendChild(o);
            });
            const cb = $('pay-cb');
            cb.innerHTML = '<option value="" disabled selected>— Select the Cash/Bank account (required) —</option>';
            (options.cash_bank_accounts || []).forEach((a) => {
                const o = document.createElement('option');
                o.value = a.cloud_cash_bank_account_id;
                o.textContent = a.code + ' · ' + a.name + ' (' + a.account_type + ') · balance ' + fmt(a.projected_balance);
                if (a.is_default) { o.selected = true; }
                cb.appendChild(o);
            });
            const m = $('pay-method');
            m.innerHTML = '';
            (options.payment_methods || []).forEach((pm) => {
                const o = document.createElement('option');
                o.value = pm.code;
                o.textContent = pm.label + (pm.offline_allowed ? '' : ' — needs the Online POS');
                o.disabled = !pm.offline_allowed;
                if (pm.code === 'cash') { o.selected = true; }
                m.appendChild(o);
            });
            $('pay-amount').value = '';
            $('pay-available').textContent = 'Available payable ' + fmt(s.available_payable) + ' — supplier advances are not supported, so the payment cannot exceed it.';
            ['pay-ref', 'pay-bank', 'pay-account', 'pay-txn', 'pay-cheque', 'pay-cheque-date', 'pay-notes'].forEach((id) => { $(id).value = ''; });
            $('pay-error').textContent = '';
            $('pay-modal').hidden = false;
            $('pay-amount').focus();
        }

        async function submitPayment(ev) {
            ev.preventDefault();
            const btn = $('pay-submit');
            btn.disabled = true;
            $('pay-error').textContent = '';
            const body = {
                cloud_supplier_id: ledger.supplier.cloud_supplier_id,
                cloud_bill_id: $('pay-bill').value ? Number($('pay-bill').value) : null,
                payment_date: $('pay-date').value,
                cloud_cash_bank_account_id: $('pay-cb').value ? Number($('pay-cb').value) : null,
                payment_method: $('pay-method').value,
                amount: Number($('pay-amount').value),
                reference_no: $('pay-ref').value || null,
                bank_name: $('pay-bank').value || null,
                account_no: $('pay-account').value || null,
                transaction_ref: $('pay-txn').value || null,
                cheque_no: $('pay-cheque').value || null,
                cheque_date: $('pay-cheque-date').value || null,
                notes: $('pay-notes').value || null,
            };
            try {
                const res = await api('POST', '/suppliers/payments', body);
                $('pay-modal').hidden = true;
                showResult(res.event);
                options = await api('GET', '/suppliers/options');
                renderStatus();
                renderSuppliers($('search').value);
                await openLedger(current);
            } catch (e) {
                $('pay-error').textContent = e.message;
            } finally {
                btn.disabled = false;
            }
        }

        function showResult(event) {
            const p = event.payload || {};
            const rows = [
                ['Status', syncBadge(event.sync) + ' <span class="kv">' + esc(event.sync ? event.sync.label : '') + '</span>'],
                ['Supplier', esc(p.supplier_name) + ' (' + esc(p.supplier_code) + ')'],
                ['Amount', fmt(event.amount)],
                ['Paid from', esc(p.cash_bank_code) + ' · ' + esc(p.cash_bank_name)],
                ['Method', esc(p.payment_method)],
                ['Against bill', p.bill_no ? esc(p.bill_no) : 'No specific bill (general payment)'],
                ['Payment date', esc(event.business_date)],
                ['Cloud will post', 'Dr Accounts Payable / Cr ' + esc(p.cash_bank_code) + ' — exactly once, when the connection returns'],
                ['Event', '<code>' + esc(event.event_uuid) + '</code>'],
            ];
            $('result-dl').innerHTML = rows.map((r) => '<dt>' + r[0] + '</dt><dd>' + r[1] + '</dd>').join('');
            $('result-modal').hidden = false;
        }

        async function load() {
            options = await api('GET', '/suppliers/options');
            renderStatus();
            renderSuppliers('');
        }

        $('search').addEventListener('input', (e) => renderSuppliers(e.target.value));
        $('pay-btn').addEventListener('click', openPayForm);
        $('pay-cancel').addEventListener('click', () => { $('pay-modal').hidden = true; });
        $('pay-form').addEventListener('submit', submitPayment);
        $('result-close').addEventListener('click', () => { $('result-modal').hidden = true; });
        load().catch((e) => toast(e.message));
    })();
    </script>
</body>
</html>
