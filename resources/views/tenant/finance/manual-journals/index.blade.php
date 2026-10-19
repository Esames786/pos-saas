@extends('layouts.app')

@section('title', 'Manual Journal Entries')

@section('content')
    <div class="page-header">
        <div class="page-title">
            <h4>Manual Journal Entries</h4>
            <h6>Finance — Ad-hoc GL postings (asset purchases, transfers, corrections, accruals)</h6>
        </div>
        <div class="page-btn">
            <a href="{{ url('/finance/manual-journals/create') }}" class="btn btn-added">
                <i class="ti ti-plus me-1"></i>New Manual Journal
            </a>
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
                <div class="col-sm-4">
                    <label class="form-label mb-1">Search</label>
                    <input type="text" name="q" class="form-control" placeholder="Entry no / ref / description" value="{{ $filters['q'] ?? '' }}">
                </div>
                <div class="col-sm-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
                <div class="col-sm-2">
                    <a href="{{ url('/finance/manual-journals') }}" class="btn btn-secondary w-100">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card table-list-card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table datanew">
                    <caption class="visually-hidden">Manual journal entries</caption>
                    <thead class="thead-light">
                        <tr>
                            <th scope="col">Entry #</th>
                            <th scope="col">Date</th>
                            <th scope="col">Reference</th>
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
                            <td>
                                <a href="{{ url('/finance/manual-journals/' . $e->id) }}" class="fw-semibold">{{ $e->entry_no }}</a>
                                {{-- B2 — reversal list me apne badge ke saath, aur asal par "Reversed by". --}}
                                @if($e->is_reversal)
                                    <span class="badge bg-warning text-dark ms-1">Reversal</span>
                                    @if($e->reversedEntry)
                                        <div class="small text-muted">of
                                            <a href="{{ url('/finance/manual-journals/' . $e->reversedEntry->id) }}">{{ $e->reversedEntry->entry_no }}</a>
                                        </div>
                                    @endif
                                @elseif($reversedBy->has($e->id))
                                    <span class="badge bg-secondary ms-1">Reversed</span>
                                    <div class="small text-muted">by
                                        <a href="{{ url('/finance/manual-journals/' . $reversedBy[$e->id]->id) }}">{{ $reversedBy[$e->id]->entry_no }}</a>
                                    </div>
                                @endif
                            </td>
                            <td>{{ optional($e->entry_date)->format('Y-m-d') }}</td>
                            <td class="text-muted">{{ $e->source_no ?: '—' }}</td>
                            <td>{{ $e->description }}</td>
                            <td class="text-end">{{ number_format((float) $e->total_debit, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $e->total_credit, 2) }}</td>
                            <td>
                                <span class="badge {{ $e->status === 'posted' ? 'bg-success' : 'bg-secondary' }}">
                                    {{ ucfirst($e->status) }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ url('/finance/manual-journals/' . $e->id) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="ti ti-eye"></i>
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No manual journal entries yet.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
