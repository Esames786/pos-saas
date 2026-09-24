{{-- W1 (Team 1): the Branch Server sign-in, in the Online light look (Dreams POS tokens: #F7F7F7 page, white card, gold primary).
     Self-contained: inline CSS + inline icon; the packaged Bootstrap 5.3.8 loads through the local asset route only when it is
     enabled on this appliance. No external URL, no font import (works with NO Internet). --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bingoo Edge — Local Login</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23CAA23F'/%3E%3Cpath d='M18 14h17c8 0 13 4 13 10 0 4-2 7-6 8 5 1 8 5 8 10 0 7-6 12-15 12H18z' fill='%23fff'/%3E%3C/svg%3E">
@if(\App\Http\Controllers\Edge\EdgeLocalAssetController::available())
    <link rel="stylesheet" href="{{ \App\Http\Controllers\Edge\EdgeLocalAssetController::url('css/bootstrap.min.css') }}">
@endif
    <style>
        * { box-sizing:border-box; }
        body { font-family:"Nunito","Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif; background:#F7F7F7; color:#646B72; display:flex; min-height:100vh; align-items:center; justify-content:center; margin:0; font-size:14px; padding:1rem; }
        .card { background:#fff; padding:2rem; border-radius:8px; width:min(380px,100%); border:1px solid #edf0f4; box-shadow:0 12px 34px rgba(15,23,42,.08); }
        .brand { display:flex; align-items:center; gap:.6rem; margin-bottom:.25rem; }
        .brand .mark { width:40px; height:40px; border-radius:10px; background:#CAA23F; color:#fff; display:grid; place-items:center; font-weight:900; font-size:1.3rem; }
        h1 { font-size:1.35rem; margin:0; color:#212B36; font-weight:700; }
        p.sub { color:#6c757d; font-size:.85rem; margin:.25rem 0 1.25rem; }
        label { display:block; font-size:.85rem; font-weight:700; color:#212B36; margin:.9rem 0 .3rem; }
        input { width:100%; padding:.6rem .75rem; border-radius:6px; border:1px solid #dee2e6; background:#fff; color:#212B36; font:inherit; min-height:44px; }
        input:focus { border-color:#e3c77e; outline:0; box-shadow:0 0 0 .2rem rgba(202,162,63,.2); }
        button { width:100%; margin-top:1.4rem; padding:.7rem; border:1px solid #CAA23F; border-radius:6px; background:#CAA23F; color:#fff; font-weight:700; cursor:pointer; min-height:46px; font:inherit; font-weight:700; box-shadow:0 4px 20px rgba(202,162,63,.15); }
        button:hover { background:#b08a2e; }
        button[aria-busy=true] { opacity:.7; cursor:progress; }
        .err { background:#f8d7da; border:1px solid #f1aeb5; color:#842029; padding:.55rem .8rem; border-radius:6px; font-size:.85rem; margin-bottom:1rem; }
        .foot { margin-top:1rem; font-size:.75rem; color:#6c757d; text-align:center; }
    </style>
</head>
<body>
    <form class="card" method="POST" action="{{ url('/edge/local/login') }}" id="edge-login-form">
        @csrf
        <div class="brand"><span class="mark" aria-hidden="true">B</span><h1>Bingoo Edge</h1></div>
        <p class="sub">Branch Server @if($branchId)· Branch #{{ $branchId }}@endif — sign in with your employee code and Edge credential.</p>
        @if($errors->any())
            <div class="err" role="alert">{{ $errors->first() }}</div>
        @endif
        <label for="employee_code">Employee code</label>
        <input id="employee_code" name="employee_code" value="{{ old('employee_code') }}" autocomplete="username" autofocus required>
        <label for="credential">Edge credential</label>
        <input id="credential" name="credential" type="password" autocomplete="current-password" required>
        <button type="submit" id="edge-login-submit">Log in</button>
        <p class="foot">Works without Internet — this sign-in is checked on the Branch Server.</p>
    </form>
    <script>
        document.getElementById('edge-login-form').addEventListener('submit', function () {
            var b = document.getElementById('edge-login-submit');
            b.setAttribute('aria-busy', 'true'); b.textContent = 'Signing in…';
        });
    </script>
</body>
</html>
