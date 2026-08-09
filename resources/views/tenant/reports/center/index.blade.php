@extends('layouts.app')

@section('title', 'Sales Report Center')

@php
    $fmt = fn ($v) => number_format((float) $v, 2);
    $qs = fn (array $extra = []) => http_build_query(array_filter(array_merge(request()->except('page'), $extra), fn ($v) => $v !== null && $v !== ''));
@endphp

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h1 class="mb-0">Sales Report Center</h1>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-dark btn-sm" href="{{ url('/reports/center?' . $qs(['preset' => 'z'])) }}">Z Report (End of Day)</a>
        <a class="btn btn-outline-secondary btn-sm" target="_blank" href="{{ url('/reports/center/print?' . $qs(['mode' => 'thermal'])) }}">Print Thermal</a>
        <a class="btn btn-outline-secondary btn-sm" target="_blank" href="{{ url('/reports/center/print?' . $qs(['mode' => 'a4'])) }}">Print A4</a>
        <a class="btn btn-outline-primary btn-sm" href="{{ url('/reports/center/export?' . $qs()) }}">Export CSV</a>
        <form method="POST" action="{{ url('/reports/center/email') }}" class="d-inline">
            @csrf
            @foreach(request()->except('_token') as $k => $v)
                @if(!is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@else @foreach($v as $vv)<input type="hidden" name="{{ $k }}[]" value="{{ $vv }}">@endforeach @endif
            @endforeach
            <button class="btn btn-outline-success btn-sm">Email Now</button>
        </form>
    </div>
</div>

@if(session('status'))<div class="alert alert-success py-2">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

{{-- ── filters ── --}}
<form method="GET" action="{{ url('/reports/center') }}" class="card mb-3">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <div class="card-body py-2 row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">From</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">To</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Branch</label>
            <select name="branch_id" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected(($filters['branch_ids'][0] ?? null) == $b->id)>{{ $b->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Terminal</label>
            <select name="terminal_id" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach($terminals as $t)<option value="{{ $t->id }}" @selected($filters['terminal_id'] == $t->id)>{{ $t->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Order Type</label>
            <select name="order_type" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach($orderTypes as $key => $label)<option value="{{ $key }}" @selected($filters['order_type'] === $key)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Waiter</label>
            <select name="waiter_id" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach($waiters as $w)<option value="{{ $w->id }}" @selected($filters['waiter_id'] == $w->id)>{{ $w->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Category</label>
            <select name="category_id" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach($categories as $c)<option value="{{ $c->id }}" @selected($filters['category_id'] == $c->id)>{{ $c->parent_id ? '— ' : '' }}{{ $c->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Payment Method</label>
            <select name="payment_method_id" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach($paymentMethods as $pm)<option value="{{ $pm->id }}" @selected($filters['payment_method_id'] == $pm->id)>{{ $pm->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-12 col-md-3 d-flex gap-1">
            <button class="btn btn-primary btn-sm">Apply</button>
            @foreach(['today' => [now()->toDateString(), now()->toDateString()], 'yesterday' => [now()->subDay()->toDateString(), now()->subDay()->toDateString()], 'this week' => [now()->startOfWeek()->toDateString(), now()->toDateString()], 'this month' => [now()->startOfMonth()->toDateString(), now()->toDateString()]] as $label => [$from, $to])
                <a class="btn btn-light btn-sm" href="{{ url('/reports/center?' . $qs(['date_from' => $from, 'date_to' => $to])) }}">{{ ucfirst($label) }}</a>
            @endforeach
        </div>
    </div>
</form>

{{-- ── KPI cards (always) ── --}}
@php $o = $data['overview']; @endphp
<div class="row g-2 mb-3">
    @foreach([
        'Orders' => number_format($o['orders']),
        'Net Qty' => $fmt($o['net_qty']) . ' (sold ' . $fmt($o['sold_qty']) . ' − ret ' . $fmt($o['returned_qty']) . ')',
        'Gross Sales' => $fmt($o['gross_sales']),
        'Discount' => $fmt($o['discount']),
        'Tax' => $fmt($o['tax']),
        'Service Charge' => $fmt($o['service_charge']),
        'Delivery Charge' => $fmt($o['delivery_charge']),
        'Returns' => $fmt($o['returns_amount']),
        'Net Sales' => $fmt($o['net_sales']),
    ] as $label => $value)
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100"><div class="card-body py-2">
                <div class="text-muted small">{{ $label }}</div>
                <div class="fw-bold">{{ $value }}</div>
            </div></div>
        </div>
    @endforeach
</div>

{{-- ── tabs ── --}}
<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
    @foreach(['overview' => 'Overview', 'categories' => 'Categories', 'items' => 'Items', 'waiters' => 'Waiters', 'order_types' => 'Order Types', 'departments' => 'Departments', 'detailed' => 'Details', 'cash_bank' => 'Cash & Bank'] as $key => $label)
        <li class="nav-item"><a class="nav-link @if($tab === $key) active @endif" href="{{ url('/reports/center?' . $qs(['tab' => $key, 'preset' => null])) }}">{{ $label }}</a></li>
    @endforeach
</ul>

@if($tab === 'overview' || $tab === 'z')
    <div class="card mb-3"><div class="card-body">
        <h6>Payments</h6>
        <div class="table-responsive"><table class="table table-sm w-auto">
            @foreach($o['payments'] as $method => $amount)
                <tr><td class="text-capitalize">{{ str_replace('_', ' ', $method) }}</td><td class="text-end">{{ $fmt($amount) }}</td></tr>
            @endforeach
        </table></div>
        <p class="text-muted small mb-0">Sales reports answer “what did we sell?”. Cash &amp; Bank answers “where did money come from / go?” — see its tab; opening floats are never revenue.</p>
    </div></div>
@endif

@if(isset($data['categories']))
    <div class="card mb-3"><div class="card-body table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Category</th><th class="text-end">Orders</th><th class="text-end">Sold</th><th class="text-end">Ret</th><th class="text-end">Net Qty</th><th class="text-end">Gross</th><th class="text-end">Disc</th><th class="text-end">Tax</th><th class="text-end">Returns</th><th class="text-end">Net</th></tr></thead>
            <tbody>
            @foreach($data['categories'] as $root)
                <tr class="table-light fw-semibold"><td>{{ $root['name'] }}</td><td class="text-end">{{ $root['orders'] }}</td><td class="text-end">{{ $fmt($root['sold_qty']) }}</td><td class="text-end">{{ $fmt($root['returned_qty']) }}</td><td class="text-end">{{ $fmt($root['net_qty']) }}</td><td class="text-end">{{ $fmt($root['gross']) }}</td><td class="text-end">{{ $fmt($root['discount']) }}</td><td class="text-end">{{ $fmt($root['tax']) }}</td><td class="text-end">{{ $fmt($root['returns_amount']) }}</td><td class="text-end">{{ $fmt($root['net']) }}</td></tr>
                @foreach($root['children'] as $c)
                    @if($c['id'] !== $root['id'])
                        <tr><td class="ps-4">↳ {{ $c['name'] }}</td><td class="text-end">{{ $c['orders'] }}</td><td class="text-end">{{ $fmt($c['sold_qty']) }}</td><td class="text-end">{{ $fmt($c['returned_qty']) }}</td><td class="text-end">{{ $fmt($c['net_qty']) }}</td><td class="text-end">{{ $fmt($c['gross']) }}</td><td class="text-end">{{ $fmt($c['discount']) }}</td><td class="text-end">{{ $fmt($c['tax']) }}</td><td class="text-end">{{ $fmt($c['returns_amount']) }}</td><td class="text-end">{{ $fmt($c['net']) }}</td></tr>
                    @endif
                @endforeach
            @endforeach
            </tbody>
        </table>
    </div></div>
@endif

@if(isset($data['items']))
    <div class="card mb-3"><div class="card-body table-responsive">
        <div class="mb-2">
            Sort:
            @foreach(['net' => 'Amount', 'qty' => 'Quantity', 'returns' => 'Returns', 'alpha' => 'A–Z'] as $s => $sl)
                <a class="btn btn-sm {{ request('sort', 'net') === $s ? 'btn-secondary' : 'btn-light' }}" href="{{ url('/reports/center?' . $qs(['tab' => 'items', 'sort' => $s])) }}">{{ $sl }}</a>
            @endforeach
        </div>
        <table class="table table-sm">
            <thead><tr><th>Item</th><th>Variant</th><th>Category</th><th class="text-end">Sold</th><th class="text-end">Ret</th><th class="text-end">Net Qty</th><th class="text-end">Gross</th><th class="text-end">Disc</th><th class="text-end">Tax</th><th class="text-end">Net</th></tr></thead>
            <tbody>
            @foreach($data['items'] as $r)
                <tr><td>{{ $r->item }}</td><td>{{ $r->variant }}</td><td>{{ $r->category }}</td><td class="text-end">{{ $fmt($r->sold_qty) }}</td><td class="text-end">{{ $fmt($r->returned_qty) }}</td><td class="text-end">{{ $fmt($r->net_qty) }}</td><td class="text-end">{{ $fmt($r->gross) }}</td><td class="text-end">{{ $fmt($r->discount) }}</td><td class="text-end">{{ $fmt($r->tax) }}</td><td class="text-end">{{ $fmt($r->net) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div></div>
@endif

@foreach(['waiters' => 'Waiter', 'order_types' => 'Order Type'] as $dimKey => $dimLabel)
    @if(isset($data[$dimKey]))
        <div class="card mb-3"><div class="card-body table-responsive">
            <table class="table table-sm">
                <thead><tr><th>{{ $dimLabel }}</th><th class="text-end">Orders</th><th class="text-end">Sold</th><th class="text-end">Ret Qty</th><th class="text-end">Net Qty</th><th class="text-end">Gross</th><th class="text-end">Disc</th><th class="text-end">Tax</th><th class="text-end">Svc</th><th class="text-end">Delivery</th><th class="text-end">Grand</th><th class="text-end">Returns</th><th class="text-end">Net Sales</th></tr></thead>
                <tbody>
                @foreach($data[$dimKey] as $r)
                    <tr @if($r['label'] === 'Unassigned') class="table-warning" @endif>
                        <td>{{ $r['label'] }}</td><td class="text-end">{{ $r['orders'] }}</td><td class="text-end">{{ $fmt($r['sold_qty']) }}</td><td class="text-end">{{ $fmt($r['returned_qty']) }}</td><td class="text-end">{{ $fmt($r['net_qty']) }}</td><td class="text-end">{{ $fmt($r['gross']) }}</td><td class="text-end">{{ $fmt($r['discount']) }}</td><td class="text-end">{{ $fmt($r['tax']) }}</td><td class="text-end">{{ $fmt($r['service_charge']) }}</td><td class="text-end">{{ $fmt($r['delivery_charge']) }}</td><td class="text-end">{{ $fmt($r['grand_total']) }}</td><td class="text-end">{{ $fmt($r['returns_amount']) }}</td><td class="text-end fw-semibold">{{ $fmt($r['net_sales']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div></div>
    @endif
@endforeach

@if(isset($data['departments']))
    <div class="card mb-3"><div class="card-body table-responsive">
        <p class="text-muted small">Department attribution uses the existing department mapping (products/categories → departments); unmapped products appear under Unassigned.</p>
        <table class="table table-sm">
            <thead><tr><th>Department</th><th class="text-end">Qty</th><th class="text-end">Net</th></tr></thead>
            <tbody>
            @foreach(($data['departments']['rows'] ?? []) as $row)
                <tr><td>{{ $row['department'] ?? ($row['name'] ?? '—') }}</td><td class="text-end">{{ $fmt($row['qty'] ?? ($row['qty_sold'] ?? 0)) }}</td><td class="text-end">{{ $fmt($row['net'] ?? ($row['net_total'] ?? 0)) }}</td></tr>
            @endforeach
            @foreach(($data['departments']['unassigned'] ?? []) as $row)
                <tr class="table-warning"><td>Unassigned — {{ $row['product'] }}</td><td class="text-end">{{ $fmt($row['qty']) }}</td><td class="text-end">{{ $fmt($row['net']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div></div>
@endif

@if(isset($data['detailed']))
    <div class="card mb-3"><div class="card-body table-responsive">
        <table class="table table-sm" style="font-size: .82rem">
            <thead><tr><th>Date</th><th>Sale</th><th>Type</th><th>Terminal</th><th>Cashier</th><th>Waiter</th><th>Item</th><th class="text-end">Qty</th><th class="text-end">Ret</th><th class="text-end">Rate</th><th class="text-end">Gross</th><th class="text-end">Disc</th><th class="text-end">Tax</th><th class="text-end">Net</th></tr></thead>
            <tbody>
            @foreach($data['detailed'] as $r)
                <tr><td>{{ $r->sale_date }}</td><td>{{ $r->sale_no }}</td><td>{{ $r->order_type }}</td><td>{{ $r->terminal }}</td><td>{{ $r->cashier }}</td><td>{{ $r->waiter ?? '—' }}</td><td>{{ $r->item }}</td><td class="text-end">{{ $fmt($r->quantity) }}</td><td class="text-end">{{ $fmt($r->returned_quantity) }}</td><td class="text-end">{{ $fmt($r->unit_price) }}</td><td class="text-end">{{ $fmt($r->gross) }}</td><td class="text-end">{{ $fmt($r->discount_amount) }}</td><td class="text-end">{{ $fmt($r->tax_amount) }}</td><td class="text-end">{{ $fmt($r->line_total) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        {{ $data['detailed']->links() }}
        <p class="text-muted small mb-0">Export uses the complete filtered dataset, not just this page.</p>
    </div></div>
@endif

@if(isset($data['cash_bank']))
    @php $cb = $data['cash_bank']; @endphp
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
            <div class="card h-100"><div class="card-body">
                <h6>Cash position (NOT sales revenue)</h6>
                <table class="table table-sm">
                    <tr><td>Opening Cash (float)</td><td class="text-end">{{ $fmt($cb['shifts']['opening_cash'] ?? 0) }}</td></tr>
                    @foreach($cb['method_money'] as $method => $amount)
                        <tr><td class="text-capitalize">Sales received via {{ str_replace('_', ' ', $method) }}</td><td class="text-end">{{ $fmt($amount) }}</td></tr>
                    @endforeach
                    <tr class="fw-semibold"><td>Expected Cash</td><td class="text-end">{{ $fmt($cb['expected_cash_formula']) }}</td></tr>
                    <tr><td>Counted Cash</td><td class="text-end">{{ $fmt($cb['shifts']['counted_cash'] ?? 0) }}</td></tr>
                    <tr><td>Variance</td><td class="text-end">{{ $fmt($cb['shifts']['cash_variance'] ?? 0) }}</td></tr>
                </table>
            </div></div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card h-100"><div class="card-body table-responsive">
                <h6>Money movements</h6>
                <table class="table table-sm">
                    <thead><tr><th>Movement</th><th>Dir</th><th>Account</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    @foreach($cb['movements'] as $m)
                        <tr><td>{{ $m['label'] }}</td><td>{{ $m['direction'] }}</td><td>{{ $m['account_type'] }}</td><td class="text-end">{{ $fmt($m['amount']) }}</td></tr>
                    @endforeach
                    <tr class="fw-semibold"><td>Net Cash Movement</td><td colspan="2"></td><td class="text-end">{{ $fmt($cb['net_cash_movement']) }}</td></tr>
                    <tr class="fw-semibold"><td>Net Bank Movement</td><td colspan="2"></td><td class="text-end">{{ $fmt($cb['net_bank_movement']) }}</td></tr>
                    </tbody>
                </table>
                <p class="text-muted small mb-0">Ledger movements are dated by transaction date (sale date); sales tabs use the business date — totals can differ across midnight.</p>
            </div></div>
        </div>
    </div>
@endif

@if($tab === 'z')
    <div class="alert alert-secondary py-2 small">Z Report preset: Overview + Order Types + Categories + Waiters + Payments + Cash &amp; Bank for the selected day. Use <strong>Print Thermal</strong> for the familiar end-of-day slip.</div>
@endif

{{-- ── schedules ── --}}
<div class="card mb-4"><div class="card-body">
    <h6>Scheduled owner reports (email to the tenant default email)</h6>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Name</th><th>Sections</th><th>Frequency</th><th>Time</th><th>Last run</th><th>Last failure</th><th></th></tr></thead>
            <tbody>
            @forelse($schedules as $s)
                <tr>
                    <td>{{ $s->name }}</td>
                    <td class="small">{{ implode(', ', (array) json_decode($s->sections, true)) }}</td>
                    <td>{{ $s->frequency }}@if($s->weekday) (day {{ $s->weekday }})@endif @if($s->day_of_month) (dom {{ $s->day_of_month }})@endif</td>
                    <td>{{ $s->send_time }}</td>
                    <td class="small">{{ $s->last_success_at ?? '—' }}</td>
                    <td class="small text-danger">{{ $s->last_failure }}</td>
                    <td>
                        <form method="POST" action="{{ url('/reports/center/schedules/' . $s->id) }}" onsubmit="return confirm('Remove this schedule?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-muted">No schedules yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <form method="POST" action="{{ url('/reports/center/schedules') }}" class="row g-2 align-items-end">
        @csrf
        <div class="col-6 col-md-2"><label class="form-label small mb-0">Name</label><input name="name" class="form-control form-control-sm" required maxlength="120"></div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">Frequency</label>
            <select name="frequency" class="form-select form-select-sm" id="rs-freq">
                <option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option>
            </select>
        </div>
        <div class="col-6 col-md-1"><label class="form-label small mb-0">Weekday</label><input name="weekday" type="number" min="1" max="7" class="form-control form-control-sm" placeholder="1-7"></div>
        <div class="col-6 col-md-1"><label class="form-label small mb-0">Day</label><input name="day_of_month" type="number" min="1" max="31" class="form-control form-control-sm" placeholder="1-31"></div>
        <div class="col-6 col-md-1"><label class="form-label small mb-0">Time</label><input name="send_time" type="time" class="form-control form-control-sm" value="08:00" required></div>
        <div class="col-12 col-md-3">
            <label class="form-label small mb-0">Sections</label>
            <div class="d-flex flex-wrap gap-2">
                @foreach(\App\Http\Controllers\Tenant\Reports\SalesReportCenterController::SECTIONS as $section)
                    <label class="form-check-label small"><input type="checkbox" name="sections[]" value="{{ $section }}" class="form-check-input" @checked(in_array($section, ['overview', 'order_types', 'cash_bank']))> {{ str_replace('_', ' ', $section) }}</label>
                @endforeach
            </div>
        </div>
        <div class="col-6 col-md-2"><button class="btn btn-primary btn-sm">Schedule Report</button></div>
    </form>
</div></div>
@endsection
