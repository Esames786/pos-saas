<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringDocumentController;
use App\Models\Tenant\CateringEstimate;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-TOTALS-ORDER-1 (27 Nov) — quotation / proforma / invoice ke totals.
 *
 * Malik ne quotation ki tasveer par nishan laga kar kaha: pehle Total aaye, phir
 * thora numaya Discount, phir Net Total aur advance. Pehle Discount charges ke
 * saath dabi hui satar thi aur seedha Net Total aa jata tha — graahak ko nazar
 * hi nahi aata tha ke kitni chhoot mili.
 *
 * Document controller ke raaste render hota hai (wohi jo screen dikhati hai) aur
 * totals ki table ki satrein TARTEEB se parhi jati hain. Adad bhi jode jate
 * hain: Total minus Discount plus Tax = Net Total, warna kaghaz ka hisaab
 * graahak ke saamne toot jata.
 */
class CateringDocumentTotalsOrderMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $unitId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'accounts',
            'catering_final_invoices', 'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_settings', 'units', 'products', 'categories', 'customers', 'branches',
        ]);

        // Invoice jaari karna GL me post karta hai — asli raasta, asli khaate.
        (new DefaultChartOfAccountsSeeder)->run();

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory(['name' => 'RICE', 'sort_order' => 2]);
        $this->unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($categoryId, ['unit_id' => $this->unitId]);

        \App\Models\Tenant\CateringMaterialRate::create([
            'product_id' => $this->productId, 'rate' => 800, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
    }

    /** Malik ki tasveer wali surat: charges + discount. */
    public function test_the_quotation_shows_total_then_discount_then_net_total(): void
    {
        $estimate = $this->booking(discount: 1000);

        $rows = $this->totalsRows($this->quotationHtml($estimate, 'en'));

        $this->assertSame(
            ['Subtotal', 'Service Charges', 'Fare', 'Total', 'Discount', 'Net Total'],
            array_column($rows, 'label'),
            'tarteeb: charges → Total → Discount → Net Total'
        );
        $this->assertSame(['grand', 'disc', 'net'], array_column(array_slice($rows, 3, 3), 'class'),
            'Total lakeeron ke beech, Discount numaya, Net Total uske neeche');
        $this->assertSame(['22,000.00', '-1,000.00', '21,000.00'], array_column(array_slice($rows, 3, 3), 'amount'));
        $this->assertTotalsAddUp($rows);
    }

    /** Discount nahi to kaghaz bilkul pehle jaisa — koi faltu "Total" nahi. */
    public function test_without_a_discount_the_quotation_is_unchanged(): void
    {
        $estimate = $this->booking(discount: 0);

        $rows = $this->totalsRows($this->quotationHtml($estimate, 'en'));

        $this->assertSame(['Subtotal', 'Service Charges', 'Fare', 'Net Total'], array_column($rows, 'label'));
        $this->assertSame('grand', end($rows)['class'], 'Net Total hi lakeeron ke beech, jaisa pehle tha');
        $this->assertSame('22,000.00', end($rows)['amount']);
    }

    /** Tax ho to wo Discount ke baad aur Net Total se pehle — aur jor phir bhi mile. */
    public function test_tax_sits_between_discount_and_net_total_and_the_sum_holds(): void
    {
        $estimate = $this->booking(discount: 1000, tax: 500);

        $rows = $this->totalsRows($this->quotationHtml($estimate, 'en'));

        $this->assertSame(['Subtotal', 'Service Charges', 'Fare', 'Total', 'Discount', 'Tax', 'Net Total'],
            array_column($rows, 'label'));
        $this->assertSame('21,500.00', end($rows)['amount']);
        $this->assertTotalsAddUp($rows);
    }

    /** Urdu kaghaz par bhi wohi tarteeb. */
    public function test_the_urdu_quotation_keeps_the_same_order(): void
    {
        $estimate = $this->booking(discount: 1000);

        $rows = $this->totalsRows($this->quotationHtml($estimate, 'ur'));

        $this->assertSame(['کل رقم', 'سروس چارجز', 'Fare', 'ٹوٹل', 'رعایت', 'کل واجب الادا'],
            array_column($rows, 'label'));
    }

    /** Proforma (final-invoice wala view) — wohi tarteeb. */
    public function test_the_proforma_shows_the_same_order(): void
    {
        $estimate = $this->booking(discount: 1000);

        $html = app(CateringDocumentController::class)
            ->proformaInvoice(Request::create('/', 'GET', ['lang' => 'en']), $estimate->event)->render();
        $rows = $this->totalsRows($html);

        $labels = array_column($rows, 'label');
        $this->assertSame(['Subtotal', 'Service Charges', 'Fare', 'Total', 'Discount', 'Net Total'], array_slice($labels, 0, 6));
        $this->assertSame(['grand', 'disc', 'net'], array_column(array_slice($rows, 3, 3), 'class'));
        $this->assertSame('21,000.00', $rows[5]['amount']);
    }

    /** Jaari shuda invoice — wohi tarteeb, aur neeche advance/baqaya waise hi. */
    public function test_the_final_invoice_shows_the_same_order(): void
    {
        $estimate = $this->booking(discount: 1000);
        $estimates = app(CateringEstimateService::class);
        $estimates->markSent($estimate);
        $estimates->markAccepted($estimate->refresh());
        $invoice = app(CateringFinalInvoiceService::class)->issue($estimate->event->refresh());

        $html = app(CateringDocumentController::class)
            ->finalInvoice(Request::create('/', 'GET', ['lang' => 'en']), $invoice->fresh())->render();
        $rows = $this->totalsRows($html);

        $this->assertSame(
            ['Subtotal', 'Service Charges', 'Fare', 'Total', 'Discount', 'Net Total', 'Advances Received', 'Balance Due'],
            array_column($rows, 'label')
        );
        $this->assertSame(['grand', 'disc', 'net'], array_column(array_slice($rows, 3, 3), 'class'));
        $this->assertSame('due', end($rows)['class']);
        $this->assertSame('21,000.00', end($rows)['amount']);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** 10 KG × 1,300 = 13,000; service 2,500; Fare 6,500 → Total 22,000. */
    private function booking(float $discount, float $tax = 0): CateringEstimate
    {
        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'TOTALS ORDER',
            'customer_phone' => '03001234567',
            'booking_date' => now()->toDateString(),
            // Bill event guzarne ke baad banta hai (CATERING-NOTHING-FREEZES-BEFORE-EVENT-1).
            'event_date' => now()->subDays(3)->toDateString(),
            'service_time' => '20:00',
            'pax' => 100,
        ]);

        return $estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Chicken Biryani',
            'quantity' => 10, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 1300,
        ]], [
            'service_charge_amount' => 2500,
            'other_charge_label' => 'Fare',
            'other_charge_amount' => 6500,
            'discount_type' => $discount > 0 ? 'fixed' : 'none',
            'discount_value' => $discount,
            'tax_amount' => $tax,
        ])->fresh(['lines', 'event']);
    }

    private function quotationHtml(CateringEstimate $estimate, string $lang): string
    {
        return app(CateringDocumentController::class)
            ->estimate(Request::create('/', 'GET', ['lang' => $lang]), $estimate)->render();
    }

    /** @return list<array{class: string, label: string, amount: string}> */
    private function totalsRows(string $html): array
    {
        $this->assertSame(1, preg_match('#<table class="totals">(.*?)</table>#s', $html, $table), 'totals ki table nahi mili');
        preg_match_all('#<tr(?: class="([^"]*)")?>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*</tr>#s', $table[1], $m, PREG_SET_ORDER);

        return array_map(fn ($r) => [
            'class' => $r[1],
            'label' => trim(html_entity_decode(strip_tags($r[2]))),
            'amount' => trim(html_entity_decode(strip_tags($r[3]))),
        ], $m);
    }

    /** Total − Discount + Tax = Net Total, kaghaz par chhape adad se. */
    private function assertTotalsAddUp(array $rows): void
    {
        $by = array_column($rows, 'amount', 'label');
        $n = fn (string $label) => (float) str_replace(',', '', $by[$label] ?? '0');

        $this->assertEqualsWithDelta(
            $n('Subtotal') + $n('Service Charges') + $n('Fare'), $n('Total'), 0.001, 'Total = Subtotal + charges');
        $this->assertEqualsWithDelta(
            $n('Total') + $n('Discount') + $n('Tax'), $n('Net Total'), 0.001, 'Total − Discount + Tax = Net Total');
    }
}
