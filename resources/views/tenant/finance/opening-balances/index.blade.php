@extends('layouts.app')

@section('title', 'Opening Balances')

@php
    $statusBadge = ['draft' => 'bg-secondary', 'posted' => 'bg-success', 'void' => 'bg-danger'];
@endphp

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Opening Balances</h4>
                <h6>Finance — start your books with existing balances</h6>
            </div>
            <div class="page-btn">
                @can('tenant.finance.opening-balances.create')
                    <a href="{{ url('/finance/opening-balances/create') }}" class="btn btn-primary"><i class="ti ti-circle-plus me-1"></i>New Opening Balance</a>
                @endcan
            </div>
        </div>

        @if(session('status'))
            <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif

        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-md-3">
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="">All statuses</option>
                            @foreach($statuses as $s)
                                <option value="{{ $s }}" {{ ($filters['status'] ?? '') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table">
                        <caption class="visually-hidden">Opening balance batches</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Batch No</th>
                                <th scope="col">Opening Date</th>
                                <th scope="col">Branch</th>
                                <th scope="col">Description</th>
                                <th scope="col" class="text-end">Total Debit</th>
                                <th scope="col" class="text-end">Total Credit</th>
                                <th scope="col">Status</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($batches as $batch)
                                <tr>
                                    <td><a href="{{ url('/finance/opening-balances/' . $batch->id) }}">{{ $batch->batch_no }}</a></td>
                                    <td>{{ optional($batch->opening_date)->format('Y-m-d') }}</td>
                                    <td>{{ $batch->branch->name ?? 'All branches' }}</td>
                                    <td>{{ $batch->description ?: '—' }}</td>
                                    <td class="text-end">{{ number_format((float) $batch->total_debit, 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $batch->total_credit, 2) }}</td>
                                    <td><span class="badge {{ $statusBadge[$batch->status] ?? 'bg-secondary' }}">{{ ucfirst($batch->status) }}</span></td>
                                    <td class="text-end"><a href="{{ url('/finance/opening-balances/' . $batch->id) }}" class="btn btn-sm btn-outline-primary">View</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted py-4">No opening balance batches yet. Create one to set your starting Assets, Liabilities and Owner Capital.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
@endsection
