@extends('layouts.public')

@section('title', __('Privacy Policy'))
@section('meta_description', __('Bingoo POS Privacy Policy — what information we collect, how we use and protect business/tenant data and payment proof files, and your rights.'))

@php
    $contact = config('saas.contact', []);
    $support = $contact['support_email'] ?? 'support@bingoopos.com';
    $sales   = $contact['sales_email'] ?? 'sales@bingoopos.com';
    $site    = str_replace(['https://','http://'], '', $contact['website'] ?? 'bingoopos.com');
    $phone   = $contact['phone'] ?? null;
    // WEBSITE-I18N-GEO-1: a legal text is translated as a whole page, not sentence by sentence
    // (public/legal/<locale>/privacy). A language without its own copy shows the English one.
    $legalLocale = view()->exists('public.legal.' . app()->getLocale() . '.privacy') ? app()->getLocale() : 'en';
@endphp

@section('content')
<section class="public-hero-premium" style="padding:3.5rem 0 2rem;position:relative;overflow:hidden;">
    <div class="mega-glow" style="top:-90px;inset-inline-start:-30px;background:#caa23f;"></div>
    <div class="container text-center" style="position:relative;z-index:2;">
        <span class="hero-badge mb-3"><i class="ti ti-shield-lock"></i> {{ __('Legal') }}</span>
        <h1 class="fw-bold mb-2" style="font-size:2.1rem;">{{ __('Privacy Policy') }}</h1>
        <p class="mb-0" style="color:#cbd5e1;">{{ __('Last updated: :date', ['date' => __('June 2026')]) }}</p>
    </div>
</section>

<section class="section-pad">
    <div class="container" style="max-width:880px;">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                @include('public.legal.' . $legalLocale . '.privacy')
            </div>
        </div>
    </div>
</section>
@endsection
