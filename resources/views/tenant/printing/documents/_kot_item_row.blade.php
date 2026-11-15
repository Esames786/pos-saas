{{-- One KOT item row, exactly as kot.blade.php has always drawn it. Shared by the plain loop and the
     KOT-DEAL-HEADING-1 grouped loop, so a plain item looks the same either way. --}}
@php
    $totalQty = (float) $line->quantity;

    if ($isReprint) {
        $displayQty = $totalQty;
        $isAddition = false;
    } elseif (isset($lineQuantities) && $lineQuantities->has((string) ($line->line_id ?? $line->id ?? ''))) {
        // Use exact quantity stored in payload at job creation time.
        $displayQty = (float) $lineQuantities->get((string) ($line->line_id ?? $line->id));
        $isAddition = $totalQty > $displayQty;  // more was ordered than this delta
    } else {
        // Fallback for old jobs without line_quantities in payload.
        $sentQty    = (float) ($line->kot_sent_quantity ?? 0);
        $displayQty = max($totalQty - $sentQty, 0);
        $isAddition = $sentQty > 0;
    }
@endphp
<tr>
    <td class="item-qty ibig">
        @if($isAddition)
            <span style="font-size:{{ $fontSize - 1 }}px">ADD</span><br>
        @endif
        {{ rtrim(rtrim(number_format($displayQty, 3, '.', ''), '0'), '.') }}
        @if($isAddition)
            <br><small style="font-weight:normal">(T:{{ rtrim(rtrim(number_format($totalQty, 3, '.', ''), '0'), '.') }})</small>
        @endif
    </td>
    <td class="item-name">
        <span class="ibig">{{ ($eventType ?? null) === 'addition' ? '(R) ' : '' }}{{ strtoupper($line->product_name) }}</span>
        {{-- COMBO-KOT-DEAL-NAME-1: a KOT is split by category and drops the combo header, so
             the station would otherwise see loose items with no idea they are one platter.
             Same sub-line the ESC/POS ticket prints, so paper and preview stay identical. --}}
        @if(!empty($comboNames[(int) ($line->combo_id ?? 0)] ?? null))
            <br><small style="font-size:{{ max($fontSize - 3, 9) }}px; font-weight:normal">({{ $comboNames[(int) $line->combo_id] }})</small>
        @endif
        @if($line->variant_name && strcasecmp(trim($line->variant_name), trim((string) $line->product_name)) !== 0)
            <br><small>{{ $line->variant_name }}</small>
        @endif
        @foreach(($line->modifiers ?? []) as $modifier)
            @if(!empty($modifier['name']))
                <br><small>+ {{ $modifier['name'] }}</small>
            @endif
        @endforeach
        @if($line->kitchen_note)
            <br><small>⚑ {{ $line->kitchen_note }}</small>
        @endif
    </td>
</tr>
