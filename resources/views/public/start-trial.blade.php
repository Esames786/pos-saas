@extends('layouts.public')

@section('title', __('Start 30-Day Free Trial'))
@section('meta_description', __('Create your Bingoo POS trial workspace with no payment required. Test retail, restaurant, inventory, and reporting workflows.'))

@section('content')
@php
    $baseDomain = config('tenancy.tenant_base_domain');
    $defaultCurrency = config('saas.default_currency', 'PKR');
    $feature = fn($plan, $key) => optional($plan->features->firstWhere('feature_key', $key))->feature_value;
    $limitLabel = fn($v) => ($v === null || $v === '') ? __('Unlimited') : $v;
    // CLOUD-BILLING-2: the cycle this page opens on (?billing=, or the value posted back). Amounts come
    // from $planPrices (the controller's resolver), never from arithmetic here.
    $billingChoice = old('billing_period', $selectedBilling ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
    $money = fn ($n) => number_format((float) $n, 0);
    $perMonthOfYear = fn ($plan) => $money($planPrices[$plan->id]['yearly'] / 12);
    // WEBSITE-I18N-GEO-1 P2: a per-branch market (Saudi Arabia, UAE, Qatar, US) — $checkout holds the
    // server's quote for the chosen plan, branches and extra terminals.
    $pb = $checkout ?? null;
    $pbq = $pb['quote'] ?? null;
    $pbMoney = fn ($n) => \App\Support\PublicMoney::format((float) $n, $pbq['currency'] ?? 'PKR');
@endphp
@if($pb)
    @include('public.partials.branch-quote-js')
@endif

{{-- HERO --}}
<section class="public-hero-premium" style="padding:4rem 0 2.5rem;position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;inset-inline-end:-30px;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <span class="hero-badge mb-3"><i class="ti ti-rocket"></i> {{ __('30-day free trial') }}</span>
        <h1 class="fw-bold mb-2" style="font-size:2.3rem;">{{ __('Start your 30-day Bingoo POS trial.') }}</h1>
        <p class="lead mb-4 mx-auto" style="color:#cbd5e1;max-width:760px;">
            {{ __('Create your cloud POS workspace, invite your team, and test retail, restaurant, inventory, and reporting workflows — no payment required.') }}
        </p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            @foreach([__('No payment required'), __('Workspace created automatically'), __('Secure owner account'), __('Upgrade anytime')] as $chip)
                <span class="hero-badge"><i class="ti ti-check"></i> {{ $chip }}</span>
            @endforeach
        </div>
    </div>
</section>

{{-- 3-STEP REASSURANCE STRIP --}}
<div class="trust-strip py-3">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-center gap-4 py-1">
            <div class="trust-item"><i class="ti ti-forms"></i> 1. {{ __('Create your business profile') }}</div>
            <div class="trust-item"><i class="ti ti-cloud-check"></i> 2. {{ __('Your workspace is provisioned') }}</div>
            <div class="trust-item"><i class="ti ti-login"></i> 3. {{ __('Log in and start testing') }}</div>
        </div>
    </div>
</div>

<section class="section-pad">
    <div class="container">
        @if($enterpriseRequested)
            <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2 reveal">
                <span>{{ __('Enterprise plans are handled by our team. Please contact sales for setup.') }}</span>
                <a href="{{ $lurl('/contact?plan=enterprise') }}" class="btn btn-sm btn-primary">{{ __('Contact Sales') }}</a>
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
                    <h6 class="text-uppercase text-muted small mb-3" style="letter-spacing:1px;">{{ __('Selected plan') }}</h6>
                    @if($selectedPlan && $pbq)
                        <h4 class="fw-bold mb-1" id="pbPlanName">{{ __($selectedPlan->name) }}</h4>
                        <p class="text-muted small mb-2">{{ __((string) $selectedPlan->public_description) }}</p>
                        <div class="plan-price" id="pbTotal">{{ $pbMoney($pbq['total']) }}</div>
                        <div class="text-muted small mb-2" id="pbPer">{{ $billingChoice === 'yearly' ? __('per year') : __('per month') }}@if($pbq['vat_percent']) · {{ __('+ :percent% VAT', ['percent' => $pbq['vat_percent']]) }}@endif</div>
                        <ul class="list-unstyled small text-muted mb-2" id="pbLines">
                            <li>{{ __('Branches') }}: {{ $pbq['branches'] }} × {{ $pbMoney($pbq['unit']) }}</li>
                            @if($pbq['extra_terminals'])<li>{{ __('Extra terminals') }}: {{ $pbq['extra_terminals'] }} × {{ $pbMoney($pbq['extra_terminal_unit']) }}</li>@endif
                            @if($pbq['discount_percent'])<li class="text-success">{{ __('Multi-branch discount (:percent%)', ['percent' => $pbq['discount_percent']]) }}: {{ $pbMoney(-$pbq['discount']) }}</li>@endif
                        </ul>
                        @if($selectedPlan->trial_days)
                            <span class="badge bg-success-subtle text-success mb-3">{{ __(':days-day free trial', ['days' => $selectedPlan->trial_days]) }}</span>
                        @endif
                        <ul class="list-unstyled small text-muted mb-0" id="pbIncludes">
                            <li class="mb-1"><i class="ti ti-building-store me-2 text-primary"></i>{{ __('Branches: :count', ['count' => $pbq['branches']]) }}</li>
                            <li class="mb-1"><i class="ti ti-device-desktop me-2 text-primary"></i>{{ __('Terminals: :count', ['count' => $pbq['terminals']]) }}</li>
                            <li class="mb-1"><i class="ti ti-users me-2 text-primary"></i>{{ __('Users: :count', ['count' => $pbq['users']]) }}</li>
                        </ul>
                    @elseif($selectedPlan)
                        {{-- CLOUD-BILLING-2: this card follows the cycle and the plan picked in the form. It used to
                             say "per month" even when the visitor had chosen Yearly on the pricing page. --}}
                        <h4 class="fw-bold mb-1" id="selPlanName">{{ __($selectedPlan->name) }}</h4>
                        <p class="text-muted small mb-2" id="selPlanDesc">{{ __((string) $selectedPlan->public_description) }}</p>
                        <div class="plan-price"><bdi><span id="selPlanCurrency">{{ $selectedPlan->currency_code }}</span> <span id="selPlanAmount">{{ $money($planPrices[$selectedPlan->id][$billingChoice]) }}</span></bdi></div>
                        <div class="text-muted small mb-1" id="selPlanPer">{{ $billingChoice === 'yearly' ? __('per year') : __('per month') }}</div>
                        <div class="small text-success mb-2" id="selPlanSaving" @if($billingChoice !== 'yearly') hidden @endif>
                            {{ __('2 months free') }} · <bdi id="selPlanMonthlyEq">{{ $selectedPlan->currency_code }} {{ $perMonthOfYear($selectedPlan) }}</bdi> {{ __('a month') }}
                        </div>
                        @if($selectedPlan->trial_days)
                            <span class="badge bg-success-subtle text-success mb-3">{{ __(':days-day free trial', ['days' => $selectedPlan->trial_days]) }}</span>
                        @endif
                        <ul class="list-unstyled small text-muted mb-0">
                            <li class="mb-1"><i class="ti ti-building-store me-2 text-primary"></i>{{ __('Branches:') }} <span id="selPlanBranches">{{ $limitLabel($feature($selectedPlan,'branch_limit')) }}</span></li>
                            <li class="mb-1"><i class="ti ti-users me-2 text-primary"></i>{{ __('Users:') }} <span id="selPlanUsers">{{ $limitLabel($feature($selectedPlan,'user_limit')) }}</span></li>
                            <li class="mb-1"><i class="ti ti-stack-2 me-2 text-primary"></i>{{ __('Modules:') }} <span id="selPlanModules">{{ $selectedPlan->enabledModules->count() }}</span></li>
                            @if($selectedPlan->enabledModules->pluck('key')->contains('finance'))
                                <li class="mb-1"><i class="ti ti-report-money me-2 text-primary"></i>{{ __('Includes Finance & Accounting (GL, P&L, Balance Sheet)') }}</li>
                            @endif
                        </ul>
                    @else
                        <p class="text-muted mb-0">{{ __('Choose a plan in the form, or continue with the default selection.') }}</p>
                    @endif
                </div>

                <div class="gradient-card p-4 mb-4 reveal">
                    <h6 class="fw-bold mb-3">{{ __('What happens next') }}</h6>
                    @foreach([
                        ['ti-forms', __('Create your business profile'), __('Business name, subdomain, and owner account.')],
                        ['ti-cloud-check', __('Your workspace is provisioned'), __('Tenant database, owner role, and trial plan — automatically.')],
                        ['ti-login', __('Log in and start testing'), __('Open your subdomain and start selling right away.')],
                    ] as $i => [$ico,$t,$d])
                        <div class="d-flex gap-3 mb-3">
                            <div class="icon-wrap flex-shrink-0" style="width:42px;height:42px;"><i class="ti {{ $ico }}"></i></div>
                            <div><div class="fw-semibold small">{{ $t }}</div><div class="text-muted small">{{ $d }}</div></div>
                        </div>
                    @endforeach
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <span class="badge bg-success-subtle text-success border"><i class="ti ti-shield-check me-1"></i>{{ __('Secure owner account') }}</span>
                        <span class="badge bg-light text-dark border"><i class="ti ti-eye-off me-1"></i>{{ __('Password never shown on success page') }}</span>
                    </div>
                </div>

                <div class="gradient-card p-4 reveal">
                    <h6 class="fw-bold mb-2">{{ __('Want to test before creating your own workspace?') }}</h6>
                    <p class="text-muted small mb-3">{{ __('Open a live demo workspace for retail, restaurant, inventory, restaurant pro, or multi-branch enterprise — with sample data and no signup required.') }}</p>
                    <a href="{{ $lurl('/demos') }}" class="btn btn-sm btn-outline-primary"><i class="ti ti-player-play me-1"></i>{{ __('View Live Demos') }}</a>
                </div>
            </div>

            {{-- RIGHT: signup form --}}
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm reveal">
                    <div class="card-body p-4 p-md-5">
                        {{-- WEBSITE-I18N-GEO-1: posts back to the same language, so errors come back in it too. --}}
                        <form method="POST" action="{{ $lurl('/start-trial') }}">
                            @csrf
                            <div style="position:absolute;inset-inline-start:-9999px;" aria-hidden="true">
                                <label>Website</label>
                                <input type="text" name="website" tabindex="-1" autocomplete="off" value="{{ old('website') }}">
                            </div>
                            <input type="hidden" name="currency_code" value="{{ old('currency_code', $pbq['currency'] ?? $defaultCurrency) }}">
                            {{-- WEBSITE-I18N-GEO-1 P3: the market this page priced (every market, not only per-branch:
                                 an Arabic visitor who picked Pakistan must not be signed up as Saudi), and the
                                 visitor's own clock, used only where the market has no single timezone. --}}
                            <input type="hidden" name="market" value="{{ old('market', $market) }}">
                            <input type="hidden" name="timezone" id="visitorTimezone" value="{{ old('timezone') }}">

                            {{-- Business details --}}
                            <h6 class="text-uppercase text-muted small mb-3" style="letter-spacing:1px;"><i class="ti ti-building me-1"></i>{{ __('Business details') }}</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Business name') }}</label>
                                    <input type="text" name="business_name" class="form-control" value="{{ old('business_name') }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Subdomain (your login address)') }}</label>
                                    {{-- An address reads left to right in every language. --}}
                                    <div class="input-group" dir="ltr">
                                        <input type="text" id="tenant_code" name="tenant_code" class="form-control" value="{{ old('tenant_code') }}" required placeholder="your-subdomain">
                                        <span class="input-group-text">.{{ $baseDomain }}</span>
                                    </div>
                                    <small class="text-muted">{{ __('Your login URL:') }} <code id="loginUrlPreview" dir="ltr">{{ old('tenant_code', 'your-subdomain') }}.{{ $baseDomain }}/login</code></small>
                                </div>
                            </div>

                            {{-- Owner account --}}
                            <h6 class="text-uppercase text-muted small mb-3" style="letter-spacing:1px;"><i class="ti ti-user me-1"></i>{{ __('Owner account') }}</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Owner name') }}</label>
                                    <input type="text" name="owner_name" class="form-control" value="{{ old('owner_name') }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Owner email') }}</label>
                                    <input type="email" name="owner_email" class="form-control" dir="ltr" value="{{ old('owner_email') }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Owner phone') }} <span class="text-muted">({{ __('optional') }})</span></label>
                                    <input type="text" name="owner_phone" class="form-control" dir="ltr" value="{{ old('owner_phone') }}">
                                </div>
                            </div>

                            {{-- Workspace login --}}
                            <h6 class="text-uppercase text-muted small mb-3" style="letter-spacing:1px;"><i class="ti ti-lock me-1"></i>{{ __('Workspace login') }}</h6>
                            <div class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Password') }}</label>
                                    <input type="password" name="password" class="form-control" required minlength="8">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Confirm password') }}</label>
                                    <input type="password" name="password_confirmation" class="form-control" required minlength="8">
                                </div>
                            </div>
                            <p class="text-muted small mb-4"><i class="ti ti-eye-off me-1"></i>{{ __('Your password is never shown on the success page.') }}</p>

                            {{-- Billing cycle (CLOUD-BILLING-2) — posts billing_period only; the server prices it.
                                 Pre-selected from ?billing=, so it works without JS. --}}
                            <h6 class="text-uppercase text-muted small mb-3" style="letter-spacing:1px;"><i class="ti ti-calendar me-1"></i>{{ __('Billing cycle') }}</h6>
                            <div class="row g-3 mb-4" id="billingCycle">
                                @foreach(['monthly' => [__('Monthly'), __('Billed every month')], 'yearly' => [__('Yearly'), __("2 months free — 10 months' price, billed once for 12 months")]] as $cycle => [$label, $sub])
                                    <div class="col-md-6">
                                        <label class="plan-card d-block p-3 h-100" style="cursor:pointer;">
                                            <div class="form-check">
                                                <input class="form-check-input billing-radio" type="radio" name="billing_period" value="{{ $cycle }}" @checked($billingChoice === $cycle) required>
                                                <span class="form-check-label fw-semibold">{{ $label }}</span>
                                            </div>
                                            <div class="text-muted small mt-1">{{ $sub }}</div>
                                        </label>
                                    </div>
                                @endforeach
                            </div>

                            @if($pb)
                                {{-- WEBSITE-I18N-GEO-1 P2: what was chosen in the plan builder. Posted as counts only; the
                                     server prices them again from the plan. --}}
                                <h6 class="text-uppercase text-muted small mb-3" style="letter-spacing:1px;"><i class="ti ti-building-store me-1"></i>{{ __('Branches and terminals') }}</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label" for="pbBranches">{{ __('Branches') }}</label>
                                        <input type="number" class="form-control" id="pbBranches" name="branches" min="1" max="{{ $perBranch['builder']['maxBranches'] }}" value="{{ old('branches', $pb['branches']) }}" dir="ltr">
                                        <small class="text-muted">{{ __('More than :count branches?', ['count' => $perBranch['builder']['maxBranches']]) }} <a href="{{ $lurl('/contact?plan=enterprise') }}">{{ __('Contact Sales') }}</a></small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="pbExtra">{{ __('Extra terminals') }}</label>
                                        <input type="number" class="form-control" id="pbExtra" name="extra_terminals" min="0" max="99" value="{{ old('extra_terminals', $pb['extra_terminals']) }}" dir="ltr">
                                    </div>
                                </div>
                            @endif

                            {{-- Plan --}}
                            <h6 class="text-uppercase text-muted small mb-3" style="letter-spacing:1px;"><i class="ti ti-stack-2 me-1"></i>{{ __('Choose your plan') }}</h6>
                            <div class="row g-3 mb-3">
                                @foreach($plans as $plan)
                                    @continue($pb && ! isset($perBranch['plans'][$plan->code]))
                                    @php $checked = old('plan_id', $selectedPlan?->id) == $plan->id; @endphp
                                    <div class="col-md-6">
                                        <label class="plan-card d-block p-3 h-100 {{ $plan->code==='restaurant_pro' ? 'plan-card-popular' : '' }}" style="cursor:pointer;">
                                            <div class="form-check">
                                                <input class="form-check-input plan-radio" type="radio" name="plan_id" value="{{ $plan->id }}" {{ $checked ? 'checked' : '' }} required
                                                       data-name="{{ __($plan->name) }}" data-desc="{{ __((string) $plan->public_description) }}" data-currency="{{ $plan->currency_code }}"
                                                       data-monthly="{{ $money($planPrices[$plan->id]['monthly']) }}" data-yearly="{{ $money($planPrices[$plan->id]['yearly']) }}"
                                                       data-yearly-month="{{ $perMonthOfYear($plan) }}"
                                                       data-branches="{{ $limitLabel($feature($plan,'branch_limit')) }}" data-users="{{ $limitLabel($feature($plan,'user_limit')) }}"
                                                       data-modules="{{ $plan->enabledModules->count() }}">
                                                <span class="form-check-label fw-semibold">{{ __($plan->name) }}</span>
                                            </div>
                                            <div class="text-muted small mt-1">{{ __((string) $plan->public_description) }}</div>
                                            @if($pb)
                                                <div class="mt-2"><span class="fw-bold">{{ $pbMoney($perBranch['plans'][$plan->code]['quote']['unit']) }}</span> <small class="text-muted">{{ __('per branch / month') }}</small></div>
                                            @else
                                            <div class="mt-2">
                                                <span class="plan-price-monthly" @if($billingChoice !== 'monthly') hidden @endif>
                                                    <span class="fw-bold"><bdi>{{ $plan->currency_code }} {{ $money($planPrices[$plan->id]['monthly']) }}</bdi></span>
                                                    <small class="text-muted">/ {{ __('month') }}</small>
                                                </span>
                                                <span class="plan-price-yearly" @if($billingChoice !== 'yearly') hidden @endif>
                                                    <span class="fw-bold"><bdi>{{ $plan->currency_code }} {{ $money($planPrices[$plan->id]['yearly']) }}</bdi></span>
                                                    <small class="text-muted">/ {{ __('year') }}</small>
                                                </span>
                                                @if($plan->trial_days)<small class="text-success ms-2">{{ __(':days-day trial', ['days' => $plan->trial_days]) }}</small>@endif
                                            </div>
                                            <div class="small text-muted mt-1">
                                                {{ __(':branches branches', ['branches' => $limitLabel($feature($plan,'branch_limit'))]) }} ·
                                                {{ __(':users users', ['users' => $limitLabel($feature($plan,'user_limit'))]) }} ·
                                                {{ __(':modules modules', ['modules' => $plan->enabledModules->count()]) }}
                                            </div>
                                            @endif
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                            <small class="text-muted d-block mb-4">{{ __('Looking for Enterprise or a custom rollout?') }} <a href="{{ $lurl('/contact?plan=enterprise') }}">{{ __('Contact Sales') }}</a>.</small>

                            <button type="submit" class="btn btn-primary btn-lg w-100"><i class="ti ti-rocket me-2"></i>{{ __('Create my trial account') }}</button>
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

// WEBSITE-I18N-GEO-1 P3: the visitor's timezone, for markets that span several (the US).
(function () {
    var tz = document.getElementById('visitorTimezone');
    try { if (tz && !tz.value && window.Intl) tz.value = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
})();

// CLOUD-BILLING-2: show the price for the chosen cycle and plan, here and in the summary card. Display only —
// the server prices the subscription from the plan and the posted billing_period.
(function () {
    var PER = { yearly: @json(__('per year')), monthly: @json(__('per month')) };
    function checked(name) { return document.querySelector('input[name="' + name + '"]:checked'); }
    function set(id, text) { var el = document.getElementById(id); if (el) el.textContent = text; }
    function apply() {
        var cycle = (checked('billing_period') || {}).value === 'yearly' ? 'yearly' : 'monthly';
        var yearly = cycle === 'yearly';
        document.querySelectorAll('.plan-price-monthly').forEach(function (el) { el.hidden = yearly; });
        document.querySelectorAll('.plan-price-yearly').forEach(function (el) { el.hidden = !yearly; });
        var plan = checked('plan_id');
        if (!plan) return;
        var d = plan.dataset;
        set('selPlanName', d.name);
        set('selPlanDesc', d.desc);
        set('selPlanCurrency', d.currency);
        set('selPlanAmount', yearly ? d.yearly : d.monthly);
        set('selPlanPer', PER[cycle]);
        set('selPlanMonthlyEq', d.currency + ' ' + d.yearlyMonth);
        set('selPlanBranches', d.branches);
        set('selPlanUsers', d.users);
        set('selPlanModules', d.modules);
        var saving = document.getElementById('selPlanSaving');
        if (saving) saving.hidden = !yearly;
    }
    document.querySelectorAll('.billing-radio, .plan-radio').forEach(function (r) { r.addEventListener('change', apply); });
})();

@if($pb)
// WEBSITE-I18N-GEO-1 P2: the per-branch summary, from the same arithmetic as the pricing page's builder.
(function () {
    var D = @json($perBranch['builder']), Q = window.BingooQuote;
    var byId = {};
    Object.keys(D.plans).forEach(function (code) { byId[String(D.plans[code].id)] = D.plans[code]; });
    function val(id, min, max) { var n = parseInt((document.getElementById(id) || {}).value, 10); return Math.max(min, Math.min(max, isNaN(n) ? min : n)); }
    function set(id, html) { var el = document.getElementById(id); if (el) el.innerHTML = html; }
    function apply() {
        var radio = document.querySelector('input[name="plan_id"]:checked');
        var p = radio ? byId[radio.value] : null;
        if (!p) return;
        var yearly = (document.querySelector('input[name="billing_period"]:checked') || {}).value === 'yearly';
        var b = val('pbBranches', 1, D.maxBranches), x = val('pbExtra', 0, 99), q = Q.quote(D, p, b, x), e = Q.esc;
        set('pbPlanName', e(p.name));
        set('pbTotal', e(Q.money(D, yearly ? q.year : q.month)));
        set('pbPer', e(Q.t(D, yearly ? 'per year' : 'per month')) + (D.vat ? ' · ' + e(Q.t(D, '+ :percent% VAT', { percent: D.vat })) : ''));
        set('pbLines', '<li>' + e(Q.t(D, 'Branches')) + ': ' + b + ' × ' + e(Q.money(D, p.unit)) + '</li>'
            + (x ? '<li>' + e(Q.t(D, 'Extra terminals')) + ': ' + x + ' × ' + e(Q.money(D, p.extra)) + '</li>' : '')
            + (q.pct ? '<li class="text-success">' + e(Q.t(D, 'Multi-branch discount (:percent%)', { percent: q.pct })) + ': ' + e(Q.money(D, q.disc, true)) + '</li>' : ''));
        set('pbIncludes', ['Branches: :count', 'Terminals: :count', 'Users: :count'].map(function (k, i) {
            return '<li class="mb-1">' + e(Q.t(D, k, { count: [b, q.terminals, q.users][i] })) + '</li>';
        }).join(''));
    }
    ['pbBranches', 'pbExtra'].forEach(function (id) { var el = document.getElementById(id); if (el) el.addEventListener('input', apply); });
    document.querySelectorAll('.billing-radio, .plan-radio').forEach(function (r) { r.addEventListener('change', apply); });
})();
@endif
</script>
@endpush
