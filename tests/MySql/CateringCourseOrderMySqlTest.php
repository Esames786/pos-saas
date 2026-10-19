<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use App\Support\Catering\CourseOrder;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-COURSE-ORDER-1 — kaghaz khane ki tarteeb me chhape.
 *
 * Client (21 Sep) ne quotation ki tasveer par laal daira laga kar sequence
 * maangi: starter, biryani, gravy, BBQ, fried, sideline, dessert, nan, raita,
 * salad, tea, pan. Ab tak lines punch order me chhapti thin, is liye ek hi
 * parche par BBQ, phir meetha, phir chatni, phir wapas fried aata tha.
 *
 * Yahan sirf helper ko nahi jaancha ja raha. Helper ka sahi hona aur KAGHAZ ka
 * sahi hona do alag baatein hain — is codebase me do martaba aisa ho chuka hai
 * ke guard ne query dobara likh li aur asli screen tooti rahi. Is liye neeche
 * asli Blade render hoti hai aur uske HTML me tarteeb parkhi jati hai.
 */
class CateringCourseOrderMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEvent $event;

    private CateringEstimate $estimate;

    /** @var array<string, int> category ka naam => product id */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_production_release_lines', 'catering_production_releases',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $branchId = $this->makeBranch();
        $unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Kashif Kitchen ki asli tarteeb, prod se li gayi (19 categories, ye un
        // me se wo hain jo client ki list me hain).
        $courses = [
            'STARTERS' => 1,
            'RICE' => 2,
            'BBQ' => 4,
            'DESSERTS' => 7,
            'NAN-TANDOOR' => 8,
            'SALAD' => 10,
        ];
        foreach ($courses as $name => $sort) {
            $categoryId = $this->makeCategory(['name' => $name, 'sort_order' => $sort]);
            $this->products[$name] = $this->makeProduct($categoryId, [
                'name' => $name.' Dish', 'unit_id' => $unitId,
            ]);
            // Send-readiness fails CLOSED without an effective Catering rate —
            // a real business rule, not scaffolding. The kitchen sheet only
            // exists after a quotation was sent, accepted and released, so the
            // fixture has to walk that whole road honestly.
            \App\Models\Tenant\CateringMaterialRate::create([
                'product_id' => $this->products[$name], 'rate' => 100, 'unit_id' => $unitId,
                'effective_from' => now()->subMonth()->toDateString(),
            ]);
        }

        $estimates = app(CateringEstimateService::class);
        $this->event = $estimates->createEvent([
            'branch_id' => $branchId, 'customer_name' => 'Course Order Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(7)->toDateString(),
            'venue' => 'Course Hall', 'pax' => 75,
        ]);

        // JAAN-BOOJH KAR ULTI TARTEEB me punch kiya gaya — bilkul us tasveer ki
        // tarah jo client ne bheji: salad se pehle meetha, meethe se pehle BBQ.
        $punchOrder = ['SALAD', 'DESSERTS', 'BBQ', 'NAN-TANDOOR', 'RICE', 'STARTERS'];
        $lines = [];
        foreach ($punchOrder as $name) {
            $lines[] = [
                'product_id' => $this->products[$name], 'item_name' => $name.' Dish',
                'quantity' => 10, 'unit_id' => $unitId, 'unit_code' => 'KG', 'rate' => 100,
            ];
        }
        $this->estimate = $estimates->saveDraftLines($this->event->currentEstimate, $lines, []);
    }

    /** Punch order aur course order waqai mukhtalif hon — warna test kuch sabit nahi karta. */
    public function test_the_punched_order_really_is_out_of_course_order(): void
    {
        $punched = $this->estimate->lines()->orderBy('sort_order')->pluck('item_name')->all();

        $this->assertSame([
            'SALAD Dish', 'DESSERTS Dish', 'BBQ Dish', 'NAN-TANDOOR Dish', 'RICE Dish', 'STARTERS Dish',
        ], $punched, 'fixture ko ulti tarteeb me punch karna chahiye, warna sort ka asar nazar hi nahi aayega');
    }

    /** Helper course ki tarteeb lagata hai. */
    public function test_course_order_sorts_by_the_category_sequence(): void
    {
        $sorted = CourseOrder::sort($this->estimate->lines)->pluck('item_name')->all();

        $this->assertSame([
            'STARTERS Dish',     // 1
            'RICE Dish',         // 2
            'BBQ Dish',          // 4
            'DESSERTS Dish',     // 7
            'NAN-TANDOOR Dish',  // 8
            'SALAD Dish',        // 10
        ], $sorted);
    }

    /**
     * Tarteeb kahin hard-code nahi hai. Malik Categories screen par sort_order
     * badle to kaghaz khud us par chale — warna ek list code me aur ek data me
     * reh jati hai aur waqt ke saath dono alag ho jati hain.
     */
    public function test_changing_the_category_sequence_changes_the_paper(): void
    {
        DB::connection('tenant')->table('categories')->where('name', 'SALAD')->update(['sort_order' => 0]);

        $sorted = CourseOrder::sort($this->estimate->lines()->get())->pluck('item_name')->all();

        $this->assertSame('SALAD Dish', $sorted[0],
            'sort_order 0 hone par salad sab se pehle aana chahiye — tarteeb data se aati hai, code se nahi');
    }

    /** Ek course ke ANDAR operator ki apni tarteeb nahi todi jati. */
    public function test_the_operators_own_order_survives_inside_one_course(): void
    {
        $unitId = DB::connection('tenant')->table('units')->value('id');
        $riceCategory = DB::connection('tenant')->table('categories')->where('name', 'RICE')->value('id');
        $second = $this->makeProduct($riceCategory, ['name' => 'RICE Second', 'unit_id' => $unitId]);

        $estimates = app(CateringEstimateService::class);
        $estimate = $estimates->saveDraftLines($this->event->currentEstimate->fresh(), [
            ['product_id' => $second, 'item_name' => 'Punched First', 'quantity' => 1,
                'unit_id' => $unitId, 'unit_code' => 'KG', 'rate' => 10],
            ['product_id' => $this->products['RICE'], 'item_name' => 'Punched Second', 'quantity' => 1,
                'unit_id' => $unitId, 'unit_code' => 'KG', 'rate' => 10],
        ], []);

        $sorted = CourseOrder::sort($estimate->lines()->get())->pluck('item_name')->all();

        $this->assertSame(['Punched First', 'Punched Second'], $sorted,
            'ek hi course ke andar wohi tarteeb rahni chahiye jis me operator ne likha');
    }

    /**
     * Jis line ka product hi na mile wo GIRE nahi — aakhir me chali jaye.
     * Abhi prod par aisi ek bhi line nahi, magar product delete hona mumkin hai
     * aur us din kaghaz bilkul nahi chhapna sab se bura nateeja hoga.
     */
    public function test_a_line_whose_product_vanished_goes_last_instead_of_breaking(): void
    {
        $orphan = $this->estimate->lines()->where('item_name', 'RICE Dish')->first();
        $orphan->forceFill(['product_id' => null])->save();

        $sorted = CourseOrder::sort($this->estimate->lines()->get())->pluck('item_name')->all();

        $this->assertCount(6, $sorted, 'koi line ghayab nahi honi chahiye');
        $this->assertSame('RICE Dish', end($sorted), 'be-category line aakhir me');
    }

    /**
     * ASLI KAGHAZ. Blade render ho kar wohi tarteeb de — helper ka theek hona
     * kaafi nahi.
     */
    public function test_the_rendered_quotation_prints_in_course_order(): void
    {
        $html = $this->renderEstimate();

        $positions = [];
        foreach (['STARTERS Dish', 'RICE Dish', 'BBQ Dish', 'DESSERTS Dish', 'NAN-TANDOOR Dish', 'SALAD Dish'] as $name) {
            $at = mb_strpos($html, $name);
            $this->assertNotFalse($at, "{$name} kaghaz par mojood hona chahiye");
            $positions[$name] = $at;
        }

        $this->assertSame(array_keys($positions), array_keys(collect($positions)->sort()->all()),
            'quotation par item course ki tarteeb me chhapne chahiye');
    }

    /**
     * Customer ke kaghaz par course ke UNWAAN nahi aane chahiyen — client ne
     * sirf tarteeb maangi thi, shakl badalne ko nahi kaha. Unwaan kitchen sheet
     * ki cheez hai.
     */
    public function test_the_customer_document_does_not_grow_course_headings(): void
    {
        $html = $this->renderEstimate();

        $this->assertStringNotContainsString('class="course"', $html);
        $this->assertStringNotContainsString('course-name', $html);
    }

    // ── KITCHEN SHEET ──────────────────────────────────────────────────────

    /**
     * KITCHEN-SHEET-NO-COURSE-HEADINGS-1 (26 Sep) — malik ne unwaan hatwa diye:
     * "category hata do, need nahi, aur space aa jayega". Do khanon wale sheet
     * par do pattiyan aa rahi thin, aur jagah wahi cheez kha rahi thi jis ki
     * shikayat thi.
     *
     * TARTEEB QAYAM HAI — wo 21 Sep ki maang thi aur wo nahi badli. Yehi is
     * test ka asal maqsad hai.
     *
     * ⚠ Purana test yahan `assertStringContainsString('RICE')` bhi karta tha,
     * aur wo unwaan hatne ke BAAD BHI PASS ho jata — kyunke is fixture ke khane
     * ka naam hi "RICE Dish" hai. Yani ek pehra jo ghalat wajah se green tha.
     * Ab unwaan ki GHAIR-mojoodgi us ke apne markup se parkhi jati hai, lafz se
     * nahi.
     */
    public function test_the_kitchen_sheet_keeps_the_course_order_without_printing_headings(): void
    {
        $html = $this->renderKitchenSheet();

        $positions = [];
        foreach (['STARTERS Dish', 'RICE Dish', 'BBQ Dish', 'DESSERTS Dish', 'NAN-TANDOOR Dish', 'SALAD Dish'] as $name) {
            $positions[$name] = mb_strpos($html, $name);
        }
        $this->assertSame(array_keys($positions), array_keys(collect($positions)->sort()->all()),
            'kitchen sheet par khana AB BHI course ki tarteeb me aana chahiye');

        // MARKUP par, lafz par nahi: stylesheet isi safhe me inline hoti hai, to
        // "course-name" jaisa lafz dhoondna CSS par ja lagta hai aur test kabhi
        // green hi nahi hota. Pehli koshish yahi thi.
        $this->assertStringNotContainsString('<tr class="course"', $html,
            'course ki patti ab nahi chhapti');
    }

    /**
     * Wo shikayat jo pehle aa chuki hai, dobara na aaye: EK table aur header EK
     * baar. (`KITCHEN-SHEET-NO-STATIONS-1` — malik ne station wali kaali
     * pattiyan aur baar baar chhapne wale header hatwaye the.)
     */
    public function test_the_kitchen_sheet_keeps_one_table_and_one_header(): void
    {
        $html = $this->renderKitchenSheet();

        $this->assertSame(1, substr_count($html, '<table class="items">'),
            'sirf EK items table honi chahiye');
        $this->assertSame(1, substr_count($html, '<thead>'),
            'header sirf ek baar — malik ne baar baar chhapta header hatwaya tha');
    }

    private function renderKitchenSheet(): string
    {
        $estimates = app(CateringEstimateService::class);
        $estimates->markSent($this->estimate);
        $estimates->markAccepted($this->estimate->refresh());
        $estimates->confirmEvent($this->event->refresh());

        $release = app(\App\Services\Catering\CateringProductionReleaseService::class)
            ->release($this->event->refresh());
        $release->load(['lines', 'event']);

        return view('tenant.catering.documents.kitchen-sheet', [
            'release' => $release,
            'lang' => 'en',
            'businessName' => 'Kashif Kitchen',
        ])->render();
    }

    /**
     * POORA document render hota hai — wohi view aur wohi data jo
     * CateringDocumentController::estimate() banata hai, asli position service
     * samet. Partial ko haath se banaye hue variables dena "asli raasta" nahi
     * hota, aur isi codebase me wo ghalti do outage la chuki hai.
     */
    private function renderEstimate(): string
    {
        $estimate = $this->estimate->fresh(['lines', 'event']);

        return view('tenant.catering.documents.estimate', [
            'estimate' => $estimate,
            'event' => $estimate->event,
            'lang' => 'en',
            'position' => app(\App\Services\Catering\CateringFinancialPositionService::class)
                ->position($estimate->event),
            'advanceTotal' => 0,
            'businessName' => 'Kashif Kitchen',
        ])->render();
    }
}
