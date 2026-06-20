@extends('layouts.public')

@section('title', 'Coming Soon')
@section('meta_description', 'Bingoo is getting ready. Our public website is being polished for launch.')

@section('content')
<section class="public-hero-premium" style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:3rem 1rem;">
    <div class="container text-center">

        {{-- Logo --}}
        <a href="{{ url('/') }}" class="d-inline-block mb-4">
            <img src="{{ asset(config('saas.brand_logo', 'images/bingoo_new/bingoo-navbar-logo.webp')) }}"
                 alt="{{ config('saas.brand_name', 'Bingoo') }}"
                 style="height:56px;filter:brightness(0) invert(1);">
        </a>

        {{-- Badge --}}
        <div class="mb-3">
            <span class="hero-badge">
                <span class="badge-dot"></span>
                Getting Ready
            </span>
        </div>

        {{-- Headline --}}
        <h1 class="display-5 fw-bold text-white mb-3">
            We're Polishing Things Up
        </h1>
        <p class="lead text-white-50 mx-auto mb-4" style="max-width:560px;">
            Our public website is being refined for launch.
            Existing demo and admin users can continue using their workspace directly.
        </p>

        {{-- Actions --}}
        <div class="d-flex flex-wrap justify-content-center gap-3 mb-4">
            <a href="{{ url('/login') }}" class="btn btn-light btn-lg px-4">
                <i class="ti ti-login me-2"></i>Admin Login
            </a>
            <a href="mailto:{{ config('saas.contact.sales_email', 'sales@bingoopos.com') }}"
               class="btn btn-outline-light btn-lg px-4">
                <i class="ti ti-mail me-2"></i>Contact Sales
            </a>
        </div>

        {{-- Sub-note --}}
        <p class="text-white-50 small mb-0">
            <i class="ti ti-info-circle me-1"></i>
            Client demo links remain available directly from their assigned subdomain.
        </p>

    </div>
</section>
@endsection
