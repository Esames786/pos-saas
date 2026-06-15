@extends('layouts.app')

@section('title', 'Journal Entries')

@php $statusBadge = ['draft' => 'bg-secondary', 'posted' => 'bg-success', 'void' => 'bg-danger']; @endphp

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Journal Entries</h4>
                <h6>Double-entry general ledger journals</h6>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-sm-2">
                        <label class="form-label mb-1">From</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label mb-1">To</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label mb-1">Source</label>
                        <select name="source_type" class="form-select">
                            <option value="">All sources</option>
                            @foreach($sourceTypes as $st)
                                <option value="{{ $st }}" {{ ($filters['source_type'] ?? '') === $st ? 'selected' : '' }}>{{ str_replace('_', ' ', $st) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label mb-1">Search</label>
                        <input type="text" name="q" class="form-control" placeholder="Entry / source / description" value="{{ $filters['q'] ?? '' }}">
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Journal entries</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Entry #</th>
                                <th scope="col">Date</th>
                                <th scope="col">Source</th>
                                <th scope="col">Source #</th>
                                <th scope="col">Description</th>
                                <th scope="col" class="text-end">Debit</th>
                                <th scope="col" class="text-end">Credit</th>
                                <th scope="col">Status</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($entries as $e)
                            <tr>
                                <td><a href="{{ url('/finance/journal-entries/' . $e->id) }}" class="fw-semibold">{{ $e->entry_no }}</a>@if($e->is_reversal)<span class="badge bg-warning text-dark ms-1">Reversal</span>@endif</td>
                                <td>{{ optional($e->entry_date)->format('Y-m-d') }}</td>
                                <td>{{ str_replace('_', ' ', $e->source_type ?? '—') }}</td>
                                <td class="text-muted">{{ $e->source_no ?: '—' }}</td>
                                <td class="text-muted">{{ $e->description }}</td>
                                <td class="text-end">{{ number_format((float) $e->total_debit, 2) }}</td>
                                <td class="text-end">{{ number_format((float) $e->total_credit, 2) }}</td>
                                <td><span class="badge {{ $statusBadge[$e->status] ?? 'bg-secondary' }}">{{ ucfirst($e->status) }}</span></td>
                                <td><a href="{{ url('/finance/journal-entries/' . $e->id) }}" class="btn btn-sm btn-outline-secondary"><i class="ti ti-eye"></i></a></td>
                            </tr>
                            @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">No journal entries found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
@endsection
