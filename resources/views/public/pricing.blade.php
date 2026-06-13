@extends('layouts.public')

@section('title', 'Pricing')

@section('content')
@php
    $feature = fn($plan, $key) => optional($plan->features->firstWhere('feature_key', $key))->feature_value;
    $limitLabel = fn($v) => ($v === null || $v === '') ? 'Unlimited' : $v;
    $hasModule = fn($plan, $key) => $plan->enabledModules->pluck('key')->contains($key);
@endphp

<section class="public-hero section-pad">
    <div class="container text-center">
        <h1 class="fw-bold mb-2">Simple, transparent pricing</h1>
        <p class="lead mb-0" style="color:#cbd5e1;">Start with a free trial. Upgrade any time.</p>
    </div>
</section>

<section class="section-pad">
    <div class="container">
        <div class="row g-4 justify-content-center">
            @foreach($selfServicePlans as $plan)
                <div class="col-md-6 col-lg-3">
                    <div class="plan-card bg-white p-4 h-100 d-flex flex-column">
                        <h4 class="fw-bold mb-1">{{ $plan->name }}</h4>
                        <p class="text-muted small flex-grow-1">{{ $plan->public_description }}</p>

                        <div class="plan-price">{{ $plan->currency_code }} {{ number_format((float) ($plan->monthly_price ?? $plan->price), 0) }}</div>
                        <div class="text-muted mb-1">per month</div>
                        @if($plan->yearly_price)
                            <div class="text-muted small mb-2">or {{ $plan->currency_code }} {{ number_format((float) $plan->yearly_price, 0) }} / year</div>
                        @endif

                        @if($plan->trial_days)
                            <span class="badge bg-success-subtle text-success align-self-start mb-3">
                                {{ $plan->trial_days }}-day free trial
                            </span>
                        @endif

                        <ul class="list-unstyled small mb-3">
                            <li class="mb-1"><i class="ti ti-building-store me-2 text-primary"></i>Branches: <strong>{{ $limitLabel($feature($plan, 'branch_limit')) }}</strong></li>
                            <li class="mb-1"><i class="ti ti-device-desktop me-2 text-primary"></i>Terminals: <strong>{{ $limitLabel($feature($plan, 'terminal_limit')) }}</strong></li>
                            <li class="mb-1"><i class="ti ti-users me-2 text-primary"></i>Users: <strong>{{ $limitLabel($feature($plan, 'user_limit')) }}</strong></li>
                            <li class="mb-1"><i class="ti ti-box me-2 text-primary"></i>Products: <strong>{{ $limitLabel($feature($plan, 'product_limit')) }}</strong></li>
                            <li class="mb-1"><i class="ti ti-stack-2 me-2 text-primary"></i>Modules: <strong>{{ $plan->enabledModules->count() }}</strong></li>
                        </ul>

                        <div class="mb-3">
                            @foreach($plan->enabledModules->take(5) as $module)
                                <span class="badge bg-light text-dark border me-1 mb-1">{{ $module->name }}</span>
                            @endforeach
                            @if($plan->enabledModules->count() > 5)
                                <span class="badge bg-light text-muted border mb-1">+{{ $plan->enabledModules->count() - 5 }} more</span>
                            @endif
                        </div>

                        <a href="{{ url('/start-trial?plan=' . $plan->code) }}" class="btn btn-primary mt-auto">Start Trial</a>
                    </div>
                </div>
            @endforeach

            @foreach($customPlans as $plan)
                <div class="col-md-6 col-lg-3">
                    <div class="plan-card bg-white p-4 h-100 d-flex flex-column" style="border-color:#1d4ed8;">
                        <h4 class="fw-bold mb-1">{{ $plan->name }}</h4>
                        <p class="text-muted small flex-grow-1">{{ $plan->public_description }}</p>

                        <div class="fw-semibold fs-5 mb-1">Custom pricing</div>
                        <div class="text-muted mb-3">Tailored to your rollout</div>

                        @if($plan->trial_days)
                            <span class="badge bg-primary-subtle text-primary align-self-start mb-3">
                                Up to {{ $plan->trial_days }}-day pilot
                            </span>
                        @endif

                        <ul class="list-unstyled small mb-3">
                            <li class="mb-1"><i class="ti ti-building-store me-2 text-primary"></i>Branches: <strong>Unlimited</strong></li>
                            <li class="mb-1"><i class="ti ti-device-desktop me-2 text-primary"></i>Terminals: <strong>Unlimited</strong></li>
                            <li class="mb-1"><i class="ti ti-users me-2 text-primary"></i>Users: <strong>Unlimited</strong></li>
                            <li class="mb-1"><i class="ti ti-stack-2 me-2 text-primary"></i>Modules: <strong>All modules</strong></li>
                        </ul>

                        <a href="{{ url('/contact?plan=' . $plan->code) }}" class="btn btn-outline-primary mt-auto">Contact Sales</a>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="text-muted small text-center mt-4">
            Product limits are plan guidance and may be adjusted for custom deployments.
        </p>
    </div>
</section>

<section class="section-pad bg-white">
    <div class="container">
        <h2 class="fw-bold text-center mb-4">Compare plans</h2>

        @php
            $comparePlans = $plans; // all public plans, ordered by display_order
            $moduleRows = [
                'pos' => 'POS',
                'catalog' => 'Catalog',
                'inventory' => 'Inventory',
                'purchasing' => 'Purchasing',
                'restaurant' => 'Restaurant Tables',
                'printing' => 'KOT Printing',
                'kitchen_display' => 'Kitchen Display',
                'kitchen_inventory' => 'Kitchen Inventory',
                'stock_count' => 'Stock Count',
                'sales_controls' => 'Sales Controls',
                'reports' => 'Reports',
                'multi_branch' => 'Multi Branch',
                'users_roles' => 'Users & Roles',
            ];
            $limitRows = [
                'branch_limit' => 'Branches',
                'terminal_limit' => 'Terminals',
                'user_limit' => 'Users',
                'product_limit' => 'Products',
            ];
        @endphp

        <div class="table-responsive rounded border">
            <table class="table align-middle mb-0 text-center">
                <thead class="table-light">
                    <tr>
                        <th class="text-start">Feature</th>
                        @foreach($comparePlans as $plan)
                            <th>{{ $plan->name }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($moduleRows as $key => $label)
                        <tr>
                            <td class="text-start fw-semibold">{{ $label }}</td>
                            @foreach($comparePlans as $plan)
                                <td>
                                    @if($plan->is_custom || $hasModule($plan, $key))
                                        <i class="ti ti-check text-success"></i>
                                    @else
                                        <i class="ti ti-minus text-muted"></i>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    @foreach($limitRows as $key => $label)
                        <tr>
                            <td class="text-start fw-semibold">{{ $label }}</td>
                            @foreach($comparePlans as $plan)
                                <td>{{ $limitLabel($feature($plan, $key)) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr>
                        <td class="text-start fw-semibold">Get started</td>
                        @foreach($comparePlans as $plan)
                            <td>
                                @if($plan->is_custom)
                                    <a href="{{ url('/contact?plan=' . $plan->code) }}" class="btn btn-sm btn-outline-primary">Contact Sales</a>
                                @else
                                    <a href="{{ url('/start-trial?plan=' . $plan->code) }}" class="btn btn-sm btn-primary">Start Trial</a>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection
