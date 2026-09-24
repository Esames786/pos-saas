<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinancialPositionService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-A4-FIT-1 — jo ek safhe me dikhta hai wo ek safhe par chhape.
 *
 * Client (22 Sep) ne 19 line ki booking confirmation bheji: screen par ek
 * safha, printer se do. Prod par naapa gaya to us document ke 2 safhe the — aur
 * 10 line wali estimate ke bhi 2.
 *
 * Yahan RAAI ka imtihan nahi liya ja raha. Document waqai dompdf se draw hota
 * hai aur uske SAFHE GINE jate hain — wohi cheez jis ki client ne shikayat ki.
 * CSS ke padding ginna is ka mutabadil nahi: kaun sa millimeter kahan gaya, ye
 * sirf renderer bata sakta hai.
 *
 * Do taraf se pehra:
 *   • aam lambai ka document EK safhe par rahe (warna shikayat wapas)
 *   • lamba document ab bhi KAI safhon par jaye (warna hum ne content kaat
 *     diya hoga, jo is se bura hai)
 */
class CateringDocumentA4FitMySqlTest extends MySqlTenantTestCase
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
            'units', 'products', 'categories', 'customers', 'branches',
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

        // Quotation bheji nahi ja sakti jab tak Catering Material Rate na ho,
        // aur invoice banti hi tab hai jab quotation bheji aur qubool ki ja
        // chuki ho. Ye asli qawaid hain — fixture ko poora raasta chalna hai.
        \App\Models\Tenant\CateringMaterialRate::create([
            'product_id' => $this->productId, 'rate' => 800, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
    }

    /**
     * Client ka asli document: 19 lines. Prod par ye 2 safhe tha.
     */
    public function test_a_nineteen_line_booking_fits_on_one_page(): void
    {
        $this->assertSame(1, $this->pagesFor(19),
            '19 line ka document ek A4 safhe par aana chahiye — yehi client ki shikayat thi');
    }

    /**
     * Prod par naapi gayi hadd 23 lines thi. Ye test us hadd ko pathar par nahi
     * likh raha — sirf ye ke aam lambai (20) aaram se andar hai. Hadd ke bilkul
     * upar test lagana aisa test banata hai jo kisi din aik lafz lamba naam
     * aane par toot jaye.
     */
    public function test_a_twenty_line_booking_still_fits(): void
    {
        $this->assertSame(1, $this->pagesFor(20));
    }

    /**
     * Ye us se zyada ahem hai. Jagah bachane ka sab se aasan aur sab se bura
     * tareeqa ye hota ke content ko kaat diya jaye. Lamba document ab bhi
     * poora chhapna chahiye, kai safhon par.
     */
    public function test_a_long_booking_still_flows_onto_more_pages(): void
    {
        $this->assertGreaterThan(1, $this->pagesFor(60),
            '60 line ka document kai safhon par jana chahiye — kaat kar ek safhe me nahi samana');
    }

    /** 60 lines chhapne par koi line gaib bhi nahi honi chahiye. */
    public function test_nothing_is_dropped_from_a_long_booking(): void
    {
        $html = $this->htmlFor(60);

        // Pehli aur AAKHRI line, dono kaghaz par hon.
        $this->assertStringContainsString('Dish 1 ', $html);
        $this->assertStringContainsString('Dish 60 ', $html);
        $this->assertSame(60, substr_count($html, 'Dish '), 'saath ki saath lines chhapni chahiyen');
    }

    /**
     * Final invoice usi booking se banti hai, is liye us me utni hi lines hoti
     * hain — aur uska items table aur spacing quotation se bilkul aik jaise
     * hain. Us par bhi wohi geometry lagai gayi hai; ye test us ka pehra hai,
     * warna kal wohi shikayat dusre kaghaz se aayegi.
     */
    public function test_the_final_invoice_of_the_same_booking_also_fits(): void
    {
        $this->assertSame(1, $this->invoicePagesFor(19),
            '19 line ki booking ki invoice bhi ek safhe par aani chahiye');
    }

    private function invoicePagesFor(int $lineCount): int
    {
        $estimate = $this->estimateWith($lineCount);

        $estimates = app(CateringEstimateService::class);
        $estimates->markSent($estimate);
        $estimates->markAccepted($estimate->refresh());

        $invoice = app(\App\Services\Catering\CateringFinalInvoiceService::class)
            ->issue($estimate->event->refresh());

        $html = view('tenant.catering.documents.final-invoice', [
            'invoice' => $invoice->fresh('event'),
            'event' => $invoice->event,
            'lang' => 'en',
            'pdf' => true,
            'businessName' => 'Kashif Kitchen',
        ])->render();

        return $this->pageCount($html);
    }

    private function pagesFor(int $lineCount): int
    {
        return $this->pageCount($this->htmlFor($lineCount));
    }

    /** Wohi renderer jo CateringDocumentController::asPdf() chalata hai. */
    private function pageCount(string $html): int
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->setPaper('a4', 'portrait');
        $pdf->loadHtml($html);
        $pdf->render();

        return $pdf->getCanvas()->get_page_count();
    }

    /** Wohi view aur wohi data jo CateringDocumentController::asPdf() deta hai. */
    private function htmlFor(int $lineCount): string
    {
        $estimate = $this->estimateWith($lineCount);

        return view('tenant.catering.documents.estimate', [
            'estimate' => $estimate,
            'event' => $estimate->event,
            'lang' => 'en',
            'pdf' => true,
            'position' => app(CateringFinancialPositionService::class)->position($estimate->event),
            'advanceTotal' => 0,
            'businessName' => 'Kashif Kitchen',
        ])->render();
    }

    /** Client ke asli document jaisa: 75 pax, shaam 8 baje. */
    private function estimateWith(int $lineCount): CateringEstimate
    {
        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. Fazal Mairaj',
            'customer_phone' => '03452291667',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'service_time' => '20:00',
            'pax' => 75,
        ]);

        $lines = [];
        for ($i = 1; $i <= $lineCount; $i++) {
            $lines[] = [
                'product_id' => $this->productId,
                'item_name' => "Dish {$i} ",
                'quantity' => 10, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 1300,
            ];
        }

        return $estimates->saveDraftLines($event->currentEstimate, $lines, [])->fresh(['lines', 'event']);
    }
}
