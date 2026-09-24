{{-- W2 (Team 2 owns lines/totals; Team 3 owns the action grid #actions via js/actions): the cart pane.
     Online reference: #cart-items rows (O:3299-3457), the always-visible charge total + the server-quoted totals
     (O:763-771, O:969-988, refreshServerTotals O:3609-3656). The Online customer chip lives in the header (Team 1, #pos-customer-chip);
     #customer-chip here is the compact cart-side mirror Team 1 observes, and #customer-name is the hidden carrier other
     fragments still read (Online keeps a hidden #customer_name for the same reason) — there is no free-text name box. --}}
        <style>
            .cart-line { display:grid; grid-template-columns:1fr auto; gap:.2rem .5rem; padding:.5rem .3rem; border-bottom:1px solid var(--line); }
            .cart-line .ln-nm { font-size:.85rem; font-weight:700; }
            .cart-line .ln-sub { font-size:.72rem; color:var(--muted); }
            .cart-line .ln-mod { font-size:.72rem; color:var(--muted); padding-left:.5rem; }
            .cart-line .ln-note { font-size:.72rem; color:#fcd34d; padding-left:.5rem; }
            .cart-line .ln-tools { display:flex; gap:.25rem; align-items:flex-start; }
            .cart-line .ln-tools button { padding:.15rem .45rem; font-size:.75rem; }
            .cart-line .ln-ctl { grid-column:1 / -1; display:flex; justify-content:space-between; align-items:center; gap:.4rem; }
            .cart-line .ln-ctl .qty { display:flex; align-items:center; gap:.3rem; }
            .cart-line .ln-ctl .qty button { padding:.15rem .55rem; }
            .cart-line .ln-ctl .qty input { width:76px; text-align:right; padding:.25rem .4rem; }
            .cart-line .ln-amt { font-weight:700; font-size:.88rem; }
            .totals .row.sub { font-size:.8rem; color:var(--muted); }
            .totals .row[hidden] { display:none; }
            .totals .quote-note { font-size:.72rem; color:var(--muted); margin-top:.2rem; }
        </style>
        <section class="cart-pane">
            <div class="cart-head">
                <span class="chip" id="customer-chip" role="button" tabindex="0" title="Add / Search Customer">Walk-in</span>
                <span class="chip" id="check-chip" hidden></span>
                <input type="hidden" id="customer-name">
            </div>
            <div class="lines" id="cart-lines"><p class="muted" style="padding:.6rem">No items yet. Scan a barcode or search for a product.</p></div>
            <div class="totals" id="totals">
                <div class="row sub"><span>Items</span><span id="t-items">0</span></div>
                <div class="row sub" id="t-subtotal-row"><span>Subtotal</span><span id="t-subtotal">0.00</span></div>
                <div class="row sub" id="t-discount-row" hidden><span id="t-discount-label">Discount</span><span id="t-discount">0.00</span></div>
                <div class="row sub" id="t-tax-row" hidden><span>Tax</span><span id="t-tax">0.00</span></div>
                <div class="row sub" id="t-service-row" hidden><span>Service charge</span><span id="t-service">0.00</span></div>
                <div class="row sub" id="t-tip-row" hidden><span>Tip</span><span id="t-tip">0.00</span></div>
                <div class="row sub" id="t-delivery-row" hidden><span>Delivery charge</span><span id="t-delivery">0.00</span></div>
                <div class="row grand"><span>Total</span><span id="t-grand">0.00</span></div>
                <div class="quote-note" id="t-quote-note" hidden></div>
            </div>
            <div class="actions" id="actions"></div>
        </section>
