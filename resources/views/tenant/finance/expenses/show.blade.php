@extends('layouts.app')

@section('title', 'Expense ' . $expenseVoucher->voucher_no)

@php
    $statusBadge = ['draft' => 'bg-secondary', 'posted' => 'bg-success', 'void' => 'bg-danger'];
@endphp

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Expense {{ $expenseVoucher->voucher_no }}
                    <span class="badge {{ $statusBadge[$expenseVoucher->status] ?? 'bg-secondary' }} ms-2">{{ ucfirst($expenseVoucher->status) }}</span>
                </h4>
                <h6>Finance — Expense Voucher</h6>
            </div>
            <div class="page-btn d-flex gap-2">
                <a href="{{ url('/finance/expenses') }}" class="btn btn-secondary">Back</a>
                @can('tenant.finance.expenses.edit')
                    @if($expenseVoucher->isDraft())
                    <a href="{{ url('/finance/expenses/' . $expenseVoucher->id . '/edit') }}" class="btn btn-outline-primary"><i class="ti ti-pencil me-1"></i>Edit</a>
                    @endif
                @endcan
                @can('tenant.finance.expenses.post')
                    @if($expenseVoucher->isDraft())
                    <form method="POST" action="{{ url('/finance/expenses/' . $expenseVoucher->id . '/post') }}" onsubmit="return confirm('Post this voucher? This will reduce the cash/bank balance.')">
                        @csrf
                        <button type="submit" class="btn btn-primary"><i class="ti ti-checks me-1"></i>Post</button>
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

        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-3"><small class="text-muted d-block">Expense Date</small>{{ optional($expenseVoucher->expense_date)->format('Y-m-d') }}</div>
                            <div class="col-md-3"><small class="text-muted d-block">Payment Date</small>{{ optional($expenseVoucher->payment_date)->format('Y-m-d') ?: '—' }}</div>
                            <div class="col-md-3"><small class="text-muted d-block">Branch</small>{{ $expenseVoucher->branch->name ?? '—' }}</div>
                            <div class="col-md-3"><small class="text-muted d-block">Payee</small>{{ $expenseVoucher->payee_name ?: '—' }}</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6"><small class="text-muted d-block">Cash / Bank Account</small>{{ $expenseVoucher->cashBankAccount->name ?? '—' }}</div>
                            <div class="col-md-6"><small class="text-muted d-block">Notes</small>{{ $expenseVoucher->notes ?: '—' }}</div>
                        </div>

                        <div class="table-responsive">
                            <table class="table">
                                <caption class="visually-hidden">Expense lines</caption>
                                <thead class="thead-light">
                                    <tr>
                                        <th scope="col">Category</th>
                                        <th scope="col">Description</th>
                                        <th scope="col" class="text-end">Amount</th>
                                        <th scope="col" class="text-end">Tax</th>
                                        <th scope="col" class="text-end">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($expenseVoucher->lines as $line)
                                    <tr>
                                        <td>{{ $line->category->name ?? '—' }}</td>
                                        <td>{{ $line->description ?: '—' }}</td>
                                        <td class="text-end">{{ number_format((float) $line->amount, 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $line->tax_amount, 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $line->line_total, 2) }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr><th colspan="4" class="text-end">Subtotal</th><th class="text-end">{{ number_format((float) $expenseVoucher->subtotal, 2) }}</th></tr>
                                    <tr><th colspan="4" class="text-end">Tax</th><th class="text-end">{{ number_format((float) $expenseVoucher->tax_amount, 2) }}</th></tr>
                                    <tr><th colspan="4" class="text-end">Total</th><th class="text-end">{{ number_format((float) $expenseVoucher->total_amount, 2) }}</th></tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                @if($transactions->isNotEmpty())
                <div class="card">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3">Cash / Bank Transactions</h6>
                        <div class="table-responsive">
                            <table class="table">
                                <caption class="visually-hidden">Cash bank transactions for this voucher</caption>
                                <thead class="thead-light">
                                    <tr>
                                        <th scope="col">Date</th>
                                        <th scope="col">Type</th>
                                        <th scope="col">Direction</th>
                                        <th scope="col" class="text-end">Amount</th>
                                        <th scope="col" class="text-end">Balance After</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($transactions as $txn)
                                    <tr>
                                        <td>{{ optional($txn->transaction_date)->format('Y-m-d') }}</td>
                                        <td>{{ str_replace('_', ' ', $txn->transaction_type) }}</td>
                                        <td><span class="badge bg-{{ $txn->direction === 'out' ? 'danger' : 'success' }}">{{ strtoupper($txn->direction) }}</span></td>
                                        <td class="text-end">{{ number_format((float) $txn->amount, 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $txn->balance_after, 2) }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                @endif
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3">Summary</h6>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Status</span><span class="badge {{ $statusBadge[$expenseVoucher->status] ?? 'bg-secondary' }}">{{ ucfirst($expenseVoucher->status) }}</span></div>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Total</span><strong>{{ number_format((float) $expenseVoucher->total_amount, 2) }}</strong></div>
                        @if($expenseVoucher->postedBy)
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Posted by</span><span>{{ $expenseVoucher->postedBy->name }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Posted at</span><span>{{ optional($expenseVoucher->posted_at)->format('Y-m-d H:i') }}</span></div>
                        @endif
                        @if($expenseVoucher->isVoid())
                            <hr>
                            <div class="text-danger small"><i class="ti ti-ban me-1"></i>Voided
                                @if($expenseVoucher->voidedBy) by {{ $expenseVoucher->voidedBy->name }}@endif
                                @if($expenseVoucher->voided_at) on {{ optional($expenseVoucher->voided_at)->format('Y-m-d H:i') }}@endif
                            </div>
                            @if($expenseVoucher->void_reason)<div class="small text-muted mt-1">{{ $expenseVoucher->void_reason }}</div>@endif
                        @endif
                    </div>
                </div>

                @can('tenant.finance.expenses.void')
                    @if($expenseVoucher->isPosted())
                    <div class="card border-danger">
                        <div class="card-body">
                            <h6 class="fw-bold text-danger mb-2">Void Voucher</h6>
                            <p class="text-muted small">Voiding reverses the cash/bank transaction and restores the balance.</p>
                            <form method="POST" action="{{ url('/finance/expenses/' . $expenseVoucher->id . '/void') }}" onsubmit="return confirm('Void this voucher? The cash/bank balance will be restored.')">
                                @csrf
                                <div class="mb-2">
                                    <label class="form-label" for="void_reason">Reason (optional)</label>
                                    <textarea name="void_reason" id="void_reason" class="form-control" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger w-100"><i class="ti ti-ban me-1"></i>Void Voucher</button>
                            </form>
                        </div>
                    </div>
                    @endif
                @endcan

                @can('tenant.finance.expenses.destroy')
                    @if($expenseVoucher->isDraft())
                    <form method="POST" action="{{ url('/finance/expenses/' . $expenseVoucher->id) }}" class="mt-2" onsubmit="return confirm('Delete this draft voucher?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger w-100"><i class="ti ti-trash me-1"></i>Delete Draft</button>
                    </form>
                    @endif
                @endcan
            </div>
        </div>
@endsection
