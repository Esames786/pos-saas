@extends('layouts.public')

@section('title', 'Features')

@section('content')
<section class="public-hero section-pad">
    <div class="container text-center">
        <h1 class="fw-bold mb-2">Built for the whole operation</h1>
        <p class="lead mb-0" style="color:#cbd5e1;">From the front counter to the back office.</p>
    </div>
</section>

<section class="section-pad">
    <div class="container">
        @php
            $groups = [
                ['POS & Sales', 'ti-cash-register', ['Quick-sale cart & barcode scanning', 'Held sales & multi-payment checkout', 'Sales returns & ledger', 'Customers & payment methods']],
                ['Inventory & Purchasing', 'ti-package', ['Stock balances, transfers & counts', 'Suppliers & supplier payments', 'Purchase orders, GRNs & bills', 'Low-stock & expiry alerts']],
                ['Restaurant & Kitchen', 'ti-tools-kitchen-2', ['Floors, tables & waiters', 'Split bills & service charges', 'Kitchen display system (KDS)', 'Recipes, productions & wastage']],
                ['Printing', 'ti-printer', ['KOT routing by category', 'Receipt layout settings', 'Print job queue & retries', 'LAN print agents']],
                ['Reports', 'ti-chart-bar', ['Sales, shifts & daily closings', 'Inventory valuation & movements', 'Restaurant & kitchen reports', 'Audit & manager approvals']],
                ['SaaS Billing / Plan Controls', 'ti-credit-card', ['Plans, modules & usage limits', 'Invoices & payment proofs', 'Plan upgrade requests', 'Trial & subscription lifecycle']],
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

        @if($plans->count())
            <div class="mt-5">
                <h4 class="fw-bold mb-3">Modules by plan</h4>
                <div class="table-responsive bg-white rounded border">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Plan</th>
                                <th>Included modules</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($plans as $plan)
                                <tr>
                                    <td class="fw-semibold">{{ $plan->name }}</td>
                                    <td>
                                        @foreach($plan->enabledModules as $module)
                                            <span class="badge bg-light text-dark border me-1 mb-1">{{ $module->name }}</span>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="text-center mt-5">
            <a href="{{ url('/start-trial') }}" class="btn btn-primary btn-lg px-4">Start Free Trial</a>
        </div>
    </div>
</section>
@endsection
