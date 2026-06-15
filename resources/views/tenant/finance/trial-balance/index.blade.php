@extends('layouts.app')

@section('title', 'Trial Balance')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Trial Balance</h4>
                <h6>As of {{ $asOf }}</h6>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-sm-3">
                        <label class="form-label mb-1">As of</label>
                        <input type="date" name="as_of_date" class="form-control" value="{{ $filters['as_of_date'] ?? $asOf }}">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label mb-1">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="">All branches</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ (string)($filters['branch_id'] ?? '') === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-primary w-100">Apply</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="alert {{ $difference == 0 ? 'alert-success' : 'alert-danger' }}">
            <i class="ti {{ $difference == 0 ? 'ti-circle-check' : 'ti-alert-triangle' }} me-1"></i>
            Total Debits {{ number_format($totalDebit, 2) }} | Total Credits {{ number_format($totalCredit, 2) }} | Difference {{ number_format($difference, 2) }}
            {{ $difference == 0 ? '— balanced' : '— OUT OF BALANCE' }}
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <caption class="visually-hidden">Trial balance</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Code</th>
                                <th scope="col">Account</th>
                                <th scope="col">Type</th>
                                <th scope="col" class="text-end">Debit</th>
                                <th scope="col" class="text-end">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $r)
                            <tr>
                                <td class="fw-semibold">{{ $r['code'] }}</td>
                                <td>{{ $r['name'] }}</td>
                                <td><span class="badge bg-secondary">{{ ucfirst($r['type']) }}</span></td>
                                <td class="text-end">{{ $r['debit_balance'] > 0 ? number_format($r['debit_balance'], 2) : '' }}</td>
                                <td class="text-end">{{ $r['credit_balance'] > 0 ? number_format($r['credit_balance'], 2) : '' }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No posted journal activity yet.</td></tr>
                            @endforelse
                        </tbody>
                        @if(count($rows))
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3" class="text-end">Totals</th>
                                <th class="text-end">{{ number_format($totalDebit, 2) }}</th>
                                <th class="text-end">{{ number_format($totalCredit, 2) }}</th>
                            </tr>
                        </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
@endsection
