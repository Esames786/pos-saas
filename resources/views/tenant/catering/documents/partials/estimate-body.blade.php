{{-- KASHIF-CATERING-OPERATOR-UI-1: the printable body, extracted verbatim
     so the single document and the bulk composition render from ONE source and
     can never drift apart. Wrapper markup (html/head/CSS shell) stays with the
     including page. --}}
<div class="print-bar">
    <button onclick="window.print()" style="padding: 8px 18px; cursor: pointer;">Print</button>
</div>

{{-- KASHIF-LEGACY-ALIGN-1: the per-line materials box carries its own styles so
     every consumer of this partial (single document, bulk composition, email
     PDF) prints it identically without touching their CSS shells. --}}
<style>
    .line-mats { margin: 2px 0 4px; border-collapse: collapse; font-size: 10px; color: #374151; width: auto; min-width: 62%; }
    .line-mats td { border: 1px solid #d1d5db; padding: 2px 8px; }
    .line-mats .num { text-align: right; white-space: nowrap; }
    .line-mats .mats-head td { background: #f3f4f6; font-weight: bold; }
    .line-mats .provider { white-space: nowrap; }
    /* The detail row belongs to the item above it: no divider between them. */
    .items tr.mats-row td { border-top: 0; padding-top: 0; }
    .compl-tag { font-size: 10px; color: #166534; font-weight: bold; }
</style>

<div class="doc-header">
    <div>
        <div class="brand">{{ $businessName }}</div>
        <div class="brand-sub">{{ $t('Catering & Events', 'کیٹرنگ اینڈ ایونٹس') }}</div>
    </div>
    <div class="doc-title">
        <h2>{{ $estimate->isDraft() ? $t('DRAFT ESTIMATE', 'مسودہ تخمینہ') : $t('ESTIMATE', 'تخمینہ') }}</h2>
        <div><strong>{{ $event->event_no }} / Q{{ $estimate->version_no }}</strong></div>
        {{-- Formatted through TenantClock: timestamps are stored UTC, and a Karachi
     caterer's paper must carry Karachi's date, not one five hours adrift. --}}
<div style="color:#6b7280;">{{ $t('Date', 'تاریخ') }}: {{ app(\App\Support\TenantClock::class)->format($estimate->sent_at ?? $estimate->updated_at, 'd M Y') }}</div>
        @if($estimate->status === \App\Models\Tenant\CateringEstimate::STATUS_SUPERSEDED)
            <div class="doc-state superseded">{{ $t('SUPERSEDED', 'منسوخ شدہ') }}</div>
        @endif
    </div>
</div>

{{-- CAT-DOC-001 — a draft must never be mistaken for the quotation.
     A draft can be printed, handed over and signed while its numbers are still
     moving underneath it: any edit, any rate change, any material override. The
     document said nothing about that, so the only way to know was to look at the
     database. Now the customer's copy says so in its title, in a band across the
     page, and on every printed sheet. --}}
@if($estimate->isDraft())
    <div class="draft-banner">
        <strong>{{ $t('DRAFT — NOT YET ISSUED', 'مسودہ — ابھی جاری نہیں کیا گیا') }}</strong>
        <span>{{ $t(
            'These figures are still being prepared and may change. This is not a confirmed quotation.',
            'یہ اعداد و شمار ابھی تیار ہو رہے ہیں اور تبدیل ہو سکتے ہیں۔ یہ حتمی تخمینہ نہیں ہے۔'
        ) }}</span>
    </div>
@endif

<div class="meta-grid">
    <div class="meta-box">
        <h4>{{ $t('Customer', 'کسٹمر') }}</h4>
        <div style="font-weight:bold; font-size: 15px;">
            @if($isUr && $event->customer_name_ur)
                <span class="ur">{{ $event->customer_name_ur }}</span>
            @else
                {{ $event->customer_name }}
                @if($isBoth && $event->customer_name_ur)
                    <span class="ur" style="font-weight:normal;"> — {{ $event->customer_name_ur }}</span>
                @endif
            @endif
        </div>
        @if($event->customer_phone)<div class="meta-row"><span class="k">{{ $t('Phone', 'فون') }}</span><span dir="ltr">{{ $event->customer_phone }}</span></div>@endif
        @if($event->customer_address)<div class="meta-row"><span class="k">{{ $t('Address', 'پتہ') }}</span><span>{{ $event->customer_address }}</span></div>@endif
    </div>
    <div class="meta-box">
        <h4>{{ $t('Event', 'تقریب') }}</h4>
        @if($event->event_type)<div class="meta-row"><span class="k">{{ $t('Type', 'قسم') }}</span><span>{{ $event->event_type }}</span></div>@endif
        <div class="meta-row"><span class="k">{{ $t('Date', 'تاریخ') }}</span><span>{{ $event->event_date->format('l, d F Y') }}</span></div>
        @if($event->service_time)<div class="meta-row"><span class="k">{{ $t('Time', 'وقت') }}</span><span>{{ \Carbon\Carbon::parse($event->service_time)->format('g:i A') }}</span></div>@endif
        @if($event->venue)<div class="meta-row"><span class="k">{{ $t('Venue', 'مقام') }}</span><span>{{ $event->venue }}</span></div>@endif
        <div class="meta-row"><span class="k">{{ $t('Guests (PAX)', 'مہمان') }}</span><span><strong>{{ number_format($event->pax) }}</strong></span></div>
    </div>
</div>

{{-- KASHIF-LEGACY-ALIGN-5 print order, as the client reads a bill: serial,
     then the ITEM, its material detail directly beneath on its own full-width
     row, and only then the figures. --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: 34px;">#</th>
            <th>{{ $t('Item', 'آئٹم') }}</th>
            <th class="num" style="width: 70px;">{{ $t('Qty', 'مقدار') }}</th>
            <th style="width: 55px;">{{ $t('Unit', 'یونٹ') }}</th>
            <th style="width: 20%;">{{ $t('Instructions', 'ہدایات') }}</th>
            <th class="num" style="width: 80px;">{{ $t('Rate', 'ریٹ') }}</th>
            <th class="num" style="width: 95px;">{{ $t('Amount', 'رقم') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($estimate->lines as $line)
        @php $matBlocks = $line->costBlocks->filter->isMaterial(); @endphp
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td>
                @if($isUr && $line->item_name_ur)
                    <span class="ur">{{ $line->item_name_ur }}</span>
                @else
                    {{ $line->item_name }}
                    @if($isBoth && $line->item_name_ur)
                        <div class="item-ur ur">{{ $line->item_name_ur }}</div>
                    @endif
                @endif
                @if($line->hasQuotedRateOverride() && (float) $line->rate === 0.0)
                    {{-- The legacy Complimentry flag, on the customer's copy. --}}
                    <div class="compl-tag">Complimentary · <span class="ur">اعزازی</span></div>
                @endif
            </td>
            <td class="num">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }}</td>
            <td>{{ $line->unit_code }}</td>
            <td>{{ $line->instructionSummary() }}</td>
            <td class="num">{{ number_format($line->rate, 2) }}</td>
            <td class="num">{{ number_format($line->amount, 2) }}</td>
        </tr>
        {{-- KASHIF-LEGACY-ALIGN-1 ("Is p gosht nh arha"): what material this
             line takes, how much, and WHO supplies it — from the LINE SNAPSHOT
             (the quoted truth), always in both languages. Quantities are the
             line's TOTAL kitchen draw — the same numbers the kitchen release
             sheet works from, so the customer's paper and the kitchen can never
             disagree. Internal cost figures (Costs us) never reach a customer's
             document. --}}
        @if($matBlocks->isNotEmpty())
        <tr class="mats-row">
            <td></td>
            <td colspan="6">
                <table class="line-mats" dir="ltr">
                    <tr class="mats-head">
                        <td>Materials · <span class="ur">سامان</span></td>
                        <td class="num">Qty · <span class="ur">مقدار</span></td>
                        <td class="provider">Provided by · <span class="ur">کون دے گا</span></td>
                    </tr>
                    @foreach($matBlocks as $mb)
                        <tr>
                            <td>{{ $mb->material_name ?: $mb->label }}</td>
                            <td class="num">{{ rtrim(rtrim(number_format((float) ($mb->event_material_qty ?? 0), 3), '0'), '.') }} {{ $mb->unit_code }}</td>
                            <td class="provider">
                                @if($mb->isCustomerSupplied())
                                    Customer provides · <span class="ur">گاہک دے گا</span>
                                @else
                                    We provide · <span class="ur">ہم دیں گے</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
        @endif
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td class="k">{{ $t('Subtotal', 'کل رقم') }}</td><td class="num">{{ number_format($estimate->subtotal, 2) }}</td></tr>
    @if($estimate->service_charge_amount > 0)
        <tr><td class="k">{{ $t('Service Charges', 'سروس چارجز') }}</td><td class="num">{{ number_format($estimate->service_charge_amount, 2) }}</td></tr>
    @endif
    @if($estimate->other_charge_amount > 0)
        <tr><td class="k">{{ $estimate->other_charge_label ?: $t('Other Charges', 'دیگر چارجز') }}</td><td class="num">{{ number_format($estimate->other_charge_amount, 2) }}</td></tr>
    @endif
    @if($estimate->discount_amount > 0)
        <tr><td class="k">{{ $t('Discount', 'رعایت') }}</td><td class="num">-{{ number_format($estimate->discount_amount, 2) }}</td></tr>
    @endif
    @if($estimate->tax_amount > 0)
        <tr><td class="k">{{ $t('Tax', 'ٹیکس') }}</td><td class="num">{{ number_format($estimate->tax_amount, 2) }}</td></tr>
    @endif
    <tr class="grand"><td>{{ $t('Net Total', 'کل واجب الادا') }}</td><td class="num">{{ number_format($estimate->grand_total, 2) }}</td></tr>
    {{-- CAT-DOC-001: every figure below comes from the one settlement authority.
         The refund line is printed whenever money has gone back, because an
         "Advance Received" of 30,000 beside a balance that assumes 20,000 is how
         a customer and a caterer end up holding two different arithmetics. --}}
    @if($position['gross_received'] > 0)
        <tr><td class="k">{{ $t('Advance Received', 'ایڈوانس وصول شدہ') }}</td><td class="num">{{ number_format($position['gross_received'], 2) }}</td></tr>
        @if($position['refunded'] > 0)
            <tr><td class="k">{{ $t('Refunded', 'واپس کیا گیا') }}</td><td class="num">-{{ number_format($position['refunded'], 2) }}</td></tr>
            <tr><td class="k">{{ $t('Net Received', 'خالص وصول شدہ') }}</td><td class="num">{{ number_format($position['net_received'], 2) }}</td></tr>
        @endif
        @if($position['customer_credit'] > 0)
            <tr><td style="font-weight:bold;">{{ $t('Refundable to Customer', 'گاہک کو واپس کرنا ہے') }}</td><td class="num" style="font-weight:bold;">{{ number_format($position['customer_credit'], 2) }}</td></tr>
        @else
            <tr><td style="font-weight:bold;">{{ $t('Balance', 'بقایا') }}</td><td class="num" style="font-weight:bold;">{{ number_format($position['balance_due'], 2) }}</td></tr>
        @endif
    @endif
</table>

@if($estimate->terms)
    <div class="terms"><strong>{{ $t('Terms & Notes', 'شرائط و ضوابط') }}:</strong>
{{ $estimate->terms }}</div>
@endif

<div class="footer">
    <div class="sig">{{ $t('Prepared By', 'تیار کردہ') }}</div>
    <div class="sig">{{ $t('Customer Approval', 'کسٹمر کی منظوری') }}</div>
</div>
