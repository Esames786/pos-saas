{{--
  OFFLINE EDGE — F3 PURCHASE RETURN PARITY: return goods to a supplier on the Branch Server.

  The current Online Bingoo POS is the specification (purchase-returns/create): Branch, Supplier, Source GRN, Return Date,
  Reason, Notes, then the "Received Lines" of the receipt — Product, Variant, Batch, Received, Already Returned, Returnable,
  Return Qty, Unit Cost, Line Total, Reason — and the Return Total. Online also allows a return WITHOUT a source receipt
  (validated against official stock only); offline that needs the Online POS, so the source receipt is required here.
  Offline the return is recorded as ONE step (Online: Save Draft → Post) because drafts are Cloud documents: the appliance
  reduces its operational stock, shows the provisional supplier effect and queues the immutable event; the Cloud posts the
  OFFICIAL return (stock OUT FEFO, supplier subledger, Dr Accounts Payable / Cr Inventory Asset) exactly once.

  Self-contained (inline CSS/JS, no build assets) so it renders with NO Internet.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Bingoo Edge — Purchase Returns</title>
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
        button:disabled { opacity:.5; cursor:not-allowed; }
        select, input, textarea { font:inherit; color:var(--ink); background:var(--panel2); border:1px solid var(--line); border-radius:8px; padding:.45rem .6rem; width:100%; }
        input:disabled { opacity:.45; }
        .chip { font-size:.78rem; background:var(--panel); border:1px solid var(--line); border-radius:999px; padding:.3rem .6rem; color:var(--muted); }
        .chip.fresh { border-color:var(--ok); color:#86efac; }
        .chip.stale { border-color:var(--danger); color:#fca5a5; }
        main { flex:1; display:grid; grid-template-columns: 380px 1fr; min-height:0; }
        .pane { display:flex; flex-direction:column; min-height:0; }
        .list-pane { border-right:1px solid var(--line); }
        .toolbar { padding:.6rem .7rem; border-bottom:1px solid var(--line); display:flex; gap:.5rem; }
        .scroll { flex:1; overflow-y:auto; }
        table { width:100%; border-collapse:collapse; font-size:.85rem; }
        th, td { padding:.45rem .55rem; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
        th { color:var(--muted); font-weight:500; font-size:.72rem; text-transform:uppercase; letter-spacing:.03em; position:sticky; top:0; background:var(--bg); }
        td.num, th.num { text-align:right; white-space:nowrap; }
        td.num input { text-align:right; }
        tr.grn { cursor:pointer; }
        tr.grn:hover, tr.grn.active { background:var(--panel2); }
        .badge { display:inline-block; font-size:.7rem; border-radius:999px; padding:.15rem .5rem; border:1px solid var(--line); color:var(--muted); white-space:nowrap; }
        .badge.pending { border-color:var(--warn); color:#fcd34d; }
        .badge.synced { border-color:var(--ok); color:#86efac; }
        .badge.failed { border-color:var(--danger); color:#fca5a5; }
        .badge.ok { border-color:var(--ok); color:#86efac; }
        .head { padding:.8rem .9rem; border-bottom:1px solid var(--line); display:flex; gap:1.2rem; align-items:flex-end; flex-wrap:wrap; }
        .head h2 { margin:0; font-size:1.1rem; }
        .kv { color:var(--muted); font-size:.8rem; }
        .kv strong { color:var(--ink); font-size:1rem; display:block; }
        .form-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:.6rem .8rem; padding:.8rem .9rem; border-bottom:1px solid var(--line); }
        .form-grid .wide { grid-column: span 3; }
        label { display:block; font-size:.75rem; color:var(--muted); margin-bottom:.2rem; }
        label.required::after { content:' *'; color:#fca5a5; }
        .help { color:var(--muted); font-size:.75rem; margin-top:.2rem; }
        .btn-row { display:flex; gap:.5rem; align-items:center; padding:.8rem .9rem; border-top:1px solid var(--line); flex-wrap:wrap; }
        .empty { color:var(--muted); padding:2rem; text-align:center; }
        .banner { padding:.5rem .9rem; font-size:.85rem; border-bottom:1px solid var(--line); }
        .banner.warn { background:#3b2a12; color:#fcd34d; }
        .banner.err { background:#3b1212; color:#fca5a5; }
        .events { padding:.6rem .9rem; border-top:1px solid var(--line); max-height:220px; overflow-y:auto; }
        .ev { border-bottom:1px solid var(--line); padding:.4rem 0; font-size:.85rem; }
        #err { color:#fca5a5; font-size:.85rem; }
        #ok { color:#86efac; font-size:.85rem; }
        code { font-size:.75rem; color:var(--muted); }
    </style>
</head>
<body>
    <header>
        <h1>Purchase Returns</h1>
        <span class="who">{{ $branchName }} · {{ $userName }}</span>
        <span class="spacer"></span>
        <span class="chip" id="fresh-chip">loading…</span>
        <span class="chip" id="mode-chip" hidden></span>
        <a class="navbtn" href="{{ url('/edge/local/pos') }}">POS</a>
        <a class="navbtn" href="{{ url('/edge/local/pos/suppliers') }}">Suppliers</a>
        <form method="POST" action="{{ url('/edge/local/logout') }}" style="margin:0">@csrf<button class="ghost">Logout</button></form>
    </header>
    <div class="banner warn" id="stale-banner" hidden>Purchase information is not current on this branch server — returns are refused until the Cloud position is refreshed (or post them on the Online POS).</div>
    <div class="banner err" id="mode-banner" hidden>The Cloud is the branch writer right now — post purchase returns on the Online POS. This page is read-only until the branch server takes over.</div>

    <main>
        <section class="pane list-pane">
            <div class="toolbar">
                <select id="supplier-filter"><option value="">All suppliers</option></select>
                <input type="search" id="search" placeholder="Search GRN no…" autocomplete="off">
            </div>
            <div class="scroll">
                <table>
                    <thead><tr><th>Source GRN</th><th>Supplier</th><th class="num">Returnable</th></tr></thead>
                    <tbody id="grn-body"></tbody>
                </table>
            </div>
            <div class="help" style="padding:.6rem .9rem;border-top:1px solid var(--line)">A return is built against the goods receipt being returned — lines and returnable quantities load automatically. A return <strong>without a source receipt</strong> needs the Online POS. The Cloud posts the official return (stock OUT, supplier subledger credit, Dr Accounts Payable / Cr Inventory Asset) exactly once when the connection returns.</div>
        </section>
        <section class="pane">
            <div class="empty" id="grn-empty">Select the goods receipt being returned against.</div>
            <div id="grn" hidden style="display:flex;flex-direction:column;min-height:0;flex:1">
                <div class="head">
                    <div><h2 id="g-no">—</h2><div class="kv" id="g-meta"></div></div>
                    <div class="kv">Supplier<strong id="g-supplier">—</strong></div>
                    <div class="kv">Receipt date<strong id="g-date">—</strong></div>
                    <div class="kv">Bill<strong id="g-bill">—</strong></div>
                    <div class="kv">Return Total<strong id="g-total">0.00</strong></div>
                </div>
                <form id="pr-form" autocomplete="off" style="display:flex;flex-direction:column;min-height:0;flex:1">
                    <div class="form-grid">
                        <div><label class="required">Branch</label><input value="{{ $branchName }}" disabled></div>
                        <div><label class="required">Return Date</label><input type="date" id="return_date" required></div>
                        <div><label class="required">Reason</label><select id="reason_code"><option value="">— Select —</option></select>
                            <div class="help">Required on the header or per line.</div></div>
                        <div class="wide"><label>Notes</label><input id="notes" maxlength="1000"></div>
                    </div>
                    <div class="kv" style="padding:.5rem .9rem;border-bottom:1px solid var(--line)"><strong style="color:var(--ink)">Received Lines</strong> — enter the quantity to return per line; 0 = not returned. Returnable = received − already returned (official and pending on this branch server).</div>
                    <div class="scroll">
                        <table id="lines">
                            <thead><tr>
                                <th>Product</th><th>Variant</th><th>Batch</th>
                                <th class="num">Received</th><th class="num">Already Returned</th><th class="num">Returnable</th>
                                <th style="width:120px">Return Qty</th><th class="num">Unit Cost</th><th class="num">Line Total</th><th style="width:150px">Reason</th>
                            </tr></thead>
                            <tbody id="line-body"></tbody>
                        </table>
                    </div>
                    <div class="btn-row">
                        <div id="err"></div><div id="ok"></div>
                        <span style="flex:1"></span>
                        <button type="submit" class="primary" id="post-btn" @unless($canPost) disabled title="You are not allowed to post purchase returns" @endunless>Post Return (pending sync)</button>
                    </div>
                </form>
                <div class="events">
                    <div class="kv" style="margin-bottom:.3rem">Returns recorded on this branch server</div>
                    <div id="events"></div>
                </div>
            </div>
        </section>
    </main>

    <script>
    (function () {
        const CSRF = document.querySelector('meta[name=csrf-token]').content;
        const BASE = '/edge/local/pos';
        const $ = (id) => document.getElementById(id);
        const fmt = (n, d) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: d === undefined ? 2 : d, maximumFractionDigits: d === undefined ? 2 : d });
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        const canPost = @json((bool) $canPost);
        const label = (code) => String(code || '').split('_').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');

        let options = null;
        let current = null;   // { grn, lines }

        async function api(method, path, body) {
            const res = await fetch(BASE + path, {
                method: method, credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                body: body ? JSON.stringify(body) : undefined,
            });
            let data = null;
            try { data = await res.json(); } catch (e) { data = null; }
            if (!res.ok) {
                const err = new Error((data && data.message) || ('HTTP ' + res.status));
                err.status = res.status;
                throw err;
            }
            return data;
        }

        function renderStatus() {
            const chip = $('fresh-chip');
            const f = options.freshness || {};
            chip.className = 'chip ' + (f.ok ? 'fresh' : 'stale');
            chip.textContent = f.ok ? ('Purchase position current · as of ' + (f.as_of ? new Date(f.as_of).toLocaleString() : '—')) : 'Cloud position NOT current';
            chip.title = f.ok ? '' : (f.reasons || []).join('; ');
            $('stale-banner').hidden = !!f.ok;
            const mode = $('mode-chip');
            mode.hidden = false;
            mode.textContent = options.local_mode ? 'Branch server is the writer' : 'Cloud is the writer';
            $('mode-banner').hidden = !!options.local_mode;
            const rs = $('reason_code');
            rs.innerHTML = '<option value="">— Select —</option>' + (options.rules.reason_codes || []).map((c) => '<option value="' + esc(c) + '">' + esc(label(c)) + '</option>').join('');
            const sf = $('supplier-filter');
            sf.innerHTML = '<option value="">All suppliers</option>' + (options.suppliers || []).map((s) => '<option value="' + s.cloud_supplier_id + '">' + esc(s.name) + '</option>').join('');
        }

        function renderGrns() {
            const sup = $('supplier-filter').value;
            const q = $('search').value.trim().toLowerCase();
            const body = $('grn-body');
            body.innerHTML = '';
            (options.grns || []).filter((g) => (!sup || String(g.cloud_supplier_id) === sup) && (!q || g.grn_no.toLowerCase().includes(q))).forEach((g) => {
                const tr = document.createElement('tr');
                tr.className = 'grn' + (current && current.grn.cloud_grn_id === g.cloud_grn_id ? ' active' : '');
                tr.innerHTML = '<td><div>' + esc(g.grn_no) + '</div><div class="kv">' + esc(g.receipt_date || '') + (g.bill_no ? ' · bill ' + esc(g.bill_no) : '') + '</div></td>'
                    + '<td>' + esc(g.supplier_name) + (g.supplier_status !== 'active' ? ' <span class="badge">inactive</span>' : '') + '</td>'
                    + '<td class="num">' + (g.returnable_total > 0 ? '<span class="badge ok">' + fmt(g.returnable_total, 3) + '</span>' : '<span class="badge">0</span>') + '</td>';
                tr.addEventListener('click', () => openGrn(g.cloud_grn_id));
                body.appendChild(tr);
            });
        }

        async function openGrn(id) {
            try {
                current = await api('GET', '/purchase-returns/grns/' + id);
            } catch (e) {
                $('err').textContent = e.message;
                return;
            }
            renderGrns();
            renderGrn();
        }

        function renderGrn() {
            const g = current.grn;
            $('grn-empty').hidden = true;
            $('grn').hidden = false;
            $('g-no').textContent = g.grn_no;
            $('g-meta').textContent = 'Source GRN · ' + (g.notes || '');
            $('g-supplier').textContent = g.supplier_name + ' (' + (g.supplier_code || '') + ')';
            $('g-date').textContent = g.receipt_date || '—';
            $('g-bill').textContent = g.bill_no || 'No bill linked';
            $('return_date').value = options.today;
            $('err').textContent = '';
            $('ok').textContent = '';
            const body = $('line-body');
            body.innerHTML = '';
            current.lines.forEach((l) => {
                const tr = document.createElement('tr');
                tr.dataset.line = l.cloud_grn_line_id;
                tr.dataset.cost = l.unit_cost;
                const canReturn = l.returnable > 0;
                tr.innerHTML = '<td>' + esc(l.product_name) + (l.local_on_hand !== null && l.local_on_hand !== undefined ? '<div class="kv">local on hand ' + fmt(l.local_on_hand, 3) + '</div>' : '') + '</td>'
                    + '<td>' + esc(l.variant_name || 'Default') + '</td>'
                    + '<td>' + esc(l.batch_no || '—') + '</td>'
                    + '<td class="num">' + fmt(l.quantity_received, 3) + '</td>'
                    + '<td class="num">' + fmt(l.already_returned, 3) + (l.pending_local_quantity > 0 ? ' <span class="badge pending" title="pending on this branch server">' + fmt(l.pending_local_quantity, 3) + ' pending</span>' : '') + '</td>'
                    + '<td class="num"><span class="badge ' + (canReturn ? 'ok' : '') + '">' + fmt(l.returnable, 3) + '</span></td>'
                    + '<td class="num"><input type="number" step="0.001" min="0" max="' + l.returnable + '" value="0" class="pr-qty"' + (canReturn ? '' : ' disabled') + '></td>'
                    + '<td class="num">' + fmt(l.unit_cost, 4) + '</td>'
                    + '<td class="num pr-line-total">0.00</td>'
                    + '<td><select class="pr-reason"><option value="">Header reason</option>' + (options.rules.reason_codes || []).map((c) => '<option value="' + esc(c) + '">' + esc(label(c)) + '</option>').join('') + '</select></td>';
                body.appendChild(tr);
            });
            recalc();
            renderEvents();
        }

        function recalc() {
            let total = 0;
            $('line-body').querySelectorAll('tr').forEach((tr) => {
                const q = Number(tr.querySelector('.pr-qty').value || 0);
                const t = q * Number(tr.dataset.cost || 0);
                tr.querySelector('.pr-line-total').textContent = fmt(t);
                total += t;
            });
            $('g-total').textContent = fmt(total);
            $('post-btn').disabled = !canPost || total <= 0 || !(options.freshness && options.freshness.ok) || !options.local_mode;
        }

        function syncBadge(sync) {
            if (!sync) { return ''; }
            const cls = sync.state === 'pending' ? 'pending' : (sync.state === 'synced' || sync.state === 'official' ? 'synced' : 'failed');
            const text = sync.state === 'pending' ? 'PENDING SYNC' : (sync.state === 'synced' ? 'POSTED AT CLOUD' : (sync.state === 'official' ? 'OFFICIAL' : (sync.state === 'failed' ? 'REFUSED BY CLOUD' : 'NOT QUEUED')));
            return '<span class="badge ' + cls + '" title="' + esc(sync.label) + '">' + text + '</span>' + (sync.official_return_no ? ' <span class="badge synced">' + esc(sync.official_return_no) + '</span>' : '');
        }

        function renderEvents() {
            const list = (options.recent_events || []).filter((ev) => !current || ev.cloud_grn_id === current.grn.cloud_grn_id);
            $('events').innerHTML = list.length ? list.map((ev) => '<div class="ev">' + esc(ev.return_date) + ' · ' + esc(label(ev.reason_code || '')) + ' · total ' + fmt(ev.grand_total) + ' · ' + ev.lines.map((l) => fmt(l.quantity, 3) + ' × ' + esc(l.product_name || ('#' + l.product_id))).join(', ') + ' ' + syncBadge(ev.sync) + ' <code>' + esc(ev.event_uuid) + '</code></div>').join('')
                : '<div class="kv">No returns recorded offline for this receipt.</div>';
        }

        async function submit(ev) {
            ev.preventDefault();
            $('err').textContent = '';
            $('ok').textContent = '';
            const lines = [];
            $('line-body').querySelectorAll('tr').forEach((tr) => {
                const q = Number(tr.querySelector('.pr-qty').value || 0);
                if (q > 0) { lines.push({ cloud_grn_line_id: Number(tr.dataset.line), quantity: q, reason_code: tr.querySelector('.pr-reason').value || null }); }
            });
            const btn = $('post-btn');
            btn.disabled = true;
            try {
                const res = await api('POST', '/purchase-returns', { cloud_grn_id: current.grn.cloud_grn_id, return_date: $('return_date').value, reason_code: $('reason_code').value || null, notes: $('notes').value || null, lines: lines });
                $('ok').textContent = 'Return recorded on this branch server — PENDING SYNC (event ' + res.event.event_uuid + '). Stock was reduced here; the Cloud posts the official return exactly once when the connection returns.';
                options = await api('GET', '/purchase-returns/options');
                renderStatus();
                await openGrn(current.grn.cloud_grn_id);
            } catch (e) {
                $('err').textContent = e.message;
                recalc();
            }
        }

        async function load() {
            options = await api('GET', '/purchase-returns/options');
            renderStatus();
            renderGrns();
        }

        $('supplier-filter').addEventListener('change', renderGrns);
        $('search').addEventListener('input', renderGrns);
        $('line-body').addEventListener('input', recalc);
        $('pr-form').addEventListener('submit', submit);
        load().catch((e) => { $('err').textContent = e.message; });
    })();
    </script>
</body>
</html>
