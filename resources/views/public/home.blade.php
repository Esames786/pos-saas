@extends('layouts.public')

@section('title', 'Cloud POS for Retail, Restaurants & Inventory Teams')
@section('meta_description', 'Launch a cloud POS for retail checkout, restaurant KOT, inventory, purchasing, reports, and multi-branch operations in minutes.')

@section('content')
<section class="public-hero-premium section-pad">
    <div class="container">
        <div class="row align-items-center gy-5">
            <div class="col-lg-6">
                <span class="hero-badge mb-3">
                    <i class="ti ti-bolt"></i> Your dream POS is only 3 clicks away
                </span>
                <h1 class="display-5 fw-bold mb-3">
                    Your retail and restaurant POS, ready in minutes.
                </h1>
                <p class="lead mb-4" style="color:#cbd5e1;">
                    Launch a cloud POS workspace for sales, inventory, restaurant tables, KOT, kitchen display,
                    purchasing, reports, and multi-branch control — without waiting weeks for setup.
                </p>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <a href="{{ url('/start-trial') }}" class="btn btn-light btn-lg px-4">Start 30-Day Free Trial</a>
                    <a href="{{ url('/pricing') }}" class="btn btn-outline-light btn-lg px-4">View Packages</a>
                    <a href="{{ url('/contact') }}" class="btn btn-outline-light btn-lg px-4">Book a Demo</a>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @foreach(['Retail checkout', 'Restaurant KOT', 'Inventory control', 'Multi-branch ready', 'FBR-ready for Pakistan'] as $chip)
                        <span class="hero-badge"><i class="ti ti-check"></i> {{ $chip }}</span>
                    @endforeach
                </div>
            </div>
            <div class="col-lg-6">
                <div class="hero-glow-card p-3 p-md-4 reveal">
                    <img src="{{ asset('images/data/Banner-Full.webp') }}"
                         alt="Habibi POS cloud POS on laptop, tablet, and mobile"
                         class="img-fluid rounded-4">
                </div>
            </div>
        </div>

        <div class="row g-4 mt-2">
            @php
                $steps = [
                    ['1', 'Choose your business type', 'Retail, restaurant, café, bakery, inventory, or multi-branch.'],
                    ['2', 'Create your cloud POS', 'Your workspace and login are provisioned automatically.'],
                    ['3', 'Start selling with your team', 'Add staff, products, and go live at the counter.'],
                ];
            @endphp
            @foreach($steps as [$n, $title, $desc])
                <div class="col-md-4">
                    <div class="hero-glow-card p-4 h-100 reveal">
                        <div class="fw-bold fs-3 mb-2" style="color:#93c5fd;">{{ $n }}</div>
                        <h5 class="fw-semibold">{{ $title }}</h5>
                        <p class="mb-0" style="color:#cbd5e1;">{{ $desc }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="section-pad">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold">One platform across the counter, the kitchen, and the back office</h2>
            <p class="text-muted">Built for growing businesses across retail, food service, and distribution.</p>
        </div>
        <div class="row g-4">
            @php
                $tiles = [
                    ['ti-barcode', 'Retail & Barcode Checkout', 'Fast cart, barcode scanning, held sales, multi-payment checkout, and returns.'],
                    ['ti-tools-kitchen-2', 'Restaurant Tables & KOT', 'Floors, tables, waiters, split bills, service charges, and kitchen tickets.'],
                    ['ti-device-desktop', 'Kitchen Display System', 'Live order routing to kitchen stations with prep and ready states.'],
                    ['ti-package', 'Inventory & Purchasing', 'Stock balances, suppliers, purchase orders, GRNs, and bills.'],
                    ['ti-transfer', 'Stock Count & Transfers', 'Physical counts, variance posting, and inter-branch transfers.'],
                    ['ti-chart-bar', 'Reports & Controls', 'Sales, shifts, inventory, restaurant, kitchen, and audit reporting.'],
                    ['ti-credit-card', 'SaaS Billing & Plan Control', 'Plans, usage limits, invoices, payment proofs, and plan upgrades.'],
                ];
            @endphp
            @foreach($tiles as [$icon, $title, $desc])
                <div class="col-md-6 col-lg-4">
                    <div class="feature-tile p-4 h-100 hover-lift reveal">
                        <i class="ti {{ $icon }} mb-3" style="font-size:2.2rem;color:#1d4ed8;"></i>
                        <h5 class="fw-semibold">{{ $title }}</h5>
                        <p class="text-muted mb-0">{{ $desc }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold">Pick the package that fits your business</h2>
            <p class="text-muted">Start with a free trial. Upgrade any time as you grow.</p>
        </div>
        <div class="row g-4 justify-content-center">
            @foreach($selfServicePlans as $plan)
                <div class="col-md-6 col-lg-3">
                    <div class="plan-card p-4 h-100 d-flex flex-column hover-lift reveal">
                        <h5 class="fw-bold mb-1">{{ $plan->name }}</h5>
                        <p class="text-muted small flex-grow-1">{{ $plan->public_description }}</p>
                        <div class="plan-price">{{ $plan->currency_code }} {{ number_format((float) ($plan->monthly_price ?? $plan->price), 0) }}</div>
                        <div class="text-muted small mb-3">per month</div>
                        <a href="{{ url('/start-trial?plan=' . $plan->code) }}" class="btn btn-primary mt-auto">Start Trial</a>
                    </div>
                </div>
            @endforeach

            @foreach($customPlans as $plan)
                <div class="col-md-6 col-lg-3">
                    <div class="plan-card p-4 h-100 d-flex flex-column" style="border-color:#1d4ed8;">
                        <h5 class="fw-bold mb-1">{{ $plan->name }}</h5>
                        <p class="text-muted small flex-grow-1">{{ $plan->public_description }}</p>
                        <div class="fw-semibold mb-3">Custom pricing</div>
                        <a href="{{ url('/contact?plan=' . $plan->code) }}" class="btn btn-outline-primary mt-auto">Contact Sales</a>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="text-center mt-4">
            <a href="{{ url('/pricing') }}" class="btn btn-link">See full pricing &amp; comparison &rarr;</a>
        </div>
    </div>
</section>
@endsection
