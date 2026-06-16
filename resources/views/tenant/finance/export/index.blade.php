@extends('layouts.app')

@section('title', 'Accounting Export')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Accounting Export</h4>
                <h6>Download a dated Financial Statement Pack for clients &amp; auditors</h6>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="GET" action="{{ url('/finance/export') }}">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="date_from">From</label>
                            <input type="date" id="date_from" name="date_from" class="form-control" value="{{ $filters['date_from'] }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="date_to">To <small class="text-muted">(also as-of for TB / Balance Sheet)</small></label>
                            <input type="date" id="date_to" name="date_to" class="form-control" value="{{ $filters['date_to'] }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="branch_id">Branch</label>
                            <select id="branch_id" name="branch_id" class="form-select">
                                <option value="">All branches</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" {{ (string)($filters['branch_id'] ?? '') === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <hr>
                    <label class="form-label fw-semibold">Include statements</label>
                    <div class="row g-2 mb-3">
                        @foreach($sections as $key => $label)
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="sections[]" value="{{ $key }}" id="sec-{{ $key }}" checked>
                                    <label class="form-check-label" for="sec-{{ $key }}">{{ $label }}</label>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <button type="submit" name="export_csv" value="1" class="btn btn-primary">
                        <i class="ti ti-download me-1"></i>Download Financial Statement Pack (CSV)
                    </button>
                </form>
            </div>
        </div>

        <div class="alert alert-info mt-3">
            <i class="ti ti-info-circle me-1"></i>
            Produces a single Excel-friendly CSV (UTF-8) containing the selected statements, built from posted general-ledger journals.
            A multi-sheet <strong>.xlsx</strong> workbook can be added later once the PHP <code>zip</code> extension is enabled.
        </div>

        <div class="card">
            <div class="card-body">
                <h6 class="fw-bold mb-2">Individual report exports</h6>
                <p class="text-muted small mb-3">Each finance report also has its own CSV button:</p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ url('/finance/trial-balance') }}" class="btn btn-sm btn-outline-secondary">Trial Balance</a>
                    <a href="{{ url('/finance/profit-loss') }}" class="btn btn-sm btn-outline-secondary">Profit &amp; Loss</a>
                    <a href="{{ url('/finance/branch-profit-loss') }}" class="btn btn-sm btn-outline-secondary">Branch-wise P&amp;L</a>
                    <a href="{{ url('/finance/balance-sheet') }}" class="btn btn-sm btn-outline-secondary">Balance Sheet</a>
                    <a href="{{ url('/finance/general-ledger') }}" class="btn btn-sm btn-outline-secondary">General Ledger</a>
                    <a href="{{ url('/finance/journal-entries') }}" class="btn btn-sm btn-outline-secondary">Journal Entries</a>
                </div>
            </div>
        </div>
@endsection
