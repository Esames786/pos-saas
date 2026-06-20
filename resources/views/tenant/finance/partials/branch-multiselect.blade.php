{{--
    Branch multi-select partial.
    Props:
      $branches          — Collection<Branch> with id + name
      $selectedBranchIds — array of selected branch IDs (empty = All)
--}}
<div class="col-sm-4">
    <label class="form-label mb-1">Branch</label>
    <select name="branch_ids[]"
            id="branch-multiselect"
            class="form-select"
            multiple
            style="min-height:38px;">
        @foreach($branches as $b)
            <option value="{{ $b->id }}" {{ in_array($b->id, $selectedBranchIds ?? []) ? 'selected' : '' }}>
                {{ $b->name }}
            </option>
        @endforeach
    </select>
    <div class="d-flex gap-2 mt-1">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="branch-select-all">Select All</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="branch-clear">Clear</button>
    </div>
    <small class="text-muted">Leave empty for all branches.</small>
</div>

@push('scripts')
<script>
(function () {
    var sel = document.getElementById('branch-multiselect');
    if (!sel) return;

    // Select2 init if available
    if (typeof $.fn !== 'undefined' && typeof $.fn.select2 !== 'undefined') {
        $(sel).select2({
            placeholder: 'All Branches',
            allowClear: true,
            width: '100%',
        });
    }

    document.getElementById('branch-select-all').addEventListener('click', function () {
        if (typeof $(sel).select2 !== 'undefined') {
            var all = Array.from(sel.options).map(function (o) { return o.value; });
            $(sel).val(all).trigger('change');
        } else {
            for (var i = 0; i < sel.options.length; i++) {
                sel.options[i].selected = true;
            }
        }
    });

    document.getElementById('branch-clear').addEventListener('click', function () {
        if (typeof $(sel).select2 !== 'undefined') {
            $(sel).val(null).trigger('change');
        } else {
            for (var i = 0; i < sel.options.length; i++) {
                sel.options[i].selected = false;
            }
        }
    });
})();
</script>
@endpush
