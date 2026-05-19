@extends('layouts.app')

@section('title', 'KOT Routing')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">KOT Routing</h1>
    @can('tenant.printing.category-mappings.store')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMappingModal">Add Mapping</button>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body pb-0">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                <select name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($selectedBranchId == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-light">Filter</button>
                <a href="{{ url('/printing/category-mappings') }}" class="btn btn-light">Clear</a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Branch</th>
                    <th>Category</th>
                    <th>Printer</th>
                    <th>Role</th>
                    <th>Active</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($mappings as $m)
                <tr>
                    <td>{{ $m->branch?->name }}</td>
                    <td>{{ $m->category?->name }}</td>
                    <td>{{ $m->printer?->name }}</td>
                    <td>{{ ucfirst($m->print_role) }}</td>
                    <td>
                        <span class="badge bg-{{ $m->is_active ? 'success' : 'secondary' }}">
                            {{ $m->is_active ? 'Yes' : 'No' }}
                        </span>
                    </td>
                    <td class="text-end">
                        @can('tenant.printing.category-mappings.destroy')
                            <form method="POST"
                                  action="{{ url('/printing/category-mappings/' . $m->id) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Remove this mapping?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger">Remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No mappings configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('tenant.printing.category-mappings.store')
<div class="modal fade" id="addMappingModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ url('/printing/category-mappings') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Add KOT Routing</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-md-6">
                    <label class="form-label required">Branch</label>
                    <select name="branch_id" class="form-select" required>
                        <option value="">— Select —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label required">Category</label>
                    <select name="category_id" class="form-select" required>
                        <option value="">— Select —</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}" @selected(old('category_id') == $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label required">Printer</label>
                    <select name="printer_id" class="form-select" required>
                        <option value="">— Select —</option>
                        @foreach($printers as $p)
                            <option value="{{ $p->id }}" @selected(old('printer_id') == $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label required">Role</label>
                    <select name="print_role" class="form-select" required>
                        <option value="kot" @selected(old('print_role') === 'kot')>KOT</option>
                        <option value="receipt" @selected(old('print_role') === 'receipt')>Receipt</option>
                    </select>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
                        <label class="form-check-label">Active</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary">Add Mapping</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
