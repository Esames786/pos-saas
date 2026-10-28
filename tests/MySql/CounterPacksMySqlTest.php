<?php

namespace Tests\MySql;

use App\Models\Tenant\Product;
use App\Models\Tenant\ProductPackUnit;
use App\Models\Tenant\SalesOrder;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\IsolatedPharmacyStore;

/**
 * UNITS-PACKS batch, feature 3 (23 Oct) — packs at the NORMAL counter ("Packs at the normal counter", off by default).
 *  - the normal POS page stays the normal one: no medical layout, no prescription; it only learns each item's packs;
 *  - a carton sells as 1 Carton at the server's price (its own, else 24 × the bottle's price), never the screen's, and
 *    24 bottles leave stock; packs are whole; too little stock is refused with nothing written;
 *  - a carton and loose bottles are separate lines and stay so through hold and recall; a returned carton puts 24 back;
 *  - with the switch off a posted pack is ignored (today's behaviour);
 *  - stock safety is not a medical-counter screen feature: with packs on the normal counter, an expiry-dated item never
 *    sells from an expired batch. With the switch off nothing changes for a live business.
 */
class CounterPacksMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;

    private const STORE_CODE = 'cptest';
    private const STORE_SUFFIX = '_cp';
    private const STORE_FEATURES = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true, 'counter_packs' => true];
    private const OFF = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
    }

    public function test_the_normal_pos_learns_the_packs_and_stays_the_normal_pos(): void
    {
        [$cola, $carton] = $this->cola('CP Cola Page');
        $html = $this->owner()->get($this->url('/pos?branch_id=' . $this->branchId()))->assertOk()->getContent();
        $this->assertStringContainsString('const counterPacks = true', $html);
        $this->assertStringContainsString('const medicalCounter = false', $html);
        $this->assertStringNotContainsString('type brand / salt', $html, 'not the medical search');
        $this->assertSame(1, preg_match('/const products\s*=\s*(\[.*?\]);\s*$/m', $html, $m));
        $row = collect(json_decode($m[1], true))->firstWhere('id', $cola->id);
        $this->assertArrayNotHasKey('med', $row, 'no batches or prescription on the normal counter');
        $this->assertEquals([['id' => $carton->id, 'name' => 'Carton', 'factor' => 24, 'own_price' => 2300, 'barcode' => $carton->barcode]], $row['packs'], 'JSON says 24 for 24.0');

        $this->setFeatures(self::OFF);
        $off = $this->owner()->get($this->url('/pos?branch_id=' . $this->branchId()))->assertOk()->getContent();
        $this->assertStringContainsString('const counterPacks = false', $off);
        preg_match('/const products\s*=\s*(\[.*?\]);\s*$/m', $off, $m);
        $this->assertArrayNotHasKey('packs', collect(json_decode($m[1], true))->firstWhere('id', $cola->id), 'switch off: the payload is today\'s');
    }

    public function test_a_carton_sells_whole_at_the_servers_price_and_24_bottles_leave(): void
    {
        [$cola, $carton] = $this->cola('CP Cola Sale');
        $this->receive($cola, (string) $carton->id, '2000', [(string) $carton->id => '2'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(48, $this->stock($cola), 0.001);

        // The screen says 1 rupee: the server's price stands.
        $sale = $this->sell([['product_id' => $cola->id, 'quantity' => 1, 'unit_price' => 1, 'pack_unit_id' => $carton->id]], 2300);
        $this->assertTrue($sale->isSuccessful(), Str::limit($sale->getContent(), 300));
        $line = $this->lastSale()->lines()->firstOrFail();
        $this->assertEqualsWithDelta(2300.0, (float) $line->unit_price, 0.001, 'the carton\'s own price, not what the screen sent');
        $this->assertSame('Carton', $line->unit_code);
        $this->assertSame('24.000', (string) $line->base_quantity);
        $this->assertEqualsWithDelta(24, $this->stock($cola), 0.001, '1 Carton = 24 bottles out');

        // A pack without a price of its own: 24 × the bottle's price.
        $plain = ProductPackUnit::create(['product_id' => $cola->id, 'name' => 'Tray', 'factor' => 12, 'can_sell' => true, 'can_buy' => true]);
        $this->assertTrue($this->sell([['product_id' => $cola->id, 'quantity' => 1, 'unit_price' => 5, 'pack_unit_id' => $plain->id]], 1200)->isSuccessful());
        $this->assertEqualsWithDelta(1200.0, (float) $this->lastSale()->lines()->firstOrFail()->unit_price, 0.001, '12 × 100');
        $this->assertEqualsWithDelta(12, $this->stock($cola), 0.001);

        // Packs are whole, and stock must cover them: both refused, nothing written.
        $paid = SalesOrder::where('status', 'paid')->count();
        $this->sell([['product_id' => $cola->id, 'quantity' => 1.5, 'pack_unit_id' => $carton->id]], 3450)->assertStatus(422);
        $short = $this->sell([['product_id' => $cola->id, 'quantity' => 1, 'pack_unit_id' => $carton->id]], 2300);
        $this->assertFalse($short->isSuccessful(), 'one carton needs 24; 12 are left');
        $this->assertSame($paid, SalesOrder::where('status', 'paid')->count(), 'no sale was made');
        $this->assertEqualsWithDelta(12, $this->stock($cola), 0.001);
    }

    public function test_a_held_carton_stays_a_carton_beside_loose_bottles_and_a_returned_carton_puts_24_back(): void
    {
        [$cola, $carton] = $this->cola('CP Cola Hold');
        $this->receive($cola, (string) $carton->id, '2000', [(string) $carton->id => '2'])->assertRedirect()->assertSessionHasNoErrors();

        $held = $this->hold([
            ['product_id' => $cola->id, 'quantity' => 1, 'unit_price' => 1, 'pack_unit_id' => $carton->id],
            ['product_id' => $cola->id, 'quantity' => 3, 'unit_price' => 100],
        ]);
        $this->assertTrue($held->isSuccessful(), Str::limit($held->getContent(), 300));
        $lines = DB::connection('tenant')->table('sales_order_lines')->where('product_id', $cola->id)->orderBy('id')->get();
        $this->assertCount(2, $lines, 'two lines, not one');
        $this->assertSame((int) $carton->id, (int) $lines[0]->pack_unit_id);
        $this->assertSame('24.000', (string) $lines[0]->base_quantity);
        $this->assertEqualsWithDelta(2300.0, (float) $lines[0]->unit_price, 0.001, 'a held carton is priced by the server too');
        $this->assertNull($lines[1]->pack_unit_id, 'the loose bottles stay loose');
        $list = $this->owner()->getJson($this->url('/api/pos/held-sales'))->assertOk()->json();
        $recalled = collect(data_get($list, 'sales.0.lines', data_get($list, '0.lines', [])))->where('product_id', $cola->id)->values();
        $this->assertSame((int) $carton->id, (int) ($recalled[0]['pack_unit_id'] ?? 0), 'the recalled carton is still a carton');

        $this->assertTrue($this->sell([['product_id' => $cola->id, 'quantity' => 1, 'pack_unit_id' => $carton->id]], 2300)->isSuccessful());
        $line = $this->lastSale()->lines()->firstOrFail();
        $this->assertEqualsWithDelta(24, $this->stock($cola), 0.001);
        $this->giveBack($line->sales_order_id, $line->id, 1, 2300)->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(48, $this->stock($cola), 0.001, 'the carton came back: 24 bottles');
    }

    public function test_with_the_switch_off_a_posted_pack_is_ignored(): void
    {
        [$cola, $carton] = $this->cola('CP Cola Off');
        $this->receive($cola, (string) $carton->id, '2000', [(string) $carton->id => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->setFeatures(self::OFF);
        $this->assertTrue($this->sell([['product_id' => $cola->id, 'quantity' => 1, 'unit_price' => 100, 'pack_unit_id' => $carton->id]], 100)->isSuccessful());
        $line = $this->lastSale()->lines()->firstOrFail();
        $this->assertNull($line->base_quantity, 'a plain line, as today');
        $this->assertEqualsWithDelta(100.0, (float) $line->unit_price, 0.001);
        $this->assertEqualsWithDelta(23, $this->stock($cola), 0.001, 'one bottle out');
    }

    public function test_an_expiry_dated_item_never_sells_from_an_expired_batch_where_the_counter_sells_packs(): void
    {
        $syrup = $this->product('CP Syrup', $this->piece()->id, ['default_selling_price' => 150, 'has_expiry' => true, 'requires_batch' => true]);
        app(InventoryService::class)->postIn(\App\Models\Tenant\Branch::findOrFail($this->branchId()), $syrup, null, 5, 100, 'purchase',
            batchNo: 'CP-OLD', expiryDate: now()->subDays(3)->toDateString());

        $r = $this->sell([['product_id' => $syrup->id, 'quantity' => 1, 'unit_price' => 150]], 150);
        $this->assertFalse($r->isSuccessful(), 'packs at the normal counter: an expired batch is never sold');
        $this->assertStringContainsString('not expired', $r->getContent());
        $this->assertEqualsWithDelta(5, $this->stock($syrup), 0.001);

        $this->setFeatures(self::OFF);
        $this->assertTrue($this->sell([['product_id' => $syrup->id, 'quantity' => 1, 'unit_price' => 150]], 150)->isSuccessful(),
            'switch off: a live business sells exactly as today');
        $this->assertEqualsWithDelta(4, $this->stock($syrup), 0.001);
    }

    /** @return array{0: Product, 1: ProductPackUnit} a bottle at 100 with a Carton of 24 at its own 2300 */
    private function cola(string $name): array
    {
        $cola = $this->product($name, $this->piece()->id, ['default_selling_price' => 100]);
        ProductPackUnit::create(['product_id' => $cola->id, 'name' => 'Bottle', 'factor' => 1, 'can_sell' => true, 'can_buy' => true]);
        $carton = ProductPackUnit::create(['product_id' => $cola->id, 'name' => 'Carton', 'factor' => 24, 'sale_price' => 2300, 'can_sell' => true, 'can_buy' => true,
            'barcode' => '2' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT)]);

        return [$cola, $carton];
    }
}
