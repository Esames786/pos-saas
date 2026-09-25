<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialRate;
use App\Services\Catering\CateringEstimateService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KITCHEN-SHEET-DENSITY-1 — utne khane ek safhe par jitne purane software par.
 *
 * Client (26 Sep) ne purane software ka kitchen sheet aur hamara, saath saath
 * bheja. Purane par saat khane safhe ke upper tihai me aa gaye; hamare par wohi
 * saat poora safha kha gaye. Bawarchi ko chulhe par do safhe palatne parte hain
 * jahan pehle ek kaafi tha.
 *
 * ⚠ RENDERER KI HAQEEQAT, PEHLE HI SAAF: kitchen sheet ka koi PDF raasta hai
 * NAHI. CateringDocumentController::kitchenSheet() hamesha HTML deta hai aur
 * client BROWSER se chhapta hai. Yani neeche dompdf jo safhe ginta hai wo wohi
 * cheez NAHI hai jo client ke haath me aati hai — dompdf me flexbox nahi, is
 * liye wahan header browser se zyada phailta hai aur uske safhe hamesha zyada
 * bantege.
 *
 * Phir bhi ye naap rakha gaya hai, kyunke ye REGRESSION par kaatta hai: agar
 * kal koi CSS phula dega to dompdf ke safhe bhi barhenge. Ise "client ko itne
 * khane dikhenge" ka daawa mat samajhna — ye sirf ye kehta hai ke sheet pehle
 * se kasa hua hai.
 *
 * Do taraf se pehra, kyunke "zyada khane ek safhe par" ka aasan aur ghalat
 * jawab ye hai ke content kaat diya jaye:
 *   • aam lambai ka sheet EK safhe par rahe
 *   • har khana, har course ka unwaan aur har checkbox safhe par MOJOOD rahe
 *   • waqai lamba sheet ab bhi KAI safhon par jaye
 */
class CateringKitchenSheetDensityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $unitId;

    /** Kashif Kitchen ki asli tarteeb, prod se. */
    private const COURSES = [
        'STARTERS' => 1, 'RICE' => 2, 'CURRIES' => 3, 'BBQ' => 4,
        'FRIED' => 5, 'SIDE LINES' => 6, 'DESSERTS' => 7, 'NAN-TANDOOR' => 8,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_production_release_lines', 'catering_production_releases',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
        $this->unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Client ki apni booking jitni: 12 khane, aath course me.
     *
     * 12 ka adad andaza nahi — CSS se naapa gaya. Pehle ek Urdu khane ki qatar
     * ~76px leti thi aur safhe par ~7 khane aate the, jo client ki bheji hui
     * tasveer se bilkul milta hai. Ab qatar ~46px hai aur ~13 khane aate hain.
     *
     * 16 ka pehra jaan-boojh kar NAHI lagaya. 16 tak pahunchne ke liye wo do
     * cheezein hatani partin jo client ne KHUD maangi thin — har khane ke neeche
     * material ka breakdown (29 Aug) aur course ke unwaan (18 Sep) — ya Urdu ka
     * naam itna chhota karna parta ke chulhe par door se parha na jaye. Wo
     * faisla malik ka hai, mera nahi.
     */
    public function test_a_normal_sheet_fits_on_one_page(): void
    {
        $this->assertSame(1, $this->pagesFor(12, 'ur'),
            '12 khane ek safhe par — pehle 7 par hi doosra safha shuru ho jata tha');
    }

    /** Aur Urdu par bhi, kyunke client ka sheet Urdu me hi chhapta hai. */
    public function test_the_urdu_sheet_is_the_one_that_must_fit(): void
    {
        $this->assertSame(1, $this->pagesFor(10, 'ur'));
        $this->assertSame(1, $this->pagesFor(10, 'en'));
    }

    /**
     * Wo tanbeeh jo is file me pehle se likhi hui thi, qayam rahe: Nastaliq ke
     * descender Latin ki leading par kat jate hain. Jagah bachane ke liye Urdu
     * ki leading ko 1.7 se neeche le jana harf katwa dega — safha bacha kar
     * kaghaz bekaar karna.
     */
    public function test_the_urdu_leading_is_not_cut_below_what_nastaliq_needs(): void
    {
        $css = view('tenant.catering.documents.partials.kitchen-sheet-style', ['isUr' => true])->render();

        $this->assertSame(1, preg_match('/direction: rtl; line-height: ([\d.]+)/', $css, $m),
            'Urdu ki leading CSS me milni chahiye — probe pehle khud ko zinda sabit kare');
        $this->assertGreaterThanOrEqual(1.7, (float) $m[1],
            'Nastaliq ko itni leading chahiye — is se neeche harf katte hain');
    }

    /**
     * DOOSRI TARAF KA PEHRA. Waqai lamba sheet ab bhi kai safhon par jaye —
     * warna hum ne content kaat diya hoga, jo do safhon se bura hai.
     */
    public function test_a_genuinely_long_sheet_still_runs_to_more_pages(): void
    {
        $this->assertGreaterThan(1, $this->pagesFor(60, 'ur'),
            '60 khane ek safhe par "aa jayen" to kuch gum ho chuka hai');
    }

    /**
     * Aur kuch gaya nahi: har khana, har course ka unwaan, har checkbox aur
     * material ki line safhe par mojood rahe.
     */
    public function test_nothing_was_dropped_to_make_room(): void
    {
        $html = $this->sheetHtml(16, 'en');

        $this->assertSame(16, substr_count($html, '☐'), 'har khane ka apna checkbox');
        $this->assertSame(count(self::COURSES), substr_count($html, 'class="course"'),
            'har course ka unwaan');
        $this->assertSame(1, substr_count($html, '<table class="items">'),
            'ek hi table — malik ne per-course tables hatwaye the');
        $this->assertSame(1, substr_count($html, '<thead>'),
            'header ek hi baar');

        for ($i = 1; $i <= 16; $i++) {
            $this->assertStringContainsString("Dish {$i}", $html, "khana {$i} safhe par hona chahiye");
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** Wohi renderer jo CateringDocumentController::asPdf() chalata hai. */
    private function pagesFor(int $count, string $lang): int
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->setPaper('a4', 'portrait');
        $pdf->loadHtml($this->sheetHtml($count, $lang), 'UTF-8');
        $pdf->render();

        return $pdf->getCanvas()->get_page_count();
    }

    private function sheetHtml(int $count, string $lang): string
    {
        $release = $this->releaseWith($count);

        return view('tenant.catering.documents.kitchen-sheet', [
            'release' => $release,
            'lang' => $lang,
            'pdf' => true,
            'businessName' => 'Kashif Kitchen',
        ])->render();
    }

    /** Asli raasta: quotation banao, bhejo, qubool karo, confirm karo, release karo. */
    private function releaseWith(int $count): \App\Models\Tenant\CateringProductionRelease
    {
        $courses = array_keys(self::COURSES);
        $lines = [];

        // A test method may build more than one sheet (Urdu and English), and
        // products.sku is unique — so each build gets its own run of SKUs. The
        // NAME stays "Dish n", because another test reads those off the page.
        static $run = 0;
        $run++;

        for ($i = 1; $i <= $count; $i++) {
            $course = $courses[($i - 1) % count($courses)];
            $categoryId = $this->tenant()->table('categories')->where('name', $course)->value('id')
                ?: $this->makeCategory(['name' => $course, 'sort_order' => self::COURSES[$course]]);

            $pid = $this->makeProduct($categoryId, [
                'name' => "Dish {$i}", 'sku' => "D{$run}-{$i}", 'unit_id' => $this->unitId,
            ]);
            CateringMaterialRate::create([
                'product_id' => $pid, 'rate' => 100, 'unit_id' => $this->unitId,
                'effective_from' => now()->subMonth()->toDateString(),
            ]);

            $lines[] = [
                'product_id' => $pid,
                'item_name' => "Dish {$i}",
                // Urdu naam bhi, kyunke Nastaliq ki leading hi sab se bari jagah
                // khati hai — us ke baghair naap jhoota hoga.
                'item_name_ur' => "کھانا {$i}",
                'quantity' => 12 + $i,
                'unit_id' => $this->unitId,
                'unit_code' => 'KG',
                'rate' => 500,
                'instructions' => $i % 3 === 0 ? 'Koyla' : null,
            ];
        }

        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR,ABDUL NAEEM',
            'customer_phone' => '03002639896',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 551,
            'venue' => 'al mehmil banquet five star',
        ]);

        $estimate = $event->currentEstimate;
        $estimates->saveDraftLines($estimate, $lines);
        $estimates->markSent($estimate->refresh());
        $estimates->markAccepted($estimate->refresh());
        $estimates->confirmEvent($event->refresh());

        $release = app(\App\Services\Catering\CateringProductionReleaseService::class)
            ->release(CateringEvent::find($event->id));

        return $release->load(['lines', 'event']);
    }
}
