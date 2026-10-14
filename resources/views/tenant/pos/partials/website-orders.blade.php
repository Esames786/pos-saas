{{--
    ONLINE-ORDERING-1 (Phase 3) — website orders on the POS: a bell, a badge and a "receive only" modal.
    Included only on a plan with Online Ordering. Self-contained: it reuses the POS's own KOT-after-Hold,
    cancel and toast through window.posWebBridge, so a website order is held, printed and cancelled
    exactly like any counter order.
--}}
<div class="modal fade" id="webOrdersModal" tabindex="-1" aria-labelledby="webOrdersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="webOrdersModalLabel"><i class="ti ti-world-www me-2"></i>Website orders</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="web-orders-body"><p class="text-muted mb-0">Loading…</p></div>
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
    if (!btn || !modalEl || !body) { return; }

    var LIST = '{{ url('/api/pos/website-orders') }}';
    var HOLD = '{{ url('/api/pos/website-orders') }}/';
    var POS = '{{ url('/pos') }}';
    var CSRF = '{{ csrf_token() }}';
    var bridge = function () { return window.posWebBridge || {}; };
    var esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    };
    var money = function (n) { return 'Rs ' + Math.round(Number(n) || 0).toLocaleString('en-PK'); };
    var val = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };

    var orders = [], routed = false, seen = {}, primed = false, lastRing = 0;

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

    function poll() {
        var branch = val('branch_id');
        if (!branch) { return; }
        fetch(LIST + '?branch_id=' + encodeURIComponent(branch) + '&terminal_id=' + encodeURIComponent(val('terminal_id')), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin'
        }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
            if (!j || !j.enabled) { btn.closest('.col-auto').hidden = true; return; }
            orders = j.orders || [];
            routed = !!j.routed;
            btn.closest('.col-auto').hidden = !(routed || orders.length);
            badge.hidden = !orders.length;
            badge.textContent = orders.length;
            btn.classList.toggle('btn-danger', orders.some(function (o) { return o.is_draft; }));
            btn.classList.toggle('btn-outline-danger', !orders.some(function (o) { return o.is_draft; }));

            var fresh = orders.filter(function (o) { return o.is_draft && !seen[o.id]; });
            orders.forEach(function (o) { seen[o.id] = true; });
            var waiting = orders.some(function (o) { return o.is_draft; });
            if (routed && primed && fresh.length) {
                ring();
                var o = fresh[fresh.length - 1];
                if (bridge().toast) { bridge().toast('info', 'New website order ' + esc(o.sale_no) + ' · ' + money(o.total)); }
            } else if (routed && waiting && !modalEl.classList.contains('show') && Date.now() - lastRing > 60000) {
                ring();   // still waiting: remind every minute until someone opens it
            }
            primed = true;
            if (modalEl.classList.contains('show')) { render(); }
        }).catch(function () { /* offline: try again next tick */ });
    }

    function riderSelect(o) {
        var src = document.getElementById('delivery_rider_id');
        var branch = val('branch_id');
        var opts = '<option value="">— Rider —</option>';
        if (src) {
            Array.prototype.forEach.call(src.options, function (op) {
                if (!op.value) { return; }
                if (op.dataset.branch && branch && op.dataset.branch !== branch) { return; }
                opts += '<option value="' + esc(op.value) + '"' + (String(o.rider_id) === op.value ? ' selected' : '') + '>' + esc(op.text) + '</option>';
            });
        }
        return '<select class="form-select form-select-sm" data-rider="' + o.id + '" style="max-width:180px">' + opts + '</select>';
    }

    function render() {
        if (!orders.length) { body.innerHTML = '<p class="text-muted mb-0">No website orders waiting.</p>'; return; }
        body.innerHTML = orders.map(function (o) {
            var items = o.items.map(function (i) {
                return '<li>' + (i.qty % 1 ? i.qty : Math.round(i.qty)) + ' × ' + esc(i.name) +
                    (i.modifiers && i.modifiers.length ? ' <span class="text-muted">(' + i.modifiers.map(esc).join(', ') + ')</span>' : '') + '</li>';
            }).join('');
            var notes = (o.item_notes || []).map(function (n) { return '<div class="small">📝 ' + esc(n.item) + ': ' + esc(n.note) + '</div>'; }).join('');
            var head = '<div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">' +
                '<div><strong>' + esc(o.sale_no) + '</strong> <span class="badge ' + (o.is_draft ? 'bg-warning text-dark' : 'bg-success') + '">' +
                (o.is_draft ? 'New · not confirmed' : 'Confirmed · rider needed') + '</span> ' +
                '<span class="badge bg-light text-dark border">' + (o.order_type === 'takeaway' ? 'Pick-Up' : 'Delivery') + '</span>' +
                '<div class="small text-muted">' + o.minutes + ' min ago</div></div>' +
                '<div class="fs-5 fw-semibold">' + money(o.total) + '</div></div>';
            var who = '<div class="small mt-2"><i class="ti ti-user me-1"></i>' + esc(o.customer) +
                ' · <a href="tel:' + esc(o.phone) + '">' + esc(o.phone) + '</a>' + (o.alt_phone ? ' · ' + esc(o.alt_phone) : '') +
                (o.address ? '<div><i class="ti ti-map-pin me-1"></i>' + esc(o.address) + (o.map ? ' · <a href="' + esc(o.map) + '" target="_blank" rel="noopener">Map</a>' : '') + '</div>' : '') +
                (o.note ? '<div>💬 ' + esc(o.note) + '</div>' : '') + notes + '</div>';
            var actions = '<div class="d-flex flex-wrap gap-2 align-items-center mt-2">' + (o.needs_rider ? riderSelect(o) : '') +
                (o.is_draft
                    ? '<button type="button" class="btn btn-sm btn-success" data-hold="' + o.id + '"><i class="ti ti-chef-hat me-1"></i>Hold &amp; send to kitchen</button>' +
                      '<a class="btn btn-sm btn-outline-secondary" href="' + POS + '?held_sale_id=' + o.id + '">Recall</a>' +
                      '<button type="button" class="btn btn-sm btn-outline-danger" data-cancel="' + o.id + '" data-no="' + esc(o.sale_no) + '">Cancel</button>'
                    : '<button type="button" class="btn btn-sm btn-success" data-hold="' + o.id + '">Save rider</button>') +
                '</div>';
            return '<div class="border rounded-3 p-3 mb-3' + (o.is_draft ? ' border-warning' : '') + '">' + head +
                '<ul class="small mb-0 mt-2 ps-3">' + items + '</ul>' + who + actions + '</div>';
        }).join('');
    }

    body.addEventListener('click', function (e) {
        var hold = e.target.closest('[data-hold]');
        var cancel = e.target.closest('[data-cancel]');
        if (cancel && bridge().cancelHeldSaleFromModal) {
            bridge().cancelHeldSaleFromModal(+cancel.dataset.cancel, cancel.dataset.no, cancel);
            setTimeout(poll, 1500);
            return;
        }
        if (!hold || hold.disabled) { return; }
        var id = +hold.dataset.hold;
        var sel = body.querySelector('[data-rider="' + id + '"]');
        hold.disabled = true;
        fetch(HOLD + id + '/hold', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ delivery_rider_id: sel && sel.value ? +sel.value : null })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (res) {
            hold.disabled = false;
            if (!res.ok) {
                if (bridge().toast) { bridge().toast('error', esc(res.j.message || 'Could not hold this order.')); }
                return;
            }
            if (res.j.send_kot && bridge().handleKotAfterSale) {
                bridge().handleKotAfterSale(res.j.sale_id, res.j.sale_no, res.j.terminal_id);
            } else if (bridge().toast) {
                bridge().toast('success', 'Rider saved for ' + esc(res.j.sale_no));
            }
            poll();
        }).catch(function () { hold.disabled = false; });
    });

    btn.addEventListener('click', function () { render(); new bootstrap.Modal(modalEl).show(); });
    ['branch_id', 'terminal_id'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('change', function () { primed = false; seen = {}; poll(); }); }
    });

    poll();
    setInterval(poll, 15000);
});
</script>
