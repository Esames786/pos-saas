{{-- W-A — the shared POS transport (both runtimes). Reads window.POS_RUNTIME (App\Support\Pos\PosRuntime, injected by
     layouts.pos) and exposes window.POS:

       POS.route(key, params)          URL for a runtime route key; throws on an unknown key; null when the runtime does
                                       not offer that route (capability off). {param} placeholders are URI-encoded.
       POS.api(key, params, opts)      JSON request (opts: {method, body, query}); CSRF header from the meta tag,
                                       credentials same-origin; 401/419 → transport.unauthenticated_redirect; 403 → toast
                                       with the missing permission. Resolves the parsed JSON; rejects an Error carrying
                                       .status and .body for any other non-2xx (the page shows err.message).
                                       A FormData body is sent as multipart (no Content-Type header) — used where the Online
                                       controller relies on multipart semantics.
       POS.can(capability)             capability flag.
       POS.hint(capability)            tooltip for a capability-off control (runtime label, HTML-attribute safe).
       POS.managerCredentialFieldsHtml() / POS.managerCredentialFromPrompt()
                                       the manager-approval prompt fields and request body: {pin} (Cloud) or
                                       {manager_employee_code, manager_credential} (Edge) — the ONLY place that knows the
                                       credential type, so the workflow code stays identical in both runtimes.
       POS.setAuthority(authority)     repaint the shared runtime-status slot (#pos-runtime-slot).
       POS.overlay.show({title, text, tone}) / POS.overlay.hide()
                                       the one shared, fixed-position, non-reflowing urgent-warning overlay. --}}
<script>
(function () {
    'use strict';
    var RT = window.POS_RUNTIME || {};
    var routes = RT.routes || {};
    var caps = RT.capabilities || {};
    var transport = RT.transport || {};
    var EMPLOYEE = 'employee_code_and_credential';

    function csrfToken() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? (m.getAttribute('content') || '') : '';
    }

    function route(key, params) {
        if (!Object.prototype.hasOwnProperty.call(routes, key)) {
            throw new Error('POS.route: unknown route key [' + key + ']');
        }
        var t = routes[key];
        if (t === null || t === undefined) return null;
        params = params || {};
        Object.keys(params).forEach(function (name) {
            t = t.split('{' + name + '}').join(encodeURIComponent(String(params[name])));
        });
        return t;
    }

    function toQuery(obj) {
        var parts = [];
        Object.keys(obj || {}).forEach(function (k) {
            var v = obj[k];
            if (v === null || v === undefined) return;
            if (Array.isArray(v)) { v.forEach(function (x) { parts.push(encodeURIComponent(k) + '[]=' + encodeURIComponent(x)); }); }
            else { parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v)); }
        });
        return parts.join('&');
    }

    function toast(icon, title) {
        if (typeof Swal !== 'undefined') {
            Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3500 }).fire({ icon: icon, title: title });
        }
    }

    function api(key, params, opts) {
        opts = opts || {};
        var url = route(key, params);
        if (url === null) {
            // W-G3 (E3): a recognisable rejection — `capabilityOff: true`, status 0 (no HTTP happened), and a body shaped
            // like a refused JSON response ({ok:false, message, capabilityOff}) so a page handler that resolves HTTP
            // refusals to their body can treat it the same way. Never produced on Online (its routes are all non-null).
            var off = new Error((RT.labels && RT.labels.capabilityOff) || 'Not available in this mode.');
            off.status = 0; off.capabilityOff = true; off.routeKey = key;
            off.body = { ok: false, capabilityOff: true, route: key, message: off.message };
            return Promise.reject(off);
        }
        if (opts.query) {
            var qs = toQuery(opts.query);
            if (qs) url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
        }
        var hasBody = opts.body !== undefined && opts.body !== null;
        var method = String(opts.method || (hasBody ? 'POST' : 'GET')).toUpperCase();
        var headers = { 'Accept': 'application/json' };
        headers[transport.csrf_header || 'X-CSRF-TOKEN'] = csrfToken();
        var init = { method: method, headers: headers, credentials: 'same-origin' };
        if (hasBody && method !== 'GET') {
            if (typeof FormData !== 'undefined' && opts.body instanceof FormData) {
                init.body = opts.body;
            } else {
                headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(opts.body);
            }
        }
        return fetch(url, init).then(function (res) {
            return res.text().then(function (text) {
                var data = null;
                try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
                if (res.status === 401 || res.status === 419) {
                    var err401 = new Error('Your session has ended. Please sign in again.');
                    err401.status = res.status; err401.body = data;
                    if (transport.unauthenticated_redirect) { window.location.href = transport.unauthenticated_redirect; }
                    throw err401;
                }
                if (res.status === 403) {
                    var msg403 = (data && data.message) || 'You do not have permission for this action.';
                    toast('error', msg403 + (data && data.permission ? ' (' + data.permission + ')' : ''));
                }
                if (res.status === 422 && isAuthorityRefusal(data)) {
                    // F-1: the Branch Server refused a write because it is not the active writer — say so (the page's own
                    // catch may show err.message too; the toast guarantees the cashier sees it even where it does not).
                    notifyNoMutate(data.message);
                }
                if (!res.ok) {
                    var err = new Error((data && (data.message
                        || (data.errors && Object.values(data.errors).flat().join(' ')))) || ('Request failed (' + res.status + ').'));
                    err.status = res.status; err.body = data;
                    throw err;
                }
                return data;
            });
        });
    }

    /**
     * opts (optional, so each page keeps its own Online markup): { id: PIN input id (default 'swal-manager-pin'),
     * placeholder (default 'Manager code'), attrs: extra attributes of the PIN input }.
     */
    function managerCredentialFieldsHtml(opts) {
        opts = opts || {};
        var id = opts.id || 'swal-manager-pin';
        if (RT.managerCredential === EMPLOYEE) {
            // Two fields on ONE row so the prompt keeps the single-field height (A5: same dialog dimensions).
            var s = 'margin:0;flex:1 1 0;min-width:0;width:auto';
            return '<div class="d-flex gap-2" style="margin:1em 2em 3px">'
                + '<input type="text" id="swal-manager-code" class="swal2-input" placeholder="Manager employee code" maxlength="64" autocomplete="off" style="' + s + '">'
                + '<input type="password" id="' + id + '" class="swal2-input" placeholder="Credential" maxlength="128" autocomplete="one-time-code" style="' + s + '">'
                + '</div>';
        }
        return '<input type="password" id="' + id + '" class="swal2-input" placeholder="' + (opts.placeholder || 'Manager code') + '" '
            + (opts.attrs !== undefined ? opts.attrs : 'maxlength="64" autocomplete="one-time-code"') + '>';
    }

    /** The field to focus first when the prompt opens (the employee code on Edge, the PIN on the Cloud). */
    function managerCredentialFocus(opts) {
        var el = document.getElementById('swal-manager-code') || document.getElementById((opts && opts.id) || 'swal-manager-pin');
        if (el) el.focus();
    }

    /** The credential part of the verify body, or null when a field is empty. Key order keeps the Cloud body unchanged. */
    function managerCredentialFromPrompt(opts) {
        var pinEl = document.getElementById((opts && opts.id) || 'swal-manager-pin');
        var pin = pinEl ? pinEl.value : '';
        if (RT.managerCredential === EMPLOYEE) {
            var codeEl = document.getElementById('swal-manager-code');
            var code = codeEl ? codeEl.value.trim() : '';
            if (!code || !pin) return null;
            return { manager_employee_code: code, manager_credential: pin };
        }
        if (!pin) return null;
        return { pin: pin };
    }

    var TONES = { ok: 'bg-success', warn: 'bg-warning text-dark', danger: 'bg-danger' };

    /* F-1 (owner, 8 Nov 2026) — the WRITE GATE of the shared cashier view. The Cloud runtime carries no `can_mutate`
       (→ always true, Online untouched); the Edge runtime sets authority.can_mutate (STANDBY / handback = false, Local
       Mode = true). The gate is a UI courtesy only: the Branch Server still refuses every mutation server-side (422
       "Local Mode is not active…"), which api() now surfaces as a toast instead of a silent failure. The in-page cart is
       never discarded by a gate change — it is only prevented from growing / being paid while the appliance cannot write. */
    function canMutate() {
        var a = RT.authority;
        if (!a || typeof a !== 'object' || !Object.prototype.hasOwnProperty.call(a, 'can_mutate')) return true;
        return a.can_mutate !== false;
    }
    function noMutateText() {
        var a = RT.authority || {};
        if (a.state === 'handing_back') {
            return 'This Branch Server is returning authority to the Online POS. Nothing can be rung here until the handback completes; continue on the Online POS.';
        }
        return 'This Branch Server is in STANDBY — the Online (Cloud) POS is the active writer for this branch. Items, payments and shifts can only be rung here once Local Mode is active.';
    }
    var GATED = ['review-pay-btn', 'complete-sale-btn', 'pos-shift-open-link'];
    var gateOverlayShown = false;
    function applyMutationGate() {
        var ok = canMutate();
        GATED.forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            if (!ok) {
                if (el.getAttribute('data-pos-gate') !== '1') {
                    el.setAttribute('data-pos-gate', '1');
                    el.setAttribute('data-pos-gate-title', el.getAttribute('title') || '');
                    if (el.tagName === 'BUTTON') { el.disabled = true; } else { el.setAttribute('aria-disabled', 'true'); el.style.pointerEvents = 'none'; el.style.opacity = '0.5'; }
                    el.setAttribute('title', noMutateText());
                }
            } else if (el.getAttribute('data-pos-gate') === '1') {
                el.removeAttribute('data-pos-gate');
                if (el.tagName === 'BUTTON') { el.disabled = false; } else { el.removeAttribute('aria-disabled'); el.style.pointerEvents = ''; el.style.opacity = ''; }
                var t = el.getAttribute('data-pos-gate-title'); el.removeAttribute('data-pos-gate-title');
                if (t) { el.setAttribute('title', t); } else { el.removeAttribute('title'); }
            }
        });
        document.body.classList.toggle('pos-no-mutate', !ok);
        if (!ok) {
            overlay.show({ title: (RT.authority && RT.authority.label) || 'STANDBY', text: noMutateText(), tone: RT.authority && RT.authority.tone === 'danger' ? 'danger' : 'warn' });
            gateOverlayShown = true;
        } else if (gateOverlayShown) {
            overlay.hide();
            gateOverlayShown = false;
            toast('success', 'Local Mode is active — this Branch Server can ring sales now.');
        }
    }
    /** A server-side authority refusal (EdgeAuthorityService, 422) — recognised by its message, never by status alone. */
    function isAuthorityRefusal(data) {
        var m = data && typeof data === 'object' ? String(data.message || '') : String(data || '');
        return /Local Mode is not active|active writer for this branch/i.test(m);
    }
    /** Tell the cashier why a write was refused (gate or server), without touching the cart. */
    function notifyNoMutate(message) {
        toast('error', message || noMutateText());
        if (!canMutate()) { applyMutationGate(); }
    }

    function setAuthority(a) {
        a = a || {};
        RT.authority = Object.assign({}, RT.authority || {}, a);
        var st = document.getElementById('pos-runtime-state');
        var sub = document.getElementById('pos-runtime-sub');
        var pend = document.getElementById('pos-runtime-pending');
        if (st) {
            st.className = 'badge ' + (TONES[RT.authority.tone] || TONES.ok);
            st.textContent = RT.authority.label || '';
        }
        if (sub) { sub.textContent = RT.authority.sub_label ? '· ' + RT.authority.sub_label : ''; }
        if (pend) {
            var n = parseInt(RT.authority.pending_sync, 10) || 0;
            pend.textContent = n + ' pending sync';
            pend.classList.toggle('d-none', n <= 0);
        }
        applyMutationGate();
    }
    document.addEventListener('DOMContentLoaded', applyMutationGate);

    var overlay = {
        show: function (o) {
            o = o || {};
            var root = document.getElementById('pos-runtime-overlay');
            if (!root) return;
            var box = document.getElementById('pos-runtime-overlay-box');
            if (box) box.className = 'alert shadow d-flex align-items-start gap-2 mb-0 alert-' + (o.tone === 'danger' ? 'danger' : (o.tone === 'ok' ? 'success' : 'warning'));
            var t = document.getElementById('pos-runtime-overlay-title');
            var x = document.getElementById('pos-runtime-overlay-text');
            if (t) t.textContent = o.title || '';
            if (x) x.textContent = o.text || '';
            root.classList.remove('d-none');
        },
        hide: function () {
            var root = document.getElementById('pos-runtime-overlay');
            if (root) root.classList.add('d-none');
        },
    };
    document.addEventListener('click', function (e) {
        if (e.target && e.target.id === 'pos-runtime-overlay-close') overlay.hide();
    });

    window.POS = {
        mode: RT.mode || 'cloud',
        route: route,
        api: api,
        can: function (capability) { return !!caps[capability]; },
        /** Tooltip for a capability-off control (HTML-attribute safe). */
        hint: function (capability) {
            var l = RT.labels || {};
            var t = l['capability.' + capability] || l.capabilityOff || 'Not available in this mode';
            return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; });
        },
        /** The same hint as plain text (for textContent / toasts) — W-G3 (E3). */
        hintText: function (capability) {
            var l = RT.labels || {};
            return String(l['capability.' + capability] || l.capabilityOff || 'Not available in this mode');
        },
        managerCredentialFieldsHtml: managerCredentialFieldsHtml,
        managerCredentialFromPrompt: managerCredentialFromPrompt,
        managerCredentialFocus: managerCredentialFocus,
        setAuthority: setAuthority,
        overlay: overlay,
        /* F-1 write gate (see above). Online: canMutate() is always true and the other three are harmless no-ops. */
        canMutate: canMutate,
        applyMutationGate: applyMutationGate,
        isAuthorityRefusal: isAuthorityRefusal,
        notifyNoMutate: notifyNoMutate,
    };
})();
</script>
