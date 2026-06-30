@php
    $accountsByType = $accounts->groupBy('type');
    $rows = old('lines', [
        ['account_id' => '', 'cash_bank_account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''],
        ['account_id' => '', 'cash_bank_account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''],
        ['account_id' => '', 'cash_bank_account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''],
    ]);
@endphp

@extends('layouts.app')

@section('title', 'New Manual Journal Entry')

@section('content')
    <div class="page-header">
        <div class="page-title">
            <h4>New Manual Journal Entry</h4>
            <h6>Finance — Post any balanced debit/credit entry directly to the General Ledger</h6>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="alert alert-info d-flex gap-2 align-items-start">
        <i class="ti ti-info-circle fs-5 mt-1 flex-shrink-0"></i>
        <div>
            <strong>When to use this:</strong> asset purchases (Dr Asset / Cr Bank), capital injections
            (Dr Bank / Cr Owner Capital), inter-account transfers (Dr Account A / Cr Account B),
            depreciation, accruals, or any correction not covered by a specific workflow.
            <br>
            <strong>Tip:</strong> Select a <em>Cash/Bank Account</em> on any cash or bank line to keep
            the operational balance in sync. Total debits must equal total credits before posting.
        </div>
    </div>

    <form method="POST" action="{{ url('/finance/manual-journals') }}">
        @csrf

        {{-- Header --}}
        <div class="card">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-2 mb-3">
                        <label class="form-label required" for="entry_date">Entry Date</label>
                        <input type="date" id="entry_date" name="entry_date" class="form-control"
                            value="{{ old('entry_date', now()->format('Y-m-d')) }}" required>
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label required" for="description">Description / Memo</label>
                        <input type="text" id="description" name="description" class="form-control"
                            placeholder="e.g. Purchase of office furniture, Depreciation – June 2026"
                            value="{{ old('description') }}" required maxlength="500">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="reference_no">Reference No <small class="text-muted">(optional)</small></label>
                        <input type="text" id="reference_no" name="reference_no" class="form-control"
                            placeholder="Invoice # / cheque # / etc."
                            value="{{ old('reference_no') }}" maxlength="100">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label" for="branch_id">Branch</label>
                        <select id="branch_id" name="branch_id" class="form-select">
                            <option value="">All / None</option>
                            @foreach($branches as $br)
                                <option value="{{ $br->id }}" {{ (int) old('branch_id') === $br->id ? 'selected' : '' }}>
                                    {{ $br->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        {{-- Lines --}}
        <div class="card">
            <div class="card-body">
                <h6 class="fw-bold mb-3">Journal Lines <small class="text-muted fw-normal">(min 2 lines — debits must equal credits)</small></h6>
                <div class="table-responsive">
                    <table class="table table-borderless align-middle" id="lineTable">
                        <caption class="visually-hidden">Journal lines</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col" style="width:28%">Account <span class="text-danger">*</span></th>
                                <th scope="col" style="width:20%">Cash/Bank Account <small class="text-muted">(opt)</small></th>
                                <th scope="col">Description</th>
                                <th scope="col" style="width:12%">Debit</th>
                                <th scope="col" style="width:12%">Credit</th>
                                <th scope="col" style="width:4%"></th>
                            </tr>
                        </thead>
                        <tbody id="lineRows">
                            @foreach($rows as $i => $row)
                            <tr>
                                <td>
                                    <select name="lines[{{ $i }}][account_id]" class="form-select form-select-sm" required>
                                        <option value="">— Account —</option>
                                        @foreach($accountsByType as $type => $typeAccounts)
                                            <optgroup label="{{ ucfirst($type) }}">
                                                @foreach($typeAccounts as $a)
                                                    <option value="{{ $a->id }}" {{ (int) ($row['account_id'] ?? 0) === $a->id ? 'selected' : '' }}>
                                                        {{ $a->code }} — {{ $a->name }}
                                                    </option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="lines[{{ $i }}][cash_bank_account_id]" class="form-select form-select-sm">
                                        <option value="">— None —</option>
                                        @foreach($cashBankAccounts as $cb)
                                            <option value="{{ $cb->id }}" {{ (int) ($row['cash_bank_account_id'] ?? 0) === $cb->id ? 'selected' : '' }}>
                                                {{ $cb->code }} — {{ $cb->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="lines[{{ $i }}][description]" class="form-control form-control-sm"
                                        value="{{ $row['description'] ?? '' }}" placeholder="Optional note for this line">
                                </td>
                                <td>
                                    <input type="number" step="0.0001" min="0" name="lines[{{ $i }}][debit]"
                                        class="form-control form-control-sm text-end mj-debit"
                                        value="{{ $row['debit'] ?? '' }}" placeholder="0.00">
                                </td>
                                <td>
                                    <input type="number" step="0.0001" min="0" name="lines[{{ $i }}][credit]"
                                        class="form-control form-control-sm text-end mj-credit"
                                        value="{{ $row['credit'] ?? '' }}" placeholder="0.00">
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-row"
                                        title="Remove line"><i class="ti ti-x"></i></button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-semibold">
                                <td colspan="3" class="text-end">Totals</td>
                                <td class="text-end" id="totDebit">0.00</td>
                                <td class="text-end" id="totCredit">0.00</td>
                                <td></td>
                            </tr>
                            <tr class="table-light">
                                <td colspan="3" class="text-end text-muted small">Difference (must be 0 to post)</td>
                                <td class="text-end" colspan="2">
                                    <span id="totDiff" class="badge bg-secondary fs-6">0.00</span>
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="addRow">
                    <i class="ti ti-plus me-1"></i>Add Line
                </button>
            </div>
        </div>

        <div class="d-flex gap-2 mb-4">
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="ti ti-check me-1"></i>Post Journal Entry
            </button>
            <a href="{{ url('/finance/manual-journals') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

    {{-- Row template --}}
    <template id="rowTemplate">
        <tr>
            <td>
                <select name="lines[__I__][account_id]" class="form-select form-select-sm" required>
                    <option value="">— Account —</option>
                    @foreach($accountsByType as $type => $typeAccounts)
                        <optgroup label="{{ ucfirst($type) }}">
                            @foreach($typeAccounts as $a)
                                <option value="{{ $a->id }}">{{ $a->code }} — {{ $a->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </td>
            <td>
                <select name="lines[__I__][cash_bank_account_id]" class="form-select form-select-sm">
                    <option value="">— None —</option>
                    @foreach($cashBankAccounts as $cb)
                        <option value="{{ $cb->id }}">{{ $cb->code }} — {{ $cb->name }}</option>
                    @endforeach
                </select>
            </td>
            <td><input type="text" name="lines[__I__][description]" class="form-control form-control-sm" placeholder="Optional note"></td>
            <td><input type="number" step="0.0001" min="0" name="lines[__I__][debit]"  class="form-control form-control-sm text-end mj-debit"  placeholder="0.00"></td>
            <td><input type="number" step="0.0001" min="0" name="lines[__I__][credit]" class="form-control form-control-sm text-end mj-credit" placeholder="0.00"></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="ti ti-x"></i></button></td>
        </tr>
    </template>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var rows    = document.getElementById('lineRows');
    var tpl     = document.getElementById('rowTemplate');
    var addBtn  = document.getElementById('addRow');
    var idx     = {{ count($rows) }};

    function recalc() {
        var d = 0, c = 0;
        rows.querySelectorAll('.mj-debit').forEach(function (el)  { d += parseFloat(el.value) || 0; });
        rows.querySelectorAll('.mj-credit').forEach(function (el) { c += parseFloat(el.value) || 0; });
        d = Math.round(d * 10000) / 10000;
        c = Math.round(c * 10000) / 10000;
        document.getElementById('totDebit').textContent  = d.toFixed(2);
        document.getElementById('totCredit').textContent = c.toFixed(2);
        var diff  = Math.round((d - c) * 10000) / 10000;
        var badge = document.getElementById('totDiff');
        badge.textContent = diff.toFixed(4);
        badge.className   = 'badge fs-6 ' + (diff === 0 && d > 0 ? 'bg-success' : 'bg-danger');
        // Disable submit when not balanced or no value.
        document.getElementById('submitBtn').disabled = (diff !== 0 || d <= 0);
    }

    addBtn.addEventListener('click', function () {
        rows.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__I__/g, idx++));
        recalc();
    });

    rows.addEventListener('click', function (e) {
        var btn = e.target.closest('.remove-row');
        if (!btn) return;
        var allRows = rows.querySelectorAll('tr');
        if (allRows.length > 2) { btn.closest('tr').remove(); recalc(); }
        else { alert('A journal entry needs at least 2 lines.'); }
    });

    rows.addEventListener('input', function (e) {
        if (e.target.classList.contains('mj-debit') || e.target.classList.contains('mj-credit')) {
            recalc();
        }
    });

    recalc();
});
</script>
@endpush
