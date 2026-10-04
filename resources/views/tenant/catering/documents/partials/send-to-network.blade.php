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
@endphp

@can('tenant.catering.documents.bulk-print')
    @if($stnPrinters->isNotEmpty() && $stnIds->isNotEmpty())
        <form method="POST" action="{{ url('/catering/documents/bulk/print') }}" class="doc-toolbar-row">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            @foreach($stnIds as $id)
                <input type="hidden" name="ids[]" value="{{ $id }}">
            @endforeach

            <select name="printer_id" class="doc-select" required>
                @foreach($stnPrinters as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
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
