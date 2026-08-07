<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bingoo Edge — Local Login</title>
    <style>
        body { font-family: system-ui, sans-serif; background:#0f172a; color:#e2e8f0; display:flex; min-height:100vh; align-items:center; justify-content:center; margin:0; }
        .card { background:#1e293b; padding:2rem; border-radius:12px; width:320px; box-shadow:0 10px 30px rgba(0,0,0,.4); }
        h1 { font-size:1.1rem; margin:0 0 .25rem; }
        p.sub { color:#94a3b8; font-size:.8rem; margin:0 0 1.25rem; }
        label { display:block; font-size:.8rem; margin:.75rem 0 .25rem; }
        input { width:100%; box-sizing:border-box; padding:.6rem; border-radius:8px; border:1px solid #334155; background:#0f172a; color:#e2e8f0; }
        button { width:100%; margin-top:1.25rem; padding:.7rem; border:0; border-radius:8px; background:#4f46e5; color:#fff; font-weight:600; cursor:pointer; }
        .err { background:#7f1d1d; color:#fecaca; padding:.5rem .75rem; border-radius:8px; font-size:.8rem; margin-bottom:1rem; }
    </style>
</head>
<body>
    <form class="card" method="POST" action="{{ url('/edge/local/login') }}">
        @csrf
        <h1>Bingoo Edge</h1>
        <p class="sub">Branch Server @if($branchId)· Branch #{{ $branchId }}@endif</p>
        @if($errors->any())
            <div class="err">{{ $errors->first() }}</div>
        @endif
        <label for="employee_code">Employee code</label>
        <input id="employee_code" name="employee_code" value="{{ old('employee_code') }}" autofocus required>
        <label for="credential">Edge credential</label>
        <input id="credential" name="credential" type="password" required>
        <button type="submit">Log in</button>
    </form>
</body>
</html>
