@extends('layouts.public')

@section('title', 'Privacy Policy')
@section('meta_description', 'Bingoo POS Privacy Policy — what information we collect, how we use and protect business/tenant data and payment proof files, and your rights.')

@php
    $contact = config('saas.contact', []);
    $support = $contact['support_email'] ?? 'support@bingoopos.com';
    $site    = str_replace(['https://','http://'], '', $contact['website'] ?? 'bingoopos.com');
@endphp

@section('content')
<section class="public-hero-premium" style="padding:3.5rem 0 2rem;position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;left:-30px;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <span class="hero-badge mb-3"><i class="ti ti-shield-lock"></i> Legal</span>
        <h1 class="fw-bold mb-2" style="font-size:2.1rem;">Privacy Policy</h1>
        <p class="mb-0" style="color:#cbd5e1;">Last updated: June 2026</p>
    </div>
</section>

<section class="section-pad">
    <div class="container" style="max-width:880px;">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <div class="alert alert-light border small text-muted">
                    This policy is provided for general product use and may be updated as the service evolves.
                    For pilot customers, specific data terms may also be agreed separately in writing.
                </div>

                <p class="text-muted">This Privacy Policy explains how Bingoo POS ({{ $site }}) handles information when you use our cloud POS platform. <strong>We do not sell customer data.</strong></p>

                <h2 class="h5 fw-bold mt-4 mb-2">1. Information we collect</h2>
                <p class="text-muted">Account and contact details you provide at signup (business name, owner name, email, phone, chosen subdomain), authentication data, and basic technical/usage logs needed to operate and secure the service.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">2. Business / tenant data</h2>
                <p class="text-muted">Data you enter into your workspace — products, customers, suppliers, sales, inventory, purchasing, and finance records — is stored in your isolated tenant database. <strong>This business data remains owned by you</strong>; we process it on your behalf to provide the service.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">3. Payment proof files</h2>
                <p class="text-muted">When you upload a payment proof, it may contain bank/transaction reference details. These files are used <strong>only to verify payments</strong> for your subscription and are not used for any other purpose.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">4. How we use information</h2>
                <p class="text-muted">To create and operate your workspace, authenticate users, process and verify subscription payments, provide support, maintain security, and improve the platform. We do not sell or rent your data, and we do not use your tenant business data for advertising.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">5. How we protect information</h2>
                <p class="text-muted">We use per‑tenant data isolation, role‑based access controls, hashed passwords, and reasonable administrative and technical safeguards. No method of transmission or storage is 100% secure, but we work to protect your information appropriately.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">6. Backups and retention</h2>
                <p class="text-muted">We maintain backups of platform data to support recovery. Backup copies contain sensitive business data and are access‑restricted. Data is retained for as long as your account is active and for a reasonable period afterward to meet operational and legal needs, then removed or anonymized.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">7. Sharing with service providers</h2>
                <p class="text-muted">We may use trusted infrastructure providers (e.g. hosting, email delivery) strictly to operate the service. Such providers are bound to handle data only as needed to provide their service. We do not share your data with third parties for their own marketing.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">8. Customer responsibilities</h2>
                <p class="text-muted">You are responsible for the data you collect from your own customers, for configuring user access appropriately, and for complying with privacy and data‑protection laws applicable to your business and jurisdiction.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">9. Access and correction</h2>
                <p class="text-muted">You can access and correct most data directly within your workspace. For other requests regarding your account information, contact us using the details below.</p>

                <h2 class="h5 fw-bold mt-4 mb-2">10. Contact</h2>
                <p class="text-muted mb-0">Privacy questions: <a href="mailto:{{ $support }}">{{ $support }}</a>. See also our <a href="{{ url('/terms') }}">Terms of Service</a>.</p>
            </div>
        </div>
    </div>
</section>
@endsection
