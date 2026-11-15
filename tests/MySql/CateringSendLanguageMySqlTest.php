<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringBulkDocumentController;
use App\Http\Controllers\Tenant\Catering\CateringDocumentController;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringSetting;
use App\Models\Tenant\Printer;
use App\Models\Tenant\PrintJob;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinancialPositionService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-SEND-LANGUAGE-1 (11 Nov) — jo zubaan screen par, wohi kaghaz par.
 *
 * Client: "kitchen sheet bulk ya single, event screen ke andar ya bahar — Urdu ya
 * sirf English select karo, send to network hamesha English + Urdu bhejta hai.
 * Quotation, proforma, invoice bhi check karo."
 *
 * Wajah: "Send to network" ka form zubaan bhejta hi nahi tha. Kitchen sheet aur
 * quotation printer par hamesha Catering Settings ki default (Kashif Kitchen par
 * "both") jati thi, aur proforma hamesha English. Sirf final invoice theek thi —
 * us ke form me `lang` pehle se tha.
 *
 * Yahan tenant ki default jaan-boojh kar "both" rakhi gayi hai — wohi jo client
 * ke paas hai — taake "en" aur "ur" ka test us default se ALAG nateeja maange.
 * Default ke barabar zubaan chun kar test hara rehta chahe fix na bhi hota.
 */
class CateringSendLanguageMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const URDU_DISH = 'بریانی';

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
        $this->branchId = $this->makeBranch();

        $unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'RICE']), [
            'name' => 'Biryani', 'sku' => 'SL1', 'unit_id' => $unitId, 'default_purchase_price' => 400,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400,
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Client jaisa: settings ki default "both".
        CateringSetting::create([
            'quotation_paper' => 'a4_portrait', 'kitchen_sheet_paper' => 'a5_portrait',
            'print_language_profile' => CateringSetting::PRINT_BOTH,
        ]);

        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        Gate::before(fn (?\App\Models\Tenant\User $u = null) => true);
        $user = \App\Models\Tenant\User::on('tenant')
            ->find($this->makeUser(['employee_code' => 'SL'.\Illuminate\Support\Str::random(4)]));
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');
    }

    // ── 1. Safha jis zubaan me khula hai, wohi form ke saath jati hai ─────────

    public function test_every_preview_page_sends_the_language_it_was_opened_in(): void
    {
        $event = $this->confirmedBooking();
        $this->printer();
        $doc = app(CateringDocumentController::class);
        $bulk = app(CateringBulkDocumentController::class);
        $get = fn (array $q) => Request::create('/x', 'GET', $q);

        foreach ([
            ['kitchen sheet', 'ur', fn () => $doc->kitchenSheetPreview($get(['lang' => 'ur']), $event)],
            ['quotation', 'en', fn () => $doc->estimate($get(['lang' => 'en']), $event->currentEstimate()->first())],
            ['proforma', 'ur', fn () => $doc->proformaInvoice($get(['lang' => 'ur']), $event)],
            ['bulk kitchen sheets', 'en', fn () => $bulk->kitchenSheets($get(['ids' => [$event->id], 'lang' => 'en']))],
            ['bulk quotations', 'ur', fn () => $bulk->quotations($get(['ids' => [$event->id], 'lang' => 'ur']), app(CateringFinancialPositionService::class))],
        ] as [$name, $lang, $page]) {
            $html = $page()->render();

            $this->assertStringContainsString('Send to network', $html, "{$name}: network button");
            $this->assertMatchesRegularExpression('/<input type="hidden" name="lang" value="'.$lang.'">/', $html,
                "{$name}: '{$lang}' me khula safha network form ke saath '{$lang}' nahi bhejta");
        }
    }

    // ── 2. Printer par wohi zubaan ──────────────────────────────────────────

    public function test_each_document_reaches_the_printer_in_the_chosen_language(): void
    {
        $event = $this->confirmedBooking();
        $printer = $this->printer();

        foreach (['kitchen_sheet', 'quotation', 'proforma'] as $kind) {
            foreach (['en', 'ur', 'both'] as $lang) {
                $html = $this->send($kind, $event, $printer, $lang);

                $this->assertSame($lang, $this->languageOf($html),
                    "{$kind}: '{$lang}' chuna magar printer par '{$this->languageOf($html)}' gaya");
            }
        }
    }

    // ── 3. Zubaan na aaye to purana rawaiya ─────────────────────────────────

    public function test_without_a_language_each_document_keeps_its_old_default(): void
    {
        $event = $this->confirmedBooking();
        $printer = $this->printer();

        $this->assertSame('both', $this->languageOf($this->send('kitchen_sheet', $event, $printer, null)),
            'kitchen sheet: zubaan na aaye to settings ki default');
        $this->assertSame('both', $this->languageOf($this->send('quotation', $event, $printer, null)),
            'quotation: zubaan na aaye to settings ki default');
        $this->assertSame('en', $this->languageOf($this->send('proforma', $event, $printer, null)),
            'proforma: zubaan na aaye to pehle jaisa English');
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $event = $this->confirmedBooking();
        $printer = $this->printer();

        try {
            $this->send('kitchen_sheet', $event, $printer, 'fr');
            $this->fail('anjaan zubaan qubool ho gayi');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lang', $e->validator->errors()->toArray());
        }
        $this->assertSame(0, PrintJob::on('tenant')->count(), 'rad hone par koi kaghaz nahi');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** "Send to network" dabana — wohi controller jo form POST karta hai; nayi job ka HTML. */
    private function send(string $kind, CateringEvent $event, Printer $printer, ?string $lang): string
    {
        $before = (int) PrintJob::on('tenant')->max('id');
        $post = ['kind' => $kind, 'ids' => [$event->id], 'printer_id' => $printer->id, 'reprint' => '1'];
        if ($lang !== null) {
            $post['lang'] = $lang;
        }

        app(CateringBulkDocumentController::class)->printToNetwork(Request::create('/catering/documents/bulk/print', 'POST', $post));

        $job = PrintJob::on('tenant')->where('id', '>', $before)->latest('id')->first();
        $this->assertNotNull($job, "{$kind}: koi job nahi bani — ".session('status').' '.json_encode(session('errors')));

        return (string) $job->raw_payload;
    }

    /** Kaghaz ki zubaan, us ke apne nishanon se: Urdu safha RTL; "both" me Urdu naam English ke saath. */
    private function languageOf(string $html): string
    {
        if (str_contains($html, '<html lang="ur"')) {
            return 'ur';
        }

        return str_contains($html, self::URDU_DISH) ? 'both' : 'en';
    }

    private function confirmedBooking(): CateringEvent
    {
        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. LANGUAGE',
            'customer_phone' => '03005550002',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(6)->toDateString(),
            'pax' => 80,
        ]);
        $estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->productId, 'item_name' => 'Biryani', 'item_name_ur' => self::URDU_DISH, 'quantity' => 10, 'rate' => 5000],
        ]);
        $estimates->markSent($event->currentEstimate->refresh());
        $estimates->markAccepted($event->currentEstimate->refresh());

        return $event->refresh();
    }

    private function printer(): Printer
    {
        return Printer::create([
            'name' => 'Office A4/A5',
            'code' => 'DOC-'.\Illuminate\Support\Str::random(5),
            'printer_type' => Printer::TYPE_WINDOWS,
            'print_role' => Printer::ROLE_DOCUMENT,
            'windows_printer_name' => 'HP LaserJet P2055dn',
            'paper_size' => 'A4',
            'is_active' => true,
        ]);
    }
}
