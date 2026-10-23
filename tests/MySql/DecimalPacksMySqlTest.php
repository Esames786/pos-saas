<?php

namespace Tests\MySql;

use App\Models\Tenant\Product;
use App\Models\Tenant\ProductPackUnit;
use App\Support\PackUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\IsolatedPharmacyStore;

/**
 * UNITS-PACKS batch, feature 1 (23 Oct) — a kg, litre or metre item may come in packs that hold a part: Bag = 2.5 kg.
 *  - the product screen saves Bag = 2.5 kg and Sack = 10 Bag (= 25 kg), and refuses a size that is not more than 1, has
 *    a 4th decimal, or is not whole above the first pack; a counted item still takes whole packs only;
 *  - 4 Bags bought = 10 kg in; 1 Bag back to the supplier = 2.5 kg out; 1 Bag sold at the Bag's own price = 2.5 kg out;
 *    the customer brings it back = 2.5 kg in. Every line keeps the exact size (pack_size 2.5); none needs pack_factor.
 * Through the real routes on an isolated store; old lines are read as stored.
 */
class DecimalPacksMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;

    private const STORE_CODE = 'dptest';
    private const STORE_SUFFIX = '_dp';
    private const STORE_FEATURES = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true, 'medical_counter' => true];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
    }

    /* ── the product screen ─────────────────────────────────────────────── */

    public function test_a_kg_item_saves_a_bag_of_2_5_kg_and_a_sack_of_10_bags_and_wrong_sizes_are_refused(): void
    {
        $row = fn (string $name, string $contains) => ['name' => $name, 'contains' => $contains, 'price' => '', 'barcode' => '', 'sell' => '1', 'buy' => '1'];

        $this->owner()->post($this->url('/products'), $this->form('DP Basmati', $this->kg()->id, [$row('Bag', '2.5'), $row('Sack', '10')]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $rice = Product::where('name', 'DP Basmati')->firstOrFail();
        $sizes = $rice->packUnits()->orderBy('factor')->pluck('factor', 'name')->map(fn ($f) => (float) $f)->all();
        $this->assertSame(['Kilogram' => 1.0, 'Bag' => 2.5, 'Sack' => 25.0], $sizes, '1 Sack = 10 Bag = 25 kg');
        $edit = $this->owner()->get($this->url('/products/' . $rice->id . '/edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="packs\[0\]\[contains\]"[^>]*value="2\.5"/', $edit, 'the screen shows Bag holds 2.5');
        $this->assertMatchesRegularExpression('/name="packs\[1\]\[contains\]"[^>]*value="10"/', $edit, 'and Sack holds 10 Bag');

        $cases = [
            'not more than 1'      => [$this->kg()->id, [$row('Bag', '1')], 'packs.0.contains'],
            'a 4th decimal'        => [$this->kg()->id, [$row('Bag', '2.5555')], 'packs.0.contains'],
            'a part above the bag' => [$this->kg()->id, [$row('Bag', '2.5'), $row('Sack', '2.5')], 'packs.1.contains'],
            'a counted item'       => [$this->piece()->id, [$row('Box', '2.5')], 'packs.0.contains'],
        ];
        foreach ($cases as $what => [$unitId, $packs, $key]) {
            $this->owner()->post($this->url('/products'), $this->form('DP Refused ' . $what, $unitId, $packs))->assertRedirect()->assertSessionHasErrors($key);
            $this->assertFalse(Product::where('name', 'DP Refused ' . $what)->exists(), "{$what}: nothing is saved");
        }
    }

    /* ── bought, returned, sold and returned in Bags of 2.5 kg ───────────── */

    public function test_bags_of_2_5_kg_are_bought_returned_sold_and_come_back_in_kg(): void
    {
        $rice = $this->product('DP Sella Rice', $this->kg()->id, ['default_selling_price' => 300]);
        ProductPackUnit::create(['product_id' => $rice->id, 'name' => 'Kilogram', 'factor' => 1, 'can_sell' => true, 'can_buy' => true]);
        $bag = ProductPackUnit::create(['product_id' => $rice->id, 'name' => 'Bag', 'factor' => 2.5, 'sale_price' => 700, 'can_sell' => true, 'can_buy' => true,
            'barcode' => '2' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT)]);
        $c = DB::connection('tenant');

        // Bought: 4 Bags at 650 a Bag = 10 kg at 260 a kg.
        $this->receive($rice, (string) $bag->id, '650', [(string) $bag->id => '4'])->assertRedirect()->assertSessionHasNoErrors();
        $grnLine = $c->table('goods_receipt_lines')->where('product_id', $rice->id)->orderByDesc('id')->first();
        $this->assertSame('10.000', (string) $grnLine->quantity_received);
        $this->assertSame('2.5000', (string) $grnLine->pack_size, 'the line keeps the Bag\'s exact size');
        $this->assertNull($grnLine->pack_factor, 'the whole-number column is left empty for 2.5');
        $this->assertEqualsWithDelta(260.0, (float) $grnLine->unit_cost, 0.0001, 'cost per kg');
        $this->assertEqualsWithDelta(10.0, $this->stock($rice), 0.0001);

        // Back to the supplier: 1 Bag = 2.5 kg.
        $ret = $this->owner()->call('POST', $this->url('/purchase-returns'), ['_entry_v2' => '1', '_post' => '1', 'branch_id' => $this->branchId(),
            'supplier_id' => $this->supplier()->id, 'return_date' => now()->toDateString(),
            'lines' => [['product_id' => $rice->id, 'buy_pack' => (string) $bag->id, 'price' => '650', 'reason_code' => 'damaged', 'packs' => [(string) $bag->id => '1']]]],
            [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $ret->assertOk();
        $this->assertTrue($ret->json('posted'), Str::limit($ret->getContent(), 300));
        $retLine = $c->table('purchase_return_lines')->where('purchase_return_id', $ret->json('id'))->first();
        $this->assertSame('2.500', (string) $retLine->quantity);
        $this->assertSame('2.5000', (string) $retLine->pack_size);
        $this->assertEqualsWithDelta(7.5, $this->stock($rice), 0.0001);

        // Sold at the counter: 1 Bag at the Bag's own price (700, not 2.5 × 300), 2.5 kg out.
        $sale = $this->sell([['product_id' => $rice->id, 'quantity' => 1, 'pack_unit_id' => $bag->id]], 700);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 300));
        $line = $this->lastSale()->lines()->firstOrFail();
        $this->assertEqualsWithDelta(700.0, (float) $line->unit_price, 0.001);
        $this->assertSame('2.500', (string) $line->base_quantity);
        $this->assertSame('2.5000', (string) $line->pack_size);
        $this->assertNull($line->pack_factor);
        $this->assertSame('Bag', $line->unit_code);
        $this->assertEqualsWithDelta(5.0, $this->stock($rice), 0.0001, '7.5 kg − one 2.5 kg Bag');

        // The customer brings the Bag back: 2.5 kg back on the shelf.
        $this->giveBack($line->sales_order_id, $line->id, 1, 700)->assertSessionHasNoErrors();
        $back = $c->table('sales_return_lines')->where('sales_order_line_id', $line->id)->first();
        $this->assertSame('2.5000', (string) $back->pack_size);
        $this->assertSame('2.500', (string) $back->base_quantity);
        $this->assertEqualsWithDelta(7.5, $this->stock($rice), 0.0001);

        // The stock, said in packs: 7.5 kg = 3 Bag.
        $this->assertSame('3 Bag', PackUnits::describe($rice->packUnits()->get(), 7.5, 'Kilogram'));
        $this->assertSame('2 Bag 1 Kilogram', PackUnits::describe($rice->packUnits()->get(), 6.0, 'Kilogram'));
    }

    /** A product-screen form; $packs answers "Yes, it comes in packs". */
    private function form(string $name, int $unitId, array $packs): array
    {
        return ['_form_v2' => '1', '_card' => 'medicine', 'status' => 'active', 'sku' => '', 'category_id' => '', '_tax' => '0',
            'unit_id' => (string) $unitId, 'name' => $name, 'default_selling_price' => '300', '_buy' => '1', '_barcode' => '0',
            '_packs' => '1', 'pack_base_sell' => '1', 'packs' => $packs];
    }
}
