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
     * KITCHEN-SHEET-FILL-1 (27 Sep) — DONO TARAF SE PEHRA.
     *
     * Pehle yahan sirf ek taraf ka pehra tha: "16 khane ek safhe par". Wo 26
     * Sep ki shikayat ka jawab tha ("saat khane poora safha kha gaye"). Us
     * pehre ne apna kaam kiya — aur PHIR US SE AAGE NIKAL GAYA: gunjaish
     * 18–19 tak pahunch gayi, aur 27 Sep ko malik ne hamara chhapa hua parcha
     * bhej kar us ke upar aur neeche "EMPTY SPACE" likh diya. 9 khanon wali
     * booking par aadha safha khali ja raha tha.
     *
     * Ek tarfa pehra yehi karta hai: jis simt dhakelo, us simt had se aage le
     * jata hai. Is liye ab BAND hai, hadaf nahi:
     *   • 12 khane ek safhe par AANE CHAHIYEN  → parcha is se zyada phool na
     *     jaye (warna wapas "empty space")
     *   • 16 khane ek safhe par NA AAYEN       → parcha dobara na kase (warna
     *     wapas 26 Sep wali shikayat)
     *
     * ASAL ADAD, aur ye zaroori hai: purane software par 14 khane safha bhar
     * dete hain. CLIENT BROWSER SE CHHAPTA HAI, is liye asal naap Chrome ki
     * hai — aur Chrome par, Kashif Kitchen ke ASLI (lambe) naamon ke saath,
     * hamara parcha ab Urdu me 14 aur English me 15 par safha bharta hai.
     * Yani purane software ke barabar.
     *
     * Neeche wale adad (12/16) DOMPDF ke hain aur is test ke apne CHHOTE
     * farzi naamon ke hain — dono cheezein Chrome+asli-naam se alag hain, is
     * liye adad bhi alag hain. Inhe "client ko itne khane dikhenge" na samjha
     * jaye; ye sirf regression par kaatte hain.
     *
     * Chrome wali asal naap dobara lene ka tareeqa (jab malik phir shikayat
     * kare): parcha kisi asli release par render kar ke
     *   chrome --headless=new --no-pdf-header-footer --print-to-pdf=out.pdf file:///sheet.html
     * chalao aur out.pdf me `/Count N` parho.
     */
    public function test_the_sheet_neither_wastes_the_page_nor_cramps_it(): void
    {
        $this->assertSame(1, $this->pagesFor(12, 'ur'),
            'parcha phool gaya — 12 khane bhi ek safhe par nahi aa rahe, yani wapas "empty space"');

        $this->assertSame(2, $this->pagesFor(16, 'ur'),
            'parcha dobara kas gaya — 16 khane ek safhe par aa gaye, yani harf phir chhote ho gaye');
    }

    /** Aur Urdu par bhi, kyunke client ka sheet Urdu me hi chhapta hai. */
    public function test_the_urdu_sheet_is_the_one_that_must_fit(): void
    {
        $this->assertSame(1, $this->pagesFor(10, 'ur'));
        $this->assertSame(1, $this->pagesFor(10, 'en'));
    }

    /**
     * KITCHEN-SHEET-FILL-1 (27 Sep) — GINTI aur SERVICE.
     *
     * Malik ne apni tasveer par do cheezon ke neeche laal lakeer khainchi:
     * qatar ka number, aur paer ka SERVICE. Dono halke the — number grey
     * 10px, SERVICE 11px.
     *
     * Jaanch ULTI likhi gayi hai — "purani qeemat gayi ya nahi" — sidhi
     * nahi. Wajah tajruba hai: ek dafa dashboard par `.68rem` isi liye bach
     * gaya tha ke har "nayi qeemat mojood hai?" wali jaanch pehli baar me
     * pass ho gayi, aur purani qeemat neeche kisi doosre qaide me zinda
     * rahi. Sidhi jaanch nayi satar dekh kar khush ho jati hai; ulti jaanch
     * purani satar par kaat-ti hai.
     */
    public function test_the_serial_and_the_service_word_stay_big(): void
    {
        $css = view('tenant.catering.documents.partials.kitchen-sheet-style', ['isUr' => true])->render();

        // Probe zinda hai? Dono qaide CSS me mojood hone chahiyen, warna
        // neeche wali "purani qeemat nahi mili" khali file par bhi pass ho
        // jayegi.
        $this->assertSame(1, preg_match('/td\.sr\s*\{([^}]*)\}/', $css, $sr),
            'ginti ka qaida CSS me milna chahiye');
        $this->assertSame(1, preg_match('/\.svc\s*\{([^}]*)\}/s', $css, $svc),
            'SERVICE ka qaida CSS me milna chahiye');

        // Ginti: kaali aur bold, 13px se chhoti nahi — aur purana 10px gaya.
        $this->assertStringNotContainsString('font-size: 10px', $sr[1],
            'ginti wapas 10px par chali gayi');
        $this->assertStringContainsString('font-weight: bold', $sr[1],
            'ginti bold honi chahiye — bawarchi-khane me ye adad pukara jata hai');
        $this->assertSame(1, preg_match('/font-size:\s*([\d.]+)px/', $sr[1], $m));
        $this->assertGreaterThanOrEqual(13, (float) $m[1], 'ginti 13px se chhoti na ho');

        // SERVICE: purana 11px gaya, ab 15px se chhota nahi.
        $this->assertStringNotContainsString('font-size: 11px', $svc[1],
            'SERVICE wapas 11px par chala gaya');
        $this->assertSame(1, preg_match('/font-size:\s*([\d.]+)px/', $svc[1], $m));
        $this->assertGreaterThanOrEqual(15, (float) $m[1], 'SERVICE 15px se chhota na ho');
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

        // Needle property ki TARTEEB se azaad hai. Pehle wo
        // "direction: rtl; line-height:" dhoondti thi; jis din `.ur` me beech
        // me `font-size` aaya, probe andha ho gaya — leading 1.7 se 1.6 par
        // giri aur ye test us par khamosh raha. Ab `.ur` ke poore block me se
        // line-height nikalti hai, property kahin bhi ho.
        $this->assertSame(1, preg_match('/\.ur\s*\{[^}]*line-height:\s*([\d.]+)/', $css, $m),
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
        // KITCHEN-SHEET-NO-COURSE-HEADINGS-1 (26 Sep): malik ne pattiyan hatwa
        // din. Tarteeb qayam hai — wo CateringCourseOrderMySqlTest me parkhi
        // jati hai — magar sar-naame ab nahi chhapte.
        $this->assertSame(0, substr_count($html, 'class="course"'),
            'course ki patti ab nahi chhapti');
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

        // KITCHEN-SHEET-A5-1 (27 Sep): kaghaz ab SETTING se aata hai, aur naap
        // usi kaghaz par honi chahiye jo parcha waqai istemaal karta hai.
        // Pehle yahan 'a4','portrait' likha tha; jis din parcha A5 hua, ye
        // test us kaghaz ko naapta raha jo product chhapta hi nahi — ek aisa
        // adad jo na sach tha na jhoot.
        $paper = \App\Models\Tenant\CateringSetting::tenantDefault()->kitchen_sheet_paper ?: 'a5_portrait';
        [$size, $orientation] = match ($paper) {
            'a5_landscape' => ['a5', 'landscape'],
            'a4_landscape' => ['a4', 'landscape'],
            'a4_portrait' => ['a4', 'portrait'],
            default => ['a5', 'portrait'],
        };

        $pdf = new Dompdf($options);
        $pdf->setPaper($size, $orientation);
        // Kitchen sheet browser se chhapti hai, dompdf se nahi — is liye dompdf
        // us ke @media print qawaid nahi lagata aur screen wali body (chaurai +
        // padding + min-height) page box se takra jati hai. Wohi qawaid yahan
        // haath se lagaye ja rahe hain jo browser khud lagata hai.
        $html = str_replace('</head>',
            '<style>body{width:auto!important;min-height:0!important;margin:0!important;'
            .'padding:0!important;box-shadow:none!important}</style></head>',
            $this->sheetHtml($count, $lang));
        $pdf->loadHtml($html, 'UTF-8');
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
