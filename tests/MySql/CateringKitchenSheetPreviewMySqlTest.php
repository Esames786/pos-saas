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

    /**
     * Kaghaz render hota hai, aur us par RELEASE NUMBER nahi hota.
     *
     * Pehle ye parcha khud likh kar kehta tha ke wo preview hai ("PRODUCTION
     * NOT RELEASED YET" ka band). Malik ne 11 Oct ko parche par nishan laga
     * kar wo band hatwa diya.
     *
     * Us faisle ka khatra bata diya gaya tha: bawarchi-khane ke paas do sach
     * ho sakte hain — ek preview deewar par, asli parcha baad me, aur dono ek
     * jaise. Farq ab bhi mojood hai magar CHHOTA: jaari-shuda parche par
     * release number chhapta hai, preview par nahi. Ye test ab usi farq par
     * khara hai, taake wo bhi chup chaap khatm na ho jaye.
     */
    public function test_the_rendered_sheet_carries_no_release_number(): void
    {
        $html = $this->render(true);

        $this->assertDoesNotMatchRegularExpression('/PR-\d/', $html,
            'preview par release number nahi hona chahiye — PREVIEW ka band hatne ke baad '
            .'yehi akeli nishani hai jo use asli parche se alag karti hai');
        $this->assertStringNotContainsString('PRODUCTION NOT RELEASED YET', $html,
            'aur purana band wapas nahi aana chahiye');
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
     * KITCHEN-SHEET-ONE-TIME-1 (16 Oct) — paer par waqt ki sirf EK satar.
     *
     * Pehle ye test ULTA tha: wo zor deta tha ke DONO waqt apne naam ke saath
     * chhapein, is daleel par ke ek akela waqt bawarchi ko ye nahi batata ke
     * khana nikalne ka waqt hai ya khane ka. Malik ne asli parche dekh kar
     * is ke khilaf faisla diya — amal me dono waqt amooman EK hi hote hain,
     * is liye parche par ek hi cheez do baar aa rahi thi.
     *
     * Naya usool: DEPARTURE pehle. Wo darj ho to sirf wohi chhapta hai aur
     * SERVE bilkul nahi aata — chahe SERVE alag waqt par hi kyun na ho.
     * Purani daleel ka wazan yahin likha hai taake aage koi use dobara
     * "bug" samajh kar na palat de.
     */
    public function test_only_the_dispatch_time_prints_when_both_are_set(): void
    {
        $this->event->forceFill(['service_time' => '21:00', 'dispatch_time' => '19:30'])->save();

        $html = $this->render(true);

        // Nange lafz par nahi, LABEL ke markup par parkho. `kitchen-sheet-style`
        // ke CSS comment me "SERVE" ka lafz mojood hai aur wo style ke saath
        // document me chala jata hai — nanga `assertStringNotContainsString`
        // is liye hamesha red deta hai, chahe paer bilkul theek ho.
        $this->assertStringContainsString('>DEPARTURE<', $html);
        $this->assertStringContainsString('7:30 PM', $html);
        $this->assertStringNotContainsString('>SERVE<', $html,
            'departure darj ho to serve ka lafz paer par nahi aana chahiye');
        $this->assertStringNotContainsString('9:00 PM', $html,
            'aur na us ka waqt');
    }

    /**
     * Departure darj na ho to SERVE hi wo ek satar hai — warna parche par
     * waqt ka koi nishan hi na bachta.
     */
    public function test_the_service_time_prints_when_there_is_no_dispatch_time(): void
    {
        $this->event->forceFill(['service_time' => '21:00', 'dispatch_time' => null])->save();

        $html = $this->render(true);

        $this->assertStringContainsString('>SERVE<', $html);
        $this->assertStringContainsString('9:00 PM', $html);
        $this->assertStringNotContainsString('>DEPARTURE<', $html);
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
        // 11 Oct: PREVIEW wala band malik ke kehne par hata diya gaya. Wohi
        // farq ab release number se parkha jata hai — dono parche bante hain,
        // magar number sirf jaari-shuda wale par chhapta hai.
        $this->assertSame(2, substr_count($html, 'Printed'),
            'dono parche banne chahiyein');
        $this->assertSame(1, substr_count($html, $released->release_no),
            'magar release number sirf jaari-shuda parche par — draft wale par nahi. '
            . 'PREVIEW ka band hatne ke baad yehi akela farq bacha hai');
    }

    /** Bulk par ab koi booking sirf "release nahi hui" ki wajah se nahi chhoot-ti. */
    public function test_the_bulk_run_no_longer_skips_unreleased_bookings(): void
    {
        $html = $this->bulkHtml([$this->event->id]);

        $this->assertStringNotContainsString('chhoot gayin', $html);
        $this->assertStringContainsString('MR. HARIS', $html);
    }

    /**
     * KITCHEN-SHEET-FOOT-BOTTOM-2 (16 Oct) — bulk safhe par bhi paer tal par.
     *
     * Malik: "footer footer ki jagah aaye, chahe list me ek item ho ya bees."
     * 27 September wala hal sirf TANHA parche par chal raha tha: wahan paer
     * `body` ka seedha beta hai aur `body` khara flex hai, is liye paer ka
     * `margin-top: auto` bachi hui jagah kha jata hai. Bulk safhe par har
     * parcha `.bulk-doc` me lipta hai, aur ek aam block ke andar us lafz ka
     * matlab SIFAR hai.
     *
     * Chrome se naapa gaya (asli raasta, asli PDF): tanha parche par paer
     * 95% neeche tha, bulk safhe par 22% — aur client isi safhe se chhapta
     * hai. Hal ke baad teen parchon wale safhe par 95% / 99% / 96%.
     *
     * YE TEST KYA KAR SAKTA HAI AUR KYA NAHI: PHP se kaghaz ka layout nahi
     * naapa ja sakta, CSS yahan chalti hi nahi. Is liye ye test wo TEEN
     * lafz pakadta hai jin ke baghair paer neeche ja hi nahi sakta — agar
     * koi unhe hata de to yahan red hoga. Asli paimaish upar likhi hai aur
     * wo Chrome se hoti hai.
     */
    public function test_each_sheet_on_the_bulk_page_is_a_full_page_column_so_the_footer_sits_at_the_bottom(): void
    {
        $html = $this->bulkHtml([$this->event->id]);

        $css = substr($html, 0, (int) strpos($html, '</head>'));
        $rule = substr($css, (int) strpos($css, '.bulk-doc {'));
        $rule = substr($rule, 0, (int) strpos($rule, '}') + 1);

        $this->assertStringContainsString('display: flex', $rule,
            'har parcha khud flex banna chahiye, warna paer ka margin-top:auto bekaar hai');
        $this->assertStringContainsString('flex-direction: column', $rule,
            'aur khara, warna bachi hui jagah paer ke upar nahi jati');
        $this->assertStringContainsString('min-height: calc(', $rule,
            'aur poore safhe jitna ooncha, warna "neeche" ka koi matlab hi nahi');

        $this->assertStringContainsString('<div class="foot"', $html,
            'aur paer safhe par mojood ho — warna upar wali teen shartein khali hain');
    }

    /**
     * DOC-TOOLBAR-OFF-PAPER-1 (19 Oct) — screen ke button kaghaz par na baithen.
     *
     * Malik ne estimate ka safha nishan laga kar bheja: Print / A4 / printer ka
     * khana / Send to network — chaaron parche ke UPAR tair rahe thay aur
     * unwan ko dhaanp rahe thay. Phir: "ye tamam pages — invoices, pre invoice,
     * kitchen sheet, address — har jagah ye masla nahi aana chahiye."
     *
     * Wajah ek hi satar thi, teen stylesheet me alag alag likhi hui:
     *     .print-bar { position: fixed; top: 10px; right: 10px; z-index: 10 }
     * In safhon par `body` KHUD kaghaz hai, is liye wo kona hamesha parche ke
     * upar parta tha.
     *
     * Ye test wahi cheez pakadta hai jo dobara likhi ja sakti hai: koi bhi
     * document view phir se toolbar ko screen ke kone par taanke, ya koi safha
     * sanjha partial lagana bhool jaye. Chaukhat ki paimaish Chrome se hui
     * (screenshot): button ab kaghaz ke upar wali mez par hain.
     */
    public function test_no_document_page_pins_its_toolbar_over_the_paper(): void
    {
        $dir = resource_path('views/tenant/catering/documents');

        // 1. Koi bhi document view toolbar ko screen ke kone par na taanke.
        $pinned = [];
        foreach (glob($dir.'/{,partials/}*.blade.php', GLOB_BRACE) as $file) {
            // Comments PEHLE nikaalo. Purani satar in files ke notes me jaan
            // boojh kar likhi hui hai (taake aane wale ko pata ho ke kya aur
            // kyun hataya gaya) — aur us par match karna ek jhoota red deta
            // hai. Yehi jaal is track me pehle bhi lag chuka hai: CSS comment
            // document me inline jata hai, is liye uske lafz "mojood" hote
            // hain. Hamesha matn par parkho, note par nahi.
            $text = (string) preg_replace(
                ['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s'], '', (string) file_get_contents($file)
            );
            if (preg_match('/\.(print-bar|bulk-toolbar|toolbar)[^{]*\{[^}]*position:\s*fixed/', $text)) {
                $pinned[] = basename($file);
            }
        }
        $this->assertSame([], $pinned,
            'in views me toolbar phir se screen ke kone par taanka gaya hai — '
            .'in safhon par body khud kaghaz hai, is liye wo kona parche ke upar parta hai: '
            .implode(', ', $pinned));

        // 2. Har document SAFHA sanjha partial lagaye (partials khud nahi —
        //    wo shell ka hissa nahi hote).
        $pages = [
            'estimate.blade.php', 'final-invoice.blade.php', 'kitchen-sheet.blade.php',
            'address-sheet.blade.php', 'bulk-quotations.blade.php', 'bulk-kitchen-sheets.blade.php',
        ];
        foreach ($pages as $page) {
            $this->assertStringContainsString(
                "@include('tenant.catering.documents.partials.doc-toolbar-style')",
                (string) file_get_contents($dir.'/'.$page),
                "{$page} par toolbar ki jagah tay karne wali partial nahi lagi — "
                .'us safhe ka toolbar phir kaghaz par ja baithega');
        }
    }

    /**
     * Bulk safhe par TOOLBAR SIRF EK.
     *
     * Toolbar pehle `kitchen-sheet-body` ke andar tha, aur bulk safha wohi jism
     * har booking ke liye dobara lagata hai — yani N toolbar, sab ek doosre ke
     * upar tairte hue, plus bulk ka apna. Nazar ek hi aata tha, is liye kisi ko
     * pata nahi chala. Toolbar ab safhe ka chrema hai, jism ka nahi.
     */
    public function test_the_bulk_page_shows_only_one_toolbar_however_many_bookings(): void
    {
        $estimates = app(CateringEstimateService::class);
        $second = $estimates->createEvent([
            'branch_id' => $this->event->branch_id, 'customer_name' => 'MR MUBASSHIR',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(6)->toDateString(), 'pax' => 100,
        ]);
        $estimates->saveDraftLines($second->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Chicken Biryani',
            'quantity' => 12, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 900,
        ]], []);

        $html = $this->bulkHtml([$this->event->id, $second->id]);

        $this->assertSame(2, substr_count($html, 'class="bulk-doc"'),
            'do booking, do parche — warna neeche wali ginti ka koi matlab nahi');
        $this->assertSame(1,
            substr_count($html, 'class="print-bar"') + substr_count($html, 'class="bulk-toolbar"'),
            'chahe kitni bookings hon, toolbar ek hi hona chahiye');
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
