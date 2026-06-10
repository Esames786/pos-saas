@extends('layouts.app')

@section('title', 'Stock Counts')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Stock Counts</h1>
        <p class="text-muted mb-0">Physical inventory count sessions</p>
    </div>
    @can('tenant.stock-counts.create')
        <a href="{{ url('/stock-counts/create') }}" class="btn btn-primary">New Stock Count</a>
    @endcan
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Count No</th>
                    <th>Branch</th>
                    <th>Status</th>
                    <th>Lines</th>
                    <th>Started</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($sessions as $session)
                <tr>
                    <td><strong>{{ $session->count_no }}</strong></td>
                    <td>{{ $session->branch?->name }}</td>
                    <td>
                        @php
                            $badge = match($session->status) {
                                'counting'  => 'warning',
                                'review'    => 'info',
                                'posted'    => 'success',
                                'cancelled' => 'secondary',
                                default     => 'secondary',
                            };
                        @endphp
                        <span class="badge bg-{{ $badge }}">{{ ucfirst($session->status) }}</span>
                    </td>
                    <td>{{ $session->lines_count }}</td>
                    <td class="text-muted small">{{ $session->started_at?->format('d/m/Y H:i') }}</td>
                    <td class="text-end">
                        <a href="{{ url('/stock-counts/' . $session->id) }}" class="btn btn-sm btn-light">Open</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No stock counts yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($sessions->hasPages())
        <div class="p-3">{{ $sessions->links() }}</div>
    @endif
</div>
@endsection
