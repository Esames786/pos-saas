@extends('layouts.app')

@section('title', 'Receipt / KOT Layouts')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Receipt &amp; KOT Layouts</h1>
    @can('tenant.printing.layouts.store')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLayoutModal">Add Layout</button>
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
                <a href="{{ url('/printing/layouts') }}" class="btn btn-light">Clear</a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Branch</th>
                    <th>Type</th>
                    <th>Paper</th>
                    <th>Font</th>
                    <th>Header</th>
                    <th>Footer</th>
                    <th>Active</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($layouts as $l)
                <tr>
                    <td>{{ $l->branch?->name }}</td>
                    <td>{{ ucfirst($l->document_type) }}</td>
                    <td>{{ $l->paper_size }}</td>
                    <td>{{ $l->font_size }}px</td>
                    <td>{{ $l->header_text ? Str::limit($l->header_text, 30) : '—' }}</td>
                    <td>{{ $l->footer_text ? Str::limit($l->footer_text, 30) : '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $l->is_active ? 'success' : 'secondary' }}">
                            {{ $l->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="text-end">
                        @can('tenant.printing.layouts.store')
                            <button class="btn btn-sm btn-light"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editLayoutModal{{ $l->id }}">Edit</button>
                        @endcan
                    </td>
                </tr>

                {{-- Edit modal --}}
                @can('tenant.printing.layouts.store')
                <div class="modal fade" id="editLayoutModal{{ $l->id }}" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <form method="POST" action="{{ url('/printing/layouts') }}" class="modal-content" enctype="multipart/form-data">
                            @csrf
                            <div class="modal-header">
                                <h5 class="modal-title">Edit Layout — {{ $l->branch?->name }} ({{ ucfirst($l->document_type) }})</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body row g-3">
                                <input type="hidden" name="branch_id" value="{{ $l->branch_id }}">
                                <input type="hidden" name="document_type" value="{{ $l->document_type }}">
                                @include('tenant.printing.layouts._form', ['layout' => $l])
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-primary">Save</button>
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
                @endcan
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No layouts configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('tenant.printing.layouts.store')
<div class="modal fade" id="addLayoutModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="{{ url('/printing/layouts') }}" class="modal-content" enctype="multipart/form-data">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Add Layout</h5>
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
                    <label class="form-label required">Document Type</label>
                    <select name="document_type" class="form-select" required>
                        <option value="receipt" @selected(old('document_type') === 'receipt')>Receipt</option>
                        <option value="kot" @selected(old('document_type') === 'kot')>KOT</option>
                    </select>
                </div>
                @include('tenant.printing.layouts._form')
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary">Add Layout</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
