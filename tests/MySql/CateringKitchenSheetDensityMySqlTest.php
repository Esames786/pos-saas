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
     * hai — aur Chrome par, Kashif Kitchen ke ASLI (lambe) naamon ke saath:
     *
     *   27 Sep, pehla daur : Urdu 14, English 15  — purane software ke barabar
     *   27 Sep, doosra daur: Urdu 13, English 15  — Urdu font 21 -> 24px
     *   29 Sep, pehla daur : Urdu 14, English 16  — Urdu 24 -> 21px wapas,
     *                                              English 15 -> 14px
     *   29 Sep, doosra daur: Urdu 13, English 16  — malik ne Urdu wapas 24px
     *                                              par maanga, aur wazan 600
     *
     * DOMPDF KE ADAD 29 Sep ko 12/16 se 8/12 par laaye gaye. Ye parche ka
     * phoolna NAHI hai — Chrome par gunjaish 13 hai. Ye us khaayi ka barhna
     * hai jo dompdf aur Chrome ke darmiyan hai: dompdf ke paas Nastaliq hai
     * hi nahi aur wo 24px par DejaVu se kaam chalata hai, jo kahin chaura
     * hai. Isi liye upar likha hai ke ye adad "client ko itne khane
     * dikhenge" ka daawa nahi — sirf regression par kaat-te hain.
     *
     * Har daur wohi adla-badli hai: jitna bara harf, utne kam khane. 29 Sep
     * ko malik ne Urdu ko 21px par wapas laaya, is liye gunjaish bhi purane
     * software wale 14 par wapas aa gayi.
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
        $this->assertSame(1, $this->pagesFor(8, 'ur'),
            'parcha phool gaya — 8 khane bhi ek safhe par nahi aa rahe, yani wapas "empty space"');

        $this->assertSame(2, $this->pagesFor(12, 'ur'),
            'parcha dobara kas gaya — 12 khane ek safhe par aa gaye, yani harf phir chhote ho gaye');
    }

    /** Aur Urdu par bhi, kyunke client ka sheet Urdu me hi chhapta hai. */
    public function test_the_urdu_sheet_is_the_one_that_must_fit(): void
    {
        $this->assertSame(1, $this->pagesFor(8, 'ur'));
        $this->assertSame(1, $this->pagesFor(8, 'en'));
    }

    /**
     * KITCHEN-SHEET-FOOT-WRAP-1 (29 Sep) — lamba pata safhe se BAHAR na jaye.
     *
     * Malik ne ek parcha bheja jis par pata paer ki tang jagah me nichur kar
     * aath chhoti chhoti satrein ban gaya tha aur parche ki tal se bahar nikal
     * gaya tha. Ye khamosh nakami hai: screen par sab theek lagta hai, matn
     * bas kaghaz se neeche chala jata hai.
     *
     * Do cheezein mil kar ise rokti hain, aur koi ek bhi tootne par pata
     * wapas nichurne lagta hai:
     *   • paer par `flex-wrap: wrap` — warna pata lipat kar agli satar par
     *     ja hi nahi sakta;
     *   • pate ke khaane par `min-width: 0` — is ke baghair flex item apne
     *     matn se chhota hota hi nahi aur lipatne ke bajaye khaane ko
     *     phaila deta hai.
     */
    public function test_a_long_address_can_wrap_onto_its_own_line(): void
    {
        $css = view('tenant.catering.documents.partials.kitchen-sheet-style', ['isUr' => true])->render();

        $this->assertSame(1, preg_match('/\.foot\s*\{([^}]*)\}/s', $css, $foot),
            'paer ka qaida CSS me milna chahiye');
        $this->assertStringContainsString('flex-wrap: wrap', $foot[1],
            'is ke baghair lamba pata agli satar par ja hi nahi sakta');

        $this->assertSame(1, preg_match('/\.foot-r\s*\{([^}]*)\}/s', $css, $right),
            'pate ke khaane ka qaida milna chahiye');
        $this->assertStringContainsString('min-width: 0', $right[1],
            'is ke baghair flex item lipta nahi, khaana phailta hai');
    }

    /**
     * KITCHEN-SHEET-SUPPLY-TAG-1 (29 Sep) — PARTY/OWN ka gehra dabba CHHAPNA
     * chahiye.
     *
     * Ye pehra ek KHAMOSH nakami ke liye hai. Browser print par background
     * rang girte hain — isi file me do jagah (SERVICE ka dabba, preview ki
     * patti) jaan-boojh kar border se banayi gayi hain, isi wajah se. Yahan
     * malik ne gehra dabba maanga, aur harf safed hai.
     *
     * Agar `print-color-adjust` nikal gaya to background gir jayega aur label
     * safed par safed reh kar BILKUL GHAYAB ho jayega. Kuch ghalat nazar nahi
     * aayega — bas parche se khabar chali jayegi ke maal party ka hai ya hamara.
     * Aisi nakami ka pakra jana screen dekh kar mumkin nahi, is liye test.
     *
     * (Wo property waqai kaam karti hai, ye naapa gaya: parcha Chrome se PDF
     * bana kar us me fill rang dhoonda — `.0667 .0941 .1529 rg` nau baar mila.)
     */
    public function test_the_supply_tag_will_actually_print_its_dark_box(): void
    {
        $css = view('tenant.catering.documents.partials.kitchen-sheet-style', ['isUr' => true])->render();

        $this->assertSame(1, preg_match('/\.sup-tag\s*\{([^}]*)\}/s', $css, $tag),
            'PARTY/OWN ke dabbe ka qaida CSS me milna chahiye');

        $this->assertStringContainsString('print-color-adjust: exact', $tag[1],
            'is ke baghair background print par gir jata hai aur safed label ghayab ho jata hai');
        $this->assertStringContainsString('color: #fff', $tag[1],
            'harf safed hai — yehi wajah hai ke upar wali property lazmi hai');
    }

    /**
     * KITCHEN-SHEET-FOOT-BOTTOM-1 (27 Sep) — paer HAMESHA safhe ki tal par.
     *
     * Malik: "jo footer hai wo hamesha bottom mai hi aae, irrespective ek
     * item ho ya 14-15." Pehle paer table ke foran baad chipka tha, is liye
     * 8 khanon wale parche par beech me latak jata tha.
     *
     * Ye teen cheezein MIL KAR kaam karti hain, aur koi ek bhi tootne par
     * paer wapas upar chala jata hai — is liye teenon par pehra hai:
     *   1. body ek khara flex ho
     *   2. paer par `margin-top: auto`
     *   3. print me body ki `min-height` 100% ho, 0 nahi (0 par body sirf
     *      apne matn jitni oonchi hoti aur "neeche" ka koi matlab na rehta)
     *
     * (3) khaas taur par likha ja raha hai kyunke wo purani qeemat is file me
     * mahinon se `min-height: 0` thi aur bilkul maasoom lagti hai.
     */
    public function test_the_footer_is_pinned_to_the_bottom_of_the_page(): void
    {
        $css = view('tenant.catering.documents.partials.kitchen-sheet-style', ['isUr' => true])->render();

        $this->assertSame(1, preg_match('/\.foot\s*\{([^}]*)\}/s', $css, $foot),
            'paer ka qaida CSS me milna chahiye');
        $this->assertStringContainsString('margin-top: auto', $foot[1],
            'paer par margin-top: auto — yehi use tal par le jata hai');
        $this->assertStringNotContainsString('margin-top: 4px', $foot[1],
            'purani chipki hui qeemat wapas aa gayi');

        $this->assertSame(1, preg_match('/@media print\s*\{.*?body\s*\{([^}]*)\}/s', $css, $print),
            'print wala body qaida milna chahiye');
        $this->assertStringContainsString('min-height: 100%', $print[1],
            'print me body poore safhe jitni oonchi ho');
        $this->assertStringNotContainsString('min-height: 0', $print[1],
            'min-height: 0 wapas aa gayi — is par paer beech me latak jata hai');

        $this->assertMatchesRegularExpression('/body\s*\{[^}]*flex-direction:\s*column/s', $css,
            'body khara flex hona chahiye, warna margin-top: auto ka koi asar nahi');
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
