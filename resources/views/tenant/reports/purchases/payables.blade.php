@extends('layouts.app')
@section('title', 'Supplier Payables Aging')
@section('content')
<div class="page-header">
    <div class="page-title">
        <h4>Supplier Payables Aging</h4>
        <h6>Outstanding supplier bills by age (as of {{ $asOf }})</h6>
    </div>
    <div class="page-btn">
        <form method="GET" class="d-inline">
            @foreach($filters as $k => $val)
                @if(!is_array($val) && $val !== null && $val !== '')<input type="hidden" name="{{ $k }}" value="{{ $val }}">@endif
            @endforeach
            @foreach($selectedBranchIds ?? [] as $bid)
                <input type="hidden" name="branch_ids[]" value="{{ $bid }}">
            @endforeach
            <button type="submit" name="export_csv" value="1" class="btn btn-outline-success btn-sm">
                <i class="ti ti-download me-1"></i>CSV
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-3">
                <label class="form-label mb-1">Supplier</label>
                <select name="supplier_id" class="form-select">
                    <option value="">All suppliers</option>
                    @foreach($suppliers as $s)
                        <option value="{{ $s->id }}" {{ (string)($filters['supplier_id'] ?? '') === (string)$s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            @include('tenant.partials.branch-multiselect', [
                'branches'          => $branches,
                'selectedBranchIds' => $selectedBranchIds ?? [],
                'colClass'          => 'col-sm-3',
            ])
            <div class="col-sm-2">
                <label class="form-label mb-1">Status</label>
                <select name="status" class="form-select">
                    @foreach(['all' => 'All', 'unpaid' => 'Unpaid', 'partial' => 'Partial'] as $v => $l)
                        <option value="{{ $v }}" {{ ($filters['status'] ?? 'all') === $v ? 'selected' : '' }}>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label mb-1">As of</label>
                <input type="date" name="as_of_date" class="form-control" value="{{ $filters['as_of_date'] ?? '' }}">
            </div>
            <div class="col-sm-2">
                <button type="submit" class="btn btn-primary w-100">Apply</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <caption class="visually-hidden">Supplier payables aging</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Supplier</th>
                        <th scope="col" class="text-end">Current</th>
                        <th scope="col" class="text-end">1–30</th>
                        <th scope="col" class="text-end">31–60</th>
                        <th scope="col" class="text-end">61–90</th>
                        <th scope="col" class="text-end">90+</th>
                        <th scope="col" class="text-end">Total Due</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                    <tr>
                        <td>{{ $r['supplier_name'] }}</td>
                        <td class="text-end">{{ number_format($r['current'], 2) }}</td>
                        <td class="text-end">{{ number_format($r['d1_30'], 2) }}</td>
                        <td class="text-end">{{ number_format($r['d31_60'], 2) }}</td>
                        <td class="text-end">{{ number_format($r['d61_90'], 2) }}</td>
                        <td class="text-end {{ $r['d90_plus'] > 0 ? 'text-danger fw-semibold' : '' }}">{{ number_format($r['d90_plus'], 2) }}</td>
                        <td class="text-end fw-semibold text-warning">{{ number_format($r['total'], 2) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No outstanding payables.</td></tr>
                    @endforelse
                </tbody>
                @if(count($rows))
                <tfoot class="table-light">
                    <tr>
                        <th>Total</th>
                        <th class="text-end">{{ number_format($totals['current'], 2) }}</th>
                        <th class="text-end">{{ number_format($totals['d1_30'], 2) }}</th>
                        <th class="text-end">{{ number_format($totals['d31_60'], 2) }}</th>
                        <th class="text-end">{{ number_format($totals['d61_90'], 2) }}</th>
                        <th class="text-end">{{ number_format($totals['d90_plus'], 2) }}</th>
                        <th class="text-end">{{ number_format($totals['total'], 2) }}</th>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
@endsection
