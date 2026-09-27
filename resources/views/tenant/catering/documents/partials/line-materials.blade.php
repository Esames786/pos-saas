{{-- KASHIF-KITCHEN-MATERIALS-1 — "what this line takes and who brings it", as
     one quiet line UNDER the item rather than a box competing with it.

     ONE partial, deliberately: the customer's quotation and the kitchen sheet
     print the same sentence about the same line, and the only way they can
     never disagree is for both to render it from here. The figures arrive
     already computed (CateringEstimateLine::materialSummary, snapshotted onto
     the release line at release time) — this file only chooses the words, in
     the document's own language. Internal cost never appears.

     Expects: $materials (array of rows), $t (the document's translator). --}}
{{-- KITCHEN-SHEET-A5-1 (27 Sep) — ek partial, DO shaklein.

     Malik: "instruction mai sirf instruction ayen (material ya cost block na
     ae) — bas aa jaye Party aur us ki qty, aur agar own to Own aur us ki qty."

     Bawarchi-khane ko maal ka NAAM nahi chahiye — wo dish ke naam se jaanta
     hai ke us me kya parta hai. Usay sirf ye chahiye: maal PARTY laa rahi hai
     ya hum, aur kitna. Customer ki quotation par naam zaroori rehta hai,
     kyunke wahan wo hisaab ka hissa hai.

     Alag partial jaan-boojh kar NAHI banaya: lafzon ka faisla ek hi jagah
     rehna chahiye, warna kal ek kaghaz "Party" kahega aur doosra "گاہک".
     Yahan sirf ek $compact jhanda hai, aur dono shaklein saath saath parhi
     ja sakti hain. --}}
@php
    $compact = $compact ?? false;
    $fmtQty = fn ($q) => rtrim(rtrim(number_format((float) $q, 3), '0'), '.');
    // KITCHEN-SHEET-RTL-MEASURE-1 — naap ko ULTA CHHAPNE se bachao.
    //
    // Urdu kaghaz `dir="rtl"` hai. "84 KG" us me DO tukre hain — ek adad
    // ka, ek harf ka — aur RTL unhe ulta laga kar "KG 84" bana deta hai.
    // Yehi kharabi waqt par bhi thi.
    //
    // Do ilaj hain, aur yahan DONO istemaal hote hain — wajah ke saath:
    //   • kitchen sheet (compact) par naap apne `dir="ltr"` span me jati
    //     hai. Markup zyada saaf hai aur test us par kaat sakta hai.
    //   • quotation par naap ek JURE HUE jumle ke beech me hai
    //     ("chawal 30 KG (hum)"), jahan span daalne ke liye poora jumla
    //     tukron me torna parta. Wahan Unicode ke isolate nishan — LRI aur
    //     PDI — wohi kaam bina markup ke karte hain. Ye be-chaurai hain,
    //     chhapte nahi.
    $ltr = fn (string $s) => "⁦".$s."⁩";
    $matLine = collect($materials ?? [])->map(function ($m) use ($fmtQty, $t, $compact, $ltr) {
        $qty = $fmtQty($m['qty'] ?? 0).' '.($m['unit_code'] ?? '');
        $name = trim((string) ($m['name'] ?? ''));
        $supply = $m['supply'] ?? 'ours';
        $unit = $m['unit_code'] ?? '';

        if ($compact) {
            // Sifar kuch nahi kehta. "Own 0 KG" parche par shor hai, khabar
            // nahi — aur A5 par har satar ek khane ki jagah khaati hai.
            if (round((float) ($m['qty'] ?? 0), 3) <= 0) {
                return null;
            }

            // Har hissa apni SATAR par — malik: "part ya own upar neeche
            // dikha do". Ek dish jis ka kuch maal party laati hai aur kuch
            // hum, us par do satrein aati hain.
            if ($supply === 'customer') {
                return [[$t('Party', 'پارٹی'), $qty]];
            }
            if ($supply === 'split') {
                $parts = [];
                if (round((float) ($m['customer'] ?? 0), 3) > 0) {
                    $parts[] = [$t('Party', 'پارٹی'), $fmtQty($m['customer']).' '.$unit];
                }
                if (round((float) ($m['ours'] ?? 0), 3) > 0) {
                    $parts[] = [$t('Own', 'اپنا'), $fmtQty($m['ours']).' '.$unit];
                }

                return $parts ?: null;
            }

            return [[$t('Own', 'اپنا'), $qty]];
        }

        if ($supply === 'customer') {
            return $name.' '.$ltr($qty).' ('.$t('PAR', 'گاہک').')';
        }

        if ($supply === 'split') {
            return $name.' '.$ltr($qty).' ('.$t('CAT', 'ہم').' '.$ltr($fmtQty($m['ours'] ?? 0))
                .', '.$t('PAR', 'گاہک').' '.$ltr($fmtQty($m['customer'] ?? 0)).')';
        }

        return $name.' '.$ltr($qty).' ('.$t('CAT', 'ہم').')';
    })->filter();
@endphp
@if($compact)
    {{-- Har hissa apni satar par: "Party 30 KG" ke neeche "Own 12 KG". --}}
    @foreach($matLine->flatten(1) as $part)
        <div class="supply-line">{{ $part[0] }} <span dir="ltr">{{ $part[1] }}</span></div>
    @endforeach
@else
    @php($joined = $matLine->implode(' · '))
    @if($joined !== '')
        <div class="line-mats-inline">{{ $joined }}</div>
    @endif
@endif
