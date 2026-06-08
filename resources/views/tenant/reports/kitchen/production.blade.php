@extends('layouts.app')
@section('title', 'Kitchen Production Report')
@section('content')
<div class="page-header">
    <div class="page-title">
        <h4>Production Batches</h4>
        <h6>Kitchen batch production history</h6>
    </div>
</div>

@include('tenant.reports.partials.filters', ['showTerminal' => false, 'showOrderType' => false, 'showCsvExport' => false])

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <caption class="visually-hidden">Production batches</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Branch</th>
                    <th scope="col">Product</th>
                    <th scope="col" class="text-end">Qty Produced</th>
                    <th scope="col">Status</th>
                    <th scope="col">Produced By</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $p)
                <tr>
                    <td>{{ $p->production_date->format('d/m/Y') }}</td>
                    <td>{{ $p->branch?->name }}</td>
                    <td>{{ $p->recipe?->product?->name ?? '—' }}</td>
                    <td class="text-end">{{ number_format($p->quantity_produced, 3) }}</td>
                    <td><span class="badge bg-secondary">{{ ucfirst(str_replace('_', ' ', $p->status)) }}</span></td>
                    <td class="text-muted small">{{ $p->producedBy?->name ?? '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No production batches.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3">{{ $rows->links() }}</div>
</div>
@endsection
