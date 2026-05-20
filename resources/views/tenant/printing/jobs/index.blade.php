@extends('layouts.app')

@section('title', 'Print Jobs')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Print Jobs</h1>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body pb-0">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-3">
                <select name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="document_type" class="form-select">
                    <option value="">All Types</option>
                    <option value="receipt" @selected(request('document_type') === 'receipt')>Receipt</option>
                    <option value="kot" @selected(request('document_type') === 'kot')>KOT</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="print_status" class="form-select">
                    <option value="">All Statuses</option>
                    @foreach(['queued', 'printed', 'failed', 'cancelled'] as $s)
                        <option value="{{ $s }}" @selected(request('print_status') === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-light">Filter</button>
                <a href="{{ url('/printing/jobs') }}" class="btn btn-light">Clear</a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Job No</th>
                    <th>Type</th>
                    <th>Reference</th>
                    <th>Printer</th>
                    <th>Agent</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Created By</th>
                    <th>Created At</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($jobs as $j)
                <tr>
                    <td><code>{{ $j->job_no }}</code></td>
                    <td>{{ ucfirst($j->document_type) }}</td>
                    <td>
                        @if($j->reference_no)
                            <a href="{{ url('/sales-orders?q=' . $j->reference_no) }}">{{ $j->reference_no }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $j->printer?->name ?? '—' }}</td>
                    <td>
                        {{ $j->claimedByAgent?->name ?? '—' }}
                        @if($j->claimed_at)
                            <small class="d-block text-muted">{{ $j->claimed_at->format('H:i:s') }}</small>
                        @endif
                    </td>
                    <td>
                        @php
                            $statusColor = match($j->print_status) {
                                'queued'    => 'warning',
                                'printed'   => 'success',
                                'failed'    => 'danger',
                                'cancelled' => 'secondary',
                                default     => 'light',
                            };
                        @endphp
                        <span class="badge bg-{{ $statusColor }}">{{ ucfirst($j->print_status) }}</span>
                    </td>
                    <td>{{ $j->attempts }}</td>
                    <td>{{ $j->createdBy?->name ?? '—' }}</td>
                    <td>{{ $j->created_at?->format('d M Y H:i') }}</td>
                    <td class="text-end d-flex gap-1 justify-content-end">
                        <a href="{{ url('/printing/documents/' . $j->id . '/preview') }}"
                           target="_blank" class="btn btn-sm btn-light">View</a>
                        @if($j->print_status === 'queued')
                            @can('tenant.printing.jobs.mark-printed')
                                <form method="POST" action="{{ url('/printing/jobs/' . $j->id . '/mark-printed') }}">
                                    @csrf
                                    <button class="btn btn-sm btn-success">Printed</button>
                                </form>
                            @endcan
                        @endif
                        @if(in_array($j->print_status, ['failed', 'cancelled']))
                            @can('tenant.printing.jobs.retry')
                                <form method="POST" action="{{ url('/printing/jobs/' . $j->id . '/retry') }}">
                                    @csrf
                                    <button class="btn btn-sm btn-warning">Retry</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="10" class="text-center text-muted py-4">No print jobs found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $jobs->links() }}
@endsection
