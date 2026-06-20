{{--
    Reusable branch multi-select filter (reports / filters only).

    Props:
      $branches          — Collection<Branch> with id + name
      $selectedBranchIds — array of selected branch IDs (empty = All branches)
      $colClass          — wrapper column class (default 'col-sm-4')
      $label             — field label (default 'Branch')

    Submits `branch_ids[]`. Empty selection = all branches. Backward compatible
    with screens that previously sent a single `branch_id` (the controller-side
    NormalizesBranchIds trait accepts either).

    Class-based (no hard-coded IDs) so multiple instances can coexist on one page.
--}}
@php
    $colClass = $colClass ?? 'col-sm-4';
    $label    = $label ?? 'Branch';
    $selected = $selectedBranchIds ?? [];
@endphp
<div class="{{ $colClass }} js-branch-filter">
    <label class="form-label mb-1">{{ $label }}</label>
    <select name="branch_ids[]" class="js-branch-multiselect form-select" multiple style="min-height:38px;">
        @foreach($branches as $b)
            <option value="{{ $b->id }}" {{ in_array($b->id, $selected) ? 'selected' : '' }}>{{ $b->name }}</option>
        @endforeach
    </select>
    <div class="d-flex gap-2 mt-1">
        <button type="button" class="btn btn-outline-secondary btn-sm js-branch-select-all">Select All</button>
        <button type="button" class="btn btn-outline-secondary btn-sm js-branch-clear">Clear</button>
    </div>
    <small class="text-muted">Leave empty for all branches.</small>
</div>

@once
@push('scripts')
<script>
(function () {
    var hasSelect2 = (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.select2 !== 'undefined');

    // Init Select2 on every branch multi-select (skip ones already initialised).
    document.querySelectorAll('.js-branch-multiselect').forEach(function (sel) {
        if (hasSelect2 && !sel.classList.contains('select2-hidden-accessible')) {
            window.jQuery(sel).select2({ placeholder: 'All Branches', allowClear: true, width: '100%' });
        }
    });

    function setAll(sel, value) {
        for (var i = 0; i < sel.options.length; i++) { sel.options[i].selected = value; }
        if (hasSelect2 && sel.classList.contains('select2-hidden-accessible')) {
            window.jQuery(sel).trigger('change');
        }
    }

    // Delegated handlers — scoped to the clicked filter's own select.
    document.addEventListener('click', function (e) {
        var allBtn = e.target.closest('.js-branch-select-all');
        var clrBtn = e.target.closest('.js-branch-clear');
        if (!allBtn && !clrBtn) return;
        var wrap = (allBtn || clrBtn).closest('.js-branch-filter');
        if (!wrap) return;
        var sel = wrap.querySelector('.js-branch-multiselect');
        if (sel) setAll(sel, !!allBtn);
    });
})();
</script>
@endpush
@endonce
