<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Api\PrintAgentApiController;
use App\Http\Controllers\Tenant\Catering\CateringBulkDocumentController;
use App\Http\Controllers\Tenant\Catering\CateringDocumentController;
use App\Models\Master\Tenant;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringSetting;
use App\Models\Tenant\PrintAgent;
use App\Models\Tenant\Printer;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\PrintPrepare;
use App\Services\Catering\CateringDocumentQueueService;
use App\Services\Catering\CateringEstimateService;
use App\Support\TenantFeatures;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRINT-PREPARE-1 (11 Nov) — screen khulte hi parcha tayyar; Send par sirf printer ka waqt.
 *
 * Owner: "user sirf ye screen khole, abhi print na bheje, to agent saara kaam kar le … jaise hi send to network kare
 * foran print ho … ye saare screen pe — proforma, kitchen, quotation, invoice — English, Urdu, Both … order update ho
 * to agent updated file load kare … purane agent / thermal kharab na ho."
 *
 * Sab se ahem do baatein: (1) tayyari ka fingerprint aur Send ka fingerprint BARABAR hon — warna tayyari chup-chaap
 * bekaar; (2) badla hua order kabhi purani PDF se na chhape. Aur purane agent ko tayyari ka kaam kabhi na mile.
 */
class PrintPrepareMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $productId;

    private Printer $printer;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'print_prepares', 'print_jobs', 'print_agent_commands', 'print_agents', 'catering_printer_mappings',
            'category_printer_mappings', 'printers',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_final_invoices', 'catering_advances', 'catering_refunds',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_settings',
            'journal_lines', 'journal_entries', 'accounts', 'payment_methods',
            'customers', 'product_translations', 'units', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch();
        $unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'RICE']), [
            'name' => 'Biryani', 'sku' => 'PP1', 'unit_id' => $unitId, 'default_purchase_price' => 400,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400, 'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        CateringSetting::create(['quotation_paper' => 'a4_portrait', 'kitchen_sheet_paper' => 'a5_portrait',
            'print_language_profile' => CateringSetting::PRINT_BOTH]);

        $this->printer = Printer::create([
            'name' => 'Office A4/A5', 'code' => 'DOC-'.Str::random(5), 'printer_type' => Printer::TYPE_WINDOWS,
            'print_role' => Printer::ROLE_DOCUMENT, 'windows_printer_name' => 'HP LaserJet P2055dn',
            'paper_size' => 'A4', 'is_active' => true,
        ]);

        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        Gate::before(fn (?\App\Models\Tenant\User $u = null) => true);
        $user = \App\Models\Tenant\User::on('tenant')->find($this->makeUser(['employee_code' => 'PP'.Str::random(4)]));
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');

        $this->switchTo(true);
    }

    // ── Switch ─────────────────────────────────────────────────────────────

    public function test_with_the_switch_off_nothing_is_prepared_and_the_page_is_unchanged(): void
    {
        $this->switchTo(false);
        $event = $this->booking();

        $res = $this->bulk('prepare', 'kitchen_sheet', $event, 'ur');
        $this->assertSame('off', $res['state']);
        $this->assertSame(0, PrintPrepare::count(), 'switch band par koi tayyari nahi');

        $html = app(CateringDocumentController::class)
            ->kitchenSheetPreview(Request::create('/x', 'GET', ['lang' => 'ur']), $event)->render();
        $this->assertStringNotContainsString('data-stn-prepare', $html, 'switch band: safha bilkul pehle jaisa');
        $this->assertStringContainsString('Send to network', $html);

        $this->switchTo(true);
        $html = app(CateringDocumentController::class)
            ->kitchenSheetPreview(Request::create('/x', 'GET', ['lang' => 'ur']), $event)->render();
        $this->assertStringContainsString('data-stn-prepare', $html, 'switch ON: safha tayyari mangta hai');
        $this->assertStringContainsString('data-prep-refresh', $html, '↻ ka button');
    }

    // ── Sab se ahem: tayyari aur Send ek hi parcha pehchaanein ──────────────

    public function test_send_carries_the_same_fingerprint_as_the_prepared_pdf_for_every_document_and_language(): void
    {
        $event = $this->booking();

        foreach (['kitchen_sheet', 'quotation', 'proforma'] as $kind) {
            foreach (['en', 'ur', 'both'] as $lang) {
                $prepared = $this->bulk('prepare', $kind, $event, $lang)['items'][0]['fingerprint'];

                $before = (int) PrintJob::max('id');
                $this->bulk('send', $kind, $event, $lang);
                $job = PrintJob::where('id', '>', $before)->latest('id')->firstOrFail();

                $this->assertSame($prepared, $job->payload['fingerprint'] ?? null,
                    "{$kind}/{$lang}: Send ka parcha tayyar PDF se nahi milta — tayyari bekaar");
            }
        }

        // Har document + zubaan ki apni PDF: 3 × 3 = 9 alag fingerprint.
        $this->assertSame(9, PrintPrepare::count(), 'har parcha aur har zubaan alag');
    }

    public function test_the_printed_time_does_not_change_the_fingerprint_but_the_order_does(): void
    {
        $html = '<p>Biryani 10 KG</p><span dir="ltr"><!--printed-at-->Printed 11 Nov 2026 5:01 PM<!--/printed-at--></span>';
        $later = str_replace('5:01 PM', '5:09 PM', $html);
        $changed = str_replace('10 KG', '12 KG', $html);

        $this->assertSame(CateringDocumentQueueService::fingerprint($html, 'a5_portrait'),
            CateringDocumentQueueService::fingerprint($later, 'a5_portrait'), 'sirf waqt badla — wohi parcha');
        $this->assertNotSame(CateringDocumentQueueService::fingerprint($html, 'a5_portrait'),
            CateringDocumentQueueService::fingerprint($changed, 'a5_portrait'), 'miqdaar badli — naya parcha');
        $this->assertNotSame(CateringDocumentQueueService::fingerprint($html, 'a5_portrait'),
            CateringDocumentQueueService::fingerprint($html, 'a4_portrait'), 'kaghaz badla — naya parcha');
    }

    public function test_opening_the_same_page_again_does_not_prepare_twice(): void
    {
        $event = $this->booking();

        $a = $this->bulk('prepare', 'kitchen_sheet', $event, 'en')['items'][0]['fingerprint'];
        $b = $this->bulk('prepare', 'kitchen_sheet', $event, 'en')['items'][0]['fingerprint'];

        $this->assertSame($a, $b);
        $this->assertSame(1, PrintPrepare::count(), 'wohi safha dobara khula — nayi tayyari nahi');
    }

    // ── Order badla ─────────────────────────────────────────────────────────

    public function test_a_changed_order_is_noticed_and_prepared_again(): void
    {
        $event = $this->booking(sent: false);
        $old = $this->bulk('prepare', 'kitchen_sheet', $event, 'en')['items'][0]['fingerprint'];

        $same = $this->bulk('status', 'kitchen_sheet', $event, 'en', ['known' => [$event->id => $old]]);
        $this->assertFalse($same['changed'], 'kuch nahi badla');

        app(CateringEstimateService::class)->saveDraftLines($event->refresh()->currentEstimate, [
            ['product_id' => $this->productId, 'item_name' => 'Biryani', 'quantity' => 25, 'rate' => 500],
        ]);

        $res = $this->bulk('status', 'kitchen_sheet', $event->refresh(), 'en', ['known' => [$event->id => $old]]);
        $this->assertTrue($res['changed'], 'order badla — safhe ko batana chahiye');
        $this->assertNotSame($old, $res['items'][0]['fingerprint']);
        $this->assertSame(2, PrintPrepare::count(), 'naye order ki tayyari khud lag gayi');

        $before = (int) PrintJob::max('id');
        $this->bulk('send', 'kitchen_sheet', $event, 'en');
        $job = PrintJob::where('id', '>', $before)->firstOrFail();
        $this->assertNotSame($old, $job->payload['fingerprint'], 'Send kabhi purane order ki PDF nahi mangta');
        $this->assertStringContainsString('25', (string) $job->raw_payload, 'aur parcha aaj ka order hai');
    }

    public function test_a_ready_pdf_older_than_nine_minutes_is_prepared_again(): void
    {
        $event = $this->booking();
        $fp = $this->bulk('prepare', 'kitchen_sheet', $event, 'en')['items'][0]['fingerprint'];
        PrintPrepare::where('fingerprint', $fp)->update(['status' => 'ready', 'ready_at' => now()->subMinutes(10)]);

        $res = $this->bulk('status', 'kitchen_sheet', $event, 'en', ['known' => [$event->id => $fp]]);

        $this->assertSame('preparing', $res['state'], 'purani PDF par kaghaz ka waqt purana — nayi chahiye');
        $this->assertSame('queued', PrintPrepare::where('fingerprint', $fp)->value('status'));
    }

    // ── Agent: kaun, kab ─────────────────────────────────────────────────────

    public function test_only_an_agent_that_can_prepare_gets_the_work_and_never_ahead_of_a_real_print(): void
    {
        $event = $this->booking();
        $this->bulk('prepare', 'kitchen_sheet', $event, 'en');
        [$old, $oldToken] = $this->agent('Old 2.6.6');
        [$new, $newToken] = $this->agent('New 2.7.0');

        $this->assertSame([], $this->pending($old, $oldToken, 'document')['prepares'] ?? [], 'purane agent ko kabhi nahi');

        $got = $this->pending($new, $newToken, 'document,prepare');
        $this->assertCount(1, $got['prepares'], 'naye agent ko tayyari');
        $this->assertSame('HP LaserJet P2055dn', $got['prepares'][0]['windows_printer_name']);
        $this->assertStringContainsString('<html', $got['prepares'][0]['html']);

        // Asli parcha aaya: usi poll me tayyari nahi.
        $this->bulk('prepare', 'kitchen_sheet', $event, 'en', ['force' => '1']);
        $this->bulk('send', 'quotation', $event, 'en');
        $next = $this->pending($new, $newToken, 'document,prepare');
        $this->assertCount(1, $next['jobs'], 'asli parcha');
        $this->assertSame([], $next['prepares'], 'asli parche ke saath tayyari nahi — asli pehle');
    }

    public function test_only_the_agent_that_took_the_work_can_mark_it_ready(): void
    {
        $event = $this->booking();
        $this->bulk('prepare', 'kitchen_sheet', $event, 'en');
        [$a, $aToken] = $this->agent('A');
        [$b, $bToken] = $this->agent('B');
        $id = $this->pending($a, $aToken, 'document,prepare')['prepares'][0]['id'];
        $row = PrintPrepare::findOrFail($id);

        try {
            app(PrintAgentApiController::class)->prepareReady($this->agentRequest('/x', $b, $bToken), $row);
            $this->fail('doosre agent ne tayyar likh diya');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        app(PrintAgentApiController::class)->prepareReady($this->agentRequest('/x', $a, $aToken), $row);
        $this->assertSame('ready', $row->refresh()->status);
        $this->assertSame('ready', $this->bulk('status', 'kitchen_sheet', $event, 'en')['state']);
    }

    public function test_heartbeat_keeps_the_agent_version_and_the_server_sets_the_poll(): void
    {
        [$agent, $token] = $this->agent('Kitchen PC');
        $beat = fn () => json_decode(app(PrintAgentApiController::class)
            ->heartbeat($this->agentRequest('/x', $agent, $token, ['agent_version' => '2.7.0']))->getContent(), true);

        $this->assertSame(1000, $beat()['poll_ms'], 'switch ON: har second');
        $this->assertSame('2.7.0', $agent->refresh()->agent_version);

        $this->switchTo(false);
        $this->assertSame(3000, $beat()['poll_ms'], 'switch band: pehle jaisa 3 second');
    }

    public function test_old_prepare_rows_are_cleared_after_a_day(): void
    {
        $event = $this->booking();
        $fp = $this->bulk('prepare', 'kitchen_sheet', $event, 'en')['items'][0]['fingerprint'];
        $this->bulk('prepare', 'kitchen_sheet', $event, 'ur');
        PrintPrepare::where('fingerprint', $fp)->update(['updated_at' => now()->subDays(2)]);

        $this->assertSame(1, PrintPrepare::purgeOlderThanADay(dryRun: true));
        $this->assertSame(2, PrintPrepare::count(), 'dry run kuch nahi mitata');
        $this->assertSame(1, PrintPrepare::purgeOlderThanADay());
        $this->assertSame(1, PrintPrepare::count(), 'sirf purani mitti, aaj wali bachi');
    }

    public function test_the_final_invoice_page_prepares_the_same_document_send_prints(): void
    {
        $event = $this->booking();
        DB::connection('tenant')->table('catering_events')->where('id', $event->id)
            ->update(['event_date' => now()->subDays(2)->toDateString()]);
        app(\App\Services\Catering\CateringAdvanceService::class)->record($event->refresh(), [
            'amount' => 50000, 'received_date' => now()->toDateString(), 'payment_method_id' => $this->makePaymentMethod(),
        ]);
        $invoice = app(\App\Services\Catering\CateringFinalInvoiceService::class)->issue($event->refresh());

        $res = json_decode(app(CateringDocumentController::class)->printFinalInvoice(
            Request::create('/x', 'POST', ['printer_id' => $this->printer->id, 'lang' => 'ur', 'mode' => 'prepare']), $invoice
        )->getContent(), true);
        $this->assertSame('preparing', $res['state']);

        $before = (int) PrintJob::max('id');
        app(CateringDocumentController::class)->printFinalInvoice(
            Request::create('/x', 'POST', ['printer_id' => $this->printer->id, 'lang' => 'ur', 'reprint' => '1']), $invoice
        );
        $job = PrintJob::where('id', '>', $before)->firstOrFail();
        $this->assertSame($res['items'][0]['fingerprint'], $job->payload['fingerprint'], 'invoice: tayyari aur Send ek parcha');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function switchTo(bool $on): void
    {
        app()->instance('tenant', new Tenant([
            'tenant_code' => 'pptest', 'business_name' => 'Prepare Kitchen', 'features' => [TenantFeatures::PRINT_PREPARE => $on],
        ]));
        $this->assertSame($on, TenantFeatures::on(TenantFeatures::PRINT_PREPARE));
    }

    /** Wohi form jo safha bhejta hai — `mode` ke saath. */
    private function bulk(string $mode, string $kind, CateringEvent $event, string $lang, array $extra = []): array
    {
        $response = app(CateringBulkDocumentController::class)->printToNetwork(Request::create('/catering/documents/bulk/print', 'POST', [
            'kind' => $kind, 'ids' => [$event->id], 'printer_id' => $this->printer->id, 'reprint' => '1',
            'lang' => $lang, 'mode' => $mode,
        ] + $extra));

        return $response instanceof \Illuminate\Http\JsonResponse ? json_decode($response->getContent(), true) : [];
    }

    private function booking(bool $sent = true): CateringEvent
    {
        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'MR. PREPARE', 'customer_phone' => '03005550003',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(6)->toDateString(), 'pax' => 80,
        ]);
        $estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->productId, 'item_name' => 'Biryani', 'item_name_ur' => 'بریانی', 'quantity' => 10, 'rate' => 5000],
        ]);
        if ($sent) {
            $estimates->markSent($event->currentEstimate->refresh());
            $estimates->markAccepted($event->currentEstimate->refresh());
        }

        return $event->refresh();
    }

    private function agent(string $name): array
    {
        $token = Str::random(40);
        $agent = PrintAgent::create([
            'name' => $name, 'agent_code' => 'AG-'.Str::random(8), 'token_hash' => Hash::make($token), 'is_active' => true,
        ]);

        return [$agent, $token];
    }

    private function agentRequest(string $uri, PrintAgent $agent, string $token, array $body = [], ?string $caps = null): Request
    {
        $request = Request::create($uri, $body === [] ? 'GET' : 'POST', $body);
        $request->headers->set('X-Print-Agent-Code', $agent->agent_code);
        $request->headers->set('X-Print-Agent-Token', $token);
        if ($caps !== null) {
            $request->headers->set('X-Print-Agent-Caps', $caps);
        }

        return $request;
    }

    private function pending(PrintAgent $agent, string $token, string $caps): array
    {
        $response = app(PrintAgentApiController::class)->pending($this->agentRequest('/api/print-agent/pending', $agent, $token, [], $caps));

        return json_decode($response->getContent(), true);
    }
}
