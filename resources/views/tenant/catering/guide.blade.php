@extends('layouts.app')

@section('title', 'Catering Guide')

@php
    /**
     * KASHIF-UAT-2 — the in-app Catering manual.
     *
     * Every impact claim on this page is stated from the code, not from
     * intention: advances and final invoices DO post to the general ledger
     * (CateringAdvanceService / CateringFinalInvoiceService), material issues
     * ARE the only stock mutation (InventoryService::postOutFefo), and
     * everything in the quoting phase touches nothing at all. If that ever
     * changes, this page changes with it — a manual that lies is worse than
     * no manual.
     */
    $isUr = ($lang ?? 'en') === 'ur';

    // stage, English, Urdu, finance, stock, print, email
    $flow = [
        ['1', 'Create Event', 'تقریب بنائیں',
            'Customer, date, venue, PAX. This is the booking itself.',
            'none', 'none', 'no', 'no'],
        ['2', 'Build the Estimate', 'تخمینہ تیار کریں',
            'Add dishes and quantities. A block-costed dish shows its Calculated Rate and Quoted Rate apart, with the full breakdown under Cost Details — agreed rates, customer-supplied materials and per-event quantities are all set right there. Edit as much as you like — it is a draft, and nothing reloads.',
            'none', 'none', 'no', 'no'],
        ['3', 'Recalculate Cost', 'لاگت دوبارہ نکالیں',
            'Works out your internal material cost from each recipe and the Rate Book. Customer price is untouched.',
            'none', 'none', 'no', 'no'],
        ['4', 'Finalize Quotation', 'کوٹیشن حتمی کریں',
            'Validates the costing and freezes the quotation so the customer copy can never change underneath them. Printing or previewing never finalizes — only this button does. Changes afterwards need a revision.',
            'none', 'none', 'yes', 'yes'],
        ['5', 'Customer Accepted', 'گاہک نے قبول کیا',
            'Records that the customer agreed. The booking CONFIRMS ITSELF at the same moment, so the calendar and every screen agree without a second click. If the costing is not finished the acceptance still stands and the booking waits at Quoted — recording a yes never depends on the kitchen\x27s arithmetic. Nothing posts.',
            'none', 'none', 'no', 'no'],
        ['6', 'Confirm Booking', 'بکنگ کی تصدیق',
            'Usually already done for you by step 5. Press it when the booking did not confirm automatically — finish the costing first. Pressing it on an already-confirmed booking does nothing and is not an error. A draft quotation can never be confirmed.',
            'none', 'none', 'no', 'yes'],
        ['7', 'Record Advance', 'پیشگی رقم درج کریں',
            'Money received before the event.',
            'posts', 'none', 'no', 'yes'],
        ['8', 'Release Production', 'پیداوار جاری کریں',
            'Freezes the dish list into a kitchen sheet — with each line\x27s selected kitchen instructions — and sends it to the kitchen printers.',
            'none', 'none', 'network', 'no'],
        ['9', 'Issue Materials', 'خام مال جاری کریں',
            'Take the raw materials out of store for cooking. Customer-supplied materials stay visible to the kitchen but draw nothing from your store.',
            'posts', 'moves', 'no', 'no'],
        ['10', 'Final Invoice', 'حتمی بل',
            'The real bill. Advances already received are applied against it.',
            'posts', 'none', 'yes', 'yes'],
        ['11', 'Close Event', 'تقریب بند کریں',
            'Locks the booking. Blocked while any balance is outstanding.',
            'none', 'none', 'no', 'no'],
    ];

    $manage = [
        ['A dish you sell (Chicken Karahi, Welcome Drink)', 'Catalog › Products', '/products'],
        ['A raw material you buy (mutton, rice, oil)', 'Catering › Materials', '/catering/materials'],
        ['What a dish is made of', 'Kitchen Inventory › Recipes', '/recipes'],
        ['The rate you COST a material at when quoting', 'Catering › Material Cost Rates', '/catering/material-rates'],
        ['The rate you CHARGE a material at (customer side)', 'Catering › Commercial Charge Rates', '/catering/commercial-rates'],
        ['Which open quotes a rate change affects', 'Catering › Rate Impact', '/catering/rate-impact'],
        ['Bulk-change the Making charge across dishes and drafts', 'Catering › Making Adjustment', '/catering/making-adjustment'],
        ['The kitchen instruction vocabulary (Mirch Kam, …)', 'Catering › Kitchen Instructions', '/catering/instructions'],
        ['Issue materials for one or many bookings', 'Catering › Store Issue', '/catering/store-issues'],
        ['Print several quotations / kitchen sheets / an address list at once', 'Catering › Events — tick bookings, then the print buttons', '/catering/events'],
        ['Serving size, station, kitchen instructions per dish', 'Catering › Catering Products', '/catering/profiles'],
        ['Which kitchen printer prints which category', 'Catering › Catering Printers', '/catering/printer-mappings'],
        ['Reminder timing and print language', 'Catering › Catering Settings', '/catering/settings'],
        ['The physical printers and the job queue', 'Printing › Printers / Print Jobs', '/printing/printers'],
        ['Customers and how they pay', 'Operations › Customers / Payment Methods', '/customers'],
    ];
@endphp

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $isUr ? 'کیٹرنگ گائیڈ' : 'Catering Guide' }}</h1>
        <p class="fw-medium mb-0">
            {{ $isUr
                ? 'پورا کیٹرنگ نظام — کون سا بٹن کیا کرتا ہے اور ہر چیز کہاں سے تبدیل ہوتی ہے۔'
                : 'The whole Catering module — what each button does, and where every setting lives.' }}
        </p>
    </div>
    <div class="d-flex gap-2">
        <div class="btn-group">
            <a href="{{ url('/catering/guide') }}" class="btn btn-outline-secondary {{ $isUr ? '' : 'active' }}">English</a>
            <a href="{{ url('/catering/guide?lang=ur') }}" class="btn btn-outline-secondary {{ $isUr ? 'active' : '' }}">اردو</a>
        </div>
        <button onclick="window.print()" class="btn btn-light d-print-none">
            <i class="ti ti-printer me-1"></i>{{ $isUr ? 'پرنٹ' : 'Print' }}
        </button>
    </div>
</div>

{{-- ── The one thing worth understanding first ──────────────────────── --}}
<div class="card mb-3 border-primary">
    <div class="card-body">
        <h5 class="mb-2"><i class="ti ti-bulb text-primary me-1"></i>{{ $isUr ? 'سب سے اہم بات' : 'The one rule to remember' }}</h5>
        @if($isUr)
            <p class="mb-0">
                جب تک تخمینہ <strong>ڈرافٹ</strong> ہے، آپ جو مرضی تبدیل کریں — نہ کوئی رقم کھاتے میں جاتی ہے،
                نہ اسٹاک ہلتا ہے، نہ POS پر کوئی اثر پڑتا ہے۔ صرف <strong>تین</strong> کام پیسے یا اسٹاک کو ہاتھ لگاتے ہیں:
                <strong>پیشگی رقم</strong>، <strong>خام مال کا اجرا</strong>، اور <strong>حتمی بل</strong>۔
            </p>
        @else
            <p class="mb-0">
                While the estimate is a <strong>draft</strong>, change anything you like — no money is posted,
                no stock moves, and POS is untouched. Only <strong>three</strong> actions in the whole module
                touch money or stock: <strong>Record Advance</strong>, <strong>Issue Materials</strong>,
                and <strong>Final Invoice</strong>.
            </p>
        @endif
    </div>
</div>

{{-- ── The flow ──────────────────────────────────────────────────────── --}}
<div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">{{ $isUr ? 'مکمل ترتیب' : 'The complete flow' }}</h5></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:2.5rem">#</th>
                    <th>{{ $isUr ? 'مرحلہ' : 'Step' }}</th>
                    <th>{{ $isUr ? 'تفصیل' : 'What it does' }}</th>
                    <th class="text-center">{{ $isUr ? 'فنانس' : 'Finance' }}</th>
                    <th class="text-center">{{ $isUr ? 'اسٹاک' : 'Stock' }}</th>
                    <th class="text-center">{{ $isUr ? 'پرنٹ' : 'Print' }}</th>
                    <th class="text-center">{{ $isUr ? 'ای میل' : 'Email' }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach($flow as [$n, $en, $ur, $desc, $fin, $stk, $prn, $eml])
                <tr>
                    <td class="text-muted">{{ $n }}</td>
                    <td class="fw-semibold text-nowrap">
                        {{ $isUr ? $ur : $en }}
                        @if($isUr)<div class="fs-12 text-muted">{{ $en }}</div>@endif
                    </td>
                    <td class="fs-13">{{ $desc }}</td>
                    <td class="text-center">
                        @if($fin === 'posts')
                            <span class="badge bg-primary">{{ $isUr ? 'کھاتے میں' : 'Posts to GL' }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($stk === 'moves')
                            <span class="badge bg-warning text-dark">{{ $isUr ? 'اسٹاک کم' : 'Moves stock' }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($prn === 'network')
                            <span class="badge bg-success">{{ $isUr ? 'کچن پرنٹر' : 'Network' }}</span>
                        @elseif($prn === 'yes')
                            <span class="badge bg-light text-dark">A4</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($eml === 'yes')
                            <i class="ti ti-mail text-primary" title="{{ $isUr ? 'گاہک کو ای میل' : 'Emails the customer' }}"></i>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- ── One booking, step by step ─────────────────────────────────────── --}}
<div class="card mb-3">
    <div class="card-header">
        <h5 class="mb-0">{{ $isUr ? 'ایک اصل بکنگ، قدم بہ قدم' : 'One real booking, step by step' }}</h5>
    </div>
    <div class="card-body pb-0">
        <p class="fs-13 text-muted mb-0">
            {{ $isUr
                ? 'ایک شادی — 100 مہمان۔ ہر قدم پر دیکھیں کہ بکنگ کی حالت کیا ہے، کوٹیشن کی حالت کیا ہے، اور پیسہ کہاں گیا۔'
                : 'A wedding for 100 guests. At every step: what the booking says, what the quotation says, and where the money actually went.' }}
        </p>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 fs-13">
            <thead>
                <tr>
                    <th style="width:3%"></th>
                    <th style="width:26%">{{ $isUr ? 'آپ کیا کرتے ہیں' : 'What you do' }}</th>
                    <th style="width:13%">{{ $isUr ? 'بکنگ' : 'Booking' }}</th>
                    <th style="width:13%">{{ $isUr ? 'کوٹیشن' : 'Quotation' }}</th>
                    <th>{{ $isUr ? 'کھاتے اور اسٹاک پر اثر' : 'What it does to money and stock' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach([
                    ['1', 'Create the booking', 'بکنگ بنائیں', 'Inquiry', '—',
                        'Nothing at all. A name, a date, a guest count.',
                        'کچھ نہیں۔ صرف نام، تاریخ، مہمانوں کی تعداد۔', 'muted'],
                    ['2', 'Add the first dish', 'پہلا آئٹم ڈالیں', 'Draft', 'Draft',
                        'Nothing. The booking becomes a Draft by itself — you did not press anything.',
                        'کچھ نہیں۔ بکنگ خود بخود ڈرافٹ بن جاتی ہے — آپ نے کچھ نہیں دبایا۔', 'muted'],
                    ['3', 'Price it at 250,000', 'قیمت 250,000 رکھیں', 'Draft', 'Draft',
                        'Nothing. Edit as often as you like — this is the whole point of a draft.',
                        'کچھ نہیں۔ جتنی مرضی تبدیلی کریں — ڈرافٹ کا مطلب ہی یہی ہے۔', 'muted'],
                    ['4', 'Finalize the quotation', 'کوٹیشن حتمی کریں', 'Quoted', 'Sent',
                        'Nothing posts. But the quotation FREEZES — the customer copy can no longer change underneath them.',
                        'کوئی رقم نہیں۔ مگر کوٹیشن مقفل ہو جاتی ہے — گاہک کی کاپی اب نہیں بدل سکتی۔', 'info'],
                    ['5', 'Customer says yes', 'گاہک ہاں کہہ دے', 'Confirmed', 'Accepted',
                        'Nothing posts. The booking confirms itself — you do not press Confirm separately.',
                        'کوئی رقم نہیں۔ بکنگ خود تصدیق ہو جاتی ہے — الگ سے تصدیق نہیں کرنی پڑتی۔', 'info'],
                    ['6', 'Take 100,000 advance', '100,000 پیشگی لیں', 'Confirmed', 'Accepted',
                        'MONEY: Dr Cash/Bank 100,000 · Cr Customer Advances 100,000. It is a LIABILITY — you owe a party, not a profit. Balance due 150,000.',
                        'رقم: کیش 100,000 ڈیبٹ · گاہک کی پیشگی 100,000 کریڈٹ۔ یہ آمدنی نہیں، ذمہ داری ہے۔ باقی 150,000۔', 'warn'],
                    ['7', 'Guests rise to 120 — Create Revision', 'مہمان 120 ہو گئے — نظرثانی', 'Draft', 'Q2 Draft (Q1 superseded)',
                        'NO money moves. But the bill changes: at 300,000 the balance due becomes 200,000. The booking drops back to Draft — the customer agreed to 250,000, not this.',
                        'رقم نہیں ہلتی۔ مگر بل بدل جاتا ہے: 300,000 پر باقی 200,000۔ بکنگ واپس ڈرافٹ — گاہک نے 250,000 پر ہاں کی تھی، اس پر نہیں۔', 'warn'],
                    ['8', 'Finalize Q2, customer accepts', 'Q2 حتمی، گاہک ہاں کرے', 'Confirmed', 'Q2 Accepted',
                        'Nothing posts. The booking confirms again, on the NEW figure this time.',
                        'کوئی رقم نہیں۔ بکنگ دوبارہ تصدیق — اب نئی رقم پر۔', 'info'],
                    ['9', 'Release production', 'پیداوار جاری کریں', 'Released', 'Q2 Accepted',
                        'No money, NO STOCK YET. It freezes the dish list into a kitchen sheet and prints it. From here the booking can no longer be walked back.',
                        'نہ رقم، نہ ابھی اسٹاک۔ کچن شیٹ بن کر چھپ جاتی ہے۔ اس کے بعد بکنگ واپس نہیں جا سکتی۔', 'info'],
                    ['10', 'Issue materials', 'خام مال جاری کریں', 'Released', 'Q2 Accepted',
                        'STOCK LEAVES THE STORE — the only place in catering where it does. Cost is posted at what the stock actually cost you.',
                        'اسٹاک اسٹور سے نکلتا ہے — کیٹرنگ میں صرف یہیں۔ لاگت اصل قیمت پر کھاتے میں جاتی ہے۔', 'danger'],
                    ['11', 'Issue the final invoice', 'حتمی بل جاری کریں', 'Completed', 'Q2 Accepted',
                        'REVENUE IS EARNED HERE, not when the money arrived: Dr Receivable 300,000 · Cr Revenue 300,000. The 100,000 advance is applied against it. The document freezes permanently.',
                        'آمدنی یہاں بنتی ہے، رقم آنے پر نہیں: قابلِ وصول 300,000 ڈیبٹ · آمدنی 300,000 کریڈٹ۔ 100,000 پیشگی اسی پر لگ جاتی ہے۔', 'danger'],
                    ['12', 'Take the remaining 200,000', 'باقی 200,000 وصول کریں', 'Completed', 'Q2 Accepted',
                        'MONEY: Dr Cash/Bank 200,000 · Cr Receivable 200,000. Nothing is owed in either direction now.',
                        'رقم: کیش 200,000 ڈیبٹ · قابلِ وصول 200,000 کریڈٹ۔ اب کسی طرف کچھ باقی نہیں۔', 'warn'],
                    ['13', 'Close the booking', 'بکنگ بند کریں', 'Closed', 'Q2 Accepted',
                        'Nothing posts. Refused while ANY balance is outstanding — in either direction, including money you owe the customer.',
                        'کوئی رقم نہیں۔ اگر کسی بھی طرف کچھ باقی ہو تو بند نہیں ہوگی — بشمول وہ رقم جو آپ نے گاہک کو واپس کرنی ہے۔', 'muted'],
                ] as [$n, $en, $ur, $bk, $qt, $fxEn, $fxUr, $tone])
                    <tr>
                        <td class="text-muted">{{ $n }}</td>
                        <td><strong>{{ $isUr ? $ur : $en }}</strong></td>
                        <td><span class="badge bg-{{ $bk === 'Confirmed' || $bk === 'Released' ? 'success' : ($bk === 'Completed' ? 'warning' : ($bk === 'Closed' ? 'dark' : ($bk === 'Quoted' ? 'info' : 'secondary'))) }}">{{ $bk }}</span></td>
                        <td class="text-muted">{{ $qt }}</td>
                        <td class="{{ $tone === 'danger' ? 'text-danger' : ($tone === 'warn' ? 'text-warning-emphasis' : ($tone === 'info' ? '' : 'text-muted')) }}">
                            {{ $isUr ? $fxUr : $fxEn }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-body pt-3">
        <div class="alert alert-light border mb-0 fs-13">
            <strong>{{ $isUr ? 'اس مثال سے تین باتیں سیکھیں:' : 'Three things this example teaches:' }}</strong>
            <ol class="mb-0 mt-2">
                <li>{{ $isUr
                    ? 'پیشگی رقم آمدنی نہیں ہوتی۔ آمدنی صرف حتمی بل پر بنتی ہے (قدم 11)۔'
                    : 'An advance is not income. Revenue is earned only at the final invoice (step 11).' }}</li>
                <li>{{ $isUr
                    ? 'اسٹاک صرف خام مال جاری کرنے پر ہلتا ہے (قدم 10) — پیداوار جاری کرنے پر نہیں۔'
                    : 'Stock moves only when materials are issued (step 10) — not when production is released.' }}</li>
                <li>{{ $isUr
                    ? 'نظرثانی کوئی رقم نہیں ہلاتی (قدم 7) — مگر بل بدل جاتا ہے، اس لیے باقی رقم بھی بدل جاتی ہے۔'
                    : 'A revision moves no money (step 7) — but it changes the bill, so it changes what is still owed.' }}</li>
            </ol>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    {{-- ── What a quotation is ───────────────────────────────────────── --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0">{{ $isUr ? 'کوٹیشن کیا ہے، اور نظرثانی کیا کرتی ہے' : 'What a quotation is, and what a revision does' }}</h5>
            </div>
            <div class="card-body fs-13">
                <p>
                    {{ $isUr
                        ? 'بکنگ اور کوٹیشن دو الگ چیزیں ہیں، اور دونوں کی اپنی حالت ہے۔ بکنگ وہ تقریب ہے؛ کوٹیشن وہ کاغذ ہے جو گاہک کے ہاتھ میں ہے۔'
                        : 'The booking and the quotation are two different things, each with its own status. The booking is the event; the quotation is the piece of paper in the customer\x27s hand.' }}
                </p>
                <p class="mb-2"><strong>{{ $isUr ? 'آئٹم کب تک بدل سکتے ہیں؟' : 'Until when can you edit the items?' }}</strong></p>
                <ul class="mb-3">
                    <li>{{ $isUr ? 'جب تک کوٹیشن ڈرافٹ ہے — جتنی مرضی۔' : 'While the quotation is a Draft — as much as you like.' }}</li>
                    <li>{{ $isUr ? 'بھیجنے کے بعد — بکنگ کو واپس ڈرافٹ پر لے جائیں، یا نظرثانی بنائیں۔' : 'After it is Sent — move the booking back to Draft, or create a revision.' }}</li>
                    <li>{{ $isUr ? 'گاہک کے قبول کرنے کے بعد — صرف نظرثانی۔' : 'After the customer accepts — revision only.' }}</li>
                </ul>
                <p class="mb-2"><strong>{{ $isUr ? 'نظرثانی بنانے پر کیا ہوتا ہے؟' : 'What happens when you create a revision?' }}</strong></p>
                <ul class="mb-0">
                    <li>{{ $isUr ? 'نئی Q2 قابلِ تدوین ڈرافٹ بنتی ہے؛ Q1 محفوظ رہتی ہے، کبھی حذف نہیں ہوتی۔' : 'A new Q2 opens as an editable draft; Q1 is kept forever, never deleted.' }}</li>
                    <li>{{ $isUr ? 'بکنگ واپس ڈرافٹ پر چلی جاتی ہے — گاہک نے پرانی رقم پر ہاں کی تھی۔' : 'The booking goes back to Draft — the customer agreed to the old figure, not the new one.' }}</li>
                    <li class="text-warning-emphasis">{{ $isUr ? 'کوئی رقم نہیں ہلتی — مگر باقی رقم بدل جاتی ہے، کیونکہ بل بدل گیا۔' : 'No money moves — but the balance changes, because the bill changed.' }}</li>
                    <li>{{ $isUr ? 'اگر رقم پہلے سے وصول ہو تو اسکرین بتائے گی، دبانے سے پہلے۔' : 'If money has already been received the screen says so, before you press it.' }}</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- ── Going back ────────────────────────────────────────────────── --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0">{{ $isUr ? 'واپس جانا — کہاں تک ممکن ہے' : 'Going back — how far you can undo' }}</h5>
            </div>
            <div class="card-body fs-13">
                <p>
                    {{ $isUr
                        ? 'اصول حالت کا نام نہیں دیکھتا — یہ دیکھتا ہے کہ اُس حالت نے کیا کر دیا ہے۔'
                        : 'The rule is not which status you are in. It is what that status already DID.' }}
                </p>
                <div class="table-responsive">
                    <table class="table table-sm mb-3">
                        <tbody>
                            <tr>
                                <td class="text-success fs-5">&#10003;</td>
                                <td><code>Confirmed → Quoted → Draft → Inquiry</code></td>
                            </tr>
                            <tr>
                                <td class="text-success fs-5">&#10003;</td>
                                <td>{{ $isUr ? 'منسوخ شدہ → جہاں سے منسوخ ہوئی تھی' : 'Cancelled → back to wherever it was cancelled from' }}</td>
                            </tr>
                            <tr>
                                <td class="text-danger fs-5">&#10007;</td>
                                <td><strong>{{ $isUr ? 'حتمی بل جاری ہونے کے بعد' : 'After the final invoice' }}</strong></td>
                            </tr>
                            <tr>
                                <td class="text-danger fs-5">&#10007;</td>
                                <td><strong>{{ $isUr ? 'پیداوار جاری ہونے کے بعد' : 'After production is released' }}</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mb-2">
                    {{ $isUr
                        ? 'ان دو کے بعد واپسی کیوں نہیں؟ کیونکہ کھاتے میں یا اسٹور میں نشان پڑ چکا ہے۔ حالت بدلنے سے وہ نشان نہیں مٹتا — اسکرین جھوٹ بولنے لگے گی جبکہ رقم اور مال جا چکے ہوں گے۔ ان کا جواب ایک اور دستاویز ہوتی ہے: واپسی (Refund) یا اسٹاک کی درستی۔'
                        : 'Why not after those two? Because the ledger or the store carries a mark. Changing a status does not erase it — the screen would start lying while the money and the goods are still gone. Those are answered by another DOCUMENT: a refund, or a stock correction.' }}
                </p>
                <div class="alert alert-light border mb-0">
                    <strong>{{ $isUr ? 'واپس جانے سے رقم کو کچھ نہیں ہوتا۔' : 'Going back never touches money.' }}</strong>
                    {{ $isUr
                        ? ' نہ کوئی رسید بدلتی ہے، نہ کوئی اندراج اُلٹتا ہے۔ صرف یہ بدلتا ہے کہ بل کس چیز کا ہے — اور منسوخی پر پہلے سے وصول رقم گاہک کا کریڈٹ بن جاتی ہے، جو الگ سے واپس کرنی ہوتی ہے۔'
                        : ' No receipt is edited and no entry is reversed. Only what the booking is billed FOR changes — and on a cancellation, money already received becomes the customer\x27s credit, which must be refunded separately.' }}
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ── Where to manage what ──────────────────────────────────────────── --}}
<div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">{{ $isUr ? 'کون سی چیز کہاں سے بدلیں' : 'Where to manage what' }}</h5></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>{{ $isUr ? 'آپ کیا بدلنا چاہتے ہیں' : 'What you want to change' }}</th>
                    <th>{{ $isUr ? 'کہاں' : 'Where' }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach($manage as [$what, $where, $href])
                <tr>
                    <td>{{ $what }}</td>
                    <td><a href="{{ url($href) }}">{{ $where }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    {{-- ── Statuses ──────────────────────────────────────────────────── --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h5 class="mb-0">{{ $isUr ? 'حالتوں کا مطلب' : 'What the statuses mean' }}</h5></div>
            <div class="card-body">
                <h6 class="text-muted fs-12 text-uppercase">{{ $isUr ? 'بکنگ' : 'Booking' }}</h6>
                <dl class="row fs-13 mb-3">
                    <dt class="col-4"><span class="badge bg-light text-dark">Inquiry</span></dt>
                    <dd class="col-8">{{ $isUr ? 'صرف پوچھ گچھ — ابھی کوئی آئٹم نہیں ڈالا گیا۔ پہلا آئٹم ڈالتے ہی یہ خود ڈرافٹ بن جاتی ہے۔' : 'Just an enquiry — no items yet. Adding the first item turns it into a Draft by itself.' }}</dd>
                    <dt class="col-4"><span class="badge bg-secondary">Draft</span></dt>
                    <dd class="col-8">{{ $isUr ? 'آپ ابھی تخمینہ بنا رہے ہیں۔ کچھ بھی حتمی نہیں۔' : 'You are still building the quote. Nothing is committed.' }}</dd>
                    <dt class="col-4"><span class="badge bg-info">Quoted</span></dt>
                    <dd class="col-8">{{ $isUr ? 'تخمینہ گاہک کو بھیجا جا چکا ہے۔' : 'The estimate has gone to the customer.' }}</dd>
                    <dt class="col-4"><span class="badge bg-success">Confirmed</span></dt>
                    <dd class="col-8">{{ $isUr ? 'گاہک نے ہاں کر دی۔ اب پیشگی رقم اور پیداوار ممکن ہے۔' : 'Customer agreed. Advances and production unlock.' }}</dd>
                    <dt class="col-4"><span class="badge bg-success">Released</span></dt>
                    <dd class="col-8">{{ $isUr ? 'کچن شیٹ جاری۔ اب خام مال نکالا جا سکتا ہے۔' : 'Kitchen sheet issued. Materials can now be drawn.' }}</dd>
                    <dt class="col-4"><span class="badge bg-dark">Closed</span></dt>
                    <dd class="col-8">{{ $isUr ? 'مکمل ادائیگی کے بعد بند۔ اب کوئی تبدیلی نہیں۔' : 'Settled and locked. No further changes.' }}</dd>
                    <dt class="col-4"><span class="badge bg-danger">Cancelled</span></dt>
                    <dd class="col-8">{{ $isUr ? 'منسوخ۔ پہلے سے وصول رقم کھاتے میں رہتی ہے، الگ سے واپس کرنی ہوگی۔' : 'Cancelled. Any advance already received stays on the ledger and must be refunded separately.' }}</dd>
                </dl>
                <h6 class="text-muted fs-12 text-uppercase">{{ $isUr ? 'تخمینہ' : 'Estimate' }}</h6>
                <dl class="row fs-13 mb-0">
                    <dt class="col-4"><span class="badge bg-secondary">Draft</span></dt>
                    <dd class="col-8">{{ $isUr ? 'قابلِ تدوین۔ جتنی مرضی تبدیلی کریں۔' : 'Editable. Change it as often as you want.' }}</dd>
                    <dt class="col-4"><span class="badge bg-info">Sent</span></dt>
                    <dd class="col-8">{{ $isUr ? 'گاہک کو بھیجی جا چکی — مقفل۔ قیمت بدلنے کے لیے نظرثانی بنائیں، یا بکنگ کو واپس ڈرافٹ پر لے جائیں۔' : 'Sent to the customer — locked. To change the price, create a revision, or move the booking back to Draft.' }}</dd>
                    <dt class="col-4"><span class="badge bg-success">Accepted</span></dt>
                    <dd class="col-8">{{ $isUr ? 'گاہک نے اسی کوٹیشن پر ہاں کی۔ اب صرف نظرثانی سے تبدیلی ہو سکتی ہے۔' : 'The customer said yes to this exact quotation. From here only a revision can change it.' }}</dd>
                    <dt class="col-4"><span class="badge bg-light text-dark">Superseded</span></dt>
                    <dd class="col-8">{{ $isUr ? 'نئی نظرثانی نے اس کی جگہ لے لی۔ ریکارڈ محفوظ رہتا ہے۔' : 'Replaced by a newer revision. Kept for your records, never deleted.' }}</dd>
                </dl>
            </div>
        </div>
    </div>

    {{-- ── Printing ──────────────────────────────────────────────────── --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h5 class="mb-0">{{ $isUr ? 'پرنٹنگ' : 'Printing' }}</h5></div>
            <div class="card-body">
                <p class="fs-13">
                    {{ $isUr
                        ? 'دو الگ طریقے ہیں۔ فرق سمجھنا ضروری ہے:'
                        : 'There are two different mechanisms. The difference matters:' }}
                </p>
                <dl class="fs-13">
                    <dt>{{ $isUr ? 'دستی پرنٹ (A4)' : 'Manual print (A4)' }}</dt>
                    <dd>
                        {{ $isUr
                            ? 'براؤزر سے کوئی بھی پرنٹر۔ انگریزی اور اردو دونوں مکمل کام کرتی ہیں۔ تخمینہ اور بل اسی طریقے سے نکلتے ہیں۔'
                            : 'Any printer, through the browser. Both English and Urdu work fully. Estimates and invoices use this.' }}
                    </dd>
                    <dt>{{ $isUr ? 'نیٹ ورک پرنٹ' : 'Send to network' }}</dt>
                    <dd>
                        {{ $isUr
                            ? 'کچن شیٹ خودبخود کچن کے تھرمل پرنٹر پر چلی جاتی ہے — کوئی ڈائیلاگ نہیں۔ اس کے لیے برانچ میں LAN ایجنٹ چلنا ضروری ہے۔'
                            : 'The kitchen sheet goes straight to the mapped thermal printer — no dialog, no browser. Requires the LAN agent running on site.' }}
                    </dd>
                </dl>
                <dl class="fs-13">
                    <dt>{{ $isUr ? 'گاہک کو ای میل / دوبارہ بھیجیں' : 'Email to Customer / Resend' }}</dt>
                    <dd>
                        {{ $isUr
                            ? 'حتمی کوٹیشن اور حتمی بل پر ای میل کا بٹن موجود ہے۔ دوبارہ دبانا جان بوجھ کر دوبارہ بھیجنا ہے — ہر کوشش کا ریکارڈ بنتا ہے۔ ڈرافٹ ای میل نہیں ہوتا: پہلے حتمی کریں۔'
                            : 'Finalized quotations and final invoices carry an email button. Pressing it again is a deliberate resend — every attempt is logged. A draft cannot be emailed: finalize first. Emailing never changes the booking.' }}
                    </dd>
                </dl>
                <div class="alert alert-warning fs-13 mb-0">
                    <i class="ti ti-alert-triangle me-1"></i>
                    <strong>{{ $isUr ? 'حد:' : 'Limitation:' }}</strong>
                    {{ $isUr
                        ? 'تھرمل پرنٹر پر اردو نہیں چھپ سکتی۔ اردو صرف A4 / براؤزر پرنٹ پر دستیاب ہے۔'
                        : 'Urdu cannot print on a thermal printer. Urdu is available on A4 / browser print only.' }}
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ── Costing, which is the part people get wrong ───────────────────── --}}
<div class="card mt-3">
    <div class="card-header"><h5 class="mb-0">{{ $isUr ? 'لاگت کیسے نکلتی ہے' : 'How costing works' }}</h5></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <h6>{{ $isUr ? 'تخمینے کی لاگت' : 'Quoted cost' }}</h6>
                <p class="fs-13 mb-0">
                    {{ $isUr
                        ? 'ہر ڈش کی ترکیب × میٹیریل ریٹ بک کا ریٹ۔ یہ آپ کا اندازہ ہے — اسٹاک سے اس کا کوئی تعلق نہیں۔'
                        : "Each dish's recipe × the rate in the Material Rate Book. This is your planning figure — it never looks at stock." }}
                </p>
            </div>
            <div class="col-md-6">
                <h6>{{ $isUr ? 'اصل لاگت' : 'Actual cost' }}</h6>
                <p class="fs-13 mb-0">
                    {{ $isUr
                        ? 'جب خام مال جاری ہوتا ہے تو اصل بیچ کی قیمت (FEFO) پر لاگت آتی ہے۔ اسی لیے دونوں اعداد مختلف ہوتے ہیں — یہ خرابی نہیں۔'
                        : 'When materials are issued, cost comes from the real batch price (FEFO). The two figures differ by design — that is not a bug.' }}
                </p>
            </div>
        </div>
        <hr>
        <p class="fs-13 mb-0 text-muted">
            <i class="ti ti-eye-off me-1"></i>
            {{ $isUr
                ? 'تخمینہ شدہ لاگت اور منافع صرف آپ کے لیے ہیں — گاہک کے کسی دستاویز پر کبھی نہیں چھپتے۔'
                : 'Estimated cost and margin are internal only — they are never printed on any customer document.' }}
        </p>
    </div>
</div>

{{-- ── Honest limitations ────────────────────────────────────────────── --}}
<div class="card mt-3 border-warning">
    <div class="card-header"><h5 class="mb-0">{{ $isUr ? 'ابھی کیا کام نہیں کرتا' : 'What does not work yet' }}</h5></div>
    <div class="card-body">
        <ul class="fs-13 mb-0">
            <li>
                <strong>{{ $isUr ? 'تھرمل اردو' : 'Urdu on thermal' }}</strong> —
                {{ $isUr ? 'دستیاب نہیں۔ اردو کے لیے A4 استعمال کریں۔' : 'Not available. Use A4 for Urdu.' }}
            </li>
            <li>
                <strong>{{ $isUr ? 'تخمینہ / بل کا نیٹ ورک پرنٹ' : 'Network print for estimate / invoice' }}</strong> —
                {{ $isUr ? 'صرف کچن شیٹ نیٹ ورک پر جاتی ہے۔' : 'Only the kitchen sheet goes over the network today.' }}
            </li>
        </ul>
    </div>
</div>
@endsection
