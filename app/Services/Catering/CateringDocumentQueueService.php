<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringProductionRelease;
use App\Models\Tenant\CateringSetting;
use App\Models\Tenant\Printer;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\PrintPrepare;
use App\Services\Catering\CateringProductionReleaseService;
use App\Services\Printing\PrintJobFactory;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * CATERING-SEND-TO-PRINTER-1 — A4/A5 document seedha office ke printer par.
 *
 * Malik (4 Oct): "client confuse ho raha hai, bar bar A4 / A5 select karna
 * parta hai."
 *
 * Asal shikayat kagaz ke size ki thi, network ki nahi. Chrome har printer ke
 * liye AAKHRI manual setting yaad rakhta hai aur wo document ki apni marzi par
 * bhaari parti hai — ek baar A4 chun liya to kitchen sheet bhi A4 par khulti
 * hai, chahe document `@page { size: A5 portrait }` keh raha ho.
 *
 * Is liye yahan kagaz ka size APP tay karti hai aur JOB KE SAATH jata hai.
 * Operator sirf printer chunta hai; A4/A5 wo kabhi nahi dekhta.
 *
 * ── TEEN FAISLE JO IS CLASS KI SHAKAL BANATE HAIN ─────────────────────────
 *
 * 1. HTML YAHIN JAMA HO JATA HAI, bhejte waqt nahi banta. Wohi qaida jo
 *    production ticket par pehle se hai: "payload is frozen at queue time".
 *    Jo aap ne qatar me lagaya wohi chhapega, chahe booking baad me badal
 *    jaye. Zimni fayda bara hai: agent ko document maangne ka koi naya
 *    endpoint nahi chahiye — HTML usi `raw_payload` me jata hai jo wo pehle se
 *    har job ke saath leta hai. Ek poora auth/route ka darwaza banane ki
 *    zarurat hi nahi pari.
 *
 * 2. RENDER AGENT KE CHROME SE HOGA, SERVER PAR NAHI. Server par PDF banana
 *    aasan tha aur ghalat hota: `dompdf has no complex-script shaping engine`
 *    — Urdu toot jati. Agent ke PC par wohi Chrome chalta hai jis se aaj
 *    chhapta hai, is liye output bilkul wohi rahega. Ye service sirf HTML
 *    deti hai; PDF agent banata hai.
 *
 * 3. SIRF `windows` QISAM KE PRINTER. Ek A4 laser par ESC/POS bytes bhej dena
 *    safhe bhar kachra chhapta hai, aur ye ghalti purane setup me mumkin thi.
 *    Yahan wo shart sarih hai aur `assertCanPrintDocuments()` me kaat-ti hai.
 */
class CateringDocumentQueueService
{
    public const KIND_KITCHEN_SHEET = 'kitchen_sheet';

    public const KIND_QUOTATION = 'quotation';

    public const KIND_ADDRESS_SHEET = 'address_sheet';

    /** Final invoice — A4, aur wo kisi setting se nahi badalta. */
    public const KIND_FINAL_INVOICE = 'final_invoice';

    /** Proforma — event se pehle wala bill. Wohi kaghaz jo invoice ka: A4. */
    public const KIND_PROFORMA = 'proforma';

    public const KINDS = [self::KIND_KITCHEN_SHEET, self::KIND_QUOTATION, self::KIND_ADDRESS_SHEET];

    /** `print_jobs.document_type` — POS ke `receipt`/`kot` se bilkul alag lane. */
    public const DOCUMENT_TYPE = 'catering_document';

    /**
     * Kis document ka kaghaz kaun sa — EK hi jagah.
     *
     * Malik (10 Oct): "kitchen sheet hamesha A5 par, baqi quotation / invoice /
     * address sab A4 par. Jo printer bhi chunoon, size wohi rehna chahiye."
     *
     * Ye qaida pehle se chal raha tha — teenon queue methods apna apna kaghaz
     * yahin se uthate thay — magar wo faisla TEEN jagah likha tha, aur SCREEN
     * par kahin likha hi nahi tha. Is liye operator ko printer ke naam par
     * jana parta tha ("Office - HP P2055dn (A4)"), aur wo naam jhoot bolta
     * hai: usi printer par kitchen sheet bhejo to wo A5 hi nikalti hai.
     *
     * Ab faisla yahan ek baar hota hai, aur Send-to-network wala control bhi
     * YAHIN se parh kar operator ko dikhata hai. Do jagah do jawab nahi ho
     * sakte, kyunke jagah ek hi hai.
     */
    public static function paperFor(string $kind): string
    {
        return match ($kind) {
            self::KIND_KITCHEN_SHEET => CateringSetting::tenantDefault()->kitchen_sheet_paper ?: 'a5_portrait',
            self::KIND_QUOTATION => CateringSetting::tenantDefault()->quotation_paper ?: 'a4_portrait',
            // Address sheet ka kaghaz setting se NAHI aata: wo document ke code
            // me A4 tay hai, aur use kahin aur se badalne dena do sach bana
            // deta — screen par kuch, kaghaz par kuch.
            //
            // Final invoice YAHAN SAAF likhi hui hai, `default` par chhori nahi
            // gayi. Malik ne 11 Oct ko poochha tha: "normal aur fully paid dono
            // me A4 aayega?" Us sawal ka jawab parhne wale ko DIKHNA chahiye,
            // kisi `default` me chhupa nahi hona chahiye.
            self::KIND_FINAL_INVOICE, self::KIND_PROFORMA => 'a4_portrait',
            default => 'a4_portrait',
        };
    }

    /** Wohi faisla, operator ki zubaan me: "A4" ya "A5". */
    public static function paperLabel(string $kind): string
    {
        return str_starts_with(self::paperFor($kind), 'a5') ? 'A5' : 'A4';
    }
    /**
     * Kitchen sheet — production release ka parcha. Kagaz tenant ki setting se
     * (`kitchen_sheet_paper`, aam tor par A5).
     */
    public function queueKitchenSheet(
        CateringProductionRelease $release,
        Printer $printer,
        ?int $userId = null,
        bool $isReprint = false,
        ?string $lang = null,
    ): PrintJob {
        $release->loadMissing(['lines', 'event']);

        return $this->queue(
            kind: self::KIND_KITCHEN_SHEET,
            printer: $printer,
            html: view('tenant.catering.documents.kitchen-sheet', [
                'release' => $release,
                'lang' => $this->language($lang),
                'businessName' => $this->businessName(),
            ])->render(),
            paper: self::paperFor(self::KIND_KITCHEN_SHEET),
            referenceType: 'catering_production_release',
            referenceId: (int) $release->id,
            referenceNo: $release->release_no,
            branchId: $release->event?->branch_id,
            userId: $userId,
            isReprint: $isReprint,
        );
    }

    /**
     * Kitchen sheet, EVENT se — wohi parcha jo preview safhe par dikhta hai.
     *
     * Ye `queueKitchenSheet()` se alag is liye hai ke release se PEHLE bhi
     * parcha chhapta hai, aur us waqt wo ek PREVIEW hota hai jo kahin mehfooz
     * nahi — us ka koi id hi nahi hota. Preview safha khud yehi faisla karta
     * hai (jaari shuda release, warna preview), aur yahan wohi faisla dohraya
     * jata hai taake jo screen par dikhe wohi printer par jaye.
     *
     * Reference EVENT par rakha jata hai, release par nahi: preview ka koi id
     * nahi hota, aur idempotency ko kisi aise adad par khara karna jo mojood
     * hi na ho, do kaghaz nikalwa deta.
     */
    public function queueKitchenSheetForEvent(
        CateringEvent $event,
        Printer $printer,
        ?int $userId = null,
        bool $isReprint = false,
        ?string $lang = null,
    ): PrintJob {
        return $this->queueRendered($this->document(self::KIND_KITCHEN_SHEET, $event, $lang), $printer, $userId, $isReprint);
    }

    /** @return array{kind:string, html:string, paper:string, lang:?string, reference_type:string, reference_id:int, reference_no:?string, branch_id:?int} */
    private function kitchenSheetDocument(CateringEvent $event, ?string $lang): array
    {
        $event->loadMissing(['productionReleases.lines', 'productionReleases.event']);

        // CATERING-SHEET-ALWAYS-CURRENT-1 — parcha hamesha aaj ki quotation se.
        // `sheetFor()` khud hi preview par gir jata hai jab booking kabhi
        // release hui hi na ho, is liye yahan doosri shart ki zaroorat nahi.
        $releases = app(CateringProductionReleaseService::class);
        $release = $releases->sheetFor($event) ?? $releases->preview($event);

        if (! $release->relationLoaded('event')) {
            $release->setRelation('event', $event);
        }

        $lang = $this->language($lang);

        return [
            'kind' => self::KIND_KITCHEN_SHEET,
            'html' => view('tenant.catering.documents.kitchen-sheet', [
                'release' => $release,
                'lang' => $lang,
                'businessName' => $this->businessName(),
            ])->render(),
            'paper' => self::paperFor(self::KIND_KITCHEN_SHEET),
            'lang' => $lang,
            'reference_type' => 'catering_event',
            'reference_id' => (int) $event->id,
            'reference_no' => $event->event_no,
            'branch_id' => $event->branch_id,
        ];
    }

    /** Quotation — kagaz `quotation_paper` se (aam tor par A4). */
    public function queueQuotation(
        CateringEstimate $estimate,
        Printer $printer,
        ?int $userId = null,
        bool $isReprint = false,
        ?string $lang = null,
    ): PrintJob {
        return $this->queueRendered($this->document(self::KIND_QUOTATION, $estimate, $lang), $printer, $userId, $isReprint);
    }

    private function quotationDocument(CateringEstimate $estimate, ?string $lang): array
    {
        $estimate->loadMissing(['event.customer', 'lines']);

        // CAT-DOC-001 ka wohi qaida: "graahak par kitna baqi hai" ka ek hi
        // jawab hai aur wo yahan DOBARA nahi likha ja raha. Pehle ye kaghaz
        // gross advances se hisaab lagata tha, is liye jis booking par refund
        // ho chuka hota us ka parcha zyada baqi dikhata — aur saath khari
        // screen us se ikhtilaf karti.
        $position = app(CateringFinancialPositionService::class)->position($estimate->event);
        $lang = $this->language($lang);

        return [
            'kind' => self::KIND_QUOTATION,
            'html' => view('tenant.catering.documents.estimate', [
                'estimate' => $estimate,
                'event' => $estimate->event,
                'lang' => $lang,
                'position' => $position,
                'advanceTotal' => $position['net_received'],
                'businessName' => $this->businessName(),
            ])->render(),
            'paper' => self::paperFor(self::KIND_QUOTATION),
            'lang' => $lang,
            'reference_type' => 'catering_estimate',
            'reference_id' => (int) $estimate->id,
            'reference_no' => $estimate->event?->event_no.' / Q'.$estimate->version_no,
            'branch_id' => $estimate->event?->branch_id,
        ];
    }

    /**
     * Final invoice — A4 par, poora document, agent ke Chrome se.
     *
     * ── 11 OCTOBER: YE METHOD KYUN BANI ──────────────────────────────────
     *
     * `printFinalInvoice()` HAR printer ke liye `CateringDocumentPrintService`
     * bulata tha, jo `raw_payload` me ESC/POS bytes bharta hai (wo THERMAL
     * parchi ke liye bana tha) aur printer ki qisam dekhta hi nahi.
     *
     * Agent us job ko `printer_type === 'windows'` dekh kar document ke raaste
     * par bhejta hai: raw_payload ko `.html` me likh kar Chrome ko deta hai.
     * Chrome un control bytes ko matn samajh kar chhap deta hai — A4 ke kaghaz
     * par koora. Test ne ye code parh kar nahi, CHALA KAR dikhaya: payload
     * `1d2111 1b4501 ... 1d564200` nikla, yani GS! / ESC E / GS V.
     *
     * Quotation par ye branch PEHLE SE mojood thi (`printEstimate()` me).
     * Invoice ko wo kabhi di hi nahi gayi — ek jagah kiya, doosri reh gayi.
     *
     * Thermal ka purana raasta waisa hi hai: wahan ESC/POS hi theek hai.
     */
    public function queueFinalInvoice(
        \App\Models\Tenant\CateringFinalInvoice $invoice,
        Printer $printer,
        string $lang = 'en',
        ?int $userId = null,
        bool $isReprint = false,
    ): PrintJob {
        return $this->queueRendered($this->document(self::KIND_FINAL_INVOICE, $invoice, $lang), $printer, $userId, $isReprint);
    }

    private function finalInvoiceDocument(\App\Models\Tenant\CateringFinalInvoice $invoice, string $lang): array
    {
        $invoice->loadMissing(['event']);

        return [
            'kind' => self::KIND_FINAL_INVOICE,
            'html' => view('tenant.catering.documents.final-invoice', [
                'invoice' => $invoice,
                'lang' => $lang,
                'businessName' => $this->businessName(),
            ])->render(),
            'paper' => self::paperFor(self::KIND_FINAL_INVOICE),
            'lang' => $lang,
            'reference_type' => 'catering_final_invoice',
            'reference_id' => (int) $invoice->id,
            'reference_no' => $invoice->invoice_no,
            'branch_id' => $invoice->event?->branch_id,
        ];
    }

    /**
     * Proforma — event se pehle wala bill, maujooda order se.
     *
     * WOHI view jo asli invoice chalati hai, aur wohi kaghaz (A4). Do view ya
     * do naap rakhne par ek din unhen milate rehna bhool jata hai aur graahak
     * ke do kaghaz alag dikhne lagte hain. Kaghaz khud farq bata deta hai,
     * kyunke `$invoice->exists` jhoot hai.
     *
     * EVENT leti hai, invoice nahi — proforma kahin mehfooz hoti hi nahi. Isi
     * liye ye bulk endpoint par theek baithti hai: wahan `ids[]` hamesha event
     * ki hoti hain. Is ka apna route BANAYA NAHI gaya, kyunke naya route naya
     * permission maangta hai jo har role par alag se dena parta — aur yahan us
     * ki zaroorat hi nahi thi.
     */
    public function queueProformaInvoice(
        CateringEvent $event,
        Printer $printer,
        string $lang = 'en',
        ?int $userId = null,
        bool $isReprint = false,
    ): PrintJob {
        return $this->queueRendered($this->document(self::KIND_PROFORMA, $event, $lang), $printer, $userId, $isReprint);
    }

    private function proformaDocument(CateringEvent $event, string $lang): array
    {
        $proforma = app(CateringFinalInvoiceService::class)->proforma($event);

        return [
            'kind' => self::KIND_PROFORMA,
            'html' => view('tenant.catering.documents.final-invoice', [
                'invoice' => $proforma,
                'lang' => $lang,
                'businessName' => $this->businessName(),
            ])->render(),
            'paper' => self::paperFor(self::KIND_PROFORMA),
            'lang' => $lang,
            'reference_type' => 'catering_event',
            'reference_id' => (int) $event->id,
            'reference_no' => 'PROFORMA-'.$event->event_no,
            'branch_id' => $event->branch_id,
        ];
    }

    /**
     * Address sheet — delivery ki fehrist. Is ka kagaz tenant ki setting se
     * NAHI aata: wo document ke code me A4 tay hai, aur yahan us ko doosri
     * jagah dohrana do jagah do jawab bana deta.
     */
    public function queueAddressSheet(
        CateringEvent $event,
        Printer $printer,
        ?int $userId = null,
        bool $isReprint = false,
    ): PrintJob {
        return $this->queueRendered($this->document(self::KIND_ADDRESS_SHEET, $event, null), $printer, $userId, $isReprint);
    }

    private function addressSheetDocument(CateringEvent $event): array
    {
        return [
            'kind' => self::KIND_ADDRESS_SHEET,
            'html' => view('tenant.catering.documents.address-sheet', [
                'events' => collect([$event]),
                'businessName' => $this->businessName(),
            ])->render(),
            'paper' => self::paperFor(self::KIND_ADDRESS_SHEET),
            'lang' => null,
            'reference_type' => 'catering_event',
            'reference_id' => (int) $event->id,
            'reference_no' => $event->event_no,
            'branch_id' => $event->branch_id,
        ];
    }

    // ── PRINT-PREPARE-1 — ek jagah render, Send aur tayyari dono ke liye ────────

    /**
     * Har document EK hi raaste se banta hai — Send (`queue…`) bhi, screen khulte hi tayyari (`prepare`) bhi.
     * Do raaste hote to ek din dono ka HTML alag ho jata, fingerprint kabhi na milta, aur tayyari chup-chaap bekaar
     * ho jati (kaghaz phir bhi sahi nikalta, magar dheema). Ek raasta = ek HTML.
     *
     * @param  CateringEvent|CateringEstimate|\App\Models\Tenant\CateringFinalInvoice  $subject
     */
    public function document(string $kind, $subject, ?string $lang = null): array
    {
        return match ($kind) {
            self::KIND_KITCHEN_SHEET => $this->kitchenSheetDocument($subject, $lang),
            self::KIND_QUOTATION => $this->quotationDocument($subject, $lang),
            // Invoice/proforma: zubaan na di gayi ho to pehle jaisa English (CATERING-SEND-LANGUAGE-1).
            self::KIND_FINAL_INVOICE => $this->finalInvoiceDocument($subject, $lang ?? 'en'),
            self::KIND_PROFORMA => $this->proformaDocument($subject, $lang ?? 'en'),
            self::KIND_ADDRESS_SHEET => $this->addressSheetDocument($subject),
            default => throw new RuntimeException("Unknown document kind [{$kind}]."),
        };
    }

    /**
     * Parche ki pehchan — kaghaz ka matn, "chhapne ka waqt" HATA KAR, aur kaghaz ka naap.
     *
     * Waqt is liye nikala jata hai ke wohi parcha do minute baad bhi wohi rahe; warna har minute naya fingerprint
     * banta aur tayyar PDF kabhi kaam na aati. Waqt `partials/printed-at` ke `<!--printed-at-->` nishanon ke beech hai.
     * Baqi ek huroof bhi badla (rate, item, zubaan, naam) to fingerprint badal jata hai — purani PDF kabhi nahi chhapti.
     */
    public static function fingerprint(string $html, string $paper): string
    {
        $stable = preg_replace('/<!--printed-at-->.*?<!--\/printed-at-->/s', '', $html) ?? $html;

        return hash('sha256', $paper."\n".$stable);
    }

    private function queueRendered(array $doc, Printer $printer, ?int $userId, bool $isReprint): PrintJob
    {
        return $this->queue(
            kind: $doc['kind'],
            printer: $printer,
            html: $doc['html'],
            paper: $doc['paper'],
            referenceType: $doc['reference_type'],
            referenceId: $doc['reference_id'],
            referenceNo: $doc['reference_no'],
            branchId: $doc['branch_id'],
            userId: $userId,
            isReprint: $isReprint,
        );
    }

    /**
     * Screen khulte hi: agent ke liye "PDF bana kar rakho, chhapo mat".
     *
     * Wohi parcha (wohi fingerprint) dobara khula to nayi row nahi — wohi lauti. Nayi tayyari sirf tab:
     *  - pehli dafa;
     *  - pichli tayyar PDF `FRESH_MINUTES` se purani ho (kaghaz par waqt purana na aaye);
     *  - nakaam hui thi;
     *  - user ne ↻ dabaya (`$force`).
     * Order badla to HTML badla → fingerprint naya → nayi row; agent usi document (`doc_key`) ki purani PDF mitata hai.
     */
    public function prepare(string $kind, $subject, Printer $printer, ?string $lang = null, bool $force = false): PrintPrepare
    {
        $this->assertCanPrintDocuments($printer);

        $doc = $this->document($kind, $subject, $lang);
        $fingerprint = self::fingerprint($doc['html'], $doc['paper']);

        $row = PrintPrepare::where('fingerprint', $fingerprint)->first();
        if ($row && ! $force) {
            $inFlight = $row->status === PrintPrepare::STATUS_QUEUED;
            if ($row->isFreshReady() || $inFlight) {
                return $row;
            }
        }

        $attributes = [
            'printer_id' => $printer->id,
            'branch_id' => $doc['branch_id'],
            'doc_key' => self::docKey($doc),
            'paper' => $doc['paper'],
            'html' => $doc['html'],
            'status' => PrintPrepare::STATUS_QUEUED,
            'claimed_by_agent_id' => null,
            'claimed_at' => null,
            'ready_at' => null,
            'error' => null,
        ];

        if ($row) {
            $row->update($attributes);

            return $row->refresh();
        }

        try {
            return PrintPrepare::create($attributes + ['fingerprint' => $fingerprint]);
        } catch (QueryException $e) {
            // Do tab ek saath khule: doosri ne isi lamhe wohi fingerprint likh diya.
            return PrintPrepare::where('fingerprint', $fingerprint)->firstOrFail();
        }
    }

    /**
     * Khula safha har kuch second poochhta hai: "mera parcha tayyar hai? aur kya order ab bhi wohi hai?"
     *
     * Document ABHI dobara render hota hai aur fingerprint milaya jata hai — is liye order kisi bhi raaste se badla
     * ho (punch, edit, revision, restore…), pakda jata hai; har save par alag pehra lagane ki zaroorat nahi.
     * `$known` = jo fingerprint safhe ne pehle suna tha. Badla to `changed = true` aur nayi tayyari khud lag jati hai.
     *
     * @return array{state:string, fingerprint:string, changed:bool, ready_at:?string, agent_online:bool}
     */
    public function prepareStatus(string $kind, $subject, Printer $printer, ?string $lang = null, ?string $known = null): array
    {
        $doc = $this->document($kind, $subject, $lang);
        $fingerprint = self::fingerprint($doc['html'], $doc['paper']);
        $changed = $known !== null && $known !== '' && ! hash_equals($known, $fingerprint);

        $row = PrintPrepare::where('fingerprint', $fingerprint)->first();
        if ($changed || ! $row || (! $row->isFreshReady() && $row->status !== PrintPrepare::STATUS_QUEUED)) {
            $row = $this->prepare($kind, $subject, $printer, $lang);
        }

        return [
            'state' => $row->isFreshReady() ? 'ready' : ($row->status === PrintPrepare::STATUS_FAILED ? 'failed' : 'preparing'),
            'fingerprint' => $fingerprint,
            'changed' => $changed,
            'ready_at' => $row->ready_at?->toIso8601String(),
            'agent_online' => $this->agentOnlineFor($printer, $doc['branch_id']),
        ];
    }

    private static function docKey(array $doc): string
    {
        return $doc['kind'].':'.$doc['reference_id'].':'.($doc['lang'] ?? '-');
    }

    /** Is printer ka parcha uthane wala koi agent pichle 30 second me bola ho. */
    public function agentOnlineFor(Printer $printer, ?int $branchId): bool
    {
        return \App\Models\Tenant\PrintAgent::query()
            ->where('is_active', true)
            ->where('last_seen_at', '>=', now()->subSeconds(30))
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->exists();
    }

    /**
     * Wo printer jo A4/A5 document chhap sakta hai — aur koi nahi.
     *
     * Mana karna yahan AHEM hai: ek A4 laser ke port 9100 par ESC/POS bytes
     * bhej dena safhe bhar kachra chhapta hai, aur wo soorat dekhne me "feature
     * chal gaya" lagti hai.
     */
    public function assertCanPrintDocuments(Printer $printer): void
    {
        if (! $printer->is_active) {
            throw new RuntimeException('Ye printer active nahi hai.');
        }

        if ($printer->printer_type !== Printer::TYPE_WINDOWS) {
            throw new RuntimeException(
                'Ye printer A4/A5 document nahi chhap sakta. Document ke liye printer ki qisam '
                .'"Windows" honi chahiye — thermal printer par ye kaghaz nahi ban sakta.'
            );
        }

        if (trim((string) $printer->windows_printer_name) === '') {
            throw new RuntimeException(
                'Is printer par Windows wala naam likha hi nahi. Agent usi naam se printer pehchanta hai.'
            );
        }
    }

    // ── andar ka kaam ──────────────────────────────────────────────────────

    private function queue(
        string $kind,
        Printer $printer,
        string $html,
        string $paper,
        string $referenceType,
        int $referenceId,
        ?string $referenceNo,
        ?int $branchId,
        ?int $userId,
        bool $isReprint,
    ): PrintJob {
        $this->assertCanPrintDocuments($printer);

        // Idempotency: ek hi document, ek hi printer par, do baar qatar me
        // nahi lagta. Sarih "reprint" apni alag copy banata hai.
        $base = 'catering-doc:'.$kind.':'.$referenceId.':printer-'.$printer->id;
        $copyNo = $isReprint ? $this->nextCopyNo($kind, $referenceId, (int) $printer->id) : 1;
        $logicalKey = $isReprint ? $base.':copy-'.$copyNo : $base;

        $attributes = [
            'logical_key' => $logicalKey,
            'copy_no' => $copyNo,
            'branch_id' => $branchId,
            'printer_id' => $printer->id,
            'document_type' => self::DOCUMENT_TYPE,
            'print_status' => 'queued',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_no' => $referenceNo,
            // Agent ko jo chahiye, aur kuch nahi. Kagaz yahan se jata hai —
            // yehi is poore kaam ka maqsad hai.
            'payload' => [
                'kind' => $kind,
                'render' => 'html',
                'paper' => $paper,
                'paper_css' => CateringSetting::cssPageSize($paper, 'A4 portrait'),
                'windows_printer_name' => $printer->windows_printer_name,
                'document_no' => $referenceNo,
                // PRINT-PREPARE-1 — agent ke paas isi fingerprint ki taaza PDF pehle se bani ho to wohi chhapta hai
                // (Chrome ka kaam bach jata hai). Na ho to HTML se banata hai — purana agent ise dekhta hi nahi.
                'fingerprint' => self::fingerprint($html, $paper),
            ],
            // Poora HTML, abhi ke abhi jama. Agent ise Chrome se PDF banata hai.
            'raw_payload' => $html,
            'created_by_user_id' => $userId,
        ];

        try {
            return app(PrintJobFactory::class)->create($attributes, 'CD');
        } catch (QueryException $exception) {
            // Wohi document dobara maanga gaya — jo job pehle se qatar me hai
            // wahi lauta do, doosra kaghaz nahi.
            $existing = PrintJob::where('logical_key', $logicalKey)->first();
            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function nextCopyNo(string $kind, int $referenceId, int $printerId): int
    {
        return (int) PrintJob::query()
            ->where('document_type', self::DOCUMENT_TYPE)
            ->where('reference_id', $referenceId)
            ->where('printer_id', $printerId)
            ->max('copy_no') + 1;
    }

    /**
     * CATERING-SEND-LANGUAGE-1 (11 Nov) — jo zubaan SAFHE par chuni gayi, wohi printer par.
     *
     * Client: "kitchen sheet Urdu ya sirf English select karo, send to network
     * hamesha English + Urdu bhejta hai." Wajah: "Send to network" ka form zubaan
     * bhejta hi nahi tha, aur yahan har baar Catering Settings ki default
     * (`print_language_profile`, Kashif Kitchen par "both") parhi jati thi. Screen
     * par Urdu, kaghaz par dono — preview aur printer do alag sach.
     *
     * Ab caller ki di hui zubaan pehle; sirf tab default jab koi zubaan aaye hi
     * nahi (booking screen ka chhota Print button, jahan zubaan chunne ka khaana
     * hai hi nahi — wo pehle jaisa rehta hai). Ghalat/anjaan qeemat bhi default par.
     */
    private function language(?string $chosen = null): string
    {
        if (in_array($chosen, CateringSetting::PRINT_PROFILES, true)) {
            return $chosen;
        }

        return CateringSetting::tenantDefault()->print_language_profile ?? 'en';
    }

    private function businessName(): string
    {
        try {
            return app('tenant')->business_name ?? config('saas.brand_name', 'Bingoo');
        } catch (\Throwable) {
            return config('saas.brand_name', 'Bingoo');
        }
    }
}
