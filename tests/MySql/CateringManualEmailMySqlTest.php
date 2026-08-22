<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringDocumentEmailController;
use App\Mail\Catering\CateringCustomerMail;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-EMAIL-1 — the operator's Email to Customer / Resend button.
 *
 * The automatic sends already exist and are idempotent by claim; the manual
 * button is the opposite by design — a deliberate ADDITIONAL delivery attempt
 * with its own log row. What must never change: a draft is refused (emailing
 * is not finalizing), a missing address is a message not an exception, and no
 * business state moves — stock, ledger, lifecycle and document all stay put.
 */
class CateringManualEmailMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private int $branchId;

    private int $productId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_email_logs',
            'catering_estimate_line_instruction', 'catering_instructions',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_final_invoices',
            'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi', 'unit_id' => $this->unitId]);
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->productId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'recipe']
        );
        CateringMaterialRate::create([
            'product_id' => $this->productId, 'rate' => 900, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
    }

    private function booking(?string $email = 'customer@example.com'): CateringEstimate
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Email Customer',
            'customer_email' => $email,
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(4)->toDateString(),
            'pax' => 40,
        ]);

        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Chicken Karahi',
            'quantity' => 8, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 900,
        ]]);

        return $event->refresh()->currentEstimate;
    }

    private function emailEstimate(CateringEstimate $estimate)
    {
        $request = Request::create('/x', 'POST');
        $request->setLaravelSession(app('session.store'));
        app('request')->setLaravelSession(app('session.store'));

        return app(CateringDocumentEmailController::class)->emailEstimate($request, $estimate->refresh());
    }

    private function stateFingerprint(CateringEvent $event): array
    {
        $db = DB::connection('tenant');
        $event->refresh();
        $estimate = $event->currentEstimate?->refresh();

        return [
            'journal_lines' => $db->table('journal_lines')->count(),
            'stock_ledgers' => $db->table('stock_ledgers')->count(),
            'event_status' => $event->status,
            'estimate_status' => $estimate?->status,
            'grand_total' => (string) $estimate?->grand_total,
        ];
    }

    public function test_a_draft_is_refused_and_stays_a_draft(): void
    {
        $estimate = $this->booking();
        $before = $this->stateFingerprint($estimate->event);

        $response = $this->emailEstimate($estimate);

        $this->assertTrue($response->getSession()->has('errors') || $response->isRedirect());
        Mail::assertNothingSent();
        $this->assertSame(0, DB::connection('tenant')->table('catering_email_logs')->count(),
            'a refused draft leaves no delivery attempt behind');
        $this->assertSame('draft', $estimate->refresh()->status, 'emailing must never finalize');
        $this->assertSame($before, $this->stateFingerprint($estimate->event));
    }

    public function test_manual_send_and_resend_are_each_a_deliberate_delivery_with_their_own_log_row(): void
    {
        $estimate = $this->booking();
        $this->estimates->markSent($estimate);
        Mail::fake(); // discard the automatic finalize email; the button is under test

        $logs = fn () => DB::connection('tenant')->table('catering_email_logs')
            ->where('dedupe_key', 'like', 'manual-%')->whereNotNull('sent_at')->count();

        $this->emailEstimate($estimate);
        $this->assertSame(1, $logs());

        // The RESEND: not swallowed by the idempotency claim — a second,
        // deliberate delivery with a second log row.
        $this->emailEstimate($estimate);
        $this->assertSame(2, $logs());

        Mail::assertSent(CateringCustomerMail::class, 2);
        $this->assertSame(2, DB::connection('tenant')->table('catering_email_logs')
            ->where('recipient', 'customer@example.com')->where('dedupe_key', 'like', 'manual-%')->count());
    }

    public function test_a_missing_address_is_a_message_not_an_exception_and_sends_nothing(): void
    {
        $estimate = $this->booking(null);
        $this->estimates->markSent($estimate);
        Mail::fake();

        $response = $this->emailEstimate($estimate);

        $this->assertTrue($response->isRedirect());
        Mail::assertNothingSent();
        $this->assertSame(0, DB::connection('tenant')->table('catering_email_logs')
            ->where('dedupe_key', 'like', 'manual-%')->count());
    }

    public function test_manual_email_mutates_no_business_state(): void
    {
        $estimate = $this->booking();
        $this->estimates->markSent($estimate);
        Mail::fake();
        $before = $this->stateFingerprint($estimate->event);

        $this->emailEstimate($estimate);
        $this->emailEstimate($estimate);

        $this->assertSame($before, $this->stateFingerprint($estimate->event),
            'no GL, no stock, no lifecycle, no repricing — an email is only an email');
    }

    public function test_the_permissions_are_cataloged_for_granting(): void
    {
        // The catalog groups live in a const map; the source file is the contract.
        $src = file_get_contents(app_path('Services/Permissions/PermissionCatalogService.php'));

        foreach ([
            "'tenant.catering.estimates.email' => 'Email Customer Documents'",
            "'tenant.catering.final-invoices.email' => 'Email Customer Documents'",
            "'tenant.catering.making-adjustment.index' => 'Making Adjustment'",
            "'tenant.catering.making-adjustment.apply-products' => 'Apply Making Adjustment'",
            "'tenant.catering.making-adjustment.apply-drafts' => 'Apply Making Adjustment'",
        ] as $entry) {
            $this->assertStringContainsString($entry, $src, "catalog must group: {$entry}");
        }
    }
}
