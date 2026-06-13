@extends('layouts.public')

@section('title', 'Start 30-Day Free Trial')
@section('meta_description', 'Create your Habibi POS trial workspace with no payment required.')

@section('content')
@php
    $baseDomain = config('tenancy.tenant_base_domain');
    $defaultCurrency = config('saas.default_currency', 'PKR');
    $feature = fn($plan, $key) => optional($plan->features->firstWhere('feature_key', $key))->feature_value;
    $limitLabel = fn($v) => ($v === null || $v === '') ? 'Unlimited' : $v;
@endphp

<section class="section-pad">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9">
                <div class="text-center mb-4">
                    <h1 class="fw-bold mb-1">Start your free trial</h1>
                    <p class="text-muted">Create your account and you'll be live in minutes.</p>
                </div>

                @if($enterpriseRequested)
                    <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <span>Enterprise plans are handled by our team. Please contact sales for setup.</span>
                        <a href="{{ url('/contact?plan=enterprise') }}" class="btn btn-sm btn-primary">Contact Sales</a>
                    </div>
                @endif

                @if($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <form method="POST" action="{{ url('/start-trial') }}">
                            @csrf

                            {{-- Honeypot: hidden from real users --}}
                            <div style="position:absolute;left:-9999px;" aria-hidden="true">
                                <label>Website</label>
                                <input type="text" name="website" tabindex="-1" autocomplete="off" value="{{ old('website') }}">
                            </div>

                            <input type="hidden" name="currency_code" value="{{ old('currency_code', $defaultCurrency) }}">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Business name</label>
                                    <input type="text" name="business_name" class="form-control"
                                           value="{{ old('business_name') }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Subdomain (your login address)</label>
                                    <div class="input-group">
                                        <input type="text" id="tenant_code" name="tenant_code" class="form-control"
                                               value="{{ old('tenant_code') }}" required placeholder="your-subdomain">
                                        <span class="input-group-text">.{{ $baseDomain }}</span>
                                    </div>
                                    <small class="text-muted">
                                        Your login URL will be:
                                        <code id="loginUrlPreview">{{ old('tenant_code', 'your-subdomain') }}.{{ $baseDomain }}/login</code>
                                    </small>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Owner name</label>
                                    <input type="text" name="owner_name" class="form-control"
                                           value="{{ old('owner_name') }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Owner email</label>
                                    <input type="email" name="owner_email" class="form-control"
                                           value="{{ old('owner_email') }}" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Owner phone <span class="text-muted">(optional)</span></label>
                                    <input type="text" name="owner_phone" class="form-control" value="{{ old('owner_phone') }}">
                                </div>
                                <div class="col-md-6"></div>

                                <div class="col-md-6">
                                    <label class="form-label">Password</label>
                                    <input type="password" name="password" class="form-control" required minlength="8">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Confirm password</label>
                                    <input type="password" name="password_confirmation" class="form-control" required minlength="8">
                                </div>

                                <div class="col-12">
                                    <label class="form-label d-block">Choose a plan</label>
                                    <div class="row g-3">
                                        @foreach($plans as $plan)
                                            @php $checked = old('plan_id', $selectedPlan?->id) == $plan->id; @endphp
                                            <div class="col-md-6 col-lg-3">
                                                <label class="plan-card d-block p-3 h-100" style="cursor:pointer;">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="plan_id"
                                                               value="{{ $plan->id }}" {{ $checked ? 'checked' : '' }} required>
                                                        <span class="form-check-label fw-semibold">{{ $plan->name }}</span>
                                                    </div>
                                                    <div class="text-muted small mt-1">{{ $plan->public_description }}</div>
                                                    <div class="mt-2">
                                                        <span class="fw-bold">{{ $plan->currency_code }} {{ number_format((float) ($plan->monthly_price ?? $plan->price), 0) }}</span>
                                                        <small class="text-muted">/ month</small>
                                                    </div>
                                                    @if($plan->trial_days)
                                                        <small class="text-success d-block">{{ $plan->trial_days }}-day free trial</small>
                                                    @endif
                                                    <div class="small text-muted mt-2">
                                                        {{ $limitLabel($feature($plan, 'branch_limit')) }} branches ·
                                                        {{ $limitLabel($feature($plan, 'user_limit')) }} users ·
                                                        {{ $limitLabel($feature($plan, 'terminal_limit')) }} terminals
                                                    </div>
                                                    <div class="mt-2">
                                                        @foreach($plan->enabledModules->take(4) as $module)
                                                            <span class="badge bg-light text-dark border me-1 mb-1">{{ $module->name }}</span>
                                                        @endforeach
                                                        @if($plan->enabledModules->count() > 4)
                                                            <span class="badge bg-light text-muted border mb-1">+{{ $plan->enabledModules->count() - 4 }}</span>
                                                        @endif
                                                    </div>
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>
                                    <small class="text-muted d-block mt-2">
                                        Looking for Enterprise or a custom rollout?
                                        <a href="{{ url('/contact?plan=enterprise') }}">Contact Sales</a>.
                                    </small>
                                </div>

                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary btn-lg w-100">Create my trial account</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
    (function () {
        var input = document.getElementById('tenant_code');
        var preview = document.getElementById('loginUrlPreview');
        if (!input || !preview) return;
        input.addEventListener('input', function () {
            var code = (input.value || 'your-subdomain')
                .toLowerCase().trim()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9_-]/g, '') || 'your-subdomain';
            preview.textContent = code + '.{{ $baseDomain }}/login';
        });
    })();
</script>
@endpush
