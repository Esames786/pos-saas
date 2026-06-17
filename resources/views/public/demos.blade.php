@extends('layouts.public')

@section('title', 'Live Demos')
@section('meta_description', 'Open a live Bingoo POS demo workspace by business type — retail, inventory, restaurant, restaurant pro, and multi-branch enterprise. Test real workflows with sample data, no signup required.')

@section('content')

@push('styles')
<style>
    .demo-card { scroll-margin-top: 96px; }
    .demo-card .demo-img { width:100%; height:200px; object-fit:cover; display:block; }
    .demo-cred {
        background:#0f172a; color:#e2e8f0; border-radius:14px;
        padding:.9rem 1rem; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-size:.82rem; line-height:1.7; border:1px solid rgba(200,155,60,.25);
    }
    .demo-cred .lbl { color:#94a3b8; }
    .demo-cred .val { color:#e9c869; font-weight:600; }
    .demo-cred .copy-btn {
        background:rgba(245,200,90,.12); border:1px solid rgba(245,200,90,.3); color:#e9c869;
        border-radius:8px; padding:.1rem .5rem; font-size:.7rem; cursor:pointer; line-height:1.4;
    }
    .demo-cred .copy-btn:hover { background:rgba(245,200,90,.22); }
    .demo-status-on  { background:#dcfce7; color:#166534; }
    .demo-status-off { background:#fef3c7; color:#92400e; }
</style>
@endpush

{{-- ═══════════ 1. HERO ═══════════ --}}
<section class="public-hero-premium section-pad" style="position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;left:-40px;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <span class="hero-badge mb-3"><i class="ti ti-player-play"></i> Live demo workspaces</span>
        <h1 class="fw-bold mb-3 mx-auto" style="font-size:2.5rem;max-width:820px;">Try Bingoo POS by business type.</h1>
        <p class="lead mb-4 mx-auto" style="color:#cbd5e1;max-width:780px;">
            Open a live demo workspace, test real workflows with sample data, then start your own
            30-day trial when you are ready.
        </p>
        <div class="d-flex flex-wrap justify-content-center gap-2 mb-4">
            @foreach([
                'No signup required',
                'Safe demo data',
                'Retail + restaurant + inventory',
                'Built-in accounting (Pro & Enterprise)',
                'Multi-branch enterprise demo',
                'Reset-ready demo environments',
            ] as $chip)
                <span class="hero-badge"><i class="ti ti-check"></i> {{ $chip }}</span>
            @endforeach
        </div>
        <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="{{ url('/start-trial') }}" class="btn btn-light btn-lg px-4 fw-semibold"><i class="ti ti-rocket me-2"></i>Start 30-Day Trial</a>
            <a href="{{ url('/pricing') }}" class="btn btn-outline-light btn-lg px-4">View Pricing</a>
        </div>
    </div>
</section>

{{-- ═══════════ 2. TRUST / REASSURANCE STRIP ═══════════ --}}
<div class="trust-strip py-3">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-center gap-4 py-1">
            <div class="trust-item"><i class="ti ti-lock-open"></i> Open instantly — no account</div>
            <div class="trust-item"><i class="ti ti-database-cog"></i> Pre-loaded sample data</div>
            <div class="trust-item"><i class="ti ti-shield-check"></i> Restricted, safe demo accounts</div>
            <div class="trust-item"><i class="ti ti-refresh"></i> Demo environments may be reset</div>
        </div>
    </div>
</div>

{{-- ═══════════ 3. LIVE DEMO CARDS ═══════════ --}}
<section class="section-pad">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">Choose your demo</span>
            <h2 class="fw-bold">Five live workspaces, one for each business type</h2>
            <p class="text-muted">Pick the demo closest to your business and open it in a new tab.</p>
        </div>

        <div class="row g-4">
            @foreach($demos as $demo)
                <div class="col-md-6 col-lg-4" id="{{ $demo['key'] }}">
                    <div class="demo-card image-card bg-white shadow-sm hover-lift reveal h-100 d-flex flex-column">
                        <div style="position:relative;">
                            <img src="{{ asset($demo['image']) }}" alt="{{ $demo['title'] }}" class="demo-img">
                            <span class="badge-tag">{{ $demo['badge'] }}</span>
                            <span class="position-absolute plan-badge {{ $demo['available'] ? 'demo-status-on' : 'demo-status-off' }}" style="top:12px;right:12px;">
                                {{ $demo['available'] ? 'Live' : 'Preparing' }}
                            </span>
                        </div>
                        <div class="card-body d-flex flex-column flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <div class="icon-wrap" style="width:42px;height:42px;margin:0;"><i class="ti {{ $demo['icon'] }}"></i></div>
                                <h4 class="fw-bold mb-0">{{ $demo['title'] }}</h4>
                            </div>
                            <p class="text-muted small mb-3">{{ $demo['description'] }}</p>

                            <div class="fw-semibold small text-uppercase text-muted mb-2" style="letter-spacing:.5px;">What you can test</div>
                            <ul class="list-unstyled small mb-3">
                                @foreach($demo['bullets'] as $bullet)
                                    <li class="mb-1"><i class="ti ti-check text-success me-2"></i>{{ $bullet }}</li>
                                @endforeach
                            </ul>

                            {{-- credentials --}}
                            <div class="demo-cred mb-3 mt-auto">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span><span class="lbl">Email:</span> <span class="val" data-copy="{{ $demo['email'] }}">{{ $demo['email'] }}</span></span>
                                    <button type="button" class="copy-btn" data-copy-text="{{ $demo['email'] }}">Copy</button>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span><span class="lbl">Password:</span> <span class="val" data-copy="{{ $demo['password'] }}">{{ $demo['password'] }}</span></span>
                                    <button type="button" class="copy-btn" data-copy-text="{{ $demo['password'] }}">Copy</button>
                                </div>
                            </div>

                            {{-- actions --}}
                            <div class="d-grid gap-2">
                                @if($demo['available'])
                                    <a href="{{ $demo['login_url'] }}" target="_blank" rel="noopener" class="btn btn-primary">
                                        <i class="ti ti-external-link me-1"></i>Open Demo
                                    </a>
                                @else
                                    <button class="btn btn-secondary" disabled>Preparing Demo</button>
                                @endif

                                @if(($demo['cta_type'] ?? 'trial') === 'contact')
                                    <a href="{{ url('/contact?plan=' . ($demo['cta_plan'] ?? 'enterprise')) }}" class="btn btn-outline-primary btn-sm">Contact Sales</a>
                                @else
                                    <a href="{{ url('/start-trial?plan=' . ($demo['cta_plan'] ?? '')) }}" class="btn btn-outline-primary btn-sm">Start Trial</a>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="text-muted small text-center mt-4">
            <i class="ti ti-info-circle me-1"></i>Public demo accounts are restricted. Billing, password changes, destructive actions, and admin-only mutations are disabled.
        </p>
    </div>
</section>

{{-- ═══════════ 4. HOW TO USE A DEMO ═══════════ --}}
<section class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">How it works</span>
            <h2 class="fw-bold">How to use a demo</h2>
        </div>
        <div class="row g-4 justify-content-center">
            @foreach([
                ['Pick your demo','Choose the demo closest to your business type above.'],
                ['Open in a new tab','Click Open Demo — the login page opens in a new tab.'],
                ['Log in','Enter the demo email and password shown on the card.'],
                ['Test workflows','Explore sales, inventory, restaurant, and reports with sample data.'],
                ['Start your trial','Come back to start your own 30-day trial or contact sales.'],
            ] as $i => [$title,$desc])
                <div class="col-md-6 col-lg-4">
                    <div class="gradient-card p-4 h-100 reveal d-flex gap-3">
                        <div class="step-number flex-shrink-0" style="width:46px;height:46px;font-size:1.2rem;">{{ $i + 1 }}</div>
                        <div>
                            <div class="fw-bold mb-1">{{ $title }}</div>
                            <div class="text-muted small">{{ $desc }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════ 5. SAFETY / DISCLAIMER ═══════════ --}}
<section class="section-pad bg-white">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9">
                <div class="fbr-section p-5 reveal">
                    <div class="row align-items-center g-4">
                        <div class="col-lg-8">
                            <span class="badge mb-3 d-inline-block" style="background:rgba(245,200,90,.15);color:#e9c869;border:1px solid rgba(245,200,90,.3);padding:.4rem 1rem;border-radius:8px;">
                                <i class="ti ti-shield-lock me-1"></i>About these demos
                            </span>
                            <h2 class="fw-bold text-white mb-3">Safe to explore, built for testing</h2>
                            <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>All data is sample data created for demonstration only — not real customers or transactions.</span></div>
                            <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Public demo accounts are restricted: billing, password changes, destructive actions, and admin-only mutations are disabled.</span></div>
                            <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Demo environments may be reset at any time, so changes you make are not permanent.</span></div>
                            <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>When you start your own trial, you get a private workspace with your own data and full owner access.</span></div>
                        </div>
                        <div class="col-lg-4">
                            <div class="receipt-mock mx-auto" style="max-width:240px;">
                                <div class="text-center fw-bold">BINGOO POS</div>
                                <div class="text-center" style="font-size:.7rem;color:#64748b;">Demo Workspace</div>
                                <hr>
                                <div class="r-row"><span>Mode</span><span>Demo</span></div>
                                <div class="r-row"><span>Data</span><span>Sample</span></div>
                                <div class="r-row"><span>Account</span><span style="color:#a87f24;">Restricted</span></div>
                                <hr>
                                <div class="text-center" style="font-size:.7rem;color:#64748b;">Reset-ready environment</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════ 6. FINAL CTA ═══════════ --}}
<section class="public-hero-premium section-pad">
    <div class="container text-center reveal" style="max-width:700px;">
        <h2 class="fw-bold text-white mb-3" style="font-size:2.2rem;">Ready to create your own workspace?</h2>
        <p class="mb-4" style="color:#cbd5e1;">Start a private 30-day trial with your own data, or talk to our team about an enterprise rollout.</p>
        <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="{{ url('/start-trial') }}" class="btn btn-light btn-lg px-4">Start 30-Day Trial</a>
            <a href="{{ url('/pricing') }}" class="btn btn-outline-light btn-lg px-4">Compare Plans</a>
            <a href="{{ url('/contact') }}" class="btn btn-outline-light btn-lg px-4">Talk to Sales</a>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy-text') || '';
            var done = function () {
                var original = btn.textContent;
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = original; }, 1400);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = text; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta); done();
            }
        });
    });
});
</script>
@endpush
