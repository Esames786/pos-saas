<?php

namespace App\Services\Catering;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringProductionRelease;
use App\Models\Tenant\CateringProductionReleaseLine;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CATERING-SLICE-3: production release (spec §14) — an immutable snapshot of
 * WHAT to produce for an event. A separate catering business event; it never
 * creates kot_batches / print POS KOTs, never moves stock, and carries zero
 * customer pricing. Actual production stock issue is a future flow that must
 * go through the approved Inventory/Kitchen authority (spec §20).
 */
class CateringProductionReleaseService
{
    public function __construct(
        private readonly CateringNumberService $numbers,
        private readonly CateringRequirementService $requirements,
    ) {}

    /**
     * KITCHEN-SHEET-PREVIEW-1 — wohi parcha, release se PEHLE, sirf chhapne ke liye.
     *
     * Malik ne 27 September ko kaha: kitchen sheet production release se pehle
     * bhi chhap sake. Ye method release BANATI hai magar MEHFOOZ NAHI karti:
     * koi row nahi banti, release number kharch nahi hota, event ka status
     * nahi hilta, koi snapshot nahi jamta. Sirf wo dhaancha jo kaghaz maangta
     * hai.
     *
     * Lines wohi builder banata hai jo asli release chalata hai
     * (`lineAttributesFor`). Preview ke liye alag se lines likhna — production
     * label, Urdu ka silsila, "CUSTOMER SUPPLIES" wali satar, materials — un
     * dono kaghazon ko waqt ke saath alag kar deta: preview kuch kehta, asli
     * parcha kuch aur.
     *
     * release() wali shartein yahan JAAN-BOOJH KAR nahi lagayin (draft hona,
     * ya event ka confirmed na hona) — poora maqsad hi ye hai ke parcha pehle
     * dekha ja sake. Kaghaz khud oopar likhta hai ke wo abhi jaari nahi hua.
     */
    public function preview(CateringEvent $event): CateringProductionRelease
    {
        $estimate = $event->currentEstimate;
        if (! $estimate) {
            throw new RuntimeException("Event {$event->event_no} has no estimate to preview.");
        }

        $estimate->loadMissing('lines.costBlocks');

        $release = new CateringProductionRelease([
            'catering_event_id' => $event->id,
            'catering_estimate_id' => $estimate->id,
            'event_snapshot' => $this->eventSnapshotFor($event, $estimate),
            'requirements_snapshot' => $this->requirements->consolidatedForEstimate($estimate, $event->branch_id),
            'status' => CateringProductionRelease::STATUS_RELEASED,
        ]);

        // Sirf kaghaz par chhapne ke liye. release_no sequence se NAHI liya
        // gaya: ek preview par number kharch karna us qatar me hamesha ka
        // sooraakh chhod deta.
        $release->release_no = 'PREVIEW';
        $release->released_at = now();

        // Unsaved model par relation khud load nahi hoti, aur kaghaz
        // `$release->lines` parhta hai — is liye yahin bitha di jati hai.
        $release->setRelation('lines', new EloquentCollection(
            array_map(
                fn (array $attrs) => new CateringProductionReleaseLine($attrs),
                $this->lineAttributesFor($estimate)
            )
        ));
        $release->setRelation('event', $event);

        return $release;
    }

    /**
     * Wo parcha jo chhapega — aur us par HAMESHA aaj ki quotation.
     *
     * CATERING-SHEET-ALWAYS-CURRENT-1 (9 Oct). Malik: "kitchen sheet mai hamesha
     * updated data aana chahiye... mujhe itna status update na karna pare, auto
     * sab ho."
     *
     * Pehle parcha us release se banta tha jo kitchen ko BHEJI GAYI thi. Wo soch
     * ghalat nahi thi — jo kaghaz deewar par lag chuka us ka chup chaap badal
     * jana khatarnak hai — magar us ki qeemat ye thi ke quotation badalne par
     * parcha hamesha ke liye adhoora reh jata. EV-20261009-0216 par yehi hua:
     * release ke 4 minute baad do dish juriin aur parcha ek hi dish par atka
     * raha.
     *
     * Pehla ilaj ek button tha ("Send Updated Kitchen Sheet"). Malik ne use
     * radd kiya, aur theek kiya: poora masla hi ye tha ke kisi ne ek qadam
     * bhula diya, aur ilaj me ek aur qadam jorna usi ghalti ko dawat dena hai.
     *
     * Ab parcha SEEDHA maujooda quotation se banta hai. Release ka record
     * barqarar hai aur apna kaam karta rehta hai (maal nikalne ka snapshot,
     * aur "kab bheja tha" ka number) — magar wo ab ye tay nahi karta ke
     * kaghaz par kya chhapega.
     *
     * Jis booking ki kabhi release hui hi nahi, us par `null` — aur ye jaan
     * boojh kar hai. Pehle yahan `preview()` banaya ja raha tha, magar us ka
     * matlab tha ke JIS BOOKING KI QUOTATION HI NAHI us par poora safha phat
     * jata ("has no estimate to preview"). Bulk print aisi booking ko pehle
     * shaista tareeqe se chhor deta tha — "ye chhoot gayi" — aur wo rawaiya
     * wapas aana chahiye.
     *
     * Preview chahiye to pukarne wala khud maange; dono pukarne wale alag
     * cheez chahte hain aur ye farq unhi ka hai:
     *   • bulk print — kabhi release na hui ho to CHHOR do
     *   • print queue — preview bhej do
     */
    public function sheetFor(CateringEvent $event): ?CateringProductionRelease
    {
        $real = $event->currentRelease();

        if (! $real) {
            return null;
        }

        $estimate = $event->currentEstimate;

        if (! $estimate) {
            return $real;
        }

        // Asli release ka number aur waqt rehte hain (kaghaz par wohi chhapta
        // hai), magar lines aaj ki quotation ki.
        //
        // ⚠️ Ye badlav SIRF memory me hai — ye object kabhi save nahi hota, aur
        // hona bhi nahi chahiye: database me padi release ek jami hui gawahi hai
        // ke us waqt kitchen ko kya bheja gaya tha. Is liye yahan se aage koi
        // `save()` nahi, aur isi wajah se ye kaam ek alag method me hai jise
        // chhapne wale raaste hi bulate hain.
        $real->setRelation('lines', new EloquentCollection(
            array_map(
                fn (array $attrs) => new CateringProductionReleaseLine($attrs),
                $this->lineAttributesFor($estimate)
            )
        ));
        $real->event_snapshot = $this->eventSnapshotFor($event, $estimate);

        // "QUOTATION CHANGED AFTER THIS SHEET" ab jhoot hoga — lines to aaj ki
        // hi hain. Band usi `catering_estimate_id` par chalta hai, is liye wo
        // yahan bhi aaj wali par laga di jati hai.
        $real->catering_estimate_id = $estimate->id;
        $real->setRelation('event', $event);

        return $real;
    }
    public function release(CateringEvent $event, ?int $userId = null): CateringProductionRelease
    {
        $estimate = $event->currentEstimate;
        if (! $estimate) {
            throw new RuntimeException("Event {$event->event_no} has no estimate to release.");
        }
        if ($estimate->isDraft()) {
            throw new RuntimeException('Send/lock the estimate before releasing production.');
        }
        if (! in_array($event->status, [
            CateringEvent::STATUS_CONFIRMED,
            CateringEvent::STATUS_PRODUCTION_READY,
            CateringEvent::STATUS_QUOTED, // allow direct release for short-notice bookings
        ], true)) {
            throw new RuntimeException("Event {$event->event_no} ({$event->status}) cannot release production.");
        }

        // The line snapshot is read for every line below; loading it once keeps
        // a fifty-dish release from becoming fifty queries.
        $estimate->loadMissing('lines.costBlocks');

        $consolidated = $this->requirements->consolidatedForEstimate($estimate, $event->branch_id);

        return DB::connection('tenant')->transaction(function () use ($event, $estimate, $consolidated, $userId) {
            $release = CateringProductionRelease::create([
                'release_no' => $this->numbers->nextProductionReleaseNo(),
                'catering_event_id' => $event->id,
                'catering_estimate_id' => $estimate->id,
                'event_snapshot' => $this->eventSnapshotFor($event, $estimate),
                'requirements_snapshot' => $consolidated,
                'status' => CateringProductionRelease::STATUS_RELEASED,
                'released_at' => now(),
                'released_by_user_id' => $userId,
            ]);

            foreach ($this->lineAttributesFor($estimate) as $attrs) {
                CateringProductionReleaseLine::create(
                    $attrs + ['catering_production_release_id' => $release->id]
                );
            }

            $event->forceFill(['status' => CateringEvent::STATUS_RELEASED])->save();

            return $release;
        });
    }

    /**
     * Tqreeb ka wo hissa jo kaghaz par chhapta hai.
     *
     * Ye pehle `release()` ke andar likha tha. KITCHEN-SHEET-PREVIEW-1 me bahar
     * nikala gaya taake preview aur asli release DONO isi se banein — do jagah
     * do nakal rakhne ka anjaam yahi hota hai ke ek din venue ek kaghaz par
     * aata hai aur dusre par nahi.
     *
     * @return array<string, mixed>
     */
    private function eventSnapshotFor(CateringEvent $event, $estimate): array
    {
        return [
            'event_no' => $event->event_no,
            'customer_name' => $event->customer_name,
            'customer_name_ur' => $event->customer_name_ur,
            'customer_phone' => $event->customer_phone,
            'venue' => $event->venue,
            // KITCHEN-SHEET-A5-1: driver ko pata chahiye, aur khana nikalne ka
            // waqt bawarchi-khane ko. Dono parche par chhapte hain, is liye
            // dono snapshot me jamte hain — kaghaz us waqt ka sach dikhae jab
            // release hui thi, na ke aaj ka.
            'customer_address' => $event->customer_address,
            'dispatch_time' => $event->dispatch_time,
            // Sirf HAAN/NAHI. Raqam jaan-boojh kar nahi: kitchen sheet par
            // kabhi paisa nahi chhapta, aur malik ne bhi lafz maanga tha —
            // "jub service charges li gae tub service likha howe ae".
            'has_service_charge' => (float) ($estimate->service_charge_amount ?? 0) > 0,
            'event_date' => $event->event_date->toDateString(),
            'service_time' => $event->service_time,
            'pax' => $event->pax,
            'event_type' => $event->event_type,
            'estimate_version' => $estimate->version_no,
        ];
    }

    /**
     * Har line ka wo roop jo bawarchi-khana parhta hai — production label,
     * Urdu ka silsila, hidayaat, aur maal.
     *
     * Yehi hissa sab se zyada nazuk hai aur isi liye ek hi jagah rehta hai:
     * preview aur release dono isay chalate hain, sirf ek `release_id` ka
     * farq hota hai.
     *
     * @return list<array<string, mixed>>
     */
    private function lineAttributesFor($estimate): array
    {
        $out = [];

        foreach ($estimate->lines as $index => $line) {
            $profile = $line->product?->cateringProfile;

            $out[] = [
                'product_id' => $line->product_id,
                // Production label wins over the commercial name on the kitchen floor.
                'item_name' => $profile?->production_label ?: $line->item_name,
                // KASHIF-URDU-CARRY-1: the kitchen sheet prints Urdu when it HAS
                // Urdu. Production label first, then the line's own, then the
                // product book itself — a blank here was the only reason the
                // sheet read English on an Urdu sheet.
                'item_name_ur' => $profile?->production_label_ur
                    ?: ($line->item_name_ur ?: $this->productUrduName($line->product_id)),
                'quantity' => $line->quantity,
                'unit_code' => $line->unit_code,
                'production_station' => $profile?->production_station,
                // KASHIF-CATERING-INSTRUCTIONS-1: the line's managed selections
                // and free note as one string, then the dish profile's standing
                // instruction. Snapshotted as TEXT — the kitchen sheet stays
                // readable even if the vocabulary is edited later.
                // KITCHEN-SHEET-A5-1 (27 Sep) — is khaane me ab SIRF hidayaat.
                //
                // Yahan pehle "CUSTOMER SUPPLIES: Beef (With Bone) …" bhi
                // jorra jata tha. Malik ne kaha: "instruction mai sirf
                // instruction ayen (material ya cost block na ae)". Wo baat
                // ab bhi parche par hai — magar dish ke neeche, chhoti si
                // shakl me: "Party 42 KG" / "Own 84 KG" (dekhein
                // line-materials ka compact roop). Ek hi baat do jagah likhna
                // parche ko lamba karta tha aur A5 par wo gunjaish hai nahi.
                'instructions' => trim(implode("\n", array_filter([
                    $line->instructionSummary(),
                    $profile?->instructions,
                ]))) ?: null,
                // KASHIF-KITCHEN-MATERIALS-1: what this dish takes and who
                // brings it, frozen with the rest of the release. The kitchen
                // sheet reads THIS, never the live quotation — a sheet on the
                // wall must not change because someone edited the estimate.
                'materials_snapshot' => $line->materialSummary() ?: null,
                'sort_order' => $index,
            ];
        }

        return $out;
    }

    /** The product book's Urdu name, cached per request. */
    /** @var array<int, string|null> Read once per product, per request. */
    private array $urduNameCache = [];

    private function productUrduName(?int $productId): ?string
    {
        if (! $productId) {
            return null;
        }

        return $this->urduNameCache[$productId] ??= \Illuminate\Support\Facades\DB::connection('tenant')
            ->table('product_translations')
            ->where('product_id', $productId)
            ->where('language_code', 'ur')
            ->value('name');
    }
}
