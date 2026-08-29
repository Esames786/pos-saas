{{-- KASHIF-CATERING-OPERATOR-UI-1 — the drivers' list. One row per selected
     booking: number, date, time, customer, phone, venue/address, guests. No
     prices anywhere — this sheet rides in the delivery van. Read-only. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Address Sheet — {{ $events->count() }} bookings</title>
<style>
    @page { size: A4 portrait; margin: 12mm; }
    * { box-sizing: border-box; }
    html { background: #e5e7eb; }
    body {
        font-family: Arial, Helvetica, sans-serif; color: #111827;
        font-size: 13px; line-height: 1.5;
        width: 210mm; min-height: 297mm; margin: 12px auto; padding: 12mm;
        background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.18);
    }
    @media print {
        html { background: #fff; }
        body { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
        .toolbar { display: none; }
    }
    .doc-header { display: flex; justify-content: space-between; align-items: flex-start;
                  border-bottom: 3px solid #111827; padding-bottom: 10px; margin-bottom: 12px; }
    .brand { font-size: 22px; font-weight: bold; }
    .doc-title { text-align: right; }
    .doc-title h2 { margin: 0; font-size: 18px; }
    .doc-title .sub { color: #6b7280; font-size: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #111827; color: #fff; padding: 7px 8px; font-size: 12px; text-align: left; }
    td { border-bottom: 1px solid #e5e7eb; padding: 7px 8px; vertical-align: top; }
    tr { page-break-inside: avoid; }
    .ur { font-family: 'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif;
          direction: rtl; line-height: 2; }
    .muted { color: #6b7280; font-size: 12px; }
    .toolbar { text-align: center; margin-bottom: 10px; }
</style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()" style="padding:6px 18px;font-size:14px;cursor:pointer">Print Address Sheet</button>
    </div>
    <div class="doc-header">
        <div>
            <div class="brand">{{ $businessName }}</div>
            <div class="muted">Delivery &amp; logistics list</div>
        </div>
        <div class="doc-title">
            <h2>ADDRESS SHEET</h2>
            <div class="sub">{{ $events->count() }} {{ \Illuminate\Support\Str::plural('booking', $events->count()) }} · printed {{ app(\App\Support\TenantClock::class)->now()->format('d M Y g:i A') }}</div>
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th style="width:12%">Booking</th>
                <th style="width:13%">Date</th>
                <th style="width:9%">Time</th>
                <th style="width:20%">Customer</th>
                <th style="width:14%">Phone</th>
                <th>Venue / Delivery Address</th>
                <th style="width:7%; text-align:right">PAX</th>
            </tr>
        </thead>
        <tbody>
            @foreach($events as $event)
            <tr>
                <td><strong>{{ $event->event_no }}</strong></td>
                <td>{{ $event->event_date->format('D, d M Y') }}</td>
                <td>{{ $event->service_time ? \Carbon\Carbon::parse($event->service_time)->format('g:i A') : '—' }}</td>
                <td>
                    {{ $event->customer_name }}
                    @if($event->customer_name_ur)
                        <div class="ur">{{ $event->customer_name_ur }}</div>
                    @endif
                </td>
                <td>{{ $event->customer_phone ?? '—' }}</td>
                <td>
                    {{ $event->venue ?? '—' }}
                    @if($event->customer_address && $event->customer_address !== $event->venue)
                        <div class="muted">{{ $event->customer_address }}</div>
                    @endif
                </td>
                <td style="text-align:right">{{ number_format($event->pax) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
