@extends('layouts.app')

@section('title', 'Journal ' . $journalEntry->entry_no)

@php $statusBadge = ['draft' => 'bg-secondary', 'posted' => 'bg-success', 'void' => 'bg-danger']; @endphp

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Journal {{ $journalEntry->entry_no }}
                    <span class="badge {{ $statusBadge[$journalEntry->status] ?? 'bg-secondary' }} ms-2">{{ ucfirst($journalEntry->status) }}</span>
                    @if($journalEntry->is_reversal)<span class="badge bg-warning text-dark ms-1">Reversal</span>@endif
                </h4>
                <h6>General Ledger journal entry</h6>
            </div>
            <div class="page-btn">
                <a href="{{ url('/finance/journal-entries') }}" class="btn btn-secondary">Back</a>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-3"><small class="text-muted d-block">Entry Date</small>{{ optional($journalEntry->entry_date)->format('Y-m-d') }}</div>
                    <div class="col-md-3"><small class="text-muted d-block">Source</small>{{ str_replace('_', ' ', $journalEntry->source_type ?? '—') }}</div>
                    <div class="col-md-3"><small class="text-muted d-block">Source #</small>{{ $journalEntry->source_no ?: '—' }}</div>
                    <div class="col-md-3"><small class="text-muted d-block">Posted By</small>{{ $journalEntry->postedBy->name ?? '—' }}</div>
                    <div class="col-12"><small class="text-muted d-block">Description</small>{{ $journalEntry->description }}</div>
                    @if($journalEntry->reversedEntry)
                        <div class="col-12"><span class="badge bg-warning text-dark">Reverses {{ $journalEntry->reversedEntry->entry_no }}</span></div>
                    @endif
                    @if($reversal)
                        <div class="col-12"><span class="badge bg-info text-dark">Reversed by <a href="{{ url('/finance/journal-entries/' . $reversal->id) }}">{{ $reversal->entry_no }}</a></span></div>
                    @endif
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <caption class="visually-hidden">Journal lines</caption>
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
                            @foreach($journalEntry->lines as $line)
                            <tr>
                                <td>{{ $line->account->code ?? '' }} — {{ $line->account->name ?? '' }}</td>
                                <td class="text-muted">{{ $line->branch->name ?? '—' }}</td>
                                <td class="text-muted">{{ $line->description }}</td>
                                <td class="text-end">{{ (float) $line->debit > 0 ? number_format((float) $line->debit, 2) : '' }}</td>
                                <td class="text-end">{{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2) : '' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3" class="text-end">Totals</th>
                                <th class="text-end">{{ number_format((float) $journalEntry->total_debit, 2) }}</th>
                                <th class="text-end">{{ number_format((float) $journalEntry->total_credit, 2) }}</th>
                            </tr>
                            <tr>
                                <td colspan="5" class="text-end {{ $journalEntry->isBalanced() ? 'text-success' : 'text-danger' }}">
                                    {{ $journalEntry->isBalanced() ? 'Balanced ✓' : 'NOT balanced ✗' }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
@endsection
