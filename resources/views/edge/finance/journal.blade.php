{{--
  OFFLINE EDGE — F2 SUPPLIER FINANCE: the General Journal on the Branch Server (supplier-aware AP dimension).

  Online is the specification (finance/manual-journals/form): Entry Date, Description / Memo, Reference, lines with
  Account, Cash/Bank, Supplier (AP lines only — enabled and REQUIRED when the account is Accounts Payable 2100 or a
  descendant, disabled otherwise so no stray counterparty is sent), Description, Debit, Credit; post only when
  balanced. Dr Expense / Cr AP (supplier) increases the payable; Dr AP (supplier) / Cr offset reduces it (never below
  the boundary — supplier advances are unsupported). The Cloud posts the official GL + subledger mirror exactly once.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Bingoo Edge — General Journal</title>
    <style>
        :root { --bg:#0f172a; --panel:#1e293b; --panel2:#172033; --line:#334155; --ink:#e2e8f0; --muted:#94a3b8; --accent:#4f46e5; --ok:#16a34a; --warn:#d97706; --danger:#dc2626; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:system-ui,Segoe UI,sans-serif; background:var(--bg); color:var(--ink); min-height:100vh; display:flex; flex-direction:column; }
        header { background:var(--panel); border-bottom:1px solid var(--line); padding:.5rem .9rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
        header h1 { font-size:1.05rem; margin:0; }
        header .who { color:var(--muted); font-size:.8rem; }
        header .spacer { flex:1; }
        a.navbtn, button { font:inherit; color:var(--ink); cursor:pointer; border:1px solid var(--line); background:var(--panel2); border-radius:8px; padding:.5rem .8rem; text-decoration:none; font-size:.9rem; }
        button.primary { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; }
        button.ghost, a.navbtn { background:transparent; }
        button.sm { padding:.25rem .55rem; font-size:.8rem; }
        button:disabled { opacity:.5; cursor:not-allowed; }
        select, input, textarea { font:inherit; color:var(--ink); background:var(--panel2); border:1px solid var(--line); border-radius:8px; padding:.4rem .55rem; width:100%; }
        select:disabled, input:disabled { opacity:.45; }
        .chip { font-size:.78rem; background:var(--panel); border:1px solid var(--line); border-radius:999px; padding:.3rem .6rem; color:var(--muted); }
        .chip.fresh { border-color:var(--ok); color:#86efac; }
        .chip.stale { border-color:var(--danger); color:#fca5a5; }
        main { flex:1; display:grid; grid-template-columns: 1fr 360px; gap:1rem; padding:1rem; min-height:0; }
        .card { background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:1rem; }
        .card h2 { margin:0 0 .6rem; font-size:1rem; }
        .form-grid { display:grid; grid-template-columns:1fr 2fr 1fr; gap:.6rem .8rem; }
        label { display:block; font-size:.75rem; color:var(--muted); margin-bottom:.2rem; }
        label.required::after { content:' *'; color:#fca5a5; }
        table { width:100%; border-collapse:collapse; font-size:.85rem; margin-top:.8rem; }
        th, td { padding:.35rem .35rem; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
        th { color:var(--muted); font-weight:500; font-size:.72rem; text-transform:uppercase; letter-spacing:.03em; }
        td.num input { text-align:right; }
        tfoot td { font-weight:600; }
        .help { color:var(--muted); font-size:.75rem; margin-top:.25rem; }
        .btn-row { display:flex; gap:.5rem; align-items:center; margin-top:.8rem; flex-wrap:wrap; }
        .badge { display:inline-block; font-size:.7rem; border-radius:999px; padding:.15rem .5rem; border:1px solid var(--line); color:var(--muted); white-space:nowrap; }
        .badge.pending { border-color:var(--warn); color:#fcd34d; }
        .badge.synced { border-color:var(--ok); color:#86efac; }
        .badge.failed { border-color:var(--danger); color:#fca5a5; }
        .banner { padding:.5rem .9rem; font-size:.85rem; border-bottom:1px solid var(--line); }
        .banner.warn { background:#3b2a12; color:#fcd34d; }
        .banner.err { background:#3b1212; color:#fca5a5; }
        .ev { border-bottom:1px solid var(--line); padding:.5rem 0; font-size:.85rem; }
        .ev .kv { color:var(--muted); font-size:.75rem; }
        #err { color:#fca5a5; font-size:.85rem; margin-top:.5rem; }
        #ok { color:#86efac; font-size:.85rem; margin-top:.5rem; }
        code { font-size:.75rem; color:var(--muted); }
    </style>
</head>
<body>
    <header>
        <h1>General Journal</h1>
        <span class="who">{{ $branchName }} · {{ $userName }}</span>
        <span class="spacer"></span>
        <span class="chip" id="fresh-chip">loading…</span>
        <span class="chip" id="mode-chip" hidden></span>
        <a class="navbtn" href="{{ url('/edge/local/pos') }}">POS</a>
        @if($canViewLedger)
            <a class="navbtn" id="suppliers-link" href="{{ url('/edge/local/pos/suppliers') }}">Suppliers</a>
        @endif
        <form method="POST" action="{{ url('/edge/local/logout') }}" style="margin:0">@csrf<button class="ghost">Logout</button></form>
    </header>
    <div class="banner warn" id="stale-banner" hidden>Chart of accounts / supplier information is not current on this branch server — journals are refused until the Cloud position is refreshed (or post them on the Online POS).</div>
    <div class="banner err" id="mode-banner" hidden>The Cloud is the branch writer right now — post journals on the Online POS. This page is read-only until the branch server takes over.</div>

    <main>
        <section class="card">
            <h2>New journal entry</h2>
            <form id="mj-form" autocomplete="off">
                <div class="form-grid">
                    <div><label class="required" for="entry_date">Entry Date</label><input type="date" id="entry_date" required></div>
                    <div><label class="required" for="description">Description / Memo</label><input id="description" maxlength="500" required placeholder="e.g. Supplier invoice accrual, AP correction"></div>
                    <div><label for="reference_no">Reference</label><input id="reference_no" maxlength="100"></div>
                </div>
                <table id="lines">
                    <thead><tr>
                        <th style="width:26%">Account</th>
                        <th style="width:16%">Cash/Bank</th>
                        <th style="width:18%">Supplier <small>(AP lines)</small></th>
                        <th>Description</th>
                        <th style="width:11%">Debit</th>
                        <th style="width:11%">Credit</th>
                        <th style="width:2rem"></th>
                    </tr></thead>
                    <tbody id="rows"></tbody>
                    <tfoot><tr><td colspan="4" style="text-align:right">Totals</td><td class="num" id="tot-debit">0.00</td><td class="num" id="tot-credit">0.00</td><td></td></tr>
                    <tr><td colspan="7" class="help" id="balance-note"></td></tr></tfoot>
                </table>
                <div class="btn-row">
                    <button type="button" class="ghost" id="add-line">+ Add line</button>
                    <span class="spacer" style="flex:1"></span>
                    <button type="submit" class="primary" id="submitBtn" disabled>Post Journal (pending sync)</button>
                </div>
                <div class="help">Accounts Payable lines (2100 and its sub-accounts) must name the supplier they belong to — the supplier ledger mirrors the AP movement at the Cloud. Dr Expense / Cr AP increases the supplier's payable; Dr AP / Cr offset reduces it, never below zero (supplier advances are not supported).</div>
                <div id="err"></div>
                <div id="ok"></div>
            </form>
        </section>
        <aside class="card">
            <h2>Journals recorded on this branch server</h2>
            <div id="events"></div>
        </aside>
    </main>

    <script>
    (function () {
        const CSRF = document.querySelector('meta[name=csrf-token]').content;
        const BASE = '/edge/local/pos';
        const $ = (id) => document.getElementById(id);
        const fmt = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

        let options = null;
        let AP_ACCOUNT_IDS = [];

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
            chip.textContent = f.ok ? ('Chart & suppliers current · as of ' + (f.as_of ? new Date(f.as_of).toLocaleString() : '—')) : 'Cloud position NOT current';
            chip.title = f.ok ? '' : (f.reasons || []).join('; ');
            $('stale-banner').hidden = !!f.ok;
            const mode = $('mode-chip');
            mode.hidden = false;
            mode.textContent = options.local_mode ? 'Branch server is the writer' : 'Cloud is the writer';
            $('mode-banner').hidden = !!options.local_mode;
        }

        function accountOptions(selected) {
            let html = '<option value="">— Account —</option>';
            (options.accounts || []).forEach((a) => {
                html += '<option value="' + a.cloud_account_id + '"' + (Number(selected) === a.cloud_account_id ? ' selected' : '') + '>' + esc(a.code + ' · ' + a.name) + (a.is_ap ? ' (AP)' : '') + '</option>';
            });
            return html;
        }

        function supplierOptions() {
            let html = '<option value="">— Supplier —</option>';
            (options.suppliers || []).forEach((s) => {
                html += '<option value="' + s.cloud_supplier_id + '">' + esc(s.name + ' (' + s.code + ') · payable ' + fmt(s.available_payable)) + '</option>';
            });
            return html;
        }

        function cashBankOptionsFor(accountId) {
            const mapped = (options.cash_bank_accounts || []).filter((c) => Number(c.coa_account_id) === Number(accountId));
            let html = '<option value="">' + (mapped.length ? '— none —' : '— no cash/bank account mapped to this account —') + '</option>';
            mapped.forEach((c) => { html += '<option value="' + c.cloud_cash_bank_account_id + '">' + esc(c.code + ' · ' + c.name) + '</option>'; });
            return { html: html, any: mapped.length > 0 };
        }

        function addLine() {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td><select class="mj-account" required>' + accountOptions(null) + '</select></td>'
                + '<td><select class="mj-cb" disabled><option value="">— none —</option></select></td>'
                + '<td><select class="mj-supplier" disabled>' + supplierOptions() + '</select></td>'
                + '<td><input class="mj-desc" maxlength="255" placeholder="Optional note"></td>'
                + '<td class="num"><input type="number" step="0.0001" min="0" class="mj-debit" placeholder="0.00"></td>'
                + '<td class="num"><input type="number" step="0.0001" min="0" class="mj-credit" placeholder="0.00"></td>'
                + '<td><button type="button" class="ghost sm mj-remove" title="Remove line">×</button></td>';
            $('rows').appendChild(tr);
            syncLine(tr);
        }

        // Online semantics: the supplier cell is ENABLED + REQUIRED only on an Accounts Payable line; disabled otherwise so
        // no stray counterparty travels. The cash/bank cell offers only the accounts mapped to the chosen chart account.
        function syncLine(tr) {
            const acc = tr.querySelector('.mj-account');
            const sup = tr.querySelector('.mj-supplier');
            const cb = tr.querySelector('.mj-cb');
            const isAp = AP_ACCOUNT_IDS.includes(Number(acc.value));
            sup.disabled = !isAp;
            sup.required = isAp;
            if (!isAp) { sup.value = ''; }
            const o = cashBankOptionsFor(acc.value);
            const prev = cb.value;
            cb.innerHTML = o.html;
            cb.disabled = !acc.value || !o.any;
            if (o.any && prev) { cb.value = prev; }
            recalc();
        }

        function recalc() {
            let d = 0, c = 0, filled = 0;
            $('rows').querySelectorAll('tr').forEach((tr) => {
                const dv = Number(tr.querySelector('.mj-debit').value || 0);
                const cv = Number(tr.querySelector('.mj-credit').value || 0);
                d += dv; c += cv;
                if ((dv > 0 || cv > 0) && tr.querySelector('.mj-account').value) { filled++; }
            });
            $('tot-debit').textContent = fmt(d);
            $('tot-credit').textContent = fmt(c);
            const diff = Math.round((d - c) * 10000) / 10000;
            const balanced = diff === 0 && d > 0 && filled >= 2;
            $('balance-note').textContent = d === 0 && c === 0 ? 'Enter at least two lines.' : (diff === 0 ? 'Balanced.' : ('Out of balance by ' + fmt(Math.abs(diff)) + (diff > 0 ? ' (debit heavier)' : ' (credit heavier)')));
            $('submitBtn').disabled = !balanced || !$('description').value.trim() || !(options && options.freshness && options.freshness.ok && options.local_mode);
        }

        function collectLines() {
            const lines = [];
            $('rows').querySelectorAll('tr').forEach((tr) => {
                const acc = tr.querySelector('.mj-account').value;
                const dv = Number(tr.querySelector('.mj-debit').value || 0);
                const cv = Number(tr.querySelector('.mj-credit').value || 0);
                if (!acc || (dv <= 0 && cv <= 0)) { return; }
                const sup = tr.querySelector('.mj-supplier');
                const cb = tr.querySelector('.mj-cb');
                lines.push({
                    cloud_account_id: Number(acc),
                    cloud_cash_bank_account_id: !cb.disabled && cb.value ? Number(cb.value) : null,
                    cloud_supplier_id: !sup.disabled && sup.value ? Number(sup.value) : null,   // disabled → not sent (Online semantics)
                    description: tr.querySelector('.mj-desc').value || null,
                    debit: dv,
                    credit: cv,
                });
            });
            return lines;
        }

        function syncBadge(sync) {
            if (!sync) { return ''; }
            const cls = sync.state === 'pending' ? 'pending' : (sync.state === 'synced' || sync.state === 'official' ? 'synced' : 'failed');
            const text = sync.state === 'pending' ? 'PENDING SYNC' : (sync.state === 'synced' ? 'POSTED AT CLOUD' : (sync.state === 'official' ? 'OFFICIAL' : (sync.state === 'failed' ? 'REFUSED BY CLOUD' : 'NOT QUEUED')));
            return '<span class="badge ' + cls + '" title="' + esc(sync.label) + '">' + text + '</span>';
        }

        function renderEvents() {
            const box = $('events');
            const list = options.recent_events || [];
            if (!list.length) { box.innerHTML = '<div class="kv" style="color:var(--muted)">No journals recorded offline yet.</div>'; return; }
            box.innerHTML = list.map((ev) => {
                const p = ev.payload || {};
                const lines = (p.lines || []).map((l) => esc(l.account_code) + (l.supplier_name ? ' [' + esc(l.supplier_name) + ']' : '') + (l.debit > 0 ? ' Dr ' + fmt(l.debit) : ' Cr ' + fmt(l.credit))).join(' · ');
                return '<div class="ev"><div>' + esc(ev.description) + ' ' + syncBadge(ev.sync) + (ev.sync && ev.sync.official_reference_no ? ' <span class="badge synced">' + esc(ev.sync.official_reference_no) + '</span>' : '') + '</div>'
                    + '<div class="kv">' + esc(ev.business_date) + ' · total ' + fmt(ev.amount) + '</div><div class="kv">' + lines + '</div><div class="kv"><code>' + esc(ev.event_uuid) + '</code></div></div>';
            }).join('');
        }

        async function submit(ev) {
            ev.preventDefault();
            $('err').textContent = '';
            $('ok').textContent = '';
            const btn = $('submitBtn');
            btn.disabled = true;
            try {
                const res = await api('POST', '/finance/journal', {
                    entry_date: $('entry_date').value,
                    description: $('description').value,
                    reference_no: $('reference_no').value || null,
                    lines: collectLines(),
                });
                $('ok').textContent = 'Journal recorded on this branch server — PENDING SYNC. The Cloud posts the official entry exactly once when the connection returns. Event ' + res.event.event_uuid + '.';
                $('rows').innerHTML = '';
                addLine(); addLine();
                $('description').value = '';
                $('reference_no').value = '';
                options = await api('GET', '/finance/journal/options');
                AP_ACCOUNT_IDS = (options.ap_account_ids || []).map(Number);
                renderStatus();
                renderEvents();
                recalc();
            } catch (e) {
                $('err').textContent = e.message;
                recalc();
            }
        }

        async function load() {
            options = await api('GET', '/finance/journal/options');
            AP_ACCOUNT_IDS = (options.ap_account_ids || []).map(Number);
            $('entry_date').value = options.today;
            renderStatus();
            renderEvents();
            addLine(); addLine();
        }

        $('rows').addEventListener('change', (e) => {
            if (e.target.classList.contains('mj-account')) { syncLine(e.target.closest('tr')); }
        });
        $('rows').addEventListener('input', recalc);
        $('rows').addEventListener('click', (e) => {
            if (e.target.classList.contains('mj-remove')) {
                const tr = e.target.closest('tr');
                if ($('rows').querySelectorAll('tr').length > 2) { tr.remove(); recalc(); }
            }
        });
        $('description').addEventListener('input', recalc);
        $('add-line').addEventListener('click', addLine);
        $('mj-form').addEventListener('submit', submit);
        load().catch((e) => { $('err').textContent = e.message; });
    })();
    </script>
</body>
</html>
