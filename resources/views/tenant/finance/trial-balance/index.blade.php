@extends('layouts.app')

@section('title', 'Trial Balance')

@php
    $sided = [\App\Http\Controllers\Tenant\Finance\TrialBalanceController::class, 'sided'];
@endphp

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Trial Balance</h4>
                <h6>{{ $from }} to {{ $to }}
                    @if(count($selectedBranchIds))
                        &mdash; {{ $branches->whereIn('id', $selectedBranchIds)->pluck('name')->implode(', ') }}
                    @else
                        &mdash; All Branches
                    @endif
                </h6>
            </div>
            <div class="page-btn">
                <form method="GET" class="d-inline">
                    @foreach($selectedBranchIds as $id)
                        <input type="hidden" name="branch_ids[]" value="{{ $id }}">
                    @endforeach
                    <input type="hidden" name="date_from" value="{{ $from }}">
                    <input type="hidden" name="date_to" value="{{ $to }}">
                    <button type="submit" name="export_csv" value="1" class="btn btn-outline-success btn-sm"><i class="ti ti-download me-1"></i>CSV</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                {{-- TRIAL-BALANCE-PERIOD-1: a period, not a single "as of" date. --}}
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-sm-2">
                        <label class="form-label mb-1">From</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $from }}">
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label mb-1">To</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $to }}">
                    </div>
                    @include('tenant.finance.partials.branch-multiselect', ['branches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-primary w-100">Apply</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- The check is on the CLOSING pair — the same figures the old "as of" screen balanced. --}}
        <div class="alert {{ $difference == 0 ? 'alert-success' : 'alert-danger' }}">
            <i class="ti {{ $difference == 0 ? 'ti-circle-check' : 'ti-alert-triangle' }} me-1"></i>
            Closing Debits {{ number_format($totals['closing_debit'], 2) }} | Closing Credits {{ number_format($totals['closing_credit'], 2) }} | Difference {{ number_format($difference, 2) }}
            {{ $difference == 0 ? '— balanced' : '— OUT OF BALANCE' }}
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <caption class="visually-hidden">Trial balance for the period</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Code</th>
                                <th scope="col">Account</th>
                                <th scope="col">Type</th>
                                <th scope="col" class="text-end">Opening</th>
                                <th scope="col" class="text-end">Debit</th>
                                <th scope="col" class="text-end">Credit</th>
                                <th scope="col" class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $r)
                            <tr>
                                <td class="fw-semibold">{{ $r['code'] }}</td>
                                {{-- One click to where the movement came from. --}}
                                <td><a href="{{ url('/finance/general-ledger') . '?' . http_build_query(['account_id' => $r['account_id'], 'date_from' => $from, 'date_to' => $to, 'branch_ids' => $selectedBranchIds]) }}">{{ $r['name'] }}</a></td>
                                <td><span class="badge bg-secondary">{{ ucfirst($r['type']) }}</span></td>
                                <td class="text-end text-muted">{{ $sided($r['opening_debit'], $r['opening_credit']) }}</td>
                                <td class="text-end">{{ $r['period_debit'] > 0 ? number_format($r['period_debit'], 2) : '' }}</td>
                                <td class="text-end">{{ $r['period_credit'] > 0 ? number_format($r['period_credit'], 2) : '' }}</td>
                                <td class="text-end fw-semibold">{{ $sided($r['closing_debit'], $r['closing_credit']) }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No posted journal activity up to {{ $to }}.</td></tr>
                            @endforelse
                        </tbody>
                        @if(count($rows))
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3" class="text-end">Totals</th>
                                <th class="text-end small">{{ number_format($totals['opening_debit'], 2) }} Dr / {{ number_format($totals['opening_credit'], 2) }} Cr</th>
                                <th class="text-end" id="tb-period-debit">{{ number_format($totals['period_debit'], 2) }}</th>
                                <th class="text-end" id="tb-period-credit">{{ number_format($totals['period_credit'], 2) }}</th>
                                <th class="text-end small" id="tb-closing">{{ number_format($totals['closing_debit'], 2) }} Dr / {{ number_format($totals['closing_credit'], 2) }} Cr</th>
                            </tr>
                        </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
@endsection
