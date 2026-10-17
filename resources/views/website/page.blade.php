<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{{ $title }} — {{ $businessName }}</title>
<meta name="description" content="{{ $title }} — {{ $businessName }}.">
<meta name="robots" content="{{ $indexable ? 'index, follow' : 'noindex, follow' }}">
<link rel="canonical" href="{{ $canonical }}">
<meta name="theme-color" content="#A60D12">
@if($logo)<link rel="icon" href="{{ $logo }}">@endif
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
<style>
{{-- ONLINE-ORDERING-1 — Privacy Policy / FAQs: the restaurant's own text from Website Settings, same look as the menu. --}}
:root { --brand:#A60D12; --brand-soft:#FDF0F0; --ink:#1B1B1B; --muted:#6B6B6B; --line:#EDEDED; --cream:#FBF7F2; }
* { box-sizing:border-box; }
body { margin:0; background:#fff; color:var(--ink); font:400 15px/1.65 Poppins, system-ui, -apple-system, "Segoe UI", sans-serif; }
a { color:var(--brand); }
.top { border-bottom:1px solid var(--line); }
.wrap { max-width:860px; margin:0 auto; padding:0 16px; }
.top .wrap { display:flex; align-items:center; justify-content:space-between; gap:12px; height:68px; }
.brand { display:flex; align-items:center; gap:10px; text-decoration:none; color:var(--ink); font-weight:700; min-width:0; }
.brand .lg { width:42px; height:42px; border-radius:50%; background:var(--brand); overflow:hidden; flex:none; }
.brand .lg img { width:100%; height:100%; object-fit:cover; }
.brand span:last-child { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.order { flex:none; background:var(--brand); color:#fff; text-decoration:none; font-weight:600; font-size:14px; border-radius:999px; padding:9px 18px; }
main { padding:34px 0 50px; }
.card { background:#fff; border:1px solid var(--line); border-radius:18px; padding:28px clamp(18px, 4vw, 40px); box-shadow:0 8px 28px rgba(0,0,0,.05); }
h1 { margin:0 0 6px; font-size:clamp(24px, 4vw, 32px); line-height:1.2; text-wrap:balance; }
.lede { margin:0 0 24px; color:var(--muted); }
h2 { margin:26px 0 8px; font-size:18px; text-wrap:balance; }
p { margin:0 0 10px; max-width:68ch; }
ul { margin:0 0 12px; padding-left:22px; max-width:68ch; }
li { margin:2px 0; }
.chips { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 24px; }
.chips button { border:0; border-radius:999px; padding:7px 15px; font:inherit; font-size:13.5px; font-weight:500; background:#F2F2F2; color:#3A3A3A; cursor:pointer; }
.chips button.on { background:var(--brand); color:#fff; }
.chips button:focus-visible, summary:focus-visible, .order:focus-visible { outline:3px solid #F3C9CB; outline-offset:2px; }
.group h2 { color:var(--brand); border-bottom:2px solid var(--brand); padding-bottom:6px; margin-top:28px; }
.group:first-of-type h2 { margin-top:0; }
details { border-bottom:1px solid var(--line); background:#FAFAFA; }
details:first-of-type { border-radius:12px 12px 0 0; }
details:last-of-type { border-radius:0 0 12px 12px; border-bottom:0; }
summary { list-style:none; cursor:pointer; padding:14px 16px; font-weight:500; display:flex; justify-content:space-between; gap:12px; }
summary::-webkit-details-marker { display:none; }
summary::after { content:"+"; color:var(--muted); font-weight:600; }
details[open] summary::after { content:"−"; }
details .ans { padding:0 16px 14px; color:#4A4A4A; }
details .ans > :last-child, .rich > :last-child { margin-bottom:0; }
.rich h3, .ans h3 { margin:20px 0 6px; font-size:16px; }
.rich h4, .ans h4 { margin:16px 0 4px; font-size:15px; }
blockquote { margin:0 0 12px; padding:8px 14px; border-left:3px solid #F3C9CB; background:#FFF8F8; color:#4A4A4A; }
footer { background:var(--cream); border-top:1px solid #F1E7DA; font-size:13.5px; color:#4A4A4A; }
footer .wrap { display:flex; flex-wrap:wrap; gap:8px 22px; justify-content:space-between; padding-top:18px; padding-bottom:18px; }
footer nav { display:flex; gap:16px; flex-wrap:wrap; }
</style>
</head>
<body>
<header class="top"><div class="wrap">
    <a class="brand" href="{{ $home }}"><span class="lg">@if($logo)<img src="{{ $logo }}" alt="">@endif</span><span>{{ $businessName }}</span></a>
    <a class="order" href="{{ $home }}">Order now</a>
</div></header>

<main><div class="wrap"><div class="card">
@if($slug === 'faqs')
    <h1>{{ $title }}</h1>
    <p class="lede">Answers to common questions about ordering from {{ $businessName }}.</p>
    @if(count($groups) > 1)
        <div class="chips" role="group" aria-label="Filter questions">
            <button type="button" class="on" data-cat="">All</button>
            @foreach(array_keys($groups) as $cat)<button type="button" data-cat="{{ $cat }}">{{ $cat }}</button>@endforeach
        </div>
    @endif
    @foreach($groups as $cat => $items)
        <section class="group" data-group="{{ $cat }}">
            <h2>{{ $cat }}</h2>
            @foreach($items as $f)
                <details><summary>{{ $f['q'] }}</summary>@if($f['html'] !== '')<div class="ans">{!! $f['html'] !!}</div>@endif</details>
            @endforeach
        </section>
    @endforeach
    <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => collect($groups)->flatten(1)->map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['text']]])->values()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    <script>
    (function () {
        'use strict';
        var chips = document.querySelectorAll('.chips button');
        chips.forEach(function (b) {
            b.addEventListener('click', function () {
                chips.forEach(function (c) { c.classList.toggle('on', c === b); });
                document.querySelectorAll('[data-group]').forEach(function (g) { g.hidden = !!b.dataset.cat && g.dataset.group !== b.dataset.cat; });
            });
        });
    })();
    </script>
@else
    <h1>{{ $title }}</h1>
    {{-- Cleaned by SafeHtml (only text formatting survives) — the restaurant's own rich text. --}}
    <div class="rich">{!! $html !!}</div>
@endif
</div></div></main>

<footer><div class="wrap">
    <span>&copy; {{ now()->year }} {{ $businessName }}@if($settings->contact_phone) · <a href="tel:{{ preg_replace('/\s+/', '', $settings->contact_phone) }}">{{ $settings->contact_phone }}</a>@endif</span>
    <nav aria-label="Pages">
        <a href="{{ $home }}">Menu</a>
        @if($privacyUrl)<a href="{{ $privacyUrl }}">Privacy Policy</a>@endif
        @if($faqsUrl)<a href="{{ $faqsUrl }}">FAQs</a>@endif
    </nav>
</div></footer>
</body>
</html>
