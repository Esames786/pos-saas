{{-- W0 (Team 1 owns from W1): page-wide banners — stock baseline not accepted, network unavailable. --}}
    @unless($operationalStockReady)
        <div class="banner warn">Operational stock baseline not accepted yet — selling is refused until a baseline is cut over.</div>
    @endunless
    <div class="banner offline" id="offline-banner" hidden>Network unavailable — sales complete locally and sync when the connection returns.</div>
