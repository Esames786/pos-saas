{{--
    CATERING-SEND-TO-PRINTER-1 — "Send to network", THEEK WAHIN jahan Print hai.

    Malik: "jaise abhi preview HTML ka khulta hai, wahan send to network ka
    option de dena — jaise manual option diya hua hai." Aur saath: "old manual
    print aur send to network dono work karain."

    Pehli koshish me maine ye control booking ki screen par rakha tha. Wo ghalat
    jagah thi: operator kaam YAHIN karta hai — preview kholta hai aur Print
    dabata hai. Jo control us ke haath ke paas na ho, wo mojood hone ke baraabar
    nahi.

    ── CHAAR BAATEIN JO IS PARCHE KI SHAKAL BANATI HAIN ───────────────────────

    1. YAHAN KAGAZ KA KHAANA HAI HI NAHI, aur yehi poore kaam ka maqsad hai.
       Malik ki shikayat thi: "bar bar A4 / A5 select karna parta hai." Size
       document khud jaanta hai aur job ke saath jata hai.

    2. KOI BOOTSTRAP NAHI. Ye safhe app ke layout se BAHAR hain — apna CSS, apni
       koi JS nahi. Yahan `class="btn btn-primary"` likhna ek neela text bana
       deta aur `dropdown` likhne par kuch khulta hi nahi. Is liye poora CSS
       neeche khud likha hua hai.

    3. CSS `@can` SE BAHAR HAI, aur ye farq ahem hai. Agar style bhi shart ke
       andar hota to jis tenant ke paas printer ya ijazat na ho, us ka PURANA
       Print button bhi be-shakal ho jata — ek nayi cheez purani ko tor deti.

    4. PRINTER NA HO TO BUTTON HI NAHI — us ki jagah ek satar jo batati hai ke
       karna kya hai. Ek control jo dabane par hamesha error de, screen ka jhoot
       hai.

    Parameters: kind (quotation|kitchen_sheet|address_sheet), ids, printers
--}}
<style>
    /* Teenon preview safhon ka toolbar. `doc-btn` purane Print button par bhi
       lagta hai, is liye ye CSS har haal me nikalti hai — dekho upar (3). */
    .doc-toolbar-row { display: inline-flex; align-items: stretch; gap: 8px; vertical-align: middle; }
    .doc-btn, .doc-select {
        font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 1.2;
        padding: 8px 16px; border: 1px solid #d1d5db; border-radius: 5px;
        background: #fff; color: #111827; cursor: pointer;
    }
    .doc-select { padding: 8px 10px; max-width: 220px; }
    .doc-btn:hover, .doc-select:hover { border-color: #9ca3af; }
    .doc-btn-primary { background: #111827; border-color: #111827; color: #fff; }
    .doc-btn-primary:hover { background: #374151; border-color: #374151; }
    .doc-paper {
        display: inline-flex; align-items: center; font-family: Arial, Helvetica, sans-serif;
        font-size: 12px; font-weight: 700; letter-spacing: .04em; padding: 0 12px;
        border: 1px solid #d1d5db; border-radius: 5px; background: #f3f4f6; color: #374151;
    }
    .doc-note { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #6b7280; }
    .doc-flash {
        font-family: Arial, Helvetica, sans-serif; font-size: 13px; border-radius: 5px;
        padding: 8px 12px; margin: 10px auto 0; max-width: 210mm;
    }
    .doc-flash-ok  { color: #065f46; background: #d1fae5; border: 1px solid #6ee7b7; }
    .doc-flash-bad { color: #991b1b; background: #fee2e2; border: 1px solid #fca5a5; }
    @media print { .doc-toolbar-row, .doc-flash, .doc-note { display: none !important; } }
</style>

{{-- IDS NA HON TO YAHAN SE AAGE KUCH NAHI.

     Ye sirf ehtiyat nahi, ek asli kharabi ka ilaj hai. Yehi document
     `CateringDocumentQueueService` bhi render karta hai — agent ko bhejne ke
     liye HTML jama karte waqt — aur wahan `ids` hoti hi nahi. Us surat me
     neeche ka hissa chalta to chhapne wale kaghaz par "No A4/A5 printer yet"
     likha nikal aata, aur `@error` ko `$errors` chahiye jo request ke bahar
     mojood hi nahi hota (yehi 500 test ne pakra).

     CSS upar hai, is shart se BAHAR: wo purane Print button par bhi lagti hai. --}}
@isset($ids)
@php
    $stnPrinters = collect($printers ?? []);
    $stnIds = collect($ids)->filter()->values();
    $stnPaper = \App\Services\Catering\CateringDocumentQueueService::paperLabel($kind);
@endphp

@can('tenant.catering.documents.bulk-print')
    @if($stnPrinters->isNotEmpty() && $stnIds->isNotEmpty())
        <form method="POST" action="{{ url('/catering/documents/bulk/print') }}" class="doc-toolbar-row">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            @foreach($stnIds as $id)
                <input type="hidden" name="ids[]" value="{{ $id }}">
            @endforeach

            {{-- KAGHAZ KA SIZE YAHAN LIKHA HAI, aur ye jaan bujh kar hai.

                 Malik (10 Oct): "dono printer A4 bhi chhap sakte hain aur A5
                 bhi. Kitchen sheet hamesha A5, baqi sab A4." Qaida pehle se
                 THEEK chal raha tha — size document se aata hai, printer se
                 nahi — magar screen par kahin likha nahi tha. Operator ko
                 printer ke naam par jana parta tha ("… (A4)"), aur wo naam
                 jhoot bolta hai: usi printer par kitchen sheet bhejo to A5 hi
                 nikalti hai.

                 Ye label SERVICE se aata hai, yahan dobara nahi likha ja raha.
                 Do jagah likhne par ek din dono alag ho jate aur screen jhoot
                 bolne lagti. --}}
            <span class="doc-paper" title="Kaghaz ka size document se tay hota hai, printer se nahi">
                {{ $stnPaper }}
            </span>
            <select name="printer_id" class="doc-select" required>
                {{-- JIS KAGAZ KA DOCUMENT HAI, USI NAAP KA PRINTER PEHLE SE CHUNA HO.

                     Malik (10 Oct): "kitchen sheet par A4 likha hai magar printer A5
                     aa raha hai — ye confusing lag raha hai." Theek shikayat thi, aur
                     screen WAQAI apne aap ko jhutla rahi thi: chip "A4" kehti aur us
                     ke saath "Office - HP M127fn (A5)" chuna hua nazar aata.

                     Wajah mamooli thi: fehrist sirf NAAM ke hisaab se tarteeb me hai,
                     is liye "M127fn" hamesha "P2055dn" se pehle aa jata aur browser
                     pehla option khud chun leta — document se us ka koi taaluq hi nahi
                     tha.

                     `paper_size` yahan sirf PEHLA CHUNAO tay karta hai, rokta kisi ko
                     nahi: dono printer fehrist me rehte hain aur dono dono naap chhap
                     sakte hain. Isi liye duplicate rows banane ki zaroorat nahi pari —
                     wo har cheez do guni kar deti (do health check, do jagah naam
                     theek karna) aur masla phir bhi screen ka hi rehta.

                     Kisi printer ka naap na mile to koi `selected` nahi lagta aur
                     browser pehla chun leta hai — yani purana rawaiyya, jo kabhi
                     galat jawab nahi deta kyunke kagaz document se jata hai. --}}
                @foreach($stnPrinters as $p)
                    <option value="{{ $p->id }}"
                        @selected(strtoupper((string) ($p->paper_size ?? '')) === $stnPaper)>{{ $p->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="doc-btn doc-btn-primary">Send to network</button>
        </form>
    @elseif($stnPrinters->isEmpty())
        <span class="doc-note">
            No A4/A5 printer yet — add one under Printing › Printers (Type = Windows).
        </span>
    @endif
@endcan

{{-- Natija usi safhe par, kyunke ye safhe naye tab me khulte hain aur un ka
     koi "wapas" nahi hota. --}}
@if(session('status'))
    <div class="doc-flash doc-flash-ok">{{ session('status') }}</div>
@endif

{{-- `$errors` web middleware deta hai; is safhe ko kahin aur se render karne par
     wo mojood nahi hota. `@error` seedha likhne se wahan 500 aata tha. --}}
@if(isset($errors) && $errors->has('print'))
    <div class="doc-flash doc-flash-bad">{{ $errors->first('print') }}</div>
@endif
@endisset
