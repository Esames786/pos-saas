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
        $this->assertStringContainsString('Receive (GRN)', $grn);
        $this->assertStringContainsString('id="sup-q"', $grn, 'type-to-search supplier box');
        $this->assertStringContainsString('id="sup-new"', $grn, '+ New supplier');
        $this->assertStringContainsString('class="pur-tabs"', $this->page('/purchase-returns')->assertOk()->getContent(), 'every purchasing page');

        $clerk = $this->clerk('grnonly3', ['tenant.goods-receipts.create', 'tenant.goods-receipts.index']);
        $mine = $this->callAs($clerk, 'GET', '/goods-receipts/create')->assertOk()->getContent();
        $this->assertStringContainsString('Receive (GRN)', $mine);
        $this->assertStringNotContainsString('>Bills <', $mine, 'a role sees only the tabs it can open');

        $this->setFeatures(['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => false]);
        $off = $this->page('/goods-receipts/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('pur-tabs', $off);
        $this->assertStringNotContainsString('id="sup-q"', $off);
        $this->assertStringContainsString('class="form-select', $off, 'the old supplier list is back');
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────────

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
