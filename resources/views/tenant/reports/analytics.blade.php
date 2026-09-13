@extends('layouts.app')

@section('title', 'Sales Analytics')

@php
    use Illuminate\Support\Str;

    $granular  = $granularity === 'month' ? $monthly : $daily;
    $hasData   = collect($granular)->sum('net_sales') > 0 || collect($granular)->sum('orders') > 0;
    $money     = fn ($v) => number_format((float) $v, 0);

    // Growth ka chip — `null` ka matlab "moqabale ka data nahi", 0% NAHI.
    $chip = function (?float $pc) {
        if ($pc === null) {
            return ['—', 'text-muted', ''];
        }
        if ($pc > 0)  { return ['+' . number_format($pc, 1) . '%', 'text-success', 'ti-trending-up']; }
        if ($pc < 0)  { return [number_format($pc, 1) . '%', 'text-danger', 'ti-trending-down']; }
        return ['0.0%', 'text-muted', 'ti-minus'];
    };

    $presets = [
        '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days',
        '6m' => 'Last 6 months', '12m' => 'Last 12 months',
        'this_month' => 'This month', 'last_month' => 'Last month',
    ];
@endphp

@section('content')
<div class="page-header">
    <div class="page-title">
        <h4>Sales Analytics</h4>
        <h6>Sales aur growth — {{ $from }} se {{ $to }} ({{ $days }} din)</h6>
    </div>
</div>

{{-- ── Filters ───────────────────────────────────────────────────────────── --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        {{-- ⚠️ `url()`, `route()` NAHI. Tenant ke routes `{subdomain}` group me hain, is liye
             `route()` "Missing parameter: subdomain" par gir jata hai. Is codebase ka har doosra
             blade bhi isi wajah se `url()` istemal karta hai. --}}
        <form method="GET" action="{{ url('/reports/analytics') }}" class="row g-2 align-items-end">
            <div class="col-12">
                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
                    <div class="d-flex flex-wrap gap-1">
                        @foreach ($presets as $key => $label)
                            {{-- Preset badalne par order type aur branch SATH chalte hain, warna har
                                 click par filter wapas "All" par gir jata. --}}
                            <a class="btn btn-sm {{ $preset === $key ? 'btn-primary' : 'btn-outline-secondary' }}"
                               href="{{ url('/reports/analytics?' . http_build_query(array_filter([
                                   'preset' => $key, 'branch_id' => $selectedBranch, 'order_type' => $orderType,
                               ]))) }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>

                    {{-- Order type — ALL ya koi ek. Sirf wohi types jo ye operator chala sakta hai. --}}
                    <div class="d-flex flex-wrap gap-1">
                        <a class="btn btn-sm {{ $orderType === null ? 'btn-dark' : 'btn-outline-dark' }}"
                           href="{{ url('/reports/analytics?' . http_build_query(array_filter([
                               'preset' => $preset, 'from' => $from, 'to' => $to, 'branch_id' => $selectedBranch,
                           ]))) }}">All</a>

                        @foreach ($orderTypeList as $key => $label)
                            <a class="btn btn-sm {{ $orderType === $key ? 'btn-dark' : 'btn-outline-dark' }}"
                               href="{{ url('/reports/analytics?' . http_build_query(array_filter([
                                   'preset' => $preset, 'from' => $from, 'to' => $to,
                                   'branch_id' => $selectedBranch, 'order_type' => $key,
                               ]))) }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Custom range ke sath order type bhi jaye. --}}
            <input type="hidden" name="order_type" value="{{ $orderType }}">

            <input type="hidden" name="preset" value="custom">

            <div class="col-md-3 col-6">
                <label class="form-label small text-muted mb-1" for="an-from">From</label>
                <input type="date" class="form-control form-control-sm" id="an-from" name="from" value="{{ $from }}">
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label small text-muted mb-1" for="an-to">To</label>
                <input type="date" class="form-control form-control-sm" id="an-to" name="to" value="{{ $to }}">
            </div>

            @if ($branches->count() > 1)
                <div class="col-md-3 col-8">
                    <label class="form-label small text-muted mb-1" for="an-branch">Branch</label>
                    <select class="form-select form-select-sm" id="an-branch" name="branch_id">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected($selectedBranch === (int) $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="col-md-2 col-4">
                <button class="btn btn-sm btn-primary w-100" type="submit">Apply</button>
            </div>
        </form>
    </div>
</div>

@unless ($hasData)
    {{-- Khali daur ka sharif jawab — khali charts se behtar hai ke saaf likh diya jaye. --}}
    <div class="alert alert-secondary" role="status">
        <strong>Is daur me koi sale nahi.</strong>
        {{ $from }} se {{ $to }} tak koi bill nahi bana. Koi doosra daur chunein.
    </div>
@else

{{-- ── KPI ───────────────────────────────────────────────────────────────── --}}
@php
    [$netTxt, $netCls, $netIcon] = $chip($growth['net_sales']);
    [$ordTxt, $ordCls, $ordIcon] = $chip($growth['orders']);
@endphp
<div class="row g-3 mb-3">
    <div class="col-md-4 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Net Sales</div>
            <div class="fw-bold fs-3">{{ $money($totals['net_sales']) }}</div>
            <div class="small {{ $netCls }}">
                @if ($growth['comparable'])
                    @if ($netIcon)<i class="ti {{ $netIcon }}"></i>@endif {{ $netTxt }}
                    <span class="text-muted">vs pichle {{ $days }} din ({{ $money($growth['prev_net']) }})</span>
                @else
                    <span class="text-muted">Pichle daur ka data nahi — moqabala mumkin nahi</span>
                @endif
            </div>
        </div></div>
    </div>

    <div class="col-md-4 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Orders</div>
            <div class="fw-bold fs-3">{{ number_format($totals['orders']) }}</div>
            <div class="small {{ $ordCls }}">
                @if ($growth['comparable'])
                    @if ($ordIcon)<i class="ti {{ $ordIcon }}"></i>@endif {{ $ordTxt }}
                    <span class="text-muted">vs {{ number_format($growth['prev_ord']) }}</span>
                @else
                    <span class="text-muted">Moqabale ka data nahi</span>
                @endif
            </div>
        </div></div>
    </div>

    <div class="col-md-4 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Average Order Value</div>
            <div class="fw-bold fs-3">{{ $money($totals['aov']) }}</div>
            <div class="small text-muted">
                Returns is daur me: {{ $money($overview['returns_amount'] ?? 0) }}
            </div>
        </div></div>
    </div>
</div>

{{-- ── Charts ────────────────────────────────────────────────────────────── --}}
<div class="row g-3">
    <div class="col-12">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <h6 class="mb-1">Sales ka rujhan</h6>
            <div class="text-muted small mb-2">
                Net sales {{ $granularity === 'month' ? 'har mahine' : 'har din' }} — returns ghata kar
            </div>
            <div id="an-trend" style="min-height:300px"></div>
        </div></div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <h6 class="mb-1">Orders</h6>
            <div class="text-muted small mb-2">Kitne bill bane</div>
            <div id="an-orders" style="min-height:280px"></div>
        </div></div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <h6 class="mb-1">Is daur vs pichla daur</h6>
            <div class="text-muted small mb-2">
                @if ($growth['comparable'])
                    {{ $from }} – {{ $to }} ka moqabala {{ $prevFrom }} – {{ $prevTo }} se
                @else
                    Pichle daur ka data nahi
                @endif
            </div>
            <div id="an-compare" style="min-height:280px"></div>
        </div></div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <h6 class="mb-1">Category</h6>
            <div class="text-muted small mb-2">Sab se ziyada bikne wale aath heads</div>
            <div id="an-category" style="min-height:300px"></div>
        </div></div>
    </div>

    <div class="col-lg-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <h6 class="mb-1">Order type</h6>
            <div class="text-muted small mb-2">Dine in / Takeaway / Delivery</div>
            <div id="an-ordertype" style="min-height:280px"></div>
        </div></div>
    </div>

    <div class="col-lg-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <h6 class="mb-1">Payment</h6>
            <div class="text-muted small mb-2">Paisa kis shakl me aaya</div>
            <div id="an-payment" style="min-height:280px"></div>
        </div></div>
    </div>
</div>

@endunless
@endsection

@push('scripts')
{{-- ⚠️ ApexCharts SIRF is safhe par. Layout me daalna 100+ doosre safhon par bila zaroorat bojh
     hota. Library theme ke sath disk par aayi hai — koi CDN nahi, kyunke branch ka internet band
     ho sakta hai aur POS local network par chalta hai. --}}
<script src="{{ asset('assets/plugins/apexchart/apexcharts.min.js') }}"></script>
<script>
(function () {
    if (typeof ApexCharts === 'undefined') { return; }

    var D        = @json($granular);
    var cats     = @json($categories);
    var oTypes   = @json($orderTypes);
    var payments = @json($payments);
    var keys   = Object.keys(D);
    var net    = keys.map(function (k) { return Math.round(D[k].net_sales); });
    var orders = keys.map(function (k) { return D[k].orders; });

    // Axis par "Thu 04 Sep" — sirf tareekh se hafte ki tarteeb nazar nahi aati, aur restaurant ka
    // karobar usi tarteeb par chalta hai (jumma/hafta bhaari, peer halka).
    var labels = keys.map(function (k) { return D[k].label || k; });
    // Tooltip me poora din: "Thursday, 04 Sep 2026".
    var full   = keys.map(function (k) { return D[k].full || k; });

    var titleFormatter = function (val, opts) {
        var i = opts && opts.dataPointIndex;
        return (typeof i === 'number' && full[i]) ? full[i] : val;
    };

    // Ek hi rang-tarteeb har chart par — taake ek cheez har jagah ek hi rang me nazar aaye.
    var PALETTE = ['#1B3A5C', '#B0842F', '#1E7A57', '#A33226', '#5C6A7A', '#7FA3C7', '#D6A94A', '#8FB8A4'];
    var money   = function (v) { return Number(v || 0).toLocaleString(); };

    var base = {
        chart:  { fontFamily: 'inherit', toolbar: { show: false }, animations: { enabled: false } },
        colors: PALETTE,
        grid:   { borderColor: 'rgba(0,0,0,.06)', strokeDashArray: 3 },
        dataLabels: { enabled: false },
        tooltip: { y: { formatter: function (v) { return money(v); } } },
    };

    function draw(el, opts) {
        var node = document.querySelector(el);
        if (!node) { return; }
        try { new ApexCharts(node, opts).render(); }
        catch (e) { node.innerHTML = '<div class="text-muted small p-3">Chart nahi bana.</div>'; }
    }

    // 1. Sales ka rujhan
    draw('#an-trend', Object.assign({}, base, {
        chart:  Object.assign({}, base.chart, { type: 'area', height: 300 }),
        series: [{ name: 'Net sales', data: net }],
        xaxis:  { categories: labels, labels: { rotate: -45, hideOverlappingLabels: true } },
        yaxis:  { labels: { formatter: function (v) { return money(Math.round(v)); } } },
        stroke: { curve: 'smooth', width: 2 },
        fill:   { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
        tooltip: { x: { formatter: titleFormatter }, y: { formatter: function (v) { return money(v); } } },
    }));

    // 2. Orders — ALAG chart, jaan-boojh kar. Sales aur orders ke paimane alag hain; dono ko ek
    //    chart par do y-axis ke sath dikhana padhne wale ko dhoka deta hai.
    draw('#an-orders', Object.assign({}, base, {
        chart:  Object.assign({}, base.chart, { type: 'bar', height: 280 }),
        series: [{ name: 'Orders', data: orders }],
        colors: [PALETTE[1]],
        xaxis:  { categories: labels, labels: { rotate: -45, hideOverlappingLabels: true } },
        plotOptions: { bar: { borderRadius: 3, columnWidth: '60%' } },
        tooltip: {
            x: { formatter: titleFormatter },
            y: { formatter: function (v) { return Number(v).toLocaleString() + ' orders'; } },
        },
    }));

    // 3. Is daur vs pichla — dono ko "Din 1..N" par rakha hai, taake alag tareekhen aamne saamne aayen.
    var cmpPrev = @json($prevDaily ?? []);
    draw('#an-compare', Object.assign({}, base, {
        chart:  Object.assign({}, base.chart, { type: 'line', height: 280 }),
        series: [
            { name: 'Is daur', data: net },
            { name: 'Pichla daur', data: (cmpPrev || []).map(function (v) { return Math.round(v); }) },
        ],
        stroke: { curve: 'smooth', width: [2, 2], dashArray: [0, 4] },
        xaxis:  { categories: labels.map(function (_, i) { return 'Din ' + (i + 1); }),
                  labels: { hideOverlappingLabels: true } },
        yaxis:  { labels: { formatter: function (v) { return money(Math.round(v)); } } },
        legend: { position: 'top', horizontalAlign: 'left' },
    }));

    // 4. Category
    draw('#an-category', Object.assign({}, base, {
        chart:  Object.assign({}, base.chart, { type: 'bar', height: 300 }),
        series: [{ name: 'Net sales', data: Object.values(cats).map(function (v) { return Math.round(v); }) }],
        xaxis:  { categories: Object.keys(cats) },
        plotOptions: { bar: { horizontal: true, borderRadius: 3, barHeight: '65%' } },
    }));

    // 5 + 6. Donuts
    function donut(el, obj) {
        var keys = Object.keys(obj || {});
        if (! keys.length) {
            var n = document.querySelector(el);
            if (n) { n.innerHTML = '<div class="text-muted small p-3">Is daur me kuch nahi.</div>'; }
            return;
        }
        draw(el, Object.assign({}, base, {
            chart:  Object.assign({}, base.chart, { type: 'donut', height: 280 }),
            series: keys.map(function (k) { return Math.round(obj[k]); }),
            labels: keys,
            legend: { position: 'bottom' },
            plotOptions: { pie: { donut: { size: '62%' } } },
        }));
    }

    donut('#an-ordertype', oTypes);
    donut('#an-payment', payments);
})();
</script>
@endpush
