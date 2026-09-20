{{--
  EDGE-CASHIER-UI — Branch-Server browser cashier POS (page shell).

  The current Online Bingoo POS is the functional AND operator-experience specification (owner directive, 20 Sep 2026,
  after the 149-record audit — docs/status/edge-online-vs-edge-screen-audit-2026-09-20.md). Every mutation the page issues
  targets the Edge-local JSON APIs (edge.local.pos.*), never a Cloud posting/finance/inventory route.

  Self-contained (inline CSS/JS, no Vite/build assets) so it renders on the appliance with NO Internet. The bootstrap
  view-model (`$vm`) comes from EdgeLocalPosController@screen; all authority (stock, shift, terminal, sale, KOT sent-pool,
  table locks, permissions) is re-validated server-side.

  W0 PAGE DECOMPOSITION (20 Sep 2026): this file is only the shell. Markup lives in partials/*, behaviour in js/* — one
  fragment per feature area with a named owning team, so the parity workstreams (W1–W5) edit disjoint files. The js/*
  fragments are concatenated INSIDE the single IIFE below (function declarations hoist across fragments), so the runtime
  is exactly the pre-W0 single script. tests/MySql/EdgeCashierControlCensusHttpMySqlTest.php is the control-census gate:
  every Online POS control id must be registered against this page (present / equivalent / partial / planned /
  online_required) and the composed script must parse.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Bingoo Edge — Cashier POS</title>
@include('edge.pos.partials.styles')
</head>
<body>
    <script id="edge-pos-data" type="application/json">@json($vm)</script>

@include('edge.pos.partials.header')

@include('edge.pos.partials.banners')

    <main>
@include('edge.pos.partials.grid')

@include('edge.pos.partials.cart')
    </main>

@include('edge.pos.partials.shell')

    <script>
    (function () {
        'use strict';
@include('edge.pos.js.core')
@include('edge.pos.js.catalog')
@include('edge.pos.js.cart')
@include('edge.pos.js.commercial')
@include('edge.pos.js.returns')
@include('edge.pos.js.held')
@include('edge.pos.js.payment')
@include('edge.pos.js.printing')
@include('edge.pos.js.tables')
@include('edge.pos.js.reports')
@include('edge.pos.js.shift')
@include('edge.pos.js.sync')
@include('edge.pos.js.boot')
    })();
    </script>
</body>
</html>
