@extends('layouts.public')

@section('title', 'Pricing')
@section('meta_description', 'Compare Bingoo POS packages for retail stores, inventory teams, restaurants, cafés, and enterprise rollouts. 30-day free trial.')

@section('content')
@php
    $feature = fn($plan, $key) => optional($plan->features->firstWhere('feature_key', $key))->feature_value;
    $limitLabel = fn($v) => ($v === null || $v === '') ? 'Unlimited' : $v;
    $hasModule = fn($plan, $key) => $plan->enabledModules->pluck('key')->contains($key);
    $planBadges = [
        'retail_starter'     => 'Best for small shops',
        'inventory_store'    => 'Best for marts & stock-heavy stores',
        'restaurant_starter' => 'Best for cafés & restaurants',
        'restaurant_pro'     => '⭐ Most Popular',
    ];
    // Map plan code → /demos card anchor (15D-7).
    $demoAnchors = [
        'retail_starter'     => 'retail',
        'inventory_store'    => 'inventory',
        'restaurant_starter' => 'restaurant',
        'restaurant_pro'     => 'restaurant_pro',
        'enterprise'         => 'enterprise',
    ];
@endphp

@push('styles')
<style>
    .bill-toggle { background:#fff;border:1px solid #e5e7eb;border-radius:999px;padding:.35rem;display:inline-flex;gap:.25rem; }
    .bill-toggle button { border:0;background:transparent;border-radius:999px;padding:.4rem 1.2rem;font-weight:600;color:#64748b;cursor:pointer; }
    .bill-toggle button.active { background:linear-gradient(135deg,#d8b24e,#a87f24);color:#11203f; }
    #planGrid .price-yearly { display:none; }
    #planGrid.show-yearly .price-monthly { display:none; }
    #planGrid.show-yearly .price-yearly { display:block; }
    .cmp-table th:first-child, .cmp-table td:first-child { position:sticky;left:0;background:#fff;z-index:2;text-align:left; }
    .cmp-table thead th:first-child { background:#f9fafb; }
    .cmp-group td { background:#0f172a;color:#e9c869;font-weight:700;font-size:.8rem;letter-spacing:.5px;text-transform:uppercase; }
    .cmp-hot { background:#fdf6e3 !important; }
</style>
@endpush

{{-- HERO --}}
<section class="public-hero-premium section-pad" style="position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;left:-40px;background:#caa23f;"></div>
    <div class="container" style="position:relative;z-index:2;">
        <div class="row align-items-center gy-4">
            <div class="col-lg-7">
                <span class="hero-badge mb-3"><i class="ti ti-tag"></i> Simple, transparent pricing</span>
                <h1 class="fw-bold mb-3" style="font-size:2.4rem;">Choose the POS plan that fits your business.</h1>
                <p class="lead mb-4" style="color:#cbd5e1;">
                    Start with a 30-day free trial. Upgrade as your team, branches, terminals, and workflows grow.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    @foreach(['No payment required','30-day trial','Retail + restaurant packages','Upgrade anytime','Enterprise rollout available'] as $chip)
                        <span class="hero-badge"><i class="ti ti-check"></i> {{ $chip }}</span>
                    @endforeach
                </div>
            </div>
            <div class="col-lg-5">
                <div class="hero-glow-card p-4 reveal">
                    <div class="text-white-50 small text-uppercase mb-2" style="letter-spacing:2px;">Plans at a glance</div>
                    @foreach($selfServicePlans->take(2) as $p)
                        <div class="d-flex justify-content-between align-items-center py-2" style="border-bottom:1px solid rgba(255,255,255,.12);">
                            <span class="text-white fw-semibold">{{ $p->name }}</span>
                            <span style="color:#e9c869;font-weight:700;">{{ $p->currency_code }} {{ number_format((float)($p->monthly_price ?? $p->price),0) }}<small class="text-white-50">/mo</small></span>
                        </div>
                    @endforeach
                    @foreach($customPlans as $p)
                        <div class="d-flex justify-content-between align-items-center py-2">
                            <span class="text-white fw-semibold">{{ $p->name }}</span>
                            <span style="color:#e9c869;font-weight:700;">Custom</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- TOGGLE --}}
<section class="section-pad pb-0">
    <div class="container text-center">
        <div class="bill-toggle reveal">
            <button id="btnMonthly" class="active" type="button">Monthly</button>
            <button id="btnYearly" type="button">Yearly <span class="badge bg-success-subtle text-success ms-1">2 months free</span></button>
        </div>
    </div>
</section>

{{-- PLAN CARDS --}}
<section class="section-pad">
    <div class="container">
        <div id="planGrid" class="row g-4 justify-content-center">
            @foreach($selfServicePlans as $plan)
                @php $popular = $plan->code === 'restaurant_pro'; @endphp
                <div class="col-md-6 col-lg-3">
                    <div class="plan-card bg-white p-4 h-100 d-flex flex-column hover-lift reveal {{ $popular ? 'plan-card-popular' : '' }}">
                        @if(isset($planBadges[$plan->code]))
                            <span class="plan-badge {{ $popular ? 'plan-badge-popular' : 'plan-badge-retail' }} mb-2 align-self-start">{{ $planBadges[$plan->code] }}</span>
                        @endif
                        <h4 class="fw-bold mb-1">{{ $plan->name }}</h4>
                        <p class="text-muted small flex-grow-1">{{ $plan->public_description }}</p>

                        <div class="price-monthly">
                            <div class="plan-price">{{ $plan->currency_code }} {{ number_format((float)($plan->monthly_price ?? $plan->price),0) }}</div>
                            <div class="text-muted mb-2">per month</div>
                        </div>
                        <div class="price-yearly">
                            <div class="plan-price">{{ $plan->currency_code }} {{ number_format((float)($plan->yearly_price ?? ($plan->monthly_price ?? $plan->price) * 10),0) }}</div>
                            <div class="text-muted mb-2">per year</div>
                        </div>

                        @if($plan->trial_days)
                            <span class="badge bg-success-subtle text-success align-self-start mb-3">{{ $plan->trial_days }}-day free trial</span>
                        @endif

                        <ul class="list-unstyled small mb-3">
                            <li class="mb-1"><i class="ti ti-building-store me-2 text-primary"></i>Branches: <strong>{{ $limitLabel($feature($plan,'branch_limit')) }}</strong></li>
                            <li class="mb-1"><i class="ti ti-device-desktop me-2 text-primary"></i>Terminals: <strong>{{ $limitLabel($feature($plan,'terminal_limit')) }}</strong></li>
                            <li class="mb-1"><i class="ti ti-users me-2 text-primary"></i>Users: <strong>{{ $limitLabel($feature($plan,'user_limit')) }}</strong></li>
                            <li class="mb-1"><i class="ti ti-box me-2 text-primary"></i>Products: <strong>{{ $limitLabel($feature($plan,'product_limit')) }}</strong></li>
                            <li class="mb-1"><i class="ti ti-stack-2 me-2 text-primary"></i>Modules: <strong>{{ $plan->enabledModules->count() }}</strong></li>
                        </ul>

                        <a href="{{ url('/start-trial?plan=' . $plan->code) }}" class="btn {{ $popular ? 'btn-primary' : 'btn-outline-primary' }} mb-2">Start 30-Day Trial</a>
                        <a href="{{ url('/demos') . (isset($demoAnchors[$plan->code]) ? '#' . $demoAnchors[$plan->code] : '') }}" class="btn btn-link btn-sm text-decoration-none">Try Demo</a>
                    </div>
                </div>
            @endforeach

            @foreach($customPlans as $plan)
                <div class="col-md-6 col-lg-3">
                    <div class="plan-card bg-white p-4 h-100 d-flex flex-column hover-lift reveal" style="border-color:#caa23f;">
                        <span class="plan-badge plan-badge-inv mb-2 align-self-start">Custom rollout</span>
                        <h4 class="fw-bold mb-1">{{ $plan->name }}</h4>
                        <p class="text-muted small flex-grow-1">{{ $plan->public_description }}</p>
                        <div class="fw-semibold fs-5 mb-1">Custom pricing</div>
                        <div class="text-muted mb-3">Tailored to your rollout</div>
                        <ul class="list-unstyled small mb-3">
                            <li class="mb-1"><i class="ti ti-building-store me-2 text-primary"></i>Branches: <strong>Unlimited</strong></li>
                            <li class="mb-1"><i class="ti ti-users me-2 text-primary"></i>Users: <strong>Unlimited</strong></li>
                            <li class="mb-1"><i class="ti ti-stack-2 me-2 text-primary"></i>Modules: <strong>All modules</strong></li>
                        </ul>
                        <a href="{{ url('/contact?plan=' . $plan->code) }}" class="btn btn-outline-primary mt-auto">Contact Sales</a>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="text-muted small text-center mt-4">Product limits are plan guidance and may be adjusted for custom deployments.</p>
    </div>
</section>

{{-- DEMO PREVIEW (live) --}}
<section id="demo-preview" class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">Live demos</span>
            <h2 class="fw-bold">Want to test before starting a trial?</h2>
            <p class="text-muted mx-auto" style="max-width:640px;">Open a live demo workspace for your business type and test real sample data before creating your own account.</p>
        </div>
        <div class="row g-3 justify-content-center mb-4">
            @foreach([
                ['ti-building-store','Retail Demo','retail'],
                ['ti-package','Inventory Demo','inventory'],
                ['ti-tools-kitchen-2','Restaurant Demo','restaurant'],
                ['ti-chef-hat','Restaurant Pro Demo','restaurant_pro'],
                ['ti-building-skyscraper','Enterprise Demo','enterprise'],
            ] as [$ico, $label, $anchor])
                <div class="col-6 col-md-4 col-lg-2">
                    <a href="{{ url('/demos') }}#{{ $anchor }}" class="text-decoration-none">
                        <div class="gradient-card p-3 h-100 text-center reveal hover-lift">
                            <div class="icon-wrap mx-auto mb-2"><i class="ti {{ $ico }}"></i></div>
                            <h6 class="fw-semibold small mb-2 text-dark">{{ $label }}</h6>
                            <span class="btn btn-sm btn-outline-primary">Try Demo</span>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
        <div class="text-center reveal">
            <a href="{{ url('/demos') }}" class="btn btn-primary px-4"><i class="ti ti-player-play me-1"></i>View all live demos</a>
        </div>
    </div>
</section>

{{-- COMPARISON --}}
<section class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">Compare</span>
            <h2 class="fw-bold">Compare plans by workflow</h2>
        </div>
        @php
            $comparePlans = $plans;
            $groups = [
                'Sales & Checkout'        => ['pos'=>'POS','catalog'=>'Catalog'],
                'Restaurant'             => ['restaurant'=>'Restaurant Tables','printing'=>'KOT Printing','kitchen_display'=>'Kitchen Display','kitchen_inventory'=>'Kitchen Inventory'],
                'Inventory & Purchasing' => ['inventory'=>'Inventory','purchasing'=>'Purchasing','stock_count'=>'Stock Count'],
                'Reports & Controls'     => ['reports'=>'Reports','sales_controls'=>'Sales Controls'],
                'SaaS & Team Access'     => ['multi_branch'=>'Multi Branch','users_roles'=>'Users & Roles'],
            ];
            $limitRows = ['branch_limit'=>'Branches','terminal_limit'=>'Terminals','user_limit'=>'Users','product_limit'=>'Products'];
        @endphp
        <div class="reveal table-responsive rounded-3 border">
            <table class="table comparison-tbl cmp-table align-middle mb-0 text-center bg-white">
                <thead class="table-light">
                    <tr>
                        <th>Feature</th>
                        @foreach($comparePlans as $plan)
                            <th class="{{ $plan->code==='restaurant_pro' ? 'cmp-hot' : '' }}">
                                {{ $plan->name }}
                                @if($plan->code==='restaurant_pro')<div><span class="plan-badge plan-badge-popular">Popular</span></div>@endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($groups as $groupName => $rows)
                        <tr class="cmp-group"><td colspan="{{ count($comparePlans)+1 }}">{{ $groupName }}</td></tr>
                        @foreach($rows as $key => $label)
                            <tr>
                                <td class="fw-semibold">{{ $label }}</td>
                                @foreach($comparePlans as $plan)
                                    <td class="{{ $plan->code==='restaurant_pro' ? 'cmp-hot' : '' }}">
                                        @if($plan->is_custom || $hasModule($plan,$key))
                                            <i class="ti ti-check check-yes"></i>
                                        @else
                                            <i class="ti ti-minus check-no"></i>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                    <tr class="cmp-group"><td colspan="{{ count($comparePlans)+1 }}">Limits</td></tr>
                    @foreach($limitRows as $key => $label)
                        <tr>
                            <td class="fw-semibold">{{ $label }}</td>
                            @foreach($comparePlans as $plan)
                                <td class="{{ $plan->code==='restaurant_pro' ? 'cmp-hot' : '' }}">{{ $limitLabel($feature($plan,$key)) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

{{-- ENTERPRISE / FBR CTA --}}
<section class="premium-section section-pad">
    <div class="container text-center reveal" style="max-width:760px;">
        <span class="hero-badge mb-3"><i class="ti ti-building-skyscraper"></i> Enterprise & custom</span>
        <h2 class="fw-bold text-white mb-3">Need multi-branch rollout, FBR setup, or custom onboarding?</h2>
        <p style="color:#94a3b8;" class="mb-4">Talk to our team about enterprise deployment, FBR-ready workflows for eligible Pakistan businesses, data migration, and team training.</p>
        <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="{{ url('/contact?plan=enterprise') }}" class="btn btn-light btn-lg px-4">Contact Sales</a>
            <a href="{{ url('/contact?topic=fbr') }}" class="btn btn-outline-light btn-lg px-4">Ask about FBR Setup</a>
        </div>
    </div>
</section>

{{-- FAQ --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-5 reveal">
                    <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">FAQ</span>
                    <h2 class="fw-bold">Pricing questions</h2>
                </div>
                <div class="accordion reveal" id="priceFaq">
                    @php $faqs = [
                        ['Can I upgrade later?','Yes. Request a plan upgrade any time from the billing portal — our team approves it and issues an upgrade invoice, and your plan changes automatically once paid.'],
                        ['Do I need to pay for the free trial?','No. The 30-day trial requires no payment details.'],
                        ['What happens after 30 days?','Your trial subscription lapses to past-due until you pay an invoice. Your data stays intact while you decide.'],
                        ['Can I switch from a Retail to a Restaurant plan?','Yes. Upgrades between public plans are supported through the billing portal.'],
                        ['Do you support multiple branches?','Restaurant Pro and Enterprise include multi-branch controls with per-branch terminals, users, shifts, and reports.'],
                        ['Is Enterprise custom priced?','Yes. Enterprise is a custom rollout — contact sales for pricing tailored to your scale.'],
                        ['Are demo environments available?','Yes. Live demos are available for retail, inventory, restaurant, restaurant pro, and enterprise workflows. Open the Demos page to try a workspace with sample data — no signup required.'],
                    ]; @endphp
                    @foreach($faqs as $i => [$q,$a])
                        <div class="accordion-item border-0 mb-2 rounded-3 overflow-hidden shadow-sm">
                            <h2 class="accordion-header">
                                <button class="accordion-button {{ $i>0?'collapsed':'' }} rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#pf{{ $i }}">{{ $q }}</button>
                            </h2>
                            <div id="pf{{ $i }}" class="accordion-collapse collapse {{ $i===0?'show':'' }}" data-bs-parent="#priceFaq">
                                <div class="accordion-body text-muted">{{ $a }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- FINAL CTA --}}
<section class="public-hero-premium section-pad">
    <div class="container text-center reveal" style="max-width:680px;">
        <h2 class="fw-bold text-white mb-3" style="font-size:2.2rem;">Start your free trial today</h2>
        <p class="mb-4" style="color:#cbd5e1;">Pick a plan and launch your workspace in minutes — no payment required.</p>
        <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="{{ url('/start-trial') }}" class="btn btn-light btn-lg px-5">Start 30-Day Free Trial</a>
            <a href="{{ url('/features') }}" class="btn btn-outline-light btn-lg px-5">Explore Features</a>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var grid = document.getElementById('planGrid');
    var m = document.getElementById('btnMonthly');
    var y = document.getElementById('btnYearly');
    if (!grid || !m || !y) return;
    m.addEventListener('click', function(){ grid.classList.remove('show-yearly'); m.classList.add('active'); y.classList.remove('active'); });
    y.addEventListener('click', function(){ grid.classList.add('show-yearly'); y.classList.add('active'); m.classList.remove('active'); });
});
</script>
@endpush
