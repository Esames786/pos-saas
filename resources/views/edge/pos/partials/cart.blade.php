{{-- W0 (Team 2 owns lines/totals, Team 3 owns the action grid from W2/W3): the cart pane. --}}
        <section class="cart-pane">
            <div class="cart-head">
                <span class="chip" id="customer-chip">Walk-in</span>
                <span class="chip" id="check-chip" hidden></span>
                <input type="text" id="customer-name" placeholder="Customer (optional)" style="flex:1;min-width:120px">
            </div>
            <div class="lines" id="cart-lines"><p class="muted" style="padding:.6rem">Cart is empty.</p></div>
            <div class="totals" id="totals">
                <div class="row"><span>Items</span><span id="t-items">0</span></div>
                <div class="row grand"><span>Total</span><span id="t-grand">0.00</span></div>
            </div>
            <div class="actions" id="actions"></div>
        </section>
