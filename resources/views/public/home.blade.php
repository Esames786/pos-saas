@extends('layouts.public')

@section('title', 'Cloud POS for Retail & Restaurants')

@section('content')
<section class="public-hero section-pad">
    <div class="container">
        <div class="row align-items-center gy-4">
            <div class="col-lg-7">
                <h1 class="display-5 fw-bold mb-3">
                    Run your retail, restaurant, and inventory operations from one SaaS POS.
                </h1>
                <p class="lead mb-4" style="color:#cbd5e1;">
                    POS, inventory, purchasing, restaurant tables, kitchen display, printing, reports, and billing —
                    ready for growing Pakistani businesses.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ url('/start-trial') }}" class="btn btn-light btn-lg px-4">Start Free Trial</a>
                    <a href="{{ url('/pricing') }}" class="btn btn-outline-light btn-lg px-4">View Pricing</a>
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
            <h2 class="fw-bold">Everything your storefront needs</h2>
            <p class="text-muted">One platform across the counter, the kitchen, and the back office.</p>
        </div>
        <div class="row g-4">
            @php
                $tiles = [
                    ['ti-cash-register', 'Retail POS', 'Fast cart, barcode scan, held sales, multi-payment checkout, and returns.'],
                    ['ti-tools-kitchen-2', 'Restaurant POS', 'Floors, tables, waiters, split bills, and service charges.'],
                    ['ti-package', 'Inventory & Purchasing', 'Stock balances, transfers, counts, suppliers, POs, GRNs, and bills.'],
                    ['ti-printer', 'Kitchen & Printing', 'Kitchen display, KOT routing, receipt layouts, and print agents.'],
                    ['ti-chart-bar', 'Reports & Controls', 'Sales, shifts, inventory, restaurant, kitchen, and audit reporting.'],
                    ['ti-credit-card', 'SaaS Billing', 'Plans, usage limits, invoices, payment proofs, and plan upgrades.'],
                ];
            @endphp
            @foreach($tiles as [$icon, $title, $desc])
                <div class="col-md-6 col-lg-4">
                    <div class="feature-tile p-4">
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
    <div class="container text-center">
        <h2 class="fw-bold mb-3">Start your free trial today</h2>
        <p class="text-muted mb-4">No credit card required. Pick a plan and you're live in minutes.</p>
        <a href="{{ url('/start-trial') }}" class="btn btn-primary btn-lg px-4">Start Free Trial</a>
    </div>
</section>
@endsection
