@extends('layouts.public')

@section('title', 'Features')
@section('meta_description', 'Explore Bingoo POS features: retail checkout, restaurant service, kitchen operations, inventory, purchasing, reports, SaaS billing, and FBR-ready workflows.')

@section('content')

{{-- HERO --}}
<section class="public-hero-premium" style="padding:4rem 0 2.5rem;position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;right:-30px;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <span class="hero-badge mb-3"><i class="ti ti-stars"></i> Everything in one platform</span>
        <h1 class="fw-bold mb-2" style="font-size:2.3rem;">Built for the whole operation</h1>
        <p class="lead mb-0 mx-auto" style="color:#cbd5e1;max-width:760px;">From the front counter to the kitchen to the back office — one connected cloud POS.</p>
    </div>
</section>

{{-- CATEGORY NAV --}}
<div class="trust-strip py-3" style="position:sticky;top:64px;z-index:1020;">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-center gap-2">
            @foreach([
                ['#retail','Retail Checkout'],['#restaurant','Restaurant Service'],['#kitchen','Kitchen Operations'],
                ['#inventory','Inventory & Purchasing'],['#reports','Reports & Controls'],['#finance','Finance & Accounting'],['#saas','SaaS Billing & Team Access'],['#fbr','FBR-ready Workflows'],
            ] as [$anchor,$label])
                <a href="{{ $anchor }}" class="marquee-chip text-decoration-none">{{ $label }}</a>
            @endforeach
        </div>
    </div>
</div>

@php
    // [id, category, title, image, imageRight, [capabilities], businessValue]
    $spotlights = [
        ['retail','Retail Checkout','Fast, accurate checkout at every counter','images/data/retailers.webp',false,
            ['Barcode scanning & product search','Held sales & multi-payment checkout','Sales returns & customer ledger','Promotions & price controls'],
            'Move queues faster and keep stock accurate in real time.'],
        ['restaurant','Restaurant Service','Run dine-in, takeaway, and delivery','images/data/restaurant.png',true,
            ['Floors, tables & waiters','Split bills & service charges','Dine-in / takeaway / delivery order types','Live table board'],
            'One flow from seating the guest to closing the day.'],
        ['kitchen','Kitchen Operations','Send orders to the kitchen without paper','images/data/kitchen_display.png',false,
            ['Kitchen Display System (KDS)','KOT routing by category & station','Prep / ready / served states','Recipes, productions & wastage'],
            'Faster turnaround and fewer missed or wrong orders.'],
        ['inventory','Inventory & Purchasing','Know your stock before it runs out','images/data/mart.webp',true,
            ['Stock balances & valuation','Purchase orders, GRNs & bills','Suppliers & supplier payments','Stock counts, transfers & low-stock alerts'],
            'Control what you buy, hold, and sell across branches.'],
        ['reports','Reports & Controls','See the whole business in real time','images/data/dashbaord.png',false,
            ['Sales, shifts & daily closings','Inventory & purchase reporting','Restaurant & kitchen reports','Manager approvals & audit logs'],
            'Make decisions from live, branch-level numbers.'],
        ['finance','Finance & Accounting','Audit-ready books, built in','images/data/dashbaord.png',true,
            ['Chart of accounts, cash &amp; bank accounts','Expenses, supplier &amp; customer payments','Double-entry general ledger that auto-posts from sales, purchases &amp; expenses','Trial Balance, P&amp;L, Branch-wise P&amp;L, Balance Sheet + CSV export'],
            'Run your accounting inside your POS — included on Restaurant Pro & Enterprise.'],
        ['saas','SaaS Billing & Team Access','Plans, billing, and role-based access','images/data/pos2.png',false,
            ['Plans, modules & usage limits','Invoices & payment proofs','Plan upgrade requests','Owner / Manager / Cashier roles'],
            'Scale your subscription and team safely as you grow.'],
    ];
@endphp

@foreach($spotlights as $s)
    <section id="{{ $s[0] }}" class="section-pad {{ $loop->even ? 'bg-white' : '' }}" style="{{ $loop->even ? '' : 'background:#f8faff;' }}">
        <div class="container">
            <div class="row align-items-center g-5 reveal {{ $s[4] ? 'flex-lg-row-reverse' : '' }}">
                <div class="col-lg-6">
                    <div class="image-card shadow-sm hover-lift">
                        <img src="{{ asset($s[3]) }}" alt="{{ $s[2] }}" style="width:100%;height:340px;object-fit:cover;display:block;">
                    </div>
                </div>
                <div class="col-lg-6">
                    <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">{{ $s[1] }}</span>
                    <h2 class="fw-bold mb-3">{{ $s[2] }}</h2>
                    <ul class="list-unstyled text-muted mb-3">
                        @foreach($s[5] as $cap)
                            <li class="mb-2"><i class="ti ti-check text-success me-2"></i>{{ $cap }}</li>
                        @endforeach
                    </ul>
                    <p class="fw-semibold text-dark mb-4">{{ $s[6] }}</p>
                    <a href="{{ url('/start-trial') }}" class="btn btn-primary">Start Free Trial</a>
                </div>
            </div>
        </div>
    </section>
@endforeach

{{-- FBR-READY WORKFLOWS --}}
<section id="fbr" class="section-pad bg-white">
    <div class="container">
        <div class="fbr-section p-5 reveal">
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <span class="badge mb-3 d-inline-block" style="background:rgba(245,200,90,.15);color:#e9c869;border:1px solid rgba(245,200,90,.3);padding:.4rem 1rem;border-radius:8px;">
                        <i class="ti ti-flag me-1"></i>Pakistan Compliance
                    </span>
                    <h2 class="fw-bold text-white mb-3">FBR-ready Workflows</h2>
                    <p style="color:#94a3b8;" class="mb-4">For eligible Pakistan businesses, Bingoo POS is being designed with FBR-ready invoice workflows and tax configuration.</p>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Branch tax registration number fields</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Taxable products and per-line tax amounts</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Receipt tax number & footer configuration</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Future invoice sync and QR workflow planning</span></div>
                    <a href="{{ url('/contact?topic=fbr') }}" class="btn btn-success btn-lg px-4 mt-2">Ask about FBR setup &rarr;</a>
                    <p class="mt-3 mb-0" style="color:#fbbf24;font-size:.8rem;">Not an official FBR certification claim. Final compliance depends on business setup and official FBR requirements.</p>
                </div>
                <div class="col-lg-5">
                    <div class="receipt-mock mx-auto" style="max-width:260px;">
                        <div class="text-center fw-bold">BINGOO POS</div>
                        <div class="text-center" style="font-size:.7rem;color:#64748b;">Tax Invoice</div>
                        <hr>
                        <div class="r-row"><span>NTN</span><span>XXXXXXX-X</span></div>
                        <div class="r-row"><span>Sales tax</span><span>340</span></div>
                        <div class="r-row fw-bold"><span>Total</span><span>2,340</span></div>
                        <div class="r-row" style="color:#a87f24;"><span>FBR sync</span><span>Planned</span></div>
                        <div class="d-flex justify-content-center mt-2"><div class="qr-mock"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- FEATURE MATRIX SUMMARY --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <h2 class="fw-bold">Every module, in one platform</h2>
            <p class="text-muted">No stitching separate tools together.</p>
        </div>
        @php $modules = [
            ['ti-barcode','POS & Sales'],['ti-stack-2','Catalog'],['ti-package','Inventory'],['ti-truck-delivery','Purchasing'],
            ['ti-transfer','Stock Count & Transfers'],['ti-armchair','Restaurant'],['ti-device-desktop','Kitchen Display'],
            ['ti-chef-hat','Kitchen Inventory'],['ti-printer','Printing'],['ti-chart-bar','Reports'],['ti-adjustments','Sales Controls'],
            ['ti-building-store','Multi Branch'],['ti-users','Users & Roles'],['ti-report-money','Finance & Accounting'],
        ]; @endphp
        <div class="row g-3">
            @foreach($modules as [$ico,$name])
                <div class="col-6 col-md-4 col-lg-3">
                    <div class="gradient-card p-3 d-flex align-items-center gap-2 reveal">
                        <div class="icon-wrap" style="width:40px;height:40px;margin:0;"><i class="ti {{ $ico }}"></i></div>
                        <span class="fw-semibold small">{{ $name }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- FINAL CTA --}}
<section class="public-hero-premium section-pad">
    <div class="container text-center reveal" style="max-width:680px;">
        <h2 class="fw-bold text-white mb-3" style="font-size:2.2rem;">See the workflows in action.</h2>
        <p class="mb-4" style="color:#cbd5e1;">Start a 30-day trial or talk to our team for a guided walkthrough.</p>
        <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="{{ url('/start-trial') }}" class="btn btn-light btn-lg px-5">Start Trial</a>
            <a href="{{ url('/demos') }}" class="btn btn-outline-light btn-lg px-5">Try Live Demo</a>
            <a href="{{ url('/pricing') }}" class="btn btn-outline-light btn-lg px-5">View Pricing</a>
            <a href="{{ url('/contact') }}" class="btn btn-outline-light btn-lg px-5">Book Demo</a>
        </div>
    </div>
</section>

@endsection
