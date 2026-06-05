@extends('layouts.app')
@section('title', 'Print Jobs Report')
@section('content')
<div class="page-header">
    <div class="page-title">
        <h4>Print Jobs</h4>
        <h6>Print job audit trail</h6>
    </div>
    <div class="page-btn">
        <form method="GET">
            <button type="submit" name="export_csv" value="1" class="btn btn-outline-success btn-sm">
                <i class="ti ti-download me-1"></i>CSV
            </button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                    value="{{ request('date_from', today()->subDays(29)->format('Y-m-d')) }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                    value="{{ request('date_to', today()->format('Y-m-d')) }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="print_status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    @foreach($statuses as $s)
                    <option value="{{ $s }}" {{ request('print_status') == $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Document Type</label>
                <select name="document_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    @foreach($documentTypes as $dt)
                    <option value="{{ $dt }}" {{ request('document_type') == $dt ? 'selected' : '' }}>{{ ucfirst($dt) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                <a href="{{ url('/reports/printing/jobs') }}" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <caption class="visually-hidden">Print jobs audit</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">Job No</th>
                    <th scope="col">Document</th>
                    <th scope="col">Ref No</th>
                    <th scope="col">Printer</th>
                    <th scope="col">Branch</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Attempts</th>
                    <th scope="col">Error</th>
                </tr>
            </thead>
            <tbody>
                @forelse($jobs as $j)
                <tr>
                    <td><code class="small">{{ $j->job_no }}</code></td>
                    <td><span class="badge bg-secondary">{{ ucfirst($j->document_type) }}</span></td>
                    <td class="small">{{ $j->reference_no }}</td>
                    <td class="text-muted small">{{ $j->printer?->name ?? '—' }}</td>
                    <td class="text-muted small">{{ $j->branch?->name ?? '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $j->print_status === 'printed' ? 'success' : ($j->print_status === 'failed' ? 'danger' : 'secondary') }}">
                            {{ ucfirst($j->print_status) }}
                        </span>
                    </td>
                    <td class="text-center">{{ $j->attempts }}</td>
                    <td class="text-danger small">{{ $j->error_message ? substr($j->error_message, 0, 40) . '...' : '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No print jobs found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3">{{ $jobs->links() }}</div>
</div>
@endsection
