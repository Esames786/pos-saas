@php
    $brandName = config('saas.brand_name', 'Habibi POS');
    $brandTagline = config('saas.brand_tagline', 'Cloud POS for retail, restaurants, and inventory teams.');
    $brandLogo = config('saas.brand_logo', 'images/brand/habibi-pos-logo.svg');
    $brandMark = config('saas.brand_mark', 'images/brand/habibi-pos-mark.svg');
    $ogImage = config('saas.og_image', 'images/data/Banner-Full.webp');
    // Decode once: inline @section content is HTML-escaped at definition,
    // and {{ }} escapes again on output — decode here to avoid double-encoding.
    $pageTitle = trim(html_entity_decode($__env->yieldContent('title'), ENT_QUOTES));
    $metaTitle = $pageTitle ? $pageTitle . ' | ' . $brandName : $brandName;
    $metaDescription = trim(html_entity_decode($__env->yieldContent('meta_description'), ENT_QUOTES)) ?: 'Run sales, inventory, restaurant tables, KOT, kitchen display, purchasing, reports, and multi-branch operations from one cloud POS platform.';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $metaTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:image" content="{{ asset($ogImage) }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ $brandName }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $metaTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">
    <meta name="twitter:image" content="{{ asset($ogImage) }}">

    <link rel="icon" type="image/svg+xml" href="{{ asset($brandMark) }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/plugins/tabler-icons/tabler-icons.min.css') }}">
    <style>
        html { scroll-behavior: smooth; }
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

        /* Premium foundation (15B-5B) */
        .public-hero-premium {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at 20% 20%, rgba(37,99,235,.30), transparent 30%),
                radial-gradient(circle at 80% 10%, rgba(244,63,94,.18), transparent 28%),
                linear-gradient(135deg, #0f172a 0%, #1e3a8a 55%, #2563eb 100%);
            color: #fff;
        }
        .hero-glow-card {
            background: rgba(255,255,255,.10);
            border: 1px solid rgba(255,255,255,.18);
            box-shadow: 0 28px 80px rgba(0,0,0,.35);
            backdrop-filter: blur(16px);
            border-radius: 28px;
        }
        .device-frame {
            position: relative;
            border-radius: 24px;
            background: #020617;
            border: 1px solid rgba(255,255,255,.14);
            box-shadow: 0 30px 90px rgba(15,23,42,.35);
            padding: 12px;
        }
        .device-frame img { width: 100%; border-radius: 16px; display: block; }
        .reveal { opacity: 0; transform: translateY(24px); transition: opacity .7s ease, transform .7s ease; }
        .reveal.is-visible { opacity: 1; transform: translateY(0); }
        .hover-lift { transition: transform .2s ease, box-shadow .2s ease; }
        .hover-lift:hover { transform: translateY(-4px); box-shadow: 0 18px 50px rgba(15,23,42,.12); }
        .hero-badge {
            display: inline-flex;
            gap: .5rem;
            align-items: center;
            border: 1px solid rgba(255,255,255,.22);
            background: rgba(255,255,255,.10);
            color: #dbeafe;
            border-radius: 999px;
            padding: .45rem .8rem;
            font-weight: 600;
            font-size: .875rem;
        }
    </style>
    @stack('styles')
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-public py-3">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="{{ url('/') }}">
            <img src="{{ asset($brandMark) }}" alt="{{ $brandName }}" style="height:34px;width:34px;">
            <span>{{ $brandName }}</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="publicNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <li class="nav-item"><a class="nav-link" href="{{ url('/') }}">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/features') }}">Features</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/pricing') }}">Pricing</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/contact') }}">Contact Sales</a></li>
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
                <h5 class="text-white mb-1 d-flex align-items-center gap-2">
                    <img src="{{ asset($brandMark) }}" alt="{{ $brandName }}" style="height:28px;width:28px;">
                    {{ $brandName }}
                </h5>
                <p class="mb-0">{{ $brandTagline }}</p>
            </div>
            <div class="col-md-6 text-md-end">
                <a class="text-decoration-none me-3" style="color:#94a3b8;" href="{{ url('/features') }}">Features</a>
                <a class="text-decoration-none me-3" style="color:#94a3b8;" href="{{ url('/pricing') }}">Pricing</a>
                <a class="text-decoration-none me-3" style="color:#94a3b8;" href="{{ url('/contact') }}">Contact Sales</a>
                <a class="text-decoration-none" style="color:#94a3b8;" href="{{ url('/start-trial') }}">Start Trial</a>
            </div>
        </div>
        <hr style="border-color:#1e293b;">
        <small>&copy; {{ date('Y') }} {{ $brandName }}. All rights reserved.</small>
    </div>
</footer>

<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var items = document.querySelectorAll('.reveal');
    if (!('IntersectionObserver' in window)) {
        items.forEach(function (item) { item.classList.add('is-visible'); });
        return;
    }
    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });
    items.forEach(function (item) { observer.observe(item); });
});
</script>
@stack('scripts')
</body>
</html>
