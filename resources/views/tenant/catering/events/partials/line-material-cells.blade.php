{{-- STACKED-MATERIAL-ROW-1 (step 4) — the five breakdown cells of ONE material
     of a SAVED line: Material · Rate · Required Qty · Own · Party.

     ONE partial, deliberately. The first material sits in the line's own row
     and every further one gets a row of its own beneath it, and the only way
     those can never disagree is for both to render from here.

     It reads the LINE SNAPSHOT, never the product master — the quotation
     explains itself from what was quoted. The three quantities are the same
     three the Cost Details panel edits, asked as the operator asks them:

       Required   physicalRequirement()  what the kitchen must receive
       Own        billableQty()          what we send, and therefore charge
       Party      suppliedQty()          what the customer brings

     Required is deliberately NOT a field here. It is Own + Party by
     definition, and a box over a definition only invites the two to drift.

     Expects: $block (nullable snapshot), $partyAllowed (the ITEM's flag). --}}
@php
    $fmtMatQty = fn ($q) => rtrim(rtrim(number_format((float) $q, 3, '.', ''), '0'), '.') ?: '0';
@endphp
@if($block)
    <td class="fs-13">
        {{ $block->material_name ?: $block->label }}
        @if($block->unit_code)<div class="fs-12 text-muted">{{ $block->unit_code }}</div>@endif
    </td>
    <td class="text-end fs-13">{{ number_format((float) $block->rate, 2) }}</td>
    <td class="text-end fs-13">{{ $fmtMatQty($block->physicalRequirement()) }}</td>
    <td class="text-end fs-13">{{ $fmtMatQty($block->billableQty()) }}</td>
    {{-- A split already agreed on an older booking still shows even after the
         item's Party flag is turned off — grandfathered, exactly as the Cost
         Details panel treats it. Hiding it would make the row lie about what
         the customer was told. --}}
    @if($partyAllowed || $block->suppliedQty() > 0)
        <td class="text-end fs-13">{{ $fmtMatQty($block->suppliedQty()) }}</td>
    @else
        <td class="text-end fs-13 text-muted" title="Party supply is off for this item">—</td>
    @endif
@else
    <td class="fs-13 text-muted">—</td>
    <td></td>
    <td></td>
    <td></td>
    <td></td>
@endif
