@php
    $editing = isset($batch);
    $b = $batch ?? null;

    $rows = old('lines');
    if (! $rows) {
        if ($editing) {
            $rows = $b->lines->map(fn ($l) => [
                'account_id'           => $l->account_id,
                'cash_bank_account_id' => $l->cash_bank_account_id,
                'description'          => $l->description,
                'debit'                => (float) $l->debit ?: '',
                'credit'               => (float) $l->credit ?: '',
            ])->all();
        } else {
            $rows = [
                ['account_id' => '', 'cash_bank_account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''],
                ['account_id' => '', 'cash_bank_account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''],
            ];
        }
    }

    $accountsByType = $accounts->groupBy('type');
@endphp
@extends('layouts.app')

@section('title', $editing ? 'Edit Opening Balance' : 'New Opening Balance')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $editing ? 'Edit ' . $b->batch_no : 'New Opening Balance' }}</h4>
                <h6>Finance — Opening Balances / Owner Capital</h6>
            </div>
        </div>

        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif

        <div class="alert alert-info">
            Enter your starting balances as debits and credits. Use an equity account such as
            <strong>3100 Owner Capital</strong> as the balancing line. Debits must equal credits before you can post.
            Tick a <strong>Cash/Bank</strong> account on cash/bank lines so the operational balance is set too.
        </div>

        <form method="POST" action="{{ $editing ? url('/finance/opening-balances/' . $b->id) : url('/finance/opening-balances') }}">
            @csrf
            @if($editing) @method('PUT') @endif

            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label required" for="opening_date">Opening Date</label>
                            <input type="date" id="opening_date" name="opening_date" class="form-control"
                                value="{{ old('opening_date', $editing ? optional($b->opening_date)->format('Y-m-d') : now()->format('Y-m-d')) }}" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="branch_id">Branch <small class="text-muted">(optional)</small></label>
                            <select id="branch_id" name="branch_id" class="form-select">
                                <option value="">All branches</option>
                                @foreach($branches as $br)
                                    <option value="{{ $br->id }}" {{ (int) old('branch_id', $b->branch_id ?? 0) === $br->id ? 'selected' : '' }}>{{ $br->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="batch_no">Batch No <small class="text-muted">(auto if blank)</small></label>
                            <input type="text" id="batch_no" name="batch_no" class="form-control" value="{{ old('batch_no', $b->batch_no ?? '') }}">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="description">Description</label>
                            <input type="text" id="description" name="description" class="form-control" value="{{ old('description', $b->description ?? '') }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Opening Balance Lines</h6>
                    <div class="table-responsive">
                        <table class="table" id="lineTable">
                            <caption class="visually-hidden">Opening balance lines</caption>
                            <thead class="thead-light">
                                <tr>
                                    <th scope="col" style="width:26%">Account</th>
                                    <th scope="col" style="width:20%">Cash/Bank <small class="text-muted">(opt)</small></th>
                                    <th scope="col">Description</th>
                                    <th scope="col" style="width:13%">Debit</th>
                                    <th scope="col" style="width:13%">Credit</th>
                                    <th scope="col" style="width:4%"></th>
                                </tr>
                            </thead>
                            <tbody id="lineRows">
                                @foreach($rows as $i => $row)
                                <tr>
                                    <td>
                                        <select name="lines[{{ $i }}][account_id]" class="form-select" required>
                                            <option value="">— Account —</option>
                                            @foreach($accountsByType as $type => $typeAccounts)
                                                <optgroup label="{{ ucfirst($type) }}">
                                                    @foreach($typeAccounts as $a)
                                                        <option value="{{ $a->id }}" {{ (int) ($row['account_id'] ?? 0) === $a->id ? 'selected' : '' }}>{{ $a->code }} — {{ $a->name }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <select name="lines[{{ $i }}][cash_bank_account_id]" class="form-select">
                                            <option value="">—</option>
                                            @foreach($cashBankAccounts as $cb)
                                                <option value="{{ $cb->id }}" {{ (int) ($row['cash_bank_account_id'] ?? 0) === $cb->id ? 'selected' : '' }}>{{ $cb->code }} — {{ $cb->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="text" name="lines[{{ $i }}][description]" class="form-control" value="{{ $row['description'] ?? '' }}"></td>
                                    <td><input type="number" step="0.0001" min="0" name="lines[{{ $i }}][debit]" class="form-control ob-debit" value="{{ $row['debit'] ?? '' }}"></td>
                                    <td><input type="number" step="0.0001" min="0" name="lines[{{ $i }}][credit]" class="form-control ob-credit" value="{{ $row['credit'] ?? '' }}"></td>
                                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="ti ti-x"></i></button></td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-end">Totals</th>
                                    <th class="text-end"><span id="totDebit">0.00</span></th>
                                    <th class="text-end"><span id="totCredit">0.00</span></th>
                                    <th></th>
                                </tr>
                                <tr>
                                    <th colspan="3" class="text-end">Difference</th>
                                    <th class="text-end" colspan="2"><span id="totDiff" class="badge bg-secondary">0.00</span></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="addRow"><i class="ti ti-plus me-1"></i>Add Line</button>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ $editing ? 'Update Draft' : 'Save Draft' }}</button>
                <a href="{{ url('/finance/opening-balances') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>

        <template id="rowTemplate">
            <tr>
                <td>
                    <select name="lines[__INDEX__][account_id]" class="form-select" required>
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
                    <select name="lines[__INDEX__][cash_bank_account_id]" class="form-select">
                        <option value="">—</option>
                        @foreach($cashBankAccounts as $cb)
                            <option value="{{ $cb->id }}">{{ $cb->code }} — {{ $cb->name }}</option>
                        @endforeach
                    </select>
                </td>
                <td><input type="text" name="lines[__INDEX__][description]" class="form-control"></td>
                <td><input type="number" step="0.0001" min="0" name="lines[__INDEX__][debit]" class="form-control ob-debit"></td>
                <td><input type="number" step="0.0001" min="0" name="lines[__INDEX__][credit]" class="form-control ob-credit"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="ti ti-x"></i></button></td>
            </tr>
        </template>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var rows = document.getElementById('lineRows');
    var tpl = document.getElementById('rowTemplate');
    var addBtn = document.getElementById('addRow');
    var idx = {{ count($rows) }};

    function recalc() {
        var d = 0, c = 0;
        rows.querySelectorAll('.ob-debit').forEach(function (el) { d += parseFloat(el.value) || 0; });
        rows.querySelectorAll('.ob-credit').forEach(function (el) { c += parseFloat(el.value) || 0; });
        document.getElementById('totDebit').textContent = d.toFixed(2);
        document.getElementById('totCredit').textContent = c.toFixed(2);
        var diff = Math.round((d - c) * 10000) / 10000;
        var badge = document.getElementById('totDiff');
        badge.textContent = diff.toFixed(2);
        badge.className = 'badge ' + (diff === 0 && d > 0 ? 'bg-success' : 'bg-danger');
    }

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            rows.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__INDEX__/g, idx++));
            recalc();
        });
    }

    rows.addEventListener('click', function (e) {
        var btn = e.target.closest('.remove-row');
        if (!btn) return;
        if (rows.querySelectorAll('tr').length > 1) { btn.closest('tr').remove(); recalc(); }
    });

    rows.addEventListener('input', function (e) {
        if (e.target.classList.contains('ob-debit') || e.target.classList.contains('ob-credit')) recalc();
    });

    recalc();
});
</script>
@endpush
