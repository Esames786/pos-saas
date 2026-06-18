@extends('layouts.app')

@section('title', 'Opening Balance ' . $batch->batch_no)

@php
    $statusBadge = ['draft' => 'bg-secondary', 'posted' => 'bg-success', 'void' => 'bg-danger'];
    $difference  = $batch->difference();
    $balanced    = $batch->isBalanced();
@endphp

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Opening Balance {{ $batch->batch_no }}
                    <span class="badge {{ $statusBadge[$batch->status] ?? 'bg-secondary' }} ms-2">{{ ucfirst($batch->status) }}</span>
                </h4>
                <h6>Finance — Opening Balances / Owner Capital</h6>
            </div>
            <div class="page-btn d-flex gap-2">
                <a href="{{ url('/finance/opening-balances') }}" class="btn btn-secondary">Back</a>
                @can('tenant.finance.opening-balances.edit')
                    @if($batch->isDraft())
                        <a href="{{ url('/finance/opening-balances/' . $batch->id . '/edit') }}" class="btn btn-outline-primary"><i class="ti ti-pencil me-1"></i>Edit</a>
                    @endif
                @endcan
                @can('tenant.finance.opening-balances.post')
                    @if($batch->isDraft())
                        <form method="POST" action="{{ url('/finance/opening-balances/' . $batch->id . '/post') }}" onsubmit="return confirm('Post this opening balance to the general ledger?')">
                            @csrf
                            <button type="submit" class="btn btn-primary" {{ $balanced ? '' : 'disabled' }}>
                                <i class="ti ti-checks me-1"></i>Post
                            </button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>

        @if(session('status'))
            <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif
        @if($batch->isDraft() && ! $balanced)
            <div class="alert alert-warning"><i class="ti ti-alert-triangle me-1"></i>This batch is not balanced (difference {{ number_format($difference, 2) }}). Adjust the lines until debits equal credits before posting.</div>
        @endif

        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-3"><small class="text-muted d-block">Opening Date</small>{{ optional($batch->opening_date)->format('Y-m-d') }}</div>
                            <div class="col-md-3"><small class="text-muted d-block">Branch</small>{{ $batch->branch->name ?? 'All branches' }}</div>
                            <div class="col-md-6"><small class="text-muted d-block">Description</small>{{ $batch->description ?: '—' }}</div>
                        </div>

                        <div class="table-responsive">
                            <table class="table">
                                <caption class="visually-hidden">Opening balance lines</caption>
                                <thead class="thead-light">
                                    <tr>
                                        <th scope="col">Account</th>
                                        <th scope="col">Cash/Bank</th>
                                        <th scope="col">Description</th>
                                        <th scope="col" class="text-end">Debit</th>
                                        <th scope="col" class="text-end">Credit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($batch->lines as $line)
                                    <tr>
                                        <td>{{ $line->account ? $line->account->code . ' — ' . $line->account->name : '—' }}</td>
                                        <td>{{ $line->cashBankAccount->name ?? '—' }}</td>
                                        <td>{{ $line->description ?: '—' }}</td>
                                        <td class="text-end">{{ (float) $line->debit ? number_format((float) $line->debit, 2) : '' }}</td>
                                        <td class="text-end">{{ (float) $line->credit ? number_format((float) $line->credit, 2) : '' }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="3" class="text-end">Totals</th>
                                        <th class="text-end">{{ number_format((float) $batch->total_debit, 2) }}</th>
                                        <th class="text-end">{{ number_format((float) $batch->total_credit, 2) }}</th>
                                    </tr>
                                    <tr>
                                        <th colspan="3" class="text-end">Difference</th>
                                        <th class="text-end" colspan="2">
                                            <span class="badge {{ $difference === 0.0 ? 'bg-success' : 'bg-danger' }}">{{ number_format($difference, 2) }}</span>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3">Summary</h6>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Status</span><span class="badge {{ $statusBadge[$batch->status] ?? 'bg-secondary' }}">{{ ucfirst($batch->status) }}</span></div>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Total Debit</span><strong>{{ number_format((float) $batch->total_debit, 2) }}</strong></div>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Total Credit</span><strong>{{ number_format((float) $batch->total_credit, 2) }}</strong></div>
                        @if($batch->journalEntry)
                            <hr>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Journal</span>
                                <a href="{{ url('/finance/journal-entries/' . $batch->journalEntry->id) }}">{{ $batch->journalEntry->entry_no }}</a>
                            </div>
                        @endif
                        @if($batch->postedBy)
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Posted by</span><span>{{ $batch->postedBy->name }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Posted at</span><span>{{ optional($batch->posted_at)->format('Y-m-d H:i') }}</span></div>
                        @endif
                        @if($batch->isVoid())
                            <hr>
                            <div class="text-danger small"><i class="ti ti-ban me-1"></i>Voided
                                @if($batch->voidedBy) by {{ $batch->voidedBy->name }}@endif
                                @if($batch->voided_at) on {{ optional($batch->voided_at)->format('Y-m-d H:i') }}@endif
                            </div>
                            @if($batch->void_reason)<div class="small text-muted mt-1">{{ $batch->void_reason }}</div>@endif
                        @endif
                    </div>
                </div>

                @can('tenant.finance.opening-balances.void')
                    @if($batch->isPosted())
                    <div class="card border-danger">
                        <div class="card-body">
                            <h6 class="fw-bold text-danger mb-2">Void Batch</h6>
                            <p class="text-muted small">Voiding reverses the opening journal and restores any cash/bank balance. The original entries are kept.</p>
                            <form method="POST" action="{{ url('/finance/opening-balances/' . $batch->id . '/void') }}" onsubmit="return confirm('Void this opening balance? The journal will be reversed.')">
                                @csrf
                                <div class="mb-2">
                                    <label class="form-label" for="void_reason">Reason (optional)</label>
                                    <textarea name="void_reason" id="void_reason" class="form-control" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger w-100"><i class="ti ti-ban me-1"></i>Void Batch</button>
                            </form>
                        </div>
                    </div>
                    @endif
                @endcan
            </div>
        </div>
@endsection
