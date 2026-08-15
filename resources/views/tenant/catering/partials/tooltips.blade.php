{{--
    KASHIF-UAT-2 — Bootstrap tooltips are opt-in and nothing in this application
    initialised them, so every `title` fell back to the browser's own slow, plain
    tooltip. Catering actions post to the ledger and move stock, so the operator
    must be able to read the consequence BEFORE clicking, not a second later.

    Scoped deliberately: only elements that explicitly carry
    data-bs-toggle="tooltip" are touched, and only on screens that include this
    partial. No other module's markup is affected.
--}}
@once
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof bootstrap === 'undefined' || ! bootstrap.Tooltip) {
        return; // native title attribute still works as the fallback
    }

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        bootstrap.Tooltip.getOrCreateInstance(el, {
            placement: 'bottom',
            trigger: 'hover focus',
            container: 'body',
        });
    });

    // A tooltip anchored to a button inside a form survives the click and is
    // left orphaned on screen after submit; dispose it on the way out.
    document.addEventListener('submit', function () {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            bootstrap.Tooltip.getInstance(el)?.hide();
        });
    }, true);
});
</script>
@endpush
@endonce
