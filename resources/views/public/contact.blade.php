@extends('layouts.public')

@section('title', 'Contact Sales')
@section('meta_description', 'Talk to Bingoo POS sales — book a demo, plan an enterprise rollout, ask about FBR-ready workflows, or plan a multi-branch deployment.')

@section('content')

{{-- HERO --}}
<section class="public-hero-premium" style="padding:4rem 0 2.5rem;position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;left:-30px;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <span class="hero-badge mb-3"><i class="ti ti-headset"></i> We're here to help</span>
        <h1 class="fw-bold mb-2" style="font-size:2.3rem;">Talk to Bingoo POS sales.</h1>
        <p class="lead mb-4 mx-auto" style="color:#cbd5e1;max-width:780px;">
            Book a demo, discuss enterprise rollout, ask about FBR-ready workflows, or plan a multi-branch POS deployment.
        </p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            @foreach(['Enterprise rollout','FBR setup discussion','Multi-branch planning','Training and onboarding','Data migration'] as $chip)
                <span class="hero-badge"><i class="ti ti-check"></i> {{ $chip }}</span>
            @endforeach
        </div>
        @if(request('plan') || request('topic'))
            <div class="d-inline-block mt-3">
                <span class="hero-badge">
                    <i class="ti ti-info-circle"></i>
                    @if(request('topic')==='fbr') Topic: FBR setup
                    @else Interested plan: {{ request('plan') }} @endif
                </span>
            </div>
        @endif
    </div>
</section>

{{-- REASON CARDS --}}
<section class="section-pad">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">How can we help?</span>
            <h2 class="fw-bold">Choose what you'd like to discuss</h2>
            <p class="text-muted mb-0">Try a live demo first, or contact sales for a guided walkthrough.
                <a href="{{ url('/demos') }}" class="fw-semibold text-decoration-none">View live demos &rarr;</a>
            </p>
        </div>
        <div class="row g-4">
            @foreach([
                ['ti-device-desktop','Book a Product Demo','See retail, restaurant, kitchen, and inventory workflows live with our team.'],
                ['ti-building-skyscraper','Enterprise / Multi-Branch Rollout','Plan centralized branches, terminals, users, and reporting at scale.'],
                ['ti-receipt-tax','FBR Setup Discussion','Ask how FBR-ready workflows can be planned for eligible Pakistan businesses.'],
                ['ti-database-import','Data Migration','Move products, customers, and stock from your current system.'],
                ['ti-school','Training & Onboarding','Get your cashiers, waiters, managers, and owners up to speed fast.'],
                ['ti-adjustments','Custom Plan','Need a tailored mix of modules, limits, or pricing? Let us help.'],
            ] as [$ico,$title,$desc])
                <div class="col-md-6 col-lg-4">
                    <div class="gradient-card p-4 h-100 reveal">
                        <div class="icon-wrap mb-3"><i class="ti {{ $ico }}"></i></div>
                        <h5 class="fw-bold mb-2">{{ $title }}</h5>
                        <p class="text-muted small mb-3">{{ $desc }}</p>
                        <a href="#contact-details" class="btn btn-sm btn-outline-primary">Contact us</a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- FBR + ENTERPRISE FEATURE BLOCK --}}
<section class="section-pad bg-white">
    <div class="container">
        <div class="fbr-section p-5 reveal">
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <span class="badge mb-3 d-inline-block" style="background:rgba(245,200,90,.15);color:#e9c869;border:1px solid rgba(245,200,90,.3);padding:.4rem 1rem;border-radius:8px;">
                        <i class="ti ti-flag me-1"></i>Pakistan & Enterprise
                    </span>
                    <h2 class="fw-bold text-white mb-3">Enterprise rollout & FBR-ready planning</h2>
                    <p style="color:#94a3b8;" class="mb-4">
                        For multi-branch businesses and eligible Pakistan retailers, we help plan deployment,
                        branch tax setup, and FBR-ready invoice workflows.
                    </p>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Multi-branch, terminals, and centralized reporting</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Branch tax registration & receipt configuration</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>FBR-ready invoice workflows (planned integration support)</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>Data migration, training, and onboarding</span></div>
                    <p class="mt-3 mb-0" style="color:#fbbf24;font-size:.8rem;">
                        Not an official FBR certification claim. Final compliance depends on business setup and official FBR requirements.
                    </p>
                </div>
                <div class="col-lg-5">
                    <div class="receipt-mock mx-auto" style="max-width:260px;">
                        <div class="text-center fw-bold">BINGOO POS</div>
                        <div class="text-center" style="font-size:.7rem;color:#64748b;">Sales Consultation</div>
                        <hr>
                        <div class="r-row"><span>Demo</span><span>Booked</span></div>
                        <div class="r-row"><span>Branches</span><span>Multi</span></div>
                        <div class="r-row"><span>FBR workflow</span><span style="color:#a87f24;">Planned</span></div>
                        <hr>
                        <div class="d-flex justify-content-center mt-2"><div class="qr-mock"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- CONTACT DETAILS --}}
<section id="contact-details" class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="gradient-card p-5 reveal text-center">
                    <div class="icon-wrap mx-auto mb-3"><i class="ti ti-mail-forward"></i></div>
                    <h3 class="fw-bold mb-2">Contact our sales team</h3>
                    <p class="text-muted mb-4">For now, reach us directly. An automated contact form will be added later.</p>
                    @php $contact = config('saas.contact', []); @endphp
                    <div class="row g-3 justify-content-center mb-3">
                        <div class="col-md-4">
                            <div class="p-3 rounded-3 border bg-white">
                                <i class="ti ti-mail text-primary mb-1" style="font-size:1.4rem;"></i>
                                <div class="small text-muted">Sales</div>
                                <div class="fw-semibold">{{ $contact['sales_email'] ?? 'sales@bingoopos.com' }}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 rounded-3 border bg-white">
                                <i class="ti ti-lifebuoy text-primary mb-1" style="font-size:1.4rem;"></i>
                                <div class="small text-muted">Support</div>
                                <div class="fw-semibold">{{ $contact['support_email'] ?? 'support@bingoopos.com' }}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 rounded-3 border bg-white">
                                <i class="ti ti-world text-primary mb-1" style="font-size:1.4rem;"></i>
                                <div class="small text-muted">Website</div>
                                <div class="fw-semibold">{{ str_replace(['https://','http://'], '', $contact['website'] ?? 'bingoopos.com') }}</div>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted small mb-4">
                        <i class="ti ti-brand-whatsapp me-1"></i>WhatsApp / Phone: <strong>{{ $contact['phone'] ?? '+92 XXX XXXXXXX' }}</strong>
                        <span class="d-block mt-1"><i class="ti ti-alert-triangle me-1"></i>Phone is a placeholder — replace before production.</span>
                    </p>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <a href="{{ url('/start-trial') }}" class="btn btn-primary">Start a Self-Service Trial</a>
                        <a href="{{ url('/demos') }}" class="btn btn-outline-primary">Try a Live Demo</a>
                        <a href="{{ url('/pricing') }}" class="btn btn-outline-primary">Back to Pricing</a>
                    </div>
                </div>

                @if($customPlans->count())
                    <div class="gradient-card p-4 mt-4 reveal">
                        <h6 class="fw-bold mb-3">Custom &amp; Enterprise plans</h6>
                        @foreach($customPlans as $plan)
                            <div class="mb-2"><span class="fw-semibold">{{ $plan->name }}</span> — <span class="text-muted small">{{ $plan->public_description }}</span></div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>

{{-- FAQ --}}
<section class="section-pad bg-white">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-5 reveal">
                    <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">FAQ</span>
                    <h2 class="fw-bold">Sales questions</h2>
                </div>
                <div class="accordion reveal" id="contactFaq">
                    @php $faqs = [
                        ['How soon can I get a demo?','Reach out via email or WhatsApp and our team will arrange a walkthrough of the workflows relevant to your business.'],
                        ['Can you migrate my existing data?','Yes — we help plan migration of products, customers, and stock during onboarding.'],
                        ['Do you support FBR-ready workflows?','We help eligible Pakistan businesses plan FBR-ready invoice workflows, branch tax setup, and receipt configuration. This is not an official FBR-certified integration.'],
                        ['Can I start without talking to sales?','Yes — self-service plans can be started instantly with a 30-day free trial. Sales is for enterprise, custom, and FBR planning.'],
                    ]; @endphp
                    @foreach($faqs as $i => [$q,$a])
                        <div class="accordion-item border-0 mb-2 rounded-3 overflow-hidden shadow-sm">
                            <h2 class="accordion-header">
                                <button class="accordion-button {{ $i>0?'collapsed':'' }} rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#cf{{ $i }}">{{ $q }}</button>
                            </h2>
                            <div id="cf{{ $i }}" class="accordion-collapse collapse {{ $i===0?'show':'' }}" data-bs-parent="#contactFaq">
                                <div class="accordion-body text-muted">{{ $a }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

@endsection
