@php
    $brandName = config('saas.brand_name', 'Bingoo POS');
    $brandTagline = config('saas.brand_tagline', 'Cloud POS for retail, restaurants, and inventory teams.');
    $brandLogo = config('saas.brand_logo', 'images/brand/bingoo-pos-logo.svg');
    $brandMark = config('saas.brand_mark', 'images/brand/bingoo-pos-mark.svg');
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

    <link rel="icon" type="image/png" href="{{ asset('images/bingoo_new/bingoo-footer-icon.png') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/plugins/tabler-icons/tabler-icons.min.css') }}">
    <style>
        :root {
            --brand-navy: #0f172a;
            --brand-blue: #2563eb;
            --brand-gold: #f59e0b;
            --brand-rose: #f43f5e;
            --brand-mint: #10b981;
        }
        html { scroll-behavior: smooth; }
        body { background:#f7f8fb; color:#1f2937; }
        .navbar-public {
            position: sticky;
            top: 0;
            z-index: 1030;
            background: rgba(15, 23, 42, .86);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(255,255,255,.08);
        }
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

        /* ─── Premium section additions (15B-5C) ─── */
        .premium-section {
            background:
                radial-gradient(circle at 10% 50%, rgba(37,99,235,.20), transparent 40%),
                radial-gradient(circle at 90% 50%, rgba(124,58,237,.15), transparent 40%),
                #0f172a;
            color:#fff;
        }
        .trust-strip {
            background:#fff;
            border-top:1px solid #e5e7eb;
            border-bottom:1px solid #e5e7eb;
        }
        .trust-item {
            display:flex;
            align-items:center;
            gap:.6rem;
            color:#374151;
            font-weight:600;
            font-size:.9rem;
            white-space:nowrap;
        }
        .trust-item i { color:#2563eb; font-size:1.1rem; }

        /* floating hero micro-cards */
        @keyframes floatY {
            0%,100% { transform:translateY(0); }
            50%      { transform:translateY(-8px); }
        }
        .float-soft { animation:floatY 3s ease-in-out infinite; }
        .float-soft-2 { animation:floatY 3.4s ease-in-out infinite .4s; }
        .float-soft-3 { animation:floatY 4s ease-in-out infinite .8s; }
        .floating-card {
            position:absolute;
            background:rgba(255,255,255,.96);
            border-radius:14px;
            padding:.7rem 1rem;
            font-size:.78rem;
            font-weight:600;
            color:#0f172a;
            box-shadow:0 12px 40px rgba(0,0,0,.22);
            display:flex;
            align-items:center;
            gap:.55rem;
            white-space:nowrap;
            z-index:10;
        }
        .floating-card .dot { width:8px;height:8px;border-radius:50%;display:inline-block; }

        /* step cards */
        .step-number {
            width:60px;height:60px;border-radius:50%;
            background:linear-gradient(135deg,#1d4ed8,#7c3aed);
            color:#fff;font-size:1.6rem;font-weight:800;
            display:flex;align-items:center;justify-content:center;
            box-shadow:0 8px 24px rgba(29,78,216,.35);
        }
        .step-connector {
            flex:1;height:2px;
            background:linear-gradient(90deg,#2563eb,#7c3aed);
            opacity:.3;
        }

        /* image cards with zoom */
        .image-card { border-radius:1.25rem;overflow:hidden;position:relative; border:1px solid #e5e7eb; }
        .image-card img { transition:transform .45s ease; }
        .image-card:hover img { transform:scale(1.05); }
        .image-card .card-body { padding:1.5rem; }
        .image-card .badge-tag {
            position:absolute;top:12px;left:12px;
            background:rgba(15,23,42,.82);backdrop-filter:blur(8px);
            color:#93c5fd;border-radius:8px;padding:.3rem .7rem;
            font-size:.75rem;font-weight:700;letter-spacing:.5px;
            text-transform:uppercase;
        }

        /* gradient feature cards */
        .gradient-card {
            border-radius:1rem;
            border:1px solid #e5e7eb;
            background:#fff;
            transition:box-shadow .2s ease,transform .2s ease;
        }
        .gradient-card:hover {
            box-shadow:0 20px 50px rgba(15,23,42,.10);
            transform:translateY(-4px);
            border-color:#bfdbfe;
        }
        .gradient-card .icon-wrap {
            width:52px;height:52px;border-radius:14px;
            background:linear-gradient(135deg,#eff6ff,#dbeafe);
            display:flex;align-items:center;justify-content:center;
            margin-bottom:1rem;
        }
        .gradient-card .icon-wrap i { font-size:1.5rem;color:#1d4ed8; }

        /* FBR section */
        .fbr-section {
            background:linear-gradient(135deg,#0f172a,#1e3a5f);
            color:#fff;
            border-radius:1.5rem;
        }
        .fbr-bullet { display:flex;align-items:flex-start;gap:.75rem;margin-bottom:.9rem; }
        .fbr-bullet i { color:#34d399;margin-top:2px;flex-shrink:0; }

        /* stat cards */
        .stat-card {
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:1.25rem;
            padding:2rem 1.5rem;
            text-align:center;
        }
        .stat-number {
            font-size:3rem;
            font-weight:800;
            color:#1d4ed8;
            line-height:1;
        }
        .stat-label { color:#6b7280;font-size:.95rem;margin-top:.4rem; }

        /* testimonials */
        .testimonial-card {
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:1.25rem;
            padding:1.75rem;
            position:relative;
            transition:box-shadow .2s ease,transform .2s ease;
        }
        .testimonial-card:hover { box-shadow:0 16px 40px rgba(15,23,42,.10);transform:translateY(-3px); }
        .testimonial-card .quote-icon { color:#dbeafe;font-size:2.5rem;line-height:1; }
        .avatar-circle {
            width:44px;height:44px;border-radius:50%;
            background:linear-gradient(135deg,#1d4ed8,#7c3aed);
            color:#fff;font-weight:700;font-size:1rem;
            display:flex;align-items:center;justify-content:center;
        }
        .country-badge {
            font-size:.72rem;font-weight:600;
            background:#f0f9ff;color:#0369a1;
            border-radius:6px;padding:.15rem .5rem;
            border:1px solid #bae6fd;
        }

        /* comparison table */
        .comparison-tbl th, .comparison-tbl td { padding:.9rem 1rem;vertical-align:middle; }
        .comparison-tbl .col-brand { background:#eff6ff; }
        .comparison-tbl .col-brand th { color:#1d4ed8;font-weight:800; }
        .check-yes { color:#16a34a;font-size:1.1rem; }
        .check-no  { color:#9ca3af;font-size:1.1rem; }

        /* glow border device frame */
        .glow-border {
            border:1px solid rgba(99,102,241,.35) !important;
            box-shadow:0 0 0 1px rgba(99,102,241,.15), 0 20px 60px rgba(15,23,42,.4) !important;
        }
        /* img hover zoom inside device frame */
        .device-frame:hover img { transform:scale(1.01); }
        .device-frame img { transition:transform .4s ease; }

        /* FAQ */
        .accordion-button { font-weight:600; }
        .accordion-button:not(.collapsed) { color:#1d4ed8; background:#eff6ff; }

        /* plan badge */
        .plan-badge {
            font-size:.7rem;font-weight:700;text-transform:uppercase;
            letter-spacing:.5px;border-radius:6px;padding:.2rem .55rem;
        }
        .plan-badge-popular { background:#fef3c7;color:#92400e; }
        .plan-badge-retail  { background:#f0fdf4;color:#166534; }
        .plan-badge-resto   { background:#fff7ed;color:#9a3412; }
        .plan-badge-inv     { background:#eff6ff;color:#1e40af; }
        .plan-card-popular  { border-color:#1d4ed8 !important;box-shadow:0 8px 32px rgba(29,78,216,.18); }

        /* mobile mock card */
        .mobile-mock {
            background:linear-gradient(135deg,#1e3a8a,#7c3aed);
            border-radius:1.25rem;padding:1.25rem;color:#fff;
            font-size:.82rem;
        }
        .mobile-mock-row { display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid rgba(255,255,255,.12); }
        .mobile-mock-row:last-child { border-bottom:none; }

        /* ─── 2026 premium motion pass (15B-5D) ─── */
        .mega-glow {
            position:absolute; inset:auto;
            width:420px; height:420px; border-radius:999px;
            filter:blur(90px); opacity:.34; pointer-events:none; z-index:0;
        }
        .float-card { animation:floatY 5.5s ease-in-out infinite; }
        .float-card-delay { animation:floatY 6.5s ease-in-out infinite .8s; }

        /* hero module thumbnail rail — shows breadth beyond restaurant */
        .hero-modrail { position:absolute; left:-24px; top:22%; flex-direction:column; gap:.55rem; z-index:11; }
        .hero-modrail-cap {
            font-size:.58rem; font-weight:800; letter-spacing:.6px; text-transform:uppercase;
            color:#0f172a; background:linear-gradient(135deg,#e9c869,#caa23f);
            border-radius:8px; padding:.2rem .5rem; text-align:center; box-shadow:0 6px 16px rgba(7,16,38,.25);
        }
        .hero-modthumb {
            width:78px; background:rgba(255,255,255,.97); border:1px solid rgba(202,162,63,.55);
            border-radius:12px; padding:5px; box-shadow:0 8px 22px rgba(7,16,38,.28); text-align:center;
        }
        .hero-modthumb img { width:100%; height:42px; object-fit:cover; border-radius:8px; display:block; }
        .hero-modthumb span {
            display:block; font-size:.58rem; font-weight:800; letter-spacing:.3px;
            color:#0f172a; margin-top:3px; text-transform:uppercase;
        }

        .marquee-wrap { overflow:hidden; }
        .marquee-track { display:flex; gap:1rem; width:max-content; animation:marquee 28s linear infinite; }
        @keyframes marquee { from { transform:translateX(0); } to { transform:translateX(-50%); } }
        .marquee-chip {
            display:inline-flex; align-items:center; gap:.5rem;
            background:#fff; border:1px solid #e5e7eb; border-radius:999px;
            padding:.55rem 1.1rem; font-weight:600; font-size:.9rem; color:#334155; white-space:nowrap;
        }
        .marquee-chip i { color:var(--brand-blue); }

        .carousel-premium {
            border-radius:28px; overflow:hidden;
            background:linear-gradient(135deg, rgba(15,23,42,.96), rgba(30,58,138,.92));
            border:1px solid rgba(255,255,255,.12);
            box-shadow:0 30px 90px rgba(15,23,42,.28);
        }
        .carousel-premium .carousel-control-prev,
        .carousel-premium .carousel-control-next { width:6%; }
        .industry-slide-img { width:100%; height:420px; object-fit:cover; }
        @media (max-width:991px){ .industry-slide-img { height:240px; } }

        .mascot-card {
            border-radius:28px;
            background:
                radial-gradient(circle at top left, rgba(245,158,11,.16), transparent 28%),
                linear-gradient(135deg, #ffffff, #f8fbff);
            border:1px solid rgba(37,99,235,.12);
            box-shadow:0 24px 70px rgba(15,23,42,.10);
        }
        .speech-bubble {
            background:#fff; border:1px solid #e5e7eb; border-radius:14px;
            padding:.7rem 1rem; font-weight:600; color:#0f172a; font-size:.9rem;
            box-shadow:0 8px 24px rgba(15,23,42,.08); display:inline-block;
        }

        .receipt-mock {
            background:#fff; color:#0f172a; border-radius:22px;
            box-shadow:0 24px 70px rgba(0,0,0,.22); padding:1.25rem;
            font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size:.8rem; line-height:1.7;
        }
        .receipt-mock .r-row { display:flex; justify-content:space-between; }
        .receipt-mock hr { border-top:1px dashed #cbd5e1; margin:.5rem 0; }
        .qr-mock {
            width:96px; height:96px;
            background:
                linear-gradient(90deg, #0f172a 8px, transparent 8px) 0 0/16px 16px,
                linear-gradient(#0f172a 8px, transparent 8px) 0 0/16px 16px,
                #fff;
            border:8px solid #fff; box-shadow:inset 0 0 0 1px #e5e7eb;
        }

        .demo-tab-btn {
            border:1px solid #e5e7eb; background:#fff; color:#334155;
            border-radius:999px; padding:.5rem 1.1rem; font-weight:600; font-size:.9rem;
            transition:all .2s ease;
        }
        .demo-tab-btn.active { background:var(--brand-blue); color:#fff; border-color:var(--brand-blue); box-shadow:0 8px 24px rgba(37,99,235,.3); }

        .star-rating i { color:var(--brand-gold); font-size:.95rem; }

        .sticky-trial-cta {
            position:fixed; right:22px; bottom:22px; z-index:1050;
            display:none; box-shadow:0 18px 45px rgba(37,99,235,.35);
        }
        .sticky-trial-cta.is-visible { display:inline-flex; }

        /* responsive polish */
        @media (max-width:768px) {
            .floating-card { display:none !important; }
            .sticky-trial-cta { left:16px; right:16px; justify-content:center; }
            .mega-glow { width:240px; height:240px; }
        }

        /* ═══════════ NAVY + GOLD LUXURY THEME (matches logo) ═══════════ */
        /* Bootstrap primary → gold */
        .btn-primary {
            background:linear-gradient(135deg,#d8b24e,#a87f24) !important;
            border-color:#a87f24 !important; color:#11203f !important; font-weight:600;
        }
        .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
            background:linear-gradient(135deg,#e9c869,#caa23f) !important;
            border-color:#caa23f !important; color:#11203f !important;
        }
        .btn-outline-primary { color:#9a7320 !important; border-color:#d8b24e !important; }
        .btn-outline-primary:hover, .btn-outline-primary:focus {
            background:linear-gradient(135deg,#d8b24e,#a87f24) !important;
            border-color:#a87f24 !important; color:#11203f !important;
        }
        .btn-link { color:#9a7320 !important; }
        .text-primary { color:#9a7320 !important; }
        .bg-primary { background-color:#caa23f !important; }
        .text-success { color:#15803d !important; }

        /* gold CTAs inside dark hero / premium sections */
        .public-hero-premium .btn-light, .premium-section .btn-light {
            background:linear-gradient(135deg,#e9c869,#caa23f); border-color:#caa23f;
            color:#11203f !important; font-weight:700;
        }
        .public-hero-premium .btn-light:hover, .premium-section .btn-light:hover {
            background:linear-gradient(135deg,#f4d784,#d8b24e); color:#11203f !important;
        }
        .public-hero-premium .btn-outline-light, .premium-section .btn-outline-light {
            border-color:rgba(245,200,90,.5); color:#f4e7c1;
        }
        .public-hero-premium .btn-outline-light:hover, .premium-section .btn-outline-light:hover {
            background:rgba(245,200,90,.15); border-color:#e3c163; color:#fff;
        }

        /* navy + gold hero background */
        .public-hero-premium {
            background:
                radial-gradient(circle at 18% 22%, rgba(200,155,60,.22), transparent 30%),
                radial-gradient(circle at 82% 12%, rgba(37,99,235,.16), transparent 30%),
                linear-gradient(135deg, #0a1022 0%, #11223f 55%, #16284a 100%) !important;
        }
        .hero-badge { border-color:rgba(245,200,90,.35); background:rgba(245,200,90,.10); color:#f4e7c1; }
        .hero-badge i { color:#e9c869; }

        /* recolored accent classes */
        .step-number { background:linear-gradient(135deg,#caa23f,#a87f24) !important; color:#11203f; box-shadow:0 8px 24px rgba(168,127,36,.35) !important; }
        .icon-wrap { background:linear-gradient(135deg,#fbf1d6,#f1e0a8) !important; }
        .icon-wrap i { color:#a87f24 !important; }
        .gradient-card:hover { border-color:#e7cd86 !important; box-shadow:0 20px 50px rgba(168,127,36,.12) !important; }
        .plan-card:hover { box-shadow:0 12px 30px rgba(168,127,36,.16) !important; }
        .plan-card-popular { border-color:#caa23f !important; box-shadow:0 8px 32px rgba(200,155,60,.25) !important; }
        .plan-price { color:#11203f !important; }
        .stat-number { color:#a87f24 !important; }
        .trust-item i { color:#a87f24 !important; }
        .marquee-chip i { color:#caa23f !important; }
        .demo-tab-btn.active { background:#caa23f !important; border-color:#caa23f !important; color:#11203f !important; box-shadow:0 8px 24px rgba(200,155,60,.3); }
        .comparison-tbl .col-brand { background:#fdf6e3 !important; }
        .testimonial-card .quote-icon { color:#f1e0a8; }
        .glow-border { border-color:rgba(200,155,60,.4) !important; box-shadow:0 0 0 1px rgba(200,155,60,.2), 0 20px 60px rgba(15,23,42,.4) !important; }
        .hover-lift:hover { box-shadow:0 18px 50px rgba(168,127,36,.14); }
        .accordion-button:not(.collapsed) { color:#9a7320 !important; background:#fdf6e3 !important; }
        .plan-badge-inv { background:#fdf6e3 !important; color:#9a7320 !important; }
        .country-badge { background:#fdf6e3 !important; color:#9a7320 !important; border-color:#e7cd86 !important; }
        .navbar-public .btn-light {
            background:linear-gradient(135deg,#e9c869,#caa23f); border-color:#caa23f; color:#11203f; font-weight:700;
        }
        .navbar-public .btn-light:hover { background:linear-gradient(135deg,#f4d784,#d8b24e); color:#11203f; }

        /* legacy hero (pricing/features/contact/success) → navy + gold */
        .public-hero {
            background:
                radial-gradient(circle at 18% 30%, rgba(200,155,60,.20), transparent 32%),
                radial-gradient(circle at 85% 20%, rgba(37,99,235,.14), transparent 30%),
                linear-gradient(135deg, #0a1022 0%, #11223f 60%, #16284a 100%) !important;
        }
        .public-hero .btn-light, .section-pad .btn-light {
            background:linear-gradient(135deg,#e9c869,#caa23f); border-color:#caa23f; color:#11203f !important; font-weight:700;
        }
        .bg-primary-subtle { background-color:#fdf6e3 !important; }
        /* subtle gold focus ring */
        .form-control:focus, .form-select:focus, .form-check-input:focus {
            border-color:#d8b24e; box-shadow:0 0 0 .2rem rgba(200,155,60,.18);
        }
        .form-check-input:checked { background-color:#caa23f; border-color:#caa23f; }
    </style>
    @stack('styles')
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-public py-3">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="{{ url('/') }}">
            <img src="{{ asset($brandLogo) }}" alt="{{ $brandName }}" style="height:46px;width:auto;">
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="publicNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <li class="nav-item"><a class="nav-link" href="{{ url('/') }}">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/features') }}">Features</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/pricing') }}">Pricing</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/demos') }}">Demos</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/contact') }}">Contact Sales</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/login') }}">Admin Login</a></li>
                <li class="nav-item"><a class="btn btn-light btn-sm px-3" href="{{ url('/start-trial') }}">Start Trial</a></li>
            </ul>
        </div>
    </div>
</nav>

@yield('content')

<a href="{{ url('/start-trial') }}" class="btn btn-primary btn-lg sticky-trial-cta align-items-center gap-2">
    <i class="ti ti-rocket"></i> Start Free Trial
</a>

<footer class="public-footer section-pad mt-5">
    <div class="container">
        <div class="row gy-3">
            <div class="col-md-6">
                <img src="{{ asset($brandLogo) }}" alt="{{ $brandName }}" style="height:48px;width:auto;" class="mb-2">
                <p class="mb-0">{{ $brandTagline }}</p>
            </div>
            <div class="col-md-6 text-md-end">
                <a class="text-decoration-none me-3" style="color:#94a3b8;" href="{{ url('/features') }}">Features</a>
                <a class="text-decoration-none me-3" style="color:#94a3b8;" href="{{ url('/pricing') }}">Pricing</a>
                <a class="text-decoration-none me-3" style="color:#94a3b8;" href="{{ url('/demos') }}">Demos</a>
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

/* ── Stat counter animation ── */
document.addEventListener('DOMContentLoaded', function () {
    var counters = document.querySelectorAll('[data-count]');
    if (!counters.length) return;
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            var el = entry.target;
            var target = parseInt(el.getAttribute('data-count'), 10);
            var suffix = el.getAttribute('data-suffix') || '';
            var duration = 1400;
            var start = Date.now();
            var frame = function () {
                var elapsed = Date.now() - start;
                var progress = Math.min(elapsed / duration, 1);
                var eased = 1 - Math.pow(1 - progress, 3);
                el.textContent = Math.floor(eased * target) + suffix;
                if (progress < 1) requestAnimationFrame(frame);
            };
            frame();
            io.unobserve(el);
        });
    }, { threshold: 0.5 });
    counters.forEach(function (c) { io.observe(c); });
});

/* ── Sticky trial CTA ── */
document.addEventListener('DOMContentLoaded', function () {
    var sticky = document.querySelector('.sticky-trial-cta');
    if (!sticky) return;
    function toggleSticky() {
        if (window.scrollY > 620) sticky.classList.add('is-visible');
        else sticky.classList.remove('is-visible');
    }
    toggleSticky();
    window.addEventListener('scroll', toggleSticky, { passive: true });
});

/* ── Product demo tabs ── */
document.addEventListener('DOMContentLoaded', function () {
    var btns = document.querySelectorAll('[data-demo-tab]');
    if (!btns.length) return;
    btns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = btn.getAttribute('data-demo-tab');
            btns.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            document.querySelectorAll('[data-demo-panel]').forEach(function (panel) {
                panel.style.display = (panel.getAttribute('data-demo-panel') === target) ? '' : 'none';
            });
        });
    });
});
</script>
@stack('scripts')
</body>
</html>
