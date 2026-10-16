@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

        {{-- Header --}}
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h4 class="mb-1">{{ __('dashboard.tenant_panel') }}</h4>
                <p class="text-muted small mb-0">
                    Business: <strong class="text-primary">{{ app('tenant')->business_name }}</strong>
                    {{-- OPERATING-DATE-1: pehle yahan now() tha — SARWAR ka UTC, kisi ka business
                         timezone nahi. Ab wohi din jo neeche ki tiles gin rahi hain, warna header
                         ek din kehta aur hindse doosre din ke hote. --}}
                    &nbsp;&mdash;&nbsp; {{ \Illuminate\Support\Carbon::parse($todayBusinessDate)->format('l, d M Y') }}
                </p>
            </div>

            <div class="d-flex gap-2 align-items-center flex-wrap">
                {{-- SALES-ANALYTICS-1 — link SIRF us ko jis ke paas us route ki ijazat ho. Abhi wo
                     sirf Owner hai (deploy.sh naye route ki permission sirf Owner ko deta hai, aur
                     koi additive grant nahi chalaya gaya). @can route ke NAAM par hai, kisi alag
                     jhande par nahi — is liye jis din owner kisi aur role ko de, button khud ba khud
                     us ke saamne aa jayega. --}}
                @can('tenant.reports.analytics')
                    {{-- `url()`, `route()` nahi — tenant routes {subdomain} group me hain. --}}
                    <a href="{{ url('/reports/analytics') }}" class="btn btn-sm btn-outline-primary">
                        <i class="ti ti-chart-line me-1"></i>Analytics
                    </a>
                @endcan

                {{-- Branch filter --}}
                <form method="GET" action="{{ url('/dashboard') }}" class="d-flex gap-2 align-items-center">
                    <select name="branch_id" class="form-select form-select-sm" style="min-width:160px" onchange="this.form.submit()">
                        <option value="">All Branches</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ $selectedBranch == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
        </div>

        {{-- Alerts --}}
        @if($failedPrints > 0)
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
            <i class="ti ti-printer fs-5"></i>
            <strong>{{ $failedPrints }} failed print job{{ $failedPrints > 1 ? 's' : '' }}</strong> in the last 24 hours.
            <a href="{{ url('/printing/jobs') }}?status=failed" class="ms-2 btn btn-sm btn-danger py-0">View &amp; Retry</a>
        </div>
        @endif

        @if($lowStockCount > 0)
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
            <i class="ti ti-alert-triangle fs-5"></i>
            <strong>{{ $lowStockCount }} product{{ $lowStockCount > 1 ? 's' : '' }}</strong> below reorder level.
            <a href="{{ url('/inventory/low-stock') }}" class="ms-2 btn btn-sm btn-warning py-0">View</a>
        </div>
        @endif

        {{-- KASHIF-CATERING-CALENDAR-1 — booking diary, catering plans only.
             $cateringCalendar is null unless the module is entitled, so this
             renders nothing at all for a restaurant or retail tenant. --}}
        @if(! empty($cateringKpis))
            @include('tenant.partials.catering-kpis')
        @endif
        @if(! empty($cateringCalendar))
            @include('tenant.partials.catering-calendar')
        @endif

        {{-- DASHBOARD-OPEN-BILLS-1 — one line, four tiles.
             Every tile counts PAID work, so a busy service is invisible until the bills settle.
             This adds the still-open held/draft figure UNDER the tile, never inside it: the tile's
             own number must keep matching the Report Center and the day's closing.
             It is EXPECTED, not earned — a held bill still changes — so it says so, and it only
             appears when something is actually open.
             Cash and Card get no such line: a held bill has no payment row, because the method is
             chosen at payment time. Splitting the open total into cash and card would be invented. --}}
        @php
            $openCount  = (int) ($openBills->orders ?? 0);
            $openLine = function ($amount, $label = null) use ($openCount) {
                if ($openCount < 1 || (float) $amount <= 0) { return null; }
                return ['amount' => (float) $amount, 'label' => $label];
            };
            // HIDE-AMOUNTS-1 — every figure on these tiles goes through here, so a tile added
            // later cannot quietly skip the mask. $maySeeAmounts is decided in the controller.
            $fig = fn ($value, $decimals = 2) => $maySeeAmounts
                ? number_format((float) $value, $decimals)
                : '*****';
        @endphp

        {{-- Today KPI cards --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="bg-primary bg-opacity-10 rounded p-2"><i class="ti ti-currency-dollar text-primary fs-5"></i></span>
                            <span class="text-muted small">Net Sales Today</span>
                        </div>
                        <h4 class="mb-0 fw-bold">{{ $fig($today['net_sales']) }}</h4>
                        {{-- Show the deduction rather than a bare net, so this tile can be tied to
                             the Report Center at a glance instead of looking like a third figure. --}}
                        @if(($today['returns_amount'] ?? 0) > 0)
                            <div class="small text-muted mt-1">
                                billed {{ $fig($today['billed']) }}
                                − returns {{ $fig($today['returns_amount']) }}
                            </div>
                        @endif
                        @if($openLine($openBills->amount ?? 0))
                            <div class="small text-warning mt-1">
                                + {{ $fig($openBills->amount) }} still open
                            </div>
                            <div class="small text-muted">
                                expected {{ $fig($today['net_sales'] + (float) $openBills->amount) }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="bg-success bg-opacity-10 rounded p-2"><i class="ti ti-shopping-cart text-success fs-5"></i></span>
                            <span class="text-muted small">Orders Today</span>
                        </div>
                        <h4 class="mb-0 fw-bold">{{ $fig($today['order_count'], 0) }}</h4>
                        @if($openCount > 0)
                            <div class="small text-warning mt-1">+ {{ $fig($openCount, 0) }} still open</div>
                            <div class="small text-muted">expected {{ $fig($today['order_count'] + $openCount, 0) }}</div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="bg-info bg-opacity-10 rounded p-2"><i class="ti ti-chart-line text-info fs-5"></i></span>
                            <span class="text-muted small">Avg Order Value</span>
                        </div>
                        <h4 class="mb-0 fw-bold">{{ $fig($today['avg_order_value']) }}</h4>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="bg-warning bg-opacity-10 rounded p-2"><i class="ti ti-cash text-warning fs-5"></i></span>
                            <span class="text-muted small">Cash Today</span>
                        </div>
                        <h4 class="mb-0 fw-bold">{{ $fig($cashToday) }}</h4>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="bg-secondary bg-opacity-10 rounded p-2"><i class="ti ti-credit-card text-secondary fs-5"></i></span>
                            <span class="text-muted small">Card/Bank Today</span>
                        </div>
                        <h4 class="mb-0 fw-bold">{{ $fig($cardToday) }}</h4>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="bg-danger bg-opacity-10 rounded p-2"><i class="ti ti-tag text-danger fs-5"></i></span>
                            <span class="text-muted small">Discounts Today</span>
                        </div>
                        <h4 class="mb-0 fw-bold">{{ $fig($today['total_discount']) }}</h4>
                        @if($openLine($openBills->discount ?? 0))
                            <div class="small text-warning mt-1">
                                + {{ $fig($openBills->discount) }} still open
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Row 2: extra stats --}}
        <div class="row g-3 mb-4">
            @if($today['total_service_charge'] > 0)
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm"><div class="card-body">
                    <span class="text-muted small">Service Charge</span>
                    <h5 class="mt-1 mb-0">{{ $fig($today['total_service_charge']) }}</h5>
                </div></div>
            </div>
            @endif
            @if($today['total_tips'] > 0)
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm"><div class="card-body">
                    <span class="text-muted small">Tips</span>
                    <h5 class="mt-1 mb-0">{{ $fig($today['total_tips']) }}</h5>
                </div></div>
            </div>
            @endif
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm"><div class="card-body">
                    <span class="text-muted small">Tax Collected</span>
                    <h5 class="mt-1 mb-0">{{ $fig($today['total_tax']) }}</h5>
                    @if($openLine($openBills->tax ?? 0))
                        <div class="small text-warning mt-1">
                            + {{ $fig($openBills->tax) }} still open
                        </div>
                    @endif
                </div></div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm"><div class="card-body">
                    <span class="text-muted small">Open Shifts</span>
                    <h5 class="mt-1 mb-0">{{ $fig($openShifts, 0) }}</h5>
                </div></div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm @if($expiryCount > 0) border-warning @endif"><div class="card-body">
                    <span class="text-muted small">Expiry Alerts (30d)</span>
                    <h5 class="mt-1 mb-0 @if($expiryCount > 0) text-warning @endif">{{ $fig($expiryCount, 0) }}</h5>
                </div></div>
            </div>

            {{-- DASHBOARD-LAYOUT-1 — billing ke khanay bhi ISI row me. Pehle ye apni alag
                 row me `col-md-6` par thay, yani aik hi card hota to aadhi row khaali. Ab ye baqi
                 KPI cards ke saath behte hain aur row khud bhar jati hai. --}}
        {{-- SAAS-BILLING-WHATSAPP-1 / DASHBOARD-BILLING-ONE-BOX-1 — platform ke paise, AIK khana.

             Pehle do (kabhi teen) alag khanay thay: "dena hai", "usage is mahine". Malik ne kaha aik
             karo aur tafseel click par do — safhe par aik hi aankRa, baqi kahani modal me.

             Gate wohi permission hai jo /billing kholne deti hai. Dashboard har user ke saamne khulta
             hai — counter par khaRa banda bhi — aur kitna baqi hai, ye har kisi ke parhne ki cheez
             nahi.

             Matn ANGREZI me hai: malik ne khaas kaha. --}}
        @can('tenant.billing.index')
        @if(($billing['total'] ?? 0) > 0)
            <div class="col-md-3 col-sm-6">
                <div class="card border-0 shadow-sm h-100 @if(($billing['due'] ?? 0) > 0) border-start border-4 border-warning @endif"><div class="card-body">
                    <span class="text-muted small"><i class="ti ti-wallet me-1"></i>Billing</span>
                    <h5 class="mt-1 mb-0 @if(($billing['due'] ?? 0) > 0) text-warning @endif">{{ number_format($billing['total'], 2) }}</h5>
                    <div class="text-muted small mt-1">
                        @if(($billing['due'] ?? 0) > 0){{ $billing['dueCount'] }} invoice{{ $billing['dueCount'] === 1 ? '' : 's' }} due @endif
                        @if(($billing['due'] ?? 0) > 0 && ($billing['accruing'] ?? 0) > 0) · @endif
                        @if(($billing['accruing'] ?? 0) > 0)usage this month @endif
                        <br>
                        <a href="#" data-bs-toggle="modal" data-bs-target="#billingBreakdown">
                            <i class="ti ti-info-circle"></i> Details
                        </a>
                    </div>
                </div>
                </div>
            </div>
        @endif
        @endcan
        </div>
        {{-- Bottom row: Top Products + 7-day sales.
             DASHBOARD-DETAILS-1: both cards read the whole branch, so a counter operator does not
             get them. The controller already returns empty collections without the permission —
             this gate is what removes the empty cards from the page. --}}
        @can('tenant.dashboard.details')
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 pb-0">
                        <h6 class="mb-0">Top 5 Products Today</h6>
                    </div>
                    <div class="card-body p-0">
                        @if($topProducts->isEmpty())
                            <p class="text-muted text-center py-4 small mb-0">No sales today.</p>
                        @else
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden">Top 5 products by quantity sold today</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Product</th>
                                    <th scope="col" class="text-end">Qty</th>
                                    <th scope="col" class="text-end">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($topProducts as $p)
                                <tr>
                                    <td>{{ $p->product_name }}</td>
                                    <td class="text-end">{{ $fig($p->qty_sold) }}</td>
                                    <td class="text-end">{{ $fig($p->revenue) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @endif
                    </div>
                </div>

            {{-- DASHBOARD-LAYOUT-1 — ye card pehle apni alag row me tha aur us ki daayein taraf
                 saat column khaali paRe rehte thay. Ab Top-5 ke NEECHE, usi baayein sutoon me:
                 Top-5 par paanch satrein hoti hain aur "Last 8 Days" par aath, to baayein taraf jo
                 jagah khaali bachti thi wo theek isi se bhar jati hai. --}}
                <div class="card border-0 shadow-sm mt-3" id="dashboard-category-today">
                    <div class="card-header bg-white border-0 pb-0">
                        <h6 class="mb-0">Sales by Category Today</h6>
                    </div>
                    <div class="card-body p-0">
                        @if(empty($categoriesToday))
                            <p class="text-muted text-center py-4 small mb-0">No sales today.</p>
                        @else
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden">Quantity and sales of every category today</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Category</th>
                                    <th scope="col" class="text-end">Qty</th>
                                    <th scope="col" class="text-end">Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($categoriesToday as $cat)
                                <tr>
                                    <td>{{ $cat['name'] }}</td>
                                    <td class="text-end">{{ $fig($cat['net_qty']) }}</td>
                                    <td class="text-end">{{ $fig($cat['net_value']) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="table-light fw-semibold">
                                <tr>
                                    {{-- Items only: delivery and other bill-level charges belong to no category,
                                         so this sits a little under the Net Sales tile (Kashif Food 10 Oct:
                                         1,037,450 against 1,043,086.50). The label says so before anyone asks. --}}
                                    <td title="Bill-level charges such as delivery are not in any category">Items total</td>
                                    <td class="text-end">{{ $fig(array_sum(array_column($categoriesToday, 'net_qty'))) }}</td>
                                    <td class="text-end" id="dashboard-category-total">{{ $fig(array_sum(array_column($categoriesToday, 'net_value'))) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 pb-0">
                        {{-- DASHBOARD-8DAY-1: unwaan bhi USI constant se banta hai jis se window
                             banti hai. Warna yahan likha "7" aur neeche ginn kar 8 qatarein —
                             wohi purana rog, ke card ka lafz apne hi data se ikhtilaf kare. --}}
                        <h6 class="mb-0">Last {{ $windowDays }} Days — Net Sales</h6>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden">Sales summary for last {{ $windowDays }} days</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col" class="text-end">Orders</th>
                                    <th scope="col" class="text-end">Net Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                {{-- The days come from the controller's BUSINESS-day window, not from
                                     now(): the UTC calendar date and the Karachi business date are
                                     different things between midnight there and midnight UTC, and this
                                     table used to ask for keys the query had never produced. --}}
                                @foreach($salesWindowKeys as $day)
                                    @php $row = $salesWindowRows[$day] ?? null; @endphp
                                    <tr @if($day === $todayBusinessDate) class="table-primary" @endif>
                                        <td>{{ \Carbon\Carbon::parse($day)->format('D, d M') }}</td>
                                        <td class="text-end">{{ $row ? $fig($row->orders, 0) : "—" }}</td>
                                        <td class="text-end">{{ $row ? $fig($row->net_sales) : "—" }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-white border-0 text-end">
                        <a href="{{ url('/reports/sales/summary') }}" class="small text-primary">View Sales Summary →</a>
                    </div>
                </div>
            </div>
        </div>

        @endcan

{{-- DASHBOARD-BILLING-ONE-BOX-1 — kul ke peechhe ki tafseel.

     Row ke BAHAR rakha hai: modal ko grid ke column ke andar rakhna us ko us column ki chauRai aur
     overflow me phansa deta hai.

     Do hisse jaan boojh kar alag: "Due now" wo hai jo maanga ja chuka, "Accruing" wo jo abhi ban raha
     hai aur abhi dena NAHI. Card par dono jama hain kyunke malik ne aik aankRa maanga — magar yahan
     un par aik hi lafz lagana logon se ghalat waqt par adaegi karwata hai. --}}
@can('tenant.billing.index')
@if(($billing['total'] ?? 0) > 0)
<div class="modal fade" id="billingBreakdown" tabindex="-1" aria-labelledby="billingBreakdownLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="billingBreakdownLabel">Billing breakdown</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">

                @if(($billing['due'] ?? 0) > 0)
                    <h6 class="text-warning mb-2">Due now</h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr>
                                <th>Invoice</th><th>Period</th><th>Due date</th><th class="text-end">Amount</th><th></th>
                            </tr></thead>
                            <tbody>
                            @foreach(($billing['invoices'] ?? []) as $inv)
                                <tr>
                                    <td>
                                        <code>{{ $inv['no'] }}</code>
                                        @if($inv['type'] === 'addon')
                                            <span class="badge bg-light text-dark border">usage</span>
                                        @endif
                                    </td>
                                    <td class="small">{{ $inv['period'] ?? '—' }}</td>
                                    <td class="small @if($inv['overdue']) text-danger fw-semibold @endif">
                                        {{ $inv['due_date'] ?? '—' }}
                                        @if($inv['overdue'])<br><span class="small">overdue</span>@endif
                                    </td>
                                    <td class="text-end fw-semibold">{{ number_format($inv['balance'], 2) }}</td>
                                    <td class="text-end">
                                        <a href="{{ $inv['url'] }}" class="btn btn-sm btn-outline-secondary">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot><tr class="table-light fw-semibold">
                                <td colspan="3">Due now</td>
                                <td class="text-end">{{ number_format($billing['due'], 2) }}</td>
                                <td></td>
                            </tr></tfoot>
                        </table>
                    </div>
                @endif

                @if(($billing['accruing'] ?? 0) > 0)
                    <h6 class="mb-1">Accruing this month</h6>
                    <p class="text-muted small">
                        Not payable yet — this becomes an invoice when the month closes.
                        @if(! empty($billing['accruingPeriod'])) ({{ $billing['accruingPeriod'] }}) @endif
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <tbody>
                                @if(($billing['accruingWhatsApp'] ?? 0) > 0 || ($billing['accruingWebsite'] ?? 0) == 0)
                                    <tr>
                                        <td><i class="ti ti-brand-whatsapp me-1"></i>WhatsApp reports</td>
                                        <td class="small text-muted">{{ $billing['accruingMessages'] }} messages</td>
                                        <td class="text-end">{{ number_format($billing['accruingWhatsApp'] ?? $billing['accruing'], 2) }}</td>
                                    </tr>
                                @endif
                                @if(($billing['accruingWebsite'] ?? 0) != 0)
                                    <tr>
                                        <td><i class="ti ti-world me-1"></i>Website orders</td>
                                        <td class="small text-muted">{{ $billing['accruingOrders'] }} orders</td>
                                        <td class="text-end">{{ number_format($billing['accruingWebsite'], 2) }}</td>
                                    </tr>
                                @endif
                            </tbody>
                            <tfoot><tr class="table-light fw-semibold">
                                <td colspan="2">Accruing</td>
                                <td class="text-end">{{ number_format($billing['accruing'], 2) }}</td>
                            </tr></tfoot>
                        </table>
                    </div>
                @endif

            </div>
            <div class="modal-footer justify-content-between">
                <div>
                    <span class="text-muted">Total</span>
                    <span class="fs-5 fw-semibold ms-2">{{ number_format($billing['total'], 2) }}</span>
                </div>
                <a href="{{ url('/billing') }}" class="btn btn-primary">Open billing</a>
            </div>
        </div>
    </div>
</div>
@endif
@endcan
@endsection
