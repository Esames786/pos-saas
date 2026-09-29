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
    html { background: #e5e7eb; height: 100%; }
    /* KITCHEN-SHEET-FOOT-BOTTOM-1 (27 Sep) — malik: "jo footer hai wo hamesha
       bottom mai hi aae, irrespective ek item ho ya 14-15."

       Pehle paer table ke foran baad chipka hua tha, is liye 8 khanon wale
       parche par wo safhe ke beech me latak jata tha. Ab body ek KHARA flex
       hai aur paer par `margin-top: auto` — bachi hui saari jagah paer ke
       UPAR chali jati hai, chahe khane 1 hon ya 14.

       Isi liye print me `min-height` ab 0 nahi balke 100% hai: 0 par body
       sirf apne matn jitni oonchi hoti aur "neeche" ka koi matlab hi na
       rehta. Iske liye html ko bhi height chahiye, warna 100% kis cheez ka
       100% — us ka jawab hi na hota. */
    body {
        font-family: {{ $isUr ? "'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif" : "Arial, Helvetica, sans-serif" }};
        color: #111827; font-size: 11px; line-height: 1.25;
        width: {{ $kMm["w"] }}mm; min-height: {{ $kMm["h"] }}mm; margin: 12px auto; padding: 8mm;
        background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.18);
        display: flex; flex-direction: column;
    }
    @media screen and (max-width: 230mm) {
        body { width: 100%; min-height: 0; margin: 0; padding: 6mm; box-shadow: none; }
    }
    @media print {
        html { background: #fff; height: 100%; }
        body { width: auto; min-height: 100%; margin: 0; padding: 0; box-shadow: none; }
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
    /* `margin-top: auto` — dekho upar body ka flex wala note. Yehi ek lafz
       paer ko safhe ki tal par le jata hai. */
    .foot { display: flex; justify-content: space-between; align-items: baseline;
            gap: 12px; margin-top: auto; padding-top: 3px; border-top: 2px solid #111827; }
    .foot-l { display: flex; align-items: baseline; gap: 12px; }
    .foot-r { text-align: {{ $isUr ? 'left' : 'right' }}; }
    /* Waqt kabhi na toote: "8:00" upar aur "PM" neeche chala jata tha, jis se
       parche par do adhoore hisse nazar aate the. */
    /* Waqt ka qad SERVICE ke barabar — purane parche par dono ek jitne hain.
       Waqt ka chhota label jaan-boojh kar chhota rehta hai: wo sirf batata
       hai ke ye kaun sa waqt hai, parha waqt jata hai.

       ⚠ IN COMMENTS ME WO LAFZ NA LIKHNA JO PARCHE PAR CHHAPTE HAIN. Ye
       stylesheet parche ke andar jati hai, is liye yahan likha hua har lafz
       document ke matn ka hissa ban jata hai. Abhi isi jagah rawangi wale
       label ka naam aur ek namoona waqt likh diya gaya tha, aur wo test
       foran red ho gaya jo kehta hai "jo waqt darj nahi us ka label parche
       par na ho" — label kahin nahi tha, sirf is comment me tha.

       WAQT PAR DO ALAG KHARABIYAN THIN, aur inhe alag rakhna zaroori hai:

       1. TOOT-NA: ghanta upar aur din ka hissa neeche chala jata tha. Ilaj
          yahan ka `white-space: nowrap` hai.
       2. ULTA CHHAPNA: dono hisse ulti tarteeb me. Ye nowrap se theek NAHI
          hota — ye bidi hai. Document `dir="rtl"` hai; ghante wala hissa
          ADAD ka tukra hai aur din wala HARF ka, aur RTL do alag tukron ko
          ulta laga deta hai. Ilaj CSS ka nahi, MARKUP ka hai: waqt apne
          `dir="ltr"` wale span me hai (wohi jo phone par pehle se tha).

       27 Sep ko jab malik ne waqt theek karne ko kaha to sirf (1) dekha gaya
       aur nowrap laga kar ise band samajh liya gaya — (2) live par chhapta
       raha aur malik ko dobara tasveer bhejni pari. Naya waqt kahin aur
       lagate waqt: wo span lazmi hai. */
    .ft { display: inline-block; font-size: 16px; font-weight: bold; white-space: nowrap; }
    .ftl { font-size: 10px; font-weight: normal; text-transform: uppercase;
           color: #6b7280; letter-spacing: .8px; margin-{{ $isUr ? "left" : "right" }}: 4px; }
    .foot .venue { font-size: 12px; font-weight: bold; display: inline; }
    .foot .addr { font-size: 13px; font-weight: bold; color: #111827; display: inline; margin-{{ $isUr ? "right" : "left" }}: 10px; }
    .doc-meta { display: flex; justify-content: space-between; margin: 4px 2px; color: #6b7280; font-size: 11px; }
    table.items { width: 100%; border-collapse: collapse; }
    table.items th { text-align: {{ $isUr ? 'right' : 'left' }}; border-bottom: 2px solid #111827; padding: 3px 6px; font-size: 9px; text-transform: uppercase; color: #374151; }
    table.items th.num, table.items td.num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    table.items td { padding: 2px 6px; border-bottom: 1px solid #d1d5db; vertical-align: top; }
    /* KITCHEN-SHEET-FILL-1 (27 Sep) — GINTI ab halki nahi.
       Malik ne apni tasveer par 1 aur 2 ke neeche laal lakeer khainch kar
       likha: "serial aur service ka text bold aur big karo". Ye adad
       bawarchi-khane me PUKARA jata hai ("number saat wala nikal do"), is
       liye grey 10px se kaala 13px bold. Sar-naame wala # halka hi rehta
       hai — wo pukara nahi jata. */
    table.items th.sr { text-align: center; color: #6b7280; }
    table.items td.sr { text-align: center; font-size: 13px; font-weight: bold; color: #111827; }
    /* KITCHEN-SHEET-FILL-1 — KHARI LAKEEREN. Malik ne hamare chhape hue
       parche par teen khari lakeerein HAATH SE khainch kar bheji hain, aur
       purane software ke parche par ye pehle se hain: har khaana poori
       bulandi tak alag. Khali khaana (jahan hidayat ya maal nahi) tab bhi
       ek khaana dikhta hai — abhi wo saath wale me ghul jata tha.
       border-collapse pehle se hai, is liye milti hui lakeerein ek hi
       bantī hain, dohri nahi. */
    table.items th, table.items td { border-left: 1px solid #9ca3af; border-right: 1px solid #9ca3af; }
    /* KITCHEN-SHEET-A5-1: bawarchi sab se pehle DISH ka naam parhta hai — malik
       ne bheji tasveer par usi par teer laga kar "bold" likha tha. A5 par font
       chhota hua hai, is liye naam ka wazan aur barhaya gaya hai, kam nahi. */
    /* KITCHEN-SHEET-A5-1 — SERVICE ka lafz. Sirf tab chhapta hai jab service
       charges li gayi hon, aur RAQAM kabhi nahi: is parche par paisa nahi
       aata. Border se banaya gaya, background se nahi — print par background
       gir jate hain. */
    /* KITCHEN-SHEET-FILL-1 (27 Sep) — SERVICE bara. Doosri laal lakeer isi
       par thi. Purane parche par ye paer ka sab se numaya lafz hai aur
       waqt us ke saath ek dabbe me bara chhapta hai; hamara 11px ka tha
       aur baqi paer me gum ho jata tha. */
    .svc { border: 2px solid #111827; border-radius: 3px; padding: 2px 8px;
           font-size: 15px; font-weight: bold; letter-spacing: .08em; align-self: center; }
    /* KITCHEN-SHEET-A5-1 (teesra daur) — "Done" ke khaane ki jagah MAAL.
       Party upar, Own neeche, har ek apni satar par. Bold: bawarchi ke liye
       ye faisla-kun khabar hai — ye cheez us ke store se nahi aayegi. */
    table.items th.supply, table.items td.supply { text-align: {{ $isUr ? 'left' : 'right' }}; }
    /* KITCHEN-SHEET-SUPPLY-TAG-1 (29 Sep) — PARTY / OWN ek gehre dabbe me,
       purane software ki tarah. Malik ne usi dabbe ki tasveer bheji thi.

       Label Latin me hai, Urdu parche par bhi — malik ki misaal bhi Latin me
       thi ("[party 18 KG]" agli satar "[OWN 10 KG]") aur purane software par
       bhi ye lafz Latin hain.

       ⚠ BROWSER PRINT PAR BACKGROUND GIRTE HAIN. Isi file me do jagah
       (SERVICE ka dabba aur preview ki patti) jaan-boojh kar BORDER se banayi
       gayi hain aur wahan likha hai ke background par bharosa na karo. Yahan
       gehra dabba maanga gaya hai, aur safed harf par background gir jane ka
       matlab hota: label BILKUL GHAYAB — safed par safed. Ye sab se buri
       soorat hai, kyunke kuch ghalat nazar nahi aata, bas khabar chali jati
       hai.

       Ilaj `print-color-adjust: exact` hai, aur ye NAAPA GAYA hai — maan
       kar nahi chhora gaya. Parcha Chrome se PDF banaya aur us ke andar
       fill rang dhoonda: `.0667 .0941 .1529 rg` (yani #111827) NAU baar
       mojood mila, aur safed harf bhi. Client Chrome hi se chhapta hai.

       Agar kabhi ye property nikal gayi to label safed par safed reh kar
       GHAYAB ho jayega — bina kisi nishani ke. Isi liye us par test hai.
       (Yahan koi CSS fallback mumkin nahi: CSS ye jaan hi nahi sakta ke
       background gira ya nahi.) */
    /* Dabbe par `dir="ltr"` MARKUP me hai, aur wo lazmi hai: poora dabba
       Urdu safhe ki RTL rau me baith kar "43.5 KG OWN" parhne lagta tha —
       label peeche. Malik ne "[PARTY 18 KG]" maanga tha, label pehle.
       Ye WOHI bidi kharabi hai jo waqt aur miqdaar par pakri ja chuki hai;
       ab chauthi jagah. Jahan bhi RTL safhe par Latin aur adad saath likhe
       jayen, unhe apna LTR khaana chahiye. */
    .sup-tag {
        display: inline-block; background: #111827; color: #fff;
        font-size: 11px; font-weight: bold; letter-spacing: .04em;
        padding: 1px 5px; border-radius: 2px; border: 1px solid #111827;
        white-space: nowrap;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .supply-line { font-size: 11px; font-weight: bold; white-space: nowrap; line-height: 1.45; }
    /* Tick box apne khaane me. 12px — 18px par ye AKELA hi har row ko lamba
       kar deta tha, kyunke row ki bulandi us ke sab se lambe khane se banti
       hai, aur us se safhe par do khane kam ho jate the. */
    table.items th.done, table.items td.done { text-align: center; font-size: 12px; }
    /* KITCHEN-SHEET-A5-1 (doosra daur) — EK SAFHE PAR 14 SATREIN.
       Purane parche par 14 aati hain; pehli koshish me sirf 10 aa rahi thin
       kyunke maal ki satar har khane ke NEECHE lagi hui thi aur sar bhari tha.
       Ab maal ka apna khaana hai (row ek hi satar ki rahi), sar halka hua,
       aur row ki lambai bhi kas di gayi. */
    /* KITCHEN-SHEET-FILL-1 (27 Sep) — SAFHA BHARO. Ye upar wale hadaf ka
       ULTA rukh hai, aur jaan-boojh kar.

       26 Sep ki shikayat thi "saat khane poora safha kha gaye" — us par sab
       kas diya gaya aur gunjaish 18–19 satron tak pahunch gayi. Ab 27 Sep ko
       malik ne hamara chhapa hua parcha bhej kar us ke upar aur neeche
       "EMPTY SPACE" likh diya: 9 khanon wali booking par aadha safha khali
       ja raha tha, jab ke purane software ka parcha bhara hua aata hai.

       Dono baatein ek hi adad par milti hain: PURANE SOFTWARE PAR 14. Us se
       kam ho to kaghaz zaya, zyada ho to harf chhote. Chrome (wohi engine
       jis se client chhapta hai) se naapa gaya — dompdf se nahi, wo is
       parche ka raasta hai hi nahi.

       Naap: gunjaish 18 thi, qatar ~8.9mm. 14 ke liye ~11.5mm chahiye, yani
       ~29% zyada — jo padding se nahi, HARF SE diya gaya hai: bawarchi ise
       chulhe se, kaam karte hue parhta hai, is liye bari jagah bare harf me
       jani chahiye, khali hashiye me nahi. */
    table.items td { padding: 3px 6px; }
    .item-name { font-size: 14px; font-weight: 800; }
    /* KITCHEN-SHEET-FOOT-BOTTOM-1 (27 Sep) — malik: "urdu items ka font
       mazeed increase karo." 21 -> 24px.

       ⚠ QEEMAT SAAF RAKHNA: qatar ki bulandi ISI khane se banti hai, is liye
       har barhotri safhe par khane ghataati hai. Chrome se naapa gaya: 24px
       par ek A5 safhe par (29 Sep ko malik ne 21px par wapas laaya) khane aate hain. Jitna bara harf, utne kam khane —
       khanon wali booking ab doosre safhe par jayegi. Malik ne bara font
       maanga hai aur wo ye
       adla-badli jaante hue maange — magar agli baar jab koi "do safhe kyun"
       poochhe, jawab yahan likha hai. */
    .item-ur { font-size: 21px; }
    .qty { font-size: 14px; font-weight: bold; white-space: nowrap; }
    .instructions { color: #374151; font-size: 12px; }
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