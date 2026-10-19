{{--
    "Printed <waqt>" — EK jagah, chaaron parchon ke liye.

    Malik (11 Oct): "ye printed time tamam quotation, invoice, address, proforma
    sab pe aana chahiye." Pehle ye sirf kitchen sheet par tha, aur address sheet
    par us ki apni nakal thi — jo kaghaz par aati hi nahi thi, kyunke wo toolbar
    ke andar hai aur toolbar print par chhup jata hai.

    Char jagah char baar likhne par ek din wo chaaron alag ho jate: kisi par
    waqt, kisi par sirf tareekh, kisi par purani ghari. Is liye ek hi jagah.

    ── DO BAATEIN JO IS SATAR KI SHAKAL BANATI HAIN ──────────────────────────

    1. `TenantClock`, `now()` nahi. Server UTC par chalta hai aur Karachi us se
       paanch ghante aage hai — bina is ke raat ka parcha pichhle din ka waqt
       dikhata.

    2. `dir="ltr"` LAZMI. Is satar me Urdu lafz aur Latin tareekh saath hain,
       aur RTL safhe par wo ulti tarteeb me lag kar "Oct 2026 6:55 PM 02 · چھپا"
       ban jati hai. Ye wohi bidi kharabi hai jo waqt, miqdaar, maal aur PARTY
       ke dabbe par pehle pakri ja chuki hai.

    Parameters: paPrefix (ikhtiyari — jaise release number)
--}}
@php
    // `$t` har parche par nahi hota (address sheet ek-zabaan hai), is liye
    // us ke baghair bhi chalna chahiye.
    $paLabel = (isset($t) && is_callable($t)) ? $t('Printed', 'چھپا') : 'Printed';
    $paWhen = app(\App\Support\TenantClock::class)->now()->format('d M Y g:i A');
@endphp
<span dir="ltr">{{ $paPrefix ?? '' }}{{ $paLabel }} {{ $paWhen }}</span>
