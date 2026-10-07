@extends('layouts.public')

@section('title', __('Cloud POS for Retail, Restaurants & Inventory Teams'))
@section('meta_description', __('Launch a cloud POS for retail checkout, restaurant KOT, inventory, purchasing, reports, and multi-branch operations in minutes. 30-day free trial.'))

@section('content')
@php
    // WEBSITE-I18N-GEO-1: the Pakistan-only blocks (FBR) belong to the Pakistan site. Until markets
    // arrive (P2) the language stands in for it: they show on the default-language pages only.
    $pakistanSite = app()->getLocale() === \App\Support\PublicLocale::default();
    $mockCurrency = $pakistanSite ? 'PKR' : 'SAR';
@endphp

{{-- ═══════════ 1. HERO ═══════════ --}}
<section class="public-hero-premium" style="padding:5rem 0 3.5rem;position:relative;">
    <div class="mega-glow" style="top:-80px;inset-inline-start:-60px;background:#caa23f;"></div>
    <div class="mega-glow" style="bottom:-120px;inset-inline-end:-40px;background:#1e3a6b;"></div>
    <div class="container" style="position:relative;z-index:2;">
        <div class="row align-items-center gy-5">
            <div class="col-lg-5">
                <span class="hero-badge mb-3"><i class="ti ti-bolt"></i> {{ __('Your dream POS is only 3 clicks away') }}</span>
                <h1 class="fw-bold mb-3" style="font-size:2.6rem;line-height:1.2;">
                    {{ __('Sell anything, run everything — one powerful cloud POS.') }}
                </h1>
                <p class="mb-4" style="color:#cbd5e1;font-size:1.1rem;line-height:1.7;">
                    {{ __('Launch a cloud workspace for sales, barcode checkout, restaurant tables, KOT, kitchen display, purchasing, stock control, reports, and multi-branch operations —') }}
                    <strong style="color:#fff;">{{ __('in minutes, not weeks.') }}</strong>
                </p>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <a href="{{ $lurl('/start-trial') }}" class="btn btn-light btn-lg px-4 fw-semibold" style="box-shadow:0 4px 20px rgba(255,255,255,.25);">
                        <i class="ti ti-rocket me-2"></i>{{ __('Start 30-Day Free Trial') }}
                    </a>
                    <a href="{{ $lurl('/demos') }}" class="btn btn-outline-light btn-lg px-4"><i class="ti ti-player-play me-2"></i>{{ __('Try Live Demo') }}</a>
                    <a href="{{ $lurl('/pricing') }}" class="btn btn-outline-light btn-lg px-4">{{ __('View Packages') }}</a>
                    <a href="{{ $lurl('/contact') }}" class="btn btn-outline-light btn-lg px-4">{{ __('Book a Demo') }}</a>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @foreach(array_filter([__('Retail checkout'), __('Restaurant KOT'), __('Inventory control'), __('Multi-branch ready'), $pakistanSite ? __('FBR-ready for Pakistan') : null]) as $chip)
                        <span class="hero-badge"><i class="ti ti-check"></i> {{ $chip }}</span>
                    @endforeach
                </div>
            </div>

            <div class="col-lg-7">
                <div style="position:relative;padding:.5rem;">
                    <div class="floating-card float-card" style="top:-18px;inset-inline-start:0;z-index:10;">
                        <span class="dot" style="background:#10b981;"></span> {{ __('Live order') }} <strong><bdi>#1042</bdi></strong>
                    </div>
                    <div class="floating-card float-card-delay" style="top:-18px;inset-inline-end:0;">
                        <span class="dot" style="background:#8b5cf6;"></span> {{ __('KOT ready:') }} <strong>{{ __('Table 8') }}</strong>
                    </div>
                    <div class="floating-card float-card-delay" style="bottom:60px;inset-inline-start:-10px;">
                        <span class="dot" style="background:#f59e0b;"></span> {{ __('Low stock:') }} <strong>{{ __(':count items', ['count' => 3]) }}</strong>
                    </div>
                    <div class="floating-card float-card" style="bottom:-18px;inset-inline-end:8%;">
                        <span class="dot" style="background:#caa23f;"></span> {{ __("Today's sales:") }} <strong><bdi>{{ $mockCurrency }} 84,250</bdi></strong>
                    </div>
                    <div class="floating-card float-card-delay" style="bottom:-18px;inset-inline-start:6%;">
                        <span class="dot" style="background:#10b981;"></span> {{ __('Branch report') }} <strong>{{ __('synced') }}</strong>
                    </div>

                    {{-- All-in-one module thumbnails (real product images) — signals retail/inventory/reports, not just restaurant --}}
                    <div class="hero-modrail float-soft-2 d-none d-lg-flex">
                        <div class="hero-modrail-cap">{{ __('All-in-one') }}</div>
                        <div class="hero-modthumb"><img src="{{ asset('images/data/hero-retail.webp') }}" alt="{{ __('Retail checkout') }}" loading="lazy"><span>{{ __('Retail') }}</span></div>
                        <div class="hero-modthumb"><img src="{{ asset('images/data/hero-inventory.webp') }}" alt="{{ __('Inventory & stock') }}" loading="lazy"><span>{{ __('Inventory') }}</span></div>
                        <div class="hero-modthumb"><img src="{{ asset('images/data/hero-reports.webp') }}" alt="{{ __('Reports & dashboards') }}" loading="lazy"><span>{{ __('Reports') }}</span></div>
                    </div>

                    <div class="hero-glow-card p-3 reveal">
                        <img src="{{ asset('images/data/Banner-Full.webp') }}"
                             alt="{{ __('Bingoo POS on laptop, tablet, and mobile') }}" class="img-fluid rounded-4">
                    </div>

                    {{-- Mini receipt ticket --}}
                    <div class="receipt-mock float-card d-none d-xl-block"
                         style="position:absolute;inset-inline-end:-26px;top:34%;width:190px;">
                        <div class="text-center fw-bold mb-1">BINGOO POS</div>
                        <div class="text-center" style="font-size:.7rem;color:#64748b;">{{ __('Order') }} <bdi>#1042</bdi></div>
                        <hr>
                        <div class="r-row"><span>{{ __('Burger Combo') }} ×2</span><span>1,300</span></div>
                        <div class="r-row"><span>{{ __('Latte') }} ×1</span><span>450</span></div>
                        <hr>
                        <div class="r-row fw-bold"><span>{{ __('Status') }}</span><span style="color:#a87f24;">{{ __('KOT Sent') }}</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════ 2. TRUST STRIP ═══════════ --}}
<div class="trust-strip py-3">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-center gap-4 py-1">
            @foreach([
                ['ti-calendar-check', __('30-day free trial')],
                ['ti-credit-card-off', __('No payment required')],
                ['ti-cloud-upload', __('Cloud setup in minutes')],
                ['ti-building-store', __('Retail + restaurant workflows')],
                ['ti-layout-grid', __('Multi-branch ready')],
                ['ti-shield-check', __('Role-based team access')],
            ] as [$ico, $txt])
                <div class="trust-item"><i class="ti {{ $ico }}"></i> {{ $txt }}</div>
            @endforeach
        </div>
    </div>
</div>

{{-- ═══════════ 3. MARQUEE STRIP ═══════════ --}}
<div class="bg-white py-4 marquee-wrap border-bottom">
    <div class="marquee-track">
        @php $chips = array_values(array_filter([__('Barcode checkout'), __('Kitchen orders'), __('Stock counts'), __('Branch reports'), __('Plan upgrades'), __('Payment proof billing'), $pakistanSite ? __('FBR-ready workflows') : null, __('30-day free trial')])); @endphp
        @foreach(array_merge($chips, $chips) as $c)
            <span class="marquee-chip"><i class="ti ti-point-filled"></i>{{ $c }}</span>
        @endforeach
    </div>
</div>

{{-- ═══════════ 4. 3-STEP PROCESS ═══════════ --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">{{ __('How it works') }}</span>
            <h2 class="fw-bold">{{ __('Go live in 3 simple steps') }}</h2>
            <p class="text-muted">{{ __('Your dream POS is only 3 clicks away.') }}</p>
        </div>
        <div class="row g-4">
            @php $steps = [
                ['1','ti-layout-grid', __('Choose your business type'), __('Retail, restaurant, bakery, café, inventory, or enterprise — pick the plan that fits.'), __('Retail to enterprise covered')],
                ['2','ti-cloud-check', __('Create your cloud workspace'), __('Your tenant, owner account, trial plan, and permissions are provisioned automatically.'), __('No server setup required')],
                ['3','ti-rocket', __('Start selling with your team'), __('Add products, open counters, print KOTs, and track branch reports from day one.'), __('Go live immediately')],
            ]; @endphp
            @foreach($steps as [$n, $ico, $title, $desc, $sub])
                <div class="col-md-4 reveal">
                    <div class="gradient-card p-4 h-100 text-center">
                        <div class="step-number mx-auto mb-3">{{ $n }}</div>
                        <div class="icon-wrap mx-auto mb-3"><i class="ti {{ $ico }}"></i></div>
                        <h5 class="fw-bold mb-2">{{ $title }}</h5>
                        <p class="text-muted mb-3">{{ $desc }}</p>
                        <span class="badge bg-success-subtle text-success border">{{ $sub }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════ 5. INDUSTRY CAROUSEL ═══════════ --}}
<section class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">{{ __('Solutions') }}</span>
            <h2 class="fw-bold">{{ __('One POS. Different businesses. Same control.') }}</h2>
            <p class="text-muted">{{ __('Built for the way your business actually works.') }}</p>
        </div>

        @php $slides = [
            ['retailers.webp', __('Retail'), __('Retail & Supermarket'), __('Long queues, stock errors, and barcode confusion.'), __('Fast checkout, live stock, returns, receipt printing, and team access.'), __('Start Retail Trial'), '/start-trial?plan=retail_starter', 'retail'],
            ['restaurant.png', __('Restaurant'), __('Restaurant & Café'), __('Tables, waiters, kitchen tickets, and split bills become messy.'), __('Table service, KOT, KDS, service charges, and daily closing in one flow.'), __('Start Restaurant Trial'), '/start-trial?plan=restaurant_starter', 'restaurant'],
            ['store.webp', __('Bakery & QSR'), __('Bakery & Quick Service'), __('Rush hours need speed, not complicated screens.'), __('Quick order taking, kitchen tickets, popular item tracking, and takeaway support.'), __('Explore Quick Service'), '/start-trial?plan=restaurant_starter', 'restaurant'],
            ['mart.webp', __('Inventory'), __('Inventory & Warehouse'), __('Stock disappears before reports catch up.'), __('Purchasing, GRNs, stock counts, supplier tracking, and low-stock visibility.'), __('Start Inventory Trial'), '/start-trial?plan=inventory_store', 'inventory'],
            ['Banner-Full.webp', __('Enterprise'), __('Multi-Branch Enterprise'), __('Branches, teams, terminals, and reports get scattered.'), __('Multi-branch control, custom rollout, centralized permissions, and reporting.'), __('Contact Sales'), '/contact?plan=enterprise', 'enterprise'],
        ]; @endphp

        <div id="industryCarousel" class="carousel slide carousel-premium reveal" data-bs-ride="carousel" data-bs-interval="5000">
            <div class="carousel-indicators">
                @foreach($slides as $i => $s)
                    <button type="button" data-bs-target="#industryCarousel" data-bs-slide-to="{{ $i }}" class="{{ $i===0?'active':'' }}"></button>
                @endforeach
            </div>
            <div class="carousel-inner">
                @foreach($slides as $i => [$img, $badge, $title, $pain, $solution, $cta, $link, $demoAnchor])
                    <div class="carousel-item {{ $i===0?'active':'' }}">
                        <div class="row g-0 align-items-stretch">
                            <div class="col-lg-6">
                                <img src="{{ asset('images/data/' . $img) }}" alt="{{ $title }}" class="industry-slide-img">
                            </div>
                            <div class="col-lg-6 d-flex align-items-center">
                                <div class="p-4 p-lg-5 text-white">
                                    <span class="badge mb-3 d-inline-block" style="background:rgba(245,158,11,.2);color:#fcd34d;padding:.4rem .9rem;border-radius:8px;">{{ $badge }}</span>
                                    <h3 class="fw-bold mb-3">{{ $title }}</h3>
                                    <p class="mb-2" style="color:#fca5a5;"><i class="ti ti-alert-triangle me-1"></i><strong>{{ __('Pain:') }}</strong> {{ $pain }}</p>
                                    <p class="mb-4" style="color:#cbd5e1;"><i class="ti ti-circle-check me-1" style="color:#34d399;"></i><strong style="color:#fff;">{{ __('Solution:') }}</strong> {{ $solution }}</p>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a href="{{ $lurl($link) }}" class="btn btn-light fw-semibold px-4">{{ $cta }} <span class="dir-arrow">&rarr;</span></a>
                                        <a href="{{ $lurl('/demos') }}#{{ $demoAnchor }}" class="btn btn-outline-light fw-semibold px-4"><i class="ti ti-player-play me-1"></i>{{ __('Try Demo') }}</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <button class="carousel-control-prev" type="button" data-bs-target="#industryCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon"></span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#industryCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon"></span>
            </button>
        </div>
    </div>
</section>

{{-- ═══════════ 6. PRODUCT DEMO TABS ═══════════ --}}
<section class="premium-section section-pad">
    <div class="container">
        <div class="text-center mb-4 reveal">
            <span class="badge mb-3 d-inline-block" style="background:rgba(200,155,60,.18);color:#e9c869;padding:.4rem 1rem;border-radius:8px;">{{ __('Live product') }}</span>
            <h2 class="fw-bold text-white">{{ __('Watch the workflow move across your business.') }}</h2>
            <p style="color:#94a3b8;">{{ __('Real screens from the live Bingoo POS platform.') }}</p>
        </div>

        @php $demos = [
            ['counter', __('Counter POS'), 'pos.png', __('Fast cart, barcode search, discounts, receipts, held sales, and payment flow.'), [__('Barcode & product search'), __('Held sales & multi-payment'), __('Receipt printing')]],
            ['kds', __('Kitchen Display'), 'kitchen_display.png', __('Orders move from counter to kitchen without paper confusion.'), [__('Live order tickets'), __('Prep / ready / served'), __('Station routing')]],
            ['inventory', __('Inventory Dashboard'), 'dashbaord.png', __('Track sales, stock, purchases, branches, and alerts in real time.'), [__('KPI dashboard'), __('Low-stock & expiry alerts'), __('Branch-level sales')]],
            ['tables', __('Restaurant Tables'), 'pos2.png', __('Open tables, assign waiters, and manage dine-in flow from a live board.'), [__('Live table board'), __('Dine-in / takeaway / delivery'), __('Split bills & service charge')]],
        ]; @endphp

        <div class="d-flex flex-wrap justify-content-center gap-2 mb-4 reveal">
            @foreach($demos as $i => [$key, $label, $img, $story, $bullets])
                <button class="demo-tab-btn {{ $i===0?'active':'' }}" data-demo-tab="{{ $key }}">{{ $label }}</button>
            @endforeach
        </div>

        @foreach($demos as $i => [$key, $label, $img, $story, $bullets])
            <div data-demo-panel="{{ $key }}" style="{{ $i===0?'':'display:none;' }}">
                <div class="row g-4 align-items-center">
                    <div class="col-lg-8 reveal">
                        <div class="device-frame glow-border">
                            <img src="{{ asset('images/data/' . $img) }}" alt="{{ $label }}">
                        </div>
                    </div>
                    <div class="col-lg-4 reveal">
                        <h4 class="fw-bold text-white mb-3">{{ $label }}</h4>
                        <p style="color:#94a3b8;">{{ $story }}</p>
                        <ul class="list-unstyled">
                            @foreach($bullets as $b)
                                <li class="mb-2" style="color:#cbd5e1;"><i class="ti ti-check me-2" style="color:#34d399;"></i>{{ $b }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>

{{-- ═══════════ 7. CORE FEATURES ═══════════ --}}
<section class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">{{ __('Features') }}</span>
            <h2 class="fw-bold">{{ __('Everything your business needs — without stitching tools together.') }}</h2>
        </div>
        @php $features = [
            ['ti-barcode', __('Barcode Checkout'), __('Fast sales, product search, held sales'), __('Multi-payment, receipts, returns')],
            ['ti-armchair', __('Restaurant Tables & KOT'), __('Floors, tables, waiters'), __('Split bills, service charge, kitchen tickets')],
            ['ti-device-desktop', __('Kitchen Display'), __('Live order routing to stations'), __('Prep / ready / served states')],
            ['ti-package', __('Inventory & Purchasing'), __('Stock balances, POs, GRNs'), __('Supplier payments, low-stock alerts')],
            ['ti-transfer', __('Stock Count & Transfers'), __('Physical stock counts'), __('Variance posting, inter-branch transfers')],
            ['ti-chart-bar', __('Reports & Controls'), __('Sales, shifts, inventory reports'), __('Restaurant, kitchen, purchase, audit')],
            ['ti-report-money', __('Finance & Accounting'), __('Chart of accounts, expenses, double-entry ledger'), __('P&L, Balance Sheet, branch-wise P&L')],
            ['ti-credit-card', __('SaaS Billing'), __('Plan invoices, payment proofs'), __('Upgrade requests, subscription lifecycle')],
            ['ti-users', __('Role-Based Access'), __('Owner, Manager, Cashier roles'), __('Custom permissions per user')],
        ]; @endphp
        <div class="row g-3">
            @foreach($features as [$ico, $title, $b1, $b2])
                <div class="col-md-6 col-lg-3">
                    <div class="gradient-card p-4 h-100 reveal">
                        <div class="icon-wrap"><i class="ti {{ $ico }}"></i></div>
                        <h6 class="fw-bold mb-2">{{ $title }}</h6>
                        <ul class="list-unstyled text-muted small mb-0">
                            <li class="mb-1"><i class="ti ti-check text-success me-1"></i>{{ $b1 }}</li>
                            <li><i class="ti ti-check text-success me-1"></i>{{ $b2 }}</li>
                        </ul>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════ 8. MASCOT / HELPER ═══════════ --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="mascot-card p-5 reveal">
            <div class="row align-items-center g-4">
                <div class="col-lg-4 text-center">
                    {{-- CSS/SVG sidekick --}}
                    <div style="position:relative;display:inline-block;">
                        <div style="width:160px;height:160px;border-radius:50%;background:linear-gradient(135deg,#0a1022,#16284a);display:flex;align-items:center;justify-content:center;box-shadow:0 20px 50px rgba(168,127,36,.35);border:2px solid rgba(200,155,60,.4);">
                            <img src="{{ asset(config('saas.brand_mark', 'images/brand/bingoo-pos-mark.svg')) }}" alt="{{ __('Bingoo Assistant') }}" style="width:84px;height:84px;filter:drop-shadow(0 4px 8px rgba(0,0,0,.2));">
                        </div>
                        <span class="speech-bubble float-card" style="position:absolute;top:-6px;inset-inline-end:-90px;">“{{ __('Add products fast') }}”</span>
                        <span class="speech-bubble float-card-delay" style="position:absolute;bottom:30px;inset-inline-start:-96px;">“{{ __('Send KOT instantly') }}”</span>
                    </div>
                </div>
                <div class="col-lg-8">
                    <span class="badge bg-warning bg-opacity-10 text-warning fw-semibold px-3 py-2 mb-3 d-inline-block">{{ __('Your POS sidekick') }}</span>
                    <h2 class="fw-bold mb-3">{{ __('Meet your POS sidekick.') }}</h2>
                    <p class="text-muted mb-4">
                        {{ __('Bingoo POS guides your team from first sale to daily closing — with workflows designed for cashiers, waiters, managers, and owners.') }}
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="speech-bubble"><i class="ti ti-bolt text-primary me-1"></i>{{ __('Add products fast') }}</span>
                        <span class="speech-bubble"><i class="ti ti-tools-kitchen-2 text-primary me-1"></i>{{ __('Send KOT instantly') }}</span>
                        <span class="speech-bubble"><i class="ti ti-package text-primary me-1"></i>{{ __('Know your stock before it runs out') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════ 9. FBR VISUAL (Pakistan site only) ═══════════ --}}
@if($pakistanSite)
<section class="section-pad bg-white">
    <div class="container">
        <div class="fbr-section p-5 reveal">
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <span class="badge mb-3 d-inline-block" style="background:rgba(52,211,153,.15);color:#34d399;border:1px solid rgba(52,211,153,.3);padding:.4rem 1rem;border-radius:8px;">
                        <i class="ti ti-flag me-1"></i>{{ __('Pakistan Compliance') }}
                    </span>
                    <h2 class="fw-bold text-white mb-3">{{ __('FBR-ready workflows for Pakistan retailers.') }}</h2>
                    <p style="color:#94a3b8;" class="mb-4">
                        {{ __('For eligible Pakistan businesses, Bingoo POS is being designed with FBR-ready invoice workflows, branch-level tax details, receipt configuration, and future integration support.') }}
                    </p>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>{{ __('Branch tax registration number fields') }}</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>{{ __('Taxable products and per-line tax amounts') }}</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>{{ __('Receipt tax number and footer configuration') }}</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>{{ __('Future invoice sync and QR workflow planning') }}</span></div>
                    <a href="{{ $lurl('/contact?topic=fbr') }}" class="btn btn-success btn-lg px-4 mt-2">{{ __('Ask about FBR setup') }} <span class="dir-arrow">&rarr;</span></a>
                    <p class="mt-3 mb-0" style="color:#fbbf24;font-size:.8rem;">
                        {{ __('Not an official FBR certification claim. Final compliance depends on business setup and official FBR requirements.') }}
                    </p>
                </div>
                <div class="col-lg-5">
                    {{-- Receipt + QR mock --}}
                    <div class="receipt-mock mx-auto" style="max-width:280px;">
                        <div class="text-center fw-bold" style="font-size:1rem;">BINGOO POS</div>
                        <div class="text-center" style="font-size:.7rem;color:#64748b;">{{ __('Tax Invoice') }}</div>
                        <hr>
                        <div class="r-row"><span>NTN</span><span>XXXXXXX-X</span></div>
                        <div class="r-row"><span>STRN</span><span>XX-XX-XXXX</span></div>
                        <div class="r-row"><span>{{ __('Invoice #') }}</span><span>INV-0001</span></div>
                        <hr>
                        <div class="r-row"><span>{{ __('Item subtotal') }}</span><span>2,000</span></div>
                        <div class="r-row"><span>{{ __('Sales tax') }}</span><span>340</span></div>
                        <div class="r-row fw-bold"><span>{{ __('Total') }}</span><span>2,340</span></div>
                        <hr>
                        <div class="r-row" style="color:#16a34a;"><span>{{ __('FBR sync') }}</span><span><i class="ti ti-clock"></i> {{ __('Planned') }}</span></div>
                        <div class="d-flex justify-content-center mt-3">
                            <div class="qr-mock"></div>
                        </div>
                        <div class="text-center mt-2" style="font-size:.65rem;color:#94a3b8;">{{ __('QR placeholder — illustrative only') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endif

{{-- ═══════════ 10. STATS ═══════════ --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <h2 class="fw-bold">{{ __('Platform capability at a glance') }}</h2>
            <p class="text-muted">{{ __('Designed to support thousands of daily transactions across counters, kitchens, and branches.') }}</p>
        </div>
        <div class="row g-4 justify-content-center">
            @php $stats = [['14','+', __('Business modules')],['30', ' ' . __('days'), __('Free trial period')],['5','', __('Public plan options')],['8','+', __('Industry use cases')]]; @endphp
            @foreach($stats as [$n, $sfx, $label])
                <div class="col-6 col-md-3">
                    <div class="stat-card reveal hover-lift">
                        <div class="stat-number" data-count="{{ $n }}" data-suffix="{{ $sfx }}">{{ $n }}{{ $sfx }}</div>
                        <div class="stat-label">{{ $label }}</div>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="text-center text-muted small mt-4">{{ __('* Capability-based figures. Actual customer data will be shown here as the platform grows.') }}</p>
    </div>
</section>

{{-- ═══════════ 11. TESTIMONIALS CAROUSEL ═══════════ --}}
<section class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">{{ __('Stories') }}</span>
            <h2 class="fw-bold">{{ __('Customer stories by business type') }}</h2>
            <p class="text-muted small">{{ __('Sample customer scenarios — replace with real testimonials when available.') }}</p>
        </div>

        @php $tcards = [
            ['RM', __('Restaurant Manager'), __('UAE'), __('Restaurant'), '#a87f24', __('We can manage tables, kitchen tickets, and daily closing from one place. The kitchen display keeps the team in sync.')],
            ['RO', __('Retail Owner'), __('Pakistan'), __('Supermarket'), '#7c3aed', __('Barcode checkout, stock visibility, and branch-level controls make daily work so much easier than before.')],
            ['CO', __('Café Operator'), __('Saudi Arabia'), __('Café'), '#059669', __('Our team takes orders fast and the kitchen gets the ticket instantly. Fewer errors, faster service.')],
            ['IS', __('Inventory Supervisor'), __('UK'), __('Warehouse'), '#d97706', __('Purchasing, stock counts, and supplier reports help us see exactly what is happening across the warehouse.')],
            ['MB', __('Multi-Branch Operator'), __('Qatar'), __('Enterprise'), '#db2777', __('Centralized branches, terminals, users, and reports — we finally see every location in one dashboard.')],
        ]; @endphp

        <div id="testimonialCarousel" class="carousel slide reveal" data-bs-ride="carousel" data-bs-interval="4500">
            <div class="carousel-inner">
                @foreach($tcards as $i => [$initials, $role, $country, $type, $color, $quote])
                    <div class="carousel-item {{ $i===0?'active':'' }}">
                        <div class="row justify-content-center">
                            <div class="col-lg-8">
                                <div class="testimonial-card text-center p-5">
                                    <div class="star-rating mb-3">
                                        @for($s=0;$s<5;$s++)<i class="ti ti-star-filled"></i>@endfor
                                    </div>
                                    <p class="fs-5 text-dark mb-4" style="line-height:1.6;">&ldquo;{{ $quote }}&rdquo;</p>
                                    <div class="d-flex align-items-center justify-content-center gap-2">
                                        <div class="avatar-circle" style="background:linear-gradient(135deg,{{ $color }},{{ $color }}aa);">{{ app()->getLocale() === 'en' ? $initials : mb_substr($role, 0, 1) }}</div>
                                        <div class="text-start">
                                            <div class="fw-semibold">{{ $role }}</div>
                                            <div class="small text-muted">{{ $type }} · <span class="country-badge">{{ $country }}</span></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="carousel-indicators position-static mt-3">
                @foreach($tcards as $i => $t)
                    <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="{{ $i }}" class="{{ $i===0?'active':'' }}" style="background-color:#caa23f;"></button>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ═══════════ 12. COMPARISON ═══════════ --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">{{ __('Comparison') }}</span>
            <h2 class="fw-bold">{{ __('Why businesses move to Bingoo POS') }}</h2>
        </div>
        <div class="reveal table-responsive">
            <table class="table comparison-tbl border rounded-3 overflow-hidden bg-white">
                <thead>
                    <tr>
                        <th style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">{{ __('Workflow') }}</th>
                        <th class="text-center" style="background:#fef2f2;color:#991b1b;border-bottom:2px solid #fecaca;">{{ __('Traditional POS') }}</th>
                        <th class="text-center" style="background:#fff7ed;color:#92400e;border-bottom:2px solid #fed7aa;">{{ __('Disconnected Tools') }}</th>
                        <th class="text-center col-brand" style="border-bottom:2px solid #ead9a3;"><span style="color:#9a7320;font-weight:800;">Bingoo POS</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach([
                        [__('Checkout & sales'), __('Basic'), __('Manual entry'), __('Barcode, cart, multi-payment')],
                        [__('Inventory control'), __('Offline'), __('Spreadsheet'), __('Live stock, transfers, counts')],
                        [__('Restaurant kitchen'), __('No KOT'), __('Paper tickets'), __('Digital KOT + Kitchen Display')],
                        [__('Reporting'), __('Day-end only'), __('Multiple apps'), __('Real-time, branch-level')],
                        [__('Multi-branch'), __('Single store'), __('Complex setup'), __('Built-in, per-branch')],
                        [__('Subscription control'), __('N/A'), __('N/A'), __('Plans, limits, upgrades')],
                    ] as [$row, $c1, $c2, $c3])
                        <tr>
                            <td class="fw-semibold">{{ $row }}</td>
                            <td class="text-center"><i class="ti ti-x check-no"></i> <span class="text-muted small">{{ $c1 }}</span></td>
                            <td class="text-center"><i class="ti ti-x check-no"></i> <span class="text-muted small">{{ $c2 }}</span></td>
                            <td class="text-center col-brand"><i class="ti ti-check check-yes"></i> <span class="small text-dark">✓ {{ $c3 }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

{{-- ═══════════ 13. PRICING PREVIEW ═══════════ --}}
<section class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">{{ __('Pricing') }}</span>
            <h2 class="fw-bold">{{ __('Pick the package that fits your business') }}</h2>
            <p class="text-muted">{{ __('Start with a 30-day free trial. Upgrade any time. No payment required.') }}</p>
        </div>
        @php $planBadges = [
            'retail_starter'    => ['plan-badge-retail', __('Best for small shops')],
            'inventory_store'   => ['plan-badge-inv', __('Best for marts')],
            'restaurant_starter'=> ['plan-badge-resto', __('Best for cafés')],
            'restaurant_pro'    => ['plan-badge-popular', '⭐ ' . __('Most Popular')],
        ]; @endphp
        <div class="row g-4 justify-content-center">
            @foreach($selfServicePlans as $plan)
                @php $badge = $planBadges[$plan->code] ?? ['plan-badge-retail','']; $popular = $plan->code==='restaurant_pro'; @endphp
                <div class="col-md-6 col-lg-3">
                    <div class="plan-card p-4 h-100 d-flex flex-column hover-lift reveal {{ $popular?'plan-card-popular':'' }}">
                        @if($badge[1])<span class="plan-badge {{ $badge[0] }} mb-2 align-self-start">{{ $badge[1] }}</span>@endif
                        <h5 class="fw-bold mb-1">{{ __($plan->name) }}</h5>
                        <p class="text-muted small flex-grow-1">{{ __((string) $plan->public_description) }}</p>
                        <div class="plan-price"><bdi>{{ $plan->currency_code }} {{ number_format((float)($plan->monthly_price ?? $plan->price), 0) }}</bdi></div>
                        <div class="text-muted small mb-1">{{ __('per month') }}</div>
                        @if($plan->yearly_price)<div class="text-muted small mb-2">{{ __('or :amount / year', ['amount' => $plan->currency_code . ' ' . number_format((float)$plan->yearly_price, 0)]) }}</div>@endif
                        @if($plan->trial_days)<div class="text-success small mb-3"><i class="ti ti-check me-1"></i>{{ __(':days-day free trial', ['days' => $plan->trial_days]) }}</div>@endif
                        <a href="{{ $lurl('/start-trial?plan=' . $plan->code) }}" class="btn {{ $popular?'btn-primary':'btn-outline-primary' }} mt-auto">{{ __('Start Trial') }}</a>
                    </div>
                </div>
            @endforeach
            @foreach($customPlans as $plan)
                <div class="col-md-6 col-lg-3">
                    <div class="plan-card p-4 h-100 d-flex flex-column hover-lift reveal" style="border-color:#caa23f;">
                        <span class="plan-badge plan-badge-inv mb-2 align-self-start">{{ __('Custom rollout') }}</span>
                        <h5 class="fw-bold mb-1">{{ __($plan->name) }}</h5>
                        <p class="text-muted small flex-grow-1">{{ __((string) $plan->public_description) }}</p>
                        <div class="fw-semibold fs-5 mb-1">{{ __('Custom pricing') }}</div>
                        <div class="text-muted small mb-3">{{ __('Tailored to your scale') }}</div>
                        <a href="{{ $lurl('/contact?plan=' . $plan->code) }}" class="btn btn-outline-primary mt-auto">{{ __('Contact Sales') }}</a>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="text-center mt-4">
            <a href="{{ $lurl('/pricing') }}" class="btn btn-link fw-semibold">{{ __('Compare full plan details & modules') }} <span class="dir-arrow">&rarr;</span></a>
        </div>
    </div>
</section>

{{-- ═══════════ 14. FAQ ═══════════ --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-5 reveal">
                    <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">{{ __('FAQ') }}</span>
                    <h2 class="fw-bold">{{ __('Common questions') }}</h2>
                </div>
                <div class="accordion reveal" id="faqAccordion">
                    @php $faqs = array_values(array_filter([
                        [__('Do I need to install anything?'), __('No. Bingoo POS is a cloud-based SaaS. You access it from any browser on any device — no software download or server required.')],
                        [__('Is payment required for the trial?'), __('No. The 30-day free trial requires no payment details. Sign up, provision your workspace, and start immediately.')],
                        [__('Can I use Bingoo POS for restaurants?'), __('Yes. The Restaurant Starter and Restaurant Pro plans include table management, KOT printing, kitchen display, split bills, and service charges.')],
                        [__('Can I manage inventory and purchasing?'), __('Yes. The Inventory Store and Restaurant Pro plans include stock balances, purchase orders, GRNs, supplier management, stock counts, and transfers.')],
                        [__('Does Bingoo POS include accounting?'), __('Yes. The Restaurant Pro and Enterprise plans include a built-in Finance & Accounting module — chart of accounts, expenses, cash & bank accounts, customer/supplier payments, a double-entry general ledger that auto-posts from sales, purchases and expenses, plus Trial Balance, Profit & Loss, Branch-wise P&L and Balance Sheet reports with CSV export.')],
                        [__('Can I upgrade my plan later?'), __('Yes. Request a plan upgrade any time from the billing portal. Our team approves it and issues an upgrade invoice.')],
                        [__('Does Bingoo POS support multiple branches?'), __('Yes. The Restaurant Pro and Enterprise plans include multi-branch controls with per-branch terminals, users, shifts, reports, and stock.')],
                        $pakistanSite ? [__('Is FBR integration available for Pakistan?'), __('Bingoo POS has FBR-ready workflows designed for eligible Pakistan businesses, including branch tax fields, taxable products, and receipt tax numbers. Full FBR invoice sync is planned. This is not an official FBR-certified integration.')] : null,
                        [__('Can I use barcode scanners and receipt printers?'), __('Yes. Bingoo POS supports USB and wireless barcode scanners, thermal receipt printers, and KOT kitchen printers via the local print agent.')],
                    ])); @endphp
                    @foreach($faqs as $i => [$q, $a])
                        <div class="accordion-item border-0 mb-2 rounded-3 overflow-hidden shadow-sm">
                            <h2 class="accordion-header">
                                <button class="accordion-button {{ $i>0?'collapsed':'' }} rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq{{ $i }}">{{ $q }}</button>
                            </h2>
                            <div id="faq{{ $i }}" class="accordion-collapse collapse {{ $i===0?'show':'' }}" data-bs-parent="#faqAccordion">
                                <div class="accordion-body text-muted">{{ $a }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════ 15. FINAL CTA ═══════════ --}}
<section class="public-hero-premium section-pad" style="position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-100px;inset-inline-end:10%;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <div class="reveal" style="max-width:720px;margin:0 auto;">
            <h2 class="fw-bold text-white mb-3" style="font-size:2.3rem;">
                {{ __('Your next counter, kitchen, and inventory system starts here.') }}
            </h2>
            <p class="mb-4" style="color:#cbd5e1;font-size:1.1rem;">
                {{ __('Create a 30-day trial workspace, invite your team, test products, print orders, and see your reports — no payment required.') }}
            </p>
            <div class="d-flex flex-wrap justify-content-center gap-3 mb-4">
                <a href="{{ $lurl('/start-trial') }}" class="btn btn-light btn-lg px-5 fw-semibold" style="box-shadow:0 4px 20px rgba(255,255,255,.25);">
                    <i class="ti ti-rocket me-2"></i>{{ __('Start 30-Day Free Trial') }}
                </a>
                <a href="{{ $lurl('/demos') }}" class="btn btn-outline-light btn-lg px-5"><i class="ti ti-player-play me-2"></i>{{ __('Try Live Demo') }}</a>
                <a href="{{ $lurl('/contact') }}" class="btn btn-outline-light btn-lg px-5"><i class="ti ti-calendar me-2"></i>{{ __('Book a Demo') }}</a>
                <a href="{{ $lurl('/pricing') }}" class="btn btn-outline-light btn-lg px-5">{{ __('Compare Packages') }}</a>
            </div>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                @foreach([__('No payment required'), __('Cloud setup'), __('Restaurant + retail ready'), __('Upgrade anytime')] as $chip)
                    <span class="hero-badge"><i class="ti ti-check"></i> {{ $chip }}</span>
                @endforeach
            </div>
        </div>
    </div>
</section>

@endsection
