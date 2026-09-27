{{-- W-A — the shop clock logic of the POS, shared by both runtimes (moved from partials/header.blade.php lines 143-227;
     the header copy stays for every other Cloud screen).

     Markup-free: it drives whichever `.shift-clock-widget` the runtime's chrome renders (Cloud: the header's widget; Edge:
     its own chrome). On the Cloud the header script has already started the clock and defined window.__setPosClock, so this
     block stands down — the Online page behaves exactly as before. The periodic resync URL comes from the widget's
     data-resync-url, falling back to the runtime map (`routes.serverTime`). The page's shift-status poll retunes the clock
     through window.__setPosClock({tz, businessDate, serverEpochMs}) in both runtimes. --}}
<script>
(function () {
    if (window.__setPosClock) return;   // already driven (Cloud header clock)
    var el = document.querySelector('.shift-clock-widget');
    if (!el) return;
    var out = el.querySelector('.shift-clock-time');
    var out2 = el.querySelector('.shift-clock-secondary');
    var serverMs = parseInt(el.getAttribute('data-epoch'), 10);
    var tz = el.getAttribute('data-tz') || undefined;
    var tz2 = el.getAttribute('data-tz-secondary') || null;
    var resyncUrl = el.getAttribute('data-resync-url') || null;
    if (!resyncUrl && window.POS) { try { resyncUrl = window.POS.route('serverTime'); } catch (e) { resyncUrl = null; } }
    if (!out || isNaN(serverMs)) return;

    // performance.now() is monotonic, so a wrong/changed client wall clock cannot skew the server-anchored instant.
    var perfBase = (window.performance && performance.now) ? performance.now() : null;

    function makeFmt(zone, withDate) {
        var opts = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false };
        if (withDate) { opts.day = '2-digit'; opts.month = 'short'; opts.year = 'numeric'; }
        try { return new Intl.DateTimeFormat('en-GB', Object.assign({ timeZone: zone }, opts)); }
        catch (e) { return new Intl.DateTimeFormat('en-GB', opts); }
    }
    var fmt = makeFmt(tz, false);
    var fmt2 = tz2 ? makeFmt(tz2, false) : null;

    function nowMs() {
        var elapsed = perfBase !== null ? (performance.now() - perfBase) : 0;
        return serverMs + elapsed;
    }
    function tick() {
        var d = new Date(nowMs());
        out.textContent = fmt.format(d);
        if (out2 && fmt2) { out2.textContent = fmt2.format(d) + ' ' + tz2; }
    }
    tick();
    setInterval(tick, 1000);

    window.__setPosClock = function (cfg) {
        cfg = cfg || {};
        if (cfg.tz) {
            tz = cfg.tz;
            fmt = makeFmt(tz, false);
            var lbl = el.querySelector('.shift-clock-tz');
            if (lbl) lbl.textContent = tz;
        }
        if (typeof cfg.serverEpochMs === 'number') {
            serverMs = cfg.serverEpochMs;
            perfBase = (window.performance && performance.now) ? performance.now() : null;
        }
        if (cfg.businessDate) {
            var badge = el.querySelector('.shift-clock-bizdate');
            if (badge) badge.innerHTML = '<i class="ti ti-calendar-event me-1"></i>' + cfg.businessDate;
        }
        tick();
    };

    if (resyncUrl) {
        setInterval(function () {
            fetch(resyncUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    // Cloud: {epoch_ms}; Edge: {server_epoch_ms} — accept either.
                    var ms = d ? (typeof d.epoch_ms === 'number' ? d.epoch_ms : d.server_epoch_ms) : null;
                    if (typeof ms === 'number') {
                        serverMs = ms;
                        perfBase = (window.performance && performance.now) ? performance.now() : null;
                    }
                })
                .catch(function () { /* transient — keep ticking on the last good anchor */ });
        }, 300000);
    }
})();
</script>
