@extends('layouts.app')

@section('title', 'General Ledger')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>General Ledger</h4>
                <h6>{{ $account ? $account->code . ' — ' . $account->name : 'Posted journal lines' }}</h6>
            </div>
            <div class="page-btn">
                <form method="GET" class="d-inline">
                    @foreach($filters as $k => $val)
                        @if($val)<input type="hidden" name="{{ $k }}" value="{{ $val }}">@endif
                    @endforeach
                    <button type="submit" name="export_csv" value="1" class="btn btn-outline-success btn-sm"><i class="ti ti-download me-1"></i>CSV</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-sm-4">
                        <label class="form-label mb-1">Account</label>
                        <select name="account_id" class="form-select">
                            <option value="">All accounts</option>
                            @foreach($accounts as $a)
                                <option value="{{ $a->id }}" {{ (string)($filters['account_id'] ?? '') === (string)$a->id ? 'selected' : '' }}>{{ $a->code }} — {{ $a->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @include('tenant.finance.partials.branch-multiselect', ['branches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                    <div class="col-sm-2">
                        <label class="form-label mb-1">From</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label mb-1">To</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-sm-1">
                        <button type="submit" class="btn btn-primary w-100">Go</button>
                    </div>
                </form>
                @unless($showRunning)
                    <small class="text-muted d-block mt-2"><i class="ti ti-info-circle me-1"></i>Select a single account to see a running balance.</small>
                @endunless
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <caption class="visually-hidden">General ledger lines</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Entry #</th>
                                <th scope="col">Account</th>
                                <th scope="col">Description</th>
                                <th scope="col">Branch</th>
                                <th scope="col" class="text-end">Debit</th>
                                <th scope="col" class="text-end">Credit</th>
                                @if($showRunning)<th scope="col" class="text-end">Balance</th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($lines as $line)
                            <tr>
                                <td>{{ optional($line->journalEntry->entry_date)->format('Y-m-d') }}</td>
                                <td><a href="{{ url('/finance/journal-entries/' . $line->journal_entry_id) }}">{{ $line->journalEntry->entry_no ?? '' }}</a></td>
                                <td class="text-muted">{{ $line->account->code ?? '' }} — {{ $line->account->name ?? '' }}</td>
                                <td class="text-muted">{{ $line->description }}</td>
                                <td class="text-muted">{{ $line->branch->name ?? '—' }}</td>
                                <td class="text-end">{{ (float) $line->debit > 0 ? number_format((float) $line->debit, 2) : '' }}</td>
                                <td class="text-end">{{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2) : '' }}</td>
                                @if($showRunning)<td class="text-end fw-semibold">{{ number_format((float) $line->running, 2) }}</td>@endif
                            </tr>
                            @empty
                            <tr><td colspan="{{ $showRunning ? 8 : 7 }}" class="text-center text-muted py-4">No ledger activity for these filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
@endsection
