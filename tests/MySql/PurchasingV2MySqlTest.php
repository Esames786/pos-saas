<?php

namespace Tests\MySql;

use App\Models\Master\Tenant;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductPackUnit;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Spatie\Permission\PermissionRegistrar;

/**
 * PURCHASING-V2-1 — the PO / GRN entry area, through the real routes on a provisioned tenant.
 *
 * The promises: what a person typed ("5 Box 3 Strip + 1 Box free @ 892 / Box, −5%") becomes today's line —
 * tablets and the net cost of one tablet — so stock, the bill, voids and returns read exactly what they read
 * today; the bonus goes into stock at zero cost and never counts as ordered; an expired batch is refused;
 * the old screen's post is untouched; and the product search adds packs only when the new screen asks.
 */
class PurchasingV2MySqlTest extends MySqlTenantTestCase
{
    use \Tests\MySql\Support\AssertsPlainEnglish;

    private const CODE = 'pv2grn';

    private static bool $built = false;

    private string $host;
    private string $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = self::CODE . '.' . config('tenancy.tenant_base_domain');
        $this->db = $this->tenantDb . '_pg2';

        if (! self::$built) {
            $this->freshDatabase();
            DB::setDefaultConnection('master');
            Artisan::call('migrate', ['--database' => 'master', '--force' => true]);
            (new \Database\Seeders\MasterSeeder)->run();
            Artisan::call('system:routes-sync');
            $this->masterTenant();
            $this->useDb();
            $tenant = Tenant::on('master')->where('tenant_code', self::CODE)->firstOrFail();
            app()->instance('tenant', $tenant);
            DB::setDefaultConnection('tenant');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            (new \ReflectionMethod(TenantProvisioner::class, 'seedTenantBaseData'))->invoke(app(TenantProvisioner::class), $tenant, 'secret123');
            self::$built = true;
        }
        $this->useDb();
        $this->setFeatures(['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true]);
    }

    public function test_a_grn_line_in_boxes_strips_and_bonus_becomes_tablets_at_the_net_cost(): void
    {
        [$p, $box, $strip, $tab] = $this->medicine('Panacet 500');
        $before = GoodsReceipt::count();

        $this->postAs('/goods-receipts', $this->grn([
            ['product_id' => $p->id, 'batch_no' => 'pn-1', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '892', 'disc' => '5', 'free' => '1',
             'packs' => [(string) $box->id => '5', (string) $strip->id => '3', (string) $tab->id => '']],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($before + 1, GoodsReceipt::count());
        $lines = GoodsReceiptLine::where('goods_receipt_id', GoodsReceipt::max('id'))->orderBy('id')->get();
        $this->assertCount(2, $lines, 'the bonus is its own line');
        [$paid, $free] = $lines->all();

        $this->assertEquals(1030, $paid->quantity_received, '5 Box + 3 Strip = 1,030 tablets');
        $this->assertEquals(4.237, (float) $paid->unit_cost, '892 less 5% over 200 tablets');
        $this->assertSame($box->id, (int) $paid->pack_unit_id);
        $this->assertSame(200, (int) $paid->pack_factor);
        $this->assertSame('PN-1', $paid->batch_no);
        $this->assertSame('2028-09-30', $paid->expiry_date->toDateString(), 'MM/YY is the last day of that month');
        $this->assertStringContainsString('5 Box 3 Strip @ 892 / Box, −5%', $paid->notes);

        $this->assertEquals(200, $free->quantity_received, '1 Box free = 200 tablets');
        $this->assertEquals(0, (float) $free->unit_cost);
        $this->assertSame('PN-1', $free->batch_no, 'same batch and expiry');
        $this->assertStringContainsString('Bonus (free): 1 Box', $free->notes);

        $c = DB::connection('tenant');
        $this->assertEquals(1230, (float) $c->table('stock_balances')->where('product_id', $p->id)->sum('quantity_on_hand'), 'stock gets the bonus too');
        $this->assertEquals(round(1030 * 4.237, 2), round((float) $c->table('stock_ledgers')->where('product_id', $p->id)->sum('total_cost'), 2), 'stock value = what was paid');
        $this->assertSame(1, $c->table('inventory_batches')->where('product_id', $p->id)->where('batch_no', 'PN-1')->count());

        // The receipt's own page says it in boxes, as it was typed.
        $show = $this->page('/goods-receipts/' . GoodsReceipt::max('id'))->assertOk()->getContent();
        $this->assertStringContainsString('5 Box 3 Strip @ 892 / Box, −5%', $show);
        $this->assertStringContainsString('Bonus (free): 1 Box', $show);

        // The supplier's bill is the paid boxes only: the free box costs nothing.
        $sup = $this->supplier()->fresh();
        $owedBefore = (float) $sup->current_balance;
        $this->postAs('/purchase-bills', ['goods_receipt_id' => GoodsReceipt::max('id'), 'bill_date' => now()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $bill = $c->table('purchase_bills')->where('goods_receipt_id', GoodsReceipt::max('id'))->first();
        $this->assertEquals(4364.11, round((float) $bill->grand_total, 2), '1,030 tablets × 4.237');
        $this->assertEquals(round($owedBefore + 4364.11, 2), round((float) $sup->fresh()->current_balance, 2), 'the supplier is owed the paid boxes only');
    }

    public function test_expired_and_unbatched_medicine_is_refused_and_nothing_posts(): void
    {
        [$p, $box] = $this->medicine('Expired Test');
        $before = GoodsReceipt::count();

        $this->postAs('/goods-receipts', $this->grn([['product_id' => $p->id, 'batch_no' => 'OLD-1', 'expiry' => '01/20', 'buy_pack' => (string) $box->id, 'price' => '100', 'packs' => [(string) $box->id => '1']]]))
            ->assertSessionHasErrors('lines.0.expiry_date');
        $this->postAs('/goods-receipts', $this->grn([['product_id' => $p->id, 'batch_no' => '', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '100', 'packs' => [(string) $box->id => '1']]]))
            ->assertSessionHasErrors('lines.0.batch_no');
        $this->postAs('/goods-receipts', $this->grn([['product_id' => $p->id, 'batch_no' => 'B', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '100', 'packs' => [(string) $box->id => '1.5']]]))
            ->assertSessionHasErrors('lines.0.packs');
        $this->assertSame($before, GoodsReceipt::count());

        // People type the expiry in many ways (owner, 19 Oct: "0928"); all of these mean Sep 2028.
        foreach (['0928', '09/28', '9/28', '09-28', '09/2028', '2028-09-30'] as $typed) {
            $this->assertSame('2028-09-30', \App\Support\PackLines::expiry($typed), $typed);
        }
        $this->assertFalse(\App\Support\PackLines::expiry('1328'), 'month 13 is refused');
    }

    public function test_the_old_grn_post_is_untouched(): void
    {
        [$p] = $this->medicine('Old Path');
        $branch = DB::connection('tenant')->table('branches')->value('id');
        $this->postAs('/goods-receipts', [
            'branch_id' => $branch, 'supplier_id' => $this->supplier()->id, 'receipt_date' => now()->toDateString(),
            'lines' => [['product_id' => $p->id, 'product_variant_id' => $p->defaultVariant->id, 'batch_no' => 'OLD-PATH', 'expiry_date' => '2028-01-31', 'quantity_received' => '10', 'unit_cost' => '5']],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $line = GoodsReceiptLine::where('batch_no', 'OLD-PATH')->firstOrFail();
        $this->assertEquals(10, $line->quantity_received);
        $this->assertEquals(5, (float) $line->unit_cost);
        $this->assertNull($line->pack_unit_id);
        $this->assertNull($line->base_quantity);
    }

    public function test_a_po_in_boxes_and_a_bonus_never_counts_as_ordered(): void
    {
        [$p, $box] = $this->medicine('Ordered Med');
        $branch = DB::connection('tenant')->table('branches')->value('id');
        $sup = $this->supplier();
        $this->postAs('/purchase-orders', ['_entry_v2' => '1', 'branch_id' => $branch, 'supplier_id' => $sup->id, 'order_date' => now()->toDateString(),
            'lines' => [['product_id' => $p->id, 'buy_pack' => (string) $box->id, 'price' => '892', 'packs' => [(string) $box->id => '2']]]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $po = PurchaseOrder::latest('id')->firstOrFail();
        $pl = PurchaseOrderLine::where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertEquals(400, $pl->quantity_ordered);
        $this->assertEquals(4.46, (float) $pl->unit_cost);
        $this->assertSame($box->id, (int) $pl->pack_unit_id);
        $this->assertEquals(1784, (float) $po->total_amount);
        $this->assertStringContainsString('2 Box @ 892 / Box', $this->page('/purchase-orders/' . $po->id)->assertOk()->getContent(), 'the order page says it in boxes');

        $po->update(['status' => 'approved']);
        // 1 Box + 1 Box free against an order of 2 Box: still partly due — the free box was never ordered.
        $this->postAs('/goods-receipts', $this->grn([['product_id' => $p->id, 'purchase_order_line_id' => (string) $pl->id, 'batch_no' => 'PO-1', 'expiry' => '09/28',
            'buy_pack' => (string) $box->id, 'price' => '892', 'free' => '1', 'packs' => [(string) $box->id => '1']]], ['purchase_order_id' => $po->id]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('partially_received', $po->fresh()->status);
    }

    public function test_the_product_search_adds_packs_only_when_the_new_screen_asks(): void
    {
        [$p, $box] = $this->medicine('Lookup Med');
        $sup = $this->supplier();
        $this->postAs('/goods-receipts', $this->grn([['product_id' => $p->id, 'batch_no' => 'LK-1', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '900', 'packs' => [(string) $box->id => '1']]]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $branch = DB::connection('tenant')->table('branches')->value('id');
        $new = $this->page('/ajax/products?context=purchase&rich=1&packs=1&branch_id=' . $branch . '&supplier_id=' . $sup->id . '&q=' . $box->barcode)->assertOk()->json('results');
        $this->assertSame($p->id, $new[0]['id'], 'a box barcode finds its item');
        $this->assertSame((string) $box->id, $new[0]['scanned_pack']);
        $this->assertSame(['Box', 'Strip', 'Tablet'], array_column($new[0]['packs'], 'name'), 'biggest first');
        $this->assertEquals(4.5, $new[0]['last_cost']['unit_cost'], 'the last price paid to this supplier, per tablet');

        $old = $this->page('/ajax/products?context=purchase&rich=1&branch_id=' . $branch . '&q=Lookup')->assertOk()->json('results');
        $this->assertArrayNotHasKey('packs', $old[0], 'older screens get the same answer as before');
    }

    public function test_the_screens_show_the_entry_area_only_where_switched_on(): void
    {
        $this->setFeatures(['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => false]);
        $this->assertStringNotContainsString('name="_entry_v2"', $this->page('/goods-receipts/create')->assertOk()->getContent());
        $this->assertStringNotContainsString('name="_entry_v2"', $this->page('/purchase-orders/create')->assertOk()->getContent());

        $this->setFeatures(['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true]);
        $grn = $this->page('/goods-receipts/create')->assertOk()->getContent();
        $this->assertStringContainsString('name="_entry_v2"', $grn);
        $this->assertStringContainsString('id="ea-search"', $grn);
        $this->assertStringContainsString('name="_entry_v2"', $this->page('/purchase-orders/create')->assertOk()->getContent());
    }

    // ── PURCHASING-V2-2 ──────────────────────────────────────────────────────────────────────────────

    public function test_a_new_supplier_from_the_grn_screen_needs_only_the_grn_screen(): void
    {
        // Owner's rule: whoever can open the screen does all of its work — a GRN-only clerk adds a supplier.
        $clerk = $this->clerk('grnonly', ['tenant.goods-receipts.create', 'tenant.goods-receipts.index']);
        $this->assertFalse($clerk->can('tenant.suppliers.create'), 'the clerk cannot open the Suppliers page');

        $r = $this->callAs($clerk, 'POST', '/suppliers/quick', ['name' => '  Al  Shifa Distributors ', 'phone' => '0300 1234567'], true)->assertStatus(201);
        $sup = Supplier::findOrFail($r->json('id'));
        $this->assertSame('Al Shifa Distributors', $sup->name, 'spaces tidied');
        $this->assertMatchesRegularExpression('/^SUP-\d{4}$/', $sup->code, 'the code is made by itself');
        $this->assertSame('active', $sup->status);
        $this->assertEquals(0, (float) $sup->current_balance);

        $this->callAs($clerk, 'POST', '/suppliers/quick', ['name' => 'al shifa distributors'], true)->assertStatus(422)->assertJsonValidationErrors('name');

        $nobody = $this->clerk('salesonly', ['tenant.pos.index']);
        $this->callAs($nobody, 'POST', '/suppliers/quick', ['name' => 'Someone Else'], true)->assertStatus(403);

        $this->setFeatures(['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => false]);
        $this->callAs($clerk, 'POST', '/suppliers/quick', ['name' => 'Switched Off Ltd'], true)->assertStatus(404);
        $this->assertSame(0, Supplier::where('name', 'Switched Off Ltd')->count(), 'switch off = nothing there');
    }

    public function test_the_batch_box_lists_batches_nearest_expiry_first_with_their_stock(): void
    {
        [$p, $box] = $this->medicine('Batchbox Med');
        $branch = (int) DB::connection('tenant')->table('branches')->value('id');
        $this->postAs('/goods-receipts', $this->grn([
            ['product_id' => $p->id, 'batch_no' => 'BB-LATE', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '800', 'packs' => [(string) $box->id => '5']],
            ['product_id' => $p->id, 'batch_no' => 'BB-SOON', 'expiry' => '03/27', 'buy_pack' => (string) $box->id, 'price' => '800', 'packs' => [(string) $box->id => '1']],
        ]))->assertSessionHasNoErrors();
        \App\Models\Tenant\InventoryBatch::create(['batch_key' => 'test-expired-' . $p->id, 'branch_id' => $branch, 'product_id' => $p->id, 'batch_no' => 'BB-OLD',
            'expiry_date' => '2025-01-31', 'unit_cost' => 0, 'status' => 'active']);

        $rows = $this->page('/ajax/batches?product_id=' . $p->id . '&branch_id=' . $branch)->assertOk()->json('batches');
        $this->assertSame(['BB-SOON', 'BB-LATE'], array_column($rows, 'batch_no'), 'nearest expiry first; the expired batch is not offered');
        $this->assertSame(['03/27', '09/28'], array_column($rows, 'expiry'));
        $this->assertEquals([200, 1000], array_column($rows, 'stock'), 'stock is kept per batch');

        $all = $this->page('/ajax/batches?expired=1&product_id=' . $p->id . '&branch_id=' . $branch)->json('batches');
        $this->assertContains('BB-OLD', array_column($all, 'batch_no'), 'a return can ask for expired batches');
    }

    public function test_a_known_batch_keeps_its_one_expiry(): void
    {
        [$p, $box] = $this->medicine('Onexpiry Med');
        $line = fn (string $exp) => ['product_id' => $p->id, 'batch_no' => 'ONE-1', 'expiry' => $exp, 'buy_pack' => (string) $box->id, 'price' => '500', 'packs' => [(string) $box->id => '1']];
        $this->postAs('/goods-receipts', $this->grn([$line('09/28')]))->assertSessionHasNoErrors();

        $r = $this->callAs(null, 'POST', '/goods-receipts', $this->grn([$line('10/28')]), true)->assertStatus(422);
        $this->assertStringContainsString('already has expiry 09/28', $r->json('errors')['lines.0.expiry_date'][0]);

        $this->postAs('/goods-receipts', $this->grn([$line('09/28')]))->assertSessionHasNoErrors();
        $this->assertSame(1, \App\Models\Tenant\InventoryBatch::where('product_id', $p->id)->where('batch_no', 'ONE-1')->count(), 'the same batch received twice');
    }

    public function test_batches_made_beforehand_show_in_the_batch_box_and_take_the_goods(): void
    {
        [$p, $box] = $this->medicine('Premade Med');
        $branch = (int) DB::connection('tenant')->table('branches')->value('id');

        $made = $this->callAs(null, 'POST', '/inventory/batches', ['product_id' => $p->id, 'branch_id' => $branch, 'batch_no' => 'pre-1', 'expiry' => '1128'], true)->assertStatus(201);
        $this->assertSame('PRE-1', $made->json('batch_no'));
        $this->callAs(null, 'POST', '/inventory/batches', ['product_id' => $p->id, 'branch_id' => $branch, 'batch_no' => 'PRE-1', 'expiry' => '11/28'], true)
            ->assertStatus(422)->assertJsonValidationErrors('batch_no');
        $this->callAs(null, 'POST', '/inventory/batches', ['product_id' => $p->id, 'branch_id' => $branch, 'batch_no' => 'PRE-2'], true)
            ->assertStatus(422)->assertJsonValidationErrors('expiry');

        $rows = $this->page('/ajax/batches?product_id=' . $p->id . '&branch_id=' . $branch)->json('batches');
        $this->assertSame([['PRE-1', '11/28', 0]], array_map(fn ($b) => [$b['batch_no'], $b['expiry'], (int) $b['stock']], $rows));

        // A typing mistake: the expiry is corrected; the GRN then receives into that batch.
        $this->callAs(null, 'POST', '/inventory/batches/' . $made->json('id') . '/expiry', ['expiry' => '12/28'], true)->assertOk()->assertJson(['expiry' => '12/28']);
        $this->postAs('/goods-receipts', $this->grn([['product_id' => $p->id, 'batch_no' => 'PRE-1', 'expiry' => '12/28', 'buy_pack' => (string) $box->id, 'price' => '500', 'packs' => [(string) $box->id => '2']]]))->assertSessionHasNoErrors();
        $this->assertEquals(400, (float) DB::connection('tenant')->table('stock_balances')->where('inventory_batch_id', $made->json('id'))->sum('quantity_on_hand'), 'stock went into the batch made beforehand');

        $page = $this->page('/inventory/batches?q=Premade')->assertOk()->getContent();
        $this->assertStringContainsString('id="bt-new-btn"', $page);
        $this->assertStringContainsString('2 Box', $page, 'stock left, in packs');
        $this->assertStringContainsString('data-exp-edit="' . $made->json('id') . '"', $page);
        $this->assertStringNotContainsString('bg-warning text-dark ms-1">Expiring', $page, 'a batch two years away is not "expiring" (the old signed-days bug)');

        $clerk = $this->clerk('grnonly2', ['tenant.goods-receipts.create']);
        $this->callAs($clerk, 'POST', '/inventory/batches', ['product_id' => $p->id, 'branch_id' => $branch, 'batch_no' => 'PRE-3', 'expiry' => '11/28'], true)->assertStatus(403);

        $this->setFeatures(['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => false]);
        $off = $this->page('/inventory/batches')->assertOk()->getContent();
        $this->assertStringNotContainsString('bt-new-btn', $off, 'switch off = the page as before');
        $this->assertStringNotContainsString('Stock left', $off);
    }

    public function test_post_without_reload_answers_with_the_number_or_the_line_to_fix(): void
    {
        [$p, $box] = $this->medicine('Nojump Med');
        $ok = $this->callAs(null, 'POST', '/goods-receipts', $this->grn([['product_id' => $p->id, 'batch_no' => 'NJ-1', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '500', 'packs' => [(string) $box->id => '1']]]), true)->assertOk();
        $this->assertTrue($ok->json('ok'));
        $this->assertStringStartsWith('GRN-', $ok->json('number'));
        $this->assertStringEndsWith('/goods-receipts/' . $ok->json('id'), $ok->json('url'));

        $bad = $this->callAs(null, 'POST', '/goods-receipts', $this->grn([
            ['product_id' => $p->id, 'batch_no' => 'NJ-2', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '500', 'packs' => [(string) $box->id => '1']],
            ['product_id' => $p->id, 'batch_no' => '', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '500', 'packs' => [(string) $box->id => '1']],
        ]), true)->assertStatus(422);
        $this->assertArrayHasKey('lines.1.batch_no', $bad->json('errors'), 'the error names the line to fix');

        $po = $this->callAs(null, 'POST', '/purchase-orders', ['_entry_v2' => '1', 'branch_id' => DB::connection('tenant')->table('branches')->value('id'), 'supplier_id' => $this->supplier()->id,
            'order_date' => now()->toDateString(), 'lines' => [['product_id' => $p->id, 'buy_pack' => (string) $box->id, 'price' => '500', 'packs' => [(string) $box->id => '3']]]], true)->assertOk();
        $this->assertStringStartsWith('PO-', $po->json('number'));
    }

    public function test_tabs_and_the_supplier_box_only_with_the_switch_on(): void
    {
        $grn = $this->page('/goods-receipts/create')->assertOk()->getContent();
        $this->assertStringContainsString('class="pur-tabs"', $grn);
        $this->assertStringContainsString('Goods Receipts', $grn);
        $this->assertStringContainsString('id="sup-q"', $grn, 'type-to-search supplier box');
        $this->assertStringContainsString('id="sup-new"', $grn, '+ New supplier');
        $this->assertStringContainsString('class="pur-tabs"', $this->page('/purchase-returns')->assertOk()->getContent(), 'every purchasing page');

        $clerk = $this->clerk('grnonly3', ['tenant.goods-receipts.create', 'tenant.goods-receipts.index']);
        $mine = $this->callAs($clerk, 'GET', '/goods-receipts/create')->assertOk()->getContent();
        $this->assertStringContainsString('Goods Receipts', $mine);
        $this->assertStringNotContainsString('>Supplier Invoices <', $mine, 'a role sees only the tabs it can open');

        $this->setFeatures(['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => false]);
        $off = $this->page('/goods-receipts/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('pur-tabs', $off);
        $this->assertStringNotContainsString('id="sup-q"', $off);
        $this->assertStringContainsString('class="form-select', $off, 'the old supplier list is back');
    }

    public function test_every_purchasing_screen_reads_in_plain_english_with_the_same_terms_and_a_guide(): void
    {
        // Review, 21 Oct: clear English and one set of terms on every purchasing screen — the new ones and the old ones.
        $creates = ['/purchase-orders/create', '/goods-receipts/create', '/purchase-bills/create', '/supplier-payments/create', '/purchase-returns/create'];
        $lists = ['/purchase-orders', '/goods-receipts', '/purchase-bills', '/supplier-payments', '/purchase-returns', '/suppliers', '/suppliers/create'];
        foreach ([true, false] as $v2) {
            $this->setFeatures(['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => $v2]);
            foreach (array_merge($creates, $lists) as $uri) {
                $html = $this->page($uri)->assertOk()->getContent();
                $this->assertNoRomanUrdu($html, $uri . ($v2 ? ' (new purchase screens)' : ' (old screens)'));
                $this->assertStringNotContainsString('<span>Purchase Bills</span>', $html, $uri . ': the sidebar says Supplier Invoices');
                $this->assertStringNotContainsString('<span>GRN</span>', $html, $uri . ': the sidebar says Goods Receipts');
                if (in_array($uri, $creates, true)) {
                    $this->assertMatchesRegularExpression('/<details class="htu" id="how-to-use">\s*<summary>How to use this screen<\/summary>/', $html, $uri . ': the guide, closed');
                    $this->assertStringContainsString('<b>Purchase Order</b> (optional)', $html, $uri . ': the steps named the same way');
                }
            }
        }
    }

    public function test_a_kitchen_grn_in_kg_sacks_and_items_without_a_batch_posts_like_the_old_screen(): void
    {
        // Owner, 19 Oct: "ye new GRN/PO restaurant, kitchen waghera ke liye bhi theek chale".
        $kg = Unit::updateOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $ltr = Unit::updateOrCreate(['code' => 'LTR'], ['name' => 'Litre', 'unit_type' => 'volume', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $sack = Unit::updateOrCreate(['code' => 'SACK'], ['name' => 'Sack', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => false, 'is_active' => true]);
        $make = function (string $name, int $unitId, array $extra = []) {
            $sku = 'KT-' . strtoupper(substr(md5($name), 0, 6));
            $p = Product::create($extra + ['sku' => $sku, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'unit_id' => $unitId, 'product_type' => 'simple',
                'product_kind' => 'sale_item', 'inventory_consumption_method' => 'stock_item', 'is_sellable' => false, 'is_pos_visible' => false, 'is_purchasable' => true,
                'is_stock_tracked' => true, 'has_expiry' => false, 'requires_batch' => false, 'default_selling_price' => 0, 'default_purchase_price' => 100, 'status' => 'active']);
            ProductVariant::create(['product_id' => $p->id, 'sku' => $sku, 'name' => $name, 'is_default' => true, 'is_active' => true]);

            return $p->fresh();
        };
        $chicken = $make('Kitchen Chicken', $kg->id);
        $rice = $make('Kitchen Rice', $kg->id, ['purchase_unit_id' => $sack->id, 'purchase_pack_size' => 50]);
        $milk = $make('Kitchen Milk', $ltr->id, ['has_expiry' => true]);
        $gas = $make('Kitchen Gas', $kg->id, ['is_stock_tracked' => false]);

        // The lookup offers the sack beside the kilogram, and decimals for kg.
        $found = $this->page('/ajax/products?context=purchase&rich=1&packs=1&branch_id=' . DB::connection('tenant')->table('branches')->value('id') . '&q=Kitchen%20Rice')->assertOk()->json('results');
        $packs = collect($found)->firstWhere('id', $rice->id)['packs'];
        $this->assertSame(['buy', 'base'], array_column($packs, 'key'));
        $this->assertSame('50 Kilogram', $packs[0]['holds']);
        $this->assertTrue(collect($found)->firstWhere('id', $rice->id)['allow_decimal']);

        $this->postAs('/goods-receipts', $this->grn([
            ['product_id' => $chicken->id, 'buy_pack' => 'base', 'price' => '650', 'packs' => ['base' => '12.5']],
            ['product_id' => $rice->id, 'buy_pack' => 'buy', 'price' => '9000', 'note' => 'one sack torn', 'packs' => ['buy' => '2', 'base' => '5']],
            ['product_id' => $milk->id, 'buy_pack' => 'base', 'price' => '220', 'expiry' => now('Asia/Karachi')->addMonths(2)->format('m/y'), 'packs' => ['base' => '20']],
            ['product_id' => $gas->id, 'buy_pack' => 'base', 'price' => '3500', 'packs' => ['base' => '1']],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $c = DB::connection('tenant');
        $grn = (int) GoodsReceipt::max('id');
        $line = fn ($p) => $c->table('goods_receipt_lines')->where('goods_receipt_id', $grn)->where('product_id', $p->id)->first();
        $stock = fn ($p) => (float) $c->table('stock_balances')->where('product_id', $p->id)->sum('quantity_on_hand');

        $this->assertEquals(12.5, (float) $line($chicken)->quantity_received, 'kg with a decimal');
        $this->assertEquals(650, (float) $line($chicken)->unit_cost);
        $this->assertNull($line($chicken)->batch_no, 'no batch asked for an item that keeps none');
        $this->assertEquals(12.5, $stock($chicken));

        $this->assertEquals(105, (float) $line($rice)->quantity_received, '2 sacks of 50 kg + 5 kg');
        $this->assertEquals(180, (float) $line($rice)->unit_cost, '9,000 a sack over 50 kg');
        $this->assertSame(50, (int) $line($rice)->pack_factor);
        $this->assertStringContainsString('2 Sack 5 Kilogram @ 9000 / Sack · one sack torn', $line($rice)->notes, 'the line\'s own note is kept');
        $this->assertEquals(105, $stock($rice));

        $this->assertNotNull($line($milk)->expiry_date, 'expiry kept');
        $this->assertEquals(20, $stock($milk), 'an expiry item needs no batch number (the old screen\'s rule)');

        $this->assertSame(0, (int) $line($gas)->affects_stock, 'purchase-only: bought, not stocked');
        $this->assertEquals(0, $stock($gas));
    }

    public function test_a_rupee_discount_a_scheme_and_a_bill_discount_all_land_in_the_cost(): void
    {
        // Owner, 19 Oct: "line discount, overall bill discount fixed or %", the distributor's "10+1".
        [$p, $box] = $this->medicine('Discount Med');
        $c = DB::connection('tenant');
        $this->postAs('/goods-receipts', $this->grn([
            ['product_id' => $p->id, 'batch_no' => 'DM-1', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '900', 'disc' => '500', 'disc_type' => 'amount',
             'scheme' => '10+1', 'packs' => [(string) $box->id => '10']],
        ], ['bill_disc' => '2', 'bill_disc_type' => 'percent']))->assertRedirect()->assertSessionHasNoErrors();

        $lines = GoodsReceiptLine::where('goods_receipt_id', GoodsReceipt::max('id'))->orderBy('id')->get();
        [$paid, $free] = $lines->all();
        $this->assertEquals(2000, $paid->quantity_received);
        // 10 Box × 900 = 9,000 − Rs 500 = 8,500; − 2% bill discount = 8,330 over 2,000 tablets.
        $this->assertEquals(4.165, (float) $paid->unit_cost);
        $this->assertEquals(200, $free->quantity_received, '10+1 on 10 Box = 1 Box free, worked out by the server');
        $this->assertEquals(0, (float) $free->unit_cost);
        $this->assertStringContainsString('10 Box @ 900 / Box, −Rs 500, scheme 10+1 · bill disc 2%', $paid->notes);
        $this->assertSame(['amount', '10+1', 2.0], [$paid->entry_json['disc_type'], $paid->entry_json['scheme'], (float) $paid->entry_json['bill_disc']]);

        $this->postAs('/purchase-bills', ['goods_receipt_id' => GoodsReceipt::max('id'), 'bill_date' => now()->toDateString()])->assertRedirect();
        $this->assertEquals(8330, round((float) $c->table('purchase_bills')->where('goods_receipt_id', GoodsReceipt::max('id'))->value('grand_total'), 2), 'the bill is the paid goods after both discounts');

        // Wrong shapes are refused, nothing saved.
        $before = GoodsReceipt::count();
        $one = ['product_id' => $p->id, 'batch_no' => 'DM-2', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '900', 'packs' => [(string) $box->id => '1']];
        $this->callAs(null, 'POST', '/goods-receipts', $this->grn([$one + ['scheme' => '10x1']]), true)->assertStatus(422)->assertJsonValidationErrors('lines.0.scheme');
        $this->callAs(null, 'POST', '/goods-receipts', $this->grn([$one + ['disc' => '1000', 'disc_type' => 'amount']]), true)->assertStatus(422)->assertJsonValidationErrors('lines.0.disc');
        $this->callAs(null, 'POST', '/goods-receipts', $this->grn([$one], ['bill_disc' => '5000', 'bill_disc_type' => 'amount']), true)->assertStatus(422)->assertJsonValidationErrors('bill_disc');
        $this->assertSame($before, GoodsReceipt::count());
    }

    public function test_the_last_deal_and_the_usual_discounts_come_back_on_the_next_purchase(): void
    {
        [$p, $box] = $this->medicine('Deal Med');
        $branch = (int) DB::connection('tenant')->table('branches')->value('id');
        $this->postAs('/goods-receipts', $this->grn([
            ['product_id' => $p->id, 'batch_no' => 'DL-1', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '880', 'disc' => '4', 'scheme' => '12+1', 'packs' => [(string) $box->id => '12']],
        ]))->assertSessionHasNoErrors();

        $p->update(['purchase_discount_type' => 'percent', 'purchase_discount_value' => 3, 'purchase_scheme_buy' => 10, 'purchase_scheme_free' => 1]);
        $row = collect($this->page('/ajax/products?context=purchase&rich=1&packs=1&branch_id=' . $branch . '&supplier_id=' . $this->supplier()->id . '&q=Deal%20Med')->assertOk()->json('results'))->firstWhere('id', $p->id);
        $this->assertSame(['price' => 880.0, 'disc' => 4.0, 'disc_type' => 'percent', 'scheme' => '12+1'],
            ['price' => (float) $row['last_cost']['deal']['price'], 'disc' => (float) $row['last_cost']['deal']['disc'], 'disc_type' => $row['last_cost']['deal']['disc_type'], 'scheme' => $row['last_cost']['deal']['scheme']],
            'the last deal with this supplier, as typed');
        $this->assertSame(['type' => 'percent', 'value' => 3.0], ['type' => $row['buy_disc']['type'], 'value' => (float) $row['buy_disc']['value']], "the item's usual discount");
        $this->assertSame('10+1', $row['buy_scheme']);

        // A PO typed with a scheme keeps it for "Receive".
        $this->postAs('/purchase-orders', ['_entry_v2' => '1', 'branch_id' => $branch, 'supplier_id' => $this->supplier()->id, 'order_date' => now()->toDateString(),
            'bill_disc' => '100', 'bill_disc_type' => 'amount',
            'lines' => [['product_id' => $p->id, 'buy_pack' => (string) $box->id, 'price' => '880', 'disc' => '4', 'scheme' => '12+1', 'packs' => [(string) $box->id => '12']]]])->assertSessionHasNoErrors();
        $pl = PurchaseOrderLine::where('purchase_order_id', PurchaseOrder::max('id'))->firstOrFail();
        $this->assertSame('12+1', $pl->entry_json['scheme']);
        // 12 × 880 = 10,560 − 4% = 10,137.60 − Rs 100 = 10,037.60, kept as 2,400 tablets × Rs 4.1823 (cost of one
        // tablet to 4 decimals) = 10,037.52 — the same figure the screen shows, so the bill never surprises.
        $this->assertEquals(10037.52, round((float) PurchaseOrder::latest('id')->value('total_amount'), 2));
        $this->assertStringContainsString('"scheme":"12+1"', $this->page('/goods-receipts/create?purchase_order_id=' . PurchaseOrder::max('id'))->assertOk()->getContent(), 'the GRN from this order gets the scheme');
    }

    // ── PURCHASE-RETURN-V2 ───────────────────────────────────────────────────────────────────────────

    public function test_a_return_in_packs_leaves_from_the_batch_picked_not_the_oldest(): void
    {
        [$p, $box, $strip] = $this->medicine('Retbatch Med');
        $c = DB::connection('tenant');
        $branch = (int) $c->table('branches')->value('id');
        $this->postAs('/goods-receipts', $this->grn([
            ['product_id' => $p->id, 'batch_no' => 'RB-OLD', 'expiry' => '03/27', 'buy_pack' => (string) $box->id, 'price' => '800', 'packs' => [(string) $box->id => '1']],
            ['product_id' => $p->id, 'batch_no' => 'RB-NEW', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '900', 'packs' => [(string) $box->id => '2']],
        ]))->assertSessionHasNoErrors();
        $old = (int) $c->table('inventory_batches')->where('product_id', $p->id)->where('batch_no', 'RB-OLD')->value('id');
        $new = (int) $c->table('inventory_batches')->where('product_id', $p->id)->where('batch_no', 'RB-NEW')->value('id');
        $owed = (float) $this->supplier()->fresh()->current_balance;

        $r = $this->callAs(null, 'POST', '/purchase-returns', $this->ret([
            ['product_id' => $p->id, 'inventory_batch_id' => $new, 'buy_pack' => (string) $box->id, 'price' => '900', 'reason_code' => 'damaged',
             'packs' => [(string) $box->id => '1', (string) $strip->id => '5']],
        ], ['_post' => '1']), true)->assertOk();
        $this->assertTrue($r->json('posted'));
        $this->assertStringStartsWith('PRET-', $r->json('number'));

        $inBatch = fn (int $id) => (float) $c->table('stock_balances')->where('inventory_batch_id', $id)->sum('quantity_on_hand');
        $this->assertEquals(150, $inBatch($new), '1 Box 5 Strip = 250 tablets left batch RB-NEW');
        $this->assertEquals(200, $inBatch($old), 'the oldest batch is untouched (oldest-first would have taken it)');

        $line = $c->table('purchase_return_lines')->where('purchase_return_id', $r->json('id'))->first();
        $this->assertSame($new, (int) $line->inventory_batch_id);
        $this->assertEquals(250, (float) $line->quantity);
        $this->assertEquals(4.5, (float) $line->unit_cost, '900 / Box over 200 tablets');
        $this->assertSame(200, (int) $line->pack_factor);
        $this->assertStringContainsString('1 Box 5 Strip @ 900 / Box · B:RB-NEW EXP:09/28', $line->notes);
        $this->assertEquals(round($owed - 1125, 2), round((float) $this->supplier()->fresh()->current_balance, 2), 'the supplier is owed 250 × 4.5 less');

        $this->assertStringContainsString('1 Box 5 Strip @ 900 / Box', $this->page('/purchase-returns/' . $r->json('id'))->assertOk()->getContent(), 'the return page says it in packs');
    }

    public function test_a_return_never_takes_more_than_its_batch_holds_and_always_says_why(): void
    {
        [$p, $box] = $this->medicine('Retguard Med');
        $c = DB::connection('tenant');
        $this->postAs('/goods-receipts', $this->grn([['product_id' => $p->id, 'batch_no' => 'RG-1', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '900', 'packs' => [(string) $box->id => '2']]]))->assertSessionHasNoErrors();
        $batch = (int) $c->table('inventory_batches')->where('product_id', $p->id)->where('batch_no', 'RG-1')->value('id');
        $before = $c->table('purchase_returns')->count();
        $line = fn (array $over) => array_merge(['product_id' => $p->id, 'inventory_batch_id' => $batch, 'buy_pack' => (string) $box->id, 'price' => '900', 'reason_code' => 'expired', 'packs' => [(string) $box->id => '1']], $over);

        $r = $this->callAs(null, 'POST', '/purchase-returns', $this->ret([$line(['packs' => [(string) $box->id => '3']])], ['_post' => '1']), true)->assertStatus(422);
        $this->assertStringContainsString('has only 2 Box left', $r->json('errors')['lines.0.packs'][0]);
        $this->callAs(null, 'POST', '/purchase-returns', $this->ret([$line(['reason_code' => ''])]), true)->assertStatus(422)->assertJsonValidationErrors('lines.0.reason_code');
        $this->callAs(null, 'POST', '/purchase-returns', $this->ret([$line(['inventory_batch_id' => ''])]), true)->assertStatus(422)->assertJsonValidationErrors('lines.0.batch_no');
        // Two lines of the same batch count together.
        $this->callAs(null, 'POST', '/purchase-returns', $this->ret([$line([]), $line(['packs' => [(string) $box->id => '2']])]), true)->assertStatus(422)->assertJsonValidationErrors('lines.1.packs');
        $this->assertSame($before, $c->table('purchase_returns')->count(), 'nothing saved, no draft left behind');
    }

    public function test_a_return_from_a_grn_keeps_its_rate_and_takes_extra_items_too(): void
    {
        [$p, $box] = $this->medicine('Retgrn Med');
        [$q, $qbox] = $this->medicine('Retextra Med');
        $c = DB::connection('tenant');
        $this->postAs('/goods-receipts', $this->grn([['product_id' => $p->id, 'batch_no' => 'RX-1', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '1000', 'packs' => [(string) $box->id => '2']]]))->assertSessionHasNoErrors();
        $grnId = (int) GoodsReceipt::max('id');
        $this->postAs('/goods-receipts', $this->grn([['product_id' => $q->id, 'batch_no' => 'RX-2', 'expiry' => '09/28', 'buy_pack' => (string) $qbox->id, 'price' => '600', 'packs' => [(string) $qbox->id => '1']]]))->assertSessionHasNoErrors();
        $grnLine = (int) $c->table('goods_receipt_lines')->where('goods_receipt_id', $grnId)->value('id');
        $b1 = (int) $c->table('inventory_batches')->where('product_id', $p->id)->where('batch_no', 'RX-1')->value('id');
        $b2 = (int) $c->table('inventory_batches')->where('product_id', $q->id)->where('batch_no', 'RX-2')->value('id');

        $page = $this->page('/purchase-returns/create?goods_receipt_id=' . $grnId)->assertOk()->getContent();
        $this->assertStringContainsString('From the receipt', $page);
        $this->assertStringContainsString('"batch_no":"RX-1"', $page, 'the receipt line carries its batch');

        $r = $this->callAs(null, 'POST', '/purchase-returns', $this->ret([
            ['product_id' => $p->id, 'source_line_id' => $grnLine, 'inventory_batch_id' => $b1, 'buy_pack' => (string) $box->id, 'price' => '1000', 'reason_code' => 'expired', 'packs' => [(string) $box->id => '1']],
            ['product_id' => $q->id, 'inventory_batch_id' => $b2, 'buy_pack' => (string) $qbox->id, 'price' => '600', 'reason_code' => 'wrong_item', 'packs' => [(string) $qbox->id => '1']],
        ], ['goods_receipt_id' => $grnId, '_post' => '0']), true)->assertOk();
        $this->assertFalse($r->json('posted'), 'Save as draft');
        $lines = $c->table('purchase_return_lines')->where('purchase_return_id', $r->json('id'))->orderBy('id')->get();
        $this->assertSame([$grnLine, null], [$lines[0]->source_line_id ? (int) $lines[0]->source_line_id : null, $lines[1]->source_line_id]);
        $this->assertSame(['expired', 'wrong_item'], [$lines[0]->reason_code, $lines[1]->reason_code]);
        $this->assertEquals(400, (float) $c->table('stock_balances')->where('inventory_batch_id', $b1)->sum('quantity_on_hand'), 'a draft moves no stock');
    }

    public function test_the_expiry_sweep_lists_only_this_suppliers_batches_that_run_out_by_then(): void
    {
        [$p, $box] = $this->medicine('Sweep Med');
        $c = DB::connection('tenant');
        $branch = (int) $c->table('branches')->value('id');
        $soon = now('Asia/Karachi')->addMonth();
        $this->postAs('/goods-receipts', $this->grn([
            ['product_id' => $p->id, 'batch_no' => 'SW-SOON', 'expiry' => $soon->format('m/y'), 'buy_pack' => (string) $box->id, 'price' => '700', 'packs' => [(string) $box->id => '1']],
            ['product_id' => $p->id, 'batch_no' => 'SW-LATE', 'expiry' => '09/29', 'buy_pack' => (string) $box->id, 'price' => '700', 'packs' => [(string) $box->id => '1']],
        ]))->assertSessionHasNoErrors();
        $other = Supplier::firstOrCreate(['code' => 'PV2-S2'], ['name' => 'Other Distributor', 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);

        $url = '/ajax/batches?branch_id=' . $branch . '&before=' . $soon->format('Y-m') . '&supplier_id=';
        $rows = $this->page($url . $this->supplier()->id)->assertOk()->json('batches');
        $this->assertSame(['SW-SOON'], array_values(array_filter(array_column($rows, 'batch_no'), fn ($b) => str_starts_with($b, 'SW-'))));
        $this->assertSame('Sweep Med', collect($rows)->firstWhere('batch_no', 'SW-SOON')['product']);
        $this->assertEquals(3.5, collect($rows)->firstWhere('batch_no', 'SW-SOON')['unit_cost'], 'what one tablet of it cost');
        $this->assertSame([], $this->page($url . $other->id)->json('batches'), "another supplier's goods never show");
    }

    public function test_the_old_return_screen_and_its_post_are_untouched_without_the_switch(): void
    {
        [$p, $box] = $this->medicine('Retold Med');
        $c = DB::connection('tenant');
        $this->postAs('/goods-receipts', $this->grn([
            ['product_id' => $p->id, 'batch_no' => 'RO-A', 'expiry' => '03/27', 'buy_pack' => (string) $box->id, 'price' => '800', 'packs' => [(string) $box->id => '1']],
            ['product_id' => $p->id, 'batch_no' => 'RO-B', 'expiry' => '09/28', 'buy_pack' => (string) $box->id, 'price' => '800', 'packs' => [(string) $box->id => '1']],
        ]))->assertSessionHasNoErrors();
        $this->setFeatures(['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => false]);
        $page = $this->page('/purchase-returns/create')->assertOk()->getContent();
        $this->assertStringContainsString('pret-line-template', $page, 'the old page');
        $this->assertStringNotContainsString('id="ea"', $page);

        // The old post: a draft, then Post — stock leaves oldest-first exactly as before, no batch on the line.
        $this->postAs('/purchase-returns', ['branch_id' => $c->table('branches')->value('id'), 'supplier_id' => $this->supplier()->id, 'return_date' => now()->toDateString(),
            'reason_code' => 'damaged', 'lines' => [['product_id' => $p->id, 'quantity' => '50', 'unit_cost' => '4']]])->assertRedirect();
        $ret = (int) $c->table('purchase_returns')->max('id');
        $this->postAs('/purchase-returns/' . $ret . '/post')->assertRedirect();
        $a = (int) $c->table('inventory_batches')->where('product_id', $p->id)->where('batch_no', 'RO-A')->value('id');
        $this->assertEquals(150, (float) $c->table('stock_balances')->where('inventory_batch_id', $a)->sum('quantity_on_hand'), 'oldest first, as before');
        $this->assertNull($c->table('purchase_return_lines')->where('purchase_return_id', $ret)->value('inventory_batch_id'));
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────────

    private function ret(array $lines, array $extra = []): array
    {
        return $extra + ['_entry_v2' => '1', 'branch_id' => DB::connection('tenant')->table('branches')->value('id'), 'supplier_id' => $this->supplier()->id,
            'return_date' => now()->toDateString(), 'lines' => $lines];
    }

    /** A user holding only these permissions (the owner's rule is tested with screen permissions only). */
    private function clerk(string $code, array $permissions): User
    {
        $c = DB::connection('tenant');
        $id = $c->table('users')->where('email', $code . '@pv2grn.test')->value('id') ?? $c->table('users')->insertGetId([
            'name' => 'Clerk ' . $code, 'email' => $code . '@pv2grn.test', 'password' => bcrypt('x'), 'employee_code' => strtoupper($code),
            'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::setDefaultConnection('tenant');
        foreach ($permissions as $perm) {
            \Spatie\Permission\Models\Permission::findOrCreate($perm, 'tenant');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::on('tenant')->findOrFail($id);
        $user->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return User::on('tenant')->findOrFail($id);
    }

    /** As $user (null = the owner); $json = the way the new screen calls (Accept: application/json). */
    private function callAs(?User $user, string $method, string $uri, array $data = [], bool $json = false)
    {
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user ??= User::on('tenant')->where('email', 'owner@pv2grn.test')->firstOrFail();
        $server = $json ? ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] : [];

        return $this->actingAs($user, 'tenant')->call($method, 'http://' . $this->host . $uri, $data, [], [], $server);
    }

    /** @return array{0:Product,1:ProductPackUnit,2:ProductPackUnit,3:ProductPackUnit} */
    private function medicine(string $name): array
    {
        $unit = Unit::updateOrCreate(['code' => 'TAB'], ['name' => 'Tablet', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $sku = 'PV2-' . strtoupper(substr(md5($name), 0, 6));
        $p = Product::create(['sku' => $sku, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'unit_id' => $unit->id, 'product_type' => 'simple',
            'product_kind' => 'sale_item', 'inventory_consumption_method' => 'stock_item', 'is_sellable' => true, 'is_pos_visible' => true, 'is_purchasable' => true,
            'is_stock_tracked' => true, 'has_expiry' => true, 'requires_batch' => true, 'default_selling_price' => 5.25, 'default_purchase_price' => 4.46, 'status' => 'active']);
        ProductVariant::create(['product_id' => $p->id, 'sku' => $sku, 'name' => $name, 'is_default' => true, 'is_active' => true]);
        $tab = ProductPackUnit::create(['product_id' => $p->id, 'name' => 'Tablet', 'factor' => 1, 'can_sell' => true, 'can_buy' => true]);
        $strip = ProductPackUnit::create(['product_id' => $p->id, 'name' => 'Strip', 'factor' => 10, 'can_sell' => true, 'can_buy' => false]);
        $box = ProductPackUnit::create(['product_id' => $p->id, 'name' => 'Box', 'factor' => 200, 'can_sell' => true, 'can_buy' => true, 'barcode' => '20' . str_pad((string) (crc32($name) % 10000000000), 10, '0', STR_PAD_LEFT) . '7']);

        return [$p->fresh(), $box, $strip, $tab];
    }

    private function supplier(): Supplier
    {
        return Supplier::firstOrCreate(['code' => 'PV2-S1'], ['name' => 'Test Distributor', 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);
    }

    private function grn(array $lines, array $extra = []): array
    {
        return $extra + ['_entry_v2' => '1', 'branch_id' => DB::connection('tenant')->table('branches')->value('id'), 'supplier_id' => $this->supplier()->id,
            'receipt_date' => now()->toDateString(), 'lines' => $lines];
    }

    private function page(string $uri)
    {
        return $this->asOwner()->call('GET', 'http://' . $this->host . $uri);
    }

    private function postAs(string $uri, array $data = [])
    {
        return $this->asOwner()->call('POST', 'http://' . $this->host . $uri, $data);
    }

    private function asOwner()
    {
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->where('email', 'owner@pv2grn.test')->firstOrFail(), 'tenant');
    }

    private function setFeatures(array $features): void
    {
        DB::connection('master')->table('tenants')->where('tenant_code', self::CODE)->update(['primary_business' => 'pharmacy', 'features' => json_encode($features)]);
    }

    private function useDb(): void
    {
        config(['database.connections.tenant.database' => $this->db]);
        DB::purge('tenant');
    }

    private function freshDatabase(): void
    {
        if (stripos($this->db, 'test') === false) {
            throw new \RuntimeException('test database name must contain "test"');
        }
        $c = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$c['host']};port={$c['port']}", $c['username'], $c['password'] ?? '');
        $pdo->exec('DROP DATABASE IF EXISTS `' . $this->db . '`');
        $pdo->exec('CREATE DATABASE `' . $this->db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->useDb();
        if (Artisan::call('migrate:fresh', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]) !== 0) {
            throw new \RuntimeException('migrations failed: ' . Artisan::output());
        }
    }

    private function masterTenant(): void
    {
        $m = DB::connection('master');
        if ($old = $m->table('tenants')->where('tenant_code', self::CODE)->value('id')) {
            foreach (['tenant_domains', 'tenant_databases', 'subscriptions', 'tenant_setting_changes'] as $t) {
                $m->table($t)->where('tenant_id', $old)->delete();
            }
            $m->table('tenants')->where('id', $old)->delete();
        }
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenant_databases')->where('db_database', $this->db)->delete();
        $id = $m->table('tenants')->insertGetId([
            'tenant_code' => self::CODE, 'business_name' => 'Purchasing V2 Test', 'owner_name' => 'Owner', 'owner_email' => 'owner@pv2grn.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $id, 'db_connection' => 'tenant', 'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'), 'db_database' => $this->db,
            'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert(['tenant_id' => $id, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $m->table('subscriptions')->insert([
            'tenant_id' => $id, 'plan_id' => $m->table('plans')->where('code', 'enterprise')->value('id'), 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
