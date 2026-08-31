@extends('layouts.app')

@section('title', 'Close Branch Shifts')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Close Branch</h1>
        <p class="fw-medium">Close all open terminal shifts of a branch at once.</p>
    </div>
    <a href="{{ url('/shifts') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/shifts-close-branch') }}" class="d-flex align-items-end gap-2 flex-wrap">
            <div>
                <label for="branch_id" class="form-label">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Select branch…</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((string) $selectedBranchId === (string) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>
</div>

@if($selectedBranchId)
    @if($openShifts->isEmpty())
        <div class="alert alert-info">This branch has no open shifts.</div>
    @else
        @php
            $sumExpected = $openShifts->sum(fn ($s) => (float) $s->expected_cash);
            $sumOpening  = $openShifts->sum(fn ($s) => (float) $s->opening_cash);
            $sumSales    = $openShifts->sum(fn ($s) => (float) $s->total_sales);
        @endphp
        <form method="POST" action="{{ url('/shifts-close-branch') }}">
            @csrf
            <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">

            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex gap-4 flex-wrap mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" id="mode-per" value="per_terminal" checked>
                            <label class="form-check-label" for="mode-per">Count each terminal</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" id="mode-total" value="branch_total">
                            <label class="form-check-label" for="mode-total">One total for the branch</label>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Terminal</th>
                                    <th class="text-end">Opening</th>
                                    <th class="text-end">Sales</th>
                                    <th class="text-end">Expected</th>
                                    <th class="text-end col-counted" style="min-width:180px">Counted</th>
                                    <th class="text-end col-counted" style="min-width:150px">Difference</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($openShifts as $shift)
                                    <tr>
                                        {{-- SHIFT-CANCELLATIONS-1: the breakup sits UNDER the terminal
                                             name, not in extra columns — the counting columns have to
                                             stay clear while someone is counting a drawer. Only the
                                             parts that actually happened are printed, so a plain cash
                                             terminal shows nothing extra. --}}
                                        <td>
                                            {{ $shift->terminal?->name ?? ('Terminal #' . $shift->terminal_id) }}
                                            @php
                                                $bits = [];
                                                if ((float) $shift->total_cash != 0.0)          { $bits[] = 'cash ' . number_format((float) $shift->total_cash, 2); }
                                                if ((float) $shift->total_card != 0.0)          { $bits[] = 'card ' . number_format((float) $shift->total_card, 2); }
                                                if ((float) $shift->total_bank_transfer != 0.0) { $bits[] = 'bank ' . number_format((float) $shift->total_bank_transfer, 2); }
                                                if ((float) $shift->total_cheque != 0.0)        { $bits[] = 'cheque ' . number_format((float) $shift->total_cheque, 2); }
                                                if ((float) $shift->total_refunds != 0.0)       { $bits[] = 'refunds −' . number_format((float) $shift->total_refunds, 2); }
                                                if ((float) $shift->total_discount != 0.0)      { $bits[] = 'discount ' . number_format((float) $shift->total_discount, 2); }
                                                $c = $cancelledOrders[$shift->id] ?? null;
                                                $v = $voidedLines[$shift->id] ?? null;
                                            @endphp
                                            @if($bits)
                                                <div class="small text-muted mt-1">{!! implode(' &middot; ', $bits) !!}</div>
                                            @endif
                                            @if($c || $v)
                                                <div class="small text-warning mt-1">
                                                    cancelled
                                                    @if($c) {{ number_format((float) $c->amount, 2) }}
                                                        ({{ (int) $c->bills }} bill{{ (int) $c->bills === 1 ? '' : 's' }})@endif
                                                    @if($c && $v) &middot; @endif
                                                    @if($v) {{ rtrim(rtrim(number_format((float) $v->units, 2), '0'), '.') }}
                                                        item{{ (float) $v->units == 1.0 ? '' : 's' }} voided@endif
                                                </div>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((float) $shift->opening_cash, 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $shift->total_sales, 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $shift->expected_cash, 2) }}</td>
                                        <td class="text-end col-counted">
                                            <input type="number" min="0" step="0.01" class="form-control form-control-sm text-end cash-counted"
                                                data-expected="{{ (float) $shift->expected_cash }}"
                                                name="counted[{{ $shift->id }}]"
                                                value="{{ old('counted.' . $shift->id, number_format((float) $shift->expected_cash, 2, '.', '')) }}">
                                        </td>
                                        {{-- CASH-SHORTAGE-1: live difference so the cashier sees the shortage before closing --}}
                                        <td class="text-end col-counted cash-diff small text-muted">—</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td>Total</td>
                                    <td class="text-end">{{ number_format($sumOpening, 2) }}</td>
                                    <td class="text-end">{{ number_format($sumSales, 2) }}</td>
                                    <td class="text-end">{{ number_format($sumExpected, 2) }}</td>
                                    <td class="text-end col-branch-total" style="display:none">
                                        <input type="number" min="0" step="0.01" class="form-control form-control-sm text-end cash-counted"
                                            data-expected="{{ (float) $sumExpected }}"
                                            name="branch_counted_cash" value="{{ old('branch_counted_cash', number_format($sumExpected, 2, '.', '')) }}">
                                    </td>
                                    <td class="text-end col-branch-total cash-diff small" style="display:none">—</td>
                                    <td class="text-end col-counted"></td>
                                    <td class="text-end col-counted cash-total-diff small">—</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="form-text mb-1">“One total for the branch” closes each terminal at its expected amount and records the branch total + variance on a Daily Closing.</p>
                    {{-- CASH-SHORTAGE-1 --}}
                    <div class="alert alert-warning py-2 mb-0 small" role="note">
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                        <strong>If the counted cash is less than expected</strong>, the shift still closes and the shortage is recorded.
                        A <strong>draft expense voucher</strong> is created automatically under the
                        <strong>“{{ \App\Services\Finance\CashShortageExpenseService::CATEGORY_NAME }}”</strong> category
                        (Finance → Expenses) so the finance team can review and settle it later. Nothing is posted to the
                        accounts until they post that voucher.
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <label for="closing_notes" class="form-label">Closing Notes</label>
                    <input type="text" id="closing_notes" name="closing_notes" class="form-control" maxlength="500" value="{{ old('closing_notes') }}">
                </div>
            </div>

            <button type="submit" class="btn btn-danger">
                <i class="ti ti-lock me-1" aria-hidden="true"></i>Close {{ $openShifts->count() }} shift(s)
            </button>
            <a href="{{ url('/shifts') }}" class="btn btn-light ms-2">Cancel</a>
        </form>

        @push('scripts')
        <script>
        (function () {
            function setMode(total) {
                document.querySelectorAll('.col-counted').forEach(function (c) { c.style.display = total ? 'none' : ''; });
                document.querySelectorAll('.col-branch-total').forEach(function (c) { c.style.display = total ? '' : 'none'; });
                // per-terminal counted inputs are disabled in branch-total mode so they don't submit.
                document.querySelectorAll('input[name^="counted["]').forEach(function (i) { i.disabled = total; });
                var bt = document.querySelector('input[name="branch_counted_cash"]');
                if (bt) bt.disabled = !total;
            }
            document.getElementById('mode-per').addEventListener('change', function () { setMode(false); refreshDiffs(); });
            document.getElementById('mode-total').addEventListener('change', function () { setMode(true); refreshDiffs(); });
            setMode(document.getElementById('mode-total').checked);

            /* CASH-SHORTAGE-1: live difference (short / over) per row + a per-terminal grand total. */
            function money(v) { return Number(v).toFixed(2); }
            function paint(cell, diff) {
                if (!cell) { return; }
                if (Math.abs(diff) < 0.005) {
                    cell.textContent = 'Exact';
                    cell.className = cell.className.replace(/\btext-(danger|success|muted)\b/g, '') + ' text-muted';
                } else if (diff < 0) {
                    cell.textContent = 'Short by ' + money(-diff);
                    cell.className = cell.className.replace(/\btext-(danger|success|muted)\b/g, '') + ' text-danger fw-semibold';
                } else {
                    cell.textContent = 'Over by ' + money(diff);
                    cell.className = cell.className.replace(/\btext-(danger|success|muted)\b/g, '') + ' text-success fw-semibold';
                }
            }
            function refreshDiffs() {
                var perTerminalTotal = 0;
                document.querySelectorAll('tbody input.cash-counted').forEach(function (input) {
                    var expected = parseFloat(input.dataset.expected || '0') || 0;
                    var counted = parseFloat(input.value || '0') || 0;
                    var diff = counted - expected;
                    perTerminalTotal += diff;
                    var row = input.closest('tr');
                    paint(row ? row.querySelector('.cash-diff') : null, diff);
                });
                paint(document.querySelector('.cash-total-diff'), perTerminalTotal);

                var bt = document.querySelector('input[name="branch_counted_cash"]');
                if (bt) {
                    var expectedTotal = parseFloat(bt.dataset.expected || '0') || 0;
                    paint(document.querySelector('.col-branch-total.cash-diff'), (parseFloat(bt.value || '0') || 0) - expectedTotal);
                }
            }
            document.querySelectorAll('input.cash-counted').forEach(function (input) {
                input.addEventListener('input', refreshDiffs);
            });
            refreshDiffs();
        })();
        </script>
        @endpush
    @endif
@endif
@endsection
