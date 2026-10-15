<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
{{-- ONLINE-ORDERING-1: SEO per restaurant (Website Settings → "SEO & pages"). Google indexes only the restaurant's
     own domain; on *.bingoopos.com (POS subdomain, UAT) the same page is noindex and points to the own domain. --}}
<title>{{ $seo['title'] }}</title>
<meta name="description" content="{{ $seo['description'] }}">
@if($seo['keywords'])<meta name="keywords" content="{{ $seo['keywords'] }}">@endif
<meta name="robots" content="{{ $seo['indexable'] ? 'index, follow, max-image-preview:large' : 'noindex, follow' }}">
<link rel="canonical" href="{{ $seo['canonical'] }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $businessName }}">
<meta property="og:title" content="{{ $seo['title'] }}">
<meta property="og:description" content="{{ $seo['description'] }}">
<meta property="og:url" content="{{ $seo['canonical'] }}">
@if($seo['image'])<meta property="og:image" content="{{ $seo['image'] }}">@endif
<meta property="og:locale" content="en_PK">
<meta name="twitter:card" content="{{ $seo['image'] ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $seo['title'] }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
@if($seo['image'])<meta name="twitter:image" content="{{ $seo['image'] }}">@endif
@if(! empty($payload['business']['logo']))<link rel="icon" href="{{ $payload['business']['logo'] }}">@endif
<script type="application/ld+json">{!! json_encode($seo['ld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
<meta name="theme-color" content="#A60D12">
@if(! empty($payload['banners'][0]))
<link rel="preload" as="image" href="{{ $payload['banners'][0]['src'] }}" imagesrcset="{{ $payload['banners'][0]['sm'] }} 960w, {{ $payload['banners'][0]['src'] }} 1920w" imagesizes="100vw">
@endif
@if(! empty($payload['installable']))
<link rel="manifest" href="{{ url('/order/manifest.webmanifest') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
@if(! empty($payload['business']['logo']))<link rel="apple-touch-icon" href="{{ $payload['business']['logo'] }}">@endif
@endif
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Allura&display=swap">
<style>
/* ONLINE-ORDERING-1 — the ordering website, laid out like the restaurant's old site (owner, 15 Oct): logo header,
   banner slider, sticky category bar with arrows, live search, big Popular cards, script category banners with
   palm leaves, cart drawer with suggestions, floating search / WhatsApp / top / cart buttons. One light page: no
   framework, pictures lazy and small, motion only when the device allows it. */
:root { --brand:#A60D12; --brand-dark:#7E090D; --brand-soft:#FCEDEE; --cream:#F7EBDD; --leaf:#D9C6A8; --ink:#141414; --muted:#6B6B6B;
  --line:#ECECEC; --tag:#FFCB2F; --bg:#FFFFFF; --card:#FFFFFF; --ok:#1E7A4C; --wa:#25D366;
  --shadow:0 8px 28px rgba(0,0,0,.08); --shadow-lg:0 18px 48px rgba(0,0,0,.18); --ease:cubic-bezier(.2,.7,.2,1); }
* { box-sizing:border-box; }
[hidden] { display:none !important; }
html { scroll-behavior:smooth; }
body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.5 "Poppins","Segoe UI",system-ui,sans-serif; -webkit-font-smoothing:antialiased; overflow-x:hidden; }
button { font:inherit; color:inherit; cursor:pointer; }
button:focus-visible, a:focus-visible, input:focus-visible { outline:3px solid #F4A7AA; outline-offset:2px; }
img { display:block; max-width:100%; }
svg { flex:none; }
.wrap { max-width:1180px; margin:0 auto; padding:0 max(16px, env(safe-area-inset-left)); }
.wide { max-width:1840px; margin:0 auto; padding:0 max(16px, env(safe-area-inset-left)); }

/* ── header ── */
.top { position:relative; z-index:30; background:#fff; box-shadow:0 1px 0 var(--line); }
.top .wrap { display:flex; align-items:center; justify-content:space-between; gap:10px; height:72px; }
/* chips keep to their half — the round logo sits in the middle */
.chips { display:flex; gap:10px; min-width:0; flex:1; max-width:calc(50% - 64px); }
.chip { display:flex; align-items:center; gap:10px; border:0; background:#F6F6F6; border-radius:999px; padding:7px 14px 7px 8px; min-width:0; text-align:left; transition:background .2s; }
.chip:hover { background:#EFEFEF; }
.chip { max-width:100%; }
.chip > span:nth-child(2) { min-width:0; }
.chip .ic { width:30px; height:30px; border-radius:50%; background:#fff; display:grid; place-items:center; color:var(--ink); }
.chip small { display:block; font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); line-height:1.1; }
.chip b { display:block; font-size:13px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:190px; }
.chip.phone { text-decoration:none; color:inherit; font-weight:700; font-size:13px; }
/* right side mirrors the chips: install + cart keep to their half too */
.acts { display:flex; align-items:center; justify-content:flex-end; gap:8px; flex:1; min-width:0; max-width:calc(50% - 64px); }
@media (max-width:720px) {
  .chip.phone { display:none; } .chip b { max-width:100%; } .chip > svg { display:none; }
  /* phone (owner, 15 Oct): the area name gets the room — smaller icon, tighter padding, halves sized to the 76px logo */
  .chips, .acts { max-width:calc(50% - 46px); }
  .chip { gap:7px; padding:5px 10px 5px 5px; }
  .chip .ic { width:28px; height:28px; }
  .chip b { font-size:12.5px; }
}
.logo { position:absolute; left:50%; top:8px; transform:translateX(-50%); width:104px; height:104px; border-radius:50%; background:var(--brand); box-shadow:0 6px 18px rgba(166,13,18,.35); display:grid; place-items:center; overflow:hidden; color:var(--cream); font:italic 700 40px Georgia,serif; text-decoration:none; z-index:2; transition:transform .3s var(--ease); }
.logo:hover { transform:translateX(-50%) scale(1.04); }
.logo img { width:100%; height:100%; object-fit:cover; }
@media (max-width:720px) { .logo { width:76px; height:76px; top:6px; font-size:30px; } }
/* small phones (360px): a slightly smaller logo leaves room for the area name */
@media (max-width:380px) { .logo { width:66px; height:66px; } .chips, .acts { max-width:calc(50% - 40px); } .top .cartbtn { width:42px; height:42px; } }
.cartbtn { position:relative; border:0; background:#fff; width:46px; height:46px; border-radius:50%; display:grid; place-items:center; transition:background .2s; flex:none; }
.cartbtn:hover { background:#F6F6F6; }
.cartbtn .n, .fcart .n { position:absolute; top:2px; right:0; background:var(--brand); color:#fff; border-radius:999px; min-width:18px; height:18px; display:grid; place-items:center; font-size:10.5px; font-weight:700; padding:0 5px; }
.bump { animation:bump .5s var(--ease); }
@keyframes bump { 30% { transform:scale(1.18); } 60% { transform:scale(.94); } }
/* Closed / not taking orders: a full-width red bar ABOVE the header, like the old site — the round logo
   hangs below the header, so it never covers this text. */
.notice { position:relative; z-index:31; background:#E3242B; color:#fff; font-size:15px; font-weight:600; text-align:center; letter-spacing:.01em; }
.notice .wrap { padding-top:12px; padding-bottom:12px; }
@media (max-width:720px) { .notice { font-size:13.5px; } .notice .wrap { padding-top:10px; padding-bottom:10px; } }

/* ── banner slider ── */
.hero { padding-top:26px; }
.slider { position:relative; border-radius:28px; overflow:hidden; aspect-ratio:3.2/1; background:#2A0507; box-shadow:var(--shadow); touch-action:pan-y; }
.slide { position:absolute; inset:0; opacity:0; transform:scale(1.03); transition:opacity .8s ease, transform 1.2s var(--ease); }
.slide.on { opacity:1; transform:none; z-index:1; }
.slide img { width:100%; height:100%; object-fit:cover; }
.sarrow { position:absolute; top:50%; z-index:3; transform:translateY(-50%); width:44px; height:44px; border-radius:50%; border:0; background:rgba(255,255,255,.75); color:var(--ink); display:grid; place-items:center; transition:background .2s, transform .2s; }
.sarrow:hover { background:#fff; transform:translateY(-50%) scale(1.06); }
.sarrow.prev { left:16px; } .sarrow.next { right:16px; }
.dots { position:absolute; left:0; right:0; bottom:16px; z-index:3; display:flex; justify-content:center; gap:7px; }
.dots button { width:9px; height:9px; border-radius:999px; border:0; padding:0; background:rgba(20,20,20,.55); transition:width .35s var(--ease), background .3s; }
.dots button.on { width:38px; background:#fff; }
/* phone: the 76px logo overhangs the header by ~10px, so a small gap is enough; banners are swiped (arrows hid the banner text) */
@media (max-width:720px) { .hero { padding-top:20px; } .slider { border-radius:16px; } .sarrow { display:none; } .dots { bottom:8px; } }
.plain-hero { background:var(--cream); border-radius:28px; padding:34px 20px; text-align:center; font:400 clamp(44px,7vw,84px)/1 "Allura",cursive; color:var(--brand); }

/* ── category bar ── */
.cats { position:sticky; top:0; z-index:25; background:var(--brand); margin-top:18px; box-shadow:0 4px 14px rgba(126,9,13,.25); }
.cats .cin { position:relative; max-width:1180px; margin:0 auto; display:flex; align-items:center; }
.cats .track { display:flex; gap:4px; overflow-x:auto; padding:12px 44px; scrollbar-width:none; scroll-behavior:smooth; flex:1; }
.cats .track::-webkit-scrollbar { display:none; }
.cats a { flex:none; color:#fff; text-decoration:none; font-size:13px; font-weight:700; padding:8px 18px; border-radius:999px; white-space:nowrap; transition:background .25s, color .25s; }
.cats a:hover { background:rgba(255,255,255,.14); }
.cats a.on { background:#fff; color:var(--brand); }
/* the white pill glides to the active category */
.cats .track { position:relative; }
.cats .ind { position:absolute; left:0; top:12px; height:calc(100% - 24px); width:0; border-radius:999px; background:#fff; opacity:0; transition:transform .45s var(--ease), width .45s var(--ease), opacity .2s; pointer-events:none; }
.cats .ind.on { opacity:1; }
.cats .track.has-ind a { position:relative; z-index:1; }
.cats .track.has-ind a.on { background:transparent; }
.cats a .lv { display:inline-block; width:7px; height:7px; border-radius:50%; background:#3BE37C; margin-left:6px; vertical-align:middle; box-shadow:0 0 0 0 rgba(59,227,124,.7); animation:live 1.6s infinite; }
@keyframes live { 70% { box-shadow:0 0 0 7px rgba(59,227,124,0); } }
.carr { position:absolute; top:50%; transform:translateY(-50%); z-index:2; width:32px; height:32px; border-radius:50%; border:0; background:rgba(255,255,255,.85); color:var(--brand); display:grid; place-items:center; transition:opacity .2s, transform .2s; }
.carr:hover { transform:translateY(-50%) scale(1.08); }
.carr.l { left:6px; } .carr.r { right:6px; }
.carr.off { opacity:0; pointer-events:none; }
/* names fade out under an arrow instead of showing through it */
.cats .track.fr { -webkit-mask-image:linear-gradient(90deg,#000 calc(100% - 58px),transparent calc(100% - 12px)); mask-image:linear-gradient(90deg,#000 calc(100% - 58px),transparent calc(100% - 12px)); }
.cats .track.fl { -webkit-mask-image:linear-gradient(90deg,transparent 12px,#000 58px); mask-image:linear-gradient(90deg,transparent 12px,#000 58px); }
.cats .track.fl.fr { -webkit-mask-image:linear-gradient(90deg,transparent 12px,#000 58px,#000 calc(100% - 58px),transparent calc(100% - 12px)); mask-image:linear-gradient(90deg,transparent 12px,#000 58px,#000 calc(100% - 58px),transparent calc(100% - 12px)); }
@media (max-width:720px) { .cats .track { padding:10px 12px; } .cats a { padding:8px 14px; } .carr { width:30px; height:30px; } }

/* ── search + status ── */
.finder { padding-top:22px; padding-bottom:6px; display:grid; justify-items:center; gap:10px; }
.sbox { position:relative; width:min(100%, 440px); }
.sbox input { width:100%; border:1px solid #E3E3E3; border-radius:999px; padding:13px 58px 13px 22px; font:inherit; font-size:14.5px; box-shadow:0 4px 18px rgba(0,0,0,.06); transition:border-color .2s, box-shadow .2s; background:#fff; }
.sbox input:focus { outline:none; border-color:#E9A9AB; box-shadow:0 6px 22px rgba(166,13,18,.12); }
.sbox button { position:absolute; right:5px; top:50%; transform:translateY(-50%); width:40px; height:40px; border-radius:50%; border:0; background:var(--brand); color:#fff; display:grid; place-items:center; }
.status { display:flex; flex-wrap:wrap; justify-content:center; gap:8px; font-size:12.5px; }
.pill { background:#fff; border:1px solid var(--line); border-radius:999px; padding:4px 12px; font-weight:600; }
.pill.open { color:var(--ok); border-color:#BFE5CF; background:#EFFAF3; }
.pill.closed { color:var(--brand); border-color:#F3C9CB; background:#FDF0F0; }
.noresult { text-align:center; color:var(--muted); padding:40px 10px; }

/* ── popular ── */
.phead { display:flex; align-items:center; gap:10px; margin:22px 0 18px; }
.phead h2 { margin:0; font-size:26px; font-weight:800; line-height:1.1; }
.phead small { display:block; font-size:13px; color:var(--muted); font-weight:500; }
.pgrid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
@media (min-width:900px) { .pgrid { grid-template-columns:repeat(4,minmax(0,1fr)); gap:22px; } }
.pcard { position:relative; border:0; background:none; padding:0; text-align:left; display:grid; gap:10px; }
.pcard .ph { width:100%; height:auto; aspect-ratio:1; border-radius:22px; }
.pcard h3 { margin:0; font-size:17px; font-weight:700; line-height:1.25; }

/* ── category sections + cards ── */
section.cat { padding-top:34px; scroll-margin-top:70px; }
.banner { position:relative; overflow:hidden; background:var(--cream); border-radius:28px; height:clamp(118px,18vw,250px); display:grid; place-items:center; margin-bottom:22px; }
.banner h2 { position:relative; z-index:1; margin:0; font:400 clamp(52px,8vw,112px)/1 "Allura",cursive; color:var(--brand); padding:0 12%; text-align:center; }
.banner .leaf { position:absolute; width:clamp(110px,18vw,250px); color:var(--leaf); opacity:.85; pointer-events:none; }
.banner .leaf.a { left:-14px; bottom:-30px; transform:rotate(-18deg); }
.banner .leaf.b { right:-18px; top:-34px; transform:rotate(160deg); }
html.anim .banner .leaf { transition:transform 1.2s var(--ease); }
html.anim .banner.seen .leaf.a { transform:rotate(-10deg); }
html.anim .banner.seen .leaf.b { transform:rotate(168deg); }
.grid { display:grid; grid-template-columns:1fr; gap:16px; }
@media (min-width:640px) { .grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (min-width:980px) { .grid { grid-template-columns:repeat(3,minmax(0,1fr)); gap:18px; } }
.card { position:relative; display:grid; grid-template-columns:minmax(0,1fr) 150px; gap:14px; text-align:left; background:var(--card); border:1px solid #F0F0F0; border-radius:16px; padding:16px; min-height:172px; box-shadow:0 2px 10px rgba(0,0,0,.04); transition:box-shadow .3s, transform .3s var(--ease); }
.card:hover { box-shadow:var(--shadow); transform:translateY(-3px); }
.card h3 { margin:0 0 6px; font-size:17px; font-weight:700; line-height:1.25; }
.card p { margin:0; font-size:13px; color:var(--muted); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.card .txt { display:flex; flex-direction:column; min-width:0; }
.price { align-self:flex-start; margin-top:auto; display:inline-flex; align-items:baseline; gap:4px; font-weight:800; font-size:16px; background:#F4F4F4; border-radius:10px; padding:4px 10px; }
.price small { font-size:11px; font-weight:600; color:#444; }
.card .price { margin-top:auto; }
.ph { position:relative; width:150px; height:150px; border-radius:14px; overflow:hidden; background:#F3EEE8; display:grid; place-items:center; color:#C9A27A; font:italic 700 34px Georgia,serif; }
.ph::before { content:""; position:absolute; inset:0; background:linear-gradient(100deg, transparent 20%, rgba(255,255,255,.55) 50%, transparent 80%); transform:translateX(-100%); animation:shimmer 1.4s infinite; }
.ph.done::before { display:none; }
@keyframes shimmer { to { transform:translateX(100%); } }
.ph img { width:100%; height:100%; object-fit:cover; opacity:0; transform:scale(1.04); transition:opacity .5s ease, transform .8s var(--ease); }
.ph img.ok { opacity:1; transform:none; }
.card:hover .ph img.ok, .pcard:hover .ph img.ok { transform:scale(1.05); }
.ph.logo-ph { background:var(--brand); }
.ph.logo-ph img { object-fit:cover; }
@media (max-width:420px) { .card { grid-template-columns:minmax(0,1fr) 116px; } .ph { width:116px; height:116px; } }
.tag { position:absolute; top:-10px; right:18px; z-index:2; background:var(--tag); color:#2B2100; font-size:11px; font-weight:700; padding:3px 12px; border-radius:999px; box-shadow:0 2px 6px rgba(0,0,0,.12); }
/* "+" and the quantity control sit on the picture's corner */
.act { position:absolute; right:12px; bottom:12px; z-index:2; }
.plus { width:38px; height:38px; border-radius:50%; border:0; background:var(--brand); color:#fff; display:grid; place-items:center; box-shadow:0 4px 12px rgba(166,13,18,.35); transition:transform .2s var(--ease), background .2s; }
.plus:hover { transform:scale(1.1); background:var(--brand-dark); }
.step { display:inline-flex; align-items:center; background:#fff; border-radius:999px; box-shadow:0 4px 14px rgba(0,0,0,.16); padding:3px; gap:2px; animation:pop .25s var(--ease); }
.step button { width:30px; height:30px; border-radius:50%; border:0; background:none; color:var(--brand); display:grid; place-items:center; }
.step button:hover { background:var(--brand-soft); }
.step b { min-width:20px; text-align:center; font-size:14px; }
html.anim .rv { opacity:0; transform:translateY(18px); }
html.anim .rv.seen { opacity:1; transform:none; transition:opacity .6s ease var(--d,0ms), transform .6s var(--ease) var(--d,0ms); }
.slide.on img { animation:kb 9s ease-out both; }
@keyframes kb { from { transform:scale(1); } to { transform:scale(1.07); } }
.roll { animation:roll .4s var(--ease); }
@keyframes roll { from { transform:translateY(-70%); opacity:0; } }
.flyer { position:fixed; z-index:70; border-radius:50%; object-fit:cover; pointer-events:none; box-shadow:0 10px 24px rgba(0,0,0,.25); background:var(--brand); }
/* order again + live strip + install */
.again { display:flex; align-items:center; gap:16px; flex-wrap:wrap; margin-top:22px; padding:16px 18px; border-radius:20px; background:linear-gradient(120deg,#FFF6EC,#FDECEC); border:1px solid #F4DCCB; animation:fade .5s ease; }
.again .ic { width:46px; height:46px; border-radius:14px; background:#fff; display:grid; place-items:center; color:var(--brand); box-shadow:0 4px 12px rgba(0,0,0,.06); }
.again .tx { flex:1; min-width:200px; }
.again b { display:block; font-size:16px; }
.again small { color:var(--muted); }
.again .big { width:auto; padding:11px 20px; }
.live { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:16px; padding:12px 16px; border-radius:16px; color:#fff; background:linear-gradient(110deg,#1B1035,#3D1A5C 60%,#7E090D); box-shadow:0 10px 24px rgba(27,16,53,.25); animation:fade .5s ease; }
.live .moon { font-size:22px; }
.live b { font-size:15px; }
.live small { display:block; opacity:.85; }
.live button { margin-left:auto; border:0; background:#fff; color:#3D1A5C; border-radius:999px; padding:8px 16px; font-weight:700; }
.live.soon { background:linear-gradient(110deg,#2A2140,#4A3B66); }
.install { border:0; background:var(--brand-soft); color:var(--brand); border-radius:999px; padding:8px 14px; font-weight:700; font-size:13px; display:flex; align-items:center; gap:6px; white-space:nowrap; flex:none; }
/* phone: icon only (owner) — the label stays for screen readers via aria-label */
@media (max-width:720px) { .install { width:40px; height:40px; padding:0; justify-content:center; border-radius:50%; } .install span { display:none; } }
.freebar { background:#fff; border:1px solid #F0EAE4; border-radius:14px; padding:10px 12px; font-size:13px; display:grid; gap:8px; }
.freebar .track { height:8px; border-radius:999px; background:#F3E8E0; overflow:hidden; }
.freebar .fill { height:100%; border-radius:999px; background:linear-gradient(90deg,#E8545A,var(--brand)); transition:width .6s var(--ease); }
.freebar.done { background:#EFFAF3; border-color:#BFE5CF; color:var(--ok); font-weight:600; }

/* ── floating buttons + view cart ── */
.fab { position:fixed; z-index:26; border:0; display:grid; place-items:center; box-shadow:var(--shadow-lg); transition:transform .25s var(--ease), opacity .25s; }
.fab:hover { transform:translateY(-2px) scale(1.04); }
.fab.search { left:16px; bottom:calc(20px + env(safe-area-inset-bottom)); width:50px; height:50px; border-radius:14px; background:var(--brand); color:#fff; }
.fab.wa { right:18px; bottom:calc(96px + env(safe-area-inset-bottom)); width:58px; height:58px; border-radius:50%; background:var(--wa); color:#fff; text-decoration:none; }
.fab.up { right:22px; bottom:calc(20px + env(safe-area-inset-bottom)); width:48px; height:48px; border-radius:14px; background:var(--brand); color:#fff; }
.fab.up.off, .fab.search.off { opacity:0; pointer-events:none; transform:translateY(10px); }
.fcart { position:fixed; right:18px; top:92px; z-index:26; width:56px; height:56px; border-radius:50%; border:0; background:var(--brand); color:#fff; display:grid; place-items:center; box-shadow:0 10px 26px rgba(166,13,18,.4); transition:opacity .3s, transform .3s var(--ease); }
.fcart .n { background:#fff; color:var(--brand); top:-2px; right:-2px; }
.fcart.off { opacity:0; pointer-events:none; transform:scale(.7); }
.bar { position:fixed; left:50%; bottom:calc(18px + env(safe-area-inset-bottom)); z-index:27; transform:translate(-50%, 140%); display:flex; align-items:center; gap:18px; background:var(--brand); color:#fff; border:0; border-radius:999px; padding:9px 22px 9px 9px; font-weight:700; box-shadow:0 12px 30px rgba(166,13,18,.4); transition:transform .45s var(--ease); white-space:nowrap; }
.bar.show { transform:translate(-50%, 0); }
.bar .cnt { width:36px; height:36px; border-radius:50%; background:#fff; color:var(--brand); display:grid; place-items:center; font-weight:800; }
/* phone: buttons sit low and small; they lift only while the View Cart pill is up (body.bar-on). The floating
   bag is dropped — the View Cart pill already shows whenever the cart has something. */
@media (max-width:560px) {
  .bar { gap:10px; padding-right:16px; font-size:14px; }
  .fab.search { left:14px; bottom:calc(16px + env(safe-area-inset-bottom)); width:46px; height:46px; }
  .fab.up { right:14px; bottom:calc(16px + env(safe-area-inset-bottom)); width:46px; height:46px; }
  .fab.wa { right:14px; bottom:calc(72px + env(safe-area-inset-bottom)); width:46px; height:46px; }
  .fab.wa svg { width:26px; height:26px; }
  body.bar-on .fab.search, body.bar-on .fab.up { bottom:calc(80px + env(safe-area-inset-bottom)); }
  body.bar-on .fab.wa { bottom:calc(136px + env(safe-area-inset-bottom)); }
  .fcart { display:none; }
}

/* ── footer ── */
footer { margin-top:56px; background:#FBF7F2; border-top:1px solid #F1E7DA; font-size:13.5px; color:#4A4A4A; }
footer .wrap { padding-top:30px; padding-bottom:calc(110px + env(safe-area-inset-bottom)); display:grid; gap:22px; }
@media (min-width:860px) { footer .wrap { grid-template-columns:1.4fr 1fr; } }
footer h4 { margin:0 0 8px; color:var(--ink); font-size:15px; }
footer p { margin:0; }
footer .flogo { width:64px; height:64px; border-radius:50%; overflow:hidden; background:var(--brand); margin-bottom:10px; }
footer .flogo img { width:100%; height:100%; object-fit:cover; }
footer ul { list-style:none; margin:0; padding:0; display:grid; gap:8px; }
footer li { display:flex; gap:10px; align-items:flex-start; }
footer a { color:var(--brand); text-decoration:none; }
footer .soc { display:flex; gap:10px; margin-top:10px; }
footer .soc a { width:36px; height:36px; border-radius:50%; background:#fff; border:1px solid #EADFCF; display:grid; place-items:center; }
footer .fine { grid-column:1 / -1; border-top:1px solid #EFE5D8; padding-top:14px; display:flex; flex-wrap:wrap; gap:6px 16px; justify-content:space-between; color:var(--muted); font-size:12.5px; }
footer .fine button { border:0; background:none; color:var(--brand); text-decoration:underline; padding:0; font-size:12.5px; }

/* ── sheets (modal / drawer) ── */
.veil { position:fixed; inset:0; z-index:40; background:rgba(15,8,8,.55); opacity:0; pointer-events:none; transition:opacity .3s; backdrop-filter:blur(2px); }
.veil.show { opacity:1; pointer-events:auto; }
.sheet { position:fixed; z-index:50; background:#fff; display:flex; flex-direction:column; overflow:hidden; visibility:hidden;
  left:0; right:0; bottom:0; max-height:92vh; border-radius:22px 22px 0 0; transform:translateY(100%); transition:transform .38s var(--ease), visibility 0s .38s; }
.sheet.show { visibility:visible; transform:none; transition:transform .38s var(--ease); }
@media (min-width:720px) {
  .sheet { left:50%; right:auto; bottom:auto; top:50%; width:540px; max-height:90vh; border-radius:22px; transform:translate(-50%,-46%) scale(.96); opacity:0; transition:transform .3s var(--ease), opacity .25s, visibility 0s .3s; }
  .sheet.show { transform:translate(-50%,-50%); opacity:1; transition:transform .3s var(--ease), opacity .25s; }
  .sheet.side { left:auto; right:0; top:0; bottom:0; width:420px; max-height:none; border-radius:0; transform:translateX(100%); opacity:1; }
  .sheet.side.show { transform:none; }
}
.sheet .hd { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:14px 18px; border-bottom:1px solid var(--line); }
.sheet .hd h2 { margin:0; font-size:17px; font-weight:700; }
.x { border:0; background:#F2F2F2; border-radius:50%; width:34px; height:34px; display:grid; place-items:center; transition:background .2s; }
.x:hover { background:#E8E8E8; }
.sheet .bd { padding:16px 18px; overflow-y:auto; display:grid; gap:14px; align-content:start; }
.sheet .ft { padding:12px 18px calc(12px + env(safe-area-inset-bottom)); border-top:1px solid var(--line); display:grid; gap:8px; }
.big { width:100%; border:0; border-radius:14px; background:var(--brand); color:#fff; padding:14px; font-weight:700; font-size:15px; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 8px 20px rgba(166,13,18,.25); transition:background .2s, transform .15s; }
.big:hover { background:var(--brand-dark); }
.big:active { transform:scale(.99); }
.big:disabled { background:#C9C9C9; box-shadow:none; cursor:not-allowed; }
.ghost { width:100%; border:1.5px solid var(--brand); border-radius:12px; background:#fff; color:var(--brand); padding:10px; font-weight:600; display:flex; align-items:center; justify-content:center; gap:8px; }

/* area picker — compact, like the old site */
@media (min-width:720px) { .sheet.area { width:470px; } }
.area .band { position:relative; height:84px; background:var(--brand); flex:none; }
.area .band .lg { position:absolute; left:50%; bottom:-34px; transform:translateX(-50%); width:78px; height:78px; border-radius:18px; background:var(--brand); border:4px solid #fff; box-shadow:0 6px 18px rgba(0,0,0,.18); overflow:hidden; display:grid; place-items:center; color:var(--cream); font:italic 700 30px Georgia,serif; }
.area .band .lg img { width:100%; height:100%; object-fit:cover; }
.area .band .x { position:absolute; right:12px; top:12px; background:rgba(255,255,255,.2); color:#fff; }
.sheet.area.locked .band .x { display:none; }
.sheet.area.nudge { animation:nudge .35s ease; }
@keyframes nudge { 25% { margin-left:-8px; } 75% { margin-left:8px; } }
.area .bd { padding-top:46px; gap:12px; }
.area h2 { margin:0; text-align:center; font-size:16px; font-weight:700; }
.seg { display:flex; background:#F2F2F2; border-radius:999px; padding:4px; }
.seg button { flex:1; border:0; background:none; border-radius:999px; padding:8px; font-weight:600; font-size:14px; color:var(--muted); transition:background .25s, color .25s; }
.seg button.on { background:var(--brand); color:#fff; box-shadow:0 4px 12px rgba(166,13,18,.25); }
.gps { border:1.5px solid #F0C5C7; background:#fff; color:var(--brand); border-radius:12px; padding:9px 12px; font-weight:600; font-size:13.5px; display:flex; align-items:center; justify-content:center; gap:8px; transition:background .2s; }
.gps:hover { background:var(--brand-soft); }
.pin { display:flex; gap:10px; align-items:center; background:#F7F7F7; border-radius:12px; padding:9px 12px; font-size:12.5px; animation:fade .3s ease; }
.pin .dot { width:9px; height:9px; border-radius:50%; background:var(--brand); box-shadow:0 0 0 4px #F3C9CB; flex:none; animation:pulse 1.6s infinite; }
@keyframes pulse { 50% { box-shadow:0 0 0 7px rgba(243,201,203,.4); } }
.asearch { width:100%; border:1px solid #E2E2E2; border-radius:12px; padding:9px 12px; font:inherit; font-size:13.5px; }
.alist { border:1px solid var(--line); border-radius:14px; max-height:236px; overflow-y:auto; padding:6px; display:grid; gap:2px; scroll-behavior:smooth; }
.alist button { width:100%; display:flex; justify-content:space-between; align-items:center; gap:10px; border:0; background:none; border-radius:10px; padding:8px 12px; text-align:left; font-size:13px; font-weight:500; transition:background .15s, color .15s; }
.alist button:hover { background:#F6F6F6; }
.alist button.on { background:var(--brand); color:#fff; font-weight:600; }
.alist em { font-style:normal; font-size:11.5px; color:var(--muted); white-space:nowrap; }
.alist button.on em { color:#FFDADB; }
.alist .near { color:var(--brand); font-weight:700; }
.alist button.on .near { color:#fff; }
.alist .ask { margin:4px 4px 8px; font-size:13px; color:var(--muted); text-align:center; }
.bcard { display:flex; gap:12px; align-items:flex-start; margin-top:10px; padding:12px 14px; border-radius:14px; background:var(--brand-soft); border:1px solid #F3C9CB; font-size:13px; line-height:1.5; animation:fade .3s ease; }
.bcard .bic { width:34px; height:34px; border-radius:10px; background:#F7D9DA; color:var(--brand); display:grid; place-items:center; flex:none; }
.bcard a { color:var(--brand); font-weight:600; text-decoration:none; }
.placeline { display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:center; max-width:min(100%, 560px); font-size:12.5px; color:var(--muted); text-align:center; animation:fade .3s ease; }
.placeline b { color:var(--ink); }
.placeline button { border:0; background:none; color:var(--brand); font:inherit; font-weight:700; padding:2px 4px; text-decoration:underline; text-underline-offset:2px; }
.pin .picks { display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin-top:7px; }
.pin .picks small { width:100%; color:var(--muted); }
.pin .pk { border:1px solid #E3E3E3; background:#fff; border-radius:999px; padding:4px 10px; font:inherit; font-size:12px; font-weight:600; color:var(--ink); transition:border-color .2s, color .2s; }
.pin .pk:hover { border-color:var(--brand); color:var(--brand); }
.pin .go { margin-left:auto; border:0; background:var(--brand); color:#fff; border-radius:999px; padding:5px 12px; font-weight:600; font-size:12px; white-space:nowrap; }

/* item sheet */
.hero-img { width:100%; aspect-ratio:16/10; object-fit:cover; border-radius:16px; background:var(--cream); }
.grp { display:grid; gap:8px; }
.grp h4 { margin:0; font-size:14px; display:flex; justify-content:space-between; gap:8px; }
.grp h4 small { font-weight:500; color:var(--muted); }
.grp h4 small.req { color:var(--brand); }
.opt { display:flex; justify-content:space-between; align-items:center; gap:10px; border:1px solid #E2E2E2; border-radius:12px; padding:10px 12px; cursor:pointer; transition:border-color .2s, background .2s; }
.opt.on { border-color:var(--brand); background:var(--brand-soft); }
.opt input { accent-color:var(--brand); width:18px; height:18px; }
.opt span { flex:1; }
.qty { display:inline-flex; align-items:center; gap:2px; border:1px solid #DCDCDC; border-radius:12px; }
.qty button { border:0; background:none; width:38px; height:42px; font-size:18px; }
.qty b { min-width:24px; text-align:center; }
.row { display:flex; gap:10px; align-items:center; }
.row > .big { flex:1; }
textarea, .in { width:100%; border:1px solid #DCDCDC; border-radius:12px; padding:10px 12px; font:inherit; resize:vertical; }
label.f { display:grid; gap:4px; font-size:13px; font-weight:500; }
label.f span.req::after { content:" *"; color:var(--brand); }

/* cart drawer — like the old site */
.cartsheet .hd { background:var(--brand); color:#fff; border-bottom:0; }
.cartsheet .hd h2 { display:flex; align-items:center; gap:10px; }
.cartsheet .hd .x { background:rgba(255,255,255,.18); color:#fff; }
.cartsheet .bd { background:#FBF9F7; }
.citem { position:relative; display:grid; grid-template-columns:64px minmax(0,1fr) auto; gap:12px; align-items:center; background:#fff; border:1px solid #F0EAE4; border-radius:16px; padding:10px; animation:fade .3s ease; }
/* "Remove X from your cart?" — a small popup over the line itself */
.citem .rmask { position:absolute; inset:0; z-index:2; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; padding:10px 14px; border-radius:inherit; background:rgba(255,255,255,.97); box-shadow:0 0 0 2px var(--brand-soft) inset; font-size:13.5px; animation:fade .2s ease; }
.citem .rmask b { color:var(--ink); }
.citem .rbtns { display:flex; gap:8px; margin-left:auto; }
.citem .rbtns button { border:0; border-radius:999px; padding:7px 14px; font:inherit; font-size:13px; font-weight:700; cursor:pointer; }
.citem .rbtns .keep { background:#F2F2F2; color:var(--ink); }
.citem .rbtns .rm { background:var(--brand); color:#fff; }
.citem .rbtns button:focus-visible { outline:3px solid #F3C9CB; outline-offset:2px; }
.citem .th { width:64px; height:64px; border-radius:50%; overflow:hidden; background:var(--brand); }
.citem .th img { width:100%; height:100%; object-fit:cover; }
.citem b { display:block; font-size:14px; line-height:1.25; }
.citem .chipr { display:inline-block; margin-top:3px; background:var(--brand-soft); color:var(--brand); font-size:10.5px; font-weight:700; border-radius:999px; padding:1px 8px; }
.citem small { display:block; color:var(--muted); font-size:11.5px; }
.citem .pr { margin-top:3px; font-size:13px; font-weight:700; }
.citem .pr span { color:var(--brand); }
.citem .step { box-shadow:none; border:1px solid #F0C5C7; animation:none; }
.addmore { border:1.5px dashed #F0A9AC; background:#fff; color:var(--brand); border-radius:14px; padding:10px; font-weight:700; display:flex; align-items:center; justify-content:center; gap:6px; }
.ups h4 { margin:4px 0 10px; display:flex; align-items:center; gap:8px; font-size:14px; border-left:3px solid var(--brand); padding-left:8px; }
.ups h4 .nav { margin-left:auto; display:flex; gap:6px; }
.ups h4 .nav button { width:30px; height:30px; border-radius:50%; border:0; background:#F2B9BB; color:#fff; display:grid; place-items:center; }
.ups .rail { display:flex; gap:12px; overflow-x:auto; scroll-behavior:smooth; scrollbar-width:none; padding-bottom:4px; }
.ups .rail::-webkit-scrollbar { display:none; }
.ucard { flex:none; width:92px; border:0; background:none; padding:0; text-align:left; }
.ucard .ph { width:92px; height:92px; border-radius:16px; }
.ucard .plus { width:26px; height:26px; position:absolute; right:4px; bottom:4px; }
.ucard b { display:block; margin-top:6px; font-size:12.5px; }
.ucard small { display:block; color:var(--muted); font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.totals { background:#fff; border:1px solid #F0EAE4; border-radius:16px; padding:14px 16px; display:grid; gap:10px; font-size:14px; }
.totals div { display:flex; align-items:center; gap:10px; }
.totals div span:nth-child(2) { flex:1; }
.totals .gt { border-top:1px solid var(--line); padding-top:12px; font-weight:800; font-size:16px; }
.totals .gt b { color:var(--brand); font-size:19px; }
.eta { margin:0; font-size:12.5px; color:var(--muted); }
.warn { background:#FFF6DA; border:1px solid #F3DC92; color:#6A4D00; border-radius:12px; padding:10px 12px; font-size:13px; }
.pay { display:grid; gap:8px; }
.pay h4 { margin:0; font-size:14px; }
.pay .opt b { display:block; font-size:14.5px; }
.pay .opt small { color:var(--muted); font-size:12.5px; }
.pay .cash { font-size:22px; line-height:1; }
.map { height:200px; border-radius:12px; overflow:hidden; border:1px solid var(--line); background:#F2F2F2; }
.maphint { margin:-4px 0 0; font-size:12px; color:var(--muted); }
.sharepin { border:1.5px dashed var(--brand); background:#fff; color:var(--brand); border-radius:12px; padding:10px; font-weight:600; width:100%; }
.empty { text-align:center; color:var(--muted); padding:30px 10px; }
@keyframes fade { from { opacity:0; transform:translateY(6px); } }
@keyframes pop { from { opacity:0; transform:scale(.85); } }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }
</style>
</head>
<body>
<noscript><div class="notice"><div class="wrap">Please turn on JavaScript to order online.</div></div></noscript>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="i-pin" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-5.6-7-11a7 7 0 1 1 14 0c0 5.4-7 11-7 11z"/><circle cx="12" cy="10" r="2.6" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
  <symbol id="i-phone" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></symbol>
  <symbol id="i-bag" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l1 14H5zM9 7a3 3 0 0 1 6 0"/></symbol>
  <symbol id="i-cart" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 4h2l2.4 11h10.2L20 7H6.3"/><circle cx="9" cy="19.5" r="1.4" fill="currentColor"/><circle cx="17" cy="19.5" r="1.4" fill="currentColor"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2.2"/><path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="m20 20-3.5-3.5"/></symbol>
  <symbol id="i-right" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></symbol>
  <symbol id="i-chev-l" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" d="m15 5-7 7 7 7"/></symbol>
  <symbol id="i-chev-r" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></symbol>
  <symbol id="i-dl" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m-5-5 5 5 5-5M5 20h14"/></symbol>
  <symbol id="i-up" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" d="m5 15 7-7 7 7"/></symbol>
  <symbol id="i-plus" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2.6" stroke-linecap="round" d="M12 5v14M5 12h14"/></symbol>
  <symbol id="i-minus" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2.6" stroke-linecap="round" d="M5 12h14"/></symbol>
  <symbol id="i-trash" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v6m4-6v6"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2.4" stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></symbol>
  <symbol id="i-flame" viewBox="0 0 24 24"><path fill="#FF8A1F" d="M12 2c1 3.5-1.6 5.3-1.6 8 0 1.5 1 2.6 2.3 2.6 1.8 0 2.6-1.7 2-3.6 2.4 1.6 4.3 4.3 4.3 7.2A7 7 0 0 1 12 23a7 7 0 0 1-7-6.8C5 11 10.8 8.7 12 2z"/><path fill="#FFC23D" d="M12 23a4 4 0 0 1-4-3.9c0-2.5 2.4-3.6 3.2-6.1 1.9 1.4 4.8 3.4 4.8 6.1A4 4 0 0 1 12 23z"/></symbol>
  <symbol id="i-truck" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 6h11v10H3zM14 9h4l3 3v4h-7"/><circle cx="7" cy="18" r="1.8" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17" cy="18" r="1.8" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
  <symbol id="i-calc" viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M8 7h8M8 12h.01M12 12h.01M16 12h.01M8 16h.01M12 16h.01M16 16h.01"/></symbol>
  <symbol id="i-loc" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.2" fill="currentColor"/><circle cx="12" cy="12" r="7.5" fill="none" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 1.5v3M12 19.5v3M1.5 12h3M19.5 12h3"/></symbol>
  <symbol id="i-mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" d="m3.5 6 8.5 7 8.5-7"/></symbol>
  <symbol id="i-wa" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3a.5.5 0 0 0 0-.4l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.4.8 3.2.7a2.8 2.8 0 0 0 1.8-1.3 2.3 2.3 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3z"/></symbol>
  <symbol id="i-fb" viewBox="0 0 24 24"><path fill="currentColor" d="M14 8h3V4.5h-3A4.5 4.5 0 0 0 9.5 9v2H7v3.5h2.5V21H13v-6.5h3l.5-3.5H13V9a1 1 0 0 1 1-1z"/></symbol>
  <symbol id="i-ig" viewBox="0 0 24 24"><rect x="3.5" y="3.5" width="17" height="17" rx="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17.3" cy="6.7" r="1.2" fill="currentColor"/></symbol>
  <symbol id="i-leaf" viewBox="0 0 200 200"><g fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M20 190C70 140 120 90 190 15"/><path d="M45 163c-6-22-4-40 8-56M45 163c22-2 38-10 50-24"/><path d="M70 137c-5-24-2-42 11-58M70 137c24-2 40-11 53-26"/><path d="M96 110c-4-24 0-42 13-57M96 110c24-3 40-12 52-28"/><path d="M122 83c-3-22 1-38 13-52M122 83c22-3 37-12 48-27"/><path d="M148 57c-2-18 2-31 12-42M148 57c18-3 30-10 39-22"/></g></symbol>
</svg>

<div class="notice" id="notice" role="status" hidden><div class="wrap" id="noticeText"></div></div>

<header class="top">
  <div class="wrap">
    <div class="chips">
      <button class="chip" id="where" type="button" aria-haspopup="dialog"><span class="ic"><svg width="16" height="16"><use href="#i-pin"/></svg></span><span><small id="whereType">Delivery</small><b id="whereName">Choose your area</b></span><svg width="14" height="14" aria-hidden="true"><use href="#i-chev-r"/></svg></button>
      <a class="chip phone" id="phoneChip" href="#" hidden><span class="ic"><svg width="16" height="16"><use href="#i-phone"/></svg></span><span id="phoneText"></span></a>
    </div>
    <a class="logo" href="#top" id="logoMark" aria-label="{{ $businessName }}"></a>
    <div class="acts">
      <button class="install" id="installBtn" type="button" aria-label="Install app" title="Install app" hidden><svg width="16" height="16" aria-hidden="true"><use href="#i-dl"/></svg><span>Install app</span></button>
      <button class="cartbtn" id="cartBtn" type="button" aria-label="Open cart"><svg width="24" height="24"><use href="#i-cart"/></svg><span class="n" id="cartCount">0</span></button>
    </div>
  </div>
</header>


<div class="hero" id="top"><div class="wide" id="heroBox"></div></div>

<nav class="cats" aria-label="Menu categories"><div class="cin">
  <button class="carr l off" id="catL" type="button" aria-label="Previous categories"><svg width="16" height="16"><use href="#i-chev-l"/></svg></button>
  <div class="track" id="nav"></div>
  <button class="carr r" id="catR" type="button" aria-label="More categories"><svg width="16" height="16"><use href="#i-chev-r"/></svg></button>
</div></nav>

<div class="wrap finder">
  <form class="sbox" id="sform" role="search"><input id="q" type="search" autocomplete="off" placeholder="Search for Chicken Biryani…" aria-label="Search the menu"><button type="submit" id="qBtn" aria-label="Search"><svg width="18" height="18"><use href="#i-right"/></svg></button></form>
  <div class="status"><span class="pill" id="statusPill"></span><span class="pill" id="etaPill"></span></div>
  <div class="placeline" id="placeLine" hidden></div>
</div>
<div class="wrap" id="liveBox"></div>

<main class="wrap" id="menu" aria-live="polite"></main>

<footer><div class="wrap" id="foot"></div></footer>

<button class="fab search" id="fabSearch" type="button" aria-label="Search the menu"><svg width="22" height="22"><use href="#i-search"/></svg></button>
<a class="fab wa" id="fabWa" href="#" target="_blank" rel="noopener" aria-label="Chat on WhatsApp" hidden><svg width="30" height="30"><use href="#i-wa"/></svg></a>
<button class="fab up off" id="fabUp" type="button" aria-label="Back to top"><svg width="22" height="22"><use href="#i-up"/></svg></button>
<button class="fcart off" id="fcart" type="button" aria-label="Open cart"><svg width="24" height="24"><use href="#i-bag"/></svg><span class="n" id="fcartCount">0</span></button>
<button class="bar" id="bar" type="button"><span class="cnt" id="barCount">0</span><span>View Cart</span><span id="barTotal"></span><svg width="18" height="18" aria-hidden="true"><use href="#i-right"/></svg></button>

<div class="veil" id="veil"></div>

<div class="sheet area" id="areaSheet" role="dialog" aria-modal="true" aria-labelledby="areaTitle">
  <div class="band"><span class="lg" id="areaLogo"></span><button class="x" type="button" data-close aria-label="Close"><svg width="16" height="16"><use href="#i-x"/></svg></button></div>
  <div class="bd">
    <h2 id="areaTitle">Select your order type</h2>
    <div class="seg" role="tablist"><button type="button" id="tDelivery" class="on">Delivery</button><button type="button" id="tPickup">Pick-Up</button></div>
    <button class="gps" type="button" id="gps"><svg width="16" height="16"><use href="#i-loc"/></svg>Use my current location</button>
    <div class="pin" id="gpsPin" hidden><span class="dot"></span><span id="gpsText"></span></div>
    <input class="asearch" id="areaSearch" type="search" placeholder="Search your area" autocomplete="off" aria-label="Search your area">
    <div class="alist" id="areaList" role="listbox" aria-label="Delivery areas"></div>
    <div class="bcard" id="branchCard" hidden></div>
  </div>
  <div class="ft"><button class="big" type="button" id="areaSelect">Select</button></div>
</div>

<div class="sheet" id="itemSheet" role="dialog" aria-modal="true" aria-labelledby="itemTitle">
  <div class="hd"><h2 id="itemTitle"></h2><button class="x" type="button" data-close aria-label="Close"><svg width="16" height="16"><use href="#i-x"/></svg></button></div>
  <div class="bd" id="itemBody"></div>
  <div class="ft"><div class="row"><div class="qty"><button type="button" id="qMinus" aria-label="Less">−</button><b id="qVal">1</b><button type="button" id="qPlus" aria-label="More">+</button></div><button class="big" type="button" id="addBtn">Add to cart</button></div></div>
</div>

<div class="sheet side cartsheet" id="cartSheet" role="dialog" aria-modal="true" aria-labelledby="cartTitle">
  <div class="hd"><h2 id="cartTitle"><svg width="20" height="20" aria-hidden="true"><use href="#i-bag"/></svg><span id="cartTitleText">Your Cart</span></h2><button class="x" type="button" data-close aria-label="Close"><svg width="16" height="16"><use href="#i-x"/></svg></button></div>
  <div class="bd" id="cartBody"></div>
  <div class="ft" id="cartFoot"></div>
</div>

<script>
(function () {
  'use strict';
  var D = @json($payload);
  var KEY = 'bingoo_web_' + location.host;
  var fmt = new Intl.NumberFormat('en-PK', { maximumFractionDigits: 0 });
  var money = function (n) { return 'Rs. ' + fmt.format(Math.round(Number(n) || 0)); };
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
  var icon = function (name, size) { return '<svg width="' + (size || 16) + '" height="' + (size || 16) + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>'; };

  function load() { try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
  function save() { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { /* private mode: still works for this visit */ } }

  var S = Object.assign({ type: 'delivery', area: null, branch: null, cart: [], customer: {}, pin: null }, load());
  var menu = D.menu, menuBranch = D.menuBranch;

  // Motion only when the device allows it; without it everything is simply visible.
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce && 'IntersectionObserver' in window) { document.documentElement.classList.add('anim'); }

  // ── header, logo, footer ──
  var LOGO = D.business.logo;
  function logoHtml() { return LOGO ? '<img src="' + esc(LOGO) + '" alt="' + esc(D.business.name) + '" decoding="async">' : esc((D.business.name || '?').charAt(0)); }
  $('logoMark').innerHTML = logoHtml();
  $('areaLogo').innerHTML = logoHtml();
  if (D.business.phone) {
    $('phoneChip').hidden = false;
    $('phoneChip').href = 'tel:' + D.business.phone.replace(/\s/g, '');
    $('phoneText').textContent = D.business.phone;
  }
  if (D.business.whatsapp) { $('fabWa').hidden = false; $('fabWa').href = 'https://wa.me/' + D.business.whatsapp; }
  (function footer() {
    var b = D.business, li = [];
    if (b.phone) { li.push('<li>' + icon('phone') + '<a href="tel:' + esc(b.phone.replace(/\s/g, '')) + '">' + esc(b.phone) + '</a></li>'); }
    if (b.email) { li.push('<li>' + icon('mail') + '<a href="mailto:' + esc(b.email) + '">' + esc(b.email) + '</a></li>'); }
    if (b.address) { li.push('<li>' + icon('pin') + '<span>' + esc(b.address) + '</span></li>'); }
    var soc = (b.facebook ? '<a href="' + esc(b.facebook) + '" target="_blank" rel="noopener" aria-label="Facebook">' + icon('fb', 18) + '</a>' : '') +
      (b.instagram ? '<a href="' + esc(b.instagram) + '" target="_blank" rel="noopener" aria-label="Instagram">' + icon('ig', 18) + '</a>' : '');
    $('foot').innerHTML =
      '<div><div class="flogo">' + logoHtml() + '</div><h4>' + esc(b.name) + '</h4>' + (b.about ? '<p>' + esc(b.about) + '</p>' : '<p>Order online for delivery or pick-up.</p>') + '</div>' +
      '<div><h4>Contact</h4><ul>' + (li.join('') || '<li>—</li>') + '</ul>' + (soc ? '<h4 style="margin-top:14px">Follow us</h4><div class="soc">' + soc + '</div>' : '') + '</div>' +
      '<div class="fine"><span>Your area, address and cart are remembered on this device · <button type="button" id="forget">Forget</button></span>' +
        '<span>' + ((D.pages || {}).privacy ? '<a href="' + esc(D.pages.privacy) + '">Privacy Policy</a> · ' : '') +
        ((D.pages || {}).faqs ? '<a href="' + esc(D.pages.faqs) + '">FAQs</a> · ' : '') + 'Online ordering by Bingoo</span></div>';
  })();

  // ── banners: first slide right away, the rest load once the page has settled ──
  (function banners() {
    var box = $('heroBox'), list = D.banners || [];
    if (!list.length) { box.innerHTML = '<div class="plain-hero">' + esc(D.business.name) + '</div>'; return; }
    box.innerHTML = '<div class="slider" id="slider" aria-roledescription="carousel" aria-label="Offers">' +
      list.map(function (b, i) {
        return '<div class="slide' + (i === 0 ? ' on' : '') + '" role="group" aria-roledescription="slide" aria-label="' + (i + 1) + ' of ' + list.length + '">' +
          '<img ' + (i === 0 ? 'src' : 'data-src') + '="' + esc(b.src) + '" ' + (i === 0 ? 'srcset' : 'data-srcset') + '="' + esc(b.sm) + ' 960w, ' + esc(b.src) + ' 1920w" sizes="100vw" alt="" decoding="async"' + (i === 0 ? ' fetchpriority="high"' : '') + '></div>';
      }).join('') +
      (list.length > 1 ? '<button class="sarrow prev" type="button" aria-label="Previous slide">' + icon('chev-l', 18) + '</button><button class="sarrow next" type="button" aria-label="Next slide">' + icon('chev-r', 18) + '</button>' +
        '<div class="dots">' + list.map(function (b, i) { return '<button type="button" aria-label="Slide ' + (i + 1) + '"' + (i === 0 ? ' class="on"' : '') + '></button>'; }).join('') + '</div>' : '') + '</div>';
    if (list.length < 2) { return; }
    var slider = $('slider'), slides = slider.querySelectorAll('.slide'), dots = slider.querySelectorAll('.dots button'), at = 0, timer = null;
    var wake = function () { slides.forEach(function (s) { var im = s.querySelector('img[data-src]'); if (im) { im.srcset = im.getAttribute('data-srcset'); im.src = im.getAttribute('data-src'); im.removeAttribute('data-src'); } }); };
    window.addEventListener('load', function () { setTimeout(wake, 600); });
    function go(n) {
      wake();
      at = (n + slides.length) % slides.length;
      slides.forEach(function (s, i) { s.classList.toggle('on', i === at); });
      dots.forEach(function (d, i) { d.classList.toggle('on', i === at); });
    }
    function auto() { clearInterval(timer); if (!reduce) { timer = setInterval(function () { go(at + 1); }, 5000); } }
    slider.querySelector('.prev').addEventListener('click', function () { go(at - 1); auto(); });
    slider.querySelector('.next').addEventListener('click', function () { go(at + 1); auto(); });
    dots.forEach(function (d, i) { d.addEventListener('click', function () { go(i); auto(); }); });
    slider.addEventListener('mouseenter', function () { clearInterval(timer); });
    slider.addEventListener('mouseleave', auto);
    var x0 = null;
    slider.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; clearInterval(timer); }, { passive: true });
    slider.addEventListener('touchend', function (e) { if (x0 === null) { return; } var dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) { go(at + (dx < 0 ? 1 : -1)); } x0 = null; auto(); });
    auto();
  })();

  function area() { return D.areas.find(function (a) { return a.id === S.area; }) || null; }
  function branchOf() { return S.type === 'pickup' ? (S.branch || D.settings.defaultBranch) : (area() ? area().branch : D.settings.defaultBranch); }
  function branchOpen() {
    var b = D.status.branches[branchOf()] || {};
    return D.status.open && (S.type === 'pickup' ? !!b.pickup : !!b.delivery);
  }
  function etaMin() { var a = area(); return S.type === 'pickup' ? D.settings.pickupEta : (a ? a.eta : D.settings.eta); }
  // The restaurant's clock (not the phone's): server time at load + the branch's UTC offset.
  var skew = D.clock ? D.clock.epoch - Date.now() : 0;
  function shopNow() { return new Date(Date.now() + skew + (D.clock ? D.clock.offset : 0) * 60000); }
  function shopMin() { var t = shopNow(); return t.getUTCHours() * 60 + t.getUTCMinutes(); }
  function toMin(h) { var p = String(h || '0:0').split(':'); return (+p[0]) * 60 + (+p[1] || 0); }
  function inWin(from, to, m) { return from < to ? (m >= from && m < to) : (m >= from || m < to); }
  function dur(min) { return min >= 60 ? Math.floor(min / 60) + 'h ' + (min % 60) + 'm' : min + ' min'; }
  function clock12(h) { var m = toMin(h), hh = Math.floor(m / 60) % 24, mm = m % 60; return ((hh % 12) || 12) + ':' + (mm < 10 ? '0' : '') + mm + ' ' + (hh < 12 ? 'AM' : 'PM'); }
  function minutesToClose() {
    if (!D.hours) { return null; }
    var days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'], t = shopNow(), m = shopMin();
    var today = D.hours[days[t.getUTCDay()]] || {}, yday = D.hours[days[(t.getUTCDay() + 6) % 7]] || {};
    if (yday.open && yday.close && !yday.closed && toMin(yday.open) > toMin(yday.close) && m < toMin(yday.close)) { return toMin(yday.close) - m; }
    if (today.open && today.close && !today.closed && inWin(toMin(today.open), toMin(today.close), m)) { return (toMin(today.close) - m + 1440) % 1440; }
    return null;
  }

  function paintStatus() {
    var p = $('statusPill');
    var open = branchOpen();
    p.className = 'pill ' + (open ? 'open' : 'closed');
    var left = open ? minutesToClose() : null;
    p.textContent = open ? (left !== null && left <= 60 ? 'Open · closes in ' + dur(left) : 'Open now') : (D.status.opens_at ? 'Closed · opens ' + D.status.opens_at : 'Closed');
    $('etaPill').textContent = S.type === 'pickup' ? 'Ready in ' + D.settings.pickupEta + ' min' : 'Delivery in ' + etaMin() + ' min';
    var msg = !D.status.open ? D.status.message : (!open ? (S.type === 'pickup' ? 'Pick-up is not available right now.' : 'Delivery is not available right now.') : '');
    $('notice').hidden = !msg;
    $('noticeText').textContent = msg || '';
    $('whereType').textContent = S.type === 'pickup' ? 'Pick-Up' : 'Delivery';
    if (S.type === 'pickup') {
      var br = D.branches.find(function (b) { return b.id === branchOf(); });
      $('whereName').textContent = br ? br.name : 'Choose branch';
    } else {
      var a = area();
      $('whereName').textContent = a ? a.name : 'Choose your area';
    }
    paintPlace();
  }

  // Owner (15 Oct): the place found from the customer's location ("Chappal Sun City, New Rizvia Society") is shown
  // with the area it picked, with Change right there. Hidden once the customer picks another area by hand.
  function paintPlace() {
    var el = $('placeLine'), a = area();
    var on = S.type === 'delivery' && !!a && !!S.place && S.placeArea === a.id;
    el.hidden = !on;
    if (!on) { return; }
    el.innerHTML = '<span>📍 ' + (S.placeSure ? 'Delivering to' : 'Nearest area:') + ' <b>' + esc(a.name) + '</b> · ' + esc(S.place) + '</span>' +
      '<button type="button" id="placeChange">Change</button>';
    $('placeChange').addEventListener('click', openAreas);
  }

  // ── menu ──
  // option id → [card, its first section]; the cart, the steppers and the suggestions all read it.
  var byOption = {};
  function indexMenu() {
    byOption = {};
    menu.forEach(function (c, ci) { c.items.forEach(function (it, ii) { it.options.forEach(function (o) { byOption[o.id] = { card: it, ci: ci, ii: ii }; }); }); });
  }
  function simple(card) { return card.options.length === 1 && !(card.options[0].addons || []).length; }
  function qtyOf(card) {
    var ids = card.options.map(function (o) { return o.id; });
    return S.cart.reduce(function (s, l) { return s + (ids.indexOf(l.option) !== -1 ? l.qty : 0); }, 0);
  }
  function picHtml(src, eager) {
    if (src) { return '<span class="ph"><img src="' + esc(src) + '" alt="" ' + (eager ? '' : 'loading="lazy" ') + 'decoding="async" onload="this.classList.add(\'ok\');this.parentNode.classList.add(\'done\')"></span>'; }
    if (LOGO) { return '<span class="ph logo-ph"><img src="' + esc(LOGO) + '" alt="" loading="lazy" decoding="async" onload="this.classList.add(\'ok\');this.parentNode.classList.add(\'done\')"></span>'; }
    return '<span class="ph done">' + esc((D.business.name || '?').charAt(0)) + '</span>';
  }
  function actHtml(card, ci, ii) {
    var q = qtyOf(card);
    if (!q) { return '<span class="act"><button type="button" class="plus" data-plus="' + ci + ':' + ii + '" aria-label="Add ' + esc(card.name) + '">' + icon('plus', 18) + '</button></span>'; }
    return '<span class="act"><span class="step"><button type="button" data-minus="' + ci + ':' + ii + '" aria-label="' + (q === 1 ? 'Remove' : 'One less') + '">' + icon(q === 1 ? 'trash' : 'minus') + '</button><b>' + q + '</b>' +
      '<button type="button" data-plus="' + ci + ':' + ii + '" aria-label="One more">' + icon('plus') + '</button></span></span>';
  }
  function priceHtml(card) { return '<span class="price">' + (card.options.length > 1 ? '<small>From</small>' : '') + money(card.from) + '</span>'; }
  function tagHtml(card) { return card.tag ? '<span class="tag">' + (card.tag === 'hot_selling' ? 'Hot Selling' : card.tag === 'new' ? 'New' : 'Popular') + '</span>' : ''; }
  function cardHtml(card, ci, ii) {
    var words = card.name + ' ' + (card.description || '');
    return '<div class="card rv" style="--d:' + (ii % 3) * 80 + 'ms" role="button" tabindex="0" data-c="' + ci + '" data-i="' + ii + '" data-name="' + esc(words.toLowerCase()) + '" data-sk="' + esc(skel(words)) + '">' + tagHtml(card) +
      '<span class="txt"><h3>' + esc(card.name) + '</h3><p>' + esc(card.description || '') + '</p>' + priceHtml(card) + '</span>' +
      '<span style="position:relative">' + picHtml(card.thumb) + actHtml(card, ci, ii) + '</span></div>';
  }
  function popularHtml(card, ci, ii, eager) {
    return '<div class="pcard rv" style="--d:' + (ii % 4) * 80 + 'ms" role="button" tabindex="0" data-c="' + ci + '" data-i="' + ii + '">' + tagHtml(card) +
      '<span style="position:relative">' + picHtml(card.img || card.thumb, eager) + actHtml(card, ci, ii) + '</span>' +
      '<h3>' + esc(card.name) + '</h3>' + priceHtml(card) + '</div>';
  }
  var leaves = '<svg class="leaf a" viewBox="0 0 200 200" aria-hidden="true"><use href="#i-leaf"/></svg><svg class="leaf b" viewBox="0 0 200 200" aria-hidden="true"><use href="#i-leaf"/></svg>';

  function renderMenu() {
    indexMenu();
    var nav = [], html = [];
    var popular = [];
    menu.forEach(function (c, ci) { c.items.forEach(function (it, ii) { if (it.popular) { popular.push([ci, ii]); } }); });
    // Best sellers first when "Popular" comes from real sales.
    popular.sort(function (x, y) { var a = menu[x[0]].items[x[1]].rank, b = menu[y[0]].items[y[1]].rank; return (a === null || a === undefined ? 99 : a) - (b === null || b === undefined ? 99 : b); });
    html.push('<div id="again"></div>');
    if (popular.length) {
      html.push('<section class="cat" id="sec-popular" data-popular><div class="phead">' + icon('flame', 34) + '<div><h2>Popular Items</h2><small>' + (D.popularAuto ? 'Most ordered this week' : 'Most ordered right now') + '</small></div></div><div class="pgrid">' +
        popular.slice(0, 8).map(function (p, n) { return popularHtml(menu[p[0]].items[p[1]], p[0], p[1], n < 4); }).join('') + '</div></section>');
    }
    menu.forEach(function (c, ci) {
      var id = 'sec-' + c.id;
      nav.push('<a href="#' + id + '" data-sec="' + id + '">' + esc(c.name) + '</a>');
      html.push('<section class="cat" id="' + id + '"><div class="banner rv">' + leaves + '<h2>' + esc(c.name) + '</h2></div><div class="grid">' +
        c.items.map(function (it, ii) { return cardHtml(it, ci, ii); }).join('') + '</div></section>');
    });
    html.push('<p class="noresult" id="noResult" hidden></p>');
    $('nav').innerHTML = '<span class="ind" id="catInd" aria-hidden="true"></span>' + nav.join('');
    $('menu').innerHTML = menu.length ? html.join('') : '<p class="empty">The menu is being prepared. Please check back soon.</p>';
    observe();
    catArrows();
    applySearch();
    placeholders();
    paintAgain();
    paintLive();
  }
  // Repaint only the "+" / quantity controls after a cart change — the menu itself stays put.
  function paintSteppers() {
    document.querySelectorAll('#menu [data-c]').forEach(function (el) {
      var ci = +el.getAttribute('data-c'), ii = +el.getAttribute('data-i');
      var card = menu[ci] && menu[ci].items[ii];
      var act = el.querySelector('.act');
      if (card && act) { act.outerHTML = actHtml(card, ci, ii); }
    });
  }

  var revealObs = null, spyObs = null;
  function observe() {
    if (!('IntersectionObserver' in window)) { return; }
    if (revealObs) { revealObs.disconnect(); }
    if (spyObs) { spyObs.disconnect(); }
    revealObs = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('seen'); revealObs.unobserve(e.target); } });
    }, { rootMargin: '0px 0px -40px 0px' });
    document.querySelectorAll('.rv').forEach(function (el) { revealObs.observe(el); });
    spyObs = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) { return; }
        var any = false;
        document.querySelectorAll('#nav a').forEach(function (a) {
          var on = a.getAttribute('data-sec') === e.target.id;
          a.classList.toggle('on', on);
          if (on) { any = true; moveInd(a); var t = $('nav'); t.scrollTo({ left: a.offsetLeft - t.clientWidth / 2 + a.clientWidth / 2, behavior: reduce ? 'auto' : 'smooth' }); }
        });
        // Popular (not in the bar) or the top of the page: no category is active — hide the pill, or the
        // last one would keep a white pill with white text.
        if (!any && $('catInd')) { $('catInd').classList.remove('on'); }
      });
    }, { rootMargin: '-80px 0px -65% 0px' });
    document.querySelectorAll('section.cat').forEach(function (s) { spyObs.observe(s); });
  }

  function moveInd(a) {
    var ind = $('catInd');
    if (!ind || !a) { return; }
    ind.style.width = a.offsetWidth + 'px';
    ind.style.transform = 'translateX(' + a.offsetLeft + 'px)';
    ind.classList.add('on');
    $('nav').classList.add('has-ind');
  }
  window.addEventListener('resize', function () { moveInd(document.querySelector('#nav a.on')); });

  // ── category bar arrows (owner: "category next button") ──
  function catArrows() {
    var t = $('nav');
    var atStart = t.scrollLeft < 8, atEnd = t.scrollLeft + t.clientWidth >= t.scrollWidth - 8;
    $('catL').classList.toggle('off', atStart);
    $('catR').classList.toggle('off', atEnd);
    t.classList.toggle('fl', !atStart);
    t.classList.toggle('fr', !atEnd);
  }
  $('nav').addEventListener('scroll', catArrows, { passive: true });
  window.addEventListener('resize', catArrows);
  $('catL').addEventListener('click', function () { $('nav').scrollBy({ left: -$('nav').clientWidth * 0.6, behavior: 'smooth' }); });
  $('catR').addEventListener('click', function () { $('nav').scrollBy({ left: $('nav').clientWidth * 0.6, behavior: 'smooth' }); });

  // ── live search (results while typing, grouped by section) ──
  // Forgiving spelling: "biriyani", "brayani" and "burgr" find Biryani and Burger (consonant skeleton per word).
  function skel(s) {
    return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').split(' ').filter(Boolean)
      .map(function (w) { return w.charAt(0) + w.slice(1).replace(/[aeiouyh]/g, '').replace(/(.)\1+/g, '$1'); }).join(' ');
  }
  function fuzzy(card, q) {
    if (card.getAttribute('data-name').indexOf(q) !== -1) { return true; }
    var have = (card.getAttribute('data-sk') || '').split(' ');
    var want = skel(q).split(' ').filter(function (w) { return w.length >= 2; });
    return want.length > 0 && want.every(function (w) { return have.some(function (h) { return h.indexOf(w) === 0; }); });
  }
  function applySearch() {
    var q = ($('q').value || '').trim().toLowerCase();
    var any = false;
    document.querySelectorAll('#menu section.cat').forEach(function (sec) {
      if (sec.hasAttribute('data-popular')) { sec.hidden = !!q; return; }
      var hits = 0;
      sec.querySelectorAll('.card').forEach(function (c) { var on = !q || fuzzy(c, q); c.hidden = !on; if (on) { hits++; c.classList.add('seen'); } });
      sec.hidden = !!q && !hits;
      if (hits) { any = true; }
    });
    var ag = $('again'); if (ag) { ag.hidden = !!q; }
    var nr = $('noResult');
    if (nr) { nr.hidden = !q || any; nr.textContent = 'Nothing matches “' + q + '”. Try another name.'; }
  }
  $('q').addEventListener('input', applySearch);
  $('sform').addEventListener('submit', function (e) {
    e.preventDefault(); applySearch();
    var first = document.querySelector('#menu section.cat:not([hidden]):not([data-popular])');
    if (first && $('q').value.trim()) { first.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth' }); }
  });
  $('fabSearch').addEventListener('click', function () {
    $('sform').scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
    setTimeout(function () { $('q').focus(); }, reduce ? 0 : 400);
  });
  // The search box suggests real dishes, one after another (stops while the customer types).
  function placeholders() {
    var names = [];
    menu.forEach(function (c) { c.items.forEach(function (it) { if (names.length < 14) { names.push(it.name); } }); });
    if (!names.length || reduce) { return; }
    var i = 0;
    clearInterval(placeholders.t);
    placeholders.t = setInterval(function () {
      if (document.activeElement === $('q') || $('q').value) { return; }
      i = (i + 1) % names.length;
      $('q').placeholder = 'Search for ' + names[i] + '…';
    }, 2600);
  }

  // ── card taps: details, "+" and the quantity control ──
  function at(code) { var p = code.split(':'); return { card: menu[+p[0]].items[+p[1]] }; }
  // The item's picture flies into the cart; a light tap on phones.
  function fly(fromEl) {
    if (navigator.vibrate) { try { navigator.vibrate(12); } catch (e) { /* not allowed */ } }
    if (reduce || !fromEl || !fromEl.getBoundingClientRect || !Element.prototype.animate) { bumpCart(); return; }
    var target = [$('fcart'), $('cartBtn')].filter(function (el) { var r = el.getBoundingClientRect(); return r.width && r.top >= 0 && r.bottom <= innerHeight && !el.classList.contains('off'); })[0] || $('cartBtn');
    var a = fromEl.getBoundingClientRect(), b = target.getBoundingClientRect();
    if (!a.width) { bumpCart(); return; }
    var src = fromEl.tagName === 'IMG' ? fromEl : fromEl.querySelector('img');
    var f = document.createElement(src ? 'img' : 'div');
    if (src) { f.src = src.currentSrc || src.src; f.alt = ''; }
    f.className = 'flyer';
    var size = Math.min(a.width, a.height, 110);
    f.style.left = (a.left + a.width / 2 - size / 2) + 'px'; f.style.top = (a.top + a.height / 2 - size / 2) + 'px';
    f.style.width = size + 'px'; f.style.height = size + 'px';
    document.body.appendChild(f);
    var dx = b.left + b.width / 2 - (a.left + a.width / 2), dy = b.top + b.height / 2 - (a.top + a.height / 2);
    var anim = f.animate([
      { transform: 'translate(0,0) scale(1)', opacity: 1 },
      { transform: 'translate(' + dx * 0.45 + 'px,' + (dy * 0.45 - 90) + 'px) scale(.55)', opacity: 0.95, offset: 0.5 },
      { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.12)', opacity: 0.4 }
    ], { duration: 720, easing: 'cubic-bezier(.5,0,.25,1)' });
    anim.onfinish = function () { f.remove(); bumpCart(); };
  }
  function addSimple(card, fromEl) {
    var o = card.options[0];
    var key = o.id + '||';
    var ex = S.cart.find(function (l) { return l.key === key; });
    if (ex) { ex.qty = Math.min(50, ex.qty + 1); } else { S.cart.push({ key: key, option: o.id, name: card.name, label: o.label, unit: o.price, qty: 1, addons: [], note: '' }); }
    fly(fromEl);
    save(); paintCart();
  }
  function minusCard(card) {
    var ids = card.options.map(function (o) { return o.id; });
    for (var i = S.cart.length - 1; i >= 0; i--) {
      if (ids.indexOf(S.cart[i].option) !== -1) { S.cart[i].qty -= 1; if (S.cart[i].qty <= 0) { S.cart.splice(i, 1); } break; }
    }
    save(); paintCart();
  }
  function bumpCart() {
    ['cartBtn', 'fcart', 'bar'].forEach(function (id) { var el = $(id); el.classList.remove('bump'); void el.offsetWidth; el.classList.add('bump'); });
  }
  $('menu').addEventListener('click', function (e) {
    var plus = e.target.closest('[data-plus]'), minus = e.target.closest('[data-minus]');
    if (plus) { var c = at(plus.getAttribute('data-plus')).card; var holder = plus.closest('[data-c]'); if (simple(c)) { addSimple(c, holder && holder.querySelector('.ph')); } else { openItem(c); } return; }
    if (minus) { minusCard(at(minus.getAttribute('data-minus')).card); return; }
    var b = e.target.closest('[data-c]');
    if (b) { openItem(menu[+b.getAttribute('data-c')].items[+b.getAttribute('data-i')]); }
  });
  $('menu').addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('[data-c]')) { e.preventDefault(); e.target.click(); }
  });

  // ── floating buttons ──
  var headerEl = document.querySelector('.top');
  function onScroll() {
    var y = window.scrollY || 0;
    $('fabUp').classList.toggle('off', y < 600);
    $('fcart').classList.toggle('off', y < headerEl.offsetHeight + 40 || !S.cart.length);
    // The search button brings the search box back — no need while the box itself is on screen.
    $('fabSearch').classList.toggle('off', $('sform').getBoundingClientRect().bottom > 60);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  $('fabUp').addEventListener('click', function () { window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }); });

  // ── sheets ──
  var openSheet = null;
  function show(id) { hide(); openSheet = $(id); openSheet.classList.add('show'); $('veil').classList.add('show'); document.body.style.overflow = 'hidden'; $('bar').classList.remove('show'); document.body.classList.remove('bar-on'); }
  function hide() { if (openSheet) { openSheet.classList.remove('show'); } openSheet = null; $('veil').classList.remove('show'); document.body.style.overflow = ''; paintBar(); }
  // Owner (15 Oct): the area window cannot be closed until an area (or a pick-up branch) is chosen.
  function chosen() { return S.type === 'pickup' ? !!S.branch : !!area(); }
  function dismiss() {
    if (openSheet === $('areaSheet') && !chosen()) {
      var s = $('areaSheet'); s.classList.remove('nudge'); void s.offsetWidth; s.classList.add('nudge');
      return;
    }
    hide();
  }
  $('veil').addEventListener('click', dismiss);
  document.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', dismiss); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { dismiss(); } });

  // ── area / order type: pick in the list, confirm with Select (like the old site) ──
  var pending = null;
  function renderAreas() {
    var q = ($('areaSearch').value || '').toLowerCase();
    var pickup = S.type === 'pickup';
    $('areaSheet').classList.toggle('locked', !chosen());   // no close button until something is chosen
    $('tDelivery').classList.toggle('on', !pickup);
    $('tPickup').classList.toggle('on', pickup);
    $('areaSearch').hidden = pickup;
    $('gps').hidden = pickup;
    var cur = pending !== null ? pending : (pickup ? branchOf() : S.area);
    var rows = pickup
      ? D.branches.map(function (b) { return '<button type="button" role="option" data-b="' + b.id + '" class="' + (cur === b.id ? 'on' : '') + '" aria-selected="' + (cur === b.id) + '"><span>' + esc(b.name) + (b.address ? '<br><em>' + esc(b.address) + '</em>' : '') + '</span><em>Ready in ' + D.settings.pickupEta + ' min</em></button>'; })
      : D.areas.filter(function (a) { return !q || a.name.toLowerCase().indexOf(q) !== -1; })
          .sort(function (x, y) { return q ? 0 : nearRank(x.id) - nearRank(y.id); })
          .map(function (a) { return '<button type="button" role="option" data-a="' + a.id + '" class="' + (cur === a.id ? 'on' : '') + '" aria-selected="' + (cur === a.id) + '"><span>' + esc(a.name) + '</span><em>' + (nearby.indexOf(a.id) !== -1 ? '<span class="near">Near you</span> · ' : '') + a.eta + ' min</em></button>'; });
    $('areaList').innerHTML = (pickup && rows.length ? '<p class="ask">Which outlet would you like to pick-up from?</p>' : '') +
      (rows.length ? rows.join('') : '<p class="empty">' + (pickup ? 'Pick-up is not available.' : 'We do not deliver to this area yet. Try Pick-Up.') + '</p>');
    // Pick-up (like the old site): the chosen outlet's address with "Get directions".
    var br = pickup ? D.branches.find(function (b) { return b.id === cur; }) : null;
    $('branchCard').hidden = !br;
    if (br) {
      $('branchCard').innerHTML = '<span class="bic">' + icon('pin', 18) + '</span><span><b>Branch location</b><br>' + esc(br.address || br.name) +
        (br.directions ? '<br><a href="' + esc(br.directions) + '" target="_blank" rel="noopener">Get directions ↗</a>' : '') + '</span>';
    }
    var on = $('areaList').querySelector('.on');
    if (on) { $('areaList').scrollTop = on.offsetTop - $('areaList').clientHeight / 2 + on.clientHeight / 2; }
    $('areaSelect').disabled = cur === null || cur === undefined;
  }
  function openAreas() { pending = null; userPicked = false; $('areaSearch').value = ''; renderAreas(); show('areaSheet'); }
  $('where').addEventListener('click', openAreas);
  $('tDelivery').addEventListener('click', function () { S.type = 'delivery'; pending = null; renderAreas(); });
  $('tPickup').addEventListener('click', function () { S.type = 'pickup'; pending = null; renderAreas(); });
  $('areaSearch').addEventListener('input', renderAreas);
  $('areaList').addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) { return; }
    pending = +(b.getAttribute('data-a') || b.getAttribute('data-b'));
    userPicked = true;   // a location answer arriving now must not override the customer's own pick
    renderAreas();
  });
  $('areaList').addEventListener('dblclick', function () { $('areaSelect').click(); });
  $('areaSelect').addEventListener('click', function () {
    var sel = pending !== null ? pending : (S.type === 'pickup' ? branchOf() : S.area);
    if (!sel) { return; }
    if (S.type === 'pickup') { S.branch = sel; } else { S.area = sel; }
    pending = null;
    save(); hide(); switchBranch();
  });
  // Owner: location is asked for as soon as the site opens; if it was not given then, tapping
  // "Use my current location" asks again. OpenStreetMap (through our server) names the place; the area
  // whose name matches — or the nearest one anywhere in the city — is picked, and nearby areas move up.
  var nearby = [], locating = false, userPicked = false;
  function nearRank(id) { var i = nearby.indexOf(id); return i === -1 ? 99 : i; }
  // Owner (15 Oct): no Continue — the found area is chosen straight away; the customer can still Change it.
  // Owner (16 Oct): the found area is PRE-SELECTED in the area window — nothing closes by itself; the customer
  // presses Select (or taps another area first). Outside the window (e.g. sharing the pin at checkout) the
  // area the customer chose is never changed.
  function showLocated(a, j) {
    S.placeArea = a.id; S.placeSure = j.by === 'name'; save();
    if (openSheet !== $('areaSheet')) { paintStatus(); return; }
    if (!userPicked) { S.type = 'delivery'; pending = a.id; }
    var sure = j.by === 'name';
    // Not sure (only by distance) = offer the next closest areas; the customer picked by hand = offer the found one.
    var others = (userPicked && pending !== a.id ? [a] : []).concat(sure ? [] : nearby.filter(function (id) { return id !== a.id; }).slice(0, 3)
      .map(function (id) { return D.areas.find(function (x) { return x.id === id; }); }).filter(Boolean));
    gpsMsg('<span>' + (sure ? 'You are in' : 'Nearest area:') + ' <b>' + esc(a.name) + '</b>' + (j.place ? '<br><small>' + esc(j.place) + '</small>' : '') +
      (others.length ? '<span class="picks"><small>' + (userPicked && pending !== a.id ? 'Tap to use it:' : 'Not right? Tap yours:') + '</small>' + others.map(function (o) {
        return '<button type="button" class="pk" data-pick="' + o.id + '">' + esc(o.name) + '</button>';
      }).join('') + '</span>' : '') + '</span>');
    $('gpsText').querySelectorAll('[data-pick]').forEach(function (b) {
      b.addEventListener('click', function () { pending = +b.dataset.pick; userPicked = true; renderAreas(); });
    });
    renderAreas();
    paintStatus();
  }
  function gpsMsg(html) { $('gpsText').innerHTML = html; $('gpsPin').hidden = false; }
  function locateMe(auto, done) {
    if (!navigator.geolocation) { if (!auto) { gpsMsg('This browser cannot share your location. Choose your area below.'); } return; }
    if (locating) { return; }
    var ask = function () {
      locating = true;
      gpsMsg('Finding your location…');
      navigator.geolocation.getCurrentPosition(function (p) {
        locating = false;
        S.pin = { lat: +p.coords.latitude.toFixed(6), lng: +p.coords.longitude.toFixed(6) }; save();
        gpsMsg('Location found — matching your area…');
        if (done) { done(); }
        fetch(D.endpoints.locate + '?lat=' + S.pin.lat + '&lng=' + S.pin.lng, { headers: { Accept: 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
          .then(function (j) {
            nearby = j.nearby || [];
            S.placeHint = j.street || j.place || null;
            S.place = j.label || j.place || null;   // goes with the order's address (staff + rider see it)
            save();
            var a = j.area ? D.areas.find(function (x) { return x.id === j.area; }) : null;
            if (a) {
              showLocated(a, j);
              return;
            } else {
              gpsMsg((j.place ? 'You are near <b>' + esc(j.place) + '</b>. ' : '') + (nearby.length ? 'Areas near you are at the top — pick yours.' : 'Choose your area below.'));
            }
            renderAreas();
          }).catch(function () { gpsMsg('Location saved. Choose your area below.'); });
      }, function (err) {
        locating = false;
        if (err.code === 1) {
          gpsMsg(auto ? 'Tap <b>Use my current location</b> to find your area, or choose it below.'
            : 'Location is blocked for this site. Tap the icon next to the web address → <b>Location → Allow</b>, then try again — or choose your area below.');
        } else {
          gpsMsg('Could not get your location right now. Try again, or choose your area below.');
        }
      }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
    };
    // A tap after "Block" cannot bring the browser's question back — say how to allow it instead.
    if (!auto && navigator.permissions && navigator.permissions.query) {
      navigator.permissions.query({ name: 'geolocation' }).then(function (st) {
        if (st.state === 'denied') { gpsMsg('Location is blocked for this site. Tap the icon next to the web address → <b>Location → Allow</b>, then try again — or choose your area below.'); return; }
        ask();
      }, ask);
      return;
    }
    ask();
  }
  $('gps').addEventListener('click', function () { locateMe(false); });

  function switchBranch() {
    var want = branchOf();
    paintStatus(); paintCart();
    if (want === menuBranch) { return; }
    fetch(D.endpoints.menu + '?branch=' + want, { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (j) { menu = j.menu; menuBranch = j.branch; S.cart = []; save(); renderMenu(); paintCart(); })
      .catch(function () { /* keep the current menu */ });
  }

  // ── item ──
  var cur = null, curOpt = 0, curQty = 1, curAdd = {}, backToCart = false;
  function openItem(card, fromCart) {
    cur = card; curOpt = 0; curQty = 1; curAdd = {}; backToCart = !!fromCart;
    $('itemTitle').textContent = card.name;
    paintItem();
    show('itemSheet');
  }
  function paintItem() {
    var o = cur.options[curOpt];
    var h = [];
    if (cur.img) { h.push('<img class="hero-img" src="' + esc(cur.img) + '" alt="" decoding="async">'); }
    if (cur.description) { h.push('<p style="margin:0;color:var(--muted)">' + esc(cur.description) + '</p>'); }
    if (cur.options.length > 1) {
      h.push('<div class="grp"><h4>Choose an option <small class="req">Required</small></h4>' + cur.options.map(function (op, i) {
        return '<label class="opt' + (i === curOpt ? ' on' : '') + '"><input type="radio" name="size" value="' + i + '"' + (i === curOpt ? ' checked' : '') + '><span>' + esc(op.label || cur.name) + '</span><b>' + money(op.price) + '</b></label>';
      }).join('') + '</div>');
    }
    (o.addons || []).forEach(function (g) {
      var single = g.max === 1;
      h.push('<div class="grp" data-g="' + g.id + '"><h4>' + esc(g.name) + ' <small class="' + (g.min > 0 ? 'req' : '') + '">' +
        (g.min > 0 ? 'Required' : 'Optional') + (g.max > 1 ? ' · up to ' + g.max : '') + '</small></h4>' + g.options.map(function (a) {
          var on = (curAdd[g.id] || []).indexOf(a.id) !== -1;
          return '<label class="opt' + (on ? ' on' : '') + '"><input type="' + (single ? 'radio' : 'checkbox') + '" name="g' + g.id + '" value="' + a.id + '"' + (on ? ' checked' : '') + '><span>' + esc(a.name) + '</span><b>' + (a.price > 0 ? '+' + money(a.price) : '') + '</b></label>';
        }).join('') + '</div>');
    });
    h.push('<label class="f">Special instructions<textarea id="itemNote" rows="2" maxlength="200" placeholder="e.g. less spicy"></textarea></label>');
    $('itemBody').innerHTML = h.join('');
    paintAdd();
  }
  function lineTotal() {
    var o = cur.options[curOpt];
    var extra = 0;
    (o.addons || []).forEach(function (g) { (curAdd[g.id] || []).forEach(function (id) { var a = g.options.find(function (x) { return x.id === id; }); if (a) { extra += a.price; } }); });
    return (o.price + extra) * curQty;
  }
  function missing() {
    var o = cur.options[curOpt];
    return (o.addons || []).filter(function (g) { return (curAdd[g.id] || []).length < g.min; });
  }
  function paintAdd() {
    $('qVal').textContent = curQty;
    var m = missing();
    $('addBtn').textContent = m.length ? 'Choose ' + m[0].name : 'Add to cart · ' + money(lineTotal());
    $('addBtn').disabled = m.length > 0;
  }
  $('itemBody').addEventListener('change', function (e) {
    var t = e.target;
    if (t.name === 'size') { curOpt = +t.value; curAdd = {}; paintItem(); return; }
    var gEl = t.closest('[data-g]');
    if (!gEl) { return; }
    var gid = +gEl.getAttribute('data-g');
    var g = cur.options[curOpt].addons.find(function (x) { return x.id === gid; });
    var picked = Array.prototype.slice.call(gEl.querySelectorAll('input:checked')).map(function (i) { return +i.value; });
    if (picked.length > g.max) { t.checked = false; picked = picked.filter(function (v) { return v !== +t.value; }); }
    curAdd[gid] = picked;
    gEl.querySelectorAll('.opt').forEach(function (l) { l.classList.toggle('on', l.querySelector('input').checked); });
    paintAdd();
  });
  $('qMinus').addEventListener('click', function () { curQty = Math.max(1, curQty - 1); paintAdd(); });
  $('qPlus').addEventListener('click', function () { curQty = Math.min(50, curQty + 1); paintAdd(); });
  $('addBtn').addEventListener('click', function () {
    if (missing().length) { return; }
    var o = cur.options[curOpt];
    var adds = [];
    (o.addons || []).forEach(function (g) { (curAdd[g.id] || []).forEach(function (id) { var a = g.options.find(function (x) { return x.id === id; }); if (a) { adds.push({ g: g.id, id: a.id, name: a.name, price: a.price }); } }); });
    var note = ($('itemNote') && $('itemNote').value || '').trim();
    var key = o.id + '|' + adds.map(function (a) { return a.id; }).sort().join(',') + '|' + note;
    var ex = S.cart.find(function (l) { return l.key === key; });
    if (ex) { ex.qty = Math.min(50, ex.qty + curQty); } else {
      S.cart.push({ key: key, option: o.id, name: cur.name, label: o.label, unit: o.price + adds.reduce(function (s, a) { return s + a.price; }, 0), qty: curQty, addons: adds, note: note });
    }
    var heroPic = $('itemBody').querySelector('.hero-img') || $('addBtn');
    var rect = heroPic.getBoundingClientRect();
    save();
    if (backToCart) { openCart(); } else { hide(); }
    paintCart();
    // fly from where the picture was (the sheet is closing)
    var ghost = document.createElement('div'); ghost.style.cssText = 'position:fixed;left:' + rect.left + 'px;top:' + rect.top + 'px;width:' + rect.width + 'px;height:' + rect.height + 'px;pointer-events:none';
    if (heroPic.tagName === 'IMG') { var gi = document.createElement('img'); gi.src = heroPic.currentSrc || heroPic.src; ghost.appendChild(gi); }
    document.body.appendChild(ghost); fly(ghost); setTimeout(function () { ghost.remove(); }, 50);
  });

  // ── cart ──
  function subtotal() { return S.cart.reduce(function (s, l) { return s + l.unit * l.qty; }, 0); }
  function fee() {
    if (S.type === 'pickup') { return 0; }
    var a = area(); if (!a) { return 0; }
    return (a.freeAbove !== null && subtotal() >= a.freeAbove) ? 0 : a.fee;
  }
  function count() { return S.cart.reduce(function (s, l) { return s + l.qty; }, 0); }
  function paintBar() {
    var n = count();
    $('bar').classList.toggle('show', n > 0 && !openSheet);
    document.body.classList.toggle('bar-on', n > 0 && !openSheet);   // phone: floating buttons step up over the pill
  }
  function paintCart() {
    var n = count();
    if (String(n) !== $('cartCount').textContent) {
      ['cartCount', 'fcartCount', 'barCount'].forEach(function (id) { var el = $(id); el.textContent = n; el.classList.remove('roll'); void el.offsetWidth; el.classList.add('roll'); });
    }
    $('barTotal').textContent = money(subtotal());
    paintBar();
    onScroll();
    paintSteppers();
    if (openSheet === $('cartSheet') && step === 'cart') { renderCart(); }
  }
  function etaText() {
    var t = new Date(Date.now() + etaMin() * 60000);
    var d = t.getDate(), sfx = (d % 10 === 1 && d !== 11) ? 'st' : (d % 10 === 2 && d !== 12) ? 'nd' : (d % 10 === 3 && d !== 13) ? 'rd' : 'th';
    var when = t.toLocaleString('en-US', { month: 'long' }) + ' ' + d + sfx + ', ' + t.getFullYear() + ' at ' + t.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    return (S.type === 'pickup' ? 'Your order will be ready for pick-up in about ' : 'Your order will be delivered approximately in ') + etaMin() + ' minutes, on ' + when + '.';
  }
  function upsells() {
    var cat = menu.find(function (c) { return c.id === D.upsellCategory; });
    if (!cat) { return ''; }
    var ci = menu.indexOf(cat);
    var cards = cat.items.map(function (it, ii) {
      return '<button type="button" class="ucard" data-up="' + ci + ':' + ii + '"><span style="position:relative;display:block">' + picHtml(it.thumb) +
        '<span class="plus" aria-hidden="true">' + icon('plus', 14) + '</span></span><b>' + money(it.from) + '</b><small>' + esc(it.name) + '</small></button>';
    }).join('');
    return '<div class="ups"><h4>' + icon('flame', 18) + 'Popular with your order<span class="nav"><button type="button" data-rail="-1" aria-label="Back">' + icon('chev-l', 14) + '</button><button type="button" data-rail="1" aria-label="More">' + icon('chev-r', 14) + '</button></span></h4><div class="rail" id="rail">' + cards + '</div></div>';
  }
  var step = 'cart';
  function renderCart() {
    step = 'cart';
    $('cartTitleText').textContent = 'Your Cart';
    if (!S.cart.length) {
      $('cartBody').innerHTML = '<p class="empty">Your cart is empty.</p>' + upsells();
      $('cartFoot').innerHTML = '<button class="ghost" type="button" data-close>Browse the menu</button>';
      $('cartFoot').querySelector('[data-close]').addEventListener('click', hide);
      return;
    }
    var a = area();
    var h = S.cart.map(function (l, i) {
      var ref = byOption[l.option];
      var thumb = ref ? ref.card.thumb : null;
      return '<div class="citem"><span class="th">' + (thumb ? '<img src="' + esc(thumb) + '" alt="" loading="lazy">' : (LOGO ? '<img src="' + esc(LOGO) + '" alt="">' : '')) + '</span>' +
        '<div><b>' + esc(l.name) + '</b>' + (l.label ? '<span class="chipr">' + esc(l.label) + '</span>' : '') +
        (l.addons.length ? '<small>' + l.addons.map(function (x) { return esc(x.name); }).join(', ') + '</small>' : '') +
        (l.note ? '<small>“' + esc(l.note) + '”</small>' : '') + '<div class="pr"><span>' + money(l.unit) + '</span></div></div>' +
        '<span class="step"><button type="button" data-q="' + i + '" data-d="-1" aria-label="' + (l.qty === 1 ? 'Remove' : 'One less') + '">' + icon(l.qty === 1 ? 'trash' : 'minus') + '</button><b>' + l.qty + '</b><button type="button" data-q="' + i + '" data-d="1" aria-label="One more">' + icon('plus') + '</button></span>' +
        // Owner (16 Oct): removing an item completely asks first — a small popup on the line itself.
        (askRemove === i ? '<div class="rmask" role="alertdialog" aria-label="Remove item"><span>Remove <b>' + esc(l.name) + '</b> from your cart?</span>' +
          '<span class="rbtns"><button type="button" class="keep" data-keep>Keep</button><button type="button" class="rm" data-rm="' + i + '">Remove</button></span></div>' : '') +
        '</div>';
    });
    if (S.type === 'delivery' && a && a.freeAbove) {
      var pct = Math.min(100, Math.round(subtotal() / a.freeAbove * 100));
      h.push(subtotal() >= a.freeAbove ? '<div class="freebar done">🎉 Free delivery unlocked</div>'
        : '<div class="freebar"><span>Add <b>' + money(a.freeAbove - subtotal()) + '</b> more for <b>free delivery</b></span><span class="track"><span class="fill" style="width:' + pct + '%"></span></span></div>');
    }
    h.push('<button type="button" class="addmore" id="addMore">' + icon('plus') + 'Add more items</button>');
    h.push(upsells());
    var sub = subtotal(), f = fee();
    h.push('<div class="totals"><div>' + icon('calc', 18) + '<span>Total</span><b>' + money(sub) + '</b></div>' +
      (S.type === 'delivery' ? '<div>' + icon('truck', 18) + '<span>Delivery Fee' + (a ? ' <small style="color:var(--muted)">(' + esc(a.name) + ')</small>' : '') + '</span><b>' + (a ? (f > 0 ? money(f) : 'Free') : '—') + '</b></div>' : '') +
      '<div class="gt"><span></span><span>Grand Total</span><b>' + money(sub + f) + '</b></div></div>');
    var warns = [];
    if (S.type === 'delivery' && !a) { warns.push('Choose your delivery area to see the delivery fee.'); }
    if (a && a.min && sub < a.min) { warns.push('Minimum order for ' + esc(a.name) + ' is ' + money(a.min) + '.'); }
    if (!branchOpen()) { warns.push(esc(D.status.message || 'We are not taking orders right now.')); }
    $('cartBody').innerHTML = h.join('') + warns.map(function (w) { return '<div class="warn">' + w + '</div>'; }).join('');
    var blocked = !branchOpen() || (S.type === 'delivery' && !a) || (a && a.min && sub < a.min);
    $('cartFoot').innerHTML = '<button class="big" type="button" id="toCheckout"' + (blocked ? ' disabled' : '') + '>Checkout ' + icon('right', 18) + '</button>' +
      '<p class="eta">' + esc(etaText()) + '</p>';
    $('toCheckout').addEventListener('click', renderCheckout);
    $('addMore').addEventListener('click', hide);
  }
  $('cartBody').addEventListener('click', function (e) {
    var rail = e.target.closest('[data-rail]');
    if (rail) { $('rail').scrollBy({ left: +rail.getAttribute('data-rail') * 200, behavior: 'smooth' }); return; }
    var up = e.target.closest('[data-up]');
    if (up) { var c = at(up.getAttribute('data-up')).card; if (simple(c)) { addSimple(c, up.querySelector('.ph')); } else { openItem(c, true); } return; }
    if (e.target.closest('[data-keep]')) { askRemove = null; renderCart(); return; }
    var rm = e.target.closest('[data-rm]');
    if (rm) { S.cart.splice(+rm.getAttribute('data-rm'), 1); askRemove = null; save(); paintCart(); renderCart(); return; }
    var b = e.target.closest('[data-q]');
    if (!b) { return; }
    var qi = +b.getAttribute('data-q'), l = S.cart[qi], d = +b.getAttribute('data-d');
    // The last one goes only after "Remove" in the small popup.
    if (d < 0 && l.qty <= 1) { askRemove = qi; renderCart(); var k = $('cartBody').querySelector('[data-rm]'); if (k) { k.focus(); } return; }
    askRemove = null;
    l.qty += d;
    save(); paintCart(); renderCart();
  });
  var askRemove = null;   // cart line waiting for "Remove?" — see the cart's click handler
  function openCart() { askRemove = null; renderCart(); show('cartSheet'); }
  $('cartBtn').addEventListener('click', openCart);
  $('fcart').addEventListener('click', openCart);
  $('bar').addEventListener('click', openCart);

  // ── checkout ──
  function renderCheckout() {
    step = 'checkout';
    var c = S.customer || {}, a = area();
    $('cartTitleText').textContent = 'Checkout';
    $('cartBody').innerHTML =
      '<div class="pin"><span class="dot"></span><span>' + (S.type === 'pickup' ? 'Pick-Up order' : 'Delivery to <b>' + esc(a ? a.name : '') + '</b>' +
        (S.place && S.pin ? '<br><small>📍 ' + esc(S.place) + '</small>' : '')) + '</span></div>' +
      '<label class="f"><span class="req">Full name</span><input class="in" id="cName" autocomplete="name" maxlength="120" value="' + esc(c.name || '') + '"></label>' +
      '<label class="f"><span class="req">WhatsApp number</span><input class="in" id="cPhone" inputmode="tel" autocomplete="tel" placeholder="03xx xxxxxxx" maxlength="20" value="' + esc(c.phone || '') + '"></label>' +
      '<label class="f">Another number (optional)<input class="in" id="cAlt" inputmode="tel" maxlength="20" value="' + esc(c.alt || '') + '"></label>' +
      (S.type === 'delivery'
        ? '<label class="f"><span class="req">House / street / flat</span><input class="in" id="cAddr" autocomplete="street-address" maxlength="300" value="' + esc(c.address || '') + '"' + (S.placeHint ? ' placeholder="e.g. House no., ' + esc(S.placeHint) + '"' : '') + '></label>' +
          '<label class="f">Nearest landmark<input class="in" id="cLand" maxlength="150" value="' + esc(c.landmark || '') + '"></label>' +
          (S.pin ? '<div class="map" id="cMap" role="img" aria-label="Your location on the map"></div><p class="maphint">Drag the pin to your door — the rider gets this location.</p>'
            : '<button type="button" class="sharepin" id="sharePin">📍 Share my location (helps the rider find you)</button>')
        : '') +
      '<label class="f">Note for the restaurant<textarea id="cNote" rows="2" maxlength="300"></textarea></label>' +
      '<div class="pay"><h4>Payment</h4><label class="opt on"><span class="cash" aria-hidden="true">💵</span><span><b>Cash on ' + (S.type === 'pickup' ? 'pick-up' : 'delivery') + '</b><small>' +
        (S.type === 'pickup' ? 'Pay in cash at the counter when you collect your order.' : 'Pay the rider in cash when your order arrives.') +
        '</small></span><input type="radio" name="pay" value="cash" checked aria-label="Cash"></label></div>' +
      '<div class="warn" id="cErr" hidden></div>';
    $('cartFoot').innerHTML = '<button class="big" type="button" id="place">Place order · ' + money(subtotal() + fee()) + ' · Cash</button><button class="ghost" type="button" id="backCart">Back to cart</button>';
    $('backCart').addEventListener('click', renderCart);
    $('place').addEventListener('click', placeOrder);
    if ($('sharePin')) {
      $('sharePin').addEventListener('click', function () { keepFields(); locateMe(false, function () { if (step === 'checkout') { keepFields(); renderCheckout(); } }); $('sharePin').textContent = 'Finding your location…'; });
    }
    showMap();
  }
  // Typed but not yet placed: kept when the checkout redraws (e.g. after sharing the location).
  function keepFields() {
    if (!$('cName')) { return; }
    S.customer = Object.assign({}, S.customer || {}, { name: $('cName').value.trim(), phone: $('cPhone').value.trim(), alt: $('cAlt').value.trim(),
      address: $('cAddr') ? $('cAddr').value.trim() : (S.customer || {}).address, landmark: $('cLand') ? $('cLand').value.trim() : (S.customer || {}).landmark });
    save();
  }
  // OpenStreetMap map with a draggable pin — loaded only when the checkout needs it (keeps the site light).
  function loadLeaflet(cb) {
    if (window.L) { cb(); return; }
    if (!document.getElementById('leafletCss')) {
      var css = document.createElement('link'); css.id = 'leafletCss'; css.rel = 'stylesheet';
      css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(css);
    }
    var sc = document.createElement('script'); sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js';
    sc.onload = cb; sc.onerror = function () { var m = $('cMap'); if (m) { m.hidden = true; } }; document.head.appendChild(sc);
  }
  function showMap() {
    var el = $('cMap');
    if (!el || !S.pin) { return; }
    loadLeaflet(function () {
      if (!window.L || !document.body.contains(el)) { return; }
      var map = L.map(el, { scrollWheelZoom: false }).setView([S.pin.lat, S.pin.lng], 17);
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
      var mk = L.marker([S.pin.lat, S.pin.lng], { draggable: true, autoPan: true }).addTo(map);
      mk.on('dragend', function () { var ll = mk.getLatLng(); S.pin = { lat: +ll.lat.toFixed(6), lng: +ll.lng.toFixed(6) }; save(); });
      map.on('click', function (e) { mk.setLatLng(e.latlng); S.pin = { lat: +e.latlng.lat.toFixed(6), lng: +e.latlng.lng.toFixed(6) }; save(); });
      setTimeout(function () { map.invalidateSize(); }, 300);
    });
  }
  function placeOrder() {
    var c = {
      name: $('cName').value.trim(), phone: $('cPhone').value.trim(), alt: $('cAlt').value.trim(),
      address: $('cAddr') ? $('cAddr').value.trim() : '', landmark: $('cLand') ? $('cLand').value.trim() : ''
    };
    S.customer = c; save();
    var err = [];
    if (!c.name) { err.push('Enter your name.'); }
    if (!/^(\+?92|0)?3\d{9}$/.test(c.phone.replace(/[\s-]/g, ''))) { err.push('Enter a valid mobile number, e.g. 0300 1234567.'); }
    if (S.type === 'delivery' && !c.address) { err.push('Enter your address.'); }
    var box = $('cErr');
    box.hidden = !err.length; box.innerHTML = err.map(esc).join('<br>');
    if (err.length) { return; }

    // One key per attempt: a double tap or a retry after a timeout gives the SAME order.
    if (!S.pendingKey) { S.pendingKey = uuid(); save(); }
    var btn = $('place');
    btn.disabled = true; btn.textContent = 'Placing your order…';
    var body = {
      key: S.pendingKey, type: S.type, area: S.type === 'delivery' ? S.area : null, branch: S.type === 'pickup' ? branchOf() : null,
      customer: { name: c.name, phone: c.phone, alt: c.alt || null, address: c.address || null, landmark: c.landmark || null,
        lat: S.pin ? S.pin.lat : null, lng: S.pin ? S.pin.lng : null, place: S.pin && S.place ? S.place : null },
      note: ($('cNote').value || '').trim() || null,
      payment: 'cash',
      lines: S.cart.map(function (l) { return { option: l.option, qty: l.qty, addons: l.addons.map(function (a) { return a.id; }), note: l.note || null }; })
    };
    fetch(D.endpoints.checkout, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok) {
          S.last = { lines: S.cart.slice(0, 30), at: Date.now() };
          S.cart = []; S.pendingKey = null; S.lastOrder = res.j.status_url; save();
          location.href = res.j.status_url;
          return;
        }
        var msgs = res.j && res.j.errors ? Object.keys(res.j.errors).filter(function (k) { return k !== 'meta'; }).map(function (k) { return res.j.errors[k][0]; }) : [];
        box.hidden = false; box.innerHTML = (msgs.length ? msgs : [res.j && res.j.message || 'Could not place the order. Please try again.']).map(esc).join('<br>');
        btn.disabled = false; btn.textContent = 'Place order · ' + money(subtotal() + fee()) + ' · Cash';
        if (res.j && res.j.errors && res.j.errors.lines) { S.pendingKey = null; save(); }
      })
      .catch(function () {
        box.hidden = false; box.textContent = 'No connection. Please check your internet and try again — you will not be charged twice.';
        btn.disabled = false; btn.textContent = 'Place order · ' + money(subtotal() + fee()) + ' · Cash';
      });
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (ch) {
      var r = Math.random() * 16 | 0; return (ch === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  // ── order again: last order at today's prices; missing items are left out (and said so) ──
  function againLines() {
    if (!S.last || !S.last.lines) { return { lines: [], missing: 0 }; }
    var missing = 0;
    var lines = S.last.lines.map(function (l) {
      var ref = byOption[l.option];
      var o = ref ? ref.card.options.filter(function (x) { return x.id === l.option; })[0] : null;
      if (!o) { missing++; return null; }
      var adds = [], ok = true;
      (l.addons || []).forEach(function (a) {
        var g = (o.addons || []).filter(function (x) { return x.id === a.g; })[0];
        var m = g ? g.options.filter(function (x) { return x.id === a.id; })[0] : null;
        if (m) { adds.push({ g: g.id, id: m.id, name: m.name, price: m.price }); } else { ok = false; }
      });
      if (!ok) { missing++; return null; }
      return { key: l.key, option: o.id, name: ref.card.name, label: o.label, unit: o.price + adds.reduce(function (s, a) { return s + a.price; }, 0), qty: l.qty, addons: adds, note: l.note || '' };
    }).filter(Boolean);
    return { lines: lines, missing: missing };
  }
  function paintAgain() {
    var box = $('again');
    if (!box) { return; }
    var g = againLines();
    if (!g.lines.length || S.cart.length) { box.innerHTML = ''; return; }
    var total = g.lines.reduce(function (s, l) { return s + l.unit * l.qty; }, 0);
    var list = g.lines.slice(0, 3).map(function (l) { return l.qty + '× ' + esc(l.name) + (l.label ? ' (' + esc(l.label) + ')' : ''); }).join(', ') + (g.lines.length > 3 ? ' +' + (g.lines.length - 3) + ' more' : '');
    box.innerHTML = '<div class="again"><span class="ic">' + icon('bag', 22) + '</span><span class="tx"><b>Order again</b><small>' + list + ' · ' + money(total) + (g.missing ? ' · some items from last time are not available' : '') + '</small></span>' +
      '<button type="button" class="big" id="againBtn">Add to cart ' + icon('right', 16) + '</button></div>';
    $('againBtn').addEventListener('click', function () {
      g.lines.forEach(function (l) { var ex = S.cart.filter(function (x) { return x.key === l.key; })[0]; if (ex) { ex.qty = Math.min(50, ex.qty + l.qty); } else { S.cart.push(l); } });
      fly(box.querySelector('.ic'));
      save(); paintCart(); paintAgain(); openCart();
    });
  }

  // ── Midnight Deals (any time-windowed section): live strip with countdown, or "starts at" ──
  var liveSeen = {};
  function paintLive() {
    var box = $('liveBox'), m = shopMin(), out = '', refresh = false;
    (D.windows || []).forEach(function (w) {
      var from = toMin(w.from), to = toMin(w.to), live = inWin(from, to, m);
      var link = document.querySelector('#nav a[data-sec="sec-' + w.id + '"]');
      if (link && !link.querySelector('.lv') && live) { link.insertAdjacentHTML('beforeend', '<i class="lv" aria-label="live"></i>'); }
      if (live) {
        if (!document.getElementById('sec-' + w.id)) { refresh = refresh || !liveSeen[w.id]; }
        liveSeen[w.id] = true;
        out += '<div class="live"><span class="moon" aria-hidden="true">🌙</span><span><b>' + esc(w.name) + ' — live now</b><small>Ends in ' + dur((to - m + 1440) % 1440) + ' · until ' + clock12(w.to) + '</small></span>' +
          '<button type="button" data-live="' + w.id + '">See deals</button></div>';
      } else {
        if (liveSeen[w.id] && document.getElementById('sec-' + w.id)) { refresh = true; }
        liveSeen[w.id] = false;
        var wait = (from - m + 1440) % 1440;
        if (wait <= 240) { out += '<div class="live soon"><span class="moon" aria-hidden="true">🌙</span><span><b>' + esc(w.name) + ' start at ' + clock12(w.from) + '</b><small>In ' + dur(wait) + '</small></span></div>'; }
      }
    });
    box.innerHTML = out;
    // A window opened (or closed) while the page was open — fetch the menu for the new time.
    if (refresh) {
      fetch(D.endpoints.menu + '?branch=' + menuBranch, { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function (j) { menu = j.menu; renderMenu(); paintCart(); }).catch(function () { /* keep */ });
    }
  }
  $('liveBox').addEventListener('click', function (e) {
    var b = e.target.closest('[data-live]');
    var sec = b && document.getElementById('sec-' + b.getAttribute('data-live'));
    if (sec) { sec.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth' }); }
  });
  setInterval(function () { paintLive(); paintStatus(); }, 30000);

  // ── install as an app (only on the restaurant's own domain) ──
  var installEvt = null;
  if (D.installable && 'serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () { /* fine without */ }); });
    window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); installEvt = e; $('installBtn').hidden = false; });
    $('installBtn').addEventListener('click', function () {
      if (!installEvt) { return; }
      installEvt.prompt();
      installEvt.userChoice.then(function () { installEvt = null; $('installBtn').hidden = true; });
    });
  }

  $('forget').addEventListener('click', function () {
    try { localStorage.removeItem(KEY); } catch (e) { /* nothing stored */ }
    S = { type: 'delivery', area: null, branch: null, cart: [], customer: {}, pin: null, placeHint: null, place: null, placeArea: null };
    nearby = [];
    paintStatus(); paintCart();
  });

  // ── start ──
  // A link may carry the area (?area=12) — e.g. a promotion for one neighbourhood.
  var qArea = +(new URLSearchParams(location.search).get('area') || 0);
  if (qArea && D.areas.some(function (a) { return a.id === qArea; })) { S.type = 'delivery'; S.area = qArea; save(); }
  if (S.area && !area()) { S.area = null; }
  renderMenu(); paintStatus(); paintCart();
  if (branchOf() !== menuBranch) { switchBranch(); }
  if (S.type === 'delivery' && !S.area && D.areas.length) { renderAreas(); show('areaSheet'); locateMe(true); }
})();
</script>
</body>
</html>
