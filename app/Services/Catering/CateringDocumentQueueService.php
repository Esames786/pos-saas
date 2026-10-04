<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringProductionRelease;
use App\Models\Tenant\CateringSetting;
use App\Models\Tenant\Printer;
use App\Models\Tenant\PrintJob;
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

    public const KINDS = [self::KIND_KITCHEN_SHEET, self::KIND_QUOTATION, self::KIND_ADDRESS_SHEET];

    /** `print_jobs.document_type` — POS ke `receipt`/`kot` se bilkul alag lane. */
    public const DOCUMENT_TYPE = 'catering_document';

    /**
     * Kitchen sheet — production release ka parcha. Kagaz tenant ki setting se
     * (`kitchen_sheet_paper`, aam tor par A5).
     */
    public function queueKitchenSheet(
        CateringProductionRelease $release,
        Printer $printer,
        ?int $userId = null,
        bool $isReprint = false,
    ): PrintJob {
        $release->loadMissing(['lines', 'event']);

        return $this->queue(
            kind: self::KIND_KITCHEN_SHEET,
            printer: $printer,
            html: view('tenant.catering.documents.kitchen-sheet', [
                'release' => $release,
                'lang' => $this->language(),
                'businessName' => $this->businessName(),
            ])->render(),
            paper: CateringSetting::tenantDefault()->kitchen_sheet_paper ?: 'a5_portrait',
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
    ): PrintJob {
        $event->loadMissing(['productionReleases.lines', 'productionReleases.event']);

        $release = $event->productionReleases
            ->where('status', 'released')
            ->sortByDesc('released_at')
            ->first()
            ?? app(CateringProductionReleaseService::class)->preview($event);

        if (! $release->relationLoaded('event')) {
            $release->setRelation('event', $event);
        }

        return $this->queue(
            kind: self::KIND_KITCHEN_SHEET,
            printer: $printer,
            html: view('tenant.catering.documents.kitchen-sheet', [
                'release' => $release,
                'lang' => $this->language(),
                'businessName' => $this->businessName(),
            ])->render(),
            paper: CateringSetting::tenantDefault()->kitchen_sheet_paper ?: 'a5_portrait',
            referenceType: 'catering_event',
            referenceId: (int) $event->id,
            referenceNo: $event->event_no,
            branchId: $event->branch_id,
            userId: $userId,
            isReprint: $isReprint,
        );
    }

    /** Quotation — kagaz `quotation_paper` se (aam tor par A4). */
    public function queueQuotation(
        CateringEstimate $estimate,
        Printer $printer,
        ?int $userId = null,
        bool $isReprint = false,
    ): PrintJob {
        $estimate->loadMissing(['event.customer', 'lines']);

        // CAT-DOC-001 ka wohi qaida: "graahak par kitna baqi hai" ka ek hi
        // jawab hai aur wo yahan DOBARA nahi likha ja raha. Pehle ye kaghaz
        // gross advances se hisaab lagata tha, is liye jis booking par refund
        // ho chuka hota us ka parcha zyada baqi dikhata — aur saath khari
        // screen us se ikhtilaf karti.
        $position = app(CateringFinancialPositionService::class)->position($estimate->event);

        return $this->queue(
            kind: self::KIND_QUOTATION,
            printer: $printer,
            html: view('tenant.catering.documents.estimate', [
                'estimate' => $estimate,
                'event' => $estimate->event,
                'lang' => $this->language(),
                'position' => $position,
                'advanceTotal' => $position['net_received'],
                'businessName' => $this->businessName(),
            ])->render(),
            paper: CateringSetting::tenantDefault()->quotation_paper ?: 'a4_portrait',
            referenceType: 'catering_estimate',
            referenceId: (int) $estimate->id,
            referenceNo: $estimate->event?->event_no.' / Q'.$estimate->version_no,
            branchId: $estimate->event?->branch_id,
            userId: $userId,
            isReprint: $isReprint,
        );
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
        return $this->queue(
            kind: self::KIND_ADDRESS_SHEET,
            printer: $printer,
            html: view('tenant.catering.documents.address-sheet', [
                'events' => collect([$event]),
                'businessName' => $this->businessName(),
            ])->render(),
            paper: 'a4_portrait',
            referenceType: 'catering_event',
            referenceId: (int) $event->id,
            referenceNo: $event->event_no,
            branchId: $event->branch_id,
            userId: $userId,
            isReprint: $isReprint,
        );
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

    private function language(): string
    {
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
