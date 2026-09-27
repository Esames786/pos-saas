{{-- KASHIF-CATERING-OPERATOR-UI-1: the document stylesheet, shared verbatim
     between the single document and the bulk composition. --}}
<style>
    /* KITCHEN-SHEET-A5-1 (27 Sep) — parcha ab A5 CHAURA hai, A4 nahi.

       Malik ne purane software ka parcha bheja: "half horizontal size of
       vertical A4 page" — yani A4 ko beech se aarzi kaat kar. Wohi 210 × 148mm.
       Fayda sirf kaghaz ka nahi: bawarchi-khane me chhota parcha deewar par
       lagta hai aur haath me rehta hai, jab ke A4 jhoolta rehta hai.

       Margin 8mm — A5 par har millimeter khane ki ek satar hai, aur 8mm aam
       printer ke na-chhapne wale kinare (≈5mm) se ooper hai.

       Lamba parcha ab bhi kai safhon par jata hai; sirf safha chhota hua hai. */
    {{-- KITCHEN-SHEET-PAPER-SETTING-1 (27 Sep): size ab Catering Settings se
         aata hai, yahan thoosa hua nahi. Default wohi A5 chaura hai jo malik
         ne maanga; jis tenant ko A4 chahiye wo ek dropdown se badal le.
         Sheet ki screen-naap bhi usi setting se banti hai, warna preview aur
         print alag alag cheez dikhate. --}}
    @php
        $kPaper = \App\Models\Tenant\CateringSetting::tenantDefault()->kitchen_sheet_paper;
        $kMm = \App\Models\Tenant\CateringSetting::paperMm($kPaper, 'a5_portrait');
    @endphp
    @page { size: {{ \App\Models\Tenant\CateringSetting::cssPageSize($kPaper, 'A5 portrait') }}; margin: 8mm; }
    * { box-sizing: border-box; }
    /* @page margin applies to PAPER only — on screen the sheet is drawn at real
       size on a grey desk so the preview matches the printed output. Undone
       inside @media print so margins are never doubled. */
    html { background: #e5e7eb; }
    body {
        font-family: {{ $isUr ? "'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif" : "Arial, Helvetica, sans-serif" }};
        color: #111827; font-size: 11px; line-height: 1.25;
        width: {{ $kMm["w"] }}mm; min-height: {{ $kMm["h"] }}mm; margin: 12px auto; padding: 8mm;
        background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.18);
    }
    @media screen and (max-width: 230mm) {
        body { width: 100%; min-height: 0; margin: 0; padding: 6mm; box-shadow: none; }
    }
    @media print {
        html { background: #fff; }
        body { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    }
    /* Nastaliq descenders clip at the body's Latin leading — and the kitchen
       reads this sheet at arm's length, so give it extra room. */
    /* KITCHEN-SHEET-A5-1 (doosra daur) — URDU BARA.
       Malik: "urdu font abhi bhi bahut chhota hai, bara karo." Nastaliq ek hi
       point size par Latin se chhoti nazar aati hai (uska x-height kam hai),
       is liye usay 1.45x diya gaya hai — aur descender ke liye line-height
       bhi. Ye parcha bawarchi haath ki doori se nahi, kaam karte hue parhta
       hai. */
    .ur { font-family: 'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif;
          direction: rtl; font-size: 1.75em; line-height: 1.7; }

    /* Sar: booking no (baayen) · GRAAHAK (beech, bara) · tareekh (dayen).
       Purane software ki tarteeb. Sirf teen cheezein — baqi sab paer me. */
    .head { border-bottom: 2px solid #111827; padding: 0 2px 3px;
            display: flex; align-items: baseline; gap: 10px; }
    .head .h-l { flex: 0 0 auto; text-align: {{ $isUr ? 'right' : 'left' }}; }
    .head .h-c { flex: 1 1 auto; text-align: center; }
    .head .h-r { flex: 0 0 auto; text-align: {{ $isUr ? 'left' : 'right' }}; }
    .head .bno { font-size: 11px; font-weight: bold; }
    .head .etype { font-size: 8px; text-transform: uppercase; color: #6b7280; letter-spacing: .7px; display: inline; margin-{{ $isUr ? "right" : "left" }}: 6px; }
    .head .customer { font-size: 15px; font-weight: bold; line-height: 1.1; display: inline; }
    /* KITCHEN-SHEET-A5-1 (chautha daur) — malik: phone aur pata NAAM jitne
       bade aur bold. Driver aur bawarchi ye do cheezein doori se parhte hain:
       kis ko phone karna hai, aur khana kahan jaana hai. */
    .head .phone { font-size: 14px; font-weight: bold; color: #111827; display: inline; margin-{{ $isUr ? "right" : "left" }}: 10px; }
    .head .edate { font-size: 12px; font-weight: bold; display: inline; }

    /* Paer: SERVICE + waqt baayen, venue/pata dayen — purane parche ki tarah.
       `ftl` label apni value se CHIPKE nahi: pehli koshish me "12:00 PMSERVE"
       chhap raha tha, kyunke label inline tha aur beech me koi jagah nahi
       thi. Ab label apni satar par hai. */
    .foot { display: flex; justify-content: space-between; align-items: baseline;
            gap: 12px; margin-top: 4px; padding-top: 3px; border-top: 2px solid #111827; }
    .foot-l { display: flex; align-items: baseline; gap: 12px; }
    .foot-r { text-align: {{ $isUr ? 'left' : 'right' }}; }
    /* Waqt kabhi na toote: "8:00" upar aur "PM" neeche chala jata tha, jis se
       parche par do adhoore hisse nazar aate the. */
    .ft { display: inline-block; font-size: 13px; font-weight: bold; white-space: nowrap; }
    .ftl { font-size: 8px; font-weight: normal; text-transform: uppercase;
           color: #6b7280; letter-spacing: .8px; margin-{{ $isUr ? "left" : "right" }}: 4px; }
    .foot .venue { font-size: 12px; font-weight: bold; display: inline; }
    .foot .addr { font-size: 13px; font-weight: bold; color: #111827; display: inline; margin-{{ $isUr ? "right" : "left" }}: 10px; }
    .doc-meta { display: flex; justify-content: space-between; margin: 4px 2px; color: #6b7280; font-size: 11px; }
    table.items { width: 100%; border-collapse: collapse; }
    table.items th { text-align: {{ $isUr ? 'right' : 'left' }}; border-bottom: 2px solid #111827; padding: 3px 6px; font-size: 9px; text-transform: uppercase; color: #374151; }
    table.items th.num, table.items td.num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    table.items td { padding: 2px 6px; border-bottom: 1px solid #d1d5db; vertical-align: top; }
    table.items th.sr, table.items td.sr { text-align: center; color: #6b7280; font-size: 10px; }
    /* KITCHEN-SHEET-A5-1: bawarchi sab se pehle DISH ka naam parhta hai — malik
       ne bheji tasveer par usi par teer laga kar "bold" likha tha. A5 par font
       chhota hua hai, is liye naam ka wazan aur barhaya gaya hai, kam nahi. */
    /* KITCHEN-SHEET-A5-1 — SERVICE ka lafz. Sirf tab chhapta hai jab service
       charges li gayi hon, aur RAQAM kabhi nahi: is parche par paisa nahi
       aata. Border se banaya gaya, background se nahi — print par background
       gir jate hain. */
    .svc { border: 1.5px solid #111827; border-radius: 3px; padding: 1px 6px;
           font-size: 11px; font-weight: bold; letter-spacing: .08em; align-self: center; }
    /* KITCHEN-SHEET-A5-1 (teesra daur) — "Done" ke khaane ki jagah MAAL.
       Party upar, Own neeche, har ek apni satar par. Bold: bawarchi ke liye
       ye faisla-kun khabar hai — ye cheez us ke store se nahi aayegi. */
    table.items th.supply, table.items td.supply { text-align: {{ $isUr ? 'left' : 'right' }}; }
    .supply-line { font-size: 11px; font-weight: bold; white-space: nowrap; line-height: 1.2; }
    /* Tick box apne khaane me. 12px — 18px par ye AKELA hi har row ko lamba
       kar deta tha, kyunke row ki bulandi us ke sab se lambe khane se banti
       hai, aur us se safhe par do khane kam ho jate the. */
    table.items th.done, table.items td.done { text-align: center; font-size: 12px; }
    /* KITCHEN-SHEET-A5-1 (doosra daur) — EK SAFHE PAR 14 SATREIN.
       Purane parche par 14 aati hain; pehli koshish me sirf 10 aa rahi thin
       kyunke maal ki satar har khane ke NEECHE lagi hui thi aur sar bhari tha.
       Ab maal ka apna khaana hai (row ek hi satar ki rahi), sar halka hua,
       aur row ki lambai bhi kas di gayi. */
    table.items td { padding: 1.5px 6px; }
    .item-name { font-size: 12.5px; font-weight: 800; }
    .item-ur { font-size: 18px; }
    .qty { font-size: 13px; font-weight: bold; white-space: nowrap; }
    .instructions { color: #374151; }
    /* KASHIF-KITCHEN-MATERIALS-1: the material line sits UNDER the dish and
       stays quieter than it — the dish name is what the cook reads first. */
    .line-mats-inline { font-size: 10px; color: #4b5563; margin-top: 0; line-height: 1.25; }
    .req { margin-top: 12px; page-break-inside: avoid; }
    .req h3 { border-bottom: 2px solid #111827; padding-bottom: 4px; font-size: 14px; }
    table.req-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    table.req-table th { text-align: {{ $isUr ? 'right' : 'left' }}; padding: 5px 8px; background: #f3f4f6; }
    table.req-table th.num, table.req-table td.num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    table.req-table td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
    /* KITCHEN-SHEET-PREVIEW-1 — release se pehle chhapa hua parcha khud kehta
       hai ke wo kya hai. Dashed border aur weight se banaya gaya, background se
       NAHI: browser print par background rang gira dete hain, aur tab ye band
       ghayab ho kar parcha bilkul asli jaisa chhap jata. */
    .preview-band {
        border: 1.5px dashed #b45309; color: #7c2d12;
        padding: 2px 8px; margin: 3px 0; border-radius: 3px; font-size: 10px;
    }
    .preview-band strong { font-size: 11px; letter-spacing: .06em; margin-{{ $isUr ? "left" : "right" }}: 8px; }
    @media print { .preview-band { border-color: #000; color: #000; } }
    /* Sits in the grey gutter beside the sheet; it used to overlap the header. */
    .print-bar { position: fixed; top: 10px; {{ $isUr ? 'left' : 'right' }}: 10px; z-index: 10; }
    @media print { .print-bar { display: none; } }
    /* Rows must not split mid-dish across a page break, and the header repeats
       on every continuation page. */
    table.items thead, table.req-table thead { display: table-header-group; }
    table.items tr, table.req-table tr, .head { break-inside: avoid; page-break-inside: avoid; }
</style>