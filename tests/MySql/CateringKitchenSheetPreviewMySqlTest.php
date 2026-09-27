<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringProductionRelease;
use App\Models\Tenant\CateringProductionReleaseLine;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringProductionReleaseService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KITCHEN-SHEET-PREVIEW-1 — kitchen sheet production release se PEHLE.
 *
 * Malik ki farmaish (27 Sep): "kitchen sheet can be print before release
 * production also — just for the print."
 *
 * Yahan "parcha ban gaya" sab se kam ahem baat hai. Ahem ye hai ke lafz
 * **JUST FOR THE PRINT** sach rahe: is raaste se koi release na bane, koi
 * release number kharch na ho, event ka status na hile, aur koi line mehfooz
 * na ho. Ek print button jo chupke se production release kar de, us screen
 * par sab se khatarnaak cheez hogi.
 *
 * Doosri ahem baat: preview kaghaz par KHUD KEHTA ho ke wo preview hai. Warna
 * deewar par lage do parche ek jaise dikhenge aur bawarchi-khane ke paas do
 * sach ho jayenge.
 */
class CateringKitchenSheetPreviewMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEvent $event;

    private int $unitId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_production_release_lines', 'catering_production_releases',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_estimate_line_cost_blocks',
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $branchId = $this->makeBranch();
        $categoryId = $this->makeCategory(['name' => 'RICE', 'sort_order' => 2]);
        $this->unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'unit_id' => $this->unitId]);

        // Quotation Catering Material Rate ke baghair bheji hi nahi ja sakti,
        // aur release usi ke baad hoti hai. Asli shart hai — fixture ko poora
        // raasta chalna parta hai. (Preview walay tests ko is ki zaroorat
        // nahi: wohi to baat hai.)
        \App\Models\Tenant\CateringMaterialRate::create([
            'product_id' => $this->productId, 'rate' => 700, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $estimates = app(CateringEstimateService::class);
        $this->event = $estimates->createEvent([
            'branch_id' => $branchId, 'customer_name' => 'MR. HARIS',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(4)->toDateString(),
            'venue' => 'Preview Hall', 'pax' => 120,
        ]);

        $estimates->saveDraftLines($this->event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Chicken Biryani',
            'quantity' => 40, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 900,
        ]], []);

        $this->event->refresh();
    }

    private function service(): CateringProductionReleaseService
    {
        return app(CateringProductionReleaseService::class);
    }

    // ── "JUST FOR THE PRINT" — yehi asal shart hai ──────────────────────────

    /** Preview koi release nahi banati. */
    public function test_a_preview_saves_no_release(): void
    {
        $before = CateringProductionRelease::count();

        $this->service()->preview($this->event);

        $this->assertSame($before, CateringProductionRelease::count(),
            'preview se database me koi release nahi banni chahiye');
        $this->assertSame(0, CateringProductionReleaseLine::count(),
            'aur koi line bhi nahi');
    }

    /** Event ka status waise ka waisa. */
    public function test_a_preview_does_not_move_the_booking(): void
    {
        $before = $this->event->status;

        $this->service()->preview($this->event);

        $this->assertSame($before, $this->event->fresh()->status,
            'preview par booking ka status nahi badalna chahiye');
        $this->assertNotSame(CateringEvent::STATUS_RELEASED, $this->event->fresh()->status);
    }

    /**
     * Release number ki qatar se kuch kharch na ho. Ek preview par number
     * uthana us qatar me hamesha ke liye ek sooraakh chhod deta — aur
     * bawarchi-khane ka register number ki tarteeb par chalta hai.
     */
    public function test_a_preview_burns_no_release_number(): void
    {
        $this->service()->preview($this->event);
        $this->service()->preview($this->event);
        $this->service()->preview($this->event);

        // Teen preview ke baad pehli ASLI release ka number wohi hona chahiye
        // jo bina preview ke hota.
        $estimates = app(CateringEstimateService::class);
        $estimates->markSent($this->event->currentEstimate);
        $estimates->markAccepted($this->event->currentEstimate->refresh());
        $estimates->confirmEvent($this->event->refresh());

        $release = $this->service()->release($this->event->refresh());

        $this->assertStringEndsWith('0001', $release->release_no,
            "teen preview ke baad bhi pehli asli release ka number 0001 hona chahiye, mila {$release->release_no}");
    }

    /** Preview ka apna release_no koi asli number nahi. */
    public function test_the_preview_carries_no_release_number(): void
    {
        $this->assertSame('PREVIEW', $this->service()->preview($this->event)->release_no);
        $this->assertFalse($this->service()->preview($this->event)->exists,
            'preview ka model database me mojood nahi hona chahiye');
    }

    // ── Parcha waqai banta hai, aur sach bolta hai ─────────────────────────

    /** Preview par wohi khana aata hai jo estimate par hai. */
    public function test_the_preview_carries_the_dishes(): void
    {
        $preview = $this->service()->preview($this->event);

        $this->assertCount(1, $preview->lines);
        $this->assertSame('Chicken Biryani', $preview->lines->first()->item_name);
        $this->assertSame('120', (string) $preview->event_snapshot['pax']);
    }

    /** Kaghaz render hota hai aur KHUD kehta hai ke wo preview hai. */
    public function test_the_rendered_sheet_says_it_is_not_released(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString('PRODUCTION NOT RELEASED YET', $html);
        $this->assertStringContainsString('<div class="preview-band">', $html);
        $this->assertStringContainsString('Chicken Biryani', $html);
    }

    /**
     * Aur asli parche par wo band NAHI aana chahiye — warna do mahine baad
     * koi kahega "har parche par preview likha aata hai" aur band ka matlab
     * hi khatam ho jayega.
     */
    public function test_a_real_release_sheet_carries_no_preview_band(): void
    {
        $estimates = app(CateringEstimateService::class);
        $estimates->markSent($this->event->currentEstimate);
        $estimates->markAccepted($this->event->currentEstimate->refresh());
        $estimates->confirmEvent($this->event->refresh());
        $release = $this->service()->release($this->event->refresh());

        $html = view('tenant.catering.documents.kitchen-sheet', [
            'release' => $release->load(['lines', 'event']),
            'lang' => 'en',
            'businessName' => 'Kashif Kitchen',
        ])->render();

        // Sirf CSS rule har parche par jata hai; BAND ka markup nahi aana chahiye.
        $this->assertStringNotContainsString('<div class="preview-band">', $html);
        $this->assertStringNotContainsString('PRODUCTION NOT RELEASED YET', $html);
        $this->assertStringContainsString($release->release_no, $html);
    }

    /**
     * Preview aur asli parcha EK hi builder se bante hain. Ye test us baat ka
     * pehra hai: agar kal koi preview ke liye alag line-banane wala raasta
     * likh de, dono kaghaz chupke se alag ho jayenge — production label, Urdu,
     * hidayaat, maal, sab me.
     */
    public function test_the_preview_and_the_real_sheet_describe_the_same_lines(): void
    {
        $preview = $this->service()->preview($this->event);

        $estimates = app(CateringEstimateService::class);
        $estimates->markSent($this->event->currentEstimate);
        $estimates->markAccepted($this->event->currentEstimate->refresh());
        $estimates->confirmEvent($this->event->refresh());
        $real = $this->service()->release($this->event->refresh());

        $shape = fn ($lines) => $lines->map(fn ($l) => [
            $l->product_id, $l->item_name, $l->item_name_ur,
            (string) $l->quantity, $l->unit_code, $l->instructions,
            json_encode($l->materials_snapshot), $l->sort_order,
        ])->all();

        $this->assertSame($shape($preview->lines), $shape($real->lines()->get()),
            'preview aur asli parche ki lines bilkul aik jaisi honi chahiye');
    }

    // ── KITCHEN-SHEET-A5-1 — purane software jaisa parcha ─────────────────

    /** A5 KHARI — malik: A4 ko aadha kaat kar KHARA chhapte hain (148 × 210mm). */
    public function test_the_sheet_is_a5_portrait_not_a4(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString('size: A5 portrait', $html);
        $this->assertStringNotContainsString('size: A4 portrait', $html,
            'A4 wali purani setting reh gayi to printer do me se kis par chale?');
    }

    /** Driver isi parche se chalta hai — pata us par hona chahiye. */
    public function test_the_sheet_carries_the_customer_address(): void
    {
        $this->event->forceFill(['customer_address' => 'Candle Banquet, Johar'])->save();

        $this->assertStringContainsString('Candle Banquet, Johar', $this->render(true));
    }

    /**
     * DONO waqt, aur har ek par apna naam. Ek waqt akela chhapne par bawarchi
     * ko pata hi nahi chalta ke ye khana nikalne ka waqt hai ya khane ka.
     */
    public function test_both_the_service_and_dispatch_times_print_with_their_names(): void
    {
        $this->event->forceFill(['service_time' => '21:00', 'dispatch_time' => '19:30'])->save();

        $html = $this->render(true);

        $this->assertStringContainsString('SERVE', $html);
        $this->assertStringContainsString('9:00 PM', $html);
        $this->assertStringContainsString('DEPARTURE', $html);
        $this->assertStringContainsString('7:30 PM', $html);
    }

    /** Jo waqt darj hi nahi, us ki satar nahi chhapni chahiye. */
    public function test_a_missing_dispatch_time_prints_nothing(): void
    {
        $this->event->forceFill(['service_time' => '21:00', 'dispatch_time' => null])->save();

        $this->assertStringNotContainsString('DEPARTURE', $this->render(true));
    }

    /**
     * SERVICE ka lafz sirf tab jab service charges li gayi hon — aur RAQAM
     * kabhi nahi. Malik: "jub Service Charges li gae tub service likha howe
     * ae." Is parche par paisa nahi aata, aur ye test us usool ka pehra hai.
     */
    public function test_the_service_word_appears_only_when_a_service_charge_was_taken(): void
    {
        $this->assertStringNotContainsString('class="svc"', $this->render(true),
            'service charge nahi li — lafz nahi aana chahiye');

        $this->event->currentEstimate->forceFill(['service_charge_amount' => 4000])->save();
        $html = $this->render(true);

        $this->assertStringContainsString('class="svc"', $html);
        $this->assertStringContainsString('SERVICE', $html);
        $this->assertStringNotContainsString('4,000', $html,
            'kitchen sheet par raqam kabhi nahi — malik ne lafz maanga tha, paisa nahi');
    }

    /**
     * Instructions me SIRF hidayaat. Maal ka byora ab chhoti shakl me dish ke
     * neeche hai — "Party 42 KG" — naam ke baghair.
     */
    public function test_materials_read_as_party_or_own_and_leave_the_instructions_alone(): void
    {
        // Dish par ek asli material block — warna parche par maal ki satar
        // hoti hi nahi aur test kuch sabit nahi karta.
        $chickenId = $this->makeProduct(
            $this->tenant()->table('categories')->value('id'),
            ['name' => 'Chicken (Regular)', 'unit_id' => $this->unitId]
        );
        \App\Models\Tenant\CateringProductProfile::updateOrCreate(
            ['product_id' => $this->productId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );
        \App\Models\Tenant\CateringProductCostBlock::create([
            'product_id' => $this->productId, 'label' => 'Chicken',
            'block_type' => \App\Models\Tenant\CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $chickenId, 'quantity_per_unit' => 2,
            'unit_id' => $this->unitId, 'rate' => 400,
            'charge_basis' => \App\Models\Tenant\CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => \App\Models\Tenant\CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'sort_order' => 1,
        ]);

        // Line ko dobara likho taake block us par snapshot ho.
        app(CateringEstimateService::class)->saveDraftLines($this->event->currentEstimate->refresh(), [[
            'product_id' => $this->productId, 'item_name' => 'Chicken Biryani',
            'quantity' => 40, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 900,
        ]], []);

        $block = \App\Models\Tenant\CateringEstimateLineCostBlock::where('block_type', 'material')->firstOrFail();
        $block->forceFill(['is_customer_supplied' => true])->save();

        $html = $this->render(true);

        $this->assertStringContainsString('Party', $html, 'party ka maal saaf likha ho');
        $this->assertStringNotContainsString('CUSTOMER SUPPLIES', $html,
            'ye purana lamba jumla instructions me se jana chahiye tha');
    }

    // ── BULK — malik ki asal shikayat ────────────────────────────────────

    /**
     * Malik ne saaf kaha: "kitchen sheet can be print to any status." Bulk
     * button par har status ki rows chuni jati hain — draft, quoted,
     * confirmed, released. Pehle sirf released wali chhapti thin aur baqi
     * "skipped" ho jati thin.
     *
     * Ye test bulk ke DONO raaste ek saath chalata hai: ek booking jis ki
     * release ho chuki hai, aur ek jis ki nahi.
     */
    public function test_the_bulk_run_prints_a_sheet_for_every_status(): void
    {
        // Pehli: release ho chuki.
        $estimates = app(CateringEstimateService::class);
        $estimates->markSent($this->event->currentEstimate);
        $estimates->markAccepted($this->event->currentEstimate->refresh());
        $estimates->confirmEvent($this->event->refresh());
        $released = $this->service()->release($this->event->refresh());

        // Doosri: abhi draft.
        $draft = $estimates->createEvent([
            'branch_id' => $this->event->branch_id, 'customer_name' => 'MR MUBASSHIR',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(6)->toDateString(), 'pax' => 100,
        ]);
        $estimates->saveDraftLines($draft->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Chicken Biryani',
            'quantity' => 12, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 900,
        ]], []);

        $this->assertSame(CateringEvent::STATUS_DRAFT, $draft->fresh()->status,
            'doosri booking ka draft hona is test ki poori bunyaad hai');

        $html = $this->bulkHtml([$this->event->id, $draft->id]);

        $this->assertStringContainsString($released->release_no, $html,
            'released booking ka asli parcha aana chahiye');
        $this->assertStringContainsString('MR MUBASSHIR', $html,
            'draft booking ka parcha bhi aana chahiye — yehi maanga gaya tha');
        $this->assertSame(1, substr_count($html, '<div class="preview-band">'),
            'sirf draft wale parche par preview band, released wale par nahi');
    }

    /** Bulk par ab koi booking sirf "release nahi hui" ki wajah se nahi chhoot-ti. */
    public function test_the_bulk_run_no_longer_skips_unreleased_bookings(): void
    {
        $html = $this->bulkHtml([$this->event->id]);

        $this->assertStringNotContainsString('chhoot gayin', $html);
        $this->assertStringContainsString('MR. HARIS', $html);
    }

    /** @param  list<int>  $eventIds */
    private function bulkHtml(array $eventIds): string
    {
        $request = \Illuminate\Http\Request::create('/catering/documents/bulk/kitchen-sheets', 'GET', [
            'ids' => $eventIds, 'lang' => 'en',
        ]);

        return app(\App\Http\Controllers\Tenant\Catering\CateringBulkDocumentController::class)
            ->kitchenSheets($request)->render();
    }

    private function render(bool $preview): string
    {
        return view('tenant.catering.documents.kitchen-sheet', [
            'release' => $this->service()->preview($this->event),
            'lang' => 'en',
            'businessName' => 'Kashif Kitchen',
        ])->render();
    }
}
