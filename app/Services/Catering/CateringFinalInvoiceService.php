<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringFinalInvoice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CATERING-V1-CLOSURE-1 (§5): final invoice + event closure lifecycle.
 *
 * The final invoice freezes the agreed estimate's commercial figures plus the
 * advance position into an immutable document. Settlement in V1 stays
 * OPERATIONAL: the remaining balance is received through the §4 advance flow
 * (capped at the outstanding amount), and the event can only CLOSE when the
 * live balance reaches zero — no override exists until an authorized business
 * rule says otherwise.
 *
 * FINANCE STOP (reported, not built): posting advances/final settlement to
 * the GL needs a customer-advance liability account and a catering revenue
 * mapping on the chart of accounts — a finance design that must land as
 * JournalPostingService translator methods. No journal tables are written
 * here and no homemade ledger exists.
 */
class CateringFinalInvoiceService
{
    public function __construct(
        private readonly CateringNumberService $numbers,
        private readonly CateringMailService $mail,
        private readonly \App\Services\Finance\JournalPostingService $journalPosting,
        private readonly \App\Services\Finance\JournalService $journal,
        private readonly CateringDocumentLock $locks,
    ) {}

    public function issue(CateringEvent $event, ?int $userId = null): CateringFinalInvoice
    {
        // KASHIF-CATERING-LIFECYCLE-LOCK-1: the invoice is the hardest boundary in
        // the whole booking — after it, nothing commercial may move. So it is
        // established under the same lock order Rate Impact waits on, and every
        // condition below is judged on rows re-read while holding it. Two
        // concurrent issues would otherwise both find no invoice and both write
        // one, against a unique invoice_no.
        $estimate = null;

        $invoice = DB::connection('tenant')->transaction(function () use ($event, $userId, &$estimate) {
            // Taken INSIDE the transaction, because a lock held outside one is
            // released the instant the statement ends and protects nothing.
            $this->locks->refreshEvent($event);

            $estimate = $event->currentEstimate;
            if (! $estimate || $estimate->isDraft()) {
                throw new RuntimeException('A final invoice needs a sent/accepted estimate — drafts cannot be invoiced.');
            }
            if ($event->finalInvoice()->exists()) {
                throw new RuntimeException("Event {$event->event_no} already has a final invoice.");
            }
            if (! in_array($event->status, [
                CateringEvent::STATUS_CONFIRMED,
                CateringEvent::STATUS_PRODUCTION_READY,
                CateringEvent::STATUS_RELEASED,
            ], true)) {
                throw new RuntimeException("Event {$event->event_no} ({$event->status}) cannot be invoiced — confirm the booking first.");
            }

            // CATERING-NOTHING-FREEZES-BEFORE-EVENT-1 (10 Oct) — malik:
            // "jab tak event ka din na guzar jaye tab tak koi invoice freeze
            // na ho, na hi final ho. Order edit ho sake."
            //
            // Ye pehra CLOSE par pehle se tha (`closeLocked()`, 8 Oct) — magar
            // asal tala CLOSE par nahi, YAHAN lagta hai: bill bante hi booking
            // `completed` ho jati hai, aur `completed` par `isCommerciallyOpen()`
            // jhoot ho kar "Create Revision" chhupa deta hai. Yani darwaza B par
            // taala laga tha aur log darwaze A se andar aate rahe.
            //
            // Prod par ye 33 me se 3 baar hua; EV-20261009-0219 (event 10 Oct,
            // bill 9 Oct) wo case hai jis par malik ne ungli rakhi.
            //
            // ⚠️ MUQABLA TAREEKH KI STRING PAR, LAMHON PAR NAHI — `event_date`
            // UTC ki aadhi raat hai, TenantClock ki aadhi raat Karachi ki (19:00
            // UTC pichhle din). Lamhe milane par event ka apna din bhi "abhi
            // aaya hi nahi" nikalta hai. Ye ghalti `closeLocked()` me ek baar ho
            // chuki hai aur us par comment likha hua hai.
            $this->refuseBeforeTheEventDayHasPassed($event);

            $advances = $event->advances()->orderBy('received_date')->get();
            $advanceTotal = round((float) $advances->sum('amount') - (float) $event->refunds()->sum('amount'), 2);
            $balanceDue = round((float) $estimate->grand_total - $advanceTotal, 2);

            // KASHIF-CATERING-CUSTOMER-CREDIT-1: an invoice can only absorb its
            // own value. Clearing the whole advance would take money out of the
            // customer-advance liability that this invoice does not account for,
            // and the business would stop being able to see that it owes it.
            $advanceApplied = round(min($advanceTotal, (float) $estimate->grand_total), 2);

            $invoice = CateringFinalInvoice::create(
                $this->documentAttributesFor($event, $estimate, $advances, $advanceTotal, $advanceApplied, $balanceDue) + [
                'invoice_no' => $this->numbers->nextFinalInvoiceNo(),
                'catering_event_id' => $event->id,
                'catering_estimate_id' => $estimate->id,
                'status' => CateringFinalInvoice::STATUS_ISSUED,
                'issued_at' => now(),
                'issued_by_user_id' => $userId,
            ]);

            // CATERING-GO-LIVE-READINESS-1 (§6): accounting posts INSIDE the issue
            // transaction — an invoice exists iff its GL exists. Translators throw
            // on failure/conflict, rolling the whole issue back.
            $invoice->setRelation('event', $event);
            $invoiceEntry = $this->journalPosting->postCateringFinalInvoice($invoice, $userId);
            $applicationEntry = $this->journalPosting->applyCateringAdvance($invoice, $userId);

            $invoice->forceFill([
                'journal_entry_id' => $invoiceEntry->id,
                'advance_application_journal_entry_id' => $applicationEntry?->id,
                'gl_posted_at' => now(),
            ])->save();

            // Event day is billed → the event is operationally complete. Inside
            // the transaction with the invoice: an invoice that exists on an
            // event still open for commercial change is precisely the state this
            // whole lock order exists to make unreachable.
            $event->forceFill(['status' => CateringEvent::STATUS_COMPLETED])->save();

            return $invoice;
        });

        $this->mail->send(
            \App\Mail\Catering\CateringCustomerMail::TYPE_FINAL_INVOICE,
            $event->refresh(),
            $estimate,
            ['advance_total' => (float) $invoice->advance_total, 'invoice_no' => $invoice->invoice_no],
            'invoice-'.$invoice->id,
        );

        return $invoice;
    }

    /**
     * Close the event. HARD RULE: an unresolved final balance blocks closure —
     * the remaining amount must first be received via the §4 advance flow.
     */
    public function close(CateringEvent $event): CateringEvent
    {
        return DB::connection('tenant')->transaction(fn () => $this->closeLocked($event));
    }

    /**
     * Event ka din guzre baghair ye booking par kuch jamta nahi.
     *
     * CATERING-NOTHING-FREEZES-BEFORE-EVENT-1 — qaida EK jagah, kyunke do
     * darwaze hain (bill banana aur booking band karna) aur un ka alag ho jana
     * khamoshi se hota hai. 8 Oct ko pehra sirf CLOSE par laga tha; INVOICE ka
     * raasta khula raha aur wohi masla doosri shakl me 9 Oct ko wapas aa gaya.
     *
     * Hadd: `event_date < aaj`. Event ke DIN bhi nahi — us din khana abhi ja
     * raha hota hai aur rakam badal sakti hai.
     */
    /**
     * Ek catering bill ke khaane — EK jagah.
     *
     * CATERING-PROFORMA-1 (10 Oct): ye hissa `issue()` ke andar likha tha.
     * Proforma ko bilkul yehi shakl chahiye (sirf number, status aur GL ke
     * baghair), aur us ki doosri nakal rakhne ka anjaam maloom hai — ek din
     * graahak ka proforma aur us ka asli bill alag adad kehne lagte. Dono ab
     * yahin se bante hain.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Tenant\CateringAdvance>  $advances
     * @return array<string, mixed>
     */
    /**
     * Bill void — aur us ke saath booking wapas khul jaye.
     *
     * CATERING-INVOICE-VOID-1 (10 Oct). Malik: "jo order ab complete ho gaye
     * hain before date, un ki invoices ko proforma mein change karna hoga taake
     * wo order edit ho sake."
     *
     * Ab tak is ka koi raasta tha hi nahi — model ke comment me likha tha ke
     * "void/reversal policy is a future finance design", yani jaan boojh kar
     * baad ke liye chhora gaya aur banaya kabhi nahi gaya.
     *
     * ── KYA HOTA HAI ───────────────────────────────────────────────────────
     *
     * 1. Bill ki DONO journal entries ulti post hoti hain. Reversal entry banti
     *    hai, purani entry mitai nahi jati — khaton me dono nazar aati hain.
     * 2. Bill ke row par nishan lagta hai. Row MITAYA NAHI jata: numbered
     *    dastavez mitana qatar me sooraakh aur gawahi ka nuqsan dono hai.
     * 3. Booking wapas khul jati hai, taake order edit ho sake.
     *
     * Advance ka kya hota hai? `applyCateringAdvance` ne Dr 2300 / Cr 1300 kiya
     * tha; us ka ulta Cr 2300 / Dr 1300 hai — yani graahak ka paisa wapas us ke
     * naam par liability ban jata hai, bilkul bill se pehle wali halat. Advance
     * ka apna row chhua tak nahi jata.
     *
     * 🚨 GL KI NAKAMI NIGLI NAHI JATI. `JournalPostingService::reverseForSource()`
     * nakami par `report()` kar ke `null` de deta hai — un translators ke liye
     * theek hai jo safe-null hain, magar YAHAN nahi: bill void ho jaye aur us ki
     * entry khaton me khari rahe to kitaabein jhoot bolne lagti hain, khamoshi
     * se. Is liye reversal `JournalService` se seedha liya ja raha hai (jo
     * throw karta hai), aur poora kaam ek hi transaction me hai.
     */
    public function void(CateringFinalInvoice $invoice, string $reason, ?int $userId = null): CateringFinalInvoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('A void needs a reason — six months from now nobody remembers why.');
        }

        if ($invoice->isVoided()) {
            return $invoice;   // dobara chalane par kuch na ho
        }

        // 🚨 BILL KE BAAD AAYA PAISA — yahan ruk jao.
        //
        // Receipt ka GL us ke `posting_type` par chalta hai:
        //   • `advance`    (bill se PEHLE)  → Cr 2300 Customer Advances
        //   • `settlement` (bill ke BAAD)   → Cr 1300 AR, seedha
        //
        // Void bill ki entry ulti karta hai, yani AR se bill ki raqam nikal
        // jati hai. Agar us bill ke khilaf `settlement` receipts aa chuki hon
        // to un ka Cr 1300 wahin reh jata hai aur AR MANFI ho jata hai —
        // khate kehne lagte hain ke graahak ne itna zyada de diya.
        //
        // Prod par ye farziya nahi: EV-20260909-0002 par 4,89,605 do
        // `settlement` receipts me aaya hai. Us ko void karna AR ko
        // -4,89,605 kar deta, aur `tb_diff` ko khabar tak na hoti kyunke
        // dono taraf barabar rehta hai.
        //
        // Is ka sahi ilaj ye hai ke aisi receipts wapas `advance` me badli
        // jayen (Dr 1300 / Cr 2300 ka ek durusti posting). Wo abhi banaya
        // nahi gaya, aur banaye baghair ye raasta BAND rehna chahiye: aadha
        // kaam khaton me chup chaap ghalat adad chhorta hai.
        $settlements = $invoice->event()->first()?->advances()
            ->where('posting_type', \App\Models\Tenant\CateringAdvance::POSTING_SETTLEMENT)
            ->count() ?? 0;

        if ($settlements > 0) {
            throw new RuntimeException(
                "Invoice {$invoice->invoice_no} ke khilaf {$settlements} receipt bill ke BAAD aayi hain "
                .'(settlement). Inhen pehle advance me badalna hoga, warna void AR ko manfi kar dega. '
                .'Ye raasta abhi banaya nahi gaya — malik se poochho.'
            );
        }

        return DB::connection('tenant')->transaction(function () use ($invoice, $reason, $userId) {
            $event = $invoice->event()->first();

            foreach (['catering_final_invoice', 'catering_advance_application'] as $source) {
                $entry = $this->journal->findPostedForSource($source, $invoice->id);

                if (! $entry) {
                    // `catering_advance_application` tab nahi banti jab koi
                    // advance hi na aaya ho — wo jaiz hai. Invoice ki apni entry
                    // ka na milna jaiz NAHI, aur neeche us par pehra hai.
                    continue;
                }

                $this->journal->reverse($entry, $reason, $userId);
            }

            if ($invoice->journal_entry_id && ! $this->journal->findPostedForSource('catering_final_invoice', $invoice->id)) {
                throw new RuntimeException(
                    "Invoice {$invoice->invoice_no} ki journal entry mili hi nahi — void rok diya gaya."
                );
            }

            $invoice->forceFill([
                'voided_at' => now(),
                'voided_by_user_id' => $userId,
                'void_reason' => $reason,
            ])->save();

            // Booking wapas khul jaye. `released` wahan rakha ja raha hai jahan
            // kitchen ko parcha ja chuka tha — us haqeeqat ko void badalta nahi.
            if ($event) {
                $event->forceFill([
                    'status' => $event->productionReleases()->exists()
                        ? CateringEvent::STATUS_RELEASED
                        : CateringEvent::STATUS_CONFIRMED,
                    'closed_at' => null,
                ])->save();
            }

            return $invoice->refresh();
        });
    }

    /**
     * Proforma — wohi bill, magar jama hua nahi.
     *
     * CATERING-PROFORMA-1 (10 Oct). Malik: "jab tak event ka din na guzar jaye
     * tab tak koi invoice freeze na ho… order edit ho sake, aur agar order edit
     * ho raha ho to kitchen release sheet ya invoice sheet sab auto update ho."
     *
     * 🚨 YE DATABASE ME KUCH NAHI LIKHTA. Na number kharch hota hai, na status
     * hilta hai, na GL. Ye ek UNSAVED model hai jo maujooda quotation se bhara
     * jata hai — bilkul waise jaise kitchen sheet ka `preview()` karta hai.
     *
     * Alag "draft invoice" ka record JAAN BOOJH KAR nahi banaya gaya: phir do
     * cheezein ho jatin jo alag ho sakti hain (order kuch kahe, draft bill kuch
     * aur), aur unhein milate rehna khud ek nayi kharabi hai. Aaj ka poora
     * masla isi shakl ka tha — kitchen sheet ek jami hui nakal par chal raha
     * tha aur order aage nikal gaya tha. Is liye: EK hi sach — order.
     *
     * Khaane `documentAttributesFor()` se aate hain, yani wohi jo asli bill
     * istemaal karta hai. Graahak ka proforma aur us ka bill kabhi alag adad
     * nahi keh sakte.
     */
    public function proforma(CateringEvent $event): CateringFinalInvoice
    {
        $estimate = $event->currentEstimate;

        if (! $estimate) {
            throw new RuntimeException("Event {$event->event_no} has no quotation to bill.");
        }

        $advances = $event->advances()->orderBy('received_date')->get();
        $advanceTotal = round((float) $advances->sum('amount') - (float) $event->refunds()->sum('amount'), 2);
        $advanceApplied = round(min($advanceTotal, (float) $estimate->grand_total), 2);
        $balanceDue = round((float) $estimate->grand_total - $advanceTotal, 2);

        $proforma = new CateringFinalInvoice(
            $this->documentAttributesFor($event, $estimate, $advances, $advanceTotal, $advanceApplied, $balanceDue)
        );

        // Number nahi liya ja raha: ek proforma par qatar ka number kharch karna
        // us qatar me hamesha ka sooraakh chhod deta hai. Yehi faisla kitchen
        // sheet ke preview par bhi hai ("PREVIEW").
        $proforma->invoice_no = 'PROFORMA';
        $proforma->issued_at = app(\App\Support\TenantClock::class)->now();
        $proforma->setRelation('event', $event);

        return $proforma;
    }
    private function documentAttributesFor(
        CateringEvent $event,
        $estimate,
        $advances,
        float $advanceTotal,
        float $advanceApplied,
        float $balanceDue,
    ): array {
        return [
                'snapshot' => [
                    'event_no' => $event->event_no,
                    'estimate_version' => $estimate->version_no,
                    'customer_name' => $event->customer_name,
                    'customer_name_ur' => $event->customer_name_ur,
                    'customer_phone' => $event->customer_phone,
                    'customer_address' => $event->customer_address,
                    'event_type' => $event->event_type,
                    'event_date' => $event->event_date->toDateString(),
                    'service_time' => $event->service_time,
                    'venue' => $event->venue,
                    'pax' => $event->pax,
                    'lines' => $estimate->lines->map(fn ($line) => [
                        'item_name' => $line->item_name,
                        'item_name_ur' => $line->item_name_ur,
                        'quantity' => (float) $line->quantity,
                        'unit_code' => $line->unit_code,
                        'rate' => (float) $line->rate,
                        'amount' => (float) $line->amount,
                        'instructions' => $line->instructions,
                    ])->values()->all(),
                    'advances' => $advances->map(fn ($advance) => [
                        'received_date' => $advance->received_date->toDateString(),
                        'amount' => (float) $advance->amount,
                        'reference' => $advance->reference,
                    ])->values()->all(),
                ],
                'subtotal' => $estimate->subtotal,
                'service_charge_amount' => $estimate->service_charge_amount,
                'other_charge_label' => $estimate->other_charge_label,
                'other_charge_amount' => $estimate->other_charge_amount,
                'discount_amount' => $estimate->discount_amount,
                'tax_amount' => $estimate->tax_amount,
                'grand_total' => $estimate->grand_total,
                'advance_total' => $advanceTotal,
                'advance_applied' => $advanceApplied,
                'balance_due' => max($balanceDue, 0),
        ];
    }

    private function refuseBeforeTheEventDayHasPassed(CateringEvent $event, string $kaam = 'bill'): void
    {
        if (! $event->event_date) {
            return;
        }

        // Tareekh ki STRING par — dekho upar likhi hui wajah.
        $today = app(\App\Support\TenantClock::class)->now()->toDateString();

        if ($event->event_date->toDateString() < $today) {
            return;
        }

        throw new RuntimeException(
            "Event {$event->event_no} ka din ({$event->event_date->format('d M Y')}) abhi guzra nahi — "
            .($kaam === 'band'
                ? 'event guzarne ke baad hi band kiya ja sakta hai.'
                : 'event guzarne ke baad hi final invoice banta hai. Us se pehle order badla ja sakta hai aur Proforma chhapi ja sakti hai.')
        );
    }
    private function closeLocked(CateringEvent $event): CateringEvent
    {
        $this->locks->refreshEvent($event);

        $invoice = $event->finalInvoice()->first();
        if (! $invoice) {
            throw new RuntimeException("Event {$event->event_no} has no final invoice — issue it before closing.");
        }
        if ($event->status !== CateringEvent::STATUS_COMPLETED) {
            throw new RuntimeException("Event {$event->event_no} ({$event->status}) is not in a closable state.");
        }

        // CATERING-CLOSE-AFTER-EVENT-1 (8 Oct) — malik: "event close nahi
        // ho sakti jab tak event ka din guzar na jaye."
        //
        // `closed` ka matlab "paisa pura ho gaya" nahi, "is booking par ab koi
        // kaam baqi nahi" hai. Aakhri adaygi aksar event se PEHLE aa jati hai,
        // jabke khana abhi jana hota hai — aur band ho jane par wo booking
        // chalti hui fehrist se nikal jati hai aur kisi ko pata nahi chalta ke
        // us par kaam baqi tha.
        //
        // Prod par ye do baar ho chuka tha jab ye pehra nahi tha:
        // EV-20260909-0002 (event 15 Oct) aur EV-20261004-0157 (event 9 Oct)
        // dono apne din se pehle band kar di gayi thin.
        //
        // Tareekh TenantClock se — server ke waqt se nahi. Karachi me raat 2
        // baje server ka "kal" dukandar ka "aaj" hota hai.
        //
        // ⚠️ MUQABLA TAREEKH KI STRING PAR, LAMHON PAR NAHI. Pehli koshish me
        // maine `event_date->startOfDay()` ko `TenantClock::now()->startOfDay()`
        // se mila diya tha — aur test foran laal ho gaya. Wajah: `event_date`
        // ek DATE hai (UTC ki aadhi raat), jabke TenantClock ki aadhi raat
        // Karachi ki hoti hai, yani 19:00 UTC pichhle din. Natija ye tha ke
        // event ka apna din bhi "abhi aaya hi nahi" nikalta.
        //
        // Do alag timezone ke lamhe milana isi project me pehle bhi kaat chuka
        // hai. Jab sawal "kaun sa DIN" ho, to din hi milao.
        //
        // 10 Oct — hadd AB "din guzar jaye" hai, "din aa jaye" nahi. Malik:
        // "aaj 10 hai, aaj complete nahi hone dena tha." Pehle ye `> $today`
        // tha, yani event ke DIN bhi band ho jati — jab ke us din to khana
        // abhi ja raha hota hai. Dono darwaze (close aur invoice) ab ek hi
        // qaida lagate hain, warna wohi masla doosri shakl me wapas aata.
        $this->refuseBeforeTheEventDayHasPassed($event, 'band');

        $position = app(CateringFinancialPositionService::class)->position($event);

        if ($position['balance_due'] > 0) {
            throw new RuntimeException(
                "Event {$event->event_no} still has an outstanding balance of "
                .number_format((float) $position['balance_due'], 2)
                .' — record the final payment before closing.'
            );
        }

        // KASHIF-CATERING-CUSTOMER-CREDIT-1: a debt in the other direction blocks
        // closure just as firmly. Closing here would stamp "settled" on a booking
        // that is still holding the customer's money, and the liability would go
        // quiet behind a closed record.
        if ($position['customer_credit'] > 0) {
            throw new RuntimeException(
                "Event {$event->event_no} still owes the customer "
                .number_format((float) $position['customer_credit'], 2)
                .' — refund the credit before closing.'
            );
        }

        $event->forceFill(['status' => CateringEvent::STATUS_CLOSED, 'closed_at' => now()])->save();

        return $event;
    }
}
