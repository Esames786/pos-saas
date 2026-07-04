@extends('layouts.app')

@section('title', 'Branch vs Department Allocation')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Branch vs Department Allocation</h1>
        <p class="fw-medium text-muted mb-0">Official branch stock vs custody handed to departments — the sub-ledger reconciliation view.</p>
    </div>
    <a href="{{ url('/department-stock') }}" class="btn btn-light">Department Stock</a>
</div>

<div class="card border-primary-subtle mb-3">
    <div class="card-body py-2 small">
        <i class="ti ti-info-circle me-1"></i>
        <strong>Unallocated = Official Branch Stock − Allocated to Departments.</strong>
        A negative Unallocated value means departments hold custody of stock the branch no longer officially has — investigate before the next stock count.
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/reports/departments/allocation') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="branch_id" class="form-label">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($filters['branch_id'] == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="only_allocated" value="1" id="only_allocated" @checked($filters['only_allocated'])>
                    <label class="form-check-label" for="only_allocated">Only products with department custody</label>
                </div>
            </div>
            <div class="col-md-1"><button class="btn btn-dark w-100">Filter</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Branch vs department allocation</caption>
            <thead class="table-light">
                <tr>
                    <th>Branch</th><th>SKU</th><th>Product</th>
                    <th class="text-end">Official Branch Stock</th>
                    <th class="text-end">Allocated to Departments</th>
                    <th class="text-end">Unallocated Branch Stock</th>
                </tr>
            </thead>
            <tbody>
            @forelse($report['rows'] as $row)
                <tr>
                    <td>{{ $branches->firstWhere('id', $row['branch_id'])?->name ?? ('#' . $row['branch_id']) }}</td>
                    <td><code>{{ $row['sku'] ?? '—' }}</code></td>
                    <td>{{ $row['product'] }}</td>
                    <td class="text-end">{{ number_format($row['official'], 3) }}</td>
                    <td class="text-end {{ $row['allocated'] > 0 ? 'fw-semibold' : 'text-muted' }}">{{ number_format($row['allocated'], 3) }}</td>
                    <td class="text-end {{ $row['unallocated'] < 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($row['unallocated'], 3) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No stock rows for the selected filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
