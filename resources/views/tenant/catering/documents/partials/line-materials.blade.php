{{-- KASHIF-KITCHEN-MATERIALS-1 — "what this line takes and who brings it", as
     one quiet line UNDER the item rather than a box competing with it.

     ONE partial, deliberately: the customer's quotation and the kitchen sheet
     print the same sentence about the same line, and the only way they can
     never disagree is for both to render it from here. The figures arrive
     already computed (CateringEstimateLine::materialSummary, snapshotted onto
     the release line at release time) — this file only chooses the words, in
     the document's own language. Internal cost never appears.

     Expects: $materials (array of rows), $t (the document's translator). --}}
@php
    $fmtQty = fn ($q) => rtrim(rtrim(number_format((float) $q, 3), '0'), '.');
    $matLine = collect($materials ?? [])->map(function ($m) use ($fmtQty, $t) {
        $qty = $fmtQty($m['qty'] ?? 0).' '.($m['unit_code'] ?? '');
        $name = trim((string) ($m['name'] ?? ''));

        if (($m['supply'] ?? 'ours') === 'customer') {
            return $name.' '.$qty.' ('.$t('customer', 'گاہک').')';
        }

        if (($m['supply'] ?? 'ours') === 'split') {
            return $name.' '.$qty.' ('.$t('us', 'ہم').' '.$fmtQty($m['ours'] ?? 0)
                .', '.$t('customer', 'گاہک').' '.$fmtQty($m['customer'] ?? 0).')';
        }

        return $name.' '.$qty.' ('.$t('us', 'ہم').')';
    })->filter()->implode(' · ');
@endphp
@if($matLine !== '')
    <div class="line-mats-inline">{{ $matLine }}</div>
@endif
