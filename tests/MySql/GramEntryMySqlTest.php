<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\IsolatedPharmacyStore;

/**
 * UNITS-PACKS batch, feature 2 (23 Oct) — a kg item typed in grams at the normal counter.
 *  - 250 g of rice sells 0.250 kg at the kg price; the line remembers "250 g"; stock and money use 0.250 kg. The server
 *    works the quantity out itself: a wrong quantity sent beside "250 g" is not believed.
 *  - 1500 ml of a litre item is 1.5 litre.
 *  - Refused with nothing written: 0.5 g (finer than 0.001 kg), ml for a kg item, g for a counted item.
 *  - A held "250 g" line comes back as 250 g; the POS page offers g and kg for a kg item, nothing for a counted one.
 */
class GramEntryMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;

    private const STORE_CODE = 'getest';
    private const STORE_SUFFIX = '_ge';
    private const STORE_FEATURES = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true];   // the normal counter

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
    }

    public function test_250_g_sells_0_250_kg_at_the_kg_price_and_the_line_says_250_g(): void
    {
        $rice = $this->product('GE Rice', $this->kg()->id, ['default_selling_price' => 300]);
        $this->receive($rice, 'base', '250', ['base' => '5'])->assertRedirect()->assertSessionHasNoErrors();

        $sale = $this->sell([['product_id' => $rice->id, 'quantity' => 1, 'unit_price' => 300, 'entered_quantity' => 250, 'entered_unit_code' => 'G']], 75);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 300));
        $line = $this->lastSale()->lines()->firstOrFail();
        $this->assertSame('0.250', (string) $line->quantity, 'the server worked it out from 250 g, not from the 1 sent beside it');
        $this->assertEqualsWithDelta(75.0, (float) $line->line_total, 0.001, '0.250 kg × 300');
        $this->assertSame('250.000', (string) $line->entered_quantity);
        $this->assertSame('G', $line->entered_unit_code);
        $this->assertEqualsWithDelta(4.75, $this->stock($rice), 0.0001);

        $oil = $this->product('GE Oil', $this->litre()->id, ['default_selling_price' => 600]);
        $this->receive($oil, 'base', '500', ['base' => '10'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($this->sell([['product_id' => $oil->id, 'quantity' => 1.5, 'unit_price' => 600, 'entered_quantity' => 1500, 'entered_unit_code' => 'ML']], 900)->isSuccessful());
        $this->assertSame('1.500', (string) $this->lastSale()->lines()->firstOrFail()->quantity, '1500 ml = 1.5 litre');
        $this->assertEqualsWithDelta(8.5, $this->stock($oil), 0.0001);
    }

    public function test_a_unit_from_another_family_or_finer_than_the_stock_keeps_is_refused_and_nothing_is_written(): void
    {
        $rice = $this->product('GE Brown Rice', $this->kg()->id, ['default_selling_price' => 300]);
        $soap = $this->product('GE Soap', $this->piece()->id, ['default_selling_price' => 90]);
        $this->receive($rice, 'base', '250', ['base' => '5'])->assertRedirect()->assertSessionHasNoErrors();
        $this->receive($soap, 'base', '60', ['base' => '10'])->assertRedirect()->assertSessionHasNoErrors();
        $sales = SalesOrder::count();

        $cases = [
            [[$rice, 1, 0.5, 'G'], 'GE Brown Rice: 0.5 g is finer than the stock keeps — Kilogram is kept to 3 decimals, so the smallest step is 1 g.'],
            [[$rice, 0.25, 250, 'ML'], 'GE Brown Rice is sold in Kilogram — it cannot be entered in ml.'],
            [[$soap, 1, 1000, 'G'], 'GE Soap is sold in Piece — it cannot be entered in g.'],
        ];
        foreach ($cases as [[$p, $qty, $entered, $code], $says]) {
            $r = $this->sell([['product_id' => $p->id, 'quantity' => $qty, 'unit_price' => 300, 'entered_quantity' => $entered, 'entered_unit_code' => $code]], 300);
            $r->assertStatus(422)->assertJsonPath('errors', fn ($e) => str_contains(json_encode($e, JSON_UNESCAPED_UNICODE), $says));
        }
        $this->assertSame($sales, SalesOrder::count(), 'no sale was made');
        $this->assertEqualsWithDelta(5.0, $this->stock($rice), 0.0001);
        $this->assertEqualsWithDelta(10.0, $this->stock($soap), 0.0001);
    }

    public function test_a_held_250_g_line_comes_back_as_250_g_and_the_pos_offers_g_and_kg(): void
    {
        $rice = $this->product('GE Basmati', $this->kg()->id, ['default_selling_price' => 400]);
        $soap = $this->product('GE Hand Soap', $this->piece()->id, ['default_selling_price' => 90]);
        $this->receive($rice, 'base', '300', ['base' => '5'])->assertRedirect()->assertSessionHasNoErrors();

        $held = $this->hold([['product_id' => $rice->id, 'quantity' => 0.25, 'unit_price' => 400, 'entered_quantity' => 250, 'entered_unit_code' => 'G']]);
        $this->assertTrue($held->isSuccessful(), Str::limit($held->getContent(), 300));
        $line = DB::connection('tenant')->table('sales_order_lines')->where('product_id', $rice->id)->orderByDesc('id')->first();
        $this->assertSame('0.250', (string) $line->quantity);
        $this->assertSame('G', $line->entered_unit_code);

        $list = $this->owner()->getJson($this->url('/api/pos/held-sales'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/"entered_quantity":250(\.0+)?,"entered_unit_code":"G"/', $list, 'the recalled line says 250 g');

        $pos = $this->owner()->get($this->url('/pos?branch_id=' . $this->branchId()))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/const products\s*=\s*(\[.*?\]);\s*$/m', $pos, $m), 'the POS page embeds its products');
        $products = collect(json_decode($m[1], true))->keyBy('id');
        $this->assertSame(['G', 'KG'], array_column($products[$rice->id]['entry_units'], 'code'), 'g and kg for a kg item');
        $this->assertEqualsWithDelta(0.001, $products[$rice->id]['entry_units'][0]['to_stock'], 1e-12);
        $this->assertSame([], $products[$soap->id]['entry_units'], 'nothing for a counted item');
    }

    public function test_gram_entry_belongs_to_the_packs_switch_and_off_it_is_todays_counter(): void
    {
        $rice = $this->product('GE Rice Off', $this->kg()->id, ['default_selling_price' => 200]);
        $this->receive($rice, 'base', '150', ['base' => '5'])->assertRedirect()->assertSessionHasNoErrors();
        $this->setFeatures(['product_screen_v2' => true, 'purchasing_v2' => true]);

        $pos = $this->owner()->get($this->url('/pos?branch_id=' . $this->branchId()))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/const products\s*=\s*(\[.*?\]);\s*$/m', $pos, $m));
        $this->assertSame([], collect(json_decode($m[1], true))->firstWhere('id', $rice->id)['entry_units'], 'switch off: the weight popup is today\'s');

        $this->assertTrue($this->sell([['product_id' => $rice->id, 'quantity' => 1, 'unit_price' => 200, 'entered_quantity' => 250, 'entered_unit_code' => 'G']], 200)->isSuccessful());
        $line = $this->lastSale()->lines()->firstOrFail();
        $this->assertSame('1.000', (string) $line->quantity, 'switch off: the quantity sent stands, as before');
        $this->assertNull($line->entered_unit_code);
        $this->assertEqualsWithDelta(4, $this->stock($rice), 0.0001);
    }
}
