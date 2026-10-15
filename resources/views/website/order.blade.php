<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $businessName }} — Order Online</title>
<meta name="description" content="Order online from {{ $businessName }}. Delivery and pick-up.">
<meta name="theme-color" content="#A60D12">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Allura&display=swap">
<style>
/* ONLINE-ORDERING-1 — the ordering website. One light page: no framework, images lazy, motion only when the
   device allows it. Content is visible without JavaScript-driven animation (html.anim is set by JS). */
:root { --brand:#A60D12; --brand-dark:#7E090D; --cream:#F7EBDD; --ink:#161616; --muted:#6B6B6B; --line:#ECECEC;
  --tag:#FFCB2F; --bg:#FAFAF8; --card:#FFFFFF; --ok:#1E7A4C; --shadow:0 6px 24px rgba(0,0,0,.08); }
* { box-sizing:border-box; }
[hidden] { display:none !important; }
html { scroll-behavior:smooth; }
body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.5 "Poppins","Segoe UI",system-ui,sans-serif; -webkit-font-smoothing:antialiased; }
button { font:inherit; color:inherit; cursor:pointer; }
img { display:block; max-width:100%; }
.wrap { max-width:1180px; margin:0 auto; padding:0 max(16px, env(safe-area-inset-left)); }

/* header */
.top { position:sticky; top:0; z-index:30; background:#fff; border-bottom:1px solid var(--line); }
/* minmax(0,…) — the side columns may shrink below their text; plain 1fr made both sides as wide as the
   area name and pushed the page wider than a phone screen. */
.top .wrap { display:grid; grid-template-columns:minmax(0,1fr) auto minmax(0,1fr); align-items:center; gap:10px; height:64px; }
.chip { justify-self:start; display:flex; align-items:center; gap:8px; border:1px solid var(--line); background:#fff; border-radius:999px; padding:6px 12px; max-width:100%; min-width:0; text-align:left; }
.chip > span:first-child { min-width:0; }
.chip small { display:block; font-size:10px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); line-height:1.1; }
.chip b { display:block; font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
@media (max-width:420px) { .cartbtn > span:first-child { display:none; } .cartbtn { padding:9px 12px; } .hero h1 { font-size:40px; } }
.logo { display:flex; align-items:center; gap:10px; text-decoration:none; color:inherit; }
.logo .mark { width:44px; height:44px; border-radius:50%; background:var(--brand); color:var(--cream); display:grid; place-items:center; font:italic 700 22px Georgia,serif; overflow:hidden; }
.logo .mark img { width:100%; height:100%; object-fit:cover; }
.logo .nm { font-weight:700; font-size:16px; display:none; }
@media (min-width:720px) { .logo .nm { display:block; } }
.cartbtn { justify-self:end; position:relative; display:flex; align-items:center; gap:8px; border:0; background:var(--brand); color:#fff; border-radius:999px; padding:9px 16px; font-weight:600; }
.cartbtn .n { background:#fff; color:var(--brand); border-radius:999px; min-width:22px; height:22px; display:grid; place-items:center; font-size:12px; padding:0 6px; }
.cartbtn.bump { animation:bump .45s ease; }
@keyframes bump { 30% { transform:scale(1.12); } 60% { transform:scale(.96); } }

/* hero + status */
.hero { background:var(--cream); }
.hero .wrap { padding-top:22px; padding-bottom:22px; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; }
.hero h1 { margin:0; font:400 46px/1 "Allura",cursive; color:var(--brand); }
.pills { display:flex; flex-wrap:wrap; gap:8px; }
.pill { background:#fff; border:1px solid var(--line); border-radius:999px; padding:5px 12px; font-size:13px; font-weight:500; }
.pill.open { color:var(--ok); border-color:#BFE5CF; background:#EFFAF3; }
.pill.closed { color:var(--brand); border-color:#F3C9CB; background:#FDF0F0; }
.notice { background:#FDF0F0; color:var(--brand-dark); border-bottom:1px solid #F3C9CB; font-size:14px; }
.notice .wrap { padding-top:10px; padding-bottom:10px; }

/* category nav */
.cats { position:sticky; top:64px; z-index:20; background:var(--brand); }
.cats .wrap { display:flex; gap:6px; overflow-x:auto; padding-top:8px; padding-bottom:8px; scrollbar-width:none; }
.cats .wrap::-webkit-scrollbar { display:none; }
.cats a { flex:none; color:#fff; text-decoration:none; font-size:13px; font-weight:600; padding:6px 14px; border-radius:999px; white-space:nowrap; transition:background .2s,color .2s; }
.cats a.on { background:#fff; color:var(--brand); }

/* sections + cards */
section.cat { padding-top:26px; scroll-margin-top:120px; }
.banner { background:var(--cream); border-radius:16px; text-align:center; padding:14px; margin-bottom:16px; font:400 44px/1.05 "Allura",cursive; color:var(--brand); }
.grid { display:grid; grid-template-columns:1fr; gap:12px; }
@media (min-width:640px) { .grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (min-width:980px) { .grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }
.card { position:relative; display:grid; grid-template-columns:1fr 112px; gap:12px; text-align:left; background:var(--card); border:1px solid var(--line); border-radius:14px; padding:12px; min-height:128px; transition:box-shadow .2s, transform .2s; }
.card:hover { box-shadow:var(--shadow); transform:translateY(-2px); }
.card h3 { margin:0 0 4px; font-size:15px; font-weight:600; }
.card p { margin:0; font-size:12.5px; color:var(--muted); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.card .price { margin-top:10px; display:inline-block; font-weight:700; font-size:13px; background:#F4F4F4; border-radius:8px; padding:3px 8px; }
.ph { width:112px; height:112px; border-radius:12px; overflow:hidden; background:linear-gradient(135deg,#F2E3D0,#F7EFE6); display:grid; place-items:center; color:#C9A27A; font:italic 700 30px Georgia,serif; }
.ph img { width:100%; height:100%; object-fit:cover; opacity:0; transition:opacity .4s; }
.ph img.ok { opacity:1; }
.add { position:absolute; right:10px; bottom:10px; width:30px; height:30px; border-radius:50%; background:var(--brand); color:#fff; display:grid; place-items:center; font-size:20px; line-height:1; box-shadow:0 2px 8px rgba(166,13,18,.35); }
.tag { position:absolute; top:-9px; left:14px; background:var(--tag); color:#2B2100; font-size:11px; font-weight:700; padding:2px 10px; border-radius:999px; }
html.anim .rv { opacity:0; transform:translateY(14px); }
html.anim .rv.in { opacity:1; transform:none; transition:opacity .5s ease, transform .5s cubic-bezier(.2,.7,.2,1); }
footer { margin-top:40px; background:#fff; border-top:1px solid var(--line); font-size:13px; color:var(--muted); }
footer .wrap { padding-top:18px; padding-bottom:calc(84px + env(safe-area-inset-bottom)); display:grid; gap:6px; }
footer button { border:0; background:none; color:var(--brand); text-decoration:underline; padding:0; font-size:13px; }

/* floating cart bar (mobile) */
.bar { position:fixed; left:12px; right:12px; bottom:calc(12px + env(safe-area-inset-bottom)); z-index:25; display:none; justify-content:space-between; align-items:center; background:var(--brand); color:#fff; border:0; border-radius:14px; padding:13px 16px; font-weight:600; box-shadow:0 8px 24px rgba(166,13,18,.35); }
.bar.show { display:flex; }
@media (min-width:980px) { .bar { display:none !important; } }

/* sheets (modal / drawer) */
.veil { position:fixed; inset:0; z-index:40; background:rgba(0,0,0,.45); display:none; }
.veil.show { display:block; }
.sheet { position:fixed; z-index:50; background:#fff; display:none; flex-direction:column; max-height:92vh; overflow:hidden;
  left:0; right:0; bottom:0; border-radius:20px 20px 0 0; }
.sheet.show { display:flex; animation:up .28s cubic-bezier(.2,.7,.2,1); }
@media (min-width:720px) { .sheet { left:50%; right:auto; bottom:auto; top:50%; width:560px; transform:translate(-50%,-50%); border-radius:20px; }
  .sheet.show { animation:pop .22s ease; } .sheet.side { left:auto; right:0; top:0; bottom:0; transform:none; width:420px; max-height:none; border-radius:0; } }
@keyframes up { from { transform:translateY(40px); opacity:0; } }
@keyframes pop { from { opacity:0; } }
.sheet .hd { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:14px 16px; border-bottom:1px solid var(--line); }
.sheet .hd h2 { margin:0; font-size:17px; }
.x { border:0; background:#F2F2F2; border-radius:50%; width:34px; height:34px; font-size:18px; }
.sheet .bd { padding:16px; overflow-y:auto; display:grid; gap:14px; }
.sheet .ft { padding:12px 16px calc(12px + env(safe-area-inset-bottom)); border-top:1px solid var(--line); display:grid; gap:8px; }
.big { width:100%; border:0; border-radius:12px; background:var(--brand); color:#fff; padding:13px; font-weight:700; font-size:15px; }
.big:disabled { background:#C9C9C9; cursor:not-allowed; }
.ghost { width:100%; border:1.5px solid var(--brand); border-radius:12px; background:#fff; color:var(--brand); padding:11px; font-weight:600; }
.seg { display:flex; background:#F2F2F2; border-radius:999px; padding:4px; }
.seg button { flex:1; border:0; background:none; border-radius:999px; padding:8px; font-weight:600; color:var(--muted); }
.seg button.on { background:var(--brand); color:#fff; }
.search { width:100%; border:1px solid #DCDCDC; border-radius:12px; padding:11px 12px; font:inherit; }
.list { border:1px solid var(--line); border-radius:12px; overflow:hidden; }
.list button { width:100%; display:flex; justify-content:space-between; gap:10px; border:0; border-bottom:1px solid var(--line); background:#fff; padding:11px 12px; text-align:left; }
.list button:last-child { border-bottom:0; }
.list button.on { background:#FCEDEE; color:var(--brand-dark); font-weight:600; }
.list em { font-style:normal; color:var(--muted); font-size:12.5px; white-space:nowrap; }
.hero-img { width:100%; aspect-ratio:16/10; object-fit:cover; border-radius:14px; background:var(--cream); }
.grp { display:grid; gap:8px; }
.grp h4 { margin:0; font-size:14px; display:flex; justify-content:space-between; gap:8px; }
.grp h4 small { font-weight:500; color:var(--muted); }
.grp h4 small.req { color:var(--brand); }
.opt { display:flex; justify-content:space-between; align-items:center; gap:10px; border:1px solid #E2E2E2; border-radius:12px; padding:10px 12px; }
.opt.on { border-color:var(--brand); background:#FCEDEE; }
.opt input { accent-color:var(--brand); width:18px; height:18px; }
.opt span { flex:1; }
.qty { display:inline-flex; align-items:center; gap:2px; border:1px solid #DCDCDC; border-radius:12px; }
.qty button { border:0; background:none; width:38px; height:40px; font-size:18px; }
.qty b { min-width:24px; text-align:center; }
.row { display:flex; gap:10px; align-items:center; }
.row > .big { flex:1; }
textarea, .in { width:100%; border:1px solid #DCDCDC; border-radius:12px; padding:10px 12px; font:inherit; resize:vertical; }
label.f { display:grid; gap:4px; font-size:13px; font-weight:500; }
label.f span.req::after { content:" *"; color:var(--brand); }
.line { display:grid; grid-template-columns:1fr auto; gap:6px 10px; padding-bottom:12px; border-bottom:1px solid var(--line); }
.line small { color:var(--muted); display:block; }
.sum { display:grid; gap:6px; font-size:14px; }
.sum div { display:flex; justify-content:space-between; }
.sum .gt { font-weight:700; font-size:16px; color:var(--brand); border-top:1px solid var(--line); padding-top:8px; }
.warn { background:#FFF6DA; border:1px solid #F3DC92; color:#6A4D00; border-radius:12px; padding:10px 12px; font-size:13px; }
.pin { display:flex; gap:10px; align-items:center; background:#F7F7F7; border-radius:12px; padding:10px 12px; font-size:13px; }
.pin .dot { width:10px; height:10px; border-radius:50%; background:var(--brand); box-shadow:0 0 0 4px #F3C9CB; flex:none; }
/* ONLINE-ORDERING-1: location result, "near you", payment, map pin */
.pin .go { margin-left:auto; border:0; background:var(--brand); color:#fff; border-radius:999px; padding:6px 12px; font-weight:600; font-size:12.5px; white-space:nowrap; }
.list .near { color:var(--brand); font-weight:600; }
.pay { display:grid; gap:8px; }
.pay h4 { margin:0; font-size:14px; }
.pay .opt b { display:block; font-size:14.5px; }
.pay .opt small { color:var(--muted); font-size:12.5px; }
.pay .cash { font-size:22px; line-height:1; }
.map { height:200px; border-radius:12px; overflow:hidden; border:1px solid var(--line); background:#F2F2F2; }
.maphint { margin:-4px 0 0; font-size:12px; color:var(--muted); }
.sharepin { border:1.5px dashed var(--brand); background:#fff; color:var(--brand); border-radius:12px; padding:10px; font-weight:600; width:100%; }
.empty { text-align:center; color:var(--muted); padding:30px 10px; }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }
</style>
</head>
<body>
<noscript><div class="notice"><div class="wrap">Please turn on JavaScript to order online.</div></div></noscript>

<header class="top">
  <div class="wrap">
    <button class="chip" id="where" type="button" aria-haspopup="dialog"><span><small id="whereType">Delivery</small><b id="whereName">Choose your area</b></span><span aria-hidden="true">›</span></button>
    <a class="logo" href="#" aria-label="{{ $businessName }}"><span class="mark" id="logoMark"></span><span class="nm">{{ $businessName }}</span></a>
    <button class="cartbtn" id="cartBtn" type="button" aria-label="Open cart"><span>Cart</span><span class="n" id="cartCount">0</span></button>
  </div>
</header>

<div class="notice" id="notice" hidden><div class="wrap" id="noticeText"></div></div>

<div class="hero"><div class="wrap">
  <h1>{{ $businessName }}</h1>
  <div class="pills"><span class="pill" id="statusPill"></span><span class="pill" id="etaPill"></span></div>
</div></div>

<nav class="cats" aria-label="Menu categories"><div class="wrap" id="nav"></div></nav>

<main class="wrap" id="menu" aria-live="polite"></main>

<footer><div class="wrap">
  <strong style="color:var(--ink)">{{ $businessName }}</strong>
  <span id="footPhone"></span>
  <span>Your area, address and cart are remembered on this device · <button type="button" id="forget">Forget</button></span>
  <span>Online ordering by Bingoo</span>
</div></footer>

<button class="bar" id="bar" type="button"><span id="barCount">View cart</span><span id="barTotal"></span></button>

<div class="veil" id="veil"></div>

<div class="sheet" id="areaSheet" role="dialog" aria-modal="true" aria-labelledby="areaTitle">
  <div class="hd"><h2 id="areaTitle">How would you like your order?</h2><button class="x" type="button" data-close aria-label="Close">×</button></div>
  <div class="bd">
    <div class="seg" role="tablist"><button type="button" id="tDelivery" class="on">Delivery</button><button type="button" id="tPickup">Pick-Up</button></div>
    <button class="ghost" type="button" id="gps">⌖ Use my current location</button>
    <div class="pin" id="gpsPin" hidden><span class="dot"></span><span id="gpsText"></span></div>
    <input class="search" id="areaSearch" type="search" placeholder="Search your area" autocomplete="off">
    <div class="list" id="areaList"></div>
  </div>
</div>

<div class="sheet" id="itemSheet" role="dialog" aria-modal="true" aria-labelledby="itemTitle">
  <div class="hd"><h2 id="itemTitle"></h2><button class="x" type="button" data-close aria-label="Close">×</button></div>
  <div class="bd" id="itemBody"></div>
  <div class="ft"><div class="row"><div class="qty"><button type="button" id="qMinus" aria-label="Less">−</button><b id="qVal">1</b><button type="button" id="qPlus" aria-label="More">+</button></div><button class="big" type="button" id="addBtn">Add to cart</button></div></div>
</div>

<div class="sheet side" id="cartSheet" role="dialog" aria-modal="true" aria-labelledby="cartTitle">
  <div class="hd"><h2 id="cartTitle">Your cart</h2><button class="x" type="button" data-close aria-label="Close">×</button></div>
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

  function load() { try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
  function save() { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { /* private mode: still works for this visit */ } }

  var S = Object.assign({ type: 'delivery', area: null, branch: null, cart: [], customer: {}, pin: null }, load());
  var menu = D.menu, menuBranch = D.menuBranch;

  // Motion only when the device allows it; without it everything is simply visible.
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce && 'IntersectionObserver' in window) { document.documentElement.classList.add('anim'); }

  // ── header bits ──
  var mark = $('logoMark');
  if (D.business.logo) { mark.innerHTML = '<img src="' + esc(D.business.logo) + '" alt="">'; } else { mark.textContent = (D.business.name || '?').charAt(0); }
  if (D.business.phone) { $('footPhone').innerHTML = 'Call us: <a href="tel:' + esc(D.business.phone.replace(/\s/g, '')) + '" style="color:var(--brand)">' + esc(D.business.phone) + '</a>'; }

  function area() { return D.areas.find(function (a) { return a.id === S.area; }) || null; }
  function branchOf() { return S.type === 'pickup' ? (S.branch || D.settings.defaultBranch) : (area() ? area().branch : D.settings.defaultBranch); }
  function branchOpen() {
    var b = D.status.branches[branchOf()] || {};
    return D.status.open && (S.type === 'pickup' ? !!b.pickup : !!b.delivery);
  }

  function paintStatus() {
    var p = $('statusPill');
    var open = branchOpen();
    p.className = 'pill ' + (open ? 'open' : 'closed');
    p.textContent = open ? 'Open now' : 'Closed';
    var a = area();
    $('etaPill').textContent = S.type === 'pickup' ? 'Ready in ' + D.settings.pickupEta + ' min' : 'Delivery in ' + (a ? a.eta : D.settings.eta) + ' min';
    var msg = !D.status.open ? D.status.message : (!open ? (S.type === 'pickup' ? 'Pick-up is not available right now.' : 'Delivery is not available right now.') : '');
    $('notice').hidden = !msg;
    $('noticeText').textContent = msg || '';
    $('whereType').textContent = S.type === 'pickup' ? 'Pick-Up' : 'Delivery';
    if (S.type === 'pickup') {
      var br = D.branches.find(function (b) { return b.id === branchOf(); });
      $('whereName').textContent = br ? br.name : 'Choose branch';
    } else {
      $('whereName').textContent = a ? a.name : 'Choose your area';
    }
  }

  // ── menu ──
  function cardHtml(card, ci, ii) {
    var img = card.thumb
      ? '<img src="' + esc(card.thumb) + '" alt="" loading="lazy" decoding="async" onload="this.classList.add(\'ok\')">'
      : esc(card.name.charAt(0));
    var tag = card.tag ? '<span class="tag">' + (card.tag === 'hot_selling' ? 'Hot Selling' : card.tag === 'new' ? 'New' : 'Popular') + '</span>' : '';
    return '<button type="button" class="card rv" data-c="' + ci + '" data-i="' + ii + '">' + tag +
      '<span><h3>' + esc(card.name) + '</h3><p>' + esc(card.description || '') + '</p>' +
      '<span class="price">' + (card.options.length > 1 ? 'From ' : '') + money(card.from) + '</span></span>' +
      '<span class="ph">' + img + '</span><span class="add" aria-hidden="true">+</span></button>';
  }

  function renderMenu() {
    var nav = [], html = [];
    var popular = [];
    menu.forEach(function (c, ci) { c.items.forEach(function (it, ii) { if (it.popular) { popular.push([ci, ii]); } }); });
    if (popular.length) {
      nav.push('<a href="#sec-popular" data-sec="sec-popular">Popular</a>');
      html.push('<section class="cat" id="sec-popular"><div class="banner">Popular Items</div><div class="grid">' +
        popular.map(function (p) { return cardHtml(menu[p[0]].items[p[1]], p[0], p[1]); }).join('') + '</div></section>');
    }
    menu.forEach(function (c, ci) {
      var id = 'sec-' + c.id;
      nav.push('<a href="#' + id + '" data-sec="' + id + '">' + esc(c.name) + '</a>');
      html.push('<section class="cat" id="' + id + '"><div class="banner">' + esc(c.name) + '</div><div class="grid">' +
        c.items.map(function (it, ii) { return cardHtml(it, ci, ii); }).join('') + '</div></section>');
    });
    $('nav').innerHTML = nav.join('');
    $('menu').innerHTML = html.length ? html.join('') : '<p class="empty">The menu is being prepared. Please check back soon.</p>';
    observe();
  }

  var revealObs = null, spyObs = null;
  function observe() {
    if (!('IntersectionObserver' in window)) { return; }
    if (revealObs) { revealObs.disconnect(); }
    if (spyObs) { spyObs.disconnect(); }
    revealObs = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); revealObs.unobserve(e.target); } });
    }, { rootMargin: '0px 0px -40px 0px' });
    document.querySelectorAll('.rv').forEach(function (el) { revealObs.observe(el); });
    spyObs = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) { return; }
        document.querySelectorAll('#nav a').forEach(function (a) {
          var on = a.getAttribute('data-sec') === e.target.id;
          a.classList.toggle('on', on);
          if (on && a.scrollIntoView) { a.scrollIntoView({ block: 'nearest', inline: 'center', behavior: reduce ? 'auto' : 'smooth' }); }
        });
      });
    }, { rootMargin: '-130px 0px -65% 0px' });
    document.querySelectorAll('section.cat').forEach(function (s) { spyObs.observe(s); });
  }

  $('menu').addEventListener('click', function (e) {
    var b = e.target.closest('.card');
    if (b) { openItem(menu[+b.getAttribute('data-c')].items[+b.getAttribute('data-i')]); }
  });

  // ── sheets ──
  var openSheet = null;
  function show(id) { hide(); openSheet = $(id); openSheet.classList.add('show'); $('veil').classList.add('show'); document.body.style.overflow = 'hidden'; }
  function hide() { if (openSheet) { openSheet.classList.remove('show'); } openSheet = null; $('veil').classList.remove('show'); document.body.style.overflow = ''; }
  $('veil').addEventListener('click', hide);
  document.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', hide); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hide(); } });

  // ── area / order type ──
  function renderAreas() {
    var q = ($('areaSearch').value || '').toLowerCase();
    var pickup = S.type === 'pickup';
    $('tDelivery').classList.toggle('on', !pickup);
    $('tPickup').classList.toggle('on', pickup);
    $('areaSearch').hidden = pickup;
    $('gps').hidden = pickup;
    var rows = pickup
      ? D.branches.map(function (b) { return '<button type="button" data-b="' + b.id + '" class="' + (branchOf() === b.id ? 'on' : '') + '"><span>' + esc(b.name) + (b.address ? '<br><em>' + esc(b.address) + '</em>' : '') + '</span><em>Ready in ' + D.settings.pickupEta + ' min</em></button>'; })
      : D.areas.filter(function (a) { return !q || a.name.toLowerCase().indexOf(q) !== -1; })
          .sort(function (x, y) { return q ? 0 : nearRank(x.id) - nearRank(y.id); })
          .map(function (a) { return '<button type="button" data-a="' + a.id + '" class="' + (S.area === a.id ? 'on' : '') + '"><span>' + esc(a.name) + '</span><em>' + (nearby.indexOf(a.id) !== -1 ? '<span class="near">Near you</span> · ' : '') + a.eta + ' min</em></button>'; });
    $('areaList').innerHTML = rows.length ? rows.join('') : '<p class="empty">' + (pickup ? 'Pick-up is not available.' : 'We do not deliver to this area yet. Try Pick-Up.') + '</p>';
  }
  $('where').addEventListener('click', function () { renderAreas(); show('areaSheet'); });
  $('tDelivery').addEventListener('click', function () { S.type = 'delivery'; renderAreas(); });
  $('tPickup').addEventListener('click', function () { S.type = 'pickup'; renderAreas(); });
  $('areaSearch').addEventListener('input', renderAreas);
  $('areaList').addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) { return; }
    if (b.hasAttribute('data-a')) { S.type = 'delivery'; S.area = +b.getAttribute('data-a'); }
    if (b.hasAttribute('data-b')) { S.type = 'pickup'; S.branch = +b.getAttribute('data-b'); }
    save(); hide(); switchBranch();
  });
  // Owner: location is asked for as soon as the site opens; if it was not given then, tapping
  // "Use my current location" asks again. OpenStreetMap (through our server) names the place; the area
  // whose name matches — or the nearest one — is chosen, and nearby areas move to the top of the list.
  var nearby = [], locating = false;
  function nearRank(id) { var i = nearby.indexOf(id); return i === -1 ? 99 : i; }
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
            S.placeHint = j.street || j.place || null; save();
            var a = j.area ? D.areas.find(function (x) { return x.id === j.area; }) : null;
            if (a) {
              S.type = 'delivery'; S.area = a.id; save();
              gpsMsg('<span>You are in <b>' + esc(a.name) + '</b>' + (j.place ? '<br><small>' + esc(j.place) + '</small>' : '') + '</span><button type="button" class="go" data-close>Continue</button>');
              $('gpsText').querySelector('[data-close]').addEventListener('click', hide);
              switchBranch();
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
  var cur = null, curOpt = 0, curQty = 1, curAdd = {};
  function openItem(card) {
    cur = card; curOpt = 0; curQty = 1; curAdd = {};
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
    save(); hide(); paintCart();
    var cb = $('cartBtn'); cb.classList.remove('bump'); void cb.offsetWidth; cb.classList.add('bump');
  });

  // ── cart ──
  function subtotal() { return S.cart.reduce(function (s, l) { return s + l.unit * l.qty; }, 0); }
  function fee() {
    if (S.type === 'pickup') { return 0; }
    var a = area(); if (!a) { return 0; }
    return (a.freeAbove !== null && subtotal() >= a.freeAbove) ? 0 : a.fee;
  }
  function paintCart() {
    var n = S.cart.reduce(function (s, l) { return s + l.qty; }, 0);
    $('cartCount').textContent = n;
    $('bar').classList.toggle('show', n > 0);
    $('barCount').textContent = 'View cart · ' + n + (n === 1 ? ' item' : ' items');
    $('barTotal').textContent = money(subtotal());
    if (openSheet === $('cartSheet') && step === 'cart') { renderCart(); }
  }
  var step = 'cart';
  function renderCart() {
    step = 'cart';
    $('cartTitle').textContent = 'Your cart';
    if (!S.cart.length) {
      $('cartBody').innerHTML = '<p class="empty">Your cart is empty.</p>';
      $('cartFoot').innerHTML = '<button class="ghost" type="button" data-close>Browse the menu</button>';
      $('cartFoot').querySelector('[data-close]').addEventListener('click', hide);
      return;
    }
    var a = area();
    var h = S.cart.map(function (l, i) {
      return '<div class="line"><div><b>' + esc(l.name) + '</b>' + (l.label ? ' · ' + esc(l.label) : '') +
        (l.addons.length ? '<small>' + l.addons.map(function (x) { return esc(x.name); }).join(', ') + '</small>' : '') +
        (l.note ? '<small>“' + esc(l.note) + '”</small>' : '') + '<small>' + money(l.unit) + '</small></div>' +
        '<div class="qty"><button type="button" data-q="' + i + '" data-d="-1" aria-label="Less">−</button><b>' + l.qty + '</b><button type="button" data-q="' + i + '" data-d="1" aria-label="More">+</button></div></div>';
    });
    var sub = subtotal(), f = fee();
    var sum = '<div class="sum"><div><span>Subtotal</span><span>' + money(sub) + '</span></div>' +
      (S.type === 'delivery' ? '<div><span>Delivery fee' + (a ? ' (' + esc(a.name) + ')' : '') + '</span><span>' + (a ? (f > 0 ? money(f) : 'Free') : '—') + '</span></div>' : '') +
      '<div class="gt"><span>Total</span><span>' + money(sub + f) + '</span></div></div>';
    var warns = [];
    if (S.type === 'delivery' && !a) { warns.push('Choose your delivery area to see the delivery fee.'); }
    if (a && a.min && sub < a.min) { warns.push('Minimum order for ' + esc(a.name) + ' is ' + money(a.min) + '.'); }
    if (a && a.freeAbove && sub < a.freeAbove) { warns.push('Free delivery above ' + money(a.freeAbove) + '.'); }
    if (!branchOpen()) { warns.push(esc(D.status.message || 'We are not taking orders right now.')); }
    $('cartBody').innerHTML = h.join('') + sum + warns.map(function (w) { return '<div class="warn">' + w + '</div>'; }).join('');
    var blocked = !branchOpen() || (S.type === 'delivery' && !a) || (a && a.min && sub < a.min);
    $('cartFoot').innerHTML = '<button class="big" type="button" id="toCheckout"' + (blocked ? ' disabled' : '') + '>Checkout · ' + money(sub + f) + '</button>' +
      '<p style="margin:0;text-align:center;font-size:12.5px;color:var(--muted)">' + (S.type === 'pickup' ? 'Ready in ' + D.settings.pickupEta + ' min' : 'Estimated delivery ' + (a ? a.eta : D.settings.eta) + ' min') + '</p>';
    $('toCheckout').addEventListener('click', renderCheckout);
  }
  $('cartBody').addEventListener('click', function (e) {
    var b = e.target.closest('[data-q]');
    if (!b) { return; }
    var l = S.cart[+b.getAttribute('data-q')];
    l.qty += +b.getAttribute('data-d');
    if (l.qty <= 0) { S.cart.splice(+b.getAttribute('data-q'), 1); }
    save(); paintCart(); renderCart();
  });
  function openCart() { renderCart(); show('cartSheet'); }
  $('cartBtn').addEventListener('click', openCart);
  $('bar').addEventListener('click', openCart);

  // ── checkout (sending the order arrives in the next step of the build) ──
  function renderCheckout() {
    step = 'checkout';
    var c = S.customer || {}, a = area();
    $('cartTitle').textContent = 'Checkout';
    $('cartBody').innerHTML =
      '<div class="pin"><span class="dot"></span><span>' + (S.type === 'pickup' ? 'Pick-Up order' : 'Delivery to <b>' + esc(a ? a.name : '') + '</b>') + '</span></div>' +
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
      setTimeout(function () { map.invalidateSize(); }, 250);
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
        lat: S.pin ? S.pin.lat : null, lng: S.pin ? S.pin.lng : null },
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
          S.cart = []; S.pendingKey = null; S.lastOrder = res.j.status_url; save();
          location.href = res.j.status_url;
          return;
        }
        var msgs = res.j && res.j.errors ? Object.keys(res.j.errors).filter(function (k) { return k !== 'meta'; }).map(function (k) { return res.j.errors[k][0]; }) : [];
        box.hidden = false; box.innerHTML = (msgs.length ? msgs : [res.j && res.j.message || 'Could not place the order. Please try again.']).map(esc).join('<br>');
        btn.disabled = false; btn.textContent = 'Place order · ' + money(subtotal() + fee());
        if (res.j && res.j.errors && res.j.errors.lines) { S.pendingKey = null; save(); }
      })
      .catch(function () {
        box.hidden = false; box.textContent = 'No connection. Please check your internet and try again — you will not be charged twice.';
        btn.disabled = false; btn.textContent = 'Place order · ' + money(subtotal() + fee());
      });
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (ch) {
      var r = Math.random() * 16 | 0; return (ch === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  $('forget').addEventListener('click', function () {
    try { localStorage.removeItem(KEY); } catch (e) { /* nothing stored */ }
    S = { type: 'delivery', area: null, branch: null, cart: [], customer: {}, pin: null, placeHint: null };
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
