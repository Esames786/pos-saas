<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductPackUnit;
use App\Services\Printing\EscPosPayloadService;
use App\Services\Reports\SalesReportEngine;
use App\Support\QtyMix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\IsolatedPharmacyStore;

/**
 * UNITS-PACKS batch, feature 4 (23 Oct) — the prints and the reports say what the number is.
 *  - the receipt (thermal and browser) and the sale screen put "2 Bag = 5 Kilogram" / "250 g = 0.25 Kilogram" under
 *    the line; a plain line gets nothing new;
 *  - the item sales report and its CSV carry the stock unit beside the quantities, counted in that unit;
 *  - the stock list and the valuation report give the unit and, where the item has packs, the packs in words;
 *  - a deal's returns are counted in the same unit as its sales;
 *  - a total of kg and pieces is never one number: the screen, CSV, A4 and thermal prints, the ESC/POS report and the
 *    dashboard show its parts ("5.25 kg · 26 pcs"); a total of counted items alone looks exactly as before.
 */
class UnitsPacksPrintsReportsMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;

    private const STORE_CODE = 'urtest';
    private const STORE_SUFFIX = '_ur';
    private const STORE_FEATURES = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true, 'counter_packs' => true];
    private const OFF = ['product_screen_v2' => true, 'purchasing_v2' => true];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
    }

    public function test_the_receipt_and_the_sale_screen_say_2_bag_and_250_g(): void
    {
        [$rice, $bag] = $this->rice('UR Rice Print');
        $saleId = $this->sellBagsAndGrams($rice, $bag);
        $loose = $this->product('UR Soap Print', $this->piece()->id, ['default_selling_price' => 90]);
        $this->receive($loose, 'base', '60', ['base' => '5'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($this->sell([['product_id' => $loose->id, 'quantity' => 2, 'unit_price' => 90]], 180)->isSuccessful());
        $plainId = $this->lastSale()->id;

        $job = $this->receiptJob($saleId);
        $thermal = app(EscPosPayloadService::class)->build($job);
        $this->assertStringContainsString('2 Bag = 5 Kilogram', $thermal);
        $this->assertStringContainsString('250 g = 0.25 Kilogram', $thermal);
        $browser = $this->owner()->get($this->url('/printing/documents/' . $job->id . '/receipt'))->assertOk()->getContent();
        $this->assertStringContainsString('2 Bag = 5 Kilogram', $browser);
        $this->assertStringContainsString('250 g = 0.25 Kilogram', $browser);

        $screen = $this->owner()->get($this->url('/sales-orders/' . $saleId))->assertOk()->getContent();
        $this->assertStringContainsString('2 Bag = 5 Kilogram', $screen);
        $this->assertStringContainsString('250 g = 0.25 Kilogram', $screen);

        $plain = app(EscPosPayloadService::class)->build($this->receiptJob($plainId));
        $this->assertStringNotContainsString('2 Piece', $plain, 'a plain line prints as today: no words under it');
        $this->assertStringNotContainsString('2 Piece', $this->owner()->get($this->url('/sales-orders/' . $plainId))->assertOk()->getContent());
    }

    public function test_the_item_report_and_its_csv_count_in_the_stock_unit_and_name_it(): void
    {
        [$rice, $bag] = $this->rice('UR Rice Report');
        $saleId = $this->sellBagsAndGrams($rice, $bag);
        $bagLine = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->whereNotNull('pack_unit_id')->first();
        $this->giveBack($saleId, $bagLine->id, 1, 500)->assertSessionHasNoErrors();

        $row = collect(app(SalesReportEngine::class)->byItem($this->filters()))->firstWhere('product_id', $rice->id);
        $this->assertSame('Kilogram', $row->unit);
        $this->assertEqualsWithDelta(5.25, $row->sold_qty, 0.0001, '2 Bag + 250 g = 5.25 kg');
        $this->assertEqualsWithDelta(2.5, $row->returned_qty, 0.0001, '1 Bag back = 2.5 kg');
        $this->assertEqualsWithDelta(2.75, $row->net_qty, 0.0001);

        $page = $this->owner()->get($this->url('/reports/center?tab=items&' . http_build_query($this->dates())))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<td>UR Rice Report<\/td>.*?<td>Kilogram<\/td>/s', $page);

        $csv = $this->owner()->get($this->url('/reports/center/export?sections[]=items&' . http_build_query($this->dates())))->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', preg_split('/\r?\n/', trim($csv)));
        $header = collect($lines)->first(fn ($l) => ($l[0] ?? '') === 'Item');
        $this->assertSame(['Item', 'Variant', 'Category', 'Unit', 'Sold Qty'], array_slice($header, 0, 5));
        $riceRow = collect($lines)->first(fn ($l) => ($l[0] ?? '') === 'UR Rice Report');
        $this->assertSame('Kilogram', $riceRow[3]);
        $this->assertEqualsWithDelta(5.25, (float) $riceRow[4], 0.0001);
    }

    public function test_the_stock_list_and_the_valuation_give_the_unit_and_the_packs_in_words(): void
    {
        [$rice, $bag] = $this->rice('UR Rice Stock');
        $saleId = $this->sellBagsAndGrams($rice, $bag);
        $bagLine = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->whereNotNull('pack_unit_id')->first();
        $this->giveBack($saleId, $bagLine->id, 1, 500)->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(7.25, $this->stock($rice), 0.0001, '10 − 5.25 + 2.5');

        $valuation = $this->owner()->get($this->url('/reports/inventory/valuation'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/UR Rice Stock.*?7\.25\d*\s*Kilogram.*?2 Bag 2\.25 Kilogram/s', $valuation);
        $list = $this->owner()->get($this->url('/inventory?search=UR+Rice+Stock'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/7\.250\s*Kilogram.*?2 Bag 2\.25 Kilogram/s', $list);
        $csv = $this->owner()->get($this->url('/reports/inventory/valuation?export_csv=1'))->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($csv)));
        $this->assertSame(['Qty On Hand', 'Unit', 'In Packs'], array_slice($rows[0], 5, 3));
        $riceRow = collect($rows)->first(fn ($r) => ($r[1] ?? '') === 'UR Rice Stock');
        $this->assertSame(['7.250', 'Kilogram', '2 Bag 2.25 Kilogram'], array_slice($riceRow, 5, 3));

        $this->setFeatures(self::OFF);
        $off = $this->owner()->get($this->url('/reports/inventory/valuation'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/UR Rice Stock.*?7\.25\d*\s*Kilogram/s', $off, 'the unit stays');
        $this->assertStringNotContainsString('2 Bag 2.25 Kilogram', $off, 'packs off: no pack words');
    }

    public function test_a_deals_returns_are_counted_in_the_unit_its_sales_are(): void
    {
        [$rice, $bag] = $this->rice('UR Rice Deal');
        $saleId = $this->sellBagsAndGrams($rice, $bag);
        $bagLine = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->whereNotNull('pack_unit_id')->first();
        $this->giveBack($saleId, $bagLine->id, 1, 500)->assertSessionHasNoErrors();
        // A deal header never carries a pack today; filing the real bag line under a deal is the one shortcut here, so
        // the deal's sold side (base, 5) and its return side (1 Bag) are in different units unless both read base.
        $combo = DB::connection('tenant')->table('combos')->insertGetId(['name' => 'UR Rice Deal Box', 'code' => 'URDEAL-' . Str::random(4),
            'price' => 1000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('sales_order_lines')->where('id', $bagLine->id)->update(['combo_id' => $combo, 'line_kind' => 'combo_header']);

        $deal = collect(app(SalesReportEngine::class)->byDeal($this->filters()))->firstWhere('deal', 'UR Rice Deal Box');
        $this->assertEqualsWithDelta(5.0, $deal['sold_qty'], 0.0001);
        $this->assertEqualsWithDelta(2.5, $deal['returned_qty'], 0.0001, 'the bag came back as 2.5 kg, like it sold');
    }

    public function test_a_total_of_kg_and_pieces_is_never_one_number_and_the_prints_say_the_unit(): void
    {
        // One shelf holds rice (kg) and cola (pieces); another holds only soap (pieces).
        $shelf = $this->category('UR Mixed Shelf');
        $countedShelf = $this->category('UR Counted Shelf');
        [$rice, $bag] = $this->rice('UR Rice Mixed');
        $cola = $this->product('UR Cola Mixed', $this->piece()->id, ['default_selling_price' => 100]);
        $soap = $this->product('UR Soap Counted', $this->piece()->id, ['default_selling_price' => 90]);
        Product::whereIn('id', [$rice->id, $cola->id])->update(['category_id' => $shelf]);
        Product::where('id', $soap->id)->update(['category_id' => $countedShelf]);
        $this->receive($cola, 'base', '60', ['base' => '30'])->assertRedirect()->assertSessionHasNoErrors();
        $this->receive($soap, 'base', '60', ['base' => '5'])->assertRedirect()->assertSessionHasNoErrors();
        $this->sellBagsAndGrams($rice, $bag);   // 2 Bag + 250 g = 5.25 kg
        $this->assertTrue($this->sell([['product_id' => $cola->id, 'quantity' => 26, 'unit_price' => 100]], 2600)->isSuccessful());
        $this->assertTrue($this->sell([['product_id' => $soap->id, 'quantity' => 2, 'unit_price' => 90]], 180)->isSuccessful());
        QtyMix::forget();

        $cats = collect(app(SalesReportEngine::class)->byCategory($this->filters()));
        $mixed = $cats->firstWhere('name', 'UR Mixed Shelf');
        $this->assertEqualsWithDelta(31.25, $mixed['sold_qty'], 0.0001, 'the old figure is still there for sorting');
        $this->assertEqualsWithDelta(5.25, $mixed['sold_mix']['kg'], 0.0001);
        $this->assertEqualsWithDelta(26, $mixed['sold_mix']['pcs'], 0.0001);
        $this->assertSame('5.25 kg · 26 pcs', QtyMix::words($mixed['sold_mix']));
        $this->assertSame(['pcs' => 2.0], QtyMix::parts($cats->firstWhere('name', 'UR Counted Shelf')['sold_mix']));

        // The screen: parts, never 31.25; the counted shelf shows its 2.00 as before.
        $page = $this->owner()->get($this->url('/reports/center?tab=categories&' . http_build_query($this->dates())))->assertOk()->getContent();
        $this->assertStringContainsString('5.25 kg · 26.00 pcs', $page);
        $this->assertStringNotContainsString('31.25', $page);
        $this->assertMatchesRegularExpression('/UR Counted Shelf<\/td><td class="text-end">\d+<\/td><td class="text-end">2\.00<\/td>/', $page);

        // CSV: a number stays a number; a mixed total is its parts.
        $csv = $this->owner()->get($this->url('/reports/center/export?sections[]=categories&' . http_build_query($this->dates())))->assertOk()->streamedContent();
        $rows = collect(array_map('str_getcsv', preg_split('/\r?\n/', trim($csv))));
        $this->assertSame('5.25 kg · 26 pcs', $rows->first(fn ($r) => ($r[0] ?? '') === 'UR Mixed Shelf')[3]);
        $this->assertSame('2', $rows->first(fn ($r) => ($r[0] ?? '') === 'UR Counted Shelf')[3]);
        // The overview sheet: the split is not a row of its own, it decides how the quantity row reads.
        $ov = $this->owner()->get($this->url('/reports/center/export?sections[]=overview&' . http_build_query($this->dates())))->assertOk()->streamedContent();
        $ovRows = collect(array_map('str_getcsv', preg_split('/\r?\n/', trim($ov))))->keyBy(0);
        $this->assertFalse($ovRows->has('Sold Mix'), 'no row of arrays in the overview sheet');
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)? kg · \d+ pcs$/', (string) $ovRows->get('Sold Qty')[1]);

        // A4 print: the parts, and an item in kg carries its unit.
        $a4 = $this->owner()->get($this->url('/reports/center/print?mode=a4&sections[]=categories&sections[]=items&' . http_build_query($this->dates())))->assertOk()->getContent();
        $this->assertStringContainsString('5.25 kg · 26 pcs', $a4);
        $this->assertMatchesRegularExpression('/UR Rice Mixed \(UR Rice Mixed\)<\/td><td class="amt">5\.25 kg<\/td>/', $a4);
        $this->assertStringNotContainsString('31.25', $a4);

        // Thermal print view: "mixed" in the narrow column, the parts on a line of their own.
        $thermal = $this->owner()->get($this->url('/reports/center/print?mode=thermal&sections[]=categories&' . http_build_query($this->dates())))->assertOk()->getContent();
        // The shelf's Qty row, column by column: Sold mixed, nothing returned, Net mixed — and 31.25 nowhere.
        $this->assertMatchesRegularExpression('/Qty<\/td><td class="amt">mixed<\/td><td class="amt">0<\/td><td class="amt">mixed<\/td>/', $thermal);
        $this->assertStringNotContainsString('31.25', $thermal);
        $this->assertMatchesRegularExpression('/Sold<\/td><td class="amt" colspan="3">5\.25 kg · 26 pcs<\/td>/', $thermal);

        // The ESC/POS report (network printer): the same, and no line wider than the roll.
        $engine = app(SalesReportEngine::class);
        $f = $this->filters();
        $esc = app(EscPosPayloadService::class)->buildReport(['sections' => ['overview', 'categories', 'items'], 'overview' => $engine->overview($f),
            'categories' => $engine->byCategory($f), 'items' => $engine->byItem($f), 'meta' => ['paper' => '80mm']]);
        $plain = preg_replace('/\x1B.{1,2}|\x1D.{1,2}/s', '', $esc);
        $this->assertStringContainsString('mixed', $plain);
        $this->assertStringContainsString('Sold 5.25 kg · 26 pcs', $plain);
        $this->assertMatchesRegularExpression('/Qty\s+5\.25 kg/', $plain, 'the rice item prints its kg');
        $this->assertStringNotContainsString('31.25', $plain);
        foreach (explode("\n", $plain) as $line) {
            $this->assertLessThanOrEqual(\App\Support\ThermalLayout::columns('80mm'), mb_strlen($line), "too wide for the roll: [{$line}]");
        }
        // The dashboard's category card: DashboardCategoryTodayMySqlTest (the card needs its own fixture).
    }

    private function category(string $name): int
    {
        return (int) DB::connection('tenant')->table('categories')->insertGetId(['name' => $name, 'slug' => Str::slug($name) . '-' . Str::random(4),
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{0: Product, 1: ProductPackUnit} rice at 200 a kg, with a Bag of 2.5 kg and no price of its own */
    private function rice(string $name): array
    {
        $rice = $this->product($name, $this->kg()->id, ['default_selling_price' => 200]);
        ProductPackUnit::create(['product_id' => $rice->id, 'name' => 'Kilogram', 'factor' => 1, 'can_sell' => true, 'can_buy' => true]);
        $bag = ProductPackUnit::create(['product_id' => $rice->id, 'name' => 'Bag', 'factor' => 2.5, 'can_sell' => true, 'can_buy' => true]);
        $this->receive($rice, (string) $bag->id, '400', [(string) $bag->id => '4'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(10, $this->stock($rice), 0.0001);

        return [$rice, $bag];
    }

    /** One sale: 2 Bag (5 kg at 500 a bag) and 250 g (0.25 kg at 200 a kg). */
    private function sellBagsAndGrams(Product $rice, ProductPackUnit $bag): int
    {
        $sale = $this->sell([
            ['product_id' => $rice->id, 'quantity' => 2, 'unit_price' => 500, 'pack_unit_id' => $bag->id],
            ['product_id' => $rice->id, 'quantity' => 0.25, 'unit_price' => 200, 'entered_quantity' => 250, 'entered_unit_code' => 'G'],
        ], 1050);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 300));

        return $this->lastSale()->id;
    }

    private function receiptJob(int $saleId): PrintJob
    {
        $id = DB::connection('tenant')->table('print_jobs')->insertGetId(['job_no' => 'PJ-' . uniqid(), 'copy_no' => 1, 'printer_id' => null,
            'document_type' => 'receipt', 'print_status' => 'queued', 'attempts' => 0, 'reference_type' => 'sales_order', 'reference_id' => $saleId,
            'branch_id' => $this->branchId(), 'created_at' => now(), 'updated_at' => now()]);

        return PrintJob::findOrFail($id);
    }

    private function dates(): array
    {
        $today = app(\App\Support\TenantClock::class)->currentBusinessDate();

        return ['date_from' => $today, 'date_to' => $today];
    }

    private function filters(): array
    {
        return app(SalesReportEngine::class)->normalizeFilters($this->dates());
    }
}
