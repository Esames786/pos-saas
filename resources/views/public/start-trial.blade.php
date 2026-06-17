@extends('layouts.public')

@section('title', 'Start 30-Day Free Trial')
@section('meta_description', 'Create your Bingoo POS trial workspace with no payment required. Test retail, restaurant, inventory, and reporting workflows.')

@section('content')
@php
    $baseDomain = config('tenancy.tenant_base_domain');
    $defaultCurrency = config('saas.default_currency', 'PKR');
    $feature = fn($plan, $key) => optional($plan->features->firstWhere('feature_key', $key))->feature_value;
    $limitLabel = fn($v) => ($v === null || $v === '') ? 'Unlimited' : $v;
@endphp

{{-- HERO --}}
<section class="public-hero-premium" style="padding:4rem 0 2.5rem;position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;right:-30px;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <span class="hero-badge mb-3"><i class="ti ti-rocket"></i> 30-day free trial</span>
        <h1 class="fw-bold mb-2" style="font-size:2.3rem;">Start your 30-day Bingoo POS trial.</h1>
        <p class="lead mb-4 mx-auto" style="color:#cbd5e1;max-width:760px;">
            Create your cloud POS workspace, invite your team, and test retail, restaurant, inventory, and
            reporting workflows — no payment required.
        </p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            @foreach(['No payment required','Workspace created automatically','Secure owner account','Upgrade anytime'] as $chip)
                <span class="hero-badge"><i class="ti ti-check"></i> {{ $chip }}</span>
            @endforeach
        </div>
    </div>
</section>

{{-- 3-STEP REASSURANCE STRIP --}}
<div class="trust-strip py-3">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-center gap-4 py-1">
            <div class="trust-item"><i class="ti ti-forms"></i> 1. Create your business profile</div>
            <div class="trust-item"><i class="ti ti-cloud-check"></i> 2. Your workspace is provisioned</div>
            <div class="trust-item"><i class="ti ti-login"></i> 3. Log in and start testing</div>
        </div>
    </div>
</div>

<section class="section-pad">
    <div class="container">
        @if($enterpriseRequested)
            <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2 reveal">
                <span>Enterprise plans are handled by our team. Please contact sales for setup.</span>
                <a href="{{ url('/contact?plan=enterprise') }}" class="btn btn-sm btn-primary">Contact Sales</a>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger reveal">
                <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="row g-4">
            {{-- LEFT: summary + next steps + trust + demo --}}
            <div class="col-lg-4">
                <div class="gradient-card p-4 mb-4 reveal">
                    <h6 class="text-uppercase text-muted small mb-3" style="letter-spacing:1px;">Selected plan</h6>
                    @if($selectedPlan)
                        <h4 class="fw-bold mb-1">{{ $selectedPlan->name }}</h4>
                        <p class="text-muted small mb-2">{{ $selectedPlan->public_description }}</p>
                        <div class="plan-price">{{ $selectedPlan->currency_code }} {{ number_format((float)($selectedPlan->monthly_price ?? $selectedPlan->price),0) }}</div>
                        <div class="text-muted small mb-2">per month</div>
                        @if($selectedPlan->trial_days)
                            <span class="badge bg-success-subtle text-success mb-3">{{ $selectedPlan->trial_days }}-day free trial</span>
                        @endif
                        <ul class="list-unstyled small text-muted mb-0">
                            <li class="mb-1"><i class="ti ti-building-store me-2 text-primary"></i>{{ $limitLabel($feature($selectedPlan,'branch_limit')) }} branches</li>
                            <li class="mb-1"><i class="ti ti-users me-2 text-primary"></i>{{ $limitLabel($feature($selectedPlan,'user_limit')) }} users</li>
                            <li class="mb-1"><i class="ti ti-stack-2 me-2 text-primary"></i>{{ $selectedPlan->enabledModules->count() }} modules included</li>
                            @if($selectedPlan->enabledModules->pluck('key')->contains('finance'))
                                <li class="mb-1"><i class="ti ti-report-money me-2 text-primary"></i>Includes Finance &amp; Accounting (GL, P&amp;L, Balance Sheet)</li>
                            @endif
                        </ul>
                    @else
                        <p class="text-muted mb-0">Choose a plan in the form, or continue with the default selection.</p>
                    @endif
                </div>

                <div class="gradient-card p-4 mb-4 reveal">
                    <h6 class="fw-bold mb-3">What happens next</h6>
                    @foreach([
                        ['ti-forms','Create your business profile','Business name, subdomain, and owner account.'],
                        ['ti-cloud-check','Your workspace is provisioned','Tenant database, owner role, and trial plan — automatically.'],
                        ['ti-login','Log in and start testing','Open your subdomain and start selling right away.'],
                    ] as $i => [$ico,$t,$d])
                        <div class="d-flex gap-3 mb-3">
                            <div class="icon-wrap flex-shrink-0" style="width:42px;height:42px;"><i class="ti {{ $ico }}"></i></div>
                            <div><div class="fw-semibold small">{{ $t }}</div><div class="text-muted small">{{ $d }}</div></div>
                        </div>
                    @endforeach
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <span class="badge bg-success-subtle text-success border"><i class="ti ti-shield-check me-1"></i>Secure owner account</span>
                        <span class="badge bg-light text-dark border"><i class="ti ti-eye-off me-1"></i>Password never shown on success page</span>
                    </div>
                </div>

                <div class="gradient-card p-4 reveal">
                    <h6 class="fw-bold mb-2">Want to test before creating your own workspace?</h6>
                    <p class="text-muted small mb-3">Open a live demo workspace for retail, restaurant, inventory, restaurant pro, or multi-branch enterprise — with sample data and no signup required.</p>
                    <a href="{{ url('/demos') }}" class="btn btn-sm btn-outline-primary"><i class="ti ti-player-play me-1"></i>View Live Demos</a>
                </div>
            </div>

            {{-- RIGHT: signup form --}}
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm reveal">
                    <div class="card-body p-4 p-md-5">
                        <form method="POST" action="{{ url('/start-trial') }}">
                            @csrf
                            <div style="position:absolute;left:-9999px;" aria-hidden="true">
                                <label>Website</label>
                                <input type="text" name="website" tabindex="-1" autocomplete="off" value="{{ old('website') }}">
                            </div>
                            <input type="hidden" name="currency_code" value="{{ old('currency_code', $defaultCurrency) }}">

                            {{-- Business details --}}
                            <h6 class="text-uppercase text-muted small mb-3" style="letter-spacing:1px;"><i class="ti ti-building me-1"></i>Business details</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Business name</label>
                                    <input type="text" name="business_name" class="form-control" value="{{ old('business_name') }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Subdomain (your login address)</label>
                                    <div class="input-group">
                                        <input type="text" id="tenant_code" name="tenant_code" class="form-control" value="{{ old('tenant_code') }}" required placeholder="your-subdomain">
                                        <span class="input-group-text">.{{ $baseDomain }}</span>
                                    </div>
                                    <small class="text-muted">Your login URL: <code id="loginUrlPreview">{{ old('tenant_code', 'your-subdomain') }}.{{ $baseDomain }}/login</code></small>
                                </div>
                            </div>

                            {{-- Owner account --}}
                            <h6 class="text-uppercase text-muted small mb-3" style="letter-spacing:1px;"><i class="ti ti-user me-1"></i>Owner account</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Owner name</label>
                                    <input type="text" name="owner_name" class="form-control" value="{{ old('owner_name') }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Owner email</label>
                                    <input type="email" name="owner_email" class="form-control" value="{{ old('owner_email') }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Owner phone <span class="text-muted">(optional)</span></label>
                                    <input type="text" name="owner_phone" class="form-control" value="{{ old('owner_phone') }}">
                                </div>
                            </div>

                            {{-- Workspace login --}}
                            <h6 class="text-uppercase text-muted small mb-3" style="letter-spacing:1px;"><i class="ti ti-lock me-1"></i>Workspace login</h6>
                            <div class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label">Password</label>
                                    <input type="password" name="password" class="form-control" required minlength="8">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Confirm password</label>
                                    <input type="password" name="password_confirmation" class="form-control" required minlength="8">
                                </div>
                            </div>
                            <p class="text-muted small mb-4"><i class="ti ti-eye-off me-1"></i>Your password is never shown on the success page.</p>

                            {{-- Plan --}}
                            <h6 class="text-uppercase text-muted small mb-3" style="letter-spacing:1px;"><i class="ti ti-stack-2 me-1"></i>Choose your plan</h6>
                            <div class="row g-3 mb-3">
                                @foreach($plans as $plan)
                                    @php $checked = old('plan_id', $selectedPlan?->id) == $plan->id; @endphp
                                    <div class="col-md-6">
                                        <label class="plan-card d-block p-3 h-100 {{ $plan->code==='restaurant_pro' ? 'plan-card-popular' : '' }}" style="cursor:pointer;">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="plan_id" value="{{ $plan->id }}" {{ $checked ? 'checked' : '' }} required>
                                                <span class="form-check-label fw-semibold">{{ $plan->name }}</span>
                                            </div>
                                            <div class="text-muted small mt-1">{{ $plan->public_description }}</div>
                                            <div class="mt-2">
                                                <span class="fw-bold">{{ $plan->currency_code }} {{ number_format((float)($plan->monthly_price ?? $plan->price),0) }}</span>
                                                <small class="text-muted">/ month</small>
                                                @if($plan->trial_days)<small class="text-success ms-2">{{ $plan->trial_days }}-day trial</small>@endif
                                            </div>
                                            <div class="small text-muted mt-1">
                                                {{ $limitLabel($feature($plan,'branch_limit')) }} branches ·
                                                {{ $limitLabel($feature($plan,'user_limit')) }} users ·
                                                {{ $plan->enabledModules->count() }} modules
                                            </div>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                            <small class="text-muted d-block mb-4">Looking for Enterprise or a custom rollout? <a href="{{ url('/contact?plan=enterprise') }}">Contact Sales</a>.</small>

                            <button type="submit" class="btn btn-primary btn-lg w-100"><i class="ti ti-rocket me-2"></i>Create my trial account</button>
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
        var code = (input.value || 'your-subdomain').toLowerCase().trim()
            .replace(/\s+/g, '-').replace(/[^a-z0-9_-]/g, '') || 'your-subdomain';
        preview.textContent = code + '.{{ $baseDomain }}/login';
    });
})();
</script>
@endpush
