@extends('layouts.app')

@section('title', 'Unit Conversions')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Unit Conversions</h1>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><strong>Add Conversion</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ url('/unit-conversions') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label required">From Unit</label>
                        <select name="from_unit_id" class="form-select" required>
                            <option value="">— Select —</option>
                            @foreach($units as $unit)
                                <option value="{{ $unit->id }}" @selected(old('from_unit_id') == $unit->id)>
                                    {{ $unit->name }} ({{ $unit->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">To Unit</label>
                        <select name="to_unit_id" class="form-select" required>
                            <option value="">— Select —</option>
                            @foreach($units as $unit)
                                <option value="{{ $unit->id }}" @selected(old('to_unit_id') == $unit->id)>
                                    {{ $unit->name }} ({{ $unit->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Factor</label>
                        <input type="number" name="factor" step="0.00000001" min="0.00000001"
                               value="{{ old('factor') }}" class="form-control" required>
                        <div class="form-help">1 [From Unit] = factor [To Unit]</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Add</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Factor</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($conversions as $conv)
                        <tr>
                            <td>{{ $conv->fromUnit?->name }} ({{ $conv->fromUnit?->code }})</td>
                            <td>{{ $conv->toUnit?->name }} ({{ $conv->toUnit?->code }})</td>
                            <td>{{ $conv->factor }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-light"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editModal{{ $conv->id }}">Edit</button>
                                <form method="POST" action="{{ url('/unit-conversions/' . $conv->id) }}"
                                      class="d-inline"
                                      onsubmit="return confirm('Delete this conversion?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>

                        {{-- Edit Modal --}}
                        <div class="modal fade" id="editModal{{ $conv->id }}" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST" action="{{ url('/unit-conversions/' . $conv->id) }}">
                                        @csrf @method('PUT')
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Conversion</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p class="mb-2 text-muted">
                                                {{ $conv->fromUnit?->name }} → {{ $conv->toUnit?->name }}
                                            </p>
                                            <label class="form-label required">Factor</label>
                                            <input type="number" name="factor" step="0.00000001" min="0.00000001"
                                                   value="{{ $conv->factor }}" class="form-control" required>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Save</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No conversions defined.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ $conversions->links() }}
    </div>
</div>
@endsection
