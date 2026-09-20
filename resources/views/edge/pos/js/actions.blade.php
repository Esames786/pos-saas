{{-- W0d js/actions (Team 3 owns): the contextual action grid — Hold/Draft/Recall for a plain cart, Save round/KOT/Split/Cancel/Leave for a held check. --}}
        // ---- Contextual action buttons (the Online layout: Hold/Draft/Recall, then KOT/Add Round for a check). ----
        // Every button carries a stable id (W0) so the control census + browser proofs can target it.
        function renderActions() {
            const a = $('actions'); a.innerHTML = '';
            const btn = (label, cls, fn, wide, id) => { const b = document.createElement('button'); b.textContent = label; b.className = cls + (wide ? ' wide' : ''); if (id) b.id = id; b.addEventListener('click', fn); a.appendChild(b); return b; };
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
                btn('Leave table', 'ghost', leaveCheck, true, 'leave-check-btn');
            } else {
                btn('Hold', '', () => holdSale(false), false, 'hold-sale-btn');
                btn('Draft', '', () => holdSale(true), false, 'draft-btn');
                btn('Recall', '', recallList, false, 'recall-btn');
                btn('Preview Bill', '', previewBill, false, 'preview-bill-btn');
                btn('Review & Pay', 'primary', reviewAndPay, true, 'review-pay-btn');
            }
            btn('Quick Report', 'ghost', quickReport, false, 'quick-report-btn');
            btn('Recent Prints', 'ghost', recentPrints, false, 'recent-prints-btn');
        }
