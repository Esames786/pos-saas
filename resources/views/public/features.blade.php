@extends('layouts.public')

@section('title', 'Features')
@section('meta_description', 'Explore cloud POS features for barcode checkout, restaurant tables, KOT, kitchen display, inventory, purchasing, reports, and subscription control.')

@section('content')
<section class="public-hero-premium section-pad">
    <div class="container text-center">
        <span class="hero-badge mb-3"><i class="ti ti-stars"></i> Everything in one platform</span>
        <h1 class="fw-bold mb-2">Built for the whole operation</h1>
        <p class="lead mb-0" style="color:#cbd5e1;">From the front counter to the kitchen to the back office.</p>
    </div>
</section>

{{-- Image + text feature spotlights (real stock POS photos) --}}
<section class="section-pad bg-white">
    <div class="container">
        {{-- Spotlight 1: Retail / barcode --}}
        <div class="row align-items-center g-5 mb-5 reveal">
            <div class="col-lg-6">
                <div class="image-card shadow-sm hover-lift">
                    <img src="{{ asset('images/data/point-of-sale-software.webp') }}"
                         alt="Retail POS terminal with barcode scanner and card reader"
                         style="width:100%;height:340px;object-fit:cover;display:block;">
                </div>
            </div>
            <div class="col-lg-6">
                <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">Counter</span>
                <h2 class="fw-bold mb-3">Fast, accurate checkout at every counter</h2>
                <p class="text-muted mb-4">Scan barcodes, hold and recall sales, take multiple payment methods, apply promotions, and print receipts — built for speed when the queue is long.</p>
                <ul class="list-unstyled text-muted">
                    <li class="mb-2"><i class="ti ti-check text-success me-2"></i>USB &amp; wireless barcode scanner support</li>
                    <li class="mb-2"><i class="ti ti-check text-success me-2"></i>Multi-payment, split, and change handling</li>
                    <li class="mb-2"><i class="ti ti-check text-success me-2"></i>Thermal receipt printing via local print agent</li>
                </ul>
            </div>
        </div>

        {{-- Spotlight 2: Restaurant (image right) --}}
        <div class="row align-items-center g-5 mb-5 reveal flex-lg-row-reverse">
            <div class="col-lg-6">
                <div class="image-card shadow-sm hover-lift">
                    <img src="{{ asset('images/data/jpg100-t3-scale100.jpg') }}"
                         alt="Restaurant POS terminal with cash drawer in dining room"
                         style="width:100%;height:340px;object-fit:cover;display:block;">
                </div>
            </div>
            <div class="col-lg-6">
                <span class="badge bg-warning bg-opacity-10 text-warning fw-semibold px-3 py-2 mb-3 d-inline-block">Restaurant</span>
                <h2 class="fw-bold mb-3">Run dine-in, takeaway, and the kitchen together</h2>
                <p class="text-muted mb-4">Open tables, assign waiters, split bills, add service charges, and fire orders straight to the kitchen display and KOT printers.</p>
                <ul class="list-unstyled text-muted">
                    <li class="mb-2"><i class="ti ti-check text-success me-2"></i>Floors, tables, and live table board</li>
                    <li class="mb-2"><i class="ti ti-check text-success me-2"></i>KOT routing by category &amp; station</li>
                    <li class="mb-2"><i class="ti ti-check text-success me-2"></i>Kitchen Display with prep / ready / served</li>
                </ul>
            </div>
        </div>

        {{-- Spotlight 3: Counter ops / shifts --}}
        <div class="row align-items-center g-5 reveal">
            <div class="col-lg-6">
                <div class="image-card shadow-sm hover-lift">
                    <img src="{{ asset('images/data/pos1.jpg') }}"
                         alt="Retail counter with POS, scanner, and receipt printer"
                         style="width:100%;height:340px;object-fit:cover;object-position:center top;display:block;">
                </div>
            </div>
            <div class="col-lg-6">
                <span class="badge bg-success bg-opacity-10 text-success fw-semibold px-3 py-2 mb-3 d-inline-block">Operations</span>
                <h2 class="fw-bold mb-3">Control shifts, stock, and reports across branches</h2>
                <p class="text-muted mb-4">Open and close shifts with cash reconciliation, track stock movement, and see real-time sales and inventory across every branch and terminal.</p>
                <ul class="list-unstyled text-muted">
                    <li class="mb-2"><i class="ti ti-check text-success me-2"></i>Shift open/close &amp; daily closing</li>
                    <li class="mb-2"><i class="ti ti-check text-success me-2"></i>Per-branch terminals, users, and roles</li>
                    <li class="mb-2"><i class="ti ti-check text-success me-2"></i>Real-time sales, stock &amp; audit reporting</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <h2 class="fw-bold">Every module, in one platform</h2>
            <p class="text-muted">No stitching separate tools together.</p>
        </div>
        @php
            $groups = [
                ['Retail & Barcode Checkout', 'ti-barcode', ['Quick-sale cart & barcode scanning', 'Held sales & multi-payment checkout', 'Sales returns & customer ledger', 'Promotions & price controls']],
                ['Restaurant Tables & KOT Printing', 'ti-tools-kitchen-2', ['Floors, tables & waiters', 'Split bills & service charges', 'KOT routing by category', 'Dine-in, takeaway & delivery flows']],
                ['Kitchen Display System', 'ti-device-desktop', ['Live order tickets to kitchen stations', 'Prep / ready / served states', 'Station-based routing', 'Faster turnaround & fewer misfires']],
                ['Inventory, Purchasing & Suppliers', 'ti-package', ['Stock balances & valuation', 'Suppliers & supplier payments', 'Purchase orders, GRNs & bills', 'Low-stock & expiry alerts']],
                ['Stock Count & Transfers', 'ti-transfer', ['Physical stock counts', 'Variance posting to ledger', 'Inter-branch stock transfers', 'Movement history & audit trail']],
                ['Recipes & Kitchen Inventory', 'ti-chef-hat', ['Recipes / bill of materials', 'Kitchen productions', 'Wastage tracking', 'Ingredient-level consumption']],
                ['Reports & Manager Controls', 'ti-chart-bar', ['Sales, shifts & daily closings', 'Inventory & purchase reporting', 'Restaurant & kitchen reports', 'Manager approvals & audit logs']],
                ['SaaS Billing & Subscription Control', 'ti-credit-card', ['Plans, modules & usage limits', 'Invoices & payment proofs', 'Plan upgrade requests', 'Trial & subscription lifecycle']],
            ];
        @endphp
        <div class="row g-4">
            @foreach($groups as [$title, $icon, $items])
                <div class="col-md-6">
                    <div class="gradient-card p-4 h-100 reveal">
                        <div class="d-flex align-items-center mb-3">
                            <div class="icon-wrap me-3 mb-0">
                                <i class="ti {{ $icon }}"></i>
                            </div>
                            <h5 class="fw-semibold mb-0">{{ $title }}</h5>
                        </div>
                        <ul class="mb-0 text-muted">
                            @foreach($items as $item)
                                <li class="mb-1">{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="text-center mt-5">
            <a href="{{ url('/start-trial') }}" class="btn btn-primary btn-lg px-4 me-2">Start Free Trial</a>
            <a href="{{ url('/pricing') }}" class="btn btn-outline-primary btn-lg px-4">View Pricing</a>
        </div>
    </div>
</section>
@endsection
