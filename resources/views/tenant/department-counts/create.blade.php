@extends('layouts.app')

@section('title', 'New Department Count')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">New Department Count</h1>
        <p class="fw-medium text-muted mb-0">Creates a draft with expected quantities loaded from current custody stock.</p>
    </div>
    <a href="{{ url('/department-counts') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/department-counts') }}" novalidate>
    @csrf
    <div class="card">
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label for="branch_id" class="form-label required">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select" required>
                    <option value="">— Select —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="department_id" class="form-label required">Department</label>
                <select id="department_id" name="department_id" class="form-select" required>
                    <option value="">— Select —</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" data-branch="{{ $dept->branch_id }}" @selected(old('department_id') == $dept->id)>{{ $dept->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="count_date" class="form-label required">Count Date</label>
                <input id="count_date" type="date" name="count_date" class="form-control" required value="{{ old('count_date', now()->toDateString()) }}">
            </div>
            <div class="col-md-3">
                <label for="notes" class="form-label">Notes</label>
                <input id="notes" name="notes" class="form-control" value="{{ old('notes') }}">
            </div>
            <div class="col-12">
                <button class="btn btn-primary">Create Draft Count</button>
                <a href="{{ url('/department-counts') }}" class="btn btn-light ms-2">Cancel</a>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
(function () {
    var branch = document.getElementById('branch_id');
    var dept = document.getElementById('department_id');
    function filterDepts() {
        Array.prototype.forEach.call(dept.options, function (opt) {
            if (!opt.value) return;
            var show = !branch.value || opt.getAttribute('data-branch') === branch.value;
            opt.hidden = !show;
            if (!show && opt.selected) dept.value = '';
        });
    }
    branch.addEventListener('change', filterDepts);
    filterDepts();
})();
</script>
@endpush
@endsection
