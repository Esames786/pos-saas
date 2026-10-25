@extends('layouts.public')

@section('title', __('Contact Sales'))
@section('meta_description', __('Talk to Bingoo POS sales — book a demo, plan an enterprise rollout, ask about FBR-ready workflows, or plan a multi-branch deployment.'))

@section('content')
@php
    // WEBSITE-I18N-GEO-1: FBR is Pakistan's; until markets arrive (P2) it shows on default-language pages only.
    $pakistanSite = app()->getLocale() === \App\Support\PublicLocale::default();
@endphp

{{-- HERO --}}
<section class="public-hero-premium" style="padding:4rem 0 2.5rem;position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;inset-inline-start:-30px;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <span class="hero-badge mb-3"><i class="ti ti-headset"></i> {{ __("We're here to help") }}</span>
        <h1 class="fw-bold mb-2" style="font-size:2.3rem;">{{ __('Talk to Bingoo POS sales.') }}</h1>
        <p class="lead mb-4 mx-auto" style="color:#cbd5e1;max-width:780px;">
            {{ $pakistanSite
                ? __('Book a demo, discuss enterprise rollout, ask about FBR-ready workflows, or plan a multi-branch POS deployment.')
                : __('Book a demo, discuss an enterprise rollout, or plan a multi-branch POS deployment.') }}
        </p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            @foreach(array_filter([__('Enterprise rollout'), $pakistanSite ? __('FBR setup discussion') : null, __('Multi-branch planning'), __('Training and onboarding'), __('Data migration')]) as $chip)
                <span class="hero-badge"><i class="ti ti-check"></i> {{ $chip }}</span>
            @endforeach
        </div>
        @if(request('plan') || request('topic'))
            <div class="d-inline-block mt-3">
                <span class="hero-badge">
                    <i class="ti ti-info-circle"></i>
                    @if(request('topic')==='fbr') {{ __('Topic: FBR setup') }}
                    @else {{ __('Interested plan:') }} <bdi>{{ request('plan') }}</bdi> @endif
                </span>
            </div>
        @endif
    </div>
</section>

{{-- REASON CARDS --}}
<section class="section-pad">
    <div class="container">
        <div class="text-center mb-5 reveal">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">{{ __('How can we help?') }}</span>
            <h2 class="fw-bold">{{ __("Choose what you'd like to discuss") }}</h2>
            <p class="text-muted mb-0">{{ __('Try a live demo first, or contact sales for a guided walkthrough.') }}
                <a href="{{ $lurl('/demos') }}" class="fw-semibold text-decoration-none">{{ __('View live demos') }} <span class="dir-arrow">&rarr;</span></a>
            </p>
        </div>
        <div class="row g-4">
            @foreach(array_filter([
                ['ti-device-desktop', __('Book a Product Demo'), __('See retail, restaurant, kitchen, and inventory workflows live with our team.')],
                ['ti-building-skyscraper', __('Enterprise / Multi-Branch Rollout'), __('Plan centralized branches, terminals, users, and reporting at scale.')],
                $pakistanSite ? ['ti-receipt-tax', __('FBR Setup Discussion'), __('Ask how FBR-ready workflows can be planned for eligible Pakistan businesses.')] : null,
                ['ti-database-import', __('Data Migration'), __('Move products, customers, and stock from your current system.')],
                ['ti-school', __('Training & Onboarding'), __('Get your cashiers, waiters, managers, and owners up to speed fast.')],
                ['ti-adjustments', __('Custom Plan'), __('Need a tailored mix of modules, limits, or pricing? Let us help.')],
            ]) as [$ico,$title,$desc])
                <div class="col-md-6 col-lg-4">
                    <div class="gradient-card p-4 h-100 reveal">
                        <div class="icon-wrap mb-3"><i class="ti {{ $ico }}"></i></div>
                        <h5 class="fw-bold mb-2">{{ $title }}</h5>
                        <p class="text-muted small mb-3">{{ $desc }}</p>
                        {{-- CONTACT-FORM-1: card ab seedha form par le jata hai (aur mauzu pehle se chun deta hai). --}}
                        <a href="#contact-form" class="btn btn-sm btn-outline-primary">{{ __('Contact us') }}</a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- FBR + ENTERPRISE FEATURE BLOCK (Pakistan site only) --}}
@if($pakistanSite)
<section class="section-pad bg-white">
    <div class="container">
        <div class="fbr-section p-5 reveal">
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <span class="badge mb-3 d-inline-block" style="background:rgba(245,200,90,.15);color:#e9c869;border:1px solid rgba(245,200,90,.3);padding:.4rem 1rem;border-radius:8px;">
                        <i class="ti ti-flag me-1"></i>{{ __('Pakistan & Enterprise') }}
                    </span>
                    <h2 class="fw-bold text-white mb-3">{{ __('Enterprise rollout & FBR-ready planning') }}</h2>
                    <p style="color:#94a3b8;" class="mb-4">
                        {{ __('For multi-branch businesses and eligible Pakistan retailers, we help plan deployment, branch tax setup, and FBR-ready invoice workflows.') }}
                    </p>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>{{ __('Multi-branch, terminals, and centralized reporting') }}</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>{{ __('Branch tax registration & receipt configuration') }}</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>{{ __('FBR-ready invoice workflows (planned integration support)') }}</span></div>
                    <div class="fbr-bullet"><i class="ti ti-check-circle"></i><span>{{ __('Data migration, training, and onboarding') }}</span></div>
                    <p class="mt-3 mb-0" style="color:#fbbf24;font-size:.8rem;">
                        {{ __('Not an official FBR certification claim. Final compliance depends on business setup and official FBR requirements.') }}
                    </p>
                </div>
                <div class="col-lg-5">
                    <div class="receipt-mock mx-auto" style="max-width:260px;">
                        <div class="text-center fw-bold">BINGOO POS</div>
                        <div class="text-center" style="font-size:.7rem;color:#64748b;">{{ __('Sales Consultation') }}</div>
                        <hr>
                        <div class="r-row"><span>{{ __('Demo') }}</span><span>{{ __('Booked') }}</span></div>
                        <div class="r-row"><span>{{ __('Branches') }}</span><span>{{ __('Multi') }}</span></div>
                        <div class="r-row"><span>{{ __('FBR workflow') }}</span><span style="color:#a87f24;">{{ __('Planned') }}</span></div>
                        <hr>
                        <div class="d-flex justify-content-center mt-2"><div class="qr-mock"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endif

{{-- CONTACT FORM (CONTACT-FORM-1) --}}
<section id="contact-form" class="section-pad" style="background:#f8faff;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="gradient-card p-4 p-md-5 reveal">
                    <div class="text-center mb-4">
                        <div class="icon-wrap mx-auto mb-3"><i class="ti ti-message-2"></i></div>
                        <h3 class="fw-bold mb-2">{{ __('Send us a message') }}</h3>
                        <p class="text-muted mb-0">{{ __('Tell us what you need and our sales team will get back to you.') }}</p>
                    </div>

                    @if(session('contact_sent'))
                        <div class="alert alert-success" role="status">
                            <i class="ti ti-circle-check me-1"></i>
                            <strong>{{ __('Thank you — your message has reached us.') }}</strong>
                            <div class="small mt-1">{{ __('Our sales team will contact you shortly. We have also emailed you a confirmation.') }}</div>
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                        </div>
                    @endif

                    {{-- Usi zaban ke safhe par wapas post hota hai, taki ghaltiyan bhi usi zaban me aayein. --}}
                    <form method="POST" action="{{ $lurl('/contact') }}">
                        @csrf

                        {{-- Honeypot: asli visitor ko ye dikhta hi nahi; bot aksar har khana bhar dete hain. --}}
                        <div style="position:absolute;inset-inline-start:-9999px;" aria-hidden="true">
                            <label>Website</label>
                            <input type="text" name="website" tabindex="-1" autocomplete="off" value="{{ old('website') }}">
                        </div>

                        {{-- Jis plan ya mauzu ke link se aaya, wo saath chalta hai. --}}
                        <input type="hidden" name="plan_hint" value="{{ old('plan_hint', request('plan')) }}">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="cf-name" class="form-label">{{ __('Your name') }} <span class="text-danger">*</span></label>
                                <input id="cf-name" type="text" name="name" value="{{ old('name') }}" required maxlength="255"
                                       autocomplete="name" class="form-control @error('name') is-invalid @enderror">
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="cf-business" class="form-label">{{ __('Business name') }}</label>
                                <input id="cf-business" type="text" name="business_name" value="{{ old('business_name') }}" maxlength="255"
                                       autocomplete="organization" class="form-control @error('business_name') is-invalid @enderror">
                                @error('business_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="cf-email" class="form-label">{{ __('Email') }} <span class="text-danger">*</span></label>
                                <input id="cf-email" type="email" name="email" value="{{ old('email') }}" required maxlength="255"
                                       autocomplete="email" dir="ltr" class="form-control @error('email') is-invalid @enderror">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="cf-phone" class="form-label">{{ __('Phone / WhatsApp') }}</label>
                                <input id="cf-phone" type="text" name="phone" value="{{ old('phone') }}" maxlength="50"
                                       autocomplete="tel" dir="ltr" class="form-control @error('phone') is-invalid @enderror">
                                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <label for="cf-topic" class="form-label">{{ __('What is this about?') }}</label>
                                @php
                                    // ?topic=fbr jaise link se aaya to wohi pehle se chuna hua.
                                    $topics = \App\Models\Master\ContactEnquiry::TOPICS;
                                    if (! $pakistanSite) { unset($topics['fbr']); }
                                    $selectedTopic = old('topic', request('topic'));
                                @endphp
                                <select id="cf-topic" name="topic" class="form-select @error('topic') is-invalid @enderror">
                                    <option value="">{{ __('Choose a topic') }}</option>
                                    @foreach($topics as $key => $label)
                                        <option value="{{ $key }}" @selected($selectedTopic === $key)>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                                @error('topic')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <label for="cf-message" class="form-label">{{ __('Your message') }} <span class="text-danger">*</span></label>
                                <textarea id="cf-message" name="message" rows="5" required minlength="10" maxlength="5000"
                                          class="form-control @error('message') is-invalid @enderror"
                                          placeholder="{{ __('How many branches and counters? What kind of business? Anything else we should know?') }}">{{ old('message') }}</textarea>
                                @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        {{-- RECAPTCHA-TRIAL-1 ka wohi box, wohi shart: sirf jab dono keys lagi hon.

                             🔴 Yahan `@php(...)` wala chhota form JAAN BOOJH KAR istemal NAHI hota.
                             Blade pehle `/@php(.*?)@endphp/s` chalata hai: chhote form ka `@php`
                             us ke BAAD aane wale kisi bhi `@endphp` se jurr jata hai. Is safhe par
                             neeche ek asli `@php … @endphp` block hai, to darmiyan ka poora HTML
                             raw block ban kar literal text ban gaya — `$hasPhone` kabhi set hi
                             nahi hua aur safha 500 de gaya. Compiled PHP phir bhi `php -l` paas
                             karta hai (wo bas directives ko TEXT ki tarah chhapta hai), is liye
                             ye sirf safha render kar ke pakRa jata hai. --}}
                        @php $recaptchaKey = \App\Services\Saas\Recaptcha::siteKey(); @endphp
                        @if ($recaptchaKey)
                            <div class="recaptcha-box mt-4">
                                <div class="g-recaptcha" data-sitekey="{{ $recaptchaKey }}"></div>
                            </div>
                            @push('scripts')
                                <script src="https://www.google.com/recaptcha/api.js?hl={{ app()->getLocale() }}" async defer></script>
                            @endpush
                            @push('styles')
                                <style>
                                    /* Google draws the box at a fixed 304px; inside the card's padding a small
                                       phone has less, so it is scaled (not clipped) below 420px. */
                                    .recaptcha-box { display:flex; justify-content:center; min-height:78px; }
                                    @media (max-width: 420px) {
                                        .recaptcha-box { min-height:68px; }
                                        .recaptcha-box .g-recaptcha { transform:scale(.86); transform-origin:top center; }
                                    }
                                </style>
                            @endpush
                        @endif

                        <button type="submit" class="btn btn-primary btn-lg w-100 mt-4">
                            <i class="ti ti-send me-2"></i>{{ __('Send message') }}
                        </button>
                        <p class="text-muted small text-center mt-3 mb-0">
                            {{ __('We use your details only to answer this enquiry.') }}
                        </p>
                    </form>
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
                    <h3 class="fw-bold mb-2">{{ __('Contact our sales team') }}</h3>
                    <p class="text-muted mb-4">{{ __('Send us a message below, or reach us directly — whichever is easier.') }}</p>
                    @php
                        $contact = config('saas.contact', []);
                        // CONTACT-FORM-1: SAAS_CONTACT_PHONE khaali ho to number ka khana dikhta hi
                        // nahi — jhoota "+92 XXX XXXXXXX" chhapne se behtar.
                        $numbers = \App\Support\ContactNumbers::all();
                    @endphp
                    <div class="row g-3 justify-content-center mb-3">
                        <div class="col-md-4">
                            <div class="p-3 rounded-3 border bg-white">
                                <i class="ti ti-mail text-primary mb-1" style="font-size:1.4rem;"></i>
                                <div class="small text-muted">{{ __('Sales') }}</div>
                                <div class="fw-semibold"><bdi dir="ltr">{{ $contact['sales_email'] ?? 'sales@bingoopos.com' }}</bdi></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 rounded-3 border bg-white">
                                <i class="ti ti-lifebuoy text-primary mb-1" style="font-size:1.4rem;"></i>
                                <div class="small text-muted">{{ __('Support') }}</div>
                                <div class="fw-semibold"><bdi dir="ltr">{{ $contact['support_email'] ?? 'support@bingoopos.com' }}</bdi></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 rounded-3 border bg-white">
                                <i class="ti ti-world text-primary mb-1" style="font-size:1.4rem;"></i>
                                <div class="small text-muted">{{ __('Website') }}</div>
                                <div class="fw-semibold"><bdi dir="ltr">{{ str_replace(['https://','http://'], '', $contact['website'] ?? 'bingoopos.com') }}</bdi></div>
                            </div>
                        </div>
                    </div>
                    @if($numbers)
                        {{-- Number sirf likha hua nahi — phone par dabate hi WhatsApp khul jata hai. --}}
                        <div class="d-flex flex-wrap justify-content-center gap-2 mb-4">
                            @foreach($numbers as $number)
                                <a href="{{ $number['wa'] }}" target="_blank" rel="noopener"
                                   class="btn btn-sm d-inline-flex align-items-center gap-2"
                                   style="background:#25D366;color:#06331C;font-weight:600;">
                                    <i class="ti ti-brand-whatsapp"></i>
                                    <bdi dir="ltr">{{ $number['display'] }}</bdi>
                                </a>
                            @endforeach
                        </div>
                    @endif
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <a href="{{ $lurl('/start-trial') }}" class="btn btn-primary">{{ __('Start a Self-Service Trial') }}</a>
                        <a href="{{ $lurl('/demos') }}" class="btn btn-outline-primary">{{ __('Try a Live Demo') }}</a>
                        <a href="{{ $lurl('/pricing') }}" class="btn btn-outline-primary">{{ __('Back to Pricing') }}</a>
                    </div>
                </div>

                @if($customPlans->count())
                    <div class="gradient-card p-4 mt-4 reveal">
                        <h6 class="fw-bold mb-3">{{ __('Custom & Enterprise plans') }}</h6>
                        @foreach($customPlans as $plan)
                            <div class="mb-2"><span class="fw-semibold">{{ __($plan->name) }}</span> — <span class="text-muted small">{{ __((string) $plan->public_description) }}</span></div>
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
                    <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-2 mb-3 d-inline-block">{{ __('FAQ') }}</span>
                    <h2 class="fw-bold">{{ __('Sales questions') }}</h2>
                </div>
                <div class="accordion reveal" id="contactFaq">
                    @php $faqs = array_values(array_filter([
                        [__('How soon can I get a demo?'), __('Reach out via email or WhatsApp and our team will arrange a walkthrough of the workflows relevant to your business.')],
                        [__('Can you migrate my existing data?'), __('Yes — we help plan migration of products, customers, and stock during onboarding.')],
                        $pakistanSite ? [__('Do you support FBR-ready workflows?'), __('We help eligible Pakistan businesses plan FBR-ready invoice workflows, branch tax setup, and receipt configuration. This is not an official FBR-certified integration.')] : null,
                        [__('Can I start without talking to sales?'), $pakistanSite
                            ? __('Yes — self-service plans can be started instantly with a 30-day free trial. Sales is for enterprise, custom, and FBR planning.')
                            : __('Yes — self-service plans can be started instantly with a 30-day free trial. Sales is for enterprise and custom rollouts.')],
                    ])); @endphp
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
