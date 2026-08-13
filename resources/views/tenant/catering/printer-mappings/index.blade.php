@extends('layouts.app')

@section('title', 'Catering Printer Routing')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Catering Printer Routing</h1>
        <div class="text-muted">Independent of POS KOT routing — changes here never affect normal POS printing.</div>
    </div>
    <div class="d-flex gap-2">
        @can('tenant.catering.printer-mappings.copy-from-pos')
            <form method="POST" action="{{ url('/catering/printer-mappings/copy-from-pos') }}">
                @csrf
                <button class="btn btn-outline-primary" onclick="return confirm('Copy active POS KOT mappings into catering routing? POS mappings are not changed.')">
                    <i class="ti ti-copy me-1"></i>Copy From POS KOT Mappings
                </button>
            </form>
        @endcan
        @can('tenant.catering.printer-mappings.store')
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMappingModal">Add Mapping</button>
        @endcan
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Branch</th>
                    <th>Category</th>
                    <th>Production Station</th>
                    <th>Printer</th>
                    <th>Active</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($mappings as $mapping)
                <tr>
                    <td>{{ $mapping->branch?->name ?? 'All Branches' }}</td>
                    <td>{{ $mapping->category?->name ?? 'All Categories' }}</td>
                    <td>{{ $mapping->production_station ?? '—' }}</td>
                    <td>{{ $mapping->printer?->name }}</td>
                    <td><span class="badge bg-{{ $mapping->is_active ? 'success' : 'secondary' }}">{{ $mapping->is_active ? 'Yes' : 'No' }}</span></td>
                    <td class="text-end">
                        @can('tenant.catering.printer-mappings.destroy')
                            <form method="POST" action="{{ url('/catering/printer-mappings/' . $mapping->id) }}" class="d-inline"
                                  onsubmit="return confirm('Remove this catering mapping?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger">Remove</button>
                            </form>
                        @endcan
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No catering mappings yet. Use “Copy From POS KOT Mappings” to start.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addMappingModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ url('/catering/printer-mappings') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Add Catering Mapping</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Branch</label>
                    <select name="branch_id" class="form-select">
                        <option value="">All Branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-select">
                        <option value="0">All Categories</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Production Station (optional)</label>
                    <input type="text" name="production_station" class="form-control" placeholder="e.g. Rice, BBQ, Curry">
                    <div class="form-text">When set, the mapping routes that station's items regardless of category.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Printer <span class="text-danger">*</span></label>
                    <select name="printer_id" class="form-select" required>
                        @foreach($printers as $printer)
                            <option value="{{ $printer->id }}">{{ $printer->name }} ({{ $printer->printer_type }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="is_active" value="1" id="mapping-active" checked>
                    <label class="form-check-label" for="mapping-active">Active</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Save Mapping</button>
            </div>
        </form>
    </div>
</div>
@endsection
