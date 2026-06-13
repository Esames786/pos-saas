@extends('layouts.public')

@section('title', 'Cloud POS for Retail, Restaurants & Inventory Teams')
@section('meta_description', 'Launch a cloud POS for retail checkout, restaurant KOT, inventory, purchasing, reports, and multi-branch operations in minutes. 30-day free trial.')

@section('content')

{{-- ═══════════════════════════════════════════════════
     1. PREMIUM ANIMATED HERO
═══════════════════════════════════════════════════ --}}
<section class="public-hero-premium" style="padding:5rem 0 3rem;">
    <div class="container">
        <div class="row align-items-center gy-5">

            {{-- LEFT: copy --}}
            <div class="col-lg-5">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="hero-badge"><i class="ti ti-bolt"></i> Your dream POS is only 3 clicks away</span>
                </div>
                <h1 class="fw-bold mb-3" style="font-size:2.6rem;line-height:1.2;letter-spacing:-.5px;">
                    Run your store, kitchen, and inventory from one powerful cloud POS.
                </h1>
                <p class="mb-4" style="color:#cbd5e1;font-size:1.1rem;line-height:1.7;">
                    Launch a cloud workspace for sales, barcode checkout, restaurant tables, KOT,
                    kitchen display, purchasing, stock control, reports, and multi-branch operations —
                    <strong style="color:#fff;">in minutes, not weeks.</strong>
                </p>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <a href="{{ url('/start-trial') }}"
                       class="btn btn-light btn-lg px-4 fw-semibold"
                       style="box-shadow:0 4px 20px rgba(255,255,255,.25);">
                        <i class="ti ti-rocket me-2"></i>Start 30-Day Free Trial
                    </a>
                    <a href="{{ url('/pricing') }}" class="btn btn-outline-light btn-lg px-4">View Packages</a>
                    <a href="{{ url('/contact') }}" class="btn btn-outline-light btn-lg px-4">Book a Demo</a>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @foreach(['Retail checkout','Restaurant KOT','Inventory control','Multi-branch ready','FBR-ready for Pakistan'] as $chip)
                        <span class="hero-badge"><i class="ti ti-check"></i> {{ $chip }}</span>
                    @endforeach
                </div>
            </div>

            {{-- RIGHT: hero image with floating micro-cards --}}
            <div class="col-lg-7">
                <div style="position:relative;padding:.5rem;">

                    {{-- Floating cards --}}
                    <div class="floating-card float-soft"
                         style="top:-20px;left:0;z-index:10;">
                        <span class="dot" style="background:#10b981;"></span>
                        Live orders: <strong>14</strong>
                    </div>
                    <div class="floating-card float-soft-2"
                         style="top:-20px;right:0;">
                        <span class="dot" style="background:#f59e0b;"></span>
                        Low stock: <strong>2 items</strong>
                    </div>
                    <div class="floating-card float-soft-3"
                         style="bottom:-18px;left:10%;">
                        <span class="dot" style="background:#3b82f6;"></span>
                        Today's sales: <strong>PKR 24,550</strong>
                    </div>
                    <div class="floating-card float-soft"
                         style="bottom:-18px;right:10%;">
                        <span class="dot" style="background:#8b5cf6;"></span>
                        KOT ready: <strong>3 tables</strong>
                    </div>

                    {{-- Main hero image --}}
                    <div class="hero-glow-card p-3 reveal">
                        <img src="{{ asset('images/data/Banner-Full.webp') }}"
                             alt="Habibi POS on laptop, tablet, and mobile"
                             class="img-fluid rounded-4">
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════
     2. TRUST STRIP
═══════════════════════════════════════════════════ --}}
<div class="trust-strip py-3">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-center gap-4 py-1">
            @foreach([
                ['ti-calendar-check','30-day free trial'],
                ['ti-credit-card-off','No payment required'],
                ['ti-cloud-upload','Cloud setup in minutes'],
                ['ti-building-store','Retail + restaurant workflows'],
                ['ti-layout-grid','Multi-branch ready'],
                ['ti-shield-check','Role-based team access'],
            ] as [$ico, $txt])
                <div class="trust-item">
                    <i class="ti {{ $ico }}"></i> {{ $txt }}
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════
     3. 3-STEP PROCESS
═══════════════════════════════════════════════════ --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">How it works</span>
            <h2 class="fw-bold">Go live in 3 simple steps</h2>
            <p class="text-muted">Your dream POS is only 3 clicks away.</p>
        </div>
        <div class="row g-4 align-items-start">
            @php
                $steps = [
                    ['1','ti-layout-grid','Choose your business type',
                     'Retail, restaurant, bakery, café, inventory, or enterprise — pick the plan that fits.',
                     'Retail to enterprise covered'],
                    ['2','ti-cloud-check','Create your cloud workspace',
                     'Your tenant, owner account, trial plan, and permissions are provisioned automatically in seconds.',
                     'No server setup required'],
                    ['3','ti-rocket','Start selling with your team',
                     'Add products, open counters, print KOTs, and track branch reports from day one.',
                     'Go live immediately'],
                ];
            @endphp
            @foreach($steps as $i => [$n, $ico, $title, $desc, $sub])
                <div class="col-md-4 reveal">
                    <div class="gradient-card p-4 h-100 text-center">
                        <div class="step-number mx-auto mb-3">{{ $n }}</div>
                        <div class="icon-wrap mx-auto mb-3">
                            <i class="ti {{ $ico }}"></i>
                        </div>
                        <h5 class="fw-bold mb-2">{{ $title }}</h5>
                        <p class="text-muted mb-3">{{ $desc }}</p>
                        <span class="badge bg-success-subtle text-success border">{{ $sub }}</span>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="text-center mt-4">
            <a href="{{ url('/start-trial') }}" class="btn btn-primary btn-lg px-5">
                <i class="ti ti-rocket me-2"></i>Start My Free Trial
            </a>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════
     4. INDUSTRY SOLUTION CARDS (5 cards, all images)
═══════════════════════════════════════════════════ --}}
<section class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">Solutions</span>
            <h2 class="fw-bold">Built for the way your business actually works</h2>
            <p class="text-muted">From a single counter to a full restaurant chain — one platform, every workflow.</p>
        </div>

        <div class="row g-4">
            {{-- Retail --}}
            <div class="col-md-6 col-lg-4">
                <div class="image-card shadow-sm hover-lift reveal h-100 d-flex flex-column">
                    <div style="position:relative;overflow:hidden;">
                        <span class="badge-tag">Retail</span>
                        <img src="{{ asset('images/data/retailers.webp') }}"
                             alt="Supermarket retail POS checkout counter"
                             style="width:100%;height:200px;object-fit:cover;display:block;">
                    </div>
                    <div class="card-body flex-grow-1 d-flex flex-column">
                        <h5 class="fw-bold mb-2">Retail & Supermarket</h5>
                        <p class="text-muted small flex-grow-1">Fast barcode checkout, returns, shifts, receipts, and real-time stock visibility.</p>
                        <a href="{{ url('/start-trial?plan=retail_starter') }}" class="btn btn-primary btn-sm">Start Retail Trial &rarr;</a>
                    </div>
                </div>
            </div>

            {{-- Restaurant --}}
            <div class="col-md-6 col-lg-4">
                <div class="image-card shadow-sm hover-lift reveal h-100 d-flex flex-column">
                    <div style="position:relative;overflow:hidden;">
                        <span class="badge-tag">Restaurant</span>
                        <img src="{{ asset('images/data/restaurant.png') }}"
                             alt="Restaurant POS terminal in a warm dining setting"
                             style="width:100%;height:200px;object-fit:cover;object-position:center;display:block;">
                    </div>
                    <div class="card-body flex-grow-1 d-flex flex-column">
                        <h5 class="fw-bold mb-2">Restaurant & Café</h5>
                        <p class="text-muted small flex-grow-1">Manage tables, waiters, split bills, KOT printing, kitchen display, and daily closing.</p>
                        <a href="{{ url('/start-trial?plan=restaurant_starter') }}" class="btn btn-primary btn-sm">Start Restaurant Trial &rarr;</a>
                    </div>
                </div>
            </div>

            {{-- Bakery / QSR --}}
            <div class="col-md-6 col-lg-4">
                <div class="image-card shadow-sm hover-lift reveal h-100 d-flex flex-column">
                    <div style="position:relative;overflow:hidden;">
                        <span class="badge-tag">Bakery & QSR</span>
                        <img src="{{ asset('images/data/store.webp') }}"
                             alt="Retail store POS counter"
                             style="width:100%;height:200px;object-fit:cover;object-position:center;display:block;">
                    </div>
                    <div class="card-body flex-grow-1 d-flex flex-column">
                        <h5 class="fw-bold mb-2">Bakery & Quick Service</h5>
                        <p class="text-muted small flex-grow-1">Move fast at the counter, send tickets to the kitchen, and track popular items in real time.</p>
                        <a href="{{ url('/start-trial?plan=restaurant_starter') }}" class="btn btn-primary btn-sm">Explore Quick Service &rarr;</a>
                    </div>
                </div>
            </div>

            {{-- Inventory --}}
            <div class="col-md-6 col-lg-6">
                <div class="image-card shadow-sm hover-lift reveal h-100 d-flex flex-column" style="flex-direction:row!important;">
                    <div style="position:relative;overflow:hidden;width:45%;flex-shrink:0;">
                        <span class="badge-tag">Inventory</span>
                        <img src="{{ asset('images/data/mart.webp') }}"
                             alt="Grocery mart checkout counter"
                             style="width:100%;height:100%;object-fit:cover;object-position:center;display:block;min-height:180px;">
                    </div>
                    <div class="card-body flex-grow-1 d-flex flex-column justify-content-center p-4">
                        <h5 class="fw-bold mb-2">Inventory & Warehouse</h5>
                        <p class="text-muted small flex-grow-1">Control purchasing, suppliers, stock counts, low-stock alerts, and movement history.</p>
                        <a href="{{ url('/start-trial?plan=inventory_store') }}" class="btn btn-primary btn-sm">Start Inventory Trial &rarr;</a>
                    </div>
                </div>
            </div>

            {{-- Enterprise --}}
            <div class="col-md-6 col-lg-6">
                <div class="image-card shadow-sm hover-lift reveal h-100 d-flex flex-column" style="flex-direction:row!important;border-color:#1d4ed8;">
                    <div style="position:relative;overflow:hidden;width:45%;flex-shrink:0;">
                        <span class="badge-tag" style="background:rgba(29,78,216,.85);">Enterprise</span>
                        <img src="{{ asset('images/data/Banner-Full.webp') }}"
                             alt="Multi-device Habibi POS enterprise"
                             style="width:100%;height:100%;object-fit:cover;display:block;min-height:180px;">
                    </div>
                    <div class="card-body flex-grow-1 d-flex flex-column justify-content-center p-4">
                        <h5 class="fw-bold mb-2">Multi-Branch Enterprise</h5>
                        <p class="text-muted small flex-grow-1">Centralize branches, users, terminals, reports, and custom rollout requirements.</p>
                        <a href="{{ url('/contact?plan=enterprise') }}" class="btn btn-outline-primary btn-sm">Contact Sales &rarr;</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════
     5. DEVICE / PRODUCT SHOWCASE
═══════════════════════════════════════════════════ --}}
<section class="premium-section section-pad">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge mb-3 d-inline-block"
                  style="background:rgba(99,102,241,.2);color:#a5b4fc;padding:.4rem 1rem;border-radius:8px;">
                Real product screens
            </span>
            <h2 class="fw-bold text-white">Run the counter, kitchen, and back office from every screen.</h2>
            <p style="color:#94a3b8;">Actual screens from the live Habibi POS platform.</p>
        </div>

        {{-- Top row: dashboard large + mobile mock --}}
        <div class="row g-4 mb-4">
            <div class="col-lg-7 reveal">
                <p class="text-uppercase fw-semibold mb-2"
                   style="color:#93c5fd;font-size:.75rem;letter-spacing:2px;">
                    <i class="ti ti-layout-dashboard me-1"></i>Dashboard & Reports
                </p>
                <div class="device-frame glow-border">
                    <img src="{{ asset('images/data/dashbaord.png') }}"
                         alt="Habibi POS admin dashboard showing KPIs and sales chart">
                </div>
            </div>
            <div class="col-lg-5 d-flex flex-column gap-4">
                <div class="reveal">
                    <p class="text-uppercase fw-semibold mb-2"
                       style="color:#93c5fd;font-size:.75rem;letter-spacing:2px;">
                        <i class="ti ti-tools-kitchen-2 me-1"></i>Kitchen Display System
                    </p>
                    <div class="device-frame glow-border" style="overflow:hidden;max-height:185px;">
                        <img src="{{ asset('images/data/kitchen_display.png') }}"
                             alt="Habibi POS kitchen display with order cards"
                             style="object-fit:cover;object-position:top;">
                    </div>
                </div>
                <div class="reveal">
                    <p class="text-uppercase fw-semibold mb-2"
                       style="color:#93c5fd;font-size:.75rem;letter-spacing:2px;">
                        <i class="ti ti-chart-bar me-1"></i>Branch Analytics
                    </p>
                    <div class="mobile-mock">
                        <div class="fw-bold mb-2" style="font-size:.85rem;color:#bfdbfe;">Today's Performance</div>
                        <div class="mobile-mock-row"><span style="color:#94a3b8;">Net Sales</span><strong>PKR 24,550</strong></div>
                        <div class="mobile-mock-row"><span style="color:#94a3b8;">Orders</span><strong>38</strong></div>
                        <div class="mobile-mock-row"><span style="color:#94a3b8;">Active Shifts</span><strong>2</strong></div>
                        <div class="mobile-mock-row"><span style="color:#94a3b8;">Low Stock</span><strong style="color:#fbbf24;">2 items</strong></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Bottom row: 2 POS screens --}}
        <div class="row g-4">
            <div class="col-md-6 reveal">
                <p class="text-uppercase fw-semibold mb-2"
                   style="color:#93c5fd;font-size:.75rem;letter-spacing:2px;">
                    <i class="ti ti-receipt me-1"></i>Quick Sale POS
                </p>
                <div class="device-frame glow-border">
                    <img src="{{ asset('images/data/pos.png') }}"
                         alt="Habibi POS restaurant quick-sale screen">
                </div>
            </div>
            <div class="col-md-6 reveal">
                <p class="text-uppercase fw-semibold mb-2"
                   style="color:#93c5fd;font-size:.75rem;letter-spacing:2px;">
                    <i class="ti ti-armchair me-1"></i>Dine-In Table Board
                </p>
                <div class="device-frame glow-border">
                    <img src="{{ asset('images/data/pos2.png') }}"
                         alt="Habibi POS dine-in table board view">
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════
     6. CORE FEATURES GRID
═══════════════════════════════════════════════════ --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">Features</span>
            <h2 class="fw-bold">Everything your business needs — without stitching tools together.</h2>
            <p class="text-muted">One platform replaces separate tools for POS, inventory, restaurant, kitchen, and reporting.</p>
        </div>
        @php $features = [
            ['ti-barcode','Barcode Checkout','Fast sales, product search, held sales','Multi-payment, receipts, returns'],
            ['ti-armchair','Restaurant Tables & KOT','Floors, tables, waiters','Split bills, service charge, kitchen tickets'],
            ['ti-device-desktop','Kitchen Display','Live order routing to stations','Prep / ready / served states, no missed orders'],
            ['ti-package','Inventory & Purchasing','Stock balances, POs, GRNs','Supplier payments, low-stock alerts'],
            ['ti-transfer','Stock Count & Transfers','Physical stock counts','Variance posting, inter-branch transfers'],
            ['ti-chart-bar','Reports & Controls','Sales, shifts, inventory reports','Restaurant, kitchen, purchase, audit'],
            ['ti-credit-card','SaaS Billing','Plan invoices, payment proofs','Upgrade requests, subscription lifecycle'],
            ['ti-users','Role-Based Access','Owner, Manager, Cashier roles','Custom permissions per user'],
        ]; @endphp
        <div class="row g-3">
            @foreach($features as [$ico, $title, $b1, $b2])
                <div class="col-md-6 col-lg-3">
                    <div class="gradient-card p-4 h-100 reveal">
                        <div class="icon-wrap">
                            <i class="ti {{ $ico }}"></i>
                        </div>
                        <h6 class="fw-bold mb-2">{{ $title }}</h6>
                        <ul class="list-unstyled text-muted small mb-0">
                            <li class="mb-1"><i class="ti ti-check text-success me-1"></i>{{ $b1 }}</li>
                            <li><i class="ti ti-check text-success me-1"></i>{{ $b2 }}</li>
                        </ul>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="text-center mt-4">
            <a href="{{ url('/features') }}" class="btn btn-outline-primary px-4">Explore All Features</a>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════
     7. FBR-READY PAKISTAN SECTION
═══════════════════════════════════════════════════ --}}
<section class="section-pad bg-white">
    <div class="container">
        <div class="fbr-section p-5 reveal">
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <span class="badge mb-3 d-inline-block"
                          style="background:rgba(52,211,153,.15);color:#34d399;border:1px solid rgba(52,211,153,.3);padding:.4rem 1rem;border-radius:8px;">
                        <i class="ti ti-flag me-1"></i>Pakistan Compliance
                    </span>
                    <h2 class="fw-bold text-white mb-3">FBR-ready workflows for Pakistan retailers.</h2>
                    <p style="color:#94a3b8;" class="mb-4">
                        For eligible Pakistan businesses, Habibi POS is being designed with FBR-ready
                        invoice workflows, branch-level tax details, receipt configuration, and
                        future integration support.
                    </p>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Branch tax registration number fields</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Taxable products and per-line tax amounts</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Receipt tax number and footer configuration</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Future invoice sync and QR workflow planning</span></div>
                    <a href="{{ url('/contact?topic=fbr') }}"
                       class="btn btn-success btn-lg px-4 mt-2">Ask about FBR setup &rarr;</a>
                </div>
                <div class="col-lg-5 text-center">
                    <div style="background:rgba(255,255,255,.06);border-radius:1.5rem;padding:2rem;border:1px solid rgba(52,211,153,.2);">
                        <i class="ti ti-receipt-tax" style="font-size:5rem;color:#34d399;"></i>
                        <div class="text-white fw-bold mt-3 mb-1" style="font-size:1.2rem;">FBR-Ready Workflows</div>
                        <div style="color:#94a3b8;font-size:.9rem;">
                            Optional FBR integration support<br>for eligible Pakistan businesses.
                        </div>
                        <div class="mt-3" style="color:#f59e0b;font-size:.8rem;font-weight:600;">
                            ⚠ Not FBR certified. Not an official FBR partner.<br>
                            Integration is optional and business-eligible.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════
     8. STATS / CONFIDENCE NUMBERS
═══════════════════════════════════════════════════ --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <h2 class="fw-bold">Platform capability at a glance</h2>
            <p class="text-muted">Designed to support thousands of daily transactions across counters, kitchens, and branches.</p>
        </div>
        <div class="row g-4 justify-content-center">
            @php $stats = [
                ['13','+'  ,'Business modules'],
                ['30','day','Free trial period'],
                ['5' ,''   ,'Public plan options'],
                ['8' ,'+'  ,'Industry use cases'],
            ]; @endphp
            @foreach($stats as [$n, $sfx, $label])
                <div class="col-6 col-md-3">
                    <div class="stat-card reveal hover-lift">
                        <div class="stat-number"
                             data-count="{{ $n }}" data-suffix="{{ $sfx }}">{{ $n }}{{ $sfx }}</div>
                        <div class="stat-label">{{ $label }}</div>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="text-center text-muted small mt-4">
            * Capability-based figures. Actual customer data will be shown here as platform grows.
        </p>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════
     9. TESTIMONIALS / SAMPLE SCENARIOS
═══════════════════════════════════════════════════ --}}
<section class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">Stories</span>
            <h2 class="fw-bold">Built around the way real teams work</h2>
            <p class="text-muted small">Sample customer scenarios — replace with real testimonials when available.</p>
        </div>
        @php $testimonials = [
            ['RM','Restaurant Manager','UAE',  '#1d4ed8','#eff6ff',
             'We can manage tables, kitchen tickets, and daily closing from one place. The kitchen display keeps the team in sync.'],
            ['RO','Retail Owner',      'Pakistan','#7c3aed','#f5f3ff',
             'Barcode checkout, stock visibility, and branch-level controls make daily work so much easier than before.'],
            ['CO','Café Operator',     'Saudi Arabia','#059669','#f0fdf4',
             'Our team takes orders fast and the kitchen gets the ticket instantly. Fewer errors, faster service.'],
            ['IS','Inventory Supervisor','UK',  '#d97706','#fffbeb',
             'Purchasing, stock counts, and supplier reports help us see exactly what is happening across the warehouse.'],
        ]; @endphp
        <div class="row g-4">
            @foreach($testimonials as [$initials, $role, $country, $color, $bg, $quote])
                <div class="col-md-6 col-lg-3">
                    <div class="testimonial-card reveal">
                        <div class="quote-icon mb-2">&ldquo;</div>
                        <p class="text-muted small mb-4">{{ $quote }}</p>
                        <div class="d-flex align-items-center gap-2">
                            <div class="avatar-circle" style="background:linear-gradient(135deg,{{ $color }},{{ $color }}aa);">
                                {{ $initials }}
                            </div>
                            <div>
                                <div class="fw-semibold" style="font-size:.85rem;">{{ $role }}</div>
                                <span class="country-badge" style="background:{{ $bg }};color:{{ $color }};border-color:{{ $color }}44;">
                                    {{ $country }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════
     10. WHY HABIBI POS COMPARISON
═══════════════════════════════════════════════════ --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">Comparison</span>
            <h2 class="fw-bold">Why businesses move to Habibi POS</h2>
        </div>
        <div class="reveal table-responsive">
            <table class="table comparison-tbl border rounded-3 overflow-hidden">
                <thead>
                    <tr>
                        <th style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">Workflow</th>
                        <th class="text-center" style="background:#fef2f2;color:#991b1b;border-bottom:2px solid #fecaca;">Traditional POS</th>
                        <th class="text-center" style="background:#fff7ed;color:#92400e;border-bottom:2px solid #fed7aa;">Disconnected Tools</th>
                        <th class="text-center col-habibi" style="border-bottom:2px solid #bfdbfe;"><span style="color:#1d4ed8;font-weight:800;">Habibi POS</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach([
                        ['Checkout & sales', 'Basic','Manual entry','✓ Barcode, cart, multi-payment'],
                        ['Inventory control','Offline','Spreadsheet','✓ Live stock, transfers, counts'],
                        ['Restaurant kitchen','No KOT','Paper tickets','✓ Digital KOT + Kitchen Display'],
                        ['Reporting','Day-end only','Multiple apps','✓ Real-time, branch-level'],
                        ['Multi-branch','Single store','Complex setup','✓ Built-in, per-branch'],
                        ['Subscription control','N/A','N/A','✓ Plans, limits, upgrades'],
                    ] as [$row, $col1, $col2, $col3])
                        <tr>
                            <td class="fw-semibold">{{ $row }}</td>
                            <td class="text-center"><i class="ti ti-x check-no"></i> <span class="text-muted small">{{ $col1 }}</span></td>
                            <td class="text-center"><i class="ti ti-x check-no"></i> <span class="text-muted small">{{ $col2 }}</span></td>
                            <td class="text-center col-habibi"><i class="ti ti-check check-yes"></i> <span class="small text-dark">{{ $col3 }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════
     11. PRICING PREVIEW
═══════════════════════════════════════════════════ --}}
<section class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">Pricing</span>
            <h2 class="fw-bold">Pick the package that fits your business</h2>
            <p class="text-muted">Start with a 30-day free trial. Upgrade any time. No payment required.</p>
        </div>
        @php
            $planBadges = [
                'retail_starter'    => ['plan-badge-retail','Best for small shops'],
                'inventory_store'   => ['plan-badge-inv','Best for marts'],
                'restaurant_starter'=> ['plan-badge-resto','Best for cafés'],
                'restaurant_pro'    => ['plan-badge-popular','⭐ Most Popular'],
            ];
        @endphp
        <div class="row g-4 justify-content-center">
            @foreach($selfServicePlans as $plan)
                @php
                    $badge = $planBadges[$plan->code] ?? ['plan-badge-retail',''];
                    $popular = $plan->code === 'restaurant_pro';
                @endphp
                <div class="col-md-6 col-lg-3">
                    <div class="plan-card p-4 h-100 d-flex flex-column hover-lift reveal {{ $popular ? 'plan-card-popular' : '' }}">
                        @if($badge[1])
                            <span class="plan-badge {{ $badge[0] }} mb-2 align-self-start">{{ $badge[1] }}</span>
                        @endif
                        <h5 class="fw-bold mb-1">{{ $plan->name }}</h5>
                        <p class="text-muted small flex-grow-1">{{ $plan->public_description }}</p>
                        <div class="plan-price">{{ $plan->currency_code }} {{ number_format((float)($plan->monthly_price ?? $plan->price), 0) }}</div>
                        <div class="text-muted small mb-1">per month</div>
                        @if($plan->yearly_price)
                            <div class="text-muted small mb-2">or {{ $plan->currency_code }} {{ number_format((float)$plan->yearly_price, 0) }} / year</div>
                        @endif
                        @if($plan->trial_days)
                            <div class="text-success small mb-3"><i class="ti ti-check me-1"></i>{{ $plan->trial_days }}-day free trial</div>
                        @endif
                        <a href="{{ url('/start-trial?plan=' . $plan->code) }}"
                           class="btn {{ $popular ? 'btn-primary' : 'btn-outline-primary' }} mt-auto">Start Trial</a>
                    </div>
                </div>
            @endforeach

            @foreach($customPlans as $plan)
                <div class="col-md-6 col-lg-3">
                    <div class="plan-card p-4 h-100 d-flex flex-column hover-lift reveal" style="border-color:#1d4ed8;">
                        <span class="plan-badge plan-badge-inv mb-2 align-self-start">Custom rollout</span>
                        <h5 class="fw-bold mb-1">{{ $plan->name }}</h5>
                        <p class="text-muted small flex-grow-1">{{ $plan->public_description }}</p>
                        <div class="fw-semibold fs-5 mb-1">Custom pricing</div>
                        <div class="text-muted small mb-3">Tailored to your scale</div>
                        <a href="{{ url('/contact?plan=' . $plan->code) }}" class="btn btn-outline-primary mt-auto">Contact Sales</a>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="text-center mt-4">
            <a href="{{ url('/pricing') }}" class="btn btn-link fw-semibold">Compare full plan details &amp; modules &rarr;</a>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════
     12. FAQ
═══════════════════════════════════════════════════ --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-5 reveal">
                    <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">FAQ</span>
                    <h2 class="fw-bold">Common questions</h2>
                </div>
                <div class="accordion reveal" id="faqAccordion">
                    @php $faqs = [
                        ['Do I need to install anything?',
                         'No. Habibi POS is a cloud-based SaaS. You access it from any browser on any device — no software download or server required.'],
                        ['Is payment required for the trial?',
                         'No. The 30-day free trial requires no payment details. Simply sign up, provision your workspace, and start using it immediately.'],
                        ['Can I use Habibi POS for restaurants?',
                         'Yes. The Restaurant Starter and Restaurant Pro plans include table management, KOT printing, kitchen display, split bills, and service charges.'],
                        ['Can I manage inventory and purchasing?',
                         'Yes. The Inventory Store and Restaurant Pro plans include stock balances, purchase orders, GRNs, supplier management, stock counts, and transfers.'],
                        ['Can I upgrade my plan later?',
                         'Yes. You can request a plan upgrade at any time from the billing portal. Our team approves the request and issues an upgrade invoice.'],
                        ['Does Habibi POS support multiple branches?',
                         'Yes. The Restaurant Pro and Enterprise plans include multi-branch controls with per-branch terminals, users, shifts, reports, and stock.'],
                        ['Is FBR integration available for Pakistan?',
                         'Habibi POS has FBR-ready workflows designed for eligible Pakistan businesses, including branch tax fields, taxable products, and receipt tax numbers. Full FBR invoice sync is planned. This is not an official FBR-certified integration.'],
                        ['Can I use barcode scanners and receipt printers?',
                         'Yes. Habibi POS supports USB and wireless barcode scanners, thermal receipt printers, and KOT kitchen printers via the local print agent.'],
                    ]; @endphp
                    @foreach($faqs as $i => [$q, $a])
                        <div class="accordion-item border-0 mb-2 rounded-3 overflow-hidden shadow-sm">
                            <h2 class="accordion-header">
                                <button class="accordion-button {{ $i > 0 ? 'collapsed' : '' }} rounded-3"
                                        type="button" data-bs-toggle="collapse"
                                        data-bs-target="#faq{{ $i }}">
                                    {{ $q }}
                                </button>
                            </h2>
                            <div id="faq{{ $i }}" class="accordion-collapse collapse {{ $i === 0 ? 'show' : '' }}"
                                 data-bs-parent="#faqAccordion">
                                <div class="accordion-body text-muted">{{ $a }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════
     13. FINAL CTA
═══════════════════════════════════════════════════ --}}
<section class="public-hero-premium section-pad">
    <div class="container text-center">
        <div class="reveal" style="max-width:640px;margin:0 auto;">
            <h2 class="fw-bold text-white mb-3" style="font-size:2.4rem;">
                Start your cloud POS trial today.
            </h2>
            <p class="mb-4" style="color:#cbd5e1;font-size:1.1rem;">
                Launch your store or restaurant workspace in minutes. No payment required.
            </p>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <a href="{{ url('/start-trial') }}"
                   class="btn btn-light btn-lg px-5 fw-semibold"
                   style="box-shadow:0 4px 20px rgba(255,255,255,.25);">
                    <i class="ti ti-rocket me-2"></i>Start 30-Day Free Trial
                </a>
                <a href="{{ url('/contact') }}" class="btn btn-outline-light btn-lg px-5">
                    <i class="ti ti-calendar me-2"></i>Book a Demo
                </a>
            </div>
            <div class="d-flex flex-wrap justify-content-center gap-3 mt-4">
                <span class="hero-badge"><i class="ti ti-check"></i> No payment required</span>
                <span class="hero-badge"><i class="ti ti-check"></i> Live in minutes</span>
                <span class="hero-badge"><i class="ti ti-check"></i> Cancel anytime</span>
            </div>
        </div>
    </div>
</section>

@endsection
