{{-- VOID-PURGE-1: one confirmation for every "Delete permanently" button on the page. Shows what goes, asks why and
     the password again; the form goes back to where it came from. --}}
@if(\App\Support\TenantFeatures::on(\App\Support\TenantFeatures::VOID_PURGE) && auth()->user()?->can('tenant.finance.voided-entries.purge'))
<div class="modal fade" id="voidPurgeModal" tabindex="-1" aria-labelledby="voidPurgeTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" id="void-purge-form">
                @csrf
                <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
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
                        journals and reports. Totals stay exactly the same. This cannot be undone; a hidden copy is kept for the
                        Owner in Finance → Deleted entries.
                    </div>
                    <label for="void-purge-reason" class="form-label">Why is it being deleted? <span class="text-danger">*</span></label>
                    <textarea name="purge_reason" id="void-purge-reason" class="form-control mb-2" rows="2" required minlength="5" maxlength="500"
                        placeholder="e.g. entered by mistake, a test entry">{{ old('purge_reason') }}</textarea>
                    <label for="void-purge-password" class="form-label">Your password <span class="text-danger">*</span></label>
                    <input type="password" name="purge_password" id="void-purge-password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete permanently</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.void-purge-open') : null;
    if (!b) { return; }
    var form = document.getElementById('void-purge-form');
    form.action = b.getAttribute('data-action');
    document.getElementById('vp-label').textContent = b.getAttribute('data-label') || '';
    document.getElementById('vp-date').textContent = b.getAttribute('data-date') || '';
    document.getElementById('vp-amount').textContent = b.getAttribute('data-amount') || '';
    document.getElementById('void-purge-password').value = '';
    if (window.bootstrap && window.bootstrap.Modal) { window.bootstrap.Modal.getOrCreateInstance(document.getElementById('voidPurgeModal')).show(); }
});
</script>
@endif
