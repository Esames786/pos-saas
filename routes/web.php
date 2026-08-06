<?php
use Illuminate\Support\Facades\Route;

// EDGE-RUNTIME-BOUNDARY-HARDEN-1: routes are registered per runtime mode. A Branch Server registers
// ONLY the Edge runtime endpoints — the entire Cloud SaaS surface (public/central/tenant + the
// central pairing API) is NOT even registered there (strongest boundary; the middleware remains as
// defence-in-depth). Cloud registers the full SaaS and does NOT register the appliance-local Edge
// endpoints, so /edge/local/* returns 404 on Cloud (no runtime/build fingerprint exposed publicly).
if (\App\Support\EdgeRuntime::isBranchServer()) {
    require __DIR__ . '/edge_runtime.php';
} else {
    require __DIR__ . '/public.php';
    require __DIR__ . '/central.php';
    require __DIR__ . '/edge.php';
    require __DIR__ . '/tenant.php';
}
