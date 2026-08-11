@php
    $layout    = $layout ?? null;
    $branch    = $session->branch;
    $fontSize  = $layout?->font_size ?? 12;
    $paperSize = $layout?->paper_size ?? '80mm';
    $width     = match ($paperSize) { '58mm' => '52mm', '80mm' => '72mm', default => '180mm' };
    $heldSales = $session->salesOrders->where('status', 'held');
    $paidSales = $session->salesOrders->where('status', 'paid');
    $heldTotal = (float) $heldSales->sum('grand_total');
    $paidTotal = (float) $paidSales->sum('grand_total');
    $fmtQty    = fn ($q) => rtrim(rtrim(number_format((float) $q, 3, '.', ''), '0'), '.');
@endphp

{{-- Receipt-style bill preview so the on-screen preview AND its browser print match the
     configured Receipt layout (font / paper width / header / footer / show-flags). The
     styles are scoped to .tbill-receipt and travel with the markup, so the print output
     (which reuses this innerHTML) looks the same as the preview. --}}
<style>
    .tbill-receipt { font-family: 'Courier New', Courier, monospace; font-size: {{ $fontSize }}px; width: {{ $width }}; max-width: 100%; margin: 0 auto; color: #000; padding: 4px; }
    .tbill-receipt .center { text-align: center; }
    .tbill-receipt .bold   { font-weight: bold; }
    .tbill-receipt hr      { border: none; border-top: 1px dashed #000; margin: 4px 0; }
    .tbill-receipt table   { width: 100%; border-collapse: collapse; }
    .tbill-receipt td      { vertical-align: top; padding: 1px 0; border: none !important; }
    .tbill-receipt .r      { text-align: right; white-space: nowrap; }
    @media screen { .tbill-receipt { width: 320px; } }
</style>

<div class="tbill-receipt" data-session-id="{{ $session->id }}">
    @if(!($layout?->show_logo === false) && $layout?->logo_path)
        <div class="center" style="margin-bottom:4px"><img src="{{ asset('storage/' . $layout->logo_path) }}" style="max-width:80px;max-height:40px"></div>
    @endif
    @if(!($layout?->show_branch_name === false))
        <div class="center bold">{{ $branch?->name }}</div>
    @endif
    @if(!($layout?->show_branch_address === false) && $branch?->address)
        <div class="center">{{ $branch->address }}</div>
    @endif
    @if(!($layout?->show_branch_phone === false) && $branch?->phone)
        <div class="center">Tel: {{ $branch->phone }}</div>
    @endif
    @if($layout?->header_text)
        <div class="center" style="margin-top:4px">{{ $layout->header_text }}</div>
    @endif

    <hr>
    <div class="center bold">TABLE BILL — NOT A TAX RECEIPT</div>
    <hr>

    @if(!($layout?->show_table_info === false))
        <div>Table: <span class="bold">{{ $session->table?->table_no }}</span> &nbsp; {{ ucfirst(str_replace('_', ' ', $session->status)) }}</div>
        <div>Check: {{ $session->session_no }}</div>
        <div>Waiter: {{ $session->waiter?->name ?? '-' }} &nbsp; Guests: {{ $session->guest_count }}</div>
    @endif
    <div>{{ app(\App\Support\TenantClock::class)->now()->format('d/m/Y H:i') }}</div>
    <hr>

    @forelse($heldSales as $sale)
        <div class="bold">{{ $sale->sale_no }}</div>
        <table>
            @foreach($sale->lines as $line)
                @if(($line->line_kind ?? 'standard') === 'component') @continue @endif
                <tr>
                    <td>{{ $line->product_name }}@if($line->variant_name) ({{ $line->variant_name }})@endif</td>
                    <td class="r">{{ $fmtQty($line->quantity) }}</td>
                    <td class="r">{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
                @foreach(($line->modifiers ?? []) as $modifier)
                    @if(!empty($modifier['name']))
                        <tr><td colspan="3" style="padding-left:8px">+ {{ $modifier['name'] }}</td></tr>
                    @endif
                @endforeach
            @endforeach
            <tr><td class="r" colspan="2">Subtotal</td><td class="r">{{ number_format((float) $sale->subtotal, 2) }}</td></tr>
            @if((float) $sale->discount_amount > 0)
                <tr><td class="r" colspan="2">Discount</td><td class="r">-{{ number_format((float) $sale->discount_amount, 2) }}</td></tr>
            @endif
            @if((float) $sale->tax_amount > 0)
                <tr><td class="r" colspan="2">Tax</td><td class="r">{{ number_format((float) $sale->tax_amount, 2) }}</td></tr>
            @endif
            @if((float) $sale->service_charge_amount > 0)
                <tr><td class="r" colspan="2">Service Charge</td><td class="r">{{ number_format((float) $sale->service_charge_amount, 2) }}</td></tr>
            @endif
            @if((float) $sale->delivery_charge_amount > 0)
                <tr><td class="r" colspan="2">Delivery Charge</td><td class="r">{{ number_format((float) $sale->delivery_charge_amount, 2) }}</td></tr>
            @endif
            @if((float) $sale->tip_amount > 0)
                <tr><td class="r" colspan="2">Tip</td><td class="r">{{ number_format((float) $sale->tip_amount, 2) }}</td></tr>
            @endif
            <tr><td class="r bold" colspan="2">Order total</td><td class="r bold">{{ number_format((float) $sale->grand_total, 2) }}</td></tr>
        </table>
        <hr>
    @empty
        <div class="center">No held orders for this table.</div>
        <hr>
    @endforelse

    <table>
        <tr><td class="r bold">OPEN CHECK</td><td class="r bold">{{ number_format($heldTotal, 2) }}</td></tr>
        @if($paidTotal > 0)
            <tr><td class="r">Previously paid</td><td class="r">{{ number_format($paidTotal, 2) }}</td></tr>
        @endif
    </table>

    @if($paidSales->isNotEmpty())
        <hr>
        <div class="bold">Paid history ({{ $paidSales->count() }})</div>
        <table>
            @foreach($paidSales as $sale)
                <tr>
                    <td>{{ $sale->sale_no }}</td>
                    <td class="r">{{ app(\App\Support\TenantClock::class)->formatSale($sale, 'd/m H:i') }}</td>
                    <td class="r">{{ number_format((float) $sale->grand_total, 2) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if($layout?->footer_text)
        <hr>
        <div class="center">{{ $layout->footer_text }}</div>
    @endif
</div>
