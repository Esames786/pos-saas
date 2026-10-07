# Arabic glossary — bingoopos.com

WEBSITE-I18N-GEO-1. One word for one idea, on every page. A translator (Claude or a person) checks
this list before writing a new sentence; a reviewer who changes a term here changes it in
`resources/lang/ar.json` too (`php artisan lang:export ar` → edit → `lang:import ar`).

Register: Modern Standard Arabic, plain and commercial, written for a Saudi business owner.
Digits: Western (0-9) everywhere — prices, counts, dates.

| English | Arabic | Note |
|---|---|---|
| Bingoo / Bingoo POS | بينجو | The brand. "Bingoo POS" in a sentence is just بينجو. Logo and receipt mock-ups stay in Latin. |
| POS / point of sale | نقاط البيع / نقطة البيع | "POS plan" = باقة نقاط البيع |
| cloud POS | نظام نقاط بيع سحابي | |
| counter / checkout (the place) | الكاشير | plural الكاشيرات |
| checkout (the act) | الدفع | "barcode checkout" = الدفع بالباركود |
| terminal | جهاز نقطة البيع | short form جهاز; plural أجهزة نقاط البيع |
| branch | فرع | plural فروع; "multi-branch" = الفروع المتعددة / متعدد الفروع |
| workspace | مساحة العمل | |
| tenant (only where unavoidable) | المنشأة / قاعدة بيانات مستقلة | never a transliteration |
| subdomain | النطاق الفرعي | |
| plan / package | الباقة | plural الباقات |
| free trial | التجربة المجانية / الفترة التجريبية | "30-day free trial" = تجربة مجانية لمدة 30 يوماً |
| upgrade | ترقية | verb رقِّ |
| billing | الفوترة | "billing portal" = بوابة الفوترة |
| monthly / yearly | شهري / سنوي ; per month / per year = شهرياً / سنوياً | |
| 2 months free | شهران مجاناً | |
| VAT | ضريبة القيمة المضافة | |
| KOT (kitchen order ticket) | طلب المطبخ | "KOT printing" = طباعة طلبات المطبخ |
| KDS / kitchen display | شاشة المطبخ | (KDS) in brackets where the English acronym helps |
| dine-in / takeaway / delivery | صالة / خارجي / توصيل | |
| waiter | النادل | plural النُدُل |
| held sale | تعليق الفاتورة | |
| split bill | تقسيم الفاتورة | |
| service charge | رسوم الخدمة | |
| inventory / stock | المخزون | "stock count" = جرد المخزون; "low stock" = مخزون منخفض |
| purchase order | أمر الشراء | |
| GRN (goods receipt) | سند استلام البضائع | short سند الاستلام |
| supplier | المورد | plural الموردون |
| recipe / BOM | الوصفة / قائمة المواد (BOM) | |
| chart of accounts | دليل الحسابات | |
| general ledger | دفتر الأستاذ العام | |
| trial balance | ميزان المراجعة | |
| profit & loss | الأرباح والخسائر | |
| balance sheet | الميزانية العمومية | |
| receivables / payables aging | أعمار الذمم المدينة والدائنة | |
| ERP | ERP / تخطيط الموارد | keep the acronym |
| FBR | FBR | Pakistan only; FBR blocks are hidden on non-Pakistan pages |
| ZATCA | زاتكا / الفوترة الإلكترونية | never "compliant" until it is built |
| demo (live demo workspace) | العرض التجريبي | "book a demo" (meeting) = احجز عرضاً توضيحياً |
| contact sales | تواصل مع المبيعات | |
| owner / manager / cashier | المالك / المدير / الكاشير | |
| users & roles | المستخدمون والصلاحيات | |

## Plan names

| English | Arabic |
|---|---|
| Retail Starter | التجزئة الأساسية |
| Inventory Store | متجر المخزون |
| Restaurant Starter | المطعم الأساسي |
| Restaurant Pro | المطعم الاحترافي |
| Finance & Supply Chain ERP | نظام المالية وسلاسل الإمداد (ERP) |
| Enterprise / Custom | المؤسسات / مخصّص |

## Legal pages

Translated whole (`resources/views/public/legal/ar/*.blade.php`), each opening with:
"هذه ترجمة عربية مقدَّمة للتسهيل. في حال وجود أي اختلاف بين النصين، تكون النسخة الإنجليزية هي المعتمدة."
A native reader must review these before they are relied on.
