@extends('layouts.public')

@section('title', 'Pricing')

@section('content')
@php
    $feature = fn($plan, $key) => optional($plan->features->firstWhere('feature_key', $key))->feature_value;
    $limitLabel = fn($v) => ($v === null || $v === '') ? 'Unlimited' : $v;
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
            @forelse($plans as $plan)
                <div class="col-md-6 col-lg-5">
                    <div class="plan-card bg-white p-4 h-100 d-flex flex-column">
                        <h4 class="fw-bold mb-1">{{ $plan->name }}</h4>
                        <div class="text-muted mb-3 text-uppercase small">{{ $plan->code }}</div>

                        <div class="plan-price">{{ $plan->currency_code }} {{ number_format((float) $plan->price, 0) }}</div>
                        <div class="text-muted mb-3">per {{ $plan->billing_period }}</div>

                        @if($plan->trial_days)
                            <span class="badge bg-success-subtle text-success align-self-start mb-3">
                                {{ $plan->trial_days }}-day free trial
                            </span>
                        @endif

                        <ul class="list-unstyled mb-4">
                            <li class="mb-2"><i class="ti ti-building-store me-2 text-primary"></i>
                                Branches: <strong>{{ $limitLabel($feature($plan, 'branch_limit')) }}</strong></li>
                            <li class="mb-2"><i class="ti ti-users me-2 text-primary"></i>
                                Users: <strong>{{ $limitLabel($feature($plan, 'user_limit')) }}</strong></li>
                            <li class="mb-2"><i class="ti ti-device-desktop me-2 text-primary"></i>
                                Terminals: <strong>{{ $limitLabel($feature($plan, 'terminal_limit')) }}</strong></li>
                            <li class="mb-2"><i class="ti ti-stack-2 me-2 text-primary"></i>
                                Included modules: <strong>{{ $plan->enabledModules->count() }}</strong></li>
                        </ul>

                        @if($plan->enabledModules->count())
                            <div class="mb-4">
                                @foreach($plan->enabledModules->take(6) as $module)
                                    <span class="badge bg-light text-dark border me-1 mb-1">{{ $module->name }}</span>
                                @endforeach
                                @if($plan->enabledModules->count() > 6)
                                    <span class="badge bg-light text-muted border mb-1">+{{ $plan->enabledModules->count() - 6 }} more</span>
                                @endif
                            </div>
                        @endif

                        <a href="{{ url('/start-trial?plan_id=' . $plan->id) }}"
                           class="btn btn-primary mt-auto">Start Trial</a>
                    </div>
                </div>
            @empty
                <div class="col-12 text-center text-muted py-5">No public plans are available right now.</div>
            @endforelse
        </div>
    </div>
</section>
@endsection
