{{-- W1 (Team 1): page-wide banners (Online .alert look) — stock baseline not accepted, network unavailable. --}}
    @unless($operationalStockReady)
        <div class="banner warn" role="alert">Operational stock baseline not accepted yet — selling is refused until a baseline is cut over.</div>
    @endunless
    <div class="banner offline" id="offline-banner" role="alert" hidden>Network unavailable — sales complete locally and sync when the connection returns.</div>
