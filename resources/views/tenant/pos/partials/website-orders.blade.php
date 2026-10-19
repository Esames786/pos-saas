{{--
    ONLINE-ORDERING-1 (Phase 3) — website orders on the POS: a bell, a badge and a "receive only" modal.
    Included only on a plan with Online Ordering. Self-contained: it reuses the POS's own KOT-after-Hold,
    cancel, toast and Held-Orders recall through window.posWebBridge, so a website order is held, printed,
    recalled and cancelled exactly like any counter order.

    Owner, 15 Oct: compact rows (details open on tap), status-wise tabs, and Recall without reloading the
    screen — the same in-place recall the Held Orders list uses.
--}}
<style>
    #webOrdersModal .wo-row { cursor: pointer; }
    #webOrdersModal .wo-row:hover, #webOrdersModal .wo-row.open { background: var(--bs-tertiary-bg, #f8f9fa); }
    #webOrdersModal .wo-detail > td { background: var(--bs-tertiary-bg, #f8f9fa); border-top: 0; }
    #webOrdersModal .wo-chev { display: inline-block; transition: transform .15s ease; }
    #webOrdersModal .wo-row.open .wo-chev { transform: rotate(90deg); }
    #webOrdersModal .wo-num { font-variant-numeric: tabular-nums; }
    #webOrdersModal .wo-row > td { padding-top: .7rem; padding-bottom: .7rem; }
    @media (prefers-reduced-motion: reduce) { #webOrdersModal .wo-chev { transition: none; } }
</style>
<div class="modal fade" id="webOrdersModal" tabindex="-1" aria-labelledby="webOrdersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5 mb-0" id="webOrdersModalLabel"><i class="ti ti-world-www me-2"></i>Website orders</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="px-3 py-2 d-flex gap-1 flex-wrap border-bottom" id="web-orders-tabs" role="tablist">
                <button type="button" class="btn btn-sm btn-dark" data-wo-tab="new">New <span class="badge bg-warning text-dark ms-1" data-wo-count="new">0</span></button>
                <button type="button" class="btn btn-sm btn-outline-dark" data-wo-tab="confirmed">Confirmed <span class="badge bg-secondary ms-1" data-wo-count="confirmed">0</span></button>
                <button type="button" class="btn btn-sm btn-outline-dark" data-wo-tab="paid">Paid today <span class="badge bg-secondary ms-1" data-wo-count="paid">0</span></button>
                <button type="button" class="btn btn-sm btn-outline-dark" data-wo-tab="cancelled">Cancelled today <span class="badge bg-secondary ms-1" data-wo-count="cancelled">0</span></button>
            </div>
            <div class="modal-body p-0" id="web-orders-body" style="min-height:55vh"><p class="text-muted p-3 mb-0">Loading…</p></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var btn = document.getElementById('web-orders-btn');
    var badge = document.getElementById('web-orders-badge');
    var modalEl = document.getElementById('webOrdersModal');
    var body = document.getElementById('web-orders-body');
    var tabsEl = document.getElementById('web-orders-tabs');
    if (!btn || !modalEl || !body || !tabsEl) { return; }

    // CANONICAL MERGE (D8): every endpoint comes from the runtime (PosRuntime / POS.route) — Cloud: the tenant.api.pos.website-orders
    // routes; on a runtime without the capability (Branch Server) the route is null and this script stops here. No Cloud literal.
    var LIST = POS.route('websiteOrders');
    if (!LIST) { return; }
    var HOLD_URL = function (id) { return POS.route('websiteOrderHold', { salesOrder: id }); };
    var VERIFY_URL = function (id) { return POS.route('websiteOrderVerify', { salesOrder: id }); };
    var HELD = POS.route('heldList');
    var POS_URL = POS.route('posIndex');
    var CSRF = '{{ csrf_token() }}';
    var bridge = function () { return window.posWebBridge || {}; };
    var esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    };
    var money = function (n) { return 'Rs ' + Math.round(Number(n) || 0).toLocaleString('en-PK'); };
    var val = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
    var ago = function (m) { return m < 60 ? m + ' min' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm'; };

    var open = [], closed = { paid: null, cancelled: null }, counts = {}, routed = false, waReceived = false;
    var tab = null, expanded = {}, pickedRider = {}, seen = {}, primed = false, lastRing = 0;

    // ── bell (no sound file: two short tones). The cashier has clicked the POS by now, so audio may play.
    var ctx = null;
    function ring() {
        try {
            var C = window.AudioContext || window.webkitAudioContext;
            if (!C) { return; }
            ctx = ctx || new C();
            if (ctx.state === 'suspended') { ctx.resume(); }
            [0, 0.35, 0.7].forEach(function (t) {
                var o = ctx.createOscillator(), g = ctx.createGain();
                o.type = 'sine'; o.frequency.value = t === 0.35 ? 660 : 880;
                o.connect(g); g.connect(ctx.destination);
                var s = ctx.currentTime + t;
                g.gain.setValueAtTime(0.0001, s);
                g.gain.exponentialRampToValueAtTime(0.35, s + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, s + 0.3);
                o.start(s); o.stop(s + 0.32);
            });
            lastRing = Date.now();
        } catch (e) { /* no audio: the badge and toast still show */ }
    }
    document.addEventListener('pointerdown', function () { if (ctx && ctx.state === 'suspended') { ctx.resume(); } }, { passive: true });

    function query(extra) {
        return LIST + '?branch_id=' + encodeURIComponent(val('branch_id')) + '&terminal_id=' + encodeURIComponent(val('terminal_id')) + (extra || '');
    }

    function showCounts() {
        ['new', 'confirmed', 'paid', 'cancelled'].forEach(function (k) {
            var el = tabsEl.querySelector('[data-wo-count="' + k + '"]');
            if (el) { el.textContent = counts[k] || 0; }
        });
        // Badge = what still needs the cashier: new orders nobody has confirmed yet + confirmed ones without a
        // rider. (A new order waiting on the customer's WhatsApp tap needs nobody right now.)
        var check = counts.to_check !== undefined ? counts.to_check : (counts.new || 0);
        var todo = check + (counts.no_rider || 0);
        badge.hidden = !todo;
        badge.textContent = todo;
        btn.classList.toggle('btn-danger', check > 0);
        btn.classList.toggle('btn-outline-danger', !check);
    }

    function poll() {
        if (!val('branch_id')) { return; }
        fetch(query(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
                if (!j || !j.enabled) { btn.closest('.col-auto').hidden = true; return; }
                open = j.orders || [];
                counts = j.counts || {};
                routed = !!j.routed;
                waReceived = !!j.wa_received;
                btn.closest('.col-auto').hidden = !(routed || open.length);
                showCounts();

                // Ring for orders nobody has confirmed yet (one waiting on the customer's tap needs no one).
                var drafts = open.filter(function (o) { return o.status === 'new' && !o.verified; });
                var fresh = drafts.filter(function (o) { return !seen[o.id]; });
                open.forEach(function (o) { seen[o.id] = true; });
                if (routed && primed && fresh.length) {
                    ring();
                    var o = fresh[fresh.length - 1];
                    if (bridge().toast) { bridge().toast('info', 'New website order ' + esc(o.sale_no) + ' · ' + money(o.total)); }
                } else if (routed && drafts.length && !modalEl.classList.contains('show') && Date.now() - lastRing > 60000) {
                    ring();   // still waiting: remind every minute until someone opens it
                }
                primed = true;
                if (modalEl.classList.contains('show') && (tab === 'new' || tab === 'confirmed')) { render(); }
            }).catch(function () { /* offline: try again next tick */ });
    }

    function loadClosed(which) {
        body.innerHTML = '<p class="text-muted p-3 mb-0">Loading…</p>';
        fetch(query('&tab=' + which), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
                closed[which] = j && j.orders ? j.orders : [];
                if (j && j.counts) { counts = j.counts; showCounts(); }
                if (tab === which) { render(); }
            }).catch(function () { body.innerHTML = '<div class="alert alert-danger m-3">Could not load orders.</div>'; });
    }

    function setTab(next) {
        tab = next;
        tabsEl.querySelectorAll('[data-wo-tab]').forEach(function (b) {
            var on = b.dataset.woTab === next;
            b.classList.toggle('btn-dark', on);
            b.classList.toggle('btn-outline-dark', !on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (next === 'paid' || next === 'cancelled') { loadClosed(next); } else { render(); }
    }

    function riderSelect(o) {
        var src = document.getElementById('delivery_rider_id');
        var branch = val('branch_id');
        var current = pickedRider[o.id] !== undefined ? pickedRider[o.id] : String(o.rider_id || '');
        var opts = '<option value="">— Rider —</option>';
        if (src) {
            Array.prototype.forEach.call(src.options, function (op) {
                if (!op.value) { return; }
                if (op.dataset.branch && branch && op.dataset.branch !== branch) { return; }
                opts += '<option value="' + esc(op.value) + '"' + (current === op.value ? ' selected' : '') + '>' + esc(op.text) + '</option>';
            });
        }
        return '<select class="form-select form-select-sm" data-rider="' + o.id + '" style="max-width:170px">' + opts + '</select>';
    }

    function statusBadge(o) {
        if (o.status === 'new') {
            if (!o.verified) { return '<span class="badge bg-warning text-dark">New</span>'; }
            return o.wa_received === 'failed' ? '<span class="badge bg-danger">WhatsApp failed</span>'
                : '<span class="badge bg-info text-dark">Waiting for customer</span>';
        }
        if (o.status === 'confirmed') {
            return o.missing_rider ? '<span class="badge bg-danger">Rider needed</span>'
                : '<span class="badge bg-success">Confirmed</span>' + (o.rider ? '<div class="small text-muted">Rider: ' + esc(o.rider) + '</div>' : '');
        }
        if (o.status === 'cancelled') { return '<span class="badge bg-secondary">' + (o.cancelled_by === 'auto' ? 'Auto-cancelled' : 'Cancelled') + '</span>'; }
        return '<span class="badge bg-success">Paid</span>';
    }

    function detail(o) {
        var items = o.items.map(function (i) {
            return '<li>' + (i.qty % 1 ? i.qty : Math.round(i.qty)) + ' × ' + esc(i.name) +
                (i.modifiers && i.modifiers.length ? ' <span class="text-muted">(' + i.modifiers.map(esc).join(', ') + ')</span>' : '') + '</li>';
        }).join('');
        var notes = (o.item_notes || []).map(function (n) { return '<div>📝 ' + esc(n.item) + ': ' + esc(n.note) + '</div>'; }).join('');
        var who = '<div class="fw-semibold"><i class="ti ti-cash me-1"></i>' + esc(o.payment || 'Cash on delivery') + '</div>' +
            '<div><i class="ti ti-phone me-1"></i><a href="tel:' + esc(o.phone) + '">' + esc(o.phone) + '</a>' + (o.alt_phone ? ' · ' + esc(o.alt_phone) : '') + '</div>' +
            (o.address ? '<div><i class="ti ti-map-pin me-1"></i>' + esc(o.address) + (o.map ? ' · <a href="' + esc(o.map) + '" target="_blank" rel="noopener" data-map>Map</a>' : '') + '</div>' : '') +
            (o.note ? '<div>💬 ' + esc(o.note) + '</div>' : '') + notes;
        var actions = '';
        if (o.status === 'new' || o.status === 'confirmed') {
            // Owner, 15 Oct: the admin checks the order and presses Confirm — only then does the customer get the
            // "Order received" WhatsApp with its Confirm Order button. Hold & send to kitchen stays for phone confirms.
            var canVerify = o.status === 'new' && !o.verified && waReceived;
            var verifyBtn = canVerify
                ? '<button type="button" class="btn btn-sm btn-primary" data-verify="' + o.id + '"><i class="ti ti-brand-whatsapp me-1"></i>Confirm &amp; send WhatsApp</button>'
                : '';
            var main = o.status === 'new'
                ? '<button type="button" class="btn btn-sm ' + (canVerify ? 'btn-outline-success' : 'btn-success') + '" data-hold="' + o.id + '"><i class="ti ti-chef-hat me-1"></i>Hold &amp; send to kitchen</button>'
                : (o.needs_rider ? '<button type="button" class="btn btn-sm btn-success" data-hold="' + o.id + '">Save rider</button>' : '');
            var hint = '';
            if (o.status === 'new' && o.verified) {
                hint = o.wa_received === 'failed'
                    ? '<div class="small text-danger mt-2">The WhatsApp message could not be sent. Call the customer, then press Hold &amp; send to kitchen.</div>'
                    : '<div class="small text-muted mt-2">Confirmed' + (o.verified_by ? ' by ' + esc(o.verified_by) : '') + ' · WhatsApp sent ' + ago(o.verified_minutes || 0) +
                        ' ago — waiting for the customer to tap Confirm Order. If they don’t, call them and press Hold &amp; send to kitchen.</div>';
            } else if (o.status === 'new' && !waReceived) {
                hint = '<div class="small text-muted mt-2">The “Order received” WhatsApp is off in Website Settings — call the customer, then press Hold &amp; send to kitchen.</div>';
            }
            actions = hint + '<div class="d-flex flex-wrap gap-2 align-items-center mt-2">' + verifyBtn + (o.needs_rider ? riderSelect(o) : '') + main +
                '<button type="button" class="btn btn-sm btn-outline-danger" data-cancel="' + o.id + '" data-no="' + esc(o.sale_no) + '">Cancel</button></div>';
        }
        return '<tr class="wo-detail"><td colspan="6" class="small px-3 pb-3 pt-1">' +
            '<div class="row g-2"><div class="col-md-6"><ul class="mb-0 ps-3">' + items + '</ul></div><div class="col-md-6">' + who + '</div></div>' +
            actions + '</td></tr>';
    }

    function render() {
        var rows = (tab === 'paid' || tab === 'cancelled') ? (closed[tab] || [])
            : open.filter(function (o) { return o.status === tab; });
        if (!rows.length) {
            var empty = { new: 'No new website orders.', confirmed: 'No confirmed orders waiting for payment.', paid: 'No paid website orders today.', cancelled: 'No cancelled website orders today.' };
            body.innerHTML = '<p class="text-muted text-center py-4 mb-0">' + empty[tab] + '</p>';
            return;
        }
        var live = tab === 'new' || tab === 'confirmed';
        body.innerHTML = '<div class="table-responsive"><table class="table table-hover align-middle mb-0">' +
            '<thead class="table-light"><tr><th class="ps-3">Order</th><th>Customer</th><th class="text-end">Total</th><th>Status</th><th>Items</th><th></th></tr></thead><tbody>' +
            rows.map(function (o) {
                var isOpen = !!expanded[o.id];
                return '<tr class="wo-row' + (isOpen ? ' open' : '') + '" data-toggle-row="' + o.id + '" aria-expanded="' + isOpen + '">' +
                    '<td class="ps-3 text-nowrap"><i class="ti ti-chevron-right wo-chev me-1 text-muted"></i><strong>' + esc(o.sale_no) + '</strong>' +
                        '<div class="small text-muted ps-3">' + (o.order_type === 'takeaway' ? 'Pick-Up' : 'Delivery') + ' · ' + (live ? ago(o.minutes) + ' ago' : esc(o.time)) + '</div></td>' +
                    '<td><div class="text-truncate" style="max-width:200px">' + esc(o.customer) + '</div>' +
                        '<div class="small text-muted text-truncate" style="max-width:240px" title="' + esc(o.place || '') + '">' + esc(o.area || o.address || '') +
                            (o.place ? ' · 📍 near ' + esc(o.place) : '') + '</div></td>' +
                    '<td class="text-end fw-semibold wo-num text-nowrap">' + money(o.total) + '</td>' +
                    '<td>' + statusBadge(o) + '</td>' +
                    '<td class="small text-muted text-nowrap">' + o.items.length + '</td>' +
                    '<td class="text-end pe-3">' + (live ? '<button type="button" class="btn btn-sm btn-primary" data-recall="' + o.id + '" data-type="' + esc(o.order_type) + '">Recall</button>' : '') + '</td>' +
                    '</tr>' + (isOpen ? detail(o) : '');
            }).join('') + '</tbody></table></div>';
    }

    // Recall in place — the SAME path as the Held Orders list (no page reload). The order is taken from
    // the Held Orders API, so the cart is rebuilt from exactly what that list would hand over. Only if it
    // cannot be found there (older than that list's 50) does it fall back to the page-load recall.
    function recall(id, type, el) {
        el.disabled = true;
        var fallback = function () { window.location = POS_URL + '?held_sale_id=' + id; };
        fetch(HELD + '?branch_id=' + encodeURIComponent(val('branch_id')) + (type ? '&order_type=' + encodeURIComponent(type) : ''), {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF }, credentials: 'same-origin'
        }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
            var sale = d && d.sales ? d.sales.find(function (s) { return Number(s.id) === id; }) : null;
            if (!sale || !bridge().recallHeldSale) { fallback(); return; }
            var m = bootstrap.Modal.getInstance(modalEl);
            if (m) { m.hide(); }
            el.disabled = false;
            bridge().recallHeldSale(sale);
        }).catch(fallback);
    }

    tabsEl.addEventListener('click', function (e) {
        var b = e.target.closest('[data-wo-tab]');
        if (b) { setTab(b.dataset.woTab); }
    });

    body.addEventListener('change', function (e) {
        var sel = e.target.closest('[data-rider]');
        if (sel) { pickedRider[sel.dataset.rider] = sel.value; }
    });

    body.addEventListener('click', function (e) {
        // Map opens in a small window over the POS (owner), not a new tab; the same window is reused.
        var mapLink = e.target.closest('[data-map]');
        if (mapLink) {
            e.preventDefault();
            var w = 760, h = 560;
            var left = Math.max(0, (window.screenX || 0) + ((window.outerWidth || screen.width) - w) / 2);
            var top = Math.max(0, (window.screenY || 0) + ((window.outerHeight || screen.height) - h) / 2);
            var win = window.open(mapLink.href, 'pos-website-map', 'popup=yes,width=' + w + ',height=' + h + ',left=' + Math.round(left) + ',top=' + Math.round(top));
            if (win) { win.focus(); } else { window.open(mapLink.href, '_blank', 'noopener'); }   // pop-ups blocked: a tab as before
            return;
        }
        var rc = e.target.closest('[data-recall]');
        if (rc) { recall(+rc.dataset.recall, rc.dataset.type, rc); return; }
        var cancel = e.target.closest('[data-cancel]');
        if (cancel && bridge().cancelHeldSaleFromModal) {
            bridge().cancelHeldSaleFromModal(+cancel.dataset.cancel, cancel.dataset.no, cancel);
            setTimeout(poll, 1500);
            return;
        }
        var verify = e.target.closest('[data-verify]');
        if (verify) {
            if (verify.disabled) { return; }
            verify.disabled = true;
            fetch(VERIFY_URL(verify.dataset.verify), {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF }
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (res) {
                verify.disabled = false;
                if (!bridge().toast) { poll(); return; }
                if (!res.ok) {
                    bridge().toast('error', esc(res.j.message || 'Could not confirm this order.'));
                } else if (res.j.whatsapp === 'sending') {
                    bridge().toast('success', 'Confirmed ' + esc(res.j.sale_no) + ' — the customer gets a WhatsApp to confirm.');
                } else {
                    bridge().toast('warning', 'Confirmed ' + esc(res.j.sale_no) + ', but no WhatsApp was sent (it is off, or no phone). Call the customer.');
                }
                poll();
            }).catch(function () { verify.disabled = false; });
            return;
        }
        var hold = e.target.closest('[data-hold]');
        if (hold) {
            if (hold.disabled) { return; }
            var id = +hold.dataset.hold;
            var sel = body.querySelector('[data-rider="' + id + '"]');
            hold.disabled = true;
            fetch(HOLD_URL(id), {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ delivery_rider_id: sel && sel.value ? +sel.value : null })
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (res) {
                hold.disabled = false;
                if (!res.ok) {
                    if (bridge().toast) { bridge().toast('error', esc(res.j.message || 'Could not hold this order.')); }
                    return;
                }
                delete pickedRider[id];
                if (res.j.send_kot && bridge().handleKotAfterSale) {
                    bridge().handleKotAfterSale(res.j.sale_id, res.j.sale_no, res.j.terminal_id);
                } else if (bridge().toast) {
                    bridge().toast('success', 'Rider saved for ' + esc(res.j.sale_no));
                }
                poll();
            }).catch(function () { hold.disabled = false; });
            return;
        }
        // Anywhere else on a row (not a control): open or close its details.
        if (e.target.closest('button, select, a, input')) { return; }
        var row = e.target.closest('[data-toggle-row]');
        if (row) {
            var rid = row.dataset.toggleRow;
            expanded[rid] = !expanded[rid];
            render();
        }
    });

    btn.addEventListener('click', function () {
        // Open on New when something is waiting, otherwise where the cashier last was.
        var start = (counts.new || 0) > 0 ? 'new' : (tab || ((counts.confirmed || 0) > 0 ? 'confirmed' : 'new'));
        setTab(start);
        new bootstrap.Modal(modalEl).show();
    });
    ['branch_id', 'terminal_id'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('change', function () { primed = false; seen = {}; poll(); }); }
    });

    poll();
    setInterval(poll, 15000);
});
</script>
