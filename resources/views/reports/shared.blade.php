<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $businessName }} — {{ $periodLabel }}</title>
<style>
    /* WHATSAPP-REPORT-CHANNEL-1: this is read on a PHONE, in WhatsApp's in-app browser, by an owner
       who wants one number. So: big figures, no table that needs sideways scrolling, nothing to zoom. */
    :root { --ink:#111; --muted:#6b7280; --line:#e5e7eb; --accent:#0d6efd; }
    * { box-sizing: border-box; }
    body { margin:0; padding:16px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
           color:var(--ink); background:#f8f9fa; }
    .wrap { max-width:560px; margin:0 auto; }
    h1 { font-size:1.15rem; margin:0 0 2px; }
    .period { color:var(--muted); font-size:.9rem; margin-bottom:16px; }
    .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:12px; }
    .headline { font-size:2rem; font-weight:700; letter-spacing:-.5px; }
    .headline-label { color:var(--muted); font-size:.8rem; text-transform:uppercase; letter-spacing:.5px; }
    .pair { display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px solid var(--line); }
    .pair:last-child { border-bottom:0; }
    .pair .k { color:var(--muted); }
    .pair .v { font-weight:600; font-variant-numeric:tabular-nums; }
    .btn { display:block; text-align:center; background:var(--accent); color:#fff; text-decoration:none;
           padding:14px; border-radius:10px; font-weight:600; margin-top:4px; }
    .note { color:var(--muted); font-size:.78rem; margin-top:14px; line-height:1.5; }
</style>
</head>
<body>
<div class="wrap">

    <h1>{{ $businessName }}</h1>
    <div class="period">{{ $periodLabel }}</div>

    @if(!empty($overview))
    <div class="card">
        <div class="headline-label">Net sales</div>
        <div class="headline">{{ number_format((float) $overview['net_sales'], 0) }}</div>
    </div>

    <div class="card">
        <div class="pair"><span class="k">Orders</span><span class="v">{{ number_format((float) $overview['orders'], 0) }}</span></div>
        <div class="pair"><span class="k">Items sold</span><span class="v">{{ number_format((float) $overview['gross_sales'], 0) }}</span></div>
        <div class="pair"><span class="k">Discount</span><span class="v">-{{ number_format((float) $overview['discount'], 0) }}</span></div>
        <div class="pair"><span class="k">Returns</span><span class="v">-{{ number_format((float) $overview['returns_amount'], 0) }}</span></div>
        <div class="pair"><span class="k">Cash collected</span><span class="v">{{ number_format((float) $overview['cash_collected'], 0) }}</span></div>
    </div>
    @endif

    <a class="btn" href="{{ $pdfUrl }}" download>Download full PDF</a>

    <div class="note">
        This link opens only for a short time and then stops working. The full report is in the PDF.
    </div>

</div>

{{-- The owner asked for the PDF to arrive without another tap. One response cannot both render and
     download, so the page starts the download itself. WhatsApp's in-app browser blocks this often
     enough that the button above is not a fallback but the reliable path. --}}
<iframe src="{{ $pdfUrl }}" style="display:none" title="report download"></iframe>

</body>
</html>
