<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Order {{ $sale->sale_no }} — {{ $businessName }}</title>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#A60D12">
@if(! in_array($step, ['completed', 'cancelled'], true))
<meta http-equiv="refresh" content="30">
@endif
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&family=Allura&display=swap">
<style>
:root { --brand:#A60D12; --cream:#F7EBDD; --ink:#161616; --muted:#6B6B6B; --line:#ECECEC; --ok:#1E7A4C; }
* { box-sizing:border-box; }
body { margin:0; background:#FAFAF8; color:var(--ink); font:15px/1.5 "Poppins","Segoe UI",system-ui,sans-serif; }
.wrap { max-width:560px; margin:0 auto; padding:24px max(16px, env(safe-area-inset-left)) 48px; display:grid; gap:16px; }
h1 { margin:0; text-align:center; font:400 42px/1 "Allura",cursive; color:var(--brand); }
.card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:18px; display:grid; gap:12px; }
.tick { width:60px; height:60px; border-radius:50%; display:grid; place-items:center; font-size:30px; font-weight:700; margin:0 auto; }
.tick.ok { background:#E6F5EC; color:var(--ok); } .tick.no { background:#FDF0F0; color:var(--brand); }
.center { text-align:center; }
.center h2 { margin:6px 0 2px; font-size:20px; }
.center p { margin:0; color:var(--muted); }
.steps { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; font-size:12px; text-align:center; }
.steps span { padding:6px 4px; border-radius:8px; background:#F2F2F2; color:var(--muted); }
.steps span.on { background:#E6F5EC; color:var(--ok); font-weight:700; }
.row { display:flex; justify-content:space-between; gap:12px; font-size:14px; }
.row small { display:block; color:var(--muted); }
.tot { border-top:1px solid var(--line); padding-top:10px; display:grid; gap:6px; }
.tot .gt { font-weight:700; color:var(--brand); font-size:16px; }
.btn { display:block; text-align:center; text-decoration:none; border-radius:12px; padding:12px; font-weight:600; }
.btn.p { background:var(--brand); color:#fff; } .btn.g { border:1.5px solid var(--brand); color:var(--brand); }
</style>
</head>
<body>
@php
    $labels = [
        'received' => ['Order received', 'We have your order. You will get a WhatsApp message to confirm it — or we will call you.'],
        'confirmed' => ['Order confirmed', 'The kitchen has started on your order.'],
        'completed' => ['Order completed', 'Thank you for ordering with us.'],
        'cancelled' => ['Order cancelled', 'This order has been cancelled. Please call us if this is a mistake.'],
    ];
    $order = ['received', 'confirmed', 'completed'];
    $reached = array_search($step, $order, true);
    $items = $sale->lines->where('line_kind', '!=', 'component');
    $eta = data_get($sale->web_meta, 'eta_minutes');
    $money = fn ($v) => 'Rs. '.number_format((float) $v, 0);
@endphp
<div class="wrap">
    <h1>{{ $businessName }}</h1>

    <div class="card center">
        <div class="tick {{ $step === 'cancelled' ? 'no' : 'ok' }}">{{ $step === 'cancelled' ? '×' : '✓' }}</div>
        <h2>{{ $labels[$step][0] }}</h2>
        <p>Order {{ $sale->sale_no }}</p>
        <p>{{ $labels[$step][1] }}</p>
        @if($step !== 'cancelled')
            <div class="steps">
                @foreach(['Received', 'Confirmed', 'Completed'] as $i => $s)
                    <span class="{{ $reached !== false && $i <= $reached ? 'on' : '' }}">{{ $s }}</span>
                @endforeach
            </div>
            @if($eta && $step !== 'completed')
                <p>{{ $sale->order_type === 'takeaway' ? 'Ready in about' : 'Estimated delivery' }} {{ $eta }} minutes.</p>
            @endif
        @endif
    </div>

    <div class="card">
        @foreach($items as $line)
            <div class="row">
                <span>{{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }} × {{ $line->product_name }}
                    @if($line->modifiers)
                        <small>{{ collect($line->modifiers)->pluck('name')->implode(', ') }}</small>
                    @endif
                </span>
                <span>{{ $money($line->line_total) }}</span>
            </div>
        @endforeach
        <div class="tot">
            <div class="row"><span>Subtotal</span><span>{{ $money($sale->subtotal) }}</span></div>
            @if((float) $sale->delivery_charge_amount > 0)
                <div class="row"><span>Delivery fee</span><span>{{ $money($sale->delivery_charge_amount) }}</span></div>
            @endif
            @if((float) $sale->tax_amount > 0)
                <div class="row"><span>Tax</span><span>{{ $money($sale->tax_amount) }}</span></div>
            @endif
            <div class="row gt"><span>Total — cash on {{ $sale->order_type === 'takeaway' ? 'pick-up' : 'delivery' }}</span><span>{{ $money($sale->grand_total) }}</span></div>
        </div>
    </div>

    @if($phone)
        <a class="btn g" href="tel:{{ preg_replace('/\s+/', '', $phone) }}">Call {{ $phone }}</a>
    @endif
    <a class="btn p" href="{{ url('/order') }}">Back to menu</a>
</div>
</body>
</html>
