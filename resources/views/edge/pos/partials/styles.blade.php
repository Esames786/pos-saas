{{-- W0 (Team 1 owns from W1): the cashier page stylesheet. Inline on purpose — the appliance renders with NO Internet. --}}
    <style>
        :root { --bg:#0f172a; --panel:#1e293b; --panel2:#172033; --line:#334155; --ink:#e2e8f0; --muted:#94a3b8; --accent:#4f46e5; --ok:#16a34a; --warn:#b45309; --danger:#b91c1c; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:system-ui,Segoe UI,sans-serif; background:var(--bg); color:var(--ink); height:100vh; display:flex; flex-direction:column; overflow:hidden; font-size:14px; }
        header { background:var(--panel); border-bottom:1px solid var(--line); padding:.5rem .9rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
        header h1 { font-size:1.05rem; margin:0; }
        header .who { color:var(--muted); font-size:.8rem; }
        header .spacer { flex:1; }
        header a.navbtn { color:var(--ink); text-decoration:none; border:1px solid var(--line); border-radius:8px; padding:.5rem .8rem; font-size:.9rem; }
        select, input, button { font:inherit; color:var(--ink); }
        select, input[type=text], input[type=search], input[type=number], input[type=datetime-local], textarea { background:var(--panel2); border:1px solid var(--line); border-radius:8px; padding:.45rem .6rem; }
        button { cursor:pointer; border:1px solid var(--line); background:var(--panel2); border-radius:8px; padding:.5rem .8rem; }
        button.primary { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; }
        button.ok { background:var(--ok); border-color:var(--ok); color:#fff; }
        button.warn { background:var(--warn); border-color:var(--warn); color:#fff; }
        button.danger { background:var(--danger); border-color:var(--danger); color:#fff; }
        button.ghost { background:transparent; }
        button.sm { padding:.3rem .6rem; font-size:.8rem; }
        button:disabled { opacity:.5; cursor:not-allowed; }
        .pill { border-radius:999px; padding:.35rem .8rem; }
        .pill.active { background:var(--accent); border-color:var(--accent); color:#fff; }
        main { flex:1; display:grid; grid-template-columns: 1fr 380px; min-height:0; }
        .grid-pane { display:flex; flex-direction:column; min-height:0; border-right:1px solid var(--line); }
        .tabs { display:flex; gap:.4rem; padding:.5rem .7rem; flex-wrap:wrap; border-bottom:1px solid var(--line); }
        .toolbar { display:flex; gap:.5rem; padding:.5rem .7rem; }
        .toolbar input { flex:1; }
        .tiles { flex:1; overflow-y:auto; padding:.7rem; display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:.55rem; align-content:start; }
        .tile { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:.6rem; text-align:left; min-height:74px; display:flex; flex-direction:column; justify-content:space-between; }
        .tile .nm { font-size:.82rem; line-height:1.15rem; max-height:2.3rem; overflow:hidden; }
        .tile .pr { font-size:.82rem; color:var(--muted); margin-top:.35rem; }
        .tile.deal { border-color:var(--accent); }
        .cart-pane { display:flex; flex-direction:column; min-height:0; background:var(--panel2); }
        .cart-head { padding:.5rem .7rem; border-bottom:1px solid var(--line); display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
        .chip { font-size:.78rem; background:var(--panel); border:1px solid var(--line); border-radius:999px; padding:.3rem .6rem; color:var(--muted); }
        .chip.hot { border-color:var(--accent); color:var(--ink); }
        .chip.draft { border-color:var(--warn); color:#fcd34d; }
        .lines { flex:1; overflow-y:auto; padding:.4rem .5rem; }
        .line { display:grid; grid-template-columns:1fr auto; gap:.2rem .5rem; padding:.45rem .3rem; border-bottom:1px solid var(--line); }
        .line .ln-nm { font-size:.82rem; }
        .line .ln-sub { font-size:.72rem; color:var(--muted); }
        .line .ln-ctl { display:flex; align-items:center; gap:.35rem; }
        .line .ln-ctl button { padding:.15rem .5rem; }
        .line .ln-amt { text-align:right; font-size:.82rem; }
        .totals { padding:.5rem .7rem; border-top:1px solid var(--line); font-size:.85rem; }
        .totals .row { display:flex; justify-content:space-between; padding:.15rem 0; }
        .totals .grand { font-size:1.15rem; font-weight:700; border-top:1px solid var(--line); margin-top:.3rem; padding-top:.4rem; }
        .actions { padding:.6rem .7rem; display:grid; grid-template-columns:1fr 1fr; gap:.45rem; }
        .actions .wide { grid-column:1 / -1; }
        .banner { padding:.35rem .9rem; font-size:.8rem; text-align:center; }
        .banner.warn { background:#3b2b0a; color:#fcd34d; }
        .banner.offline { background:#3a0d0d; color:#fca5a5; }
        .modal { position:fixed; inset:0; background:rgba(2,6,23,.72); display:none; align-items:center; justify-content:center; z-index:40; }
        .modal.open { display:flex; }
        .modal .box { background:var(--panel); border:1px solid var(--line); border-radius:12px; width:min(720px,94vw); max-height:88vh; overflow:auto; padding:1rem 1.1rem; }
        .modal h2 { margin:.1rem 0 .8rem; font-size:1rem; }
        .modal h3 { margin:.6rem 0 .3rem; font-size:.85rem; color:var(--muted); }
        .board { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:.5rem; }
        .tbl { border:1px solid var(--line); border-radius:10px; padding:.6rem; text-align:center; background:var(--panel2); cursor:pointer; }
        .tbl.occupied, .tbl.bill_requested { border-color:var(--warn); }
        .tbl.reserved { border-color:var(--accent); }
        .tbl.selected { outline:2px solid var(--accent); }
        .muted { color:var(--muted); }
        .err { background:#450a0a; color:#fecaca; padding:.5rem .7rem; border-radius:8px; font-size:.82rem; margin:.5rem 0; }
        .toast { position:fixed; bottom:1rem; left:50%; transform:translateX(-50%); background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:.6rem 1rem; z-index:60; display:none; max-width:90vw; }
        .list-row { display:flex; justify-content:space-between; align-items:center; gap:.5rem; padding:.5rem .3rem; border-bottom:1px solid var(--line); cursor:pointer; }
        .list-row:hover { background:var(--panel2); }
        .field { display:flex; flex-direction:column; gap:.2rem; margin:.4rem 0; }
        .field label { font-size:.78rem; color:var(--muted); }
        .btn-row { display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem; flex-wrap:wrap; }
        .btn-row.left { justify-content:flex-start; }
    </style>
