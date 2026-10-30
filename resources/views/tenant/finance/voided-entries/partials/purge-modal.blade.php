{{-- VOID-PURGE-1: one confirmation for every "Delete permanently" button on the page. Shows what goes, asks why and
     the password again. The form is sent in the background and the answer comes back as a popup (23 Oct): a refusal
     says why without reloading; a void dated another day asks "Delete anyway?" with the days whose closing changes.
     Without JavaScript the form still posts and the page shows the message at the top. --}}
@if(\App\Support\TenantFeatures::on(\App\Support\TenantFeatures::VOID_PURGE) && auth()->user()?->can('tenant.finance.voided-entries.purge'))
<div class="modal fade" id="voidPurgeModal" tabindex="-1" aria-labelledby="voidPurgeTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" id="void-purge-form">
                @csrf
                <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
                <input type="hidden" name="across_days" value="0" id="void-purge-across-days">
                <div class="modal-header">
                    <h5 class="modal-title" id="voidPurgeTitle">Delete permanently?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <dl class="row small mb-2">
                        <dt class="col-4">Entry</dt><dd class="col-8" id="vp-label"></dd>
                        <dt class="col-4">Date</dt><dd class="col-8" id="vp-date"></dd>
                        <dt class="col-4">Amount</dt><dd class="col-8 fw-semibold" id="vp-amount"></dd>
                    </dl>
                    <div class="alert alert-danger small mb-3">
                        This entry <strong>and its void</strong> will be deleted from every screen — Cash Book, ledgers,
                        journals and reports. Totals stay exactly the same. This cannot be undone; a hidden copy is kept in
                        Finance → Deleted entries.
                    </div>
                    <label for="void-purge-reason" class="form-label">Why is it being deleted? <span class="text-danger">*</span></label>
                    <textarea name="purge_reason" id="void-purge-reason" class="form-control mb-2" rows="2" required minlength="5" maxlength="500"
                        placeholder="e.g. entered by mistake, a test entry">{{ old('purge_reason') }}</textarea>
                    <label for="void-purge-password" class="form-label">Your password <span class="text-danger">*</span></label>
                    <input type="password" name="purge_password" id="void-purge-password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="void-purge-submit">Delete permanently</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var modalEl = document.getElementById('voidPurgeModal');
    var form = document.getElementById('void-purge-form');
    var across = document.getElementById('void-purge-across-days');
    var submit = document.getElementById('void-purge-submit');
    var modal = function () { return window.bootstrap && window.bootstrap.Modal ? window.bootstrap.Modal.getOrCreateInstance(modalEl) : null; };
    // The popup sits over the page, not over the open form (the form would keep the keyboard focus).
    var popup = function (opts) {
        if (window.Swal) { return Swal.fire(opts); }
        if (opts.showCancelButton) { return Promise.resolve({ isConfirmed: window.confirm(opts.text) }); }
        window.alert((opts.title ? opts.title + '\n\n' : '') + opts.text);
        return Promise.resolve({ isConfirmed: true });
    };
    var firstError = function (data) {
        if (data && data.message && !data.errors) { return data.message; }
        var errors = (data && data.errors) || {};
        for (var k in errors) { if (errors[k] && errors[k][0]) { return errors[k][0]; } }
        return (data && data.message) || 'Nothing was deleted.';
    };

    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.void-purge-open') : null;
        if (!b) { return; }
        form.action = b.getAttribute('data-action');
        document.getElementById('vp-label').textContent = b.getAttribute('data-label') || '';
        document.getElementById('vp-date').textContent = b.getAttribute('data-date') || '';
        document.getElementById('vp-amount').textContent = b.getAttribute('data-amount') || '';
        document.getElementById('void-purge-password').value = '';
        across.value = '0';
        if (modal()) { modal().show(); }
    });

    var send = function () {
        submit.disabled = true;
        return fetch(form.action, {
            method: 'POST', body: new FormData(form), credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) { return { status: res.status, data: data }; });
        }).finally(function () { submit.disabled = false; });
    };

    var handle = function (r) {
        if (r.status === 200 && r.data.ok) {
            if (modal()) { modal().hide(); }
            return popup({ icon: 'success', title: 'Deleted', text: r.data.message }).then(function () {
                window.location.href = r.data.redirect || window.location.href;
            });
        }
        if (modal()) { modal().hide(); }
        if (r.status === 409 && r.data.confirm) {
            // A void dated another day: say which days change, and delete only on a clear yes.
            return popup({ icon: 'warning', title: 'Voided on another day', text: r.data.message, showCancelButton: true,
                confirmButtonText: 'Yes, delete', cancelButtonText: 'Keep it', confirmButtonColor: '#d33' }).then(function (ans) {
                if (!ans.isConfirmed) { return; }
                across.value = '1';
                return send().then(handle);
            });
        }
        var retry = r.status === 422 && (r.data.retry || (r.data.errors && (r.data.errors.purge_reason || r.data.errors.purge_password)));
        return popup({ icon: 'error', title: 'Not deleted', text: firstError(r.data) }).then(function () {
            if (retry && modal()) { document.getElementById('void-purge-password').value = ''; modal().show(); }
        });
    };

    form.addEventListener('submit', function (e) {
        if (!window.fetch || !window.FormData) { return; }   // an old browser posts the form as before
        e.preventDefault();
        across.value = '0';
        send().then(handle).catch(function () {
            if (modal()) { modal().hide(); }
            popup({ icon: 'error', title: 'No answer', text: 'The server did not answer. Refresh the page to see whether the entry is still there.' });
        });
    });
})();
</script>
@endif
