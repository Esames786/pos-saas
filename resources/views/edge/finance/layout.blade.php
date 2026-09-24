{{--
  W4 (Team 4) — the shared shell of the Branch Server's operator LIST / DETAIL screens (shift history + detail, sales
  returns, supplier payments, manual journals, purchase returns). The Online screens (tenant/shifts, tenant/sales-returns,
  tenant/supplier-payments, tenant/finance/manual-journals, tenant/purchase-returns) are the specification: same columns,
  filters, totals and actions, rendered server-side from the appliance's LOCAL records.

  Self-contained (inline CSS, no build assets, no external font/script) so it renders with NO Internet; same look as the
  existing F2/F3 pages (suppliers / journal / purchase-returns). Read-only: nothing here mutates.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bingoo Edge — @yield('title')</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23CAA23F'/%3E%3Cpath d='M18 14h17c8 0 13 4 13 10 0 4-2 7-6 8 5 1 8 5 8 10 0 7-6 12-15 12H18z' fill='%23fff'/%3E%3C/svg%3E">
    <style>
        :root { --bg:#0f172a; --panel:#1e293b; --panel2:#172033; --line:#334155; --ink:#e2e8f0; --muted:#94a3b8; --accent:#4f46e5; --ok:#16a34a; --warn:#d97706; --danger:#dc2626; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:system-ui,Segoe UI,sans-serif; background:var(--bg); color:var(--ink); min-height:100vh; }
        header { background:var(--panel); border-bottom:1px solid var(--line); padding:.5rem .9rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
        header h1 { font-size:1.05rem; margin:0; }
        header .who { color:var(--muted); font-size:.8rem; }
        header .spacer { flex:1; }
        a { color:#c7d2fe; }
        a.navbtn, button, .btn { font:inherit; color:var(--ink); cursor:pointer; border:1px solid var(--line); background:var(--panel2); border-radius:8px; padding:.45rem .75rem; text-decoration:none; font-size:.88rem; display:inline-block; }
        .btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; }
        .btn.danger { border-color:var(--danger); color:#fca5a5; }
        .btn.sm { padding:.25rem .55rem; font-size:.78rem; }
        a.navbtn, button.ghost { background:transparent; }
        main { padding:1rem; max-width:1280px; margin:0 auto; }
        .page-head { display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:.8rem; margin-bottom:1rem; }
        .page-head h2 { margin:0 0 .2rem; font-size:1.3rem; }
        .page-head p { margin:0; color:var(--muted); font-size:.9rem; }
        .card { background:var(--panel); border:1px solid var(--line); border-radius:12px; margin-bottom:1rem; }
        .card-h { padding:.6rem .9rem; border-bottom:1px solid var(--line); font-weight:600; }
        .card-b { padding:.8rem .9rem; }
        .filters { display:flex; flex-wrap:wrap; gap:.6rem .8rem; align-items:flex-end; }
        .filters .f { display:flex; flex-direction:column; min-width:150px; }
        label { font-size:.75rem; color:var(--muted); margin-bottom:.2rem; }
        select, input { font:inherit; color:var(--ink); background:var(--panel2); border:1px solid var(--line); border-radius:8px; padding:.4rem .55rem; }
        .quick { display:flex; gap:.4rem; align-items:center; margin-top:.6rem; font-size:.8rem; color:var(--muted); flex-wrap:wrap; }
        .quick a.on { background:var(--ink); color:var(--bg); }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:.86rem; }
        th, td { padding:.45rem .6rem; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; white-space:nowrap; }
        th { color:var(--muted); font-weight:500; font-size:.74rem; text-transform:uppercase; letter-spacing:.03em; }
        td.num, th.num { text-align:right; }
        td.wrap { white-space:normal; }
        tr.group td { background:var(--panel2); }
        tfoot td { font-weight:700; }
        code { font-size:.82rem; color:#c7d2fe; }
        .badge { display:inline-block; font-size:.7rem; border-radius:999px; padding:.15rem .5rem; border:1px solid var(--line); color:var(--muted); white-space:nowrap; }
        .badge.open, .badge.pending { border-color:var(--warn); color:#fcd34d; }
        .badge.closed { border-color:var(--line); color:var(--muted); }
        .badge.posted, .badge.synced, .badge.official { border-color:var(--ok); color:#86efac; }
        .badge.failed, .badge.missing { border-color:var(--danger); color:#fca5a5; }
        .badge.online { border-color:var(--accent); color:#c7d2fe; }
        .neg { color:#fca5a5; } .pos { color:#fcd34d; } .zero { color:#86efac; }
        dl.kv { display:grid; grid-template-columns:minmax(140px, 38%) 1fr; gap:.35rem .8rem; margin:0; font-size:.9rem; }
        dl.kv dt { color:var(--muted); }
        dl.kv dd { margin:0; }
        .grid2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:1rem; }
        .note { background:var(--panel2); border:1px solid var(--line); border-radius:10px; padding:.6rem .8rem; font-size:.85rem; color:var(--muted); margin-bottom:1rem; }
        .note.warn { border-color:var(--warn); color:#fcd34d; }
        .empty { color:var(--muted); text-align:center; padding:1.5rem; }
        .pager { display:flex; gap:.5rem; justify-content:flex-end; align-items:center; margin-top:.7rem; font-size:.85rem; color:var(--muted); }
        .cash-grid { display:flex; flex-wrap:wrap; gap:6px 26px; padding:6px 2px; }
        .cash-grid > div { display:flex; flex-direction:column; min-width:104px; }
        .cash-grid span { font-size:.72rem; letter-spacing:.04em; text-transform:uppercase; opacity:.65; }
        .cash-grid b { font-variant-numeric:tabular-nums; font-size:.95rem; }
        @media (max-width: 720px) { main { padding:.6rem; } dl.kv { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <header>
        <h1>@yield('title')</h1>
        <span class="who">{{ $branchName ?? '' }} · {{ $userName ?? '' }}</span>
        <span class="spacer"></span>
        <a class="navbtn" id="edge-pos-link" href="{{ url('/edge/local/pos') }}">POS</a>
        @yield('nav')
        <form method="POST" action="{{ url('/edge/local/logout') }}" style="margin:0">@csrf<button class="ghost">Logout</button></form>
    </header>
    <main>
        @yield('content')
    </main>
    @yield('scripts')
</body>
</html>
