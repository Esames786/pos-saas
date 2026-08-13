<?php

namespace Tests\MySql;

use App\Mail\Catering\CateringCustomerMail;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringSetting;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringLocalizationService;
use App\Services\Catering\CateringMailService;
use App\Services\Catering\CateringProductionReleaseService;
use App\Services\Catering\CateringReminderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-SLICE-1/3 — event + estimate lifecycle invariants (spec §25):
 * quotes never mutate stock/GL/sales, sent estimates are immutable, revisions
 * clone rather than rewrite, production releases are separate immutable
 * documents (not POS KOTs), advances post no finance, emails and reminders
 * are idempotent, and localization falls back to English.
 */
class CateringEstimateLifecycleMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_email_logs', 'catering_event_reminders', 'catering_production_release_lines',
            'catering_production_releases', 'catering_final_invoices', 'catering_advances', 'catering_cost_snapshots',
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_material_rates', 'catering_printer_mappings', 'catering_product_profiles',
            'catering_settings', 'customer_translations', 'supplier_translations',
            'recipe_ingredients', 'recipes', 'unit_conversions', 'units',
            'kot_batch_lines', 'kot_batches', 'print_jobs', 'stock_ledgers', 'stock_balances',
            'journal_lines', 'journal_entries', 'sales_order_lines', 'sales_orders',
            'products', 'categories', 'customers', 'branches',
        ]);

        $this->service = app(CateringEstimateService::class);
    }

    private function eventData(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Mehboob Bhai',
            'customer_name_ur' => 'محبوب بھائی',
            'customer_phone' => '0300-1234567',
            'customer_email' => 'mehboob@example.test',
            'event_type' => 'Walima',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(10)->toDateString(),
            'venue' => 'Shadi Hall A',
            'pax' => 300,
        ], $overrides);
    }

    public function test_event_creation_assigns_sequential_numbers_ulids_and_a_draft_estimate(): void
    {
        $first = $this->service->createEvent($this->eventData());
        $second = $this->service->createEvent($this->eventData());

        $this->assertMatchesRegularExpression('/^EV-\d{8}-0001$/', $first->event_no);
        $this->assertMatchesRegularExpression('/^EV-\d{8}-0002$/', $second->event_no);
        $this->assertNotEmpty($first->event_uuid, 'events must carry a canonical ULID from day one');
        $this->assertSame(26, strlen($first->event_uuid));

        $estimate = $first->currentEstimate;
        $this->assertNotNull($estimate, 'creating an event must open a v1 draft estimate');
        $this->assertSame(1, $estimate->version_no);
        $this->assertSame(CateringEstimate::STATUS_DRAFT, $estimate->status);
        $this->assertNotEmpty($estimate->estimate_uuid);
    }

    public function test_quote_lifecycle_never_touches_stock_gl_sales_or_kot(): void
    {
        $categoryId = $this->makeCategory();
        $productId = $this->makeProduct($categoryId, ['default_purchase_price' => 500]);
        // §2 preferred contract: sending needs an effective Catering Material Rate.
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $productId, 'rate' => 500, 'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $event = $this->service->createEvent($this->eventData());
        $estimate = $event->currentEstimate;

        $this->service->saveDraftLines($estimate, [
            ['product_id' => $productId, 'item_name' => 'Chicken Biryani', 'quantity' => 300, 'rate' => 250],
        ], ['service_charge_amount' => 5000, 'discount_type' => 'percent', 'discount_value' => 10]);

        $this->service->markSent($estimate->refresh());
        $this->service->markAccepted($estimate->refresh());
        $this->service->confirmEvent($event->refresh());

        foreach (['stock_ledgers', 'stock_balances', 'journal_entries', 'journal_lines', 'sales_orders', 'kot_batches', 'print_jobs'] as $table) {
            $this->assertSame(0, (int) $this->tenant()->table($table)->count(),
                "catering quote lifecycle must write ZERO rows to {$table}");
        }

        $estimate->refresh();
        $this->assertSame('75000.00', (string) $estimate->subtotal);
        $this->assertSame('7500.00', (string) $estimate->discount_amount, '10% of 75,000');
        $this->assertSame('72500.00', (string) $estimate->grand_total, '75,000 + 5,000 service − 7,500 discount');
    }

    public function test_sent_estimate_is_commercially_immutable_and_lines_are_frozen(): void
    {
        $event = $this->service->createEvent($this->eventData());
        $estimate = $event->currentEstimate;
        $this->service->saveDraftLines($estimate, [
            ['item_name' => 'Qorma', 'quantity' => 100, 'rate' => 180],
        ]);
        $this->service->markSent($estimate->refresh());

        try {
            $estimate->refresh()->update(['subtotal' => 1]);
            $this->fail('updating a commercial column on a sent estimate must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $line = $estimate->lines()->first();
        try {
            $line->update(['rate' => 999]);
            $this->fail('updating a line of a sent estimate must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $line->delete();
            $this->fail('deleting a line of a sent estimate must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $this->assertSame('18000.00', (string) $estimate->refresh()->grand_total, 'sent totals unchanged');
    }

    public function test_revision_creates_v2_draft_and_supersedes_v1_without_rewriting_it(): void
    {
        $event = $this->service->createEvent($this->eventData());
        $v1 = $event->currentEstimate;
        $this->service->saveDraftLines($v1, [
            ['item_name' => 'Chicken Biryani', 'item_name_ur' => 'چکن بریانی', 'quantity' => 300, 'rate' => 250],
            ['item_name' => 'Raita', 'quantity' => 300, 'rate' => 30],
        ]);
        $this->service->markSent($v1->refresh());

        $v2 = $this->service->revise($v1->refresh());

        $this->assertSame(2, $v2->version_no);
        $this->assertSame(CateringEstimate::STATUS_DRAFT, $v2->status);
        $this->assertSame(2, $v2->lines()->count(), 'revision clones all lines');
        $this->assertSame('چکن بریانی', $v2->lines()->orderBy('sort_order')->first()->item_name_ur);

        $v1->refresh();
        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $v1->status);
        $this->assertNotNull($v1->superseded_at);
        $this->assertSame('84000.00', (string) $v1->grand_total, 'v1 totals untouched by revision');
        $this->assertSame($v2->id, $event->refresh()->currentEstimate->id, 'current estimate is now v2');
    }

    public function test_localization_falls_back_to_english_and_urdu_is_optional(): void
    {
        $localization = app(CateringLocalizationService::class);

        $customerId = $this->tenant()->table('customers')->insertGetId([
            'name' => 'Mehboob Bhai', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $customer = \App\Models\Tenant\Customer::find($customerId);

        $this->assertSame('Mehboob Bhai', $localization->customerName($customer, 'ur'),
            'missing Urdu value must fall back to the base name');

        $localization->setCustomerName($customer, 'ur', 'محبوب بھائی');
        $this->assertSame('محبوب بھائی', $localization->customerName($customer, 'ur'));
        $this->assertSame('Mehboob Bhai', $localization->customerName($customer, 'en'), 'base row untouched');

        $localization->setCustomerName($customer, 'ur', '');
        $this->assertSame('Mehboob Bhai', $localization->customerName($customer, 'ur'),
            'clearing the override restores the English fallback');
    }

    public function test_event_urdu_name_is_remembered_on_the_customer_translation_row(): void
    {
        $customerId = $this->tenant()->table('customers')->insertGetId([
            'name' => 'Mehboob Bhai', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service->createEvent($this->eventData(['customer_id' => $customerId]));

        $this->assertSame('محبوب بھائی', $this->tenant()->table('customer_translations')
            ->where('customer_id', $customerId)->where('language_code', 'ur')->value('name'));
        $this->assertSame('Mehboob Bhai', $this->tenant()->table('customers')
            ->where('id', $customerId)->value('name'), 'stable customers table never modified');
    }

    public function test_advance_records_are_operational_only_no_finance_rows(): void
    {
        $event = $this->service->createEvent($this->eventData());
        $this->service->saveDraftLines($event->currentEstimate, [
            ['item_name' => 'Buffet', 'quantity' => 100, 'rate' => 500], // grand 50,000
        ]);

        \App\Models\Tenant\CateringAdvance::create([
            'catering_event_id' => $event->id,
            'amount' => 25000,
            'received_date' => now()->toDateString(),
        ]);

        foreach (['journal_entries', 'journal_lines', 'cash_bank_account_transactions', 'sale_payments', 'customer_payments'] as $table) {
            $this->assertSame(0, (int) $this->tenant()->table($table)->count(),
                "V1 advances must write ZERO rows to {$table}");
        }
    }

    /** CATERING-V1-CLOSURE-1 (§4): no customer-credit authority in V1 — overpayment refused. */
    public function test_advances_can_reach_but_never_exceed_the_outstanding_balance(): void
    {
        $event = $this->service->createEvent($this->eventData());
        $this->service->saveDraftLines($event->currentEstimate, [
            ['item_name' => 'Buffet', 'quantity' => 100, 'rate' => 685], // grand 68,500
        ]);

        $advance = fn (float $amount) => \App\Models\Tenant\CateringAdvance::create([
            'catering_event_id' => $event->id,
            'amount' => $amount,
            'received_date' => now()->toDateString(),
        ]);

        // advance > balance → refused outright.
        try {
            $advance(100000);
            $this->fail('an advance above the outstanding balance must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exceeds the outstanding balance', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->tenant()->table('catering_advances')->count());

        // advance < balance → allowed; then a second advance overpaying cumulatively → refused.
        $advance(30000);
        try {
            $advance(40000); // 30,000 + 40,000 = 70,000 > 68,500
            $this->fail('cumulative overpayment must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exceeds the outstanding balance', $e->getMessage());
            $this->assertStringContainsString('38,500', $e->getMessage(), 'refusal states the true outstanding amount');
        }

        // advance == remaining balance exactly → allowed; balance closes at zero.
        $advance(38500);
        $this->assertSame('68500.00', (string) $this->tenant()->table('catering_advances')->sum('amount'));

        // and now even 1 more unit is refused.
        try {
            $advance(1);
            $this->fail('any advance on a fully-paid event must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exceeds the outstanding balance', $e->getMessage());
        }
    }

    /** CATERING-V1-CLOSURE-1 (§5): final invoice freezes the bill; closure needs zero balance. */
    public function test_final_invoice_and_closure_lifecycle(): void
    {
        Mail::fake();
        $categoryId = $this->makeCategory();
        $productId = $this->makeProduct($categoryId, ['default_purchase_price' => 400]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $productId, 'rate' => 400, 'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $event = $this->service->createEvent($this->eventData());
        $estimate = $event->currentEstimate;
        $this->service->saveDraftLines($estimate, [
            ['product_id' => $productId, 'item_name' => 'Chicken Biryani', 'item_name_ur' => 'چکن بریانی', 'quantity' => 100, 'rate' => 685],
        ]); // grand 68,500
        $this->service->markSent($estimate->refresh());
        $this->service->markAccepted($estimate->refresh());
        $this->service->confirmEvent($event->refresh());

        \App\Models\Tenant\CateringAdvance::create([
            'catering_event_id' => $event->id, 'amount' => 30000, 'received_date' => now()->toDateString(),
        ]);

        $invoices = app(\App\Services\Catering\CateringFinalInvoiceService::class);

        $invoice = $invoices->issue($event->refresh());

        $this->assertMatchesRegularExpression('/^CI-\d{8}-0001$/', $invoice->invoice_no);
        $this->assertNotEmpty($invoice->invoice_uuid);
        $this->assertSame('68500.00', (string) $invoice->grand_total);
        $this->assertSame('30000.00', (string) $invoice->advance_total);
        $this->assertSame('38500.00', (string) $invoice->balance_due);
        $this->assertSame('چکن بریانی', $invoice->snapshot['lines'][0]['item_name_ur'], 'snapshot carries Urdu names');
        $this->assertSame(CateringEvent::STATUS_COMPLETED, $event->refresh()->status);

        // Immutable document.
        try {
            $invoice->update(['grand_total' => 1]);
            $this->fail('a final invoice must be immutable');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Second invoice refused.
        try {
            $invoices->issue($event->refresh());
            $this->fail('an event cannot be invoiced twice');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already has a final invoice', $e->getMessage());
        }

        // Outstanding balance blocks closure.
        try {
            $invoices->close($event->refresh());
            $this->fail('closure must be refused while a balance is outstanding');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outstanding balance of 38,500.00', $e->getMessage());
        }

        // Settle the exact remainder via the §4 advance flow → closure succeeds.
        \App\Models\Tenant\CateringAdvance::create([
            'catering_event_id' => $event->id, 'amount' => 38500, 'received_date' => now()->toDateString(),
        ]);
        $invoices->close($event->refresh());
        $this->assertSame(CateringEvent::STATUS_CLOSED, $event->refresh()->status);
        $this->assertNotNull($event->closed_at);

        // The whole billing/closure flow stayed off sales/stock/GL.
        foreach (['sales_orders', 'stock_ledgers', 'journal_entries', 'journal_lines', 'cash_bank_account_transactions'] as $table) {
            $this->assertSame(0, (int) $this->tenant()->table($table)->count(),
                "final invoice + closure must write ZERO rows to {$table} in V1");
        }

        // Final-invoice email claimed idempotently.
        Mail::assertSent(\App\Mail\Catering\CateringCustomerMail::class, 1);
        $this->assertSame(1, (int) $this->tenant()->table('catering_email_logs')->where('email_type', 'final_invoice')->count());
    }

    /** §4: an event with no priced estimate cannot take advances at all. */
    public function test_advance_requires_a_priced_estimate(): void
    {
        $event = $this->service->createEvent($this->eventData());

        try {
            \App\Models\Tenant\CateringAdvance::create([
                'catering_event_id' => $event->id,
                'amount' => 1000,
                'received_date' => now()->toDateString(),
            ]);
            $this->fail('advance against an unpriced estimate must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('priced estimate', $e->getMessage());
        }
    }

    public function test_production_release_is_an_immutable_separate_document_not_a_pos_kot(): void
    {
        $categoryId = $this->makeCategory();
        $productId = $this->makeProduct($categoryId);
        $this->tenant()->table('catering_product_profiles')->insert([
            'product_id' => $productId, 'catering_enabled' => 1, 'pricing_mode' => 'per_pax',
            'production_station' => 'Rice', 'production_label' => 'Deg Biryani',
            'production_label_ur' => 'دیگ بریانی', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $productId, 'rate' => 200, 'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $event = $this->service->createEvent($this->eventData());
        $estimate = $event->currentEstimate;
        $this->service->saveDraftLines($estimate, [
            ['product_id' => $productId, 'item_name' => 'Chicken Biryani', 'quantity' => 300, 'rate' => 250, 'instructions' => 'Less spicy'],
        ]);
        $this->service->markSent($estimate->refresh());
        $this->service->confirmEvent($event->refresh());

        $release = app(CateringProductionReleaseService::class)->release($event->refresh());

        $this->assertMatchesRegularExpression('/^PR-\d{8}-0001$/', $release->release_no);
        $this->assertNotEmpty($release->release_uuid);
        $this->assertSame(CateringEvent::STATUS_RELEASED, $event->refresh()->status);

        $line = $release->lines()->first();
        $this->assertSame('Deg Biryani', $line->item_name, 'production label wins on the kitchen document');
        $this->assertSame('دیگ بریانی', $line->item_name_ur);
        $this->assertSame('Rice', $line->production_station);
        $this->assertStringContainsString('Less spicy', (string) $line->instructions);

        // A production release is NOT a POS KOT and moves no stock.
        foreach (['kot_batches', 'kot_batch_lines', 'print_jobs', 'stock_ledgers', 'stock_balances', 'journal_entries'] as $table) {
            $this->assertSame(0, (int) $this->tenant()->table($table)->count(),
                "production release must write ZERO rows to {$table}");
        }

        // Immutable snapshot: only a status flip is allowed.
        try {
            $release->update(['event_snapshot' => ['tampered' => true]]);
            $this->fail('mutating a production release snapshot must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            app(CateringProductionReleaseService::class)->release($event->currentEstimate->event);
            $this->fail('releasing an already-released event must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot release', $e->getMessage());
        }
    }

    public function test_customer_emails_are_idempotent_per_event_type_and_dedupe_key(): void
    {
        Mail::fake();

        $event = $this->service->createEvent($this->eventData());
        $estimate = $event->currentEstimate;
        $this->service->saveDraftLines($estimate, [['item_name' => 'Biryani', 'quantity' => 10, 'rate' => 100]]);
        $mailService = app(CateringMailService::class);

        $first = $mailService->send(CateringCustomerMail::TYPE_QUOTATION_SENT, $event, $estimate->refresh());
        $second = $mailService->send(CateringCustomerMail::TYPE_QUOTATION_SENT, $event, $estimate);

        $this->assertSame('sent', $first);
        $this->assertSame('skipped_already_sent', $second, 'same event+type+version must never double-send');
        Mail::assertSent(CateringCustomerMail::class, 1);

        $noEmailEvent = $this->service->createEvent($this->eventData(['customer_email' => null]));
        $this->assertSame('skipped_no_recipient', $mailService->send(CateringCustomerMail::TYPE_QUOTATION_SENT, $noEmailEvent, null),
            'no hardcoded recipients — missing customer email skips gracefully');
    }

    public function test_event_reminders_claim_before_send_and_never_double_fire(): void
    {
        Mail::fake();

        CateringSetting::tenantDefault()->update([
            'reminder_recipient_email' => 'ops@bingoo.test',
            'reminder_offsets' => ['d3'],
        ]);

        $this->service->createEvent($this->eventData(['event_date' => now()->addDays(3)->toDateString()]));

        $reminders = app(CateringReminderService::class);
        $firstRun = $reminders->dispatchDue();
        $secondRun = $reminders->dispatchDue();

        $this->assertSame(1, $firstRun['sent'], 'D-3 reminder fires on the due day');
        $this->assertSame(0, $secondRun['sent'], 'second scheduler tick must not re-send');
        $this->assertSame(1, $secondRun['skipped']);
        $this->assertSame(1, (int) $this->tenant()->table('catering_event_reminders')->whereNotNull('sent_at')->count());
    }
}
