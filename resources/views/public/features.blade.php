@extends('layouts.public')

@section('title', 'Features')

@section('content')
<section class="public-hero section-pad">
    <div class="container text-center">
        <h1 class="fw-bold mb-2">Built for the whole operation</h1>
        <p class="lead mb-0" style="color:#cbd5e1;">From the front counter to the kitchen to the back office.</p>
    </div>
</section>

<section class="section-pad">
    <div class="container">
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
                    <div class="feature-tile p-4 h-100">
                        <div class="d-flex align-items-center mb-3">
                            <i class="ti {{ $icon }} me-2" style="font-size:1.8rem;color:#1d4ed8;"></i>
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
