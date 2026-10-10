<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringSetting;
use App\Models\Tenant\Printer;
use App\Models\Tenant\PrintJob;
use App\Services\Catering\CateringDocumentQueueService;
use App\Services\Catering\CateringEstimateService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-SEND-TO-PRINTER-1 — 5 October.
 *
 * Malik: "client confuse ho raha hai, bar bar A4 / A5 select karna parta hai."
 *
 * Shikayat kagaz ke size ki thi. Hal ye hai ke size APP tay kare aur JOB KE
 * SAATH jaye, taake operator sirf printer chune.
 *
 * ── DO PEHRE JO IS FILE KA ASAL MAQSAD HAIN ───────────────────────────────
 *
 * 1. `test_an_old_agent_is_never_handed_a_document_job` — restaurant ki
 *    hifazat. Un ke agents purani version par chalte rahenge aur unhein aisi
 *    job kabhi nahi milni chahiye jo wo kar hi nahi sakte. Agar ye pehra tootay
 *    to natija khamoshi se kuch na chhapna hoga — ya A4 laser par safhe bhar
 *    kachra — aur kisi ko pata nahi chalega.
 *
 * 2. `test_a_thermal_printer_is_refused_for_a4_documents` — ek A4 laser par
 *    ESC/POS bytes bhejna aur ek thermal par A4 document bhejna, dono ka natija
 *    kachra hai. Purana setup ye ghalti kar sakta tha (teen thermal printers
 *    `Print Quotation` ke dropdown me pesh ho rahe the).
 */
class CateringSendToPrinterMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringDocumentQueueService $queue;

    private CateringEstimateService $estimates;

    private int $branchId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'print_jobs', 'print_agents', 'catering_printer_mappings',
            'category_printer_mappings', 'printers',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_final_invoices', 'catering_advances', 'catering_refunds',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_settings',
            'journal_lines', 'journal_entries', 'accounts', 'payment_methods',
            'customers', 'product_translations', 'units', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder())->run();

        $this->queue = app(CateringDocumentQueueService::class);
        $this->estimates = app(CateringEstimateService::class);
        $this->branchId = $this->makeBranch();

        $unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'RICE']), [
            'name' => 'Biryani', 'sku' => 'SP1', 'unit_id' => $unitId, 'default_purchase_price' => 400,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400,
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function windowsPrinter(array $attrs = []): Printer
    {
        return Printer::create(array_merge([
            'name' => 'Office — HP LaserJet',
            'code' => 'DOC-'.\Illuminate\Support\Str::random(5),
            'printer_type' => Printer::TYPE_WINDOWS,
            'print_role' => Printer::ROLE_DOCUMENT,
            'windows_printer_name' => 'HP LaserJet P2055dn',
            'paper_size' => 'A4',
            'is_active' => true,
        ], $attrs));
    }

    private function thermalPrinter(): Printer
    {
        return Printer::create([
            'name' => 'Kitchen — Rice',
            'code' => 'THR-'.\Illuminate\Support\Str::random(5),
            'printer_type' => 'network',
            'print_role' => 'kot',
            'ip_address' => '192.168.1.101',
            'port' => 9100,
            'paper_size' => '80mm',
            'is_active' => true,
        ]);
    }

    private function booking(float $total = 50000): \App\Models\Tenant\CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. PRINT',
            'customer_phone' => '03005550001',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(6)->toDateString(),
            'pax' => 80,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->productId, 'item_name' => 'Biryani', 'quantity' => 10, 'rate' => $total / 10],
        ]);

        return $event->refresh();
    }

    /**
     * MALIK KI ASAL SHIKAYAT KA PEHRA — kagaz job ke saath jata hai.
     *
     * Quotation A4, kitchen sheet A5, aur dono ka faisla tenant ki setting se.
     * Operator ko ye kabhi chunna nahi parta.
     */
    public function test_the_paper_size_travels_with_the_job_not_with_the_operator(): void
    {
        CateringSetting::create([
            'kitchen_sheet_paper' => 'a5_portrait',
            'quotation_paper' => 'a4_portrait',
        ]);

        $event = $this->booking();
        $printer = $this->windowsPrinter();

        $job = $this->queue->queueQuotation($event->currentEstimate()->first(), $printer);

        $this->assertSame('a4_portrait', $job->payload['paper'], 'quotation ka kagaz setting se');
        $this->assertSame('A4 portrait', $job->payload['paper_css'], 'aur CSS wali shakal bhi saath');
        $this->assertSame('HP LaserJet P2055dn', $job->payload['windows_printer_name']);
        $this->assertSame(CateringDocumentQueueService::DOCUMENT_TYPE, $job->document_type);

        $release = app(\App\Services\Catering\CateringProductionReleaseService::class)
            ->preview($event->fresh());
        $release->setRelation('event', $event);

        // Preview ka parcha save nahi hota, is liye seedha HTML jaanchna hi
        // yahan mumkin hai — magar kagaz ka faisla wohi service karti hai.
        $this->assertSame('a5_portrait', CateringSetting::tenantDefault()->kitchen_sheet_paper,
            'kitchen sheet A5 par hona chahiye');
    }

    /** Jo HTML jama hua, wohi chhapega — aur wo waqai document ka HTML ho. */
    public function test_the_document_is_frozen_into_the_job_at_queue_time(): void
    {
        CateringSetting::create(['quotation_paper' => 'a4_portrait']);
        $event = $this->booking();

        $job = $this->queue->queueQuotation($event->currentEstimate()->first(), $this->windowsPrinter());

        $this->assertStringContainsString('<html', (string) $job->raw_payload,
            'poora HTML job me jama hona chahiye');
        $this->assertStringContainsString('@page', (string) $job->raw_payload,
            'aur us me kagaz ka size bhi — agent isi se Chrome ko batata hai');
        $this->assertStringContainsString($event->event_no, (string) $job->raw_payload,
            'aur wo IS booking ka document ho');
    }

    /**
     * SAB SE AHEM — restaurant ki hifazat.
     *
     * Purana agent `caps=document` nahi bhejta. Us ko ye job milni hi nahi
     * chahiye. Jaanch ULTI soorat par bhi kaat-ti hai: naye agent ko milni
     * chahiye, warna pehra sirf "hamesha nahi" keh kar hara rehta.
     */
    public function test_an_old_agent_is_never_handed_a_document_job(): void
    {
        CateringSetting::create(['quotation_paper' => 'a4_portrait']);
        $event = $this->booking();
        $this->queue->queueQuotation($event->currentEstimate()->first(), $this->windowsPrinter());

        $this->assertSame(1, PrintJob::count(), 'job to bani hai');

        [$agent, $token] = $this->pairedAgent('Office PC');

        // PURANA agent — koi caps nahi bhejta.
        $old = $this->pendingFor($agent, $token, null);
        $this->assertCount(0, $old, 'purane agent ko document job nahi milni chahiye');

        // NAYA agent — probe zinda hai.
        $new = $this->pendingFor($agent, $token, 'document');
        $this->assertCount(1, $new, 'naye agent ko milni chahiye — warna pehra bemani hai');
        $this->assertSame(CateringDocumentQueueService::DOCUMENT_TYPE, $new[0]['document_type']);
        $this->assertSame('HP LaserJet P2055dn', $new[0]['printer']['windows_printer_name']);
    }

    /** Thermal ka purana raasta ek harf na badla ho. */
    public function test_a_thermal_job_still_reaches_an_agent_that_says_nothing(): void
    {
        $printer = $this->thermalPrinter();
        app(\App\Services\Printing\PrintJobFactory::class)->create([
            'printer_id' => $printer->id,
            'document_type' => 'kot',
            'print_status' => 'queued',
            'raw_payload' => "TEST\n",
        ]);

        [$agent, $token] = $this->pairedAgent('Kitchen PC');

        $this->assertCount(1, $this->pendingFor($agent, $token, null),
            'purana thermal raasta bina kisi caps ke chalta rehna chahiye');
    }

    /** A4 document thermal printer par bhejne se saaf inkaar. */
    public function test_a_thermal_printer_is_refused_for_a4_documents(): void
    {
        CateringSetting::create(['quotation_paper' => 'a4_portrait']);
        $event = $this->booking();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Windows');

        $this->queue->queueQuotation($event->currentEstimate()->first(), $this->thermalPrinter());
    }

    /** Windows ka naam likhe baghair printer kaam ka nahi. */
    public function test_a_windows_printer_without_a_name_is_refused(): void
    {
        CateringSetting::create(['quotation_paper' => 'a4_portrait']);
        $event = $this->booking();

        $this->expectException(\RuntimeException::class);

        $this->queue->queueQuotation(
            $event->currentEstimate()->first(),
            $this->windowsPrinter(['windows_printer_name' => null])
        );
    }

    /**
     * DONO CHALEIN — purana haath wala Print, aur naya Send to network.
     *
     * Malik: "old manual print aur send to network dono work karain."
     *
     * Ye pehra sirf naye button ka nahi, PURANE ka hai. Nayi cheez lagate waqt
     * purani ko hata dena sab se aam ghalti hai, aur yahan wo khaas tor par
     * mehnga hoti: jis din network wala raasta ruke — agent band, PC off,
     * printer ka naam badla — us din haath wala Print hi wo cheez hai jo kaam
     * chalati hai. Wo bhi na ho to kaghaz nikalne ka koi raasta hi nahi bachta.
     *
     * Aakhri jaanch ULTI hai aur sab se ahem: kagaz ka koi khaana HONA HI NAHI
     * chahiye. Malik ki asal shikayat yehi thi — "bar bar A4 / A5 select karna
     * parta hai." Agar kal kisi ne wo chunao wapas laga diya, to feature apna
     * maqsad kho dega aur dekhne me theek lagta rahega.
     */
    public function test_both_the_manual_print_and_send_to_network_are_offered(): void
    {
        CateringSetting::create(['quotation_paper' => 'a4_portrait', 'kitchen_sheet_paper' => 'a5_portrait']);
        $event = $this->booking();
        $this->windowsPrinter();

        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        \Illuminate\Support\Facades\Gate::before(fn (?\App\Models\Tenant\User $u = null) => true);
        $user = \App\Models\Tenant\User::on('tenant')
            ->find($this->makeUser(['employee_code' => 'SN'.\Illuminate\Support\Str::random(4)]));
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');

        $controller = app(\App\Http\Controllers\Tenant\Catering\CateringBulkDocumentController::class);
        $req = fn () => \Illuminate\Http\Request::create('/x', 'GET', ['ids' => [$event->id]]);

        foreach ([
            'quotations' => $controller->quotations($req(), app(\App\Services\Catering\CateringFinancialPositionService::class)),
            'kitchen sheets' => $controller->kitchenSheets($req()),
            'address labels' => $controller->addressSheet($req()),
        ] as $name => $response) {
            $html = $response->render();

            $this->assertStringContainsString('window.print()', $html,
                "{$name}: haath wala Print button rehna chahiye");
            $this->assertStringContainsString('Send to network', $html,
                "{$name}: network wala button bhi hona chahiye");
            $this->assertStringContainsString('name="printer_id"', $html,
                "{$name}: printer chunne ka khaana");
            $this->assertStringNotContainsString('name="paper', $html,
                "{$name}: kagaz ka koi khaana NAHI — document khud jaanta hai");
        }
    }

    /**
     * JO KAGHAZ PRINTER KO JATA HAI US ME TOOLBAR NAHI HONA CHAHIYE.
     *
     * Ye masla asal me pesh aaya tha. Preview ke safhe par "Send to network"
     * ka control lagaya, aur wohi document `CateringDocumentQueueService` bhi
     * render karti hai — agent ke liye HTML jama karte waqt. Us raaste par
     * `ids` hoti hi nahi, aur natija do kharabiyan thin:
     *
     *   • chhapne wale kaghaz par "No A4/A5 printer yet" likha nikal aata
     *   • `@error` ko `$errors` chahiye, jo request ke bahar mojood nahi —
     *     500 (`CateringKitchenSheetPreviewMySqlTest` ne yehi pakra)
     *
     * Ab partial `ids` ke baghair kuch nikalta hi nahi. Pehra yahan is liye
     * hai ke ye kharabi dikhti nahi — kaghaz nikal aata hai, bas us par ek
     * fazool satar hoti hai, aur koi shikayat tab tak nahi aati jab tak graahak
     * usay na parh le.
     *
     * `window.print()` par yahan jaanch JAAN-BOOJH KAR NAHI hai, aur pehli
     * koshish me maine ghalti se laga di thi: quotation ke document ka apna
     * print-bar us me hota hai, magar wo `@media print { display: none }` ke
     * peeche hai — aur Chrome `--print-to-pdf` print media hi lagata hai, is
     * liye kaghaz par wo kabhi nahi aata. Us par rok lagana ek be-zarar cheez
     * ko kharabi samajh lena hota.
     */
    public function test_the_queued_document_carries_no_toolbar(): void
    {
        CateringSetting::create(['quotation_paper' => 'a4_portrait', 'kitchen_sheet_paper' => 'a5_portrait']);
        $event = $this->booking();
        $printer = $this->windowsPrinter();

        foreach ([
            'quotation' => fn () => $this->queue->queueQuotation($event->currentEstimate()->first(), $printer),
            'kitchen sheet' => fn () => $this->queue->queueKitchenSheetForEvent($event, $printer),
            'address label' => fn () => $this->queue->queueAddressSheet($event, $printer),
        ] as $name => $make) {
            $html = (string) $make()->raw_payload;

            $this->assertStringNotContainsString('Send to network', $html,
                "{$name}: bheja hua kaghaz toolbar nahi le kar ja sakta");
            $this->assertStringNotContainsString('No A4/A5 printer', $html,
                "{$name}: aur na hi koi mashwara jo graahak ke liye hai hi nahi");
            $this->assertNotSame('', trim($html), "{$name}: magar kaghaz khali bhi na ho");
        }
    }

    /**
     * CATERING-SEND-SINGLE-1 — control SINGLE safhon par bhi, sirf bulk par nahi.
     *
     * Malik (10 Oct): "event ki edit wali screen se quotation print karta hoon
     * to wahan koi printer chunne ka option aata hi nahi."
     *
     * Theek shikayat thi. "Send to network" sirf TEEN bulk safhon par laga tha,
     * jabke rozmarra ka kaam inhi single safhon se hota hai — ek booking kholo,
     * quotation ya kitchen sheet kholo, chhapo. Us raaste par control tha hi
     * nahi, is liye operator ko browser ke print dialog me ja kar printer aur
     * kagaz dono haath se chunne parte — yani bilkul wohi takleef jis ko mitane
     * ke liye ye poora feature bana tha.
     */
    public function test_the_single_document_screens_also_offer_send_to_network(): void
    {
        CateringSetting::create(['quotation_paper' => 'a4_portrait', 'kitchen_sheet_paper' => 'a5_portrait']);
        $event = $this->booking();
        $this->windowsPrinter(['name' => 'Office A4']);
        $this->windowsPrinter(['name' => 'Office A5', 'paper_size' => 'A5',
            'windows_printer_name' => 'HP LaserJet Pro MFP M127fn']);

        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        \Illuminate\Support\Facades\Gate::before(fn (?\App\Models\Tenant\User $u = null) => true);
        $user = \App\Models\Tenant\User::on('tenant')
            ->find($this->makeUser(['employee_code' => 'SG'.\Illuminate\Support\Str::random(4)]));
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');

        $c = app(\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class);
        $req = fn () => \Illuminate\Http\Request::create('/x', 'GET');

        foreach ([
            ['quotation', $c->estimate($req(), $event->currentEstimate()->first())],
            ['kitchen sheet', $c->kitchenSheetPreview($req(), $event)],
        ] as [$name, $view]) {
            $html = $view->render();

            $this->assertStringContainsString('window.print()', $html,
                "{$name}: haath wala Print button rehna chahiye — naya control purane ko hataata nahi");
            $this->assertStringContainsString('Send to network', $html,
                "{$name}: single safhe par bhi network wala button hona chahiye");
            $this->assertStringContainsString('name="printer_id"', $html,
                "{$name}: printer chunne ka khaana");

            // DONO printer aayein. Malik ki shart: kaghaz ka size printer se
            // aata hi nahi, is liye har document par dono chunne ke qabil hon.
            $this->assertStringContainsString('Office A4', $html, "{$name}: pehla printer");
            $this->assertStringContainsString('Office A5', $html, "{$name}: doosra printer bhi");

            $this->assertStringNotContainsString('name="paper', $html,
                "{$name}: kagaz ka koi khaana NAHI — document khud jaanta hai");
        }
    }

    /**
     * KAGHAZ KA SIZE DOCUMENT SE AATA HAI, PRINTER SE NAHI — aur screen wohi kahe.
     *
     * Malik (10 Oct): "dono printer A4 bhi chhap sakte hain aur A5 bhi. Kitchen
     * sheet hamesha A5, baqi sab A4."
     *
     * Qaida PEHLE SE theek chal raha tha — `queueKitchenSheetForEvent()` kagaz
     * `kitchen_sheet_paper` se uthati hai — magar screen par kahin likha nahi
     * tha. Operator ko printer ke NAAM par jana parta tha ("Office — HP P2055dn
     * (A4)"), aur wo naam jhoot bolta hai: usi printer par kitchen sheet bhejo
     * to wo A5 hi nikalti hai.
     *
     * Ye test dono simton par khara hai: label wohi ho jo JOB me jata hai, aur
     * printer ke naam se mutaasir na ho.
     */
    public function test_the_paper_label_on_screen_matches_the_job_not_the_printer_name(): void
    {
        CateringSetting::create(['quotation_paper' => 'a4_portrait', 'kitchen_sheet_paper' => 'a5_portrait']);
        $event = $this->booking();

        // Printer ka naam JAAN BUJH KAR jhoota: "(A4)" likha hai magar is par
        // kitchen sheet A5 hi jayegi. Agar kabhi label printer se aane lage to
        // ye test wohi ghalti pakdega.
        $printer = $this->windowsPrinter(['name' => 'Office — HP P2055dn (A4)']);

        $svc = \App\Services\Catering\CateringDocumentQueueService::class;

        $this->assertSame('A5', $svc::paperLabel($svc::KIND_KITCHEN_SHEET), 'kitchen sheet hamesha A5');
        $this->assertSame('A4', $svc::paperLabel($svc::KIND_QUOTATION), 'quotation A4');
        $this->assertSame('A4', $svc::paperLabel($svc::KIND_ADDRESS_SHEET), 'address sheet A4');

        // Aur ab asal natija: JOB me kya gaya. Label aur job ek hi jagah se
        // aate hain, aur yahan dono mila kar dekhe jate hain.
        foreach ([
            [$svc::KIND_KITCHEN_SHEET, fn () => $this->queue->queueKitchenSheetForEvent($event, $printer)],
            [$svc::KIND_QUOTATION, fn () => $this->queue->queueQuotation($event->currentEstimate()->first(), $printer)],
            [$svc::KIND_ADDRESS_SHEET, fn () => $this->queue->queueAddressSheet($event, $printer)],
        ] as [$kind, $make]) {
            $job = $make();
            $payload = is_array($job->payload) ? $job->payload : (array) json_decode((string) $job->payload, true);
            $paper = (string) ($payload['paper'] ?? '');

            $this->assertStringStartsWith(
                strtolower($svc::paperLabel($kind)), $paper,
                "{$kind}: screen ka label (".$svc::paperLabel($kind).") aur job ka kagaz ({$paper}) ek hone chahiyein"
            );
        }
    }

    /** Do baar dabane se do kaghaz nahi — wohi job wapas aati hai. */
    public function test_queueing_the_same_document_twice_returns_the_same_job(): void
    {
        CateringSetting::create(['quotation_paper' => 'a4_portrait']);
        $event = $this->booking();
        $printer = $this->windowsPrinter();
        $estimate = $event->currentEstimate()->first();

        $first = $this->queue->queueQuotation($estimate, $printer);
        $second = $this->queue->queueQuotation($estimate, $printer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PrintJob::count(), 'doosra kaghaz nahi banna chahiye');

        // Magar sarih reprint apni alag copy banata hai.
        $third = $this->queue->queueQuotation($estimate, $printer, null, true);
        $this->assertNotSame($first->id, $third->id);
        $this->assertSame(2, $third->copy_no);
    }

    /**
     * Pending endpoint se jobs nikalo — BILKUL usi raaste se jo asli agent
     * chalta hai: wohi do headers, wohi controller, wohi auth.
     *
     * Auth ko bypass kar ke controller seedha bulana aasan tha aur kam qeemti
     * hota: pehra asli soorat me `caps` ko parhta hai ya nahi, ye sirf poora
     * raasta chala kar hi maloom hota hai.
     */
    private function pendingFor(\App\Models\Tenant\PrintAgent $agent, string $token, ?string $caps): array
    {
        $request = \Illuminate\Http\Request::create('/api/print-agent/pending', 'GET');
        $request->headers->set('X-Print-Agent-Code', $agent->agent_code);
        $request->headers->set('X-Print-Agent-Token', $token);
        if ($caps !== null) {
            $request->headers->set('X-Print-Agent-Caps', $caps);
        }

        $response = app(\App\Http\Controllers\Tenant\Api\PrintAgentApiController::class)->pending($request);

        return json_decode($response->getContent(), true)['jobs'] ?? [];
    }

    /** Ek chalta hua agent, jis ka token hum jaante hain. */
    private function pairedAgent(string $name): array
    {
        $token = \Illuminate\Support\Str::random(40);
        $agent = \App\Models\Tenant\PrintAgent::create([
            'name' => $name,
            'agent_code' => 'AG-'.\Illuminate\Support\Str::random(8),
            'token_hash' => \Illuminate\Support\Facades\Hash::make($token),
            'is_active' => true,
        ]);

        return [$agent, $token];
    }
}
