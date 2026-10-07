{{--
    WEBSITE-I18N-GEO-1 P2 — per-branch pricing (Saudi Arabia, the UAE, Qatar, the US).
    Every figure here comes from PlanPricingService::quote() in the controller ($perBranch); the
    builder only multiplies prices the server already set, for display. The checkout prices the
    choice again on the server.
--}}
@include('public.partials.branch-quote-js')
@php
    $money = fn ($n) => \App\Support\PublicMoney::format((float) $n, $perBranch['currency']);
    $vat = $perBranch['vat_percent'];
    $builder = $perBranch['builder'];
    $hasBuilder = ! empty((array) $builder['map']);
@endphp

@push('styles')
<style>
    .pb-card .pb-price { font-size:2.1rem; font-weight:700; color:#11203f; line-height:1.15; font-variant-numeric:tabular-nums; }
    .pb-card .pb-per { color:#64748b; font-size:.875rem; }
    .pb-inc { background:#f8faff; border-radius:.6rem; padding:.6rem .8rem; font-size:.875rem; }
    .pb-builder .pb-choice { border:1px solid #e5e7eb; border-radius:.7rem; padding:.75rem 1rem; background:#fff; text-align:start; width:100%; }
    .pb-builder .pb-choice[aria-pressed="true"] { border-color:#caa23f; background:#fdf6e3; box-shadow:inset 0 0 0 1px #caa23f; }
    .pb-stepper { display:inline-flex; align-items:center; border:1px solid #e5e7eb; border-radius:.7rem; overflow:hidden; background:#fff; }
    .pb-stepper button { width:44px; height:44px; border:0; background:#f8faff; font-size:1.25rem; }
    .pb-stepper button:disabled { opacity:.4; }
    .pb-stepper output { min-width:64px; text-align:center; font-weight:700; font-size:1.1rem; font-variant-numeric:tabular-nums; }
    .pb-tier { font-size:.8rem; border:1px solid #e5e7eb; border-radius:999px; padding:.15rem .65rem; color:#64748b; background:#fff; }
    .pb-tier.on { border-color:#15803d; color:#15803d; background:#ecfdf3; font-weight:600; }
    .pb-receipt { background:#fffdf6; border:1px solid #e7dfc6; border-radius:.4rem; padding:1.4rem 1.2rem; position:sticky; top:96px; }
    .pb-receipt .row-line { display:flex; justify-content:space-between; gap:1rem; font-size:.95rem; }
    .pb-receipt .calc { display:block; color:#64748b; font-size:.8rem; }
    .pb-receipt .v { font-variant-numeric:tabular-nums; white-space:nowrap; }
    .pb-receipt hr { border-top:1px dashed #d9d2bd; opacity:1; }
    .pb-total { font-size:1.6rem; font-weight:700; font-variant-numeric:tabular-nums; }
</style>
@endpush

{{-- PER-BRANCH CARDS --}}
<section class="section-pad">
    <div class="container">
        <div id="planGrid" class="row g-4 justify-content-center">
            @foreach($perBranch['plans'] as $code => $p)
                @php $popular = $code === 'restaurant_pro'; @endphp
                <div class="col-md-6 col-lg-3">
                    <div class="plan-card pb-card bg-white p-4 h-100 d-flex flex-column hover-lift reveal {{ $popular ? 'plan-card-popular' : '' }}" data-plan-card="{{ $code }}">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small text-uppercase">{{ $p['biz'] === 'restaurant' ? __('Restaurant') : __('Retail') }}</span>
                            @if($popular)<span class="plan-badge plan-badge-popular">⭐ {{ __('Most Popular') }}</span>@endif
                        </div>
                        <h4 class="fw-bold mb-1">{{ __($p['name']) }}</h4>
                        <p class="text-muted small">{{ __((string) $p['description']) }}</p>

                        <div class="price-monthly">
                            <div class="pb-price">{{ $money($p['quote']['monthly_total']) }}</div>
                            <div class="pb-per">{{ __('per branch / month') }}@if($vat) · {{ __('+ :percent% VAT', ['percent' => $vat]) }}@endif</div>
                        </div>
                        <div class="price-yearly">
                            <div class="pb-price">{{ $money($p['quote']['yearly_total']) }}</div>
                            <div class="pb-per">{{ __('per branch / year') }} · <span class="text-success">{{ __('2 months free') }}</span>@if($vat) · {{ __('+ :percent% VAT', ['percent' => $vat]) }}@endif</div>
                        </div>

                        <div class="pb-inc my-3">
                            <div class="text-muted small">{{ __('Each branch includes') }}</div>
                            <div class="fw-semibold">{{ trans_choice(':count terminal|:count terminals', $p['terminals_per_branch']) }} · {{ trans_choice(':count user|:count users', $p['users_per_branch']) }}</div>
                            <div class="text-muted small">{{ __('Up to :count products on the account', ['count' => number_format($p['products'])]) }}</div>
                        </div>

                        <ul class="list-unstyled small mb-3 flex-grow-1">
                            @foreach($p['highlights'] as $h)
                                <li class="mb-1"><i class="ti ti-check text-success me-2"></i>{{ __($h) }}</li>
                            @endforeach
                        </ul>

                        <a href="{{ $lurl('/start-trial?plan=' . $code . '&market=' . $perBranch['market'] . '&branches=1&billing=monthly') }}" data-trial-cta data-plan="{{ $code }}"
                           class="btn {{ $popular ? 'btn-primary' : 'btn-outline-primary' }} mb-2">{{ __('Start 30-Day Trial') }}</a>
                        @if($hasBuilder)
                            <a href="#build" class="btn btn-link btn-sm text-decoration-none" data-customize="{{ $code }}">{{ __('Customize') }}</a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <p class="text-muted small text-center mt-4">{{ __('More than :count branches, or a custom setup?', ['count' => $builder['maxBranches']]) }} <a href="{{ $lurl('/contact?plan=enterprise') }}">{{ __('Contact Sales') }}</a></p>
    </div>
</section>

@if($hasBuilder)
{{-- BUILD YOUR PLAN --}}
<section id="build" class="section-pad pb-builder" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-4 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">{{ __('Custom plan') }}</span>
            <h2 class="fw-bold">{{ __('Build your plan') }}</h2>
            <p class="text-muted mx-auto" style="max-width:640px;">{{ __('Choose your business, the number of branches and any extra terminals. The total updates as you go.') }}</p>
        </div>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm"><div class="card-body p-4 d-grid gap-4">
                    <div>
                        <div class="fw-semibold mb-2">{{ __('Type of business') }}</div>
                        <div class="row g-2">
                            @foreach(['restaurant' => [__('Restaurant or café'), __('Tables, kitchen, delivery')], 'retail' => [__('Shop or store'), __('Checkout, stock, purchasing')]] as $biz => [$label, $sub])
                                @if(isset(((array) $builder['map'])[$biz]))
                                    <div class="col-6"><button type="button" class="pb-choice" id="pb-biz-{{ $biz }}" data-biz="{{ $biz }}"><div class="fw-semibold">{{ $label }}</div><small class="text-muted">{{ $sub }}</small></button></div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <div class="fw-semibold mb-2">{{ __('Plan') }}</div>
                        <div class="row g-2">
                            <div class="col-6"><button type="button" class="pb-choice" id="pb-tier-starter" data-tier="starter"><div class="fw-semibold" id="pb-tier-starter-name"></div><small class="text-muted" id="pb-tier-starter-price"></small></button></div>
                            <div class="col-6"><button type="button" class="pb-choice" id="pb-tier-pro" data-tier="pro"><div class="fw-semibold" id="pb-tier-pro-name"></div><small class="text-muted" id="pb-tier-pro-price"></small></button></div>
                        </div>
                    </div>
                    <div>
                        <div class="fw-semibold mb-2" id="pb-branches-label">{{ __('Branches') }}</div>
                        <div class="pb-stepper"><button type="button" id="pb-br-minus" aria-label="−">−</button><output id="pb-br-val" aria-labelledby="pb-branches-label" aria-live="polite">1</output><button type="button" id="pb-br-plus" aria-label="+">+</button></div>
                        <div class="d-flex flex-wrap gap-2 mt-2" id="pb-tiers">
                            <span class="pb-tier" data-pct="0">{{ __('From :from to :to branches: list price', ['from' => 1, 'to' => max(1, ($perBranch['discounts'][0][0] ?? 2) - 1)]) }}</span>
                            @foreach($perBranch['discounts'] as [$from, $to, $pct])
                                <span class="pb-tier" data-pct="{{ $pct }}">{{ __('From :from to :to branches: :percent% off', ['from' => $from, 'to' => $to, 'percent' => $pct]) }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <div class="fw-semibold mb-2" id="pb-extra-label">{{ __('Extra terminals') }}</div>
                        <div class="pb-stepper"><button type="button" id="pb-ex-minus" aria-label="−">−</button><output id="pb-ex-val" aria-labelledby="pb-extra-label" aria-live="polite">0</output><button type="button" id="pb-ex-plus" aria-label="+">+</button></div>
                        <div class="text-muted small mt-1" id="pb-extra-hint"></div>
                    </div>
                </div></div>
            </div>
            <div class="col-lg-5">
                <aside class="pb-receipt" aria-live="polite">
                    <div class="d-flex justify-content-between align-items-baseline">
                        <h5 class="fw-bold mb-0">{{ __('Your plan') }}</h5>
                        <span class="text-muted small">{{ $perBranch['currency'] }}</span>
                    </div>
                    <div class="text-muted small mb-2" id="pb-r-plan"></div>
                    <div id="pb-r-body"></div>
                </aside>
            </div>
        </div>
    </div>
</section>
@endif

@push('scripts')
<script>
(function () {
    var D = @json($builder);
    var Q = window.BingooQuote, $ = function (id) { return document.getElementById(id); };
    var bizList = Object.keys(D.map);
    var S = { biz: bizList.indexOf('restaurant') !== -1 ? 'restaurant' : bizList[0], tier: 'pro', branches: 1, extra: 0, billing: 'monthly' };

    function cardLink(code) {
        return D.checkout + '?plan=' + encodeURIComponent(code) + '&market=' + encodeURIComponent(D.market) + '&branches=1&billing=' + S.billing;
    }
    function setBilling(cycle) {
        S.billing = cycle;
        document.querySelectorAll('[data-trial-cta]').forEach(function (a) { a.setAttribute('href', cardLink(a.getAttribute('data-plan'))); });
        render();
    }
    var m = $('btnMonthly'), y = $('btnYearly'), grid = $('planGrid');
    if (m && y && grid) {
        m.addEventListener('click', function () { grid.classList.remove('show-yearly'); m.classList.add('active'); y.classList.remove('active'); setBilling('monthly'); });
        y.addEventListener('click', function () { grid.classList.add('show-yearly'); y.classList.add('active'); m.classList.remove('active'); setBilling('yearly'); });
    }
    if (!S.biz || !$('pb-r-body')) return;

    function plan() { return D.plans[D.map[S.biz][S.tier]]; }
    function checkoutUrl() {
        return D.checkout + '?plan=' + encodeURIComponent(D.map[S.biz][S.tier]) + '&market=' + encodeURIComponent(D.market)
            + '&branches=' + S.branches + '&terminals=' + S.extra + '&billing=' + S.billing;
    }
    function render() {
        if (!$('pb-r-body')) return;
        bizList.forEach(function (b) { var el = $('pb-biz-' + b); if (el) el.setAttribute('aria-pressed', String(S.biz === b)); });
        ['starter', 'pro'].forEach(function (tier) {
            var p = D.plans[D.map[S.biz][tier]];
            $('pb-tier-' + tier).setAttribute('aria-pressed', String(S.tier === tier));
            $('pb-tier-' + tier + '-name').textContent = p.name;
            $('pb-tier-' + tier + '-price').textContent = Q.money(D, p.unit) + ' ' + Q.t(D, 'per branch / month');
        });
        var over = S.branches > D.maxBranches, p = plan(), q = Q.quote(D, p, Math.min(S.branches, D.maxBranches), S.extra);
        $('pb-br-val').textContent = over ? D.maxBranches + '+' : String(S.branches);
        $('pb-br-minus').disabled = S.branches <= 1;
        $('pb-br-plus').disabled = over;
        $('pb-ex-val').textContent = String(S.extra);
        $('pb-ex-minus').disabled = S.extra <= 0;
        $('pb-ex-plus').disabled = S.extra >= 99;
        document.querySelectorAll('#pb-tiers .pb-tier').forEach(function (el) { el.classList.toggle('on', !over && Number(el.getAttribute('data-pct')) === q.pct); });
        $('pb-extra-hint').textContent = Q.t(D, 'Each branch already includes :count. Add more for busy counters.', { count: p.tpb });
        $('pb-r-plan').textContent = p.name + ' · ' + Q.t(D, S.billing === 'yearly' ? 'Yearly' : 'Monthly');
        var e = Q.esc;
        if (over) {
            $('pb-r-body').innerHTML = '<hr><div class="p-3 rounded" style="background:#fdf6e3"><strong>' + e(Q.t(D, 'More than :count branches?', { count: D.maxBranches })) + '</strong><br>'
                + e(Q.t(D, 'We will plan a custom rollout and price with you.')) + '</div><a href="' + e(D.contact) + '" class="btn btn-primary w-100 mt-3">' + e(Q.t(D, 'Contact Sales')) + '</a>';
            return;
        }
        var yearly = S.billing === 'yearly';
        var rows = '<div class="row-line mb-2"><span>' + e(Q.t(D, 'Branches')) + '<span class="calc">' + S.branches + ' × ' + e(Q.money(D, p.unit)) + '</span></span><span class="v">' + e(Q.money(D, q.branch)) + '</span></div>'
            + (S.extra ? '<div class="row-line mb-2"><span>' + e(Q.t(D, 'Extra terminals')) + '<span class="calc">' + S.extra + ' × ' + e(Q.money(D, p.extra)) + '</span></span><span class="v">' + e(Q.money(D, q.extra)) + '</span></div>' : '')
            + (q.pct ? '<div class="row-line mb-2"><span>' + e(Q.t(D, 'Subtotal')) + '</span><span class="v">' + e(Q.money(D, q.sub)) + '</span></div>'
                + '<div class="row-line mb-2 text-success"><span>' + e(Q.t(D, 'Multi-branch discount (:percent%)', { percent: q.pct })) + '</span><span class="v">' + e(Q.money(D, q.disc, true)) + '</span></div>' : '');
        $('pb-r-body').innerHTML = rows + '<hr>'
            + '<div class="row-line align-items-baseline"><span class="fw-semibold">' + e(Q.t(D, yearly ? 'Total per year' : 'Total per month')) + '</span><span class="pb-total" id="pb-total">' + e(Q.money(D, yearly ? q.year : q.month)) + '</span></div>'
            + (yearly ? '<div class="text-success small">' + e(Q.t(D, 'You save :amount a year with yearly billing', { amount: Q.money(D, q.saving) })) + '</div>' : '')
            + (D.vat ? '<div class="text-muted small">' + e(Q.t(D, '+ :percent% VAT', { percent: D.vat })) + '</div>' : '')
            + '<div class="d-flex flex-wrap gap-2 mt-3 small">'
            + '<span class="badge bg-light text-dark border">' + e(Q.t(D, 'Branches: :count', { count: S.branches })) + '</span>'
            + '<span class="badge bg-light text-dark border">' + e(Q.t(D, 'Terminals: :count', { count: q.terminals })) + '</span>'
            + '<span class="badge bg-light text-dark border">' + e(Q.t(D, 'Users: :count', { count: q.users })) + '</span>'
            + '<span class="badge bg-light text-dark border">' + e(Q.t(D, 'Up to :count products', { count: Q.fmt(p.products) })) + '</span></div>'
            + '<a href="' + e(checkoutUrl()) + '" class="btn btn-primary w-100 mt-3" id="pb-checkout">' + e(Q.t(D, 'Start 30-Day Trial')) + '</a>'
            + '<div class="text-muted small text-center mt-2">' + e(Q.t(D, 'No card needed. Nothing is charged during the trial.')) + '</div>';
    }
    document.querySelectorAll('[data-biz]').forEach(function (b) { b.addEventListener('click', function () { S.biz = b.getAttribute('data-biz'); render(); }); });
    document.querySelectorAll('[data-tier]').forEach(function (b) { b.addEventListener('click', function () { S.tier = b.getAttribute('data-tier'); render(); }); });
    $('pb-br-minus').addEventListener('click', function () { S.branches = Math.max(1, Math.min(S.branches, D.maxBranches + 1) - 1); render(); });
    $('pb-br-plus').addEventListener('click', function () { S.branches = Math.min(D.maxBranches + 1, S.branches + 1); render(); });
    $('pb-ex-minus').addEventListener('click', function () { S.extra = Math.max(0, S.extra - 1); render(); });
    $('pb-ex-plus').addEventListener('click', function () { S.extra = Math.min(99, S.extra + 1); render(); });
    document.querySelectorAll('[data-customize]').forEach(function (a) {
        a.addEventListener('click', function () {
            var code = a.getAttribute('data-customize');
            Object.keys(D.map).forEach(function (biz) { Object.keys(D.map[biz]).forEach(function (tier) { if (D.map[biz][tier] === code) { S.biz = biz; S.tier = tier; } }); });
            render();
        });
    });
    render();
})();
</script>
@endpush
