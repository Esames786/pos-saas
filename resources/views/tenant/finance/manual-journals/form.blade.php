@php
    $accountsByType = $accounts->groupBy('type');
    // MANUAL-JOURNAL-CASH-LINE-GUARD-1 — kaunse khaate group heads hain (chune nahi ja sakte), aur
    // kaunse khaate kisi drawer/bank se jude hain (sirf unhi satron par Cash/Bank khulta hai).
    $groupIds     = $groupAccountIds ?? [];
    $drawerGlIds  = $cashBankAccounts->pluck('account_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
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
            <strong>Tip:</strong> Choose the drawer or bank on the cash line itself — e.g. 1110 on Credit
            when money goes out. An owner drawing of 5,000 in cash is <em>Dr Drawing 5,000 / Cr 1110 Main
            Cash Drawer 5,000</em>, with the drawer picked on the 1110 line. Total debits must equal total
            credits before posting.
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
                        {{-- R5 — "." ya "-" narration nahi; server bhi yahi shart lagata hai. --}}
                        <input type="text" id="description" name="description" class="form-control"
                            placeholder="e.g. Owner drawing - cash, Purchase of office furniture"
                            value="{{ old('description') }}" required minlength="3" maxlength="500"
                            pattern=".*[\p{L}\p{N}].*" title="Write what this entry is for — at least 3 characters, not just a dot or a dash.">
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
                                <th scope="col" style="width:24%">Account <span class="text-danger">*</span></th>
                                {{-- R4 — sirf cash/bank ke khaate wali satar par khulta hai, aur wahan lazmi hai. --}}
                                <th scope="col" style="width:16%">Cash/Bank Account <small class="text-muted">(cash lines)</small></th>
                                {{-- SUPPLIER-FINANCE-DIRECT-1 — Accounts Payable ki satar be-shanakht nahi ho sakti.
                                     Ye khaana sirf tab zinda hota hai jab satar ka account AP (ya uski nasl) ho. --}}
                                <th scope="col" style="width:18%">Supplier <small class="text-muted">(AP lines)</small></th>
                                <th scope="col">Description</th>
                                <th scope="col" style="width:11%">Debit</th>
                                <th scope="col" style="width:11%">Credit</th>
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
                                                    {{-- R3 — group head dikhta hai magar chuna nahi ja sakta. --}}
                                                    <option value="{{ $a->id }}" {{ (int) ($row['account_id'] ?? 0) === $a->id ? 'selected' : '' }}
                                                        @disabled(in_array($a->id, $groupIds, true))>
                                                        {{ $a->code }} — {{ $a->name }}{{ in_array($a->id, $groupIds, true) ? ' (group)' : '' }}
                                                    </option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                    @error("lines.$i.account_id") <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    {{-- R4 — satar ka khaata hi ye picker chalata hai (syncCashCell): cash/bank ke
                                         khaate par sirf usi se jude drawer, warna band. Band control submit nahi hota. --}}
                                    <select name="lines[{{ $i }}][cash_bank_account_id]" class="form-select form-select-sm mj-cash"
                                        @disabled(! in_array((int) ($row['account_id'] ?? 0), $drawerGlIds, true))>
                                        <option value="">— Cash/Bank —</option>
                                        @foreach($cashBankAccounts as $cb)
                                            <option value="{{ $cb->id }}" data-gl="{{ $cb->account_id }}" data-name="{{ $cb->name }}"
                                                {{ (int) ($row['cash_bank_account_id'] ?? 0) === $cb->id ? 'selected' : '' }}>
                                                {{ $cb->code }} — {{ $cb->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="mj-cash-preview small fw-semibold mt-1" hidden></div>
                                    @error("lines.$i.cash_bank_account_id") <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    {{-- counterparty_type select ke saath hidden input se jata hai: AP ki satar par
                                         dono zinda, warna dono disabled — aur disabled control submit hi nahi hota,
                                         is liye ghair-AP satar par koi awara counterparty nahi chipakta. --}}
                                    <input type="hidden" name="lines[{{ $i }}][counterparty_type]" value="supplier"
                                        class="mj-cpt" @disabled(! in_array((int) ($row['account_id'] ?? 0), $apAccountIds ?? [], true))>
                                    <select name="lines[{{ $i }}][supplier_id]" class="form-select form-select-sm mj-supplier"
                                        @disabled(! in_array((int) ($row['account_id'] ?? 0), $apAccountIds ?? [], true))>
                                        <option value="">— Supplier —</option>
                                        @foreach($suppliers as $sup)
                                            <option value="{{ $sup->id }}" {{ (int) ($row['supplier_id'] ?? 0) === $sup->id ? 'selected' : '' }}>
                                                {{ $sup->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error("lines.$i.supplier_id") <div class="text-danger small mt-1">{{ $message }}</div> @enderror
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

    {{-- B3 — ek click, ek entry: button pehle click par band, aur form ka one-time token
         (route par prevent.duplicate.submit) doosri request ko controller tak pahunchne nahi deta. --}}
    @include('tenant.catering.partials.submit-guard')

    {{-- Row template --}}
    <template id="rowTemplate">
        <tr>
            <td>
                <select name="lines[__I__][account_id]" class="form-select form-select-sm" required>
                    <option value="">— Account —</option>
                    @foreach($accountsByType as $type => $typeAccounts)
                        <optgroup label="{{ ucfirst($type) }}">
                            @foreach($typeAccounts as $a)
                                <option value="{{ $a->id }}" @disabled(in_array($a->id, $groupIds, true))>{{ $a->code }} — {{ $a->name }}{{ in_array($a->id, $groupIds, true) ? ' (group)' : '' }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </td>
            <td>
                <select name="lines[__I__][cash_bank_account_id]" class="form-select form-select-sm mj-cash" disabled>
                    <option value="">— Cash/Bank —</option>
                    @foreach($cashBankAccounts as $cb)
                        <option value="{{ $cb->id }}" data-gl="{{ $cb->account_id }}" data-name="{{ $cb->name }}">{{ $cb->code }} — {{ $cb->name }}</option>
                    @endforeach
                </select>
                <div class="mj-cash-preview small fw-semibold mt-1" hidden></div>
            </td>
            <td>
                <input type="hidden" name="lines[__I__][counterparty_type]" value="supplier" class="mj-cpt" disabled>
                <select name="lines[__I__][supplier_id]" class="form-select form-select-sm mj-supplier" disabled>
                    <option value="">— Supplier —</option>
                    @foreach($suppliers as $sup)
                        <option value="{{ $sup->id }}">{{ $sup->name }}</option>
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

    // SUPPLIER-FINANCE-DIRECT-1 — kaunse account Accounts Payable hain (2100 aur uski poori
    // nasl; server se aate hain, yahan hard-code nahi). Backend par bhi wohi shart lagti hai —
    // ye sirf operator ki suhoolat hai, hifazat nahi.
    var AP_ACCOUNT_IDS = @json($apAccountIds ?? []);

    // Ek satar par supplier ka khaana kholo/band karo. Band hone par control DISABLED hota hai,
    // is liye wo submit hi nahi hota — ghair-AP satar par koi awara counterparty nahi jata.
    function syncSupplierCell(tr) {
        var acc = tr.querySelector('select[name*="[account_id]"]');
        var sup = tr.querySelector('.mj-supplier');
        var cpt = tr.querySelector('.mj-cpt');
        if (!acc || !sup) return;

        var isAp = AP_ACCOUNT_IDS.indexOf(parseInt(acc.value, 10)) !== -1;

        sup.disabled = !isAp;
        sup.required = isAp;
        if (cpt) { cpt.disabled = !isAp; }
        if (!isAp) { sup.value = ''; }
    }

    function syncAllSupplierCells() {
        rows.querySelectorAll('tr').forEach(syncSupplierCell);
    }

    // MANUAL-JOURNAL-CASH-LINE-GUARD-1 (R4) — satar ka khaata hi Cash/Bank ka picker chalata hai.
    // Cash/bank ke khaate (jaise 1110) par sirf usi se jude drawer dikhte hain, ek ho to khud chuna
    // jata hai; kisi aur khaate (jaise Drawing) par picker band — wahi ghalti jis se ek live tenant
    // ki drawings Cash Book me "In" ban gayi thin. Server bhi yahi shart lagata hai; ye sirf asaani hai.
    function syncCashCell(tr) {
        var acc = tr.querySelector('select[name*="[account_id]"]');
        var cb  = tr.querySelector('.mj-cash');
        if (!acc || !cb) return;

        var gl = acc.value, matches = [];
        Array.prototype.forEach.call(cb.options, function (opt) {
            if (!opt.value) return;
            var ok = gl !== '' && opt.getAttribute('data-gl') === gl;
            opt.hidden = !ok;
            opt.disabled = !ok;
            if (ok) matches.push(opt);
        });

        var placeholder = cb.options[0];
        if (!matches.length) {
            cb.value = '';
            cb.disabled = true;
            cb.required = false;
            placeholder.textContent = gl ? '— not a cash/bank line —' : '— Cash/Bank —';
        } else {
            cb.disabled = false;
            cb.required = true;
            var current = cb.options[cb.selectedIndex];
            if (!current || !current.value || current.disabled) {
                cb.value = matches.length === 1 ? matches[0].value : '';
            }
            placeholder.textContent = '— choose drawer / bank —';
        }
        syncCashPreview(tr);
    }

    // Har cash satar ke neeche: "Cash Book: OUT 5,000 from Cash in Hand" — wohi jo post hone par
    // Cash Book me utrega (debit = andar, credit = bahar).
    function syncCashPreview(tr) {
        var cb  = tr.querySelector('.mj-cash');
        var out = tr.querySelector('.mj-cash-preview');
        if (!cb || !out) return;

        var d = parseFloat(tr.querySelector('.mj-debit').value) || 0;
        var c = parseFloat(tr.querySelector('.mj-credit').value) || 0;
        var opt = cb.options[cb.selectedIndex];

        if (cb.disabled || !opt || !opt.value || (d <= 0 && c <= 0) || (d > 0 && c > 0)) {
            out.textContent = '';
            out.hidden = true;
            return;
        }

        var isIn = d > 0;
        var amount = (isIn ? d : c).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        out.textContent = 'Cash Book: ' + (isIn ? 'IN ' : 'OUT ') + amount + (isIn ? ' to ' : ' from ') + opt.getAttribute('data-name');
        out.className = 'mj-cash-preview small fw-semibold mt-1 ' + (isIn ? 'text-success' : 'text-danger');
        out.hidden = false;
    }

    function syncAllCashCells() {
        rows.querySelectorAll('tr').forEach(syncCashCell);
    }

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
        syncAllSupplierCells();
        syncAllCashCells();
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
            syncCashPreview(e.target.closest('tr'));
        }
    });

    // Account badalte hi usi satar ka supplier khaana khulta/band hota hai. Delegated hai taake
    // "Add line" se bani rows par bhi chale — wo rows baad me aati hain, is liye seedha bind
    // karna kaam nahi karta.
    rows.addEventListener('change', function (e) {
        if (e.target.name && e.target.name.indexOf('[account_id]') !== -1) {
            syncSupplierCell(e.target.closest('tr'));
            syncCashCell(e.target.closest('tr'));
        }
        if (e.target.classList.contains('mj-cash')) {
            syncCashPreview(e.target.closest('tr'));
        }
    });

    // Load par ek bar: validation fail hone ke baad form purani qeematein wapas dikhata hai,
    // aur us soorat me AP wali satar ka picker pehle se khula hona chahiye.
    syncAllSupplierCells();
    syncAllCashCells();
    recalc();
});
</script>
@endpush
