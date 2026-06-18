@extends('layouts.public')

@section('title', 'Terms of Service')
@section('meta_description', 'Bingoo POS Terms of Service — account responsibility, subscriptions, payments, data, and acceptable use for the cloud POS platform.')

@php
    $contact = config('saas.contact', []);
    $support = $contact['support_email'] ?? 'support@bingoopos.com';
    $sales   = $contact['sales_email'] ?? 'sales@bingoopos.com';
    $site    = str_replace(['https://','http://'], '', $contact['website'] ?? 'bingoopos.com');
@endphp

@section('content')
<section class="public-hero-premium" style="padding:3.5rem 0 2rem;position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;left:-30px;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <span class="hero-badge mb-3"><i class="ti ti-file-text"></i> Legal</span>
        <h1 class="fw-bold mb-2" style="font-size:2.1rem;">Terms of Service</h1>
        <p class="mb-0" style="color:#cbd5e1;">Last updated: June 2026</p>
    </div>
</section>

<section class="section-pad">
    <div class="container" style="max-width:880px;">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <div class="alert alert-light border small text-muted">
                    These terms are provided for general product use and may be updated as the service evolves.
                    For pilot customers, specific commercial terms may also be agreed separately in writing.
                </div>

                <h2 class="h5 fw-bold mt-4 mb-2">1. Introduction</h2>
                <p class="text-muted">These Terms of Service ("Terms") govern your use of Bingoo POS, a cloud point‑of‑sale platform operated at {{ $site }}. By creating an account, starting a trial, or using the service, you agree to these Terms. Bingoo POS provides operational tools for POS, inventory, restaurant, purchasing, and finance workflows.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">2. Eligibility and account responsibility</h2>
                <p class="text-muted">You must be authorized to act for your business to open an account. You are responsible for the accuracy of your registration details, for safeguarding your login credentials, and for all activity that occurs under your workspace. Notify us immediately of any unauthorized access.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">3. Tenant data and user access</h2>
                <p class="text-muted">Each business operates in its own isolated workspace ("tenant"). You control the users you invite and the roles/permissions you assign to them. You are responsible for managing access for your staff and for removing access when it is no longer needed. Your business data remains owned by you.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">4. Acceptable use</h2>
                <p class="text-muted">You agree not to misuse the service, including: attempting to access other tenants' data, probing or breaching security, uploading unlawful or malicious content, reselling the service without authorization, or using it to violate applicable laws. We may suspend access for conduct that threatens the platform or other customers.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">5. Subscription plans, trials, and billing</h2>
                <p class="text-muted">The service is offered on subscription plans, with an optional free trial. Trials may lapse to a past‑due state if no payment is made. Plan features, limits, and pricing are described on our pricing page and may change with notice. Continued use after a change constitutes acceptance of the updated plan terms.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">6. Manual payments and payment proof</h2>
                <p class="text-muted">Payments may be made by bank transfer or other agreed methods, with a payment proof uploaded for verification. Access to paid features depends on successful verification of payment. Payment proof files are used only to confirm payments (see our Privacy Policy).</p>

                <h2 class="h5 fw-bold mt-4 mb-2">7. Data backup and availability</h2>
                <p class="text-muted">We take reasonable measures to operate the service reliably and to maintain backups of platform data. However, the service is provided on an "as available" basis, and you are encouraged to retain your own copies/exports of critical records. We do not guarantee uninterrupted availability.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">8. Taxes, FBR, and compliance responsibility</h2>
                <p class="text-muted">You are solely responsible for verifying your own tax, accounting, and regulatory obligations. FBR‑related features, where available, are provided as configuration and support tools (FBR‑ready workflows and optional integration support) and do not make Bingoo POS an official FBR‑certified service unless separately confirmed in writing. We do not provide tax, legal, or accounting advice.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">9. Limitations of liability</h2>
                <p class="text-muted">To the maximum extent permitted by law, Bingoo POS is not liable for indirect, incidental, or consequential damages, or for loss of profits, data, or goodwill arising from use of the service. Our total liability for any claim is limited to the fees you paid for the service in the period giving rise to the claim.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">10. Suspension or termination</h2>
                <p class="text-muted">We may suspend or terminate access for non‑payment, breach of these Terms, or activity that endangers the platform. You may cancel at any time (see our Refund and Cancellation Policy). Upon termination, your right to use the service ends, subject to any data‑access window described in that policy.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">11. Changes to the service</h2>
                <p class="text-muted">We may add, modify, or discontinue features to improve the platform. Material changes to these Terms will be reflected by updating the "Last updated" date above; significant changes may also be communicated where appropriate.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">12. Contact</h2>
                <p class="text-muted mb-0">Questions about these Terms: <a href="mailto:{{ $support }}">{{ $support }}</a> (support) or <a href="mailto:{{ $sales }}">{{ $sales }}</a> (sales). See also our <a href="{{ url('/privacy') }}">Privacy Policy</a>, <a href="{{ url('/refund-policy') }}">Refund &amp; Cancellation Policy</a>, and <a href="{{ url('/support-policy') }}">Support Policy</a>.</p>
            </div>
        </div>
    </div>
</section>
@endsection
