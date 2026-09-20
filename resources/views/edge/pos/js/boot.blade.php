{{-- W0 js/boot (Team 1 owns): event wiring, first render, the public EdgePOS handle used by inline onclick handlers. Always the LAST fragment. --}}
        $('search').addEventListener('input', renderTiles);
        $('customer-name').addEventListener('input', renderChips);
        $('view-tables-btn').addEventListener('click', viewTables);
        $('shift-btn').addEventListener('click', shiftAction);
        $('returns-btn').addEventListener('click', returnsFlow);

        renderOrderTypes(); renderTerminals(); renderTabs(); renderTiles(); renderCart();
        refreshSync(); setInterval(refreshSync, 60000);
        window.EdgePOS = { closeModal, state, loadHeld, viewTables };
