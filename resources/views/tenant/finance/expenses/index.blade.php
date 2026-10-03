@extends('layouts.app')

@section('title', 'Expenses')

@php
    $statusBadge = ['draft' => 'bg-secondary', 'posted' => 'bg-success', 'void' => 'bg-danger'];
@endphp

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Expenses</h4>
                <h6>Record and pay business expenses</h6>
            </div>
            @can('tenant.finance.expenses.create')
            <div class="page-btn">
                <a href="{{ url('/finance/expenses/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Add Expense
                </a>
            </div>
            @endcan
        </div>

        @if(session('status'))
            <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif

        <div class="card">
            <div class="card-body">
                <form method="GET" action="{{ url('/finance/expenses') }}" class="row g-2 align-items-end">
                    <div class="col-sm-2">
                        <label class="form-label mb-1">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            @foreach($statuses as $s)
                                <option value="{{ $s }}" {{ ($filters['status'] ?? '') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label mb-1">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="">All branches</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ (string) ($filters['branch_id'] ?? '') === (string) $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- EXPENSE-LIST-CATEGORY-FILTER-1: dates are on expense_date, the Date column below. --}}
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
                        <input type="text" name="q" class="form-control" placeholder="Voucher no or payee" value="{{ $filters['q'] ?? '' }}">
                    </div>
                    {{-- Category = the chart-of-accounts account; Sub-category = the expense category. --}}
                    <div class="col-sm-4">
                        <label class="form-label mb-1">Category</label>
                        <select name="account_id" id="expense-filter-account" class="form-select">
                            <option value="">All categories</option>
                            @foreach($accounts as $a)
                                <option value="{{ $a->id }}" {{ (string) ($filters['account_id'] ?? '') === (string) $a->id ? 'selected' : '' }}>{{ $a->code }} — {{ $a->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label mb-1">Sub-category</label>
                        <select name="expense_category_id" id="expense-filter-category" class="form-select">
                            <option value="">All sub-categories</option>
                            @foreach($categories->groupBy('account_id') as $accId => $group)
                                @php $groupAccount = $group->first()->account; @endphp
                                <optgroup label="{{ $groupAccount ? $groupAccount->code . ' — ' . $groupAccount->name : 'No account' }}" data-account="{{ $accId }}">
                                    @foreach($group as $c)
                                        {{-- Inactive ones stay: old vouchers still carry them. --}}
                                        <option value="{{ $c->id }}" {{ (string) ($filters['expense_category_id'] ?? '') === (string) $c->id ? 'selected' : '' }}>{{ $c->code }} — {{ $c->name }}{{ $c->is_active ? '' : ' (inactive)' }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Expense vouchers</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Voucher #</th>
                                <th scope="col">Date</th>
                                <th scope="col">Branch</th>
                                <th scope="col">Payee</th>
                                <th scope="col">Category</th>
                                <th scope="col">Sub-category</th>
                                <th scope="col">Cash/Bank</th>
                                <th scope="col">Status</th>
                                @if($byCategory)<th scope="col" class="text-end">Matched</th>@endif
                                <th scope="col" class="text-end">Total</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($vouchers as $v)
                            <tr>
                                <td><a href="{{ url('/finance/expenses/' . $v->id) }}" class="fw-semibold">{{ $v->voucher_no }}</a></td>
                                <td>{{ optional($v->expense_date)->format('Y-m-d') }}</td>
                                <td>{{ $v->branch->name ?? '—' }}</td>
                                <td>{{ $v->payee_name ?: '—' }}</td>
                                {{-- One per line inside the cell: a voucher can hold several. The account is
                                     the one each LINE was posted to. --}}
                                <td class="small">
                                    @forelse($v->lines->pluck('account')->filter()->unique('id') as $lineAccount)
                                        <div>{{ $lineAccount->code }} — {{ $lineAccount->name }}</div>
                                    @empty
                                        —
                                    @endforelse
                                </td>
                                <td class="small">
                                    @forelse($v->lines->pluck('category')->filter()->unique('id') as $lineCategory)
                                        <div>{{ $lineCategory->code }} — {{ $lineCategory->name }}</div>
                                    @empty
                                        —
                                    @endforelse
                                </td>
                                <td class="text-muted">{{ $v->cashBankAccount->name ?? '—' }}</td>
                                <td><span class="badge {{ $statusBadge[$v->status] ?? 'bg-secondary' }}">{{ ucfirst($v->status) }}</span></td>
                                @if($byCategory)<td class="text-end fw-semibold">{{ number_format((float) $v->matched_amount, 2) }}</td>@endif
                                <td class="text-end">{{ number_format((float) $v->total_amount, 2) }}</td>
                                <td>
                                    <a href="{{ url('/finance/expenses/' . $v->id) }}" class="btn btn-sm btn-outline-secondary me-1" title="View"><i class="ti ti-eye"></i></a>
                                    @can('tenant.finance.expenses.edit')
                                        @if($v->isDraft())
                                        <a href="{{ url('/finance/expenses/' . $v->id . '/edit') }}" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="ti ti-pencil"></i></a>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="{{ $byCategory ? 11 : 10 }}" class="text-center text-muted py-4">No expenses found.</td></tr>
                            @endforelse
                        </tbody>
                        {{-- Summed by the database over EVERY match, not from these rows: the list stops at
                             500 vouchers. Posted only, because void was reversed and draft was never paid. --}}
                        <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="8" class="text-end">Total (posted{{ $byCategory ? ', matching lines only' : '' }})</td>
                                <td class="text-end" id="expense-posted-total">{{ number_format($postedTotal, 2) }}</td>
                                @if($byCategory)<td></td>@endif
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="{{ $byCategory ? 11 : 10 }}" class="text-muted small text-end" id="expense-status-counts">
                                    {{ $statusCounts['posted'] ?? 0 }} posted · {{ $statusCounts['draft'] ?? 0 }} draft · {{ $statusCounts['void'] ?? 0 }} void match these filters{{ $statusCounts->sum() > $vouchers->count() ? ' (showing the latest ' . $vouchers->count() . ')' : '' }}. Draft and void are never in the total.
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
@endsection

@push('scripts')
<script>
// Picking a Category narrows Sub-category to that account's own sub-categories.
(function () {
    var acc = document.getElementById('expense-filter-account');
    var cat = document.getElementById('expense-filter-category');
    if (!acc || !cat) return;
    function narrow() {
        var a = acc.value;
        cat.querySelectorAll('optgroup').forEach(function (g) {
            var show = !a || g.getAttribute('data-account') === a;
            g.hidden = !show;
            g.disabled = !show;
        });
        var picked = cat.options[cat.selectedIndex];
        if (picked && picked.parentNode.tagName === 'OPTGROUP' && picked.parentNode.disabled) {
            cat.value = '';
        }
    }
    acc.addEventListener('change', narrow);
    narrow();
})();
</script>
@endpush
