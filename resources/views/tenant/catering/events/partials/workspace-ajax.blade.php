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
        document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
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

    window.cateringAjaxSubmit = function (action, formData) {
        return fetch(action, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
        }).then(function (r) {
            // An action that legitimately leads somewhere else still navigates.
            var finalPath = new URL(r.url, window.location.origin).pathname;
            if (finalPath !== window.location.pathname) {
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
