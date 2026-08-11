<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reminder - {{ $reminder['sale_no'] ?? 'Order' }}</title>
    @php
        $paper = $layout->paper_size ?? '80mm';
        $width = $paper === '58mm' ? '54mm' : ($paper === '80mm' ? '76mm' : '190mm');
        $revision = max((int) ($reminder['revision'] ?? 1), 1);
        $isCorrection = in_array($reminder['event_type'] ?? '', ['cancelled_order', 'cancelled_updated_order'], true);
        $lines = collect($reminder['lines'] ?? []);
        $topLines = $lines->filter(fn ($line) => empty($line['parent_line_id']));
        $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
        $time = function ($value) {
            try { return \Carbon\Carbon::parse($value)->format('Y-m-d H:i'); }
            catch (\Throwable) { return $value; }
        };
    @endphp
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 12px; background: #eee; color: #000; font-family: ui-monospace, Consolas, monospace; font-size: {{ (int) ($layout->font_size ?? 12) }}px; }
        .ticket { width: {{ $width }}; max-width: 100%; margin: 0 auto; background: #fff; padding: 10px; }
        .center { text-align: center; }
        .rule { border-top: 1px dashed #000; margin: 7px 0; }
        /* Same character budget as the printer — see EscPosPayloadService::scaleFor(). Deriving the
           preview size from the px value directly would show a line the paper cannot hold. */
        @php
            $rFont  = (int) ($layout->font_size ?? 12);
            $rScaleW = match (true) { $rFont <= 17 => 1, $rFont <= 20 => 2, default => 3 };
            $rCols   = max((int) floor(42 / $rScaleW), 8);
        @endphp
        .heading { font-size: 1.25em; font-weight: 800; }
        .line { margin: 6px 0; font-weight: 700; overflow-wrap: anywhere;
                font-size: calc({{ $width }} / {{ $rCols * 0.6 }}); line-height: 1.2; }
        .child, .modifier, .note { margin-left: 12px; font-weight: 400; }
        .print-btn { display: block; margin: 12px auto; padding: 8px 16px; }
        /* Without an @page rule the browser used its own page size and ~10mm margins, which on a
           continuous roll prints a blank band above the ticket and wastes paper below it. */
        @page { size: {{ $width }} auto; margin: 0; }
        @media print {
            body { background: #fff; margin: 0; padding: 0; }
            .ticket { width: {{ $width }}; margin: 0; padding: 1mm 1.5mm 0; }
            .print-btn { display: none; }
        }
    </style>
</head>
<body>
@php
    // Piece units ("2 EA") are noise on a ticket — print the number alone; real measures still show.
    $unitSuffix = fn ($code) => ($code && ! in_array(strtoupper(trim((string) $code)), ['EA','EACH','PC','PCS','PIECE','PIECES','NOS','NO','UNIT','UNITS','QTY'], true)) ? ' ' . $code : '';
@endphp
<main class="ticket">
    @if(!empty($layout->header_text))<div class="center">{{ $layout->header_text }}</div>@endif
    <div class="heading center">REMINDER</div>
    <div class="heading center">{{ $reminder['heading'] ?? 'REMINDER' }}</div>
    @unless($isCorrection)<div class="center">REVISION {{ $revision }}</div>@endunless
    @if(!empty($reminder['is_reprint']))<div class="center"><strong>DUPLICATE {{ max((int) ($reminder['copy_no'] ?? 1), 1) }}</strong></div>@endif
    <div class="heading center">** {{ strtoupper(str_replace('_', ' ', $reminder['order_type'] ?? 'sale')) }} **</div>
    @if($layout->show_order_no ?? true)<div class="center">{{ $reminder['sale_no'] ?? '' }}</div>@endif
    <div class="rule"></div>
    @if(($layout->show_table_info ?? true) && !empty($reminder['table']))<div>TABLE: {{ $reminder['table'] }}</div>@endif
    @if(($layout->show_table_info ?? true) && !empty($reminder['waiter']))<div>WAITER: {{ $reminder['waiter'] }}</div>@endif
    @if(($layout->show_cashier_name ?? true) && !empty($reminder['cashier']))<div>CASHIER: {{ $reminder['cashier'] }}</div>@endif
    @if(($layout->show_customer_name ?? false) && !empty($reminder['customer']))<div>CUSTOMER: {{ $reminder['customer'] }}</div>@endif
    @if(!empty($reminder['vehicle']))<div>VEHICLE: {{ $reminder['vehicle'] }}</div>@endif
    @if(($layout->show_order_time ?? true) && !empty($reminder['order_time']))<div>ORDER: {{ $time($reminder['order_time']) }}</div>@endif
    @if(($layout->show_updated_time ?? true) && !empty($reminder['updated_time']))<div>UPDATED: {{ $time($reminder['updated_time']) }}</div>@endif
    @if(($layout->show_print_time ?? true) && !empty($reminder['generated_at']))<div>PRINT: {{ $time($reminder['generated_at']) }}</div>@endif
    <div class="rule"></div>

    @if(!empty($reminder['cancelled_lines']))
        <strong>CANCELLED:</strong>
        @foreach($reminder['cancelled_lines'] as $line)
            <div class="line" style="display:flex;justify-content:space-between;gap:8px"><span>{{ strtoupper($line['product_name'] ?? 'Item') }}</span><span style="white-space:nowrap">{{ $qty($line['quantity'] ?? 0) }}{{ $unitSuffix($line['unit_code'] ?? null) }}</span></div>
        @endforeach
        <div class="rule"></div><strong>REMAINING ORDER:</strong>
    @endif

    @forelse($topLines as $line)
        @php
            $delta = (float) ($line['round_delta'] ?? 0);
            $quantity = (float) ($line['quantity'] ?? 0);
            $newLine = $revision > 1 && $delta > 0 && abs($delta - $quantity) < .000001;
            $increase = $revision > 1 && $delta > 0 && $delta < $quantity;
        @endphp
        <div class="line" style="display:flex;justify-content:space-between;gap:8px"><span>{{ $newLine ? '(R) ' : '' }}{{ strtoupper($line['product_name'] ?? 'Item') }}{{ $increase ? ' (R +' . $qty($delta) . ')' : '' }}</span><span style="white-space:nowrap">{{ $qty($quantity) }}{{ $unitSuffix($line['unit_code'] ?? null) }}</span></div>
        @foreach($lines->where('parent_line_id', $line['line_id'] ?? null) as $component)
            <div class="child">- {{ strtoupper($component['product_name'] ?? 'Item') }} x{{ $qty($component['quantity'] ?? 0) }}</div>
            @foreach($component['modifiers'] ?? [] as $modifier)<div class="modifier">&nbsp;&nbsp;+ {{ $modifier['name'] ?? '' }}</div>@endforeach
            @if(!empty($component['kitchen_note']))<div class="note">&nbsp;&nbsp;NOTE: {{ $component['kitchen_note'] }}</div>@endif
        @endforeach
        @foreach($line['modifiers'] ?? [] as $modifier)<div class="modifier">+ {{ $modifier['name'] ?? '' }}</div>@endforeach
        @if(!empty($line['kitchen_note']))<div class="note">NOTE: {{ $line['kitchen_note'] }}</div>@endif
    @empty
        <div class="line">NO REMAINING ITEMS</div>
    @endforelse

    @if($audit = collect($reminder['cancellation_audit'] ?? [])->first())
        <div class="rule"></div>
        @if(!empty($audit['reason']))<div>REASON: {{ $audit['reason'] }}</div>@endif
        @if(!empty($audit['requested_by']))<div>REQUESTED BY: {{ $audit['requested_by'] }}</div>@endif
        @if(!empty($audit['approved_by']))<div>APPROVED BY: {{ $audit['approved_by'] }}</div>@endif
        @if(!empty($audit['requested_at']))<div>REQUESTED: {{ $time($audit['requested_at']) }}</div>@endif
        @if(!empty($audit['approved_at']))<div>APPROVED: {{ $time($audit['approved_at']) }}</div>@endif
    @endif
    @if(!empty($reminder['order_note']))<div class="rule"></div><div>ORDER NOTE:</div><div>{{ $reminder['order_note'] }}</div>@endif
    @if(!empty($layout->footer_text))<div class="rule"></div><div class="center">{{ $layout->footer_text }}</div>@endif
    @if(!empty($layout->show_bingoo_branding))<div class="center">BingooPos</div><div class="center">Bingoopos.com</div>@endif
</main>
<button class="print-btn" type="button" onclick="window.print()">Print Reminder</button>
</body>
</html>
