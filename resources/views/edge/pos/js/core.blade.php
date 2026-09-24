{{-- W0 js/core (Team 1 owns): shared runtime — bootstrap DATA, CSRF, BASE, state, api(), toast(), modal shell, helpers.
     Every js/* fragment is concatenated INSIDE one IIFE by edge/pos/index.blade.php, so declarations here are visible to all.
     W1 (Online states, audit A36/E-08): api() marks the clicked button busy (Online setButtonBusy, O:2123-2160) and shows the
     loading bar; a 401/419 sends the operator back to the Edge login; toast() is severity-aware (Online top-end SweetAlert2
     toast, O:3784-3796) and falls back to the in-page toast when SweetAlert2 is not loaded; confirmDialog() is the Online
     Swal confirm (in-page fallback); showSpinner()/hideSpinner(), toastError(), showInlineError()/showInlineToast() helpers.
     Stable contract for the other fragments: DATA, CSRF, BASE, money, esc, state, api, toast, uuid, openModal, closeModal, $. --}}
        const DATA = JSON.parse(document.getElementById('edge-pos-data').textContent);
        const CSRF = document.querySelector('meta[name=csrf-token]').content;
        const BASE = '{{ url('/edge/local/pos') }}';
        const LOGIN_URL = '{{ url('/edge/local/login') }}';
        const money = n => (Math.round((Number(n) || 0) * 100) / 100).toFixed(2);
        const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        const $ = id => document.getElementById(id);

        // ---- state: a plain cart, OR a table session with no check yet, OR a loaded (recalled) open check. ----
        const state = { orderType: DATA.defaultOrderType, terminalId: null, category: null, cart: [], dirty: false,
                        session: null,   // {id, table_id, table_no, waiter_name}
                        held: null,      // {id, sale_no, sale_uuid, is_draft, order_type, session_id, table_no, waiter_name, terminal_id, totals...}
                        customer: null,  // {id, name, phone, addresses[]} picked from the synced book
                        pendingClientUuid: null,
                        // Commercial intent — the same fields the Online Review & Pay carries (discount, promo, delivery).
                        commercial: { discount_type: 'none', discount_value: 0, promo_code: '', manager_approval_id: null,
                                      delivery_channel_id: null, delivery_rider_id: null, delivery_address: '', delivery_charge_amount: DATA.defaultDeliveryCharge } };

        // ---- busy buttons (Online setButtonBusy: spinner + label, disabled, aria-busy) ----
        function buttonIsBusy(button) { return !!(button && button.dataset.posBusy === '1'); }
        function setButtonBusy(button, busy, label) {
            if (!button) return;
            if (busy) {
                if (buttonIsBusy(button)) return;
                button.dataset.posBusy = '1';
                button.dataset.posOriginalHtml = button.innerHTML;
                button.dataset.posWasDisabled = button.disabled ? '1' : '0';
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.innerHTML = '<span class="edge-spinner" aria-hidden="true"></span><span>' + esc(label || 'Please wait') + '</span>';
                return;
            }
            if (!buttonIsBusy(button)) return;
            button.innerHTML = button.dataset.posOriginalHtml || '';
            button.disabled = button.dataset.posWasDisabled === '1';
            button.removeAttribute('aria-busy');
            delete button.dataset.posBusy; delete button.dataset.posOriginalHtml; delete button.dataset.posWasDisabled;
        }

        // ---- page loading bar (Online global loader / spinner-border in modals) ----
        let _loadingCount = 0, _loadingTimer = null;
        function showSpinner() {
            _loadingCount++;
            if (_loadingCount === 1) { clearTimeout(_loadingTimer); _loadingTimer = setTimeout(() => { if (_loadingCount > 0) $('edge-loading').hidden = false; }, 250); }
        }
        function hideSpinner() {
            _loadingCount = Math.max(0, _loadingCount - 1);
            if (_loadingCount === 0) { clearTimeout(_loadingTimer); $('edge-loading').hidden = true; }
        }

        // ---- API helper: all mutations go to the Edge-local POS endpoints only. ----
        // opts.quiet: background poll (no loading bar, no busy button). A 401 (edge.auth: session gone/stale) or 419 (CSRF
        // token expired) returns the operator to the Edge login, like Online's redirect to /login.
        let _lastApiError = { msg: null, at: 0 }, _sessionEnded = false;
        async function api(method, path, body, opts) {
            opts = opts || {};
            const opt = { method, headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };
            if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
            const ev = opts.quiet ? null : window.event;
            const btn = ev && ev.type === 'click' && ev.target && ev.target.closest ? ev.target.closest('button') : null;
            const busyBtn = btn && !btn.disabled && !buttonIsBusy(btn) && !btn.closest('#calc-keypad') ? btn : null;
            if (busyBtn) setButtonBusy(busyBtn, true);
            if (!opts.quiet) showSpinner();
            let res;
            try {
                try { res = await fetch(BASE + path, opt); }
                catch (e) { $('offline-banner').hidden = false; throw apiError('The branch server did not answer — check the LAN connection.'); }
                $('offline-banner').hidden = true;
                if (res.status === 401 || res.status === 419) { sessionEnded(res.status); throw apiError(res.status === 419 ? 'Your session expired — sign in again.' : 'You are signed out — sign in again.'); }
                const json = await res.json().catch(() => ({}));
                if (!res.ok) throw apiError(json.message || (json.errors ? Object.values(json.errors).flat().join(' ') : 'Request failed (' + res.status + ')'));
                return json;
            } finally {
                if (busyBtn) setButtonBusy(busyBtn, false);
                if (!opts.quiet) hideSpinner();
            }
        }
        function apiError(msg) { _lastApiError = { msg: msg, at: Date.now() }; const e = new Error(msg); e.edgeApi = true; return e; }
        function sessionEnded(status) {
            if (_sessionEnded) return;
            _sessionEnded = true;
            toast(status === 419 ? 'Your session expired — returning to sign-in…' : 'You are signed out — returning to sign-in…', 'error');
            setTimeout(() => { window.location.assign(LOGIN_URL); }, 1500);
        }

        // ---- severity toast: toast(message[, 'success'|'error'|'warning'|'info']) ----
        // Without a level: a message that is the last API refusal shows as an error; guidance ("Select…", "Add at least…",
        // "Allow pop-ups…") as a warning; anything else as success — the Online icons for the same situations.
        const _swalToast = (typeof window.Swal !== 'undefined') ? window.Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true }) : null;
        let _toastTimer = null;
        function toast(msg, level) {
            msg = String(msg ?? '');
            if (!level) {
                if (_lastApiError.msg === msg && Date.now() - _lastApiError.at < 15000) level = 'error';
                else if (/^(Select |Add at least|Allow pop-ups|Already sent|No |Nothing |Choose |Pick |Enter )/.test(msg)) level = 'warning';
                else level = 'success';
            }
            const t = $('toast');
            t.textContent = msg; t.dataset.level = level; t.className = 'edge-toast ' + level;
            if (_swalToast) { t.style.display = 'none'; _swalToast.fire({ icon: level === 'error' ? 'error' : level, title: msg }); return; }
            t.style.display = 'block';
            clearTimeout(_toastTimer);
            _toastTimer = setTimeout(() => { t.style.display = 'none'; }, level === 'error' ? 5000 : 3200);
        }
        function toastError(msg) { toast(msg, 'error'); }

        // ---- confirm (Online Swal.fire({ icon:'warning', showCancelButton … })) → Promise<boolean> ----
        let _confirmResolve = null;
        function confirmDialog(o) {
            o = Object.assign({ title: 'Are you sure?', text: '', confirmText: 'OK', cancelText: 'Cancel', icon: 'warning', danger: false }, o || {});
            if (typeof window.Swal !== 'undefined') {
                return window.Swal.fire({ icon: o.icon, title: o.title, text: o.text, showCancelButton: true, confirmButtonText: o.confirmText, cancelButtonText: o.cancelText,
                    confirmButtonColor: o.danger ? '#dc3545' : '#d4a72c', focusCancel: !!o.danger }).then(r => !!r.isConfirmed);
            }
            return new Promise(resolve => {
                if (_confirmResolve) _confirmResolve(false);
                _confirmResolve = resolve;
                $('edge-confirm-title').textContent = o.title; $('edge-confirm-text').textContent = o.text;
                $('edge-confirm-ok').textContent = o.confirmText; $('edge-confirm-cancel').textContent = o.cancelText;
                $('edge-confirm-ok').className = o.danger ? 'danger' : 'primary';
                $('edge-confirm-icon').textContent = o.icon === 'question' ? '?' : '!';
                $('edge-confirm').classList.add('open');
                (o.danger ? $('edge-confirm-cancel') : $('edge-confirm-ok')).focus();
            });
        }
        function _settleConfirm(v) { $('edge-confirm').classList.remove('open'); const r = _confirmResolve; _confirmResolve = null; if (r) r(v); }
        $('edge-confirm-ok').addEventListener('click', () => _settleConfirm(true));
        $('edge-confirm-cancel').addEventListener('click', () => _settleConfirm(false));

        // ---- inline error / toast inside a dialog (Online #open-table-error / #qr-toast / #reserve-toast pattern) ----
        function showInlineError(target, msg) { const el = typeof target === 'string' ? $(target) : target; if (!el) { toastError(msg); return; } el.innerHTML = msg ? '<div class="edge-inline-error" role="alert">' + esc(msg) + '</div>' : ''; }
        function showInlineToast(target, msg, level) { const el = typeof target === 'string' ? $(target) : target; if (!el) { toast(msg, level); return; } el.innerHTML = msg ? '<div class="edge-inline-toast ' + esc(level || 'success') + '" role="status">' + esc(msg) + '</div>' : ''; }

        function uuid() { return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }); }

        // ---- the ONE generic dialog ----
        function openModal(html) { $('modal-body').innerHTML = html; $('modal').classList.add('open'); }
        function closeModal() { $('modal').classList.remove('open'); if (typeof onModalClosed === 'function') onModalClosed(); }
        $('modal').addEventListener('click', e => { if (e.target.id === 'modal') closeModal(); });

        // ---- W1 in-page dialogs (Branch & Terminal) ----
        function openDialog(id) { const d = $(id); if (d) { d.classList.add('open'); const f = d.querySelector('select, input, button.primary'); if (f) f.focus(); } }
        function closeDialog(id) { const d = $(id); if (d) d.classList.remove('open'); }
        document.querySelectorAll('[data-close-dialog]').forEach(b => b.addEventListener('click', () => closeDialog(b.dataset.closeDialog)));
        document.querySelectorAll('.edge-dialog').forEach(d => d.addEventListener('click', e => { if (e.target === d && d.id !== 'edge-confirm') closeDialog(d.id); }));
