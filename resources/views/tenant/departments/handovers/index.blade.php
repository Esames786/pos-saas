@extends('layouts.app')

@section('title', 'Department Handovers')

@section('content')
    <div class="page-header">
        <div class="page-title">
            <h4>Third-Party Department Handovers</h4>
            <h6>Sales handed to department owners (money-only — stock is never affected)</h6>
        </div>
        <div class="page-btn">
            <a href="{{ url('/reports/departments/sales') }}" class="btn btn-primary btn-sm">
                <i class="ti ti-arrow-left me-1"></i>Department Sales report
            </a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end mb-3">
                <div class="col-sm-4">
                    <label class="form-label mb-1">Department</label>
                    <select name="department_id" class="form-select">
                        <option value="">All third-party departments</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept->id }}" {{ (string)($filters['department_id'] ?? '') === (string)$dept->id ? 'selected' : '' }}>
                                {{ $dept->name }} @if($dept->owner_name)— {{ $dept->owner_name }}@endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label mb-1">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        @foreach(['pending_payout' => 'Pending payout', 'settled' => 'Settled', 'reversed' => 'Reversed'] as $val => $label)
                            <option value="{{ $val }}" {{ ($filters['status'] ?? '') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-2">
                    <button type="submit" class="btn btn-outline-primary btn-sm">Filter</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-nowrap align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Ref</th>
                            <th>Department / Owner</th>
                            <th>Period</th>
                            <th class="text-end">Amount</th>
                            <th>Status</th>
                            <th>Entries</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($handovers as $h)
                        <tr>
                            <td>DH-{{ $h->id }}</td>
                            <td>
                                <span class="fw-semibold">{{ $h->department?->name ?? ('#' . $h->department_id) }}</span>
                                @if($h->department?->owner_name)<div class="small text-muted">{{ $h->department->owner_name }}</div>@endif
                            </td>
                            <td>{{ $h->period_from->format('Y-m-d') }} → {{ $h->period_to->format('Y-m-d') }}</td>
                            <td class="text-end fw-semibold">{{ number_format((float) $h->handover_total, 2) }}</td>
                            <td>
                                @if($h->status === 'pending_payout')
                                    <span class="badge bg-warning-subtle text-warning-emphasis">Pending payout</span>
                                @elseif($h->status === 'settled')
                                    <span class="badge bg-success-subtle text-success-emphasis">Settled</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">Reversed</span>
                                @endif
                            </td>
                            <td class="small">
                                @if($h->reclassEntry)<div><a href="{{ url('/finance/journal-entries/' . $h->reclass_journal_entry_id) }}">{{ $h->reclassEntry->entry_no }}</a> <span class="text-muted">reclass</span></div>@endif
                                @if($h->payoutEntry)<div><a href="{{ url('/finance/journal-entries/' . $h->payout_journal_entry_id) }}">{{ $h->payoutEntry->entry_no }}</a> <span class="text-muted">payout</span></div>@endif
                            </td>
                            <td class="text-end">
                                @if($h->status === 'pending_payout')
                                    @can('tenant.departments.handovers.payout')
                                    <form method="POST" action="{{ url('/departments/handovers/' . $h->id . '/payout') }}" class="d-inline-flex gap-1 align-items-center"
                                          onsubmit="return confirm('Record payout of {{ number_format((float) $h->handover_total, 2) }} to {{ $h->department?->owner_name ?: 'the owner' }}?');">
                                        @csrf
                                        <select name="cash_bank_account_id" class="form-select form-select-sm" style="width:auto" required>
                                            <option value="">Pay from…</option>
                                            @foreach($cashBankAccounts as $cb)
                                                <option value="{{ $cb->id }}">{{ $cb->name }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-success btn-sm">Record payout</button>
                                    </form>
                                    @endcan
                                @endif
                                @if($h->status !== 'reversed')
                                    @can('tenant.departments.handovers.reverse')
                                    <form method="POST" action="{{ url('/departments/handovers/' . $h->id . '/reverse') }}" class="d-inline"
                                          onsubmit="return confirm('Reverse handover DH-{{ $h->id }}? This restores the sales (and cash, if paid).');">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Reverse</button>
                                    </form>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No handovers yet. Use the “Hand over” button in the Department Sales report.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
