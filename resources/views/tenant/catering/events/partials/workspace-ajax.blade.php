{{--
    KASHIF-CATERING-NO-RELOAD-1 — the workspace posts in place.

    Every POST on this page (customer-supplied toggles, Use this rate, Save
    Estimate, Recalculate, Finalize, advances, refunds, releases, invoices,
    emails) used to navigate, which threw the operator back to the top of the
    page on every click. Now the form posts by fetch, the server responds
    exactly as before (redirect back with flash/errors), and the workspace
    swaps its own HTML from that response. Server behavior, validation and
    authority are UNTOUCHED — this changes only how the answer reaches the
    screen. If anything unexpected happens, the form falls back to a normal
    submit, so no action is ever lost to the enhancement.
--}}
@push('scripts')
<script>
(function () {
    var ROOT_ID = 'event-workspace';
    if (! document.getElementById(ROOT_ID)) return;

    function reinit() {
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                bootstrap.Tooltip.getOrCreateInstance(el, {placement: 'bottom', trigger: 'hover focus', container: 'body'});
            });
        }
        if (window.initEstimateBuilder) window.initEstimateBuilder();
        if (window.initCateringEventForm) {
            document.querySelectorAll('[data-event-form-root]').forEach(function (el) {
                window.initCateringEventForm(el);
            });
        }
    }

    function closeOverlays() {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                var t = bootstrap.Tooltip.getInstance(el);
                if (t) t.dispose();
            }
        });
        document.querySelectorAll('.modal.show').forEach(function (m) {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var inst = bootstrap.Modal.getInstance(m);
                if (inst) inst.hide();
            }
        });
        document.querySelectorAll('.offcanvas.show').forEach(function (o) {
            if (typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
                var oi = bootstrap.Offcanvas.getInstance(o);
                if (oi) oi.hide();
            }
        });
        document.querySelectorAll('.modal-backdrop, .offcanvas-backdrop').forEach(function (b) { b.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    function swap(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var next = doc.getElementById(ROOT_ID);
        if (! next) { window.location.reload(); return; }
        closeOverlays();
        document.getElementById(ROOT_ID).innerHTML = next.innerHTML;
        reinit();
        // Bring a fresh flash or validation message into view without jumping.
        var note = document.getElementById(ROOT_ID).querySelector('.alert-success, .alert-danger');
        if (note) note.scrollIntoView({block: 'nearest', behavior: 'smooth'});
    }

    // Re-render the workspace from a plain GET — used after actions that
    // succeeded over JSON (the Edit offcanvas) rather than by redirect.
    window.cateringWorkspaceRefresh = function (message) {
        return fetch(window.location.href, {
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
        }).then(function (r) { return r.text(); }).then(function (html) {
            swap(html);
            if (message) {
                var box = document.createElement('div');
                box.className = 'alert alert-success';
                box.textContent = message;
                document.getElementById(ROOT_ID).prepend(box);
                box.scrollIntoView({block: 'nearest', behavior: 'smooth'});
            }
        });
    };

    window.cateringAjaxSubmit = function (action, formData) {
        return fetch(action, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
        }).then(function (r) {
            // Only a FOLLOWED REDIRECT may navigate. An error served straight
            // from the action URL (419 session expiry, 500) must never send
            // the browser THERE — a GET on a POST/PUT action is exactly the
            // 'Method Not Allowed' screen an operator once hit. A fresh reload
            // of the booking page recovers the session and their place.
            if (! r.redirected && ! r.ok) {
                window.location.reload();
                return null;
            }
            var finalPath = new URL(r.url, window.location.origin).pathname;
            if (r.redirected && finalPath !== window.location.pathname) {
                window.location.assign(r.url);
                return null;
            }
            return r.text().then(swap);
        });
    };

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (! (form instanceof HTMLFormElement)) return;
        if (! form.closest('#' + ROOT_ID)) return;
        if (form.hasAttribute('data-no-ajax')) return;
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        if (form.target && form.target !== '_self') return;

        e.preventDefault();
        var fd = new FormData(form);
        if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value);
        window.cateringAjaxSubmit(form.action, fd).catch(function () {
            // The enhancement must never cost the operator their action.
            form.submit();
        });
    });
})();
</script>
@endpush
