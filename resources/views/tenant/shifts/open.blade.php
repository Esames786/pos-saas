@extends('layouts.app')

@section('title', 'Open Shift')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Open Shift</h1>
        <p class="fw-medium">Pick a branch — all its terminals are selected by default. Deselect any you don't need.</p>
    </div>
    <a href="{{ url('/shifts') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ url('/shifts/open') }}" class="row g-3" novalidate>
            @csrf

            <div class="col-md-5">
                <label for="branch_id" class="form-label required">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select" required>
                    <option value="">Select branch…</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4">
                <label for="opening_cash" class="form-label required">Opening Cash <span class="text-muted small">(applied to each terminal)</span></label>
                <input type="number" id="opening_cash" name="opening_cash" value="{{ old('opening_cash', 0) }}"
                    class="form-control" required min="0" step="0.01">
            </div>

            <div class="col-12">
                <label class="form-label required d-flex justify-content-between align-items-center">
                    <span>Terminals</span>
                    <span class="small">
                        <a href="javascript:void(0)" id="term-all">Select all</a> ·
                        <a href="javascript:void(0)" id="term-none">Select none</a>
                    </span>
                </label>
                <div id="terminal-list" class="border rounded p-2">
                    <div class="text-muted small p-2" id="terminal-empty">Select a branch to list its terminals.</div>
                    @foreach($terminals as $terminal)
                        <div class="terminal-row d-flex align-items-center gap-2 py-1" data-branch="{{ $terminal->branch_id }}" style="display:none">
                            <div class="form-check mb-0 flex-grow-1">
                                <input class="form-check-input term-check" type="checkbox" name="terminal_ids[]"
                                    value="{{ $terminal->id }}" id="term-{{ $terminal->id }}" disabled>
                                <label class="form-check-label" for="term-{{ $terminal->id }}">{{ $terminal->name }}</label>
                            </div>
                            <div class="input-group input-group-sm" style="max-width:220px">
                                <span class="input-group-text">Override cash</span>
                                <input type="number" min="0" step="0.01" class="form-control"
                                    name="terminal_opening_cash[{{ $terminal->id }}]"
                                    value="{{ old('terminal_opening_cash.' . $terminal->id) }}"
                                    placeholder="(shared)" disabled>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="form-text">Leave “Override cash” empty to use the shared opening cash for that terminal.</div>
            </div>

            <div class="col-12">
                <label for="opening_notes" class="form-label">Opening Notes</label>
                <input type="text" id="opening_notes" name="opening_notes" value="{{ old('opening_notes') }}" class="form-control" maxlength="500">
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-player-play me-1" aria-hidden="true"></i>Open Shift(s)
                </button>
                <a href="{{ url('/shifts') }}" class="btn btn-light ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var branchEl = document.getElementById('branch_id');
    var rows     = Array.prototype.slice.call(document.querySelectorAll('.terminal-row'));
    var empty    = document.getElementById('terminal-empty');
    var oldBranch = @json((string) old('branch_id', ''));
    var hadOld    = @json($errors->any() || old('branch_id') !== null);

    function apply() {
        var b = branchEl.value;
        var anyVisible = false;
        rows.forEach(function (row) {
            var mine = row.getAttribute('data-branch') === b && b !== '';
            row.style.display = mine ? '' : 'none';
            var chk = row.querySelector('.term-check');
            var ov  = row.querySelector('input[type="number"]');
            chk.disabled = !mine;                 // disabled inputs are not submitted
            ov.disabled  = !mine;
            if (mine) {
                anyVisible = true;
                // Default: all terminals of the branch checked (unless returning from a validation
                // error where the user had explicitly unchecked some).
                if (!hadOld) chk.checked = true;
            } else {
                chk.checked = false;
            }
        });
        empty.style.display = anyVisible ? 'none' : '';
    }

    branchEl.addEventListener('change', function () { hadOld = false; apply(); });
    document.getElementById('term-all').addEventListener('click', function () {
        rows.forEach(function (r) { if (r.style.display !== 'none') r.querySelector('.term-check').checked = true; });
    });
    document.getElementById('term-none').addEventListener('click', function () {
        rows.forEach(function (r) { if (r.style.display !== 'none') r.querySelector('.term-check').checked = false; });
    });

    if (oldBranch) { branchEl.value = oldBranch; }
    apply();
})();
</script>
@endpush
@endsection
