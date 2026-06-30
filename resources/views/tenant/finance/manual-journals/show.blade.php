@extends('layouts.app')

@section('title', 'Manual Journal ' . $manualJournal->entry_no)

@section('content')
    <div class="page-header">
        <div class="page-title">
            <h4>
                {{ $manualJournal->entry_no }}
                <span class="badge {{ $manualJournal->status === 'posted' ? 'bg-success' : 'bg-secondary' }} ms-2">
                    {{ ucfirst($manualJournal->status) }}
                </span>
                @if($manualJournal->is_reversal)
                    <span class="badge bg-warning text-dark ms-1">Reversal</span>
                @endif
            </h4>
            <h6>Finance — Manual Journal Entry</h6>
        </div>
        <div class="page-btn d-flex gap-2">
            @if($manualJournal->status === 'posted' && ! $manualJournal->is_reversal && ! $reversal)
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reverseModal">
                    <i class="ti ti-rotate-clockwise me-1"></i>Reverse
                </button>
            @endif
            <a href="{{ url('/finance/manual-journals') }}" class="btn btn-secondary">Back</a>
        </div>
    </div>

    {{-- Status notices --}}
    @if($reversal)
        <div class="alert alert-warning">
            This entry was reversed by
            <a href="{{ url('/finance/manual-journals/' . $reversal->id) }}" class="fw-semibold">{{ $reversal->entry_no }}</a>.
        </div>
    @endif
    @if($manualJournal->reversedEntry)
        <div class="alert alert-info">
            This is a reversal of
            <a href="{{ url('/finance/manual-journals/' . $manualJournal->reversedEntry->id) }}" class="fw-semibold">
                {{ $manualJournal->reversedEntry->entry_no }}
            </a>.
        </div>
    @endif

    {{-- Header --}}
    <div class="card">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-2">
                    <small class="text-muted d-block">Entry Date</small>
                    {{ optional($manualJournal->entry_date)->format('Y-m-d') }}
                </div>
                <div class="col-md-2">
                    <small class="text-muted d-block">Entry No</small>
                    {{ $manualJournal->entry_no }}
                </div>
                <div class="col-md-2">
                    <small class="text-muted d-block">Reference</small>
                    {{ $manualJournal->source_no ?: '—' }}
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Posted By</small>
                    {{ $manualJournal->postedBy?->name ?? '—' }}
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Posted At</small>
                    {{ optional($manualJournal->posted_at)->format('Y-m-d H:i') ?? optional($manualJournal->created_at)->format('Y-m-d H:i') }}
                </div>
                <div class="col-12">
                    <small class="text-muted d-block">Description / Memo</small>
                    <span class="fw-semibold">{{ $manualJournal->description }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Journal Lines --}}
    <div class="card">
        <div class="card-header"><h6 class="mb-0">Journal Lines</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <caption class="visually-hidden">Manual journal lines</caption>
                    <thead class="thead-light">
                        <tr>
                            <th scope="col">Account</th>
                            <th scope="col">Branch</th>
                            <th scope="col">Description</th>
                            <th scope="col" class="text-end">Debit</th>
                            <th scope="col" class="text-end">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($manualJournal->lines as $line)
                        <tr>
                            <td>
                                <span class="text-muted me-1">{{ $line->account->code ?? '' }}</span>
                                {{ $line->account->name ?? '—' }}
                            </td>
                            <td class="text-muted small">{{ $line->branch?->name ?? '—' }}</td>
                            <td class="text-muted small">{{ $line->description ?: '—' }}</td>
                            <td class="text-end">
                                @if((float) $line->debit > 0)
                                    {{ number_format((float) $line->debit, 2) }}
                                @endif
                            </td>
                            <td class="text-end">
                                @if((float) $line->credit > 0)
                                    {{ number_format((float) $line->credit, 2) }}
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end">Totals</th>
                            <th class="text-end">{{ number_format((float) $manualJournal->total_debit, 2) }}</th>
                            <th class="text-end">{{ number_format((float) $manualJournal->total_credit, 2) }}</th>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end small
                                {{ abs((float)$manualJournal->total_debit - (float)$manualJournal->total_credit) <= 0.01 ? 'text-success' : 'text-danger' }}">
                                @if(abs((float)$manualJournal->total_debit - (float)$manualJournal->total_credit) <= 0.01)
                                    Balanced ✓
                                @else
                                    NOT balanced ✗
                                @endif
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Cash/Bank impact --}}
    @if($cashBankTxns->isNotEmpty())
    <div class="card">
        <div class="card-header"><h6 class="mb-0">Cash / Bank Impact</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th scope="col">Account</th>
                            <th scope="col">Direction</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col" class="text-end">Balance After</th>
                            <th scope="col">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($cashBankTxns as $txn)
                        <tr>
                            <td>{{ $txn->cashBankAccount?->name ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $txn->direction === 'in' ? 'bg-success' : 'bg-danger' }}">
                                    {{ ucfirst($txn->direction) }}
                                </span>
                            </td>
                            <td class="text-end">{{ number_format((float) $txn->amount, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $txn->balance_after, 2) }}</td>
                            <td>{{ $txn->transaction_date }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- Reverse modal --}}
    @if($manualJournal->status === 'posted' && ! $manualJournal->is_reversal && ! $reversal)
    <div class="modal fade" id="reverseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reverse Journal {{ $manualJournal->entry_no }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="{{ url('/finance/manual-journals/' . $manualJournal->id . '/reverse') }}">
                    @csrf
                    @method('POST')
                    <div class="modal-body">
                        <p class="text-muted">
                            This will create a new offsetting journal entry with all debits and credits
                            flipped. The original entry is kept unchanged. Cash/bank balances are also
                            reversed if this journal had cash/bank lines.
                        </p>
                        <div class="mb-3">
                            <label class="form-label" for="reason">Reason <small class="text-muted">(optional)</small></label>
                            <input type="text" id="reason" name="reason" class="form-control"
                                placeholder="e.g. Posted to wrong account, Error correction">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Post Reversal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif
@endsection
