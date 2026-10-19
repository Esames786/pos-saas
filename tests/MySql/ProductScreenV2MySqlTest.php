<?php

namespace Tests\MySql;

use App\Models\Master\Tenant;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductBarcode;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Spatie\Permission\PermissionRegistrar;

/**
 * PRODUCT-SCREEN-V2 — the one product screen, driven through the real routes on a provisioned tenant.
 *
 * The promises under test: it appears only where the owner switched it on (live tenants keep the old
 * form); each business sees only its own cards; the SERVER turns a card + answers into the fields (a
 * card's lock holds even against a crafted request); and editing never clears a field the screen does
 * not show.
 */
class ProductScreenV2MySqlTest extends MySqlTenantTestCase
{
    private const CODE = 'pv2test';

    private static bool $built = false;

    private string $host;
    private string $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = self::CODE . '.' . config('tenancy.tenant_base_domain');
        $this->db = $this->tenantDb . '_pv2';

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
        $this->setTenant('pharmacy', true);
    }

    // ── where it appears ─────────────────────────────────────────────────────────────────────────

    public function test_it_appears_only_where_switched_on_and_never_on_catering_or_manufacturing(): void
    {
        $this->setTenant('restaurant', false);
        $old = $this->page('/products/create')->assertOk()->getContent();
        $this->assertStringContainsString('Which part of the business is this product for?', $old, 'switch off = the old form');
        $this->assertStringNotContainsString('What are you adding?', $old);

        $this->setTenant('restaurant', true);
        $new = $this->page('/products/create')->assertOk()->getContent();
        $this->assertStringContainsString('What are you adding?', $new);
        $this->assertStringContainsString('name="_form_v2"', $new);

        $mfg = $this->page('/products/create?context=manufacturing')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="_form_v2"', $mfg, 'the manufacturing context keeps its own form tonight');
    }

    public function test_each_business_sees_only_its_own_cards(): void
    {
        $expect = [
            'pharmacy'   => [['Medicine', 'Store item'], ['Menu item', 'Kitchen item', 'Raw material', 'Menu dish']],
            'restaurant' => [['Menu item', 'Ready-made item', 'Kitchen item', 'Packing'], ['Medicine', 'Raw material', 'Menu dish']],
            'catering'   => [['Menu dish', 'Ingredient', 'Disposables &amp; packing', 'Service / hire'], ['Medicine', 'Menu item']],
            'industry'   => [['Raw material', 'Finished product', 'Half-made part', 'Packing material'], ['Medicine', 'Menu item', 'Menu dish']],
            'mart'       => [['Packed item', 'Loose item (by weight)'], ['Medicine', 'Menu item', 'Raw material']],
        ];
        foreach ($expect as $business => [$yes, $no]) {
            $this->setTenant($business, true);
            $html = $this->page('/products/create')->assertOk()->getContent();
            foreach ($yes as $label) {
                $this->assertStringContainsString('<b>' . $label . '</b>', $html, "{$business} shows {$label}");
            }
            foreach ($no as $label) {
                $this->assertStringNotContainsString('<b>' . $label . '</b>', $html, "{$business} never shows {$label}");
            }
            $this->assertStringNotContainsString('Restaurant / POS', $html, 'no restaurant wording on the new screen');

            // The top button is the business's own main screen (the test plan has pos + manufacturing, no catering).
            $button = ['pharmacy' => 'Counter', 'mart' => 'Counter', 'restaurant' => 'POS', 'industry' => 'Production', 'catering' => 'POS'][$business];
            $this->assertMatchesRegularExpression('#<i class="ti ti-[a-z0-9-]+ me-1"></i>' . $button . '\s*</a>#', $html, "{$business} top button = {$button}");
        }
    }

    // ── what a save writes ───────────────────────────────────────────────────────────────────────

    public function test_a_medicine_saves_with_batch_expiry_salt_and_an_automatic_code(): void
    {
        $this->setTenant('pharmacy', true);
        $this->postAs('/products', $this->form(['_card' => 'medicine', 'name' => 'Paracet 500 Tablet', 'default_selling_price' => '5.25',
            'default_purchase_price' => '4.46', 'generic_name' => 'Paracetamol', 'strength' => '500 mg', '_rx' => '0', '_buy' => '1']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $p = Product::where('name', 'Paracet 500 Tablet')->firstOrFail();
        $this->assertTrue($p->is_sellable && $p->is_pos_visible && $p->is_stock_tracked && $p->has_expiry && $p->requires_batch && $p->is_purchasable);
        $this->assertSame('sale_item', $p->product_kind);
        $this->assertSame('stock_item', $p->inventory_consumption_method);
        $this->assertSame('Paracetamol', $p->generic_name);
        $this->assertSame('500 mg', $p->strength);
        $this->assertMatchesRegularExpression('/^MED-\d{6}$/', $p->sku, 'empty code → the next MED code');
        $this->assertSame($p->sku, $p->defaultVariant->sku);
    }

    public function test_a_card_lock_holds_even_against_a_crafted_request(): void
    {
        $this->setTenant('restaurant', true);
        // Packing is never sold: posting "_sell=1" and a price must not make it a counter item.
        $this->postAs('/products', $this->form(['_card' => 'packing', 'name' => 'Burger box', '_sell' => '1', 'default_selling_price' => '50', '_stock' => '1']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $p = Product::where('name', 'Burger box')->firstOrFail();
        $this->assertSame('packaging_material', $p->product_kind);
        $this->assertFalse($p->is_sellable);
        $this->assertFalse($p->is_pos_visible);
        $this->assertTrue($p->is_stock_tracked);

        // A catering service has no stock and is never bought, whatever is posted.
        $this->setTenant('catering', true);
        $this->postAs('/products', $this->form(['_card' => 'service', 'name' => 'Waiter service', 'default_selling_price' => '2500', '_stock' => '1', '_buy' => '1']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $s = Product::where('name', 'Waiter service')->firstOrFail();
        $this->assertSame('service', $s->product_kind);
        $this->assertSame('service', $s->product_type);
        $this->assertFalse($s->is_stock_tracked);
        $this->assertFalse($s->is_purchasable);
        $this->assertSame('none', $s->inventory_consumption_method);

        // An industry finished product is a BOM output and is not bought.
        $this->setTenant('industry', true);
        $this->postAs('/products', $this->form(['_card' => 'finished', 'name' => 'Steel chair', 'default_selling_price' => '6500', '_buy' => '1']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $f = Product::where('name', 'Steel chair')->firstOrFail();
        $this->assertSame('finished_good', $f->product_kind);
        $this->assertTrue($f->is_manufactured_finished_good && $f->can_be_bom_output);
        $this->assertFalse($f->is_purchasable);
    }

    public function test_editing_never_clears_a_field_the_screen_does_not_show(): void
    {
        $this->setTenant('restaurant', true);
        // A menu item saved the way Kashif Food's 210 items are: kind "service", type "service", no stock.
        $p = Product::create([
            'sku' => 'KEEP-1', 'name' => 'Zinger Burger', 'slug' => 'zinger-burger-keep', 'product_type' => 'service', 'product_kind' => 'service',
            'inventory_consumption_method' => 'none', 'is_sellable' => true, 'is_pos_visible' => true, 'is_purchasable' => false, 'is_stock_tracked' => false,
            'default_selling_price' => 550, 'default_purchase_price' => 210, 'status' => 'active',
            'item_kind' => 'both', 'storage_type' => 'chilled', 'shelf_life_days' => 3, 'default_wastage_percent' => 4.5, 'is_perishable' => true,
            'purchase_pack_size' => 12, 'has_variants' => true, 'is_taxable' => true, 'tax_rate_percent' => 16, 'website_price' => 599,
        ]);
        ProductVariant::create(['product_id' => $p->id, 'sku' => 'KEEP-1', 'name' => 'Zinger Burger', 'selling_price' => 550, 'purchase_price' => 210, 'is_default' => true, 'is_active' => true, 'reorder_level' => 7]);
        $before = $p->fresh()->getAttributes();

        $html = $this->page('/products/' . $p->id . '/edit')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/value="menu_item"\s+checked/', $html, 'a "service" menu item opens on the Menu item card');

        $this->putAs('/products/' . $p->id, $this->form(['_card' => 'menu_item', 'name' => 'Zinger Burger Large', 'sku' => 'KEEP-1',
            'default_selling_price' => '600', '_tax' => '1', 'tax_rate_percent' => '16', 'reorder_level' => '7', 'reorder_quantity' => '0']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $after = $p->fresh()->getAttributes();
        foreach (['product_kind', 'product_type', 'inventory_consumption_method', 'is_purchasable', 'is_stock_tracked', 'item_kind', 'storage_type',
                  'shelf_life_days', 'default_wastage_percent', 'is_perishable', 'purchase_pack_size', 'has_variants', 'default_purchase_price',
                  'is_taxable', 'tax_rate_percent', 'website_price', 'can_be_bom_component', 'can_be_bom_output', 'has_expiry', 'requires_batch'] as $f) {
            $this->assertEquals($before[$f], $after[$f], "{$f} must not change when only the name and price were edited");
        }
        $this->assertSame('Zinger Burger Large', $after['name']);
        $this->assertEquals(600, $after['default_selling_price']);
    }

    /**
     * 19 Oct, owner on a live tenant: an item sold inside deals / online is "Hidden from POS" yet sellable.
     * The screen read "sold" as "has a counter button", so any save would have put it back on the counter.
     */
    public function test_a_hidden_from_the_counter_item_stays_hidden_until_its_own_question_says_otherwise(): void
    {
        $this->setTenant('restaurant', true);
        $p = Product::create([
            'sku' => 'HIDE-1', 'name' => 'Rice of the house', 'slug' => 'rice-of-the-house', 'product_type' => 'service', 'product_kind' => 'service',
            'inventory_consumption_method' => 'none', 'is_sellable' => true, 'is_pos_visible' => false, 'is_purchasable' => false, 'is_stock_tracked' => false,
            'default_selling_price' => 2900, 'status' => 'active',
        ]);
        ProductVariant::create(['product_id' => $p->id, 'sku' => 'HIDE-1', 'name' => 'Rice of the house', 'selling_price' => 2900, 'is_default' => true, 'is_active' => true]);

        $html = $this->page('/products/' . $p->id . '/edit')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="pos-n" name="_pos" value="0"\s+checked/', $html, 'the screen shows it as hidden');

        $edit = ['_card' => 'menu_item', 'name' => 'Rice of the house', 'sku' => 'HIDE-1', 'default_selling_price' => '2950'];
        // A save that does not carry the question (an old page, a crafted request) keeps it hidden.
        $this->putAs('/products/' . $p->id, $this->form($edit))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($p->fresh()->is_pos_visible);
        $this->assertTrue($p->fresh()->is_sellable);
        // The screen's own answer "No" keeps it hidden; "Yes" puts the button back.
        $this->putAs('/products/' . $p->id, $this->form($edit + ['_pos' => '0']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($p->fresh()->is_pos_visible);
        $this->putAs('/products/' . $p->id, $this->form($edit + ['_pos' => '1']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($p->fresh()->is_pos_visible);

        // A new sold item has its button unless the owner says no.
        $this->postAs('/products', $this->form(['_card' => 'menu_item', 'name' => 'Plain Rice New', 'default_selling_price' => '170']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Product::where('name', 'Plain Rice New')->firstOrFail()->is_pos_visible);
        $this->postAs('/products', $this->form(['_card' => 'menu_item', 'name' => 'Deal-only Raita', 'default_selling_price' => '0', '_pos' => '0']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(Product::where('name', 'Deal-only Raita')->firstOrFail()->is_pos_visible);
    }

    public function test_batch_and_expiry_keep_their_saved_difference_and_more_settings_save(): void
    {
        $this->setTenant('restaurant', true);
        $this->postAs('/products', $this->form(['_card' => 'kitchen', 'name' => 'Fresh Cream', '_buy' => '1', '_stock' => '1', '_expiry' => '1']))->assertRedirect()->assertSessionHasNoErrors();
        $p = Product::where('name', 'Fresh Cream')->firstOrFail();
        $p->update(['requires_batch' => false]);   // expiry kept, batch not — the old form allowed it

        $edit = ['_card' => 'kitchen', 'name' => 'Fresh Cream', 'sku' => $p->sku, '_buy' => '1', '_stock' => '1', '_expiry' => '1'];
        $this->putAs('/products/' . $p->id, $this->form($edit))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($p->fresh()->has_expiry);
        $this->assertFalse($p->fresh()->requires_batch, 'the answer did not change, so the saved difference stays');

        // More settings: posted boxes are saved; a box the business does not show keeps its saved value.
        $unit = DB::connection('tenant')->table('units')->value('id');
        $this->putAs('/products/' . $p->id, $this->form($edit + ['_more' => '1', 'item_kind' => 'ingredient', '_perishable' => '1', 'storage_type' => 'Fridge',
            'shelf_life_days' => '5', 'default_wastage_percent' => '10', 'purchase_unit_id' => (string) $unit, 'purchase_pack_size' => '12']))->assertRedirect()->assertSessionHasNoErrors();
        $f = $p->fresh();
        $this->assertSame('ingredient', $f->item_kind);
        $this->assertTrue($f->is_perishable);
        $this->assertSame('Fridge', $f->storage_type);
        $this->assertEquals(5, $f->shelf_life_days);
        $this->assertEquals(10, $f->default_wastage_percent);
        $this->assertEquals($unit, $f->purchase_unit_id);
        $this->assertEquals(12, $f->purchase_pack_size);

        $this->setTenant('pharmacy', true);   // the pharmacy screen shows only "Kept in"
        $this->putAs('/products/' . $p->id, $this->form(['_card' => 'store_item', 'name' => 'Fresh Cream', 'sku' => $p->sku, '_buy' => '1', '_stock' => '1', '_expiry' => '1',
            'default_selling_price' => '0', '_more' => '1', 'storage_type' => 'Freezer']))->assertRedirect()->assertSessionHasNoErrors();
        $g = $p->fresh();
        $this->assertSame('Freezer', $g->storage_type);
        $this->assertEquals(5, $g->shelf_life_days, 'not on this screen — kept');
        $this->assertEquals(10, $g->default_wastage_percent);
        $this->assertTrue($g->is_perishable);

        $html = $this->page('/products/' . $p->id . '/edit')->assertOk()->getContent();
        $this->assertStringContainsString('id="storage_type"', $html);
        $this->assertStringNotContainsString('id="shelf_life_days"', $html, 'a pharmacy does not see the kitchen boxes');
    }

    public function test_the_products_list_speaks_plain_words_only_where_the_new_screen_is_on(): void
    {
        $p = Product::create(['sku' => 'LIST-1', 'name' => 'Hidden Shashlik', 'slug' => 'hidden-shashlik', 'product_type' => 'service', 'product_kind' => 'service',
            'inventory_consumption_method' => 'none', 'is_sellable' => true, 'is_pos_visible' => false, 'default_selling_price' => 0, 'status' => 'active']);

        $this->setTenant('restaurant', false);
        $old = $this->page('/products?search=Hidden+Shashlik')->assertOk()->getContent();
        $this->assertStringContainsString('Role / Visibility', $old);
        $this->assertStringContainsString('Hidden from POS', $old, 'switch off: the list is exactly as before');

        $this->setTenant('restaurant', true);
        $new = $this->page('/products?search=Hidden+Shashlik')->assertOk()->getContent();
        $this->assertStringContainsString('Where it shows', $new);
        $this->assertStringContainsString('Sold, no counter button', $new);
        $this->assertStringContainsString('old name: Service Item', $new);
        $this->assertStringContainsString('Has a button on the counter', $new, 'the filter ticks are plain words');
        $this->assertStringContainsString('Service — sold, no stock', $new);
        $this->assertStringNotContainsString('>POS Visible<', $new);

        $this->assertStringContainsString('Hidden Shashlik', $this->page('/products?flags[]=hidden_from_pos')->getContent(), 'same filter underneath');
    }

    public function test_stock_counting_cannot_be_switched_off_after_stock_moved(): void
    {
        $this->setTenant('mart', true);
        $this->postAs('/products', $this->form(['_card' => 'packed', 'name' => 'Chips 60g', 'default_selling_price' => '100', '_stock' => '1']))->assertRedirect()->assertSessionHasNoErrors();
        $p = Product::where('name', 'Chips 60g')->firstOrFail();
        DB::connection('tenant')->table('stock_ledgers')->insert([
            'branch_id' => DB::connection('tenant')->table('branches')->value('id'), 'product_id' => $p->id, 'product_variant_id' => $p->defaultVariant->id,
            'movement_type' => 'opening_stock', 'direction' => 'in', 'quantity' => 10, 'unit_cost' => 80, 'total_cost' => 800,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->putAs('/products/' . $p->id, $this->form(['_card' => 'packed', 'name' => 'Chips 60g', 'sku' => $p->sku, 'default_selling_price' => '100', '_stock' => '0']))
            ->assertSessionHasErrors('_stock');
        $this->assertTrue($p->fresh()->is_stock_tracked, 'still counted');
    }

    public function test_barcode_reorder_level_and_unique_codes(): void
    {
        $this->setTenant('pharmacy', true);
        ProductVariant::create(['product_id' => Product::create(['sku' => 'OTHER-9', 'name' => 'Other', 'slug' => 'other-9', 'product_type' => 'simple', 'default_selling_price' => 1, 'status' => 'active'])->id,
            'sku' => 'STR-000001', 'name' => 'A variant already using STR-000001', 'is_default' => false, 'is_active' => true]);

        $this->postAs('/products', $this->form(['_card' => 'store_item', 'name' => 'Shampoo 180 ml', 'default_selling_price' => '650']))->assertRedirect()->assertSessionHasNoErrors();
        $p = Product::where('name', 'Shampoo 180 ml')->firstOrFail();
        $this->assertSame('STR-000002', $p->sku, 'the next code skips one a variant already uses');

        $this->putAs('/products/' . $p->id, $this->form(['_card' => 'store_item', 'name' => 'Shampoo 180 ml', 'sku' => $p->sku, 'default_selling_price' => '650',
            '_barcode' => '1', 'barcode' => '2012345678903', '_barcode_made' => '1', 'reorder_level' => '12', 'reorder_quantity' => '24']))->assertRedirect()->assertSessionHasNoErrors();
        $v = $p->fresh()->defaultVariant;
        $this->assertSame('2012345678903', $v->barcode, 'the old form dropped the barcode on edit — now it is saved');
        $this->assertEquals(12, $v->reorder_level, 'and the reorder level');
        $this->assertTrue(ProductBarcode::where('barcode', '2012345678903')->where('product_id', $p->id)->where('is_primary', true)->exists());

        $this->postAs('/products', $this->form(['_card' => 'store_item', 'name' => 'Soap', 'default_selling_price' => '90', '_barcode' => '1', 'barcode' => '2012345678903']))
            ->assertSessionHasErrors('barcode');
    }

    public function test_save_and_add_another_opens_a_new_one_of_the_same_type(): void
    {
        $this->setTenant('pharmacy', true);
        $this->postAs('/products', $this->form(['_card' => 'medicine', 'name' => 'Brufen syrup', 'default_selling_price' => '175', '_after' => 'new']))
            ->assertRedirect('/products/create?card=medicine');
        $html = $this->page('/products/create?card=medicine')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/value="medicine"\s+checked/', $html);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    private function form(array $over): array
    {
        return array_merge(['_form_v2' => '1', 'status' => 'active', 'sku' => '', 'category_id' => '', 'unit_id' => '', '_tax' => '0'], $over);
    }

    private function page(string $uri)
    {
        return $this->asOwner()->call('GET', 'http://' . $this->host . $uri);
    }

    private function postAs(string $uri, array $data = [])
    {
        return $this->asOwner()->call('POST', 'http://' . $this->host . $uri, $data);
    }

    private function putAs(string $uri, array $data = [])
    {
        return $this->asOwner()->call('PUT', 'http://' . $this->host . $uri, $data);
    }

    private function asOwner()
    {
        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs(User::on('tenant')->where('email', 'owner@pv2test.test')->firstOrFail(), 'tenant');
    }

    private function setTenant(string $business, bool $v2): void
    {
        DB::connection('master')->table('tenants')->where('tenant_code', self::CODE)->update([
            'primary_business' => $business, 'features' => json_encode(['product_screen_v2' => $v2]),
        ]);
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
        // Another test may wipe tenants with foreign keys off, leaving this host's domain behind.
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenant_databases')->where('db_database', $this->db)->delete();
        $id = $m->table('tenants')->insertGetId([
            'tenant_code' => self::CODE, 'business_name' => 'Screen V2 Test', 'owner_name' => 'Owner', 'owner_email' => 'owner@pv2test.test',
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
