@extends('layouts.app')

@section('title', 'Production Release ' . $release->release_no)

@section('content')
@php $snapshot = $release->event_snapshot; @endphp
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $release->release_no }}
            <span class="badge bg-{{ $release->status === 'released' ? 'success' : 'danger' }} align-middle fs-12">{{ ucfirst($release->status) }}</span>
        </h1>
        <div class="text-muted">
            Event <a href="{{ url('/catering/events/' . $release->catering_event_id) }}">{{ $snapshot['event_no'] ?? '' }}</a>
            · Released {{ $release->released_at->format('d M Y g:i A') }}
            · Immutable snapshot — no stock was moved.
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ url('/catering/events/' . $release->catering_event_id) }}" class="btn btn-light">Back to Event</a>
        @if($release->status === 'released')
            @can('tenant.catering.production-releases.print')
                <form method="POST" action="{{ url('/catering/production-releases/' . $release->id . '/print') }}">
                    @csrf
                    <button class="btn btn-success"
                            onclick="return confirm('Send production tickets to the mapped catering printers? Safe to retry — one job per printer, no duplicates.')">
                        <i class="ti ti-printer me-1"></i>Send to Kitchen Printers
                    </button>
                </form>
            @endcan
            @can('tenant.catering.production-releases.reprint')
                @if(($printJobs ?? collect())->isNotEmpty())
                    <form method="POST" action="{{ url('/catering/production-releases/' . $release->id . '/reprint') }}">
                        @csrf
                        <button class="btn btn-outline-success" onclick="return confirm('Queue a NEW physical copy on every mapped printer?')">Reprint Copy</button>
                    </form>
                @endif
            @endcan
        @endif
        @can('tenant.catering.documents.kitchen-sheet')
            <div class="btn-group">
                <a target="_blank" href="{{ url('/catering/documents/kitchen-sheet/' . $release->id . '?lang=en') }}" class="btn btn-primary">Kitchen Sheet (EN)</a>
                <a target="_blank" href="{{ url('/catering/documents/kitchen-sheet/' . $release->id . '?lang=ur') }}" class="btn btn-outline-primary">اردو</a>
                <a target="_blank" href="{{ url('/catering/documents/kitchen-sheet/' . $release->id . '?lang=both') }}" class="btn btn-outline-primary">Both</a>
            </div>
        @endcan
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

@if(($printJobs ?? collect())->isNotEmpty())
<div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">Kitchen Print Jobs <span class="text-muted fs-12">(English thermal text — Urdu stays on the A4 sheet)</span></h5></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>Job</th><th>Printer</th><th>Copy</th><th>Status</th><th>Printed / Failed</th></tr></thead>
            <tbody>
                @foreach($printJobs as $job)
                <tr>
                    <td>{{ $job->job_no }}</td>
                    <td>{{ $job->printer?->name }}</td>
                    <td>#{{ $job->copy_no }}</td>
                    <td><span class="badge bg-{{ ['printed' => 'success', 'queued' => 'info', 'failed' => 'danger', 'cancelled' => 'secondary'][$job->print_status] ?? 'secondary' }}">{{ ucfirst($job->print_status) }}</span></td>
                    <td class="text-muted fs-12">{{ $job->printed_at?->format('d M g:i A') ?? $job->failed_at?->format('d M g:i A') ?? '—' }}{{ $job->error_message ? ' · '.$job->error_message : '' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">Items (no pricing on production documents)</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th class="text-end">Qty</th><th>Item</th><th>Urdu</th><th>Station</th><th>Instructions</th></tr></thead>
                <tbody>
                    @foreach($release->lines as $line)
                    <tr>
                        <td class="text-end fw-bold">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }} {{ $line->unit_code }}</td>
                        <td>{{ $line->item_name }}</td>
                        <td dir="rtl" lang="ur">{{ $line->item_name_ur }}</td>
                        <td>{{ $line->production_station ?? '—' }}</td>
                        <td>{{ $line->instructions }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@php
    $requirements = $release->requirements_snapshot['requirements'] ?? [];
    $issue = $materialIssue ?? null;
    $issuedByProduct = $issue
        ? $issue->lines->keyBy('product_id')
        : collect();
@endphp
@if(!empty($requirements))
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <h5 class="mb-0">Raw Material Requirements
                @if(! $issue)
                    <span class="badge bg-secondary">Plan only — no stock has moved</span>
                @else
                    <span class="badge bg-success">Issued as {{ $issue->issue_no }}</span>
                @endif
            </h5>
            <div class="text-muted fs-12">Printing this release never consumes inventory. Stock moves only through the authorized “Issue Materials” action (official FEFO).</div>
        </div>
        @if(! $issue && $release->status === 'released')
            @can('tenant.catering.material-issues.store')
                <form method="POST" action="{{ url('/catering/production-releases/' . $release->id . '/issue-materials') }}">
                    @csrf
                    <button class="btn btn-warning"
                            onclick="return confirm('Issue the consolidated materials from official stock (FEFO)? This posts real inventory movements and COGS. One issue per release; retrying never doubles it.')">
                        <i class="ti ti-transfer-out me-1"></i>Issue Materials
                    </button>
                </form>
            @endcan
        @endif
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th class="text-end">Planned Requirement</th>
                        <th>Unit</th>
                        <th class="text-end">On Hand (at release)</th>
                        <th class="text-end">Shortfall</th>
                        <th class="text-end">Issued</th>
                        <th class="text-end">Remaining to Issue</th>
                        <th class="text-end">FEFO Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($requirements as $req)
                    @php
                        $issuedLine = $issuedByProduct[$req['product_id'] ?? 0] ?? null;
                        $issuedQty = (float) ($issuedLine->issued_qty ?? 0);
                        $remaining = max(((float) $req['required_qty']) - $issuedQty, 0);
                    @endphp
                    <tr>
                        <td>{{ $req['name'] }}
                            @if($issuedLine && $issuedLine->line_status === 'non_stock')
                                <span class="badge bg-light text-dark">non-stock</span>
                            @endif
                        </td>
                        <td class="text-end fw-bold">{{ rtrim(rtrim(number_format($req['required_qty'], 3), '0'), '.') }}</td>
                        <td>{{ $req['unit_code'] }}</td>
                        <td class="text-end">{{ number_format($req['on_hand'] ?? 0, 3) }}</td>
                        <td class="text-end {{ ($req['shortfall'] ?? 0) > 0 ? 'text-danger fw-bold' : 'text-success' }}">{{ number_format($req['shortfall'] ?? 0, 3) }}</td>
                        <td class="text-end {{ $issuedQty > 0 ? 'text-success fw-bold' : 'text-muted' }}">{{ $issuedQty > 0 ? rtrim(rtrim(number_format($issuedQty, 3), '0'), '.') : '—' }}</td>
                        <td class="text-end {{ $remaining > 0 ? '' : 'text-muted' }}">{{ $issue ? rtrim(rtrim(number_format($remaining, 3), '0'), '.') : rtrim(rtrim(number_format($req['required_qty'], 3), '0'), '.') }}</td>
                        <td class="text-end">{{ $issuedLine && $issuedLine->line_status === 'issued' ? number_format($issuedLine->fefo_cost_total, 2) : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @if($issue)
        <div class="card-footer d-flex justify-content-between flex-wrap gap-2 fs-13">
            <span>Issued {{ $issue->issued_at->format('d M Y g:i A') }} · branch #{{ $issue->branch_id }} · <i class="ti ti-lock"></i> immutable stock document</span>
            <span>Official FEFO cost total: <strong>{{ number_format($issue->total_fefo_cost, 2) }}</strong>
                @if($issue->cogs_journal_entry_id)
                    · COGS journal #{{ $issue->cogs_journal_entry_id }} (Dr 5200 / Cr 1400)
                @endif
            </span>
        </div>
    @endif
</div>
@endif
@endsection
