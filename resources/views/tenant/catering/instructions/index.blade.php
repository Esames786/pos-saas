@extends('layouts.app')

@section('title', 'Kitchen Instructions')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Kitchen Instructions</h1>
        <div class="text-muted">
            The managed vocabulary a booking line selects from — one spelling per
            instruction, so the kitchen never receives four versions of "mirch kam".
        </div>
    </div>
</div>

@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.screen-impact', ['manages' => 'The kitchen instruction vocabulary bookings select from.', 'managesUr' => 'ہدایات کی فہرست جو بکنگ میں منتخب ہوتی ہے۔', 'reversible' => 'safe', 'note' => 'Vocabulary only — nothing here touches money, stock or existing bookings. Deactivating an entry hides it from new selection; lines already carrying it keep it.', 'noteUr' => 'صرف الفاظ کی فہرست — رقم یا سٹاک پر کوئی اثر نہیں۔'])

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

@can('tenant.catering.instructions.store')
<div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">Add Instruction</h5></div>
    <div class="card-body">
        <form method="POST" action="{{ url('/catering/instructions') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-4">
                <label class="form-label">Label (Roman Urdu)</label>
                <input type="text" name="label" class="form-control" maxlength="120" required
                       placeholder="e.g. Mirch Kam" value="{{ old('label') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label">Urdu (printed on the kitchen sheet)</label>
                <input type="text" name="label_ur" dir="rtl" lang="ur" class="form-control" maxlength="120"
                       placeholder="مرچ کم" value="{{ old('label_ur') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label">Sort</label>
                <input type="number" name="sort_order" class="form-control" min="0" value="{{ old('sort_order', 0) }}">
            </div>
            <div class="col-auto">
                <button class="btn btn-primary">Add</button>
            </div>
        </form>
    </div>
</div>
@endcan

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th class="ps-3">Label</th>
                    <th>Urdu</th>
                    <th style="width:90px">Sort</th>
                    <th style="width:110px">Status</th>
                    <th style="width:280px"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($instructions as $instruction)
                <tr class="{{ $instruction->is_active ? '' : 'opacity-50' }}">
                    @can('tenant.catering.instructions.update')
                    {{-- A <form> tag may not sit inside a <tr>; the controls join
                         the per-row form (rendered after the table) by form=. --}}
                        <td class="ps-3">
                            <input type="text" name="label" form="instr-{{ $instruction->id }}"
                                   class="form-control form-control-sm" maxlength="120"
                                   value="{{ $instruction->label }}" required>
                        </td>
                        <td>
                            <input type="text" name="label_ur" form="instr-{{ $instruction->id }}"
                                   dir="rtl" lang="ur" class="form-control form-control-sm"
                                   maxlength="120" value="{{ $instruction->label_ur }}">
                        </td>
                        <td>
                            <input type="number" name="sort_order" form="instr-{{ $instruction->id }}"
                                   class="form-control form-control-sm"
                                   min="0" value="{{ $instruction->sort_order }}">
                        </td>
                        <td>
                            <span class="badge bg-{{ $instruction->is_active ? 'success' : 'secondary' }}">
                                {{ $instruction->is_active ? 'Active' : 'Hidden' }}
                            </span>
                        </td>
                        <td class="text-end pe-3">
                            <button class="btn btn-sm btn-outline-primary" form="instr-{{ $instruction->id }}"
                                    name="is_active" value="{{ $instruction->is_active ? 1 : 0 }}">Save</button>
                            <button class="btn btn-sm btn-outline-secondary" type="submit"
                                    form="instr-{{ $instruction->id }}"
                                    name="is_active" value="{{ $instruction->is_active ? 0 : 1 }}"
                                    title="{{ $instruction->is_active
                                        ? 'Hide from new selection — lines already carrying it keep it'
                                        : 'Offer it for selection again' }}">
                                {{ $instruction->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </td>
                    @else
                    <td class="ps-3">{{ $instruction->label }}</td>
                    <td dir="rtl" lang="ur">{{ $instruction->label_ur }}</td>
                    <td>{{ $instruction->sort_order }}</td>
                    <td>
                        <span class="badge bg-{{ $instruction->is_active ? 'success' : 'secondary' }}">
                            {{ $instruction->is_active ? 'Active' : 'Hidden' }}
                        </span>
                    </td>
                    <td></td>
                    @endcan
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        No instructions recorded yet. The authoritative list comes from your
                        old system's export — add entries here as you confirm them, rather
                        than inventing spellings.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('tenant.catering.instructions.update')
    {{-- The per-row edit forms the table's controls belong to (via form=). --}}
    @foreach($instructions as $instruction)
        <form id="instr-{{ $instruction->id }}" method="POST"
              action="{{ url('/catering/instructions/' . $instruction->id) }}">
            @csrf @method('PUT')
        </form>
    @endforeach
@endcan
@endsection
