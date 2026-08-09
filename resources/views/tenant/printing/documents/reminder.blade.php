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
        .heading { font-size: 1.25em; font-weight: 800; }
        .line { margin: 6px 0; font-weight: 700; overflow-wrap: anywhere; }
        .child, .modifier, .note { margin-left: 12px; font-weight: 400; }
        .print-btn { display: block; margin: 12px auto; padding: 8px 16px; }
        @media print { body { background: #fff; padding: 0; } .ticket { width: {{ $width }}; padding: 0; } .print-btn { display: none; } }
    </style>
</head>
<body>
<main class="ticket">
    @if(!empty($layout->header_text))<div class="center">{{ $layout->header_text }}</div>@endif
    <div class="heading center">REMINDER</div>
    <div class="heading center">{{ $reminder['heading'] ?? 'REMINDER' }}</div>
    @unless($isCorrection)<div class="center">REVISION {{ $revision }}</div>@endunless
    @if(!empty($reminder['is_reprint']))<div class="center"><strong>DUPLICATE {{ max((int) ($reminder['copy_no'] ?? 1), 1) }}</strong></div>@endif
    @if($layout->show_order_no ?? true)<div class="center">{{ $reminder['sale_no'] ?? '' }}</div>@endif
    <div class="rule"></div>
    @if(($layout->show_table_info ?? true) && !empty($reminder['table']))<div>TABLE: {{ $reminder['table'] }}</div>@endif
    @if(($layout->show_table_info ?? true) && !empty($reminder['waiter']))<div>WAITER: {{ $reminder['waiter'] }}</div>@endif
    @if(($layout->show_cashier_name ?? true) && !empty($reminder['cashier']))<div>CASHIER: {{ $reminder['cashier'] }}</div>@endif
    @if(($layout->show_customer_name ?? false) && !empty($reminder['customer']))<div>CUSTOMER: {{ $reminder['customer'] }}</div>@endif
    <div>TYPE: {{ strtoupper(str_replace('_', ' ', $reminder['order_type'] ?? 'sale')) }}</div>
    @if(!empty($reminder['vehicle']))<div>VEHICLE: {{ $reminder['vehicle'] }}</div>@endif
    @if(($layout->show_order_time ?? true) && !empty($reminder['order_time']))<div>ORDER: {{ $time($reminder['order_time']) }}</div>@endif
    @if(($layout->show_updated_time ?? true) && !empty($reminder['updated_time']))<div>UPDATED: {{ $time($reminder['updated_time']) }}</div>@endif
    @if(($layout->show_print_time ?? true) && !empty($reminder['generated_at']))<div>PRINT: {{ $time($reminder['generated_at']) }}</div>@endif
    <div class="rule"></div>

    @if(!empty($reminder['cancelled_lines']))
        <strong>CANCELLED:</strong>
        @foreach($reminder['cancelled_lines'] as $line)
            <div class="line">{{ strtoupper($line['product_name'] ?? 'Item') }} x{{ $qty($line['quantity'] ?? 0) }} {{ $line['unit_code'] ?? '' }}</div>
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
        <div class="line">{{ $newLine ? '(R) ' : '' }}{{ strtoupper($line['product_name'] ?? 'Item') }} x{{ $qty($quantity) }} {{ $line['unit_code'] ?? '' }}{{ $increase ? ' (R +' . $qty($delta) . ')' : '' }}</div>
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
</main>
<button class="print-btn" type="button" onclick="window.print()">Print Reminder</button>
</body>
</html>
