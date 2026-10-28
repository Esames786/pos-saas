@extends('layouts.app')

@section('title', 'Deleted entries')

@section('content')
{{-- VOID-PURGE-1: the Owner's record of every entry deleted permanently — read-only, nothing can be restored here. --}}
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Deleted entries</h1>
        <p class="text-muted mb-0 small">Entries that were voided on their own date and then deleted permanently, with their reversal.
            Totals did not change. Only the Owner sees this list.</p>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

@if($entries === null)
    <div class="card"><div class="card-body text-muted">Permanent delete is not set up on this business yet.</div></div>
@elseif($entries->isEmpty())
    <div class="card"><div class="card-body text-center text-muted">Nothing has been deleted permanently.</div></div>
@else
    <div class="card"><div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr><th>Deleted on</th><th>Entry</th><th>Entry date</th><th class="text-end">Amount</th><th>Why</th><th>By</th><th>What was removed</th></tr>
            </thead>
            <tbody>
            @foreach($entries as $p)
                @php $tables = $p->snapshot['tables'] ?? []; @endphp
                <tr>
                    <td class="text-nowrap small">{{ app(\App\Support\TenantClock::class)->format($p->purged_at, 'd M Y, h:i A') }}</td>
                    <td class="small">{{ $types[$p->entity_type] ?? $p->entity_type }} <span class="text-nowrap">{{ $p->document_no }}</span></td>
                    <td class="text-nowrap small">{{ $p->document_date?->format('d M Y') }}</td>
                    <td class="text-end">{{ number_format((float) $p->amount, 2) }}</td>
                    <td class="small">{{ $p->reason }}</td>
                    <td class="small">{{ $p->purgedBy?->name ?? '—' }}</td>
                    <td class="small">
                        <details>
                            <summary>{{ collect($tables)->map(fn ($rows, $t) => count($rows) . ' ' . str_replace('_', ' ', $t))->implode(' · ') }}</summary>
                            <pre class="small mb-0" style="max-height:260px;overflow:auto;white-space:pre-wrap">{{ json_encode($p->snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div></div>
    <div class="mt-3">{{ $entries->links() }}</div>
@endif
@endsection
