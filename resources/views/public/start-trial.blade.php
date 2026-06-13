@extends('layouts.public')

@section('title', 'Start Free Trial')

@section('content')
@php $baseDomain = config('tenancy.tenant_base_domain'); @endphp

<section class="section-pad">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-4">
                    <h1 class="fw-bold mb-1">Start your free trial</h1>
                    <p class="text-muted">Create your account and you'll be live in minutes.</p>
                </div>

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

                            <input type="hidden" name="currency_code" value="{{ old('currency_code', 'PKR') }}">

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
                                               value="{{ old('tenant_code') }}" required
                                               placeholder="yourbusiness">
                                        <span class="input-group-text">.{{ $baseDomain }}</span>
                                    </div>
                                    <small class="text-muted">
                                        Your login URL will be:
                                        <code id="loginUrlPreview">{{ old('tenant_code', 'yourbusiness') }}.{{ $baseDomain }}/login</code>
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
                                    <input type="text" name="owner_phone" class="form-control"
                                           value="{{ old('owner_phone') }}">
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
                                            <div class="col-md-6">
                                                <label class="plan-card d-block p-3 h-100" style="cursor:pointer;">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="plan_id"
                                                               value="{{ $plan->id }}" {{ $checked ? 'checked' : '' }} required>
                                                        <span class="form-check-label fw-semibold">{{ $plan->name }}</span>
                                                    </div>
                                                    <div class="mt-1">
                                                        <span class="fw-bold">{{ $plan->currency_code }} {{ number_format((float) $plan->price, 0) }}</span>
                                                        <small class="text-muted">/ {{ $plan->billing_period }}</small>
                                                    </div>
                                                    @if($plan->trial_days)
                                                        <small class="text-success">{{ $plan->trial_days }}-day free trial</small>
                                                    @endif
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>
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
            var code = (input.value || 'yourbusiness')
                .toLowerCase().trim()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9_-]/g, '') || 'yourbusiness';
            preview.textContent = code + '.{{ $baseDomain }}/login';
        });
    })();
</script>
@endpush
