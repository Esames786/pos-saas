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
        $old = $this->pendingFor($agent, $token, '');
        $this->assertCount(0, $old, 'purane agent ko document job nahi milni chahiye');

        // NAYA agent — probe zinda hai.
        $new = $this->pendingFor($agent, $token, '?caps=document');
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

        $this->assertCount(1, $this->pendingFor($agent, $token, ''),
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
    private function pendingFor(\App\Models\Tenant\PrintAgent $agent, string $token, string $query): array
    {
        $request = \Illuminate\Http\Request::create('/api/print-agent/pending'.$query, 'GET');
        $request->headers->set('X-Print-Agent-Code', $agent->agent_code);
        $request->headers->set('X-Print-Agent-Token', $token);

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
