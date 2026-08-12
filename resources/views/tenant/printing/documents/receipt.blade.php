<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt — {{ $salesOrder->sale_no ?? $salesOrder->order_no }}</title>
    <style>
        @php
            $fontSize  = $layout?->font_size ?? 12;
            $paperSize = $layout?->paper_size ?? '80mm';
            $width     = match($paperSize) { '58mm' => '52mm', '80mm' => '72mm', default => '180mm' };

            // THE LAYOUT SETTING'S SIZE NOW REACHES THIS PREVIEW TOO.
            // The thermal receipt prints item lines and the totals block TALLER once font_size
            // crosses the same bands EscPosPayloadService::scaleFor() uses (receipts scale in
            // height only — the money columns need their width). This preview showed everything
            // at one flat px size, so the client compared a double-height paper bill with a small
            // uniform preview and rightly called them different. Item and totals rows now grow by
            // the same bands. The header staying smaller than the items is faithful: that is
            // exactly how the paper prints.
            //
            // A TABLE carries the growth safely — the amount column keeps its own cell, so unlike
            // the ESC/POS 42-character composition, bigger type here cannot break the alignment;
            // long names simply wrap inside their cell, as they already do.
            $growBand = match (true) { $fontSize <= 14 => 1.0, $fontSize <= 20 => 1.45, default => 1.9 };
            // SHIFT-TIMEZONE-BUSINESS-DATE-HARDEN-1: print in the ORIGINAL operational timezone —
            // frozen shift tz first, then branch, then default — so changing the branch timezone
            // later never shifts a historical receipt/reprint's time.
            $clock = app(\App\Support\TenantClock::class);
            $printTz = $clock->normalize($salesOrder->shift?->timezone_name)
                ?? $clock->normalize($salesOrder->branch?->timezone)
                ?? \App\Support\TenantClock::DEFAULT_TIMEZONE;
            // ONE visibility rule, identical to EscPosPayloadService: no layout row at all means
            // show everything; once a row exists its stored value decides. Written as
            // "!( ... === false)" this blade showed a NULL column that the thermal payload hid,
            // so the on-screen preview and the paper could disagree.
            $show = fn (string $field) => $layout === null ? true : (bool) ($layout->{$field} ?? false);
        @endphp
        /* THE ROLL STARTED A FINGER'S WIDTH DOWN THE PAGE.
           No @page rule was ever declared, so the browser applied its own default page size and
           ~10mm margins on every side. On a continuous roll that prints as a blank band above the
           receipt and wasted paper below it. "size: <width> auto" asks for a roll-height page
           instead of a sheet, and margin 0 hands the whole width back to us. */
        @page { size: {{ $width }} auto; margin: 0; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: {{ $fontSize }}px;
            width: {{ $width }};
            margin: 0 auto;
            padding: 4px;
            color: #000;
        }
        .center  { text-align: center; }
        .right   { text-align: right; }
        .bold    { font-weight: bold; }
        hr       { border: none; border-top: 1px dashed #000; margin: 4px 0; }
        table    { width: 100%; border-collapse: collapse; }
        td       { vertical-align: top; }
        /* ONE LINE PER ITEM, LIKE THE PAPER.
           This was a four-column table (Item/Qty/Price/Total) on a 72mm slip. There is not enough
           room for four columns, so the name was squeezed into a third of the width and
           "Chicken Biryani (1/2 kg)" wrapped over three lines — while the thermal receipt printed
           the same item on a single tidy line. That is why the final bill looked right and the
           preview did not. The description now carries the quantity, exactly as
           EscPosPayloadService::receipt() composes it, leaving the whole remaining width to the
           amount. Two columns also make the old number collision ("11,200.001,200.00")
           impossible rather than merely padded apart. */
        /* Item | Qty | Rate | Amount — the wide name column WRAPS (that was the real fix; an
           earlier 40% name column was what wrapped names into a mess), the numeric columns stay
           narrow and right-aligned so they never collide. Matches the thermal composition. */
        .item-name  { width: 46%; word-break: break-word; }
        .item-qty   { width: 12%; text-align: right; white-space: nowrap; padding-left: 4px; }
        .item-rate  { width: 20%; text-align: right; white-space: nowrap; padding-left: 4px; }
        .item-total { width: 22%; text-align: right; white-space: nowrap; padding-left: 4px; }
        .totals td:first-child { width: 60%; text-align: right; padding-right: 4px; }
        .totals td:last-child  { width: 40%; text-align: right; white-space: nowrap; }
        /* What the customer reads — items and money — at the paper's scale. em, so the header
           font_size stays the base and the band multiplies it, print and screen alike. */
        .grow td { font-size: {{ $growBand }}em; }
        .print-btn { display: block; margin: 12px auto; padding: 8px 24px; cursor: pointer; font-size: 14px; }
        /* 320px left the slip swimming in the modal and every character smaller than it needed
           to be. Screen-only: the printed page still sizes from the paper width via @page. */
        @media screen { body { width: 400px; } }
        @media print  {
            .print-btn, .no-print { display: none !important; }
            /* Own the full roll width: the outer margin is gone, so only a hair of padding to keep
               ink off the paper edge. */
            body { width: {{ $width }}; margin: 0; padding: 1mm 1.5mm 0; }
        }
    </style>
</head>
<body>
@php
    // Piece units ("2 EA") are noise on a ticket — print the number alone; real measures still show.
    $unitSuffix = fn ($code) => ($code && ! in_array(strtoupper(trim((string) $code)), ['EA','EACH','PC','PCS','PIECE','PIECES','NOS','NO','UNIT','UNITS','QTY'], true)) ? ' ' . $code : '';
@endphp

@if($show('show_logo') && $layout?->logo_path)
<div class="center" style="margin-bottom:4px">
    <img src="{{ asset('storage/' . $layout->logo_path) }}" style="max-width:80px;max-height:40px">
</div>
@endif

@if($show('show_branch_name'))
<div class="center bold">{{ $salesOrder->branch?->name }}</div>
@endif

@if($show('show_branch_address') && $salesOrder->branch?->address)
<div class="center">{{ $salesOrder->branch->address }}</div>
@endif

@if($show('show_branch_phone') && $salesOrder->branch?->phone)
<div class="center">Tel: {{ $salesOrder->branch->phone }}</div>
@endif

@if($show('show_tax_number') && $salesOrder->branch?->tax_number)
<div class="center">Tax No: {{ $salesOrder->branch->tax_number }}</div>
@endif

@if($layout?->header_text)
<div class="center" style="margin-top:4px">{{ $layout->header_text }}</div>
@endif

<hr>

<div class="bold center">{{ ($isPreview ?? false) ? 'BILL PREVIEW' : 'RECEIPT' }}</div>
@if($show('show_order_type'))
<div class="bold center">** {{ strtoupper(str_replace('_', ' ', $salesOrder->order_type ?? 'SALE')) }} **</div>
@endif

<hr>

@if($show('show_order_no'))
<div>Order: <span class="bold">{{ $salesOrder->sale_no ?? $salesOrder->order_no }}</span></div>
@endif

<div>Date: {{ ($salesOrder->sale_date ?? $salesOrder->created_at)?->copy()->timezone($printTz)->format('d/m/Y h:i A') }}</div>

@if($show('show_cashier_name') && $salesOrder->createdBy)
<div>Cashier: {{ $salesOrder->createdBy->name }}</div>
@endif

{{-- BOLD: what the rider reads at the door — who, which number, which address. Matches the
     thermal payload, which bolds the same lines. --}}
@if($show('show_customer_name'))
    @php
        $billCustomerName = $salesOrder->customer_name ?: $salesOrder->customer?->name;
        $billCustomerPhone = $salesOrder->customer_phone ?: $salesOrder->customer?->phone;
    @endphp
    @if($billCustomerName)<div class="bold">Customer: {{ $billCustomerName }}</div>@endif
    @if($billCustomerPhone)<div class="bold">Phone: {{ $billCustomerPhone }}</div>@endif
@endif

@if($show('show_table_info') && $salesOrder->restaurantTable)
<div>Table: {{ $salesOrder->restaurantTable->table_no }}
    @if($salesOrder->restaurantTable->floor)
        ({{ $salesOrder->restaurantTable->floor->name }})
    @endif
</div>
@if($salesOrder->restaurantTableSession?->waiter)
<div>Waiter: {{ $salesOrder->restaurantTableSession->waiter->name }}</div>
@endif
@endif

@if($show('show_delivery_details'))
    @if($salesOrder->deliveryChannel)<div>Channel: {{ $salesOrder->deliveryChannel->name }}</div>@endif
    @if($salesOrder->deliveryRider)<div class="bold">Rider: {{ $salesOrder->deliveryRider->name }}{{ $salesOrder->deliveryRider->phone ? ' - ' . $salesOrder->deliveryRider->phone : '' }}</div>@endif
    @if($salesOrder->delivery_address)<div class="bold">Deliver to: {{ $salesOrder->delivery_address }}</div>@endif
@endif
@if($show('show_vehicle_number') && $salesOrder->vehicle_number)
<div class="bold">Vehicle: {{ $salesOrder->vehicle_number }}</div>
@endif

<hr>

<table>
    <thead>
        <tr>
            <td class="item-name bold">Item</td>
            <td class="item-qty bold">Qty</td>
            <td class="item-rate bold">Rate</td>
            <td class="item-total bold">Amount</td>
        </tr>
    </thead>
    <tbody class="grow">
        @foreach($salesOrder->lines as $line)
        @if(($line->line_kind ?? 'standard') === 'component') @continue @endif
        <tr>
            <td class="item-name">
                @if($show('show_item_codes') && $line->product?->barcode)
                    <small>{{ $line->product->barcode }}</small><br>
                @endif
                {{-- Item name in its own column; Qty / Rate / Amount keep theirs so the numbers
                     never collide. Modifiers and combo components read as indented sub-rows. --}}
                {{ $line->product_name }}
                @if($line->variant_name)
                    <small>({{ $line->variant_name }})</small>
                @endif
                @foreach(($line->modifiers ?? []) as $modifier)
                    @if(!empty($modifier['name']))
                        <br><small>&nbsp;+ {{ $modifier['name'] }}@if((float) ($modifier['price_delta'] ?? 0) !== 0.0) ({{ (float) $modifier['price_delta'] > 0 ? '+' : '' }}{{ number_format((float) $modifier['price_delta'], 2) }})@endif</small>
                    @endif
                @endforeach
                @if($line->kitchen_note)
                    <br><small>&nbsp;* {{ $line->kitchen_note }}</small>
                @endif
                @foreach($salesOrder->lines->where('parent_sales_order_line_id', $line->id) as $component)
                    <br><small>&nbsp;- {{ rtrim(rtrim(number_format((float) $component->quantity, 3, '.', ''), '0'), '.') }}{{ $unitSuffix($component->unit_code) }} x {{ $component->product_name }}</small>
                @endforeach
            </td>
            <td class="item-qty">{{ rtrim(rtrim(number_format((float) $line->quantity, 3, '.', ''), '0'), '.') }}{{ $unitSuffix($line->unit_code) }}</td>
            <td class="item-rate">{{ number_format($line->unit_price, 2) }}</td>
            <td class="item-total">{{ number_format($line->line_total, 2) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<hr>

<table class="totals grow">
    <tr>
        <td>Subtotal:</td>
        <td>{{ number_format($salesOrder->subtotal, 2) }}</td>
    </tr>
    @if($salesOrder->discount_amount > 0)
    <tr>
        <td>Discount:</td>
        <td>-{{ number_format($salesOrder->discount_amount, 2) }}</td>
    </tr>
    @endif
    @if($salesOrder->tax_amount > 0)
    <tr>
        <td>Tax:</td>
        <td>{{ number_format($salesOrder->tax_amount, 2) }}</td>
    </tr>
    @endif
    @if((float) ($salesOrder->delivery_charge_amount ?? 0) > 0)
    <tr>
        <td>Delivery Charge:</td>
        <td>{{ number_format($salesOrder->delivery_charge_amount, 2) }}</td>
    </tr>
    @endif
    @if((float) ($salesOrder->service_charge_amount ?? 0) > 0)
    <tr>
        <td>Service Charge:</td>
        <td>{{ number_format($salesOrder->service_charge_amount, 2) }}</td>
    </tr>
    @endif
    @if((float) ($salesOrder->tip_amount ?? 0) > 0)
    <tr>
        <td>Tip:</td>
        <td>{{ number_format($salesOrder->tip_amount, 2) }}</td>
    </tr>
    @endif
    <tr>
        <td class="bold">Total:</td>
        <td class="bold">{{ number_format($salesOrder->grand_total, 2) }}</td>
    </tr>
    @if($salesOrder->paid_amount > 0)
    <tr>
        <td>Paid:</td>
        <td>{{ number_format($salesOrder->paid_amount, 2) }}</td>
    </tr>
    @endif
    @if($salesOrder->change_amount > 0)
    <tr>
        <td>Change:</td>
        <td>{{ number_format($salesOrder->change_amount, 2) }}</td>
    </tr>
    @endif
</table>

@if($show('show_payment_breakdown') && $salesOrder->payments->isNotEmpty())
<hr>
<div class="bold">Payments:</div>
@foreach($salesOrder->payments as $payment)
<table class="totals">
    <tr>
        <td>{{ $payment->method?->name ?? ucfirst($payment->payment_method) }}:</td>
        <td>{{ number_format($payment->tendered_amount ?? $payment->amount, 2) }}</td>
    </tr>
</table>
@endforeach
@endif

<hr>

@if($layout?->footer_text)
<div class="center" style="margin-top:4px">{{ $layout->footer_text }}</div>
@else
<div class="center">Thank you for your visit!</div>
@endif
@if($show('show_bingoo_branding'))
<div class="center" style="margin-top:4px">BingooPos</div>
<div class="center">Bingoopos.com</div>
@endif

<div class="center" style="margin-top:4px; font-size:10px">
    {{ ($salesOrder->sale_date ?? $salesOrder->created_at)?->copy()->timezone($printTz)->format('d/m/Y H:i:s') }}
</div>

<div class="no-print" style="text-align:center;margin:12px 0;display:flex;gap:8px;justify-content:center">
    <button class="print-btn" style="margin:0" onclick="window.print()">🖨 Print</button>
    @if(!empty($job))
    <form method="POST" action="{{ url('/printing/jobs/' . $job->id . '/mark-printed') }}" style="display:inline">
        @csrf
        <button type="submit" class="print-btn" style="margin:0;background:#198754;color:#fff;border:none;cursor:pointer">✔ Mark Printed</button>
    </form>
    @endif
</div>

@if(!empty($job) && $job->printer?->printer_type === 'browser')
<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 300);
    });
</script>
@endif

</body>
</html>
