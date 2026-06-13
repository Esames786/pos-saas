@extends('layouts.public')

@section('title', 'Cloud POS for Retail & Restaurants')

@section('content')
<section class="public-hero section-pad">
    <div class="container">
        <div class="row align-items-center gy-4">
            <div class="col-lg-7">
                <h1 class="display-5 fw-bold mb-3">
                    Run your retail, restaurant, and inventory operations from one cloud POS.
                </h1>
                <p class="lead mb-4" style="color:#cbd5e1;">
                    Sell faster, manage stock, print KOTs, track kitchens, receive purchases, and control
                    multi-branch operations from one SaaS platform.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ url('/start-trial') }}" class="btn btn-light btn-lg px-4">Start Free Trial</a>
                    <a href="{{ url('/pricing') }}" class="btn btn-outline-light btn-lg px-4">View Pricing</a>
                    <a href="{{ url('/contact') }}" class="btn btn-outline-light btn-lg px-4">Contact Sales</a>
                </div>
            </div>
            <div class="col-lg-5 text-center d-none d-lg-block">
                <i class="ti ti-building-store" style="font-size:11rem;color:#1d4ed8;"></i>
            </div>
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
                    <div class="feature-tile p-4 h-100">
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
                    <div class="plan-card p-4 h-100 d-flex flex-column">
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
