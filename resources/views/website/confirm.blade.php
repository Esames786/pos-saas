<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Confirm order {{ $sale->sale_no }} — {{ $businessName }}</title>
<style>
body { margin:0; min-height:100vh; display:grid; place-items:center; background:#FAFAF8; color:#161616; font:16px/1.5 "Segoe UI",system-ui,sans-serif; }
.box { max-width:420px; margin:24px; background:#fff; border:1px solid #ECECEC; border-radius:16px; padding:28px; text-align:center; }
h1 { font-size:20px; margin:0 0 6px; }
p { color:#6B6B6B; margin:0 0 18px; }
button { width:100%; border:0; border-radius:12px; background:#A60D12; color:#fff; padding:14px; font-size:16px; font-weight:700; cursor:pointer; }
</style>
</head>
<body>
<div class="box">
    <h1>{{ $businessName }}</h1>
    <p>Confirming order {{ $sale->sale_no }}…</p>
    {{-- Script submits it for a person's phone; bots and link previews do not run scripts, so they never confirm. --}}
    <form method="POST" action="{{ url('/order/confirm/'.$token) }}" id="confirm-form">
        @csrf
        <button type="submit">Confirm my order</button>
    </form>
</div>
<script>document.getElementById('confirm-form').submit();</script>
</body>
</html>
