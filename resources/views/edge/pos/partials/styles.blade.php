{{-- W1 (Team 1): the cashier page stylesheet — the Online POS look (tenant/pos/index.blade.php:6-418 + layouts/app.blade.php +
     public/assets/css/style.css tokens): light Bootstrap-5 shell, white .pos-card panels, 500px cart column, 170px/148px tiles,
     44px pills, 42px action buttons, Online's @media breakpoints. The locally packaged Bootstrap 5.3.8 + Tabler icons are
     linked through the Edge-local asset route when it is enabled on this appliance (EdgeLocalAssetController::available());
     the inline sheet below is SELF-SUFFICIENT so the page never depends on it (no Internet, no CDN, no font import — E-10).
     Other teams' fragments keep their class names (.primary/.ok/.warn/.danger/.ghost/.sm, .field, .err, .row, .list-row,
     .tile, .pill, .chip, .board/.tbl, .totals) — this sheet restyles them; do not rename them. --}}
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23CAA23F'/%3E%3Cpath d='M18 14h17c8 0 13 4 13 10 0 4-2 7-6 8 5 1 8 5 8 10 0 7-6 12-15 12H18z' fill='%23fff'/%3E%3C/svg%3E">
@if(\App\Http\Controllers\Edge\EdgeLocalAssetController::available())
    <link rel="stylesheet" href="{{ \App\Http\Controllers\Edge\EdgeLocalAssetController::url('css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ \App\Http\Controllers\Edge\EdgeLocalAssetController::url('plugins/tabler-icons/tabler-icons.min.css') }}">
@endif
    <style>
        :root {
            --bg:#F7F7F7; --panel:#ffffff; --panel2:#f8fafc; --line:#e9ecef; --line-soft:#edf0f4; --ink:#212B36; --text:#3f4750; --muted:#6c757d;
            --accent:#111827; --primary:#CAA23F; --primary-dark:#b08a2e; --ok:#1e9e57; --warn:#FFCA18; --danger:#dc3545; --info:#0d6efd; --navy:#1B2850;
            --shadow:0 12px 34px rgba(15,23,42,.06);
            --font:"Nunito","Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;
        }
        * { box-sizing:border-box; }
        html { font-size:16px; }
        body { margin:0; font-family:var(--font); background:var(--bg); color:var(--text); font-size:14px; line-height:1.5; min-height:100vh; }
        h1, h2, h3, h4 { color:var(--ink); font-family:var(--font); font-weight:700; }
        [hidden] { display:none !important; }
        a { color:var(--navy); }
        :focus-visible { outline:3px solid rgba(202,162,63,.45); outline-offset:1px; }

        /* ── form controls (Bootstrap .form-control / .form-select look) ── */
        select, input, button, textarea { font:inherit; color:var(--ink); }
        select, input[type=text], input[type=search], input[type=number], input[type=password], input[type=datetime-local], input[type=date], textarea {
            background:#fff; border:1px solid #dee2e6; border-radius:6px; padding:.45rem .75rem; min-height:38px; line-height:1.4; }
        select:focus, input:focus, textarea:focus { border-color:#e3c77e; outline:0; box-shadow:0 0 0 .2rem rgba(202,162,63,.2); }
        select:disabled, input:disabled { background:#e9ecef; }

        /* ── buttons: default = outline-secondary; the team class names keep their meaning ── */
        button, a.navbtn, .btn-edge { cursor:pointer; border:1px solid #ced4da; background:#fff; border-radius:6px; padding:.45rem .85rem; font-weight:600; font-size:.875rem;
            color:var(--ink); min-height:38px; display:inline-flex; align-items:center; justify-content:center; gap:.35rem; text-decoration:none; line-height:1.2; transition:background .15s, border-color .15s, box-shadow .15s; }
        button:hover, a.navbtn:hover { background:#f3f4f6; }
        button.primary { background:var(--primary); border-color:var(--primary); color:#fff; box-shadow:0 4px 20px rgba(202,162,63,.15); }
        button.primary:hover { background:var(--primary-dark); border-color:var(--primary-dark); }
        button.ok { background:var(--ok); border-color:var(--ok); color:#fff; }
        button.ok:hover { background:#17864a; }
        button.warn { background:var(--warn); border-color:var(--warn); color:#212529; }
        button.warn:hover { background:#f0bb00; }
        button.danger { background:var(--danger); border-color:var(--danger); color:#fff; }
        button.danger:hover { background:#bb2d3b; }
        button.dark { background:var(--navy); border-color:var(--navy); color:#fff; }
        button.ghost { background:transparent; border-color:#ced4da; }
        button.outline-danger { color:var(--danger); border-color:var(--danger); background:#fff; }
        button.outline-primary { color:var(--info); border-color:var(--info); background:#fff; }
        button.outline-dark { color:var(--navy); border-color:var(--navy); background:#fff; }
        button.sm, a.navbtn.sm { padding:.25rem .6rem; font-size:.8rem; min-height:31px; }
        button:disabled, button[aria-busy=true] { opacity:.6; cursor:not-allowed; }
        .ti { font-size:1.05em; line-height:1; }

        /* Bootstrap neutraliser: the Edge fragments use .row as a plain flex row (label | value), never the 12-col grid. */
        .row { display:flex; flex-wrap:nowrap; margin:0; --bs-gutter-x:0; --bs-gutter-y:0; }
        .row > * { width:auto; max-width:none; padding:0; margin-top:0; flex-shrink:1; }

        /* ── Online .pos-card ── */
        .pos-card { border:1px solid var(--line-soft); border-radius:8px; background:#fff; box-shadow:var(--shadow); }

        /* ── POS header (Online: no app chrome; title row, session bar, mode tabs + customer slot, context row) ── */
        .pos-header { padding:12px 12px 0; }
        .pos-title-row { display:flex; align-items:center; flex-wrap:wrap; gap:.5rem; margin-bottom:.6rem; }
        #pos-sidebar-toggle { width:38px; height:38px; padding:0; display:inline-grid; place-items:center; }
        .pos-title { display:inline-flex; align-items:baseline; gap:.35rem; margin:0; }
        .pos-title .pos-title-pre, .pos-title h1 { font-size:1.5rem; font-weight:700; color:var(--ink); margin:0; line-height:1.2; }
        #view-tables-btn { background:var(--navy); border-color:var(--navy); color:#fff; margin-left:.5rem; }
        #view-tables-btn:hover { background:#14203f; }
        .edge-nav { margin-left:auto; display:flex; align-items:center; flex-wrap:wrap; gap:.4rem; font-size:.8rem; }
        .edge-nav .who { color:var(--muted); font-size:.8rem; }
        .edge-nav form { margin:0; }
        body.nav-collapsed .edge-nav .edge-nav-links { display:none; }
        .edge-nav-links { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; }
        .pos-session-bar { padding:.5rem 1rem; margin-bottom:.6rem; display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; }
        .pos-session-bar .session-context { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pos-session-bar .session-context strong { font-size:1rem; color:var(--ink); }
        .pos-session-actions { margin-left:auto; display:flex; gap:.5rem; flex-wrap:wrap; }
        .pos-session-actions:empty { display:none; }
        .pos-mode-row { display:flex; align-items:center; flex-wrap:wrap; gap:.5rem; margin-bottom:.6rem; }
        .mode-tabs { display:flex; gap:.65rem; overflow-x:auto; padding:.15rem 0; max-width:100%; }
        .mode-tab { border:1px solid var(--line); background:#fff; border-radius:999px; padding:.6rem 1rem; font-weight:800; white-space:nowrap; min-height:44px; color:var(--ink); }
        .mode-tab.active, .mode-tab.active:hover { background:var(--accent); color:#fff; border-color:var(--accent); }
        .pos-controls-locked { opacity:.45; pointer-events:none; user-select:none; }
        .pos-customer-slot { margin-left:auto; display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
        .pos-customer-chip { display:inline-flex; align-items:center; gap:.35rem; max-width:420px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:999px; padding:.3rem .5rem .3rem .75rem; font-size:.875rem; white-space:nowrap; overflow:hidden; }
        .pos-customer-chip span { overflow:hidden; text-overflow:ellipsis; }
        .pos-customer-chip .chip-clear { min-height:0; width:22px; height:22px; padding:0; border-radius:50%; border:0; background:#e2e8f0; font-size:.8rem; }
        .order-controls-row { display:flex; align-items:center; flex-wrap:wrap; gap:.4rem .75rem; margin-bottom:.6rem; font-size:.85rem; padding:.45rem .9rem; }
        .order-controls-row .ctx strong { color:var(--ink); }
        .order-controls-row .order-tools { margin-left:auto; display:flex; gap:.4rem; flex-wrap:wrap; }
        .badge { display:inline-block; border-radius:999px; padding:.25rem .6rem; font-size:.75rem; font-weight:800; color:#fff; background:#6c757d; line-height:1.2; }
        .badge.bg-success { background:#198754; }
        .badge.bg-danger { background:var(--danger); }
        .badge.bg-secondary { background:#6c757d; }
        .text-warning-emphasis { color:#997404; font-weight:600; }
        .text-muted { color:var(--muted); }

        /* ── the two-column shell (Online .pos-shell: minmax(0,1fr) 500px) ── */
        main { display:grid; grid-template-columns:minmax(0,1fr) 500px; gap:1rem; padding:0 12px 12px; align-items:start; }
        .grid-pane { min-width:0; display:flex; flex-direction:column; border:1px solid var(--line-soft); border-radius:8px; background:#fff; box-shadow:var(--shadow); padding:1rem; }
        .tabs { display:flex; gap:.6rem; flex-wrap:wrap; padding-bottom:.25rem; margin-bottom:.75rem; }
        .toolbar { display:flex; gap:.5rem; margin-bottom:.75rem; }
        .toolbar input { flex:1; min-height:48px; font-size:1.05rem; padding:.5rem 1rem; border-radius:8px; }
        #products_heading { font-size:1rem; margin:0 0 .5rem; }
        .tiles { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:.7rem; max-height:calc(100vh - 300px); overflow-y:auto; padding-right:.25rem; align-content:start; }
        .tile { border:1px solid var(--line-soft); border-radius:8px; background:linear-gradient(180deg,#fff,#fbfcfd); padding:.85rem; min-height:148px; text-align:left;
            display:flex; flex-direction:column; justify-content:space-between; align-items:stretch; overflow:hidden; transition:.15s ease; font-weight:400; }
        .tile:hover, .tile:focus { transform:translateY(-2px); box-shadow:0 14px 30px rgba(15,23,42,.10); outline:3px solid rgba(13,110,253,.18); background:#fff; }
        .tile .nm { font-size:.9rem; line-height:1.3; font-weight:700; color:var(--ink); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; word-break:break-word; min-height:2.6em; }
        .tile .pr { font-size:1.08rem; font-weight:800; color:var(--ink); white-space:nowrap; margin-top:auto; padding-top:.35rem; }
        .tile.deal { border-left:6px solid var(--primary); }
        .tile > * { flex-shrink:0; }
        .pill { border:1px solid var(--line); background:#fff; border-radius:999px; min-height:44px; padding:.6rem 1rem; font-size:.95rem; font-weight:700; white-space:nowrap; color:var(--ink); }
        .pill.active, .pill.active:hover { background:var(--accent); color:#fff; border-color:var(--accent); }

        /* cart column (Online .cart-panel: sticky, full-height flex) */
        .cart-pane { display:flex; flex-direction:column; min-height:0; border:1px solid var(--line-soft); border-radius:8px; background:#fff; box-shadow:var(--shadow); padding:1rem;
            position:sticky; top:12px; height:calc(100vh - 24px); }
        .cart-heading-row { display:flex; justify-content:space-between; align-items:center; gap:.5rem; margin-bottom:.5rem; }
        #cart_heading { font-size:1.25rem; margin:0; }
        .cart-heading-row .tools { display:flex; gap:.4rem; }
        .cart-head { padding:0 0 .5rem; border-bottom:1px solid #eef0f3; display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
        .chip { font-size:.78rem; font-weight:700; background:#f8fafc; border:1px solid #e2e8f0; border-radius:999px; padding:.25rem .6rem; color:#475569; }
        .chip.hot { background:#fff3cd; border-color:#ffc107; color:#7a5200; }
        .chip.draft { background:#e2e3e5; border-color:#c4c8cb; color:#41464b; }
        .chip.bad { background:#fee2e2; border-color:#fca5a5; color:#991b1b; }
        .chip.ok { background:#dcfce7; border-color:#86efac; color:#166534; }
        .lines { flex:1 0 auto; min-height:140px; height:0; overflow-y:auto; padding:.25rem .25rem .25rem 0; }
        .line { display:grid; grid-template-columns:1fr auto; gap:.2rem .5rem; padding:.55rem 0; border-bottom:1px solid #eef0f3; }
        .line .ln-nm { font-size:.9rem; font-weight:700; color:var(--ink); }
        .line .ln-sub { font-size:.75rem; color:var(--muted); }
        .line .ln-ctl { display:flex; align-items:center; gap:.4rem; }
        .line .ln-ctl button { width:32px; height:32px; min-height:32px; padding:0; border-radius:10px; border-color:#dee2e6; font-weight:900; }
        .line .ln-amt { text-align:right; font-size:.9rem; font-weight:700; color:var(--ink); }
        .totals { padding:.5rem 0; border-top:1px solid #eef0f3; font-size:.9rem; }
        .totals .row { justify-content:space-between; padding:.12rem 0; }
        .totals .grand { font-size:1.55rem; font-weight:900; color:var(--ink); border-top:1px solid #eef0f3; margin-top:.3rem; padding-top:.4rem; }
        .actions { padding-top:.5rem; display:grid; grid-template-columns:1fr 1fr; gap:.5rem; }
        .actions button { min-height:42px; font-size:.95rem; }
        .actions .wide { grid-column:1 / -1; min-height:48px; font-size:1.1rem; }

        /* calculator (Online #calculator-panel + .keypad, O:354-363) */
        .calculator-panel { border-top:1px solid #eef0f3; padding:.6rem 0 .2rem; }
        #calculator_heading { font-size:.95rem; margin:0 0 .5rem; }
        #calc-display { width:100%; margin-bottom:.5rem; text-align:right; font-size:1.2rem; font-weight:700; }
        .keypad { display:grid; grid-template-columns:repeat(4,1fr); gap:.45rem; }
        .keypad button { border:1px solid var(--line); background:#fff; border-radius:14px; padding:.75rem; font-weight:900; min-height:44px; }
        .keypad button.eq { grid-column:span 4; background:var(--navy); color:#fff; border-color:var(--navy); font-size:1.25rem; line-height:1; }

        /* banners (Online .alert look) */
        .banner { padding:.45rem 1rem; font-size:.85rem; text-align:center; margin:0 12px .6rem; border-radius:8px; border:1px solid transparent; }
        .banner.warn { background:#fff3cd; border-color:#ffe69c; color:#664d03; }
        .banner.offline { background:#f8d7da; border-color:#f1aeb5; color:#842029; font-weight:700; }

        /* ── the ONE generic dialog (#modal) — Bootstrap modal look ── */
        .modal { position:fixed; inset:0; background:rgba(15,23,42,.5); display:none; align-items:center; justify-content:center; z-index:1050; padding:1rem; }
        .modal.open { display:flex; }
        .modal .box { background:#fff; border:0; border-radius:8px; width:min(800px,96vw); max-height:92vh; overflow:auto; padding:1.25rem 1.5rem; box-shadow:0 20px 60px rgba(15,23,42,.25); color:var(--text); }
        .modal h2 { margin:0 -1.5rem 1rem; padding:0 1.5rem .8rem; font-size:1.15rem; border-bottom:1px solid var(--line); }
        .modal h3 { margin:.8rem 0 .4rem; font-size:.9rem; color:var(--ink); }
        .board { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:.75rem; }
        .tbl { border:1px solid var(--line-soft); border-radius:8px; background:linear-gradient(180deg,#fff,#fbfcfd); padding:.85rem; text-align:left; cursor:pointer; border-left:6px solid #20c997; min-height:74px; transition:.15s ease; }
        .tbl:hover { transform:translateY(-2px); box-shadow:0 12px 28px rgba(15,23,42,.10); }
        .tbl strong { color:var(--ink); font-size:1rem; }
        .tbl.occupied { border-left-color:#fd7e14; }
        .tbl.reserved { border-left-color:#a855f7; }
        .tbl.bill_requested { border-left-color:#0d6efd; }
        .tbl.selected { border:2px solid var(--accent); border-left:6px solid var(--accent); background:#f8fafc; box-shadow:0 14px 34px rgba(15,23,42,.16); }
        .muted { color:var(--muted); }
        .err, .edge-inline-error { background:#f8d7da; border:1px solid #f1aeb5; color:#842029; padding:.55rem .8rem; border-radius:6px; font-size:.85rem; margin:.5rem 0; }
        .edge-inline-toast { padding:.55rem .8rem; border-radius:6px; font-size:.85rem; margin:.5rem 0; border:1px solid #badbcc; background:#d1e7dd; color:#0f5132; }
        .edge-inline-toast.warning { border-color:#ffe69c; background:#fff3cd; color:#664d03; }
        .edge-inline-toast.info { border-color:#b6d4fe; background:#cfe2ff; color:#084298; }
        .list-row { display:flex; justify-content:space-between; align-items:center; gap:.5rem; padding:.6rem .4rem; border-bottom:1px solid #eef0f3; cursor:pointer; border-radius:6px; }
        .list-row:hover { background:#f8fafc; }
        .field { display:flex; flex-direction:column; gap:.25rem; margin:.5rem 0; }
        .field label { font-size:.8rem; font-weight:700; color:var(--ink); }
        .btn-row { display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem; flex-wrap:wrap; padding-top:.8rem; border-top:1px solid var(--line); }
        .btn-row.left { justify-content:flex-start; border-top:0; padding-top:0; }

        /* ── severity toast (fallback when SweetAlert2 is not loaded; Online: top-end toast, 3 s, O:3784-3796) ── */
        .edge-toast { position:fixed; top:1rem; right:1rem; background:#fff; border:1px solid var(--line); border-left:5px solid var(--info); border-radius:8px; padding:.7rem 1rem;
            z-index:1090; display:none; max-width:min(420px,92vw); box-shadow:0 10px 30px rgba(15,23,42,.18); color:var(--ink); font-weight:600; }
        .edge-toast.success { border-left-color:#198754; }
        .edge-toast.error { border-left-color:var(--danger); }
        .edge-toast.warning { border-left-color:#ffc107; }
        .edge-toast.info { border-left-color:var(--info); }

        /* ── busy / loading (Online setButtonBusy + spinner-border, O:2123-2160) ── */
        .edge-spinner { display:inline-block; width:1rem; height:1rem; border:.15em solid currentColor; border-right-color:transparent; border-radius:50%; animation:edge-spin .75s linear infinite; vertical-align:-.125em; }
        @keyframes edge-spin { to { transform:rotate(360deg); } }
        #edge-loading { position:fixed; top:0; left:0; right:0; height:3px; z-index:1100; background:linear-gradient(90deg,transparent,var(--primary),transparent); background-size:50% 100%; background-repeat:no-repeat; animation:edge-load 1s linear infinite; }
        @keyframes edge-load { from { background-position:-50% 0; } to { background-position:150% 0; } }

        /* ── in-page dialogs: Branch & Terminal (#posContextModal) + confirm fallback ── */
        .edge-dialog { position:fixed; inset:0; background:rgba(15,23,42,.5); display:none; align-items:center; justify-content:center; z-index:1060; padding:1rem; }
        .edge-dialog.open { display:flex; }
        .edge-dialog-box { background:#fff; border-radius:8px; width:min(420px,96vw); box-shadow:0 20px 60px rgba(15,23,42,.25); }
        .edge-dialog-head { display:flex; align-items:center; justify-content:space-between; padding:.6rem 1rem; border-bottom:1px solid var(--line); }
        .edge-dialog-head h2 { font-size:1rem; margin:0; }
        .edge-dialog-body { padding:1rem; }
        .edge-dialog-body label { display:block; font-size:.8rem; font-weight:700; color:var(--ink); margin-bottom:.25rem; }
        .edge-dialog-body select { width:100%; }
        .edge-dialog-foot { display:flex; justify-content:flex-end; gap:.5rem; padding:.6rem 1rem; border-top:1px solid var(--line); }
        .edge-dialog-close { min-height:0; width:32px; height:32px; padding:0; border:0; background:transparent; font-size:1.2rem; }
        .edge-confirm-icon { font-size:2.2rem; line-height:1; text-align:center; margin-bottom:.4rem; color:#f8bb86; }
        .edge-confirm-title { font-size:1.2rem; text-align:center; margin:0 0 .4rem; }
        .edge-confirm-text { text-align:center; color:var(--muted); margin:0; }
        .bound-branch { background:#f8fafc; border:1px solid var(--line); border-radius:6px; padding:.5rem .75rem; margin-bottom:.9rem; }

        /* SweetAlert2 over the generic dialog; gold confirm like Online */
        .swal2-container { z-index:1095 !important; }
        .swal2-styled.swal2-confirm { background-color:var(--primary) !important; }

        /* ── Online breakpoints (O:342-370) ── */
        /* ≥1200 wide AND ≥720 tall: fixed-height shell, each column scrolls inside itself. */
        @media (min-width: 1200px) and (min-height: 720px) {
            body { height:100vh; display:flex; flex-direction:column; overflow:hidden; }
            main { flex:1 1 auto; min-height:0; align-items:stretch; grid-template-rows:minmax(0,1fr); }
            .grid-pane { min-height:0; overflow:hidden; }
            .tiles { flex:1 1 auto; min-height:0; max-height:none; }
            .cart-pane { position:static; top:auto; height:100%; min-height:0; overflow-y:auto; }
        }
        /* < 1200: one column — the cart flows under the products (Online: static cart). */
        @media (max-width: 1199px) {
            main { grid-template-columns:1fr; }
            .cart-pane { position:static; height:auto; }
            .lines { min-height:220px; height:auto; max-height:48vh; }
            .pos-customer-slot { margin-left:0; }
        }
        /* < 992: large dialogs go full-screen (Online modal-fullscreen-lg-down). */
        @media (max-width: 991.98px) {
            .modal { padding:0; }
            .modal .box { width:100vw; max-height:100vh; height:100vh; border-radius:0; }
            .edge-nav { margin-left:0; width:100%; }
        }
        /* small (≤ 800): tighter chrome, the mode tabs scroll sideways (Online .mode-tabs overflow-x:auto). */
        @media (max-width: 800px) {
            .pos-header { padding:8px 8px 0; }
            main { padding:0 8px 8px; gap:.6rem; }
            .pos-title .pos-title-pre, .pos-title h1 { font-size:1.25rem; }
            .mode-tabs { flex-wrap:nowrap; width:100%; }
            .order-controls-row .order-tools { margin-left:0; }
            .grid-pane, .cart-pane { padding:.75rem; }
        }
        @media (prefers-reduced-motion: reduce) { .tile, .tbl { transition:none; } .edge-spinner, #edge-loading { animation-duration:3s; } }
    </style>
@if(\App\Http\Controllers\Edge\EdgeLocalAssetController::available())
    <style>.nav-glyph { display:none; }</style>
@else
    <style>.ti { display:none !important; }</style>
@endif
