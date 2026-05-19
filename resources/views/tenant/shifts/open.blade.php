@extends('layouts.app')

@section('title', 'Open Shift')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Open Shift</h1>
        <p class="fw-medium">Select a terminal and enter opening cash.</p>
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
                        <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-5">
                <label for="terminal_id" class="form-label required">Terminal</label>
                <select id="terminal_id" name="terminal_id" class="form-select" required>
                    <option value="">Select terminal…</option>
                    @foreach($terminals as $terminal)
                        <option value="{{ $terminal->id }}"
                            data-branch="{{ $terminal->branch_id }}"
                            @selected(old('terminal_id') == $terminal->id)>
                            {{ $terminal->name }} ({{ $terminal->branch?->name }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4">
                <label for="opening_cash" class="form-label required">Opening Cash</label>
                <input type="number" id="opening_cash" name="opening_cash"
                    value="{{ old('opening_cash', 0) }}"
                    class="form-control" required min="0" step="0.01">
            </div>

            <div class="col-md-8">
                <label for="opening_notes" class="form-label">Opening Notes</label>
                <input type="text" id="opening_notes" name="opening_notes"
                    value="{{ old('opening_notes') }}"
                    class="form-control" maxlength="500">
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-player-play me-1" aria-hidden="true"></i>Open Shift
                </button>
                <a href="{{ url('/shifts') }}" class="btn btn-light ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
