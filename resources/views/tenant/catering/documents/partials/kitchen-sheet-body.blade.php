{{-- KASHIF-CATERING-OPERATOR-UI-1: the printable body, extracted verbatim
     so the single document and the bulk composition render from ONE source and
     can never drift apart. Wrapper markup (html/head/CSS shell) stays with the
     including page. --}}
<div class="print-bar">
    <button onclick="window.print()" style="padding: 8px 18px; cursor: pointer;">Print</button>
</div>

<div class="doc-meta">
    <span>{{ $businessName }} — {{ $t('KITCHEN / SERVICE SHEET', 'کچن شیٹ') }}</span>
    <span>{{ $release->release_no }} · {{ $release->released_at->format('d M Y g:i A') }}</span>
</div>

<div class="head">
    <div>
        <div class="label">{{ $t('Customer / Event', 'کسٹمر / تقریب') }}</div>
        <div class="customer">
            @if($isUr && !empty($snapshot['customer_name_ur']))
                <span class="ur">{{ $snapshot['customer_name_ur'] }}</span>
            @else
                {{ $snapshot['customer_name'] ?? '' }}
                @if($isBoth && !empty($snapshot['customer_name_ur']))
                    <span class="ur" style="font-size:22px;"> — {{ $snapshot['customer_name_ur'] }}</span>
                @endif
            @endif
        </div>
        <div class="badge-row">
            <span><span class="label">{{ $t('Event #', 'تقریب نمبر') }}</span> <strong>{{ $snapshot['event_no'] ?? '' }}</strong></span>
            @if(!empty($snapshot['event_type']))<span><strong>{{ $snapshot['event_type'] }}</strong></span>@endif
            @if(!empty($snapshot['customer_phone']))<span dir="ltr">{{ $snapshot['customer_phone'] }}</span>@endif
        </div>
    </div>
    <div>
        <div class="label">{{ $t('Date & Time', 'تاریخ و وقت') }}</div>
        <div class="big">{{ \Carbon\Carbon::parse($snapshot['event_date'])->format('D, d M Y') }}</div>
        @if(!empty($snapshot['service_time']))
            <div class="big">{{ \Carbon\Carbon::parse($snapshot['service_time'])->format('g:i A') }}</div>
        @endif
    </div>
    <div>
        <div class="label">{{ $t('Venue', 'مقام') }}</div>
        <div class="big">{{ $snapshot['venue'] ?? '—' }}</div>
        <div class="label" style="margin-top: 6px;">{{ $t('Guests (PAX)', 'مہمان') }}</div>
        <div class="big">{{ number_format($snapshot['pax'] ?? 0) }}</div>
    </div>
</div>

{{-- KITCHEN-SHEET-NO-STATIONS-1: ONE table, the lines in their own order.
     This used to group by production_station and head each group with a black
     bar. The owner asked for the bars to go, and the grouping goes with them —
     several tables each repeating the same header for no stated reason reads
     worse than the bars did. The station is still recorded on the line; it is
     simply not this sheet's business.

     CATERING-COURSE-ORDER-1 (21 Sep) course ki tarteeb wapas laata hai, magar
     us shikayat ko dohraye baghair jo upar likhi hai. Wo aitraaz GROUPING par
     nahi tha — wo KAALI PATTI par tha aur un ALAG TABLES par jo har baar wohi
     header dobara chhapti thin. Is liye:
       • table ab bhi EK hi hai, header ek hi baar,
       • course ka unwaan usi tbody ke andar ek halki patti hai, kaali nahi,
       • aur us patti par `break-after: avoid` hai taake unwaan safhe ke
         aakhir me akela na reh jaye jab ke us ka khana agle safhe par ho.
     Tarteeb `categories.sort_order` se aati hai — wohi class jo customer ki
     quotation chalati hai, is liye dono kaghaz hamesha ek jaise hain. --}}
{{-- DO baatein yahan sikhi gayin, dono ne is file ko chupke se toda:

     1. Is Laravel par PHP ki INLINE shakl — yani parentheses wali — toota hua
        PHP deti hai. Wo `<?php` khol kar expression ko literal chhod deti hai
        aur band karne wala tag likhti hi nahi, jis se neeche ke tamam loop
        saada text ban jate hain. Isi liye yahan hamesha block shakl
        (open/close) hai. Generated PHP lint kiya gaya — 450 views, 0 kharab.

     2. Blade statements ko comments se PEHLE compile karta hai, is liye comment
        ke ANDAR likha hua directive bhi asli directive ki tarah chal jata hai.
        Sirf tashreeh ke liye ek directive ka naam likhne se hi ye file compile
        hona band ho gayi thi. Is liye upar ki saari baatein lafzon me hain,
        misaal ki shakl me nahi. --}}
{{-- KITCHEN-SHEET-NO-COURSE-HEADINGS-1 (26 Sep) — TARTEEB rahi, UNWAAN gaye.

     18 Sep ko course ke unwaan is dalil par daale gaye the ke bawarchi
     course-dar-course pakata hai. Malik ne 26 Sep ko wo dalil rad kar di:
     "category hata do, need nahi, aur space aa jayega" — do khanon wale sheet
     par do pattiyan aa rahi thin, aur jagah wahi cheez kha rahi thi jis ki
     shikayat thi.

     TARTEEB PAR KOI ASAR NAHI. Khane ab bhi usi silsile me chhapte hain jo
     client ne 21 Sep ko maanga tha (starter → biryani → gravy → BBQ → …), is
     liye groups() ki jagah sort() — wohi helper, wohi tarteeb, sirf sar-naame
     nahi. Estimate ka kaghaz pehle se yehi karta hai. --}}
@php
    $orderedLines = \App\Support\Catering\CourseOrder::sort($release->lines);
@endphp
    <table class="items">
        <thead>
            <tr>
                <th class="sr" style="width: 30px;">#</th>
                <th class="num" style="width: 104px;">{{ $t('Qty', 'مقدار') }}</th>
                <th>{{ $t('Item', 'آئٹم') }}</th>
                <th style="width: 34%;">{{ $t('Instructions', 'ہدایات') }}</th>
                <th style="width: 58px;">{{ $t('Done', 'مکمل') }}</th>
            </tr>
        </thead>
        <tbody>
            @php $sr = 0; @endphp
            @foreach($orderedLines as $line)
            <tr>
                <td class="sr">{{ ++$sr }}</td>
                <td class="num"><span class="qty">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }} {{ $line->unit_code }}</span></td>
                <td>
                    @if($isUr && $line->item_name_ur)
                        <div class="item-ur ur">{{ $line->item_name_ur }}</div>
                    @else
                        <div class="item-name">{{ $line->item_name }}</div>
                        @if($isBoth && $line->item_name_ur)
                            <div class="item-ur ur">{{ $line->item_name_ur }}</div>
                        @endif
                    @endif

                    {{-- KASHIF-KITCHEN-MATERIALS-1: what this dish takes and who
                         brings it, in the same words the customer's quotation
                         uses. Read from the release SNAPSHOT — releases frozen
                         before this existed carry nothing and print as before. --}}
                    @include('tenant.catering.documents.partials.line-materials', [
                        'materials' => $line->materials_snapshot ?? [],
                    ])
                </td>
                <td class="instructions">{{ $line->instructions }}</td>
                <td style="text-align:center; font-size: 18px;">☐</td>
            </tr>
            @endforeach
        </tbody>
    </table>

{{-- KITCHEN-SHEET-REQUIREMENTS-TOGGLE-1 (26 Sep) — bawarchi is table ko nahi
     parhta, wo sirf jagah leti hai. Ab ye Catering Settings ka switch hai aur
     BAND haalat me aata hai; jise planning ka hisaab chahiye wo khol le.

     Setting se parha ja raha hai, view ko diye gaye kisi variable se nahi: ye
     partial teen jagah se render hoti hai (tanha document, bulk composition,
     aur network print), aur teenon ko alag alag yaad rakhna hi wo tareeqa hai
     jis se ek jagah switch kaam karna chhor deta hai. --}}
@if(!empty($requirements) && \App\Models\Tenant\CateringSetting::tenantDefault()->show_kitchen_requirements)
<div class="req">
    <h3>{{ $t('Consolidated Raw Material Requirements (planning)', 'مجموعی خام مال کی ضروریات') }}</h3>
    <table class="req-table">
        <thead>
            {{-- CAT-PROD-002 — the KITCHEN's number, not the store's.
                 This printed only what our store issues, so a material the
                 customer is bringing appeared as 0 and effectively vanished from
                 the sheet. The kitchen still has to cook with it: the dish needs
                 eight kilos of rice whoever carries it through the door. Both
                 numbers are printed, and where they differ the sheet says why. --}}
            <tr>
                <th>{{ $t('Material', 'خام مال') }}</th>
                <th class="num">{{ $t('Kitchen Needs', 'باورچی خانہ کو درکار') }}</th>
                <th class="num">{{ $t('From Our Store', 'ہمارے اسٹور سے') }}</th>
                <th>{{ $t('Unit', 'یونٹ') }}</th>
                <th>{{ $t('Used By', 'استعمال') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($requirements as $req)
            @php
                // Releases frozen before this distinction existed carry only the
                // one figure; for those the two answers really were the same.
                $physical = (float) ($req['physical_qty'] ?? $req['required_qty']);
                $ours = (float) $req['required_qty'];
                $supplied = (float) ($req['customer_supplied_qty'] ?? max($physical - $ours, 0));
                $fmt = fn ($n) => rtrim(rtrim(number_format($n, 3), '0'), '.');
            @endphp
            <tr>
                <td>
                    {{ $req['name'] }}
                    @if($supplied > 0)
                        <div style="font-size:10px; color:#7c2d12;">
                            {{ $t('Customer supplied', 'گاہک فراہم کرے گا') }} — {{ $fmt($supplied) }} {{ $req['unit_code'] }}
                        </div>
                    @endif
                </td>
                <td class="num"><strong>{{ $fmt($physical) }}</strong></td>
                <td class="num">
                    {{ $fmt($ours) }}
                    @if($ours <= 0 && $physical > 0)
                        <div style="font-size:10px; color:#7c2d12;">{{ $t('none', 'کچھ نہیں') }}</div>
                    @endif
                </td>
                <td>{{ $req['unit_code'] }}</td>
                <td>{{ implode(', ', $req['used_by'] ?? []) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
