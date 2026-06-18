@extends('layouts.public')

@section('title', 'Support Policy')
@section('meta_description', 'Bingoo POS Support Policy — support channels, hours, what support covers, response expectations, and how to reach the team.')

@php
    $contact = config('saas.contact', []);
    $support = $contact['support_email'] ?? 'support@bingoopos.com';
    $sales   = $contact['sales_email'] ?? 'sales@bingoopos.com';
    $phone   = $contact['phone'] ?? null;
@endphp

@section('content')
<section class="public-hero-premium" style="padding:3.5rem 0 2rem;position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;left:-30px;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <span class="hero-badge mb-3"><i class="ti ti-lifebuoy"></i> Legal</span>
        <h1 class="fw-bold mb-2" style="font-size:2.1rem;">Support Policy</h1>
        <p class="mb-0" style="color:#cbd5e1;">Last updated: June 2026</p>
    </div>
</section>

<section class="section-pad">
    <div class="container" style="max-width:880px;">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <div class="alert alert-light border small text-muted">
                    This policy describes general support expectations and may be updated as the service evolves.
                    Pilot or enterprise customers may have specific support terms agreed separately in writing.
                </div>

                <h2 class="h5 fw-bold mt-4 mb-2">1. Support channels</h2>
                <p class="text-muted">
                    Email support: <a href="mailto:{{ $support }}">{{ $support }}</a><br>
                    Sales / commercial: <a href="mailto:{{ $sales }}">{{ $sales }}</a>
                    @if($phone)<br>WhatsApp / Phone: <strong>{{ $phone }}</strong>@endif
                </p>

                <h2 class="h5 fw-bold mt-4 mb-2">2. Support hours</h2>
                <p class="text-muted">Support is provided during business hours (Pakistan time), Monday to Saturday, excluding public holidays. Messages received outside these hours are addressed on the next business day.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">3. What support covers</h2>
                <p class="text-muted">Account and login assistance, guidance on using POS, inventory, restaurant, purchasing, and finance features, help interpreting reports, billing/subscription questions, and investigation of suspected platform issues or bugs.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">4. What support does not cover</h2>
                <p class="text-muted">Tax, legal, or accounting advice; data entry on your behalf; custom development or integrations (available as separate paid work); third‑party hardware/network issues; and issues caused by misuse or unauthorized modifications. FBR/tax configuration help is provided as guidance only — you remain responsible for your compliance obligations.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">5. Response expectations</h2>
                <p class="text-muted">We aim to acknowledge support requests within one business day and to work issues by priority. These are targets, not guarantees, unless a specific service‑level agreement is agreed in writing.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">6. Customer responsibilities</h2>
                <p class="text-muted">To help us resolve issues quickly, please provide your workspace/subdomain, clear steps to reproduce, screenshots where relevant, and the affected dates/records. Keep your contact details and user access up to date.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">7. Emergency / critical issues</h2>
                <p class="text-muted">For critical issues that prevent core operations (e.g. unable to log in or take sales), mark your message as urgent and include your subdomain and a description of the impact so we can prioritize it.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">8. Contact</h2>
                <p class="text-muted mb-0">Reach support at <a href="mailto:{{ $support }}">{{ $support }}</a>. See also our <a href="{{ url('/terms') }}">Terms of Service</a> and <a href="{{ url('/refund-policy') }}">Refund &amp; Cancellation Policy</a>.</p>
            </div>
        </div>
    </div>
</section>
@endsection
