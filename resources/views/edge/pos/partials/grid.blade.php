{{-- W2 (Team 2): the menu pane in the Online layout (tenant/pos/index.blade.php:585-603 quick-sale fields, :661-690 delivery panel,
     :691-696 #pos_search, :698-722 parent + child category strips, :725-729 #product-grid) — all behaviour in js/catalog + js/cart.
     #search stays as a hidden compatibility element only because js/boot (Team 1) still wires $('search'); the live box is #pos_search.
     Styles for the W2 controls live here (the page stylesheet partial is Team 1's) and use the shared CSS variables. --}}
        <style>
            .w2-ctx { display:flex; flex-wrap:wrap; gap:.5rem .9rem; align-items:center; padding:.45rem .7rem 0; }
            .w2-ctx[hidden], .w2-ctx .inl[hidden], #delivery-panel[hidden], #child-category-wrap[hidden], #delivery-rider-wrap[hidden] { display:none !important; }
            .w2-ctx .inl { display:inline-flex; align-items:center; gap:.35rem; font-size:.82rem; }
            .w2-ctx .req, .w2-req { color:#f87171; }
            #delivery-panel { display:flex; flex-wrap:wrap; gap:.5rem .8rem; padding:.45rem .7rem 0; align-items:flex-end; }
            #delivery-panel .dfield { display:flex; flex-direction:column; gap:.15rem; font-size:.78rem; min-width:150px; }
            #delivery-panel .dfield label { color:var(--muted); }
            #delivery-panel .dnote { font-size:.75rem; color:var(--muted); align-self:center; }
            .w2-strip { display:flex; gap:.4rem; padding:.45rem .7rem; flex-wrap:wrap; }
            .w2-strip.child { padding-top:0; }
            .w2-strip .pill { min-height:44px; }
            .w2-strip.child .pill { min-height:44px; font-size:.82rem; }
            .tile.w2 { min-height:148px; gap:.25rem; justify-content:flex-start; position:relative; }
            .tile.w2 .av { width:42px; height:42px; border-radius:10px; background:var(--panel2); border:1px solid var(--line); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.85rem; overflow:hidden; }
            .tile.w2 .av img { width:100%; height:100%; object-fit:cover; }
            .tile.w2 .nm { font-weight:700; }
            .tile.w2 .sku { font-size:.72rem; color:var(--muted); }
            .tile.w2 .ft { display:flex; justify-content:space-between; align-items:center; gap:.3rem; margin-top:auto; }
            .tile.w2 .ft .pr { margin:0; color:var(--ink); font-weight:700; }
            .tile.w2 .note { font-size:.72rem; color:var(--muted); }
            .tile.w2 .note.cust { color:#93c5fd; }
            .tile.w2.stock-out { opacity:.55; }
            .stock-badge { font-size:.68rem; border-radius:999px; padding:.1rem .45rem; border:1px solid var(--line); white-space:nowrap; }
            .stock-badge.stock-ok { border-color:#15803d; color:#86efac; }
            .stock-badge.stock-low { border-color:#b45309; color:#fcd34d; }
            .stock-badge.stock-out { border-color:#b91c1c; color:#fca5a5; }
            .stock-badge.stock-backorder { border-color:#c2410c; color:#fdba74; }
            .stock-badge.stock-svc { color:var(--muted); }
            .w2-form .grp { border:1px solid var(--line); border-radius:10px; padding:.55rem .6rem; margin:.5rem 0; }
            .w2-form .grp-head { display:flex; justify-content:space-between; gap:.5rem; font-size:.85rem; margin-bottom:.3rem; }
            .w2-form .opt { display:flex; align-items:center; gap:.5rem; padding:.35rem .2rem; border-bottom:1px solid var(--line); cursor:pointer; font-size:.85rem; }
            .w2-form .opt:last-child { border-bottom:0; }
            .w2-form .grp-err { color:#fca5a5; font-size:.78rem; margin-top:.2rem; }
            .w2-form .hint { border:1px solid var(--line); border-radius:8px; padding:.45rem .6rem; font-size:.82rem; margin-bottom:.5rem; }
            .w2-form .vgrid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:.5rem; }
            .w2-form .vgrid button { text-align:left; display:flex; flex-direction:column; gap:.2rem; min-height:70px; }
        </style>
        <section class="grid-pane">
            {{-- A39 Quick Sale (Online #vehicle-wrap / #qs-waiter-wrap, O:585-603): inline, required for quick sale only. --}}
            <div class="w2-ctx" id="w2-quick-sale-row" hidden>
                <span class="inl" id="vehicle-wrap" hidden>
                    <label for="vehicle_number">Vehicle <span class="req">*</span></label>
                    <input type="text" id="vehicle_number" maxlength="50" placeholder="e.g. LEA-1234" autocomplete="off" style="width:150px"
                           title="Required for quick-sale / drive-through orders — printed on KOT and receipt so staff can match the order to the car.">
                </span>
                <span class="inl" id="qs-waiter-wrap" hidden>
                    <label for="qs-waiter-select">Waiter <span class="req">*</span></label>
                    <select id="qs-waiter-select" style="width:170px"><option value="">Select waiter…</option></select>
                </span>
            </div>
            {{-- A14 delivery panel (Online #delivery-panel, O:661-690): channel, rider (own channels only), charge. The address
                 is captured through the customer modal (hidden #delivery_address, shown on the customer chip) — as Online. --}}
            <div id="delivery-panel" hidden>
                <div class="dfield">
                    <label for="delivery_channel_id">Delivery Channel</label>
                    <select id="delivery_channel_id"><option value="">Select channel</option></select>
                </div>
                <div class="dfield" id="delivery-rider-wrap" hidden>
                    <label for="delivery_rider_id">Rider</label>
                    <select id="delivery_rider_id"><option value="">Select rider</option></select>
                </div>
                <input type="hidden" id="delivery_address">
                <div class="dfield" style="min-width:110px">
                    <label for="delivery_charge_amount">Delivery Charge</label>
                    <input type="number" id="delivery_charge_amount" min="0" step="1" placeholder="0" style="width:110px">
                </div>
                <span class="dnote" id="delivery-panel-note"></span>
            </div>
            <div class="toolbar">
                <input type="search" id="pos_search" placeholder="Scan barcode or type product name / SKU" autocomplete="off" aria-label="Barcode / Product Search">
                <input type="hidden" id="search">
            </div>
            <div class="w2-strip" id="parent-category-strip" role="tablist" aria-label="Categories"></div>
            <div id="child-category-wrap" hidden><div class="w2-strip child" id="child-category-strip"></div></div>
            <div class="tiles" id="product-grid" aria-live="polite"></div>
        </section>
