<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }} — @yield('title', 'Cloud POS for Retail & Restaurants')</title>

    <link rel="shortcut icon" type="image/x-icon" href="{{ asset('assets/img/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/plugins/tabler-icons/tabler-icons.min.css') }}">
    <style>
        body { background:#f7f8fb; color:#1f2937; }
        .navbar-public { background:#0f172a; }
        .navbar-public .nav-link, .navbar-public .navbar-brand { color:#e2e8f0 !important; }
        .navbar-public .nav-link:hover { color:#fff !important; }
        .public-hero { background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%); color:#fff; }
        .public-hero .btn-light { font-weight:600; }
        .plan-card { border:1px solid #e5e7eb; border-radius:.75rem; transition:box-shadow .15s ease, transform .15s ease; }
        .plan-card:hover { box-shadow:0 12px 30px rgba(15,23,42,.12); transform:translateY(-3px); }
        .plan-price { font-size:2rem; font-weight:700; }
        .feature-tile { background:#fff; border:1px solid #eef0f4; border-radius:.75rem; height:100%; }
        .public-footer { background:#0f172a; color:#94a3b8; }
        .section-pad { padding:4rem 0; }
    </style>
    @stack('styles')
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-public py-3">
    <div class="container">
        <a class="navbar-brand fw-bold" href="{{ url('/') }}">{{ config('app.name') }}</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="publicNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <li class="nav-item"><a class="nav-link" href="{{ url('/') }}">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/features') }}">Features</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/pricing') }}">Pricing</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/login') }}">Admin Login</a></li>
                <li class="nav-item"><a class="btn btn-light btn-sm px-3" href="{{ url('/start-trial') }}">Start Trial</a></li>
            </ul>
        </div>
    </div>
</nav>

@yield('content')

<footer class="public-footer section-pad mt-5">
    <div class="container">
        <div class="row gy-3">
            <div class="col-md-6">
                <h5 class="text-white mb-1">{{ config('app.name') }}</h5>
                <p class="mb-0">Pakistan-focused retail and restaurant POS.</p>
            </div>
            <div class="col-md-6 text-md-end">
                <a class="text-decoration-none me-3" style="color:#94a3b8;" href="{{ url('/features') }}">Features</a>
                <a class="text-decoration-none me-3" style="color:#94a3b8;" href="{{ url('/pricing') }}">Pricing</a>
                <a class="text-decoration-none" style="color:#94a3b8;" href="{{ url('/start-trial') }}">Start Trial</a>
            </div>
        </div>
        <hr style="border-color:#1e293b;">
        <small>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</small>
    </div>
</footer>

<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
@stack('scripts')
</body>
</html>
