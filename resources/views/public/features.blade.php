@extends('layouts.public')

@section('title', __('Features'))
@section('meta_description', __('Explore Bingoo POS features: retail checkout, restaurant service, kitchen operations, inventory, purchasing, reports, SaaS billing, and FBR-ready workflows.'))

@section('content')
@php
    // WEBSITE-I18N-GEO-1: FBR is Pakistan's; until markets arrive (P2) it shows on default-language pages only.
    $pakistanSite = app()->getLocale() === \App\Support\PublicLocale::default();
@endphp

{{-- HERO --}}
<section class="public-hero-premium" style="padding:4rem 0 2.5rem;position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;inset-inline-end:-30px;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <span class="hero-badge mb-3"><i class="ti ti-stars"></i> {{ __('Everything in one platform') }}</span>
        <h1 class="fw-bold mb-2" style="font-size:2.3rem;">{{ __('Built for the whole operation') }}</h1>
        <p class="lead mb-0 mx-auto" style="color:#cbd5e1;max-width:760px;">{{ __('From the front counter to the kitchen to the back office — one connected cloud POS.') }}</p>
    </div>
</section>

{{-- CATEGORY NAV --}}
<div class="trust-strip py-3" style="position:sticky;top:64px;z-index:1020;">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-center gap-2">
            @foreach(array_filter([
                ['#retail', __('Retail Checkout')], ['#restaurant', __('Restaurant Service')], ['#kitchen', __('Kitchen Operations')],
                ['#inventory', __('Inventory & Purchasing')], ['#reports', __('Reports & Controls')], ['#finance', __('Finance & Accounting')],
                ['#saas', __('SaaS Billing & Team Access')], $pakistanSite ? ['#fbr', __('FBR-ready Workflows')] : null,
            ]) as [$anchor,$label])
                <a href="{{ $anchor }}" class="marquee-chip text-decoration-none">{{ $label }}</a>
            @endforeach
        </div>
    </div>
</div>

@php
    // [id, category, title, image, imageRight, [capabilities], businessValue]
    $spotlights = [
        ['retail', __('Retail Checkout'), __('Fast, accurate checkout at every counter'), 'images/data/retailers.webp', false,
            [__('Barcode scanning & product search'), __('Held sales & multi-payment checkout'), __('Sales returns & customer ledger'), __('Promotions & price controls')],
            __('Move queues faster and keep stock accurate in real time.')],
        ['restaurant', __('Restaurant Service'), __('Run dine-in, takeaway, and delivery'), 'images/data/restaurant.png', true,
            [__('Floors, tables & waiters'), __('Split bills & service charges'), __('Dine-in / takeaway / delivery order types'), __('Live table board')],
            __('One flow from seating the guest to closing the day.')],
        ['kitchen', __('Kitchen Operations'), __('Send orders to the kitchen without paper'), 'images/data/kitchen_display.png', false,
            [__('Kitchen Display System (KDS)'), __('KOT routing by category & station'), __('Prep / ready / served states'), __('Recipes, productions & wastage')],
            __('Faster turnaround and fewer missed or wrong orders.')],
        ['inventory', __('Inventory & Purchasing'), __('Know your stock before it runs out'), 'images/data/inventory.jpg', true,
            [__('Stock balances & valuation'), __('Purchase orders, GRNs & bills'), __('Suppliers & supplier payments'), __('Stock counts, transfers & low-stock alerts')],
            __('Control what you buy, hold, and sell across branches.')],
        ['reports', __('Reports & Controls'), __('See the whole business in real time'), 'images/data/dashbaord.png', false,
            [__('Sales, shifts & daily closings'), __('Inventory & purchase reporting'), __('Restaurant & kitchen reports'), __('Manager approvals & audit logs')],
            __('Make decisions from live, branch-level numbers.')],
        ['finance', __('Finance & Accounting'), __('Audit-ready books, built in'), 'images/data/dashbaord.png', true,
            [__('Chart of accounts, cash & bank accounts'), __('Expenses, supplier & customer payments'), __('Double-entry general ledger that auto-posts from sales, purchases & expenses'), __('Trial Balance, P&L, Branch-wise P&L, Balance Sheet + CSV export')],
            __('Run your accounting inside your POS — included on Restaurant Pro & Enterprise.')],
        ['saas', __('SaaS Billing & Team Access'), __('Plans, billing, and role-based access'), 'images/data/pos2.png', false,
            [__('Plans, modules & usage limits'), __('Invoices & payment proofs'), __('Plan upgrade requests'), __('Owner / Manager / Cashier roles')],
            __('Scale your subscription and team safely as you grow.')],
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
                    <a href="{{ $lurl('/start-trial') }}" class="btn btn-primary">{{ __('Start Free Trial') }}</a>
                </div>
            </div>
        </div>
    </section>
@endforeach

{{-- FINANCE & SUPPLY CHAIN ERP --}}
<section id="finance-erp" class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">{{ __('ERP / Finance') }}</span>
            <h2 class="fw-bold">{{ __('Finance & Supply Chain ERP') }}</h2>
            <p class="text-muted mx-auto" style="max-width:760px;">{{ __('Accounting-led operations today, with an ERP/manufacturing roadmap you can grow into.') }}</p>
        </div>
        <div class="row g-4 reveal">
            <div class="col-lg-6">
                <div class="gradient-card p-4 h-100">
                    <h5 class="fw-bold mb-3"><i class="ti ti-calculator me-2 text-primary"></i>{{ __('Accounts Department') }} <span class="badge bg-success ms-1">{{ __('Available Now') }}</span></h5>
                    <ul class="list-unstyled mb-0 text-muted">
                        @foreach([__('Journal Entries & General Ledger'), __('Trial Balance'), __('Profit & Loss'), __('Balance Sheet'), __('AR / AP Aging')] as $item)
                            <li class="mb-2"><i class="ti ti-check text-success me-2"></i>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="gradient-card p-4 h-100">
                    <h5 class="fw-bold mb-3"><i class="ti ti-truck-delivery me-2 text-primary"></i>{{ __('Supply Chain Foundation') }} <span class="badge bg-success ms-1">{{ __('Available Now') }}</span></h5>
                    <ul class="list-unstyled mb-0 text-muted">
                        @foreach([__('Purchase Orders'), __('GRN (Goods Receipt)'), __('Inventory stock movement'), __('Suppliers and customers'), __('Sales returns')] as $item)
                            <li class="mb-2"><i class="ti ti-check text-success me-2"></i>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <div class="mt-4 p-4 rounded-4 reveal" style="background:#0f172a;color:#e2e8f0;">
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="badge bg-warning text-dark">{{ __('Coming Soon') }}</span>
                <strong>{{ __('Planned ERP Extensions') }}</strong>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @foreach([__('Quotation'), __('Purchase Requisition'), __('Purchase Returns'), __('BOM'), __('MRC'), __('WIP'), __('Finished Goods'), __('Scrap / Rejections'), __('Production Reporting')] as $soon)
                    <span class="marquee-chip"><i class="ti ti-clock-hour-4"></i>{{ $soon }}</span>
                @endforeach
            </div>
            <p class="small mb-0 mt-3" style="color:#94a3b8;">{{ __('Manufacturing/production modules are a planned ERP extension, customizable per business — not yet live.') }}</p>
        </div>

        <div class="text-center mt-4 reveal">
            <a href="{{ $lurl('/demos') }}#finance" class="btn btn-primary"><i class="ti ti-player-play me-1"></i>{{ __('Open Finance ERP Demo') }}</a>
        </div>
    </div>
</section>

{{-- FBR-READY WORKFLOWS (Pakistan site only) --}}
@if($pakistanSite)
<section id="fbr" class="section-pad bg-white">
    <div class="container">
        <div class="fbr-section p-5 reveal">
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <span class="badge mb-3 d-inline-block" style="background:rgba(245,200,90,.15);color:#e9c869;border:1px solid rgba(245,200,90,.3);padding:.4rem 1rem;border-radius:8px;">
                        <i class="ti ti-flag me-1"></i>{{ __('Pakistan Compliance') }}
                    </span>
                    <h2 class="fw-bold text-white mb-3">{{ __('FBR-ready Workflows') }}</h2>
                    <p style="color:#94a3b8;" class="mb-4">{{ __('For eligible Pakistan businesses, Bingoo POS is being designed with FBR-ready invoice workflows and tax configuration.') }}</p>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>{{ __('Branch tax registration number fields') }}</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>{{ __('Taxable products and per-line tax amounts') }}</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>{{ __('Receipt tax number and footer configuration') }}</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>{{ __('Future invoice sync and QR workflow planning') }}</span></div>
                    <a href="{{ $lurl('/contact?topic=fbr') }}" class="btn btn-success btn-lg px-4 mt-2">{{ __('Ask about FBR setup') }} <span class="dir-arrow">&rarr;</span></a>
                    <p class="mt-3 mb-0" style="color:#fbbf24;font-size:.8rem;">{{ __('Not an official FBR certification claim. Final compliance depends on business setup and official FBR requirements.') }}</p>
                </div>
                <div class="col-lg-5">
                    <div class="receipt-mock mx-auto" style="max-width:260px;">
                        <div class="text-center fw-bold">BINGOO POS</div>
                        <div class="text-center" style="font-size:.7rem;color:#64748b;">{{ __('Tax Invoice') }}</div>
                        <hr>
                        <div class="r-row"><span>NTN</span><span>XXXXXXX-X</span></div>
                        <div class="r-row"><span>{{ __('Sales tax') }}</span><span>340</span></div>
                        <div class="r-row fw-bold"><span>{{ __('Total') }}</span><span>2,340</span></div>
                        <div class="r-row" style="color:#a87f24;"><span>{{ __('FBR sync') }}</span><span>{{ __('Planned') }}</span></div>
                        <div class="d-flex justify-content-center mt-2"><div class="qr-mock"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endif

{{-- FEATURE MATRIX SUMMARY --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <h2 class="fw-bold">{{ __('Every module, in one platform') }}</h2>
            <p class="text-muted">{{ __('No stitching separate tools together.') }}</p>
        </div>
        @php $modules = [
            ['ti-barcode', __('POS & Sales')], ['ti-stack-2', __('Catalog')], ['ti-package', __('Inventory')], ['ti-truck-delivery', __('Purchasing')],
            ['ti-transfer', __('Stock Count & Transfers')], ['ti-armchair', __('Restaurant')], ['ti-device-desktop', __('Kitchen Display')],
            ['ti-chef-hat', __('Kitchen Inventory')], ['ti-printer', __('Printing')], ['ti-chart-bar', __('Reports')], ['ti-adjustments', __('Sales Controls')],
            ['ti-building-store', __('Multi Branch')], ['ti-users', __('Users & Roles')], ['ti-report-money', __('Finance & Accounting')],
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
        <h2 class="fw-bold text-white mb-3" style="font-size:2.2rem;">{{ __('See the workflows in action.') }}</h2>
        <p class="mb-4" style="color:#cbd5e1;">{{ __('Start a 30-day trial or talk to our team for a guided walkthrough.') }}</p>
        <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="{{ $lurl('/start-trial') }}" class="btn btn-light btn-lg px-5">{{ __('Start Trial') }}</a>
            <a href="{{ $lurl('/demos') }}" class="btn btn-outline-light btn-lg px-5">{{ __('Try Live Demo') }}</a>
            <a href="{{ $lurl('/pricing') }}" class="btn btn-outline-light btn-lg px-5">{{ __('View Pricing') }}</a>
            <a href="{{ $lurl('/contact') }}" class="btn btn-outline-light btn-lg px-5">{{ __('Book Demo') }}</a>
        </div>
    </div>
</section>

@endsection
