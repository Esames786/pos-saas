@extends('layouts.public')

@section('title', 'Refund & Cancellation Policy')
@section('meta_description', 'Bingoo POS Refund and Cancellation Policy — trials, subscriptions, manual bank-transfer payments, refund eligibility, and cancellation process.')

@php
    $contact = config('saas.contact', []);
    $support = $contact['support_email'] ?? 'support@bingoopos.com';
    $sales   = $contact['sales_email'] ?? 'sales@bingoopos.com';
@endphp

@section('content')
<section class="public-hero-premium" style="padding:3.5rem 0 2rem;position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;left:-30px;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <span class="hero-badge mb-3"><i class="ti ti-receipt-refund"></i> Legal</span>
        <h1 class="fw-bold mb-2" style="font-size:2.1rem;">Refund &amp; Cancellation Policy</h1>
        <p class="mb-0" style="color:#cbd5e1;">Last updated: June 2026</p>
    </div>
</section>

<section class="section-pad">
    <div class="container" style="max-width:880px;">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <div class="alert alert-light border small text-muted">
                    This policy is provided for general product use and may be updated as the service evolves.
                    For pilot customers, specific commercial terms may also be agreed separately in writing.
                </div>

                <h2 class="h5 fw-bold mt-4 mb-2">1. Trials</h2>
                <p class="text-muted">Free trials require no payment. If a trial ends without payment, the subscription lapses to a past‑due state and the workspace becomes limited until payment is made. No charge applies to trials, so no refund is involved.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">2. Monthly / annual subscriptions</h2>
                <p class="text-muted">Subscriptions are billed for the chosen period (monthly or annual). Cancelling stops future renewals; access generally continues until the end of the period already paid for.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">3. Manual bank‑transfer payments</h2>
                <p class="text-muted">Where payment is made by bank transfer with an uploaded payment proof, the subscription period begins once payment is verified. Please keep your transaction reference in case verification questions arise.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">4. Refund eligibility</h2>
                <p class="text-muted"><strong>Refunds may be reviewed on a case‑by‑case basis.</strong> If you believe a charge was made in error or you experienced a qualifying service issue, contact us promptly with details and we will review the request fairly. Refunds are not automatic.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">5. Non‑refundable items</h2>
                <p class="text-muted">Custom onboarding, data migration, development, integration, or training work may be <strong>non‑refundable once started</strong>. Partial periods already used, and time‑and‑materials services already delivered, are generally non‑refundable.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">6. Cancellation process</h2>
                <p class="text-muted">To cancel, contact <a href="mailto:{{ $support }}">{{ $support }}</a> (or use the billing portal where available). We will confirm the cancellation and the effective date. Cancellation stops future renewals.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">7. Data access after cancellation</h2>
                <p class="text-muted">After cancellation, your workspace data is generally retained for a limited period during which you may request an export, after which it may be removed in line with our Privacy Policy. Please export critical records before cancelling.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">8. Contact</h2>
                <p class="text-muted mb-0">Billing and refund questions: <a href="mailto:{{ $support }}">{{ $support }}</a> or <a href="mailto:{{ $sales }}">{{ $sales }}</a>. See also our <a href="{{ url('/terms') }}">Terms of Service</a>.</p>
            </div>
        </div>
    </div>
</section>
@endsection
