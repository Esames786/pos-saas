{{-- W3 js/actions (Team 3 owns): the contextual action grid — Hold/Draft/Recall for a plain cart, Save round/KOT/Split/Cancel/Leave for a
     held check — plus the Online order-lifecycle entry points (Recent Orders, New Order / Add Round, Clear cart, New Sale). A control that
     another fragment already renders with the same Online id (Team 1 header / Team 2 cart head) is NOT duplicated here. --}}
        // ---- Contextual action buttons (the Online layout: Hold/Draft/Held Orders, Bill/Recent Orders, Cancel/Split, then the cart-head controls). ----
        // Every button carries a stable id (W0) so the control census + browser proofs can target it.
        function renderActions() {
            const a = $('actions'); a.innerHTML = '';
            const btn = (label, cls, fn, wide, id, title) => {
                if (id && document.getElementById(id)) return null;   // already provided by the page shell / another fragment
                const b = document.createElement('button'); b.type = 'button'; b.textContent = label; b.className = cls + (wide ? ' wide' : '');
                if (id) b.id = id; if (title) b.title = title;
                b.addEventListener('click', () => fn()); a.appendChild(b); return b;
            };
            if (state.held) {
                btn(state.dirty ? 'Save round' : 'Saved', state.dirty ? 'primary' : '', () => saveRound(false), false, 'save-round-btn');
                btn('KOT', 'warn', sendKot, false, 'kot-btn');
                btn('Preview Bill', '', previewBill, false, 'preview-bill-btn');
                btn('Split Bill', '', splitBill, false, 'split-bill-btn');
                btn('Review & Pay', 'ok', reviewAndPay, true, 'review-pay-btn');
                btn('Cancel order', 'danger', cancelOrder, false, 'cancel-order-btn');
                btn('Leave check', 'ghost', leaveCheck, false, 'leave-check-btn');
            } else if (state.session) {
                btn('Hold (send later)', 'primary', () => holdSale(false), false, 'hold-sale-btn');
                btn('Draft', '', () => holdSale(true), false, 'draft-btn');
                btn('Preview Bill', '', previewBill, true, 'preview-bill-btn');
                btn('Cancel order', 'danger', cancelOrder, false, 'cancel-order-btn', 'Clear the unsaved items — the table stays open.');
                btn('Leave table', 'ghost', leaveCheck, false, 'leave-check-btn');
            } else {
                btn('Hold', '', () => holdSale(false), false, 'hold-sale-btn');
                btn('Draft', '', () => holdSale(true), false, 'draft-btn');
                btn('Recall', '', recallList, false, 'recall-btn', 'Held Orders');
                btn('Preview Bill', '', previewBill, false, 'preview-bill-btn');
                btn('Review & Pay', 'primary', reviewAndPay, true, 'review-pay-btn');
                btn('Cancel order', 'danger', cancelOrder, false, 'cancel-order-btn', 'Clear the current unsaved cart.');
            }
            // A27 / A37 — Online order-lifecycle controls (Recent Orders button; cart-head New Order·Add Round / Clear / New Sale).
            btn('Recent Orders', 'ghost', openCompletedOrders, false, 'completed-orders-btn', 'Recent completed orders — reprint receipt / KOT');
            const sf = btn('', 'ghost', startFresh, false, 'start-fresh-btn', state.session
                ? 'Add Round — start another order for THIS table without closing it. Previously sent items stay; the new items become a fresh round on the same table.'
                : 'New Order — clear the screen and start a brand-new sale.');
            if (sf) sf.innerHTML = '+ <span id="start-fresh-label">' + (state.session ? 'Add Round' : 'New Order') + '</span>';
            else { const lbl = document.getElementById('start-fresh-label'); if (lbl) lbl.textContent = state.session ? 'Add Round' : 'New Order'; }
            btn('Clear', 'ghost', () => clearCart(new Event('click')), false, 'clear-cart-btn', 'Clear cart');
            btn('New Sale', 'ghost', newSale, false, 'new-sale-btn', 'Start a completely new sale. Any open table check stays on its table and can be recalled later.');
            btn('Quick Report', 'ghost', quickReport, false, 'quick-report-btn');
            btn('Recent Prints', 'ghost', recentPrints, false, 'recent-prints-btn');
            // R10 / A38 — the context bars follow every cart render.
            renderSessionBar();
            renderRecalledBar();
        }
