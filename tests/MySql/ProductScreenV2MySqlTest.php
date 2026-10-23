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

        // W3/W4 S1 (deliberate change of the 19 Oct wording): Structure, Role and Behaviour apart, the role names a
        // shop recognises, behaviour as Any / Yes / No, no "old name" and no "What it is".
        $this->setTenant('restaurant', true);
        $new = $this->page('/products?search=Hidden+Shashlik')->assertOk()->getContent();
        $row = $this->row($new, 'Hidden Shashlik');
        $this->assertStringContainsString('<td>Simple</td>', $row, 'structure');
        $this->assertStringContainsString('data-role="service">Service</span>', $row, 'the recognisable role name');
        $this->assertStringContainsString('>Sold</span>', $row);
        $this->assertStringNotContainsString('On the counter</span>', $row, 'sold, but no button on the counter');
        foreach (['old name', 'What it is', 'Where it shows', '>POS Visible<', 'name="product_type"', 'name="flags[]"'] as $gone) {
            $this->assertStringNotContainsString($gone, $new, "gone from the plain list: {$gone}");
        }
        $this->assertStringContainsString('name="beh[counter]"', $new, 'each behaviour is one Any / Yes / No question');
        $this->assertMatchesRegularExpression('/<option value="service"[^>]*>Service<\/option>/', $new, 'the Role filter offers the same recognisable names');

        $this->assertStringContainsString('Hidden Shashlik', $this->page('/products?flags[]=hidden_from_pos')->getContent(), 'an old link still filters');
    }

    public function test_the_plain_list_asks_each_behaviour_any_yes_or_no_and_two_answers_never_contradict(): void
    {
        $this->setTenant('restaurant', true);
        $make = fn (string $name, array $a) => Product::create($a + ['sku' => 'S1-' . substr(md5($name), 0, 6), 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name),
            'product_type' => 'simple', 'product_kind' => 'sale_item', 'inventory_consumption_method' => 'stock_item', 'is_sellable' => true,
            'is_pos_visible' => true, 'is_purchasable' => false, 'is_stock_tracked' => false, 'has_variants' => false, 'default_selling_price' => 10, 'status' => 'active']);
        $make('S1Q Cola Counter', ['is_purchasable' => true, 'is_stock_tracked' => true]);
        $make('S1Q Biryani Recipe', ['is_pos_visible' => false, 'product_type' => 'recipe', 'inventory_consumption_method' => 'recipe']);
        // Bought, not sold (the catalog lists a not-sold item only when it is flagged for the counter; not sold, so
        // "On the counter" is still No).
        $make('S1Q Box Packing', ['product_kind' => 'packaging_material', 'is_sellable' => false, 'is_pos_visible' => true, 'is_purchasable' => true, 'is_stock_tracked' => true]);
        $make('S1Q Karahi Sizes', ['has_variants' => true]);
        $names = fn (string $q) => collect(['S1Q Cola Counter', 'S1Q Biryani Recipe', 'S1Q Box Packing', 'S1Q Karahi Sizes'])
            ->filter(fn ($n) => str_contains($this->page('/products?search=S1Q&' . $q)->getContent(), '<b>' . $n . '</b>'))->values()->all();

        $this->assertSame(['S1Q Cola Counter', 'S1Q Box Packing'], $names('beh[bought]=yes'));
        $this->assertSame(['S1Q Biryani Recipe', 'S1Q Karahi Sizes'], $names('beh[bought]=no'));
        $this->assertSame(['S1Q Cola Counter', 'S1Q Karahi Sizes'], $names('beh[counter]=yes'));
        $this->assertSame(['S1Q Biryani Recipe', 'S1Q Box Packing'], $names('beh[counter]=no'), 'Yes and No split the list in two — nothing is in both');
        $this->assertSame(['S1Q Biryani Recipe'], $names('beh[recipe]=yes'));
        $this->assertSame(['S1Q Karahi Sizes'], $names('structure=variants'));
        $this->assertSame(['S1Q Box Packing'], $names('role=packaging_material'));
        $this->assertSame(['S1Q Box Packing'], $names('beh[bought]=yes&beh[sold]=no'));
        $this->assertCount(4, $names('beh[bought]=maybe'), 'an unknown answer is "Any"');
        // The Role column reads the role, never the shape: a packaging item whose product_type is "simple".
        $this->assertStringContainsString('data-role="packaging_material">Packaging</span>', $this->row($this->page('/products?search=S1Q+Box')->getContent(), 'S1Q Box Packing'));
    }

    public function test_deals_are_listed_under_combo_and_open_the_existing_deal_editor(): void
    {
        $this->setTenant('restaurant', true);
        $items = collect(['S1D Rice', 'S1D Drink'])->map(fn ($n) => Product::create(['sku' => 'S1D-' . substr(md5($n), 0, 6), 'name' => $n, 'slug' => \Illuminate\Support\Str::slug($n),
            'product_type' => 'simple', 'product_kind' => 'sale_item', 'inventory_consumption_method' => 'none', 'is_sellable' => true, 'is_pos_visible' => true,
            'default_selling_price' => 100, 'status' => 'active']));
        $combo = DB::connection('tenant')->table('combos')->insertGetId(['name' => 'S1D Family Deal', 'code' => 'S1D-DEAL', 'price' => 2900, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()]);
        foreach ($items as $i) {
            DB::connection('tenant')->table('combo_components')->insert(['combo_id' => $combo, 'product_id' => $i->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $products = Product::count();

        $list = $this->page('/products?search=S1D')->assertOk()->getContent();
        $this->assertStringContainsString('Deals are listed under Structure "Combo"', $list);
        $this->assertStringContainsString('?structure=combo', $list);

        $deals = $this->page('/products?structure=combo&search=S1D+Family')->assertOk()->getContent();
        $row = $this->row($deals, 'S1D Family Deal');
        $this->assertStringContainsString('a deal · 2 items', $row);
        $this->assertStringContainsString('/combos/' . $combo . '/edit', $row, 'the existing deal editor');
        $this->assertStringContainsString('Open deal', $row);
        $this->assertSame($products, Product::count(), 'a deal is never copied into a product');
    }

    public function test_the_plain_list_shows_packs_in_words_where_packs_are_on(): void
    {
        $kg = \App\Models\Tenant\Unit::updateOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        $rice = Product::create(['sku' => 'S1P-RICE', 'name' => 'S1P Basmati', 'slug' => 's1p-basmati', 'unit_id' => $kg->id, 'product_type' => 'simple',
            'product_kind' => 'sale_item', 'inventory_consumption_method' => 'stock_item', 'is_sellable' => true, 'is_pos_visible' => true,
            'is_purchasable' => true, 'is_stock_tracked' => true, 'default_selling_price' => 320, 'status' => 'active']);
        \App\Models\Tenant\ProductPackUnit::create(['product_id' => $rice->id, 'name' => 'Kilogram', 'factor' => 1, 'can_sell' => true, 'can_buy' => true]);
        \App\Models\Tenant\ProductPackUnit::create(['product_id' => $rice->id, 'name' => 'Bag', 'factor' => 2.5, 'can_sell' => true, 'can_buy' => true]);

        DB::connection('master')->table('tenants')->where('tenant_code', self::CODE)->update(['primary_business' => 'mart',
            'features' => json_encode(['product_screen_v2' => true, 'packs' => true])]);
        $on = $this->page('/products?search=S1P')->assertOk()->getContent();
        $this->assertStringContainsString('<th scope="col">Packs</th>', $on);
        $this->assertStringContainsString('Bag = 2.5 Kilogram', $this->row($on, 'S1P Basmati'));

        $this->setTenant('mart', true);   // packs off
        $this->assertStringNotContainsString('<th scope="col">Packs</th>', $this->page('/products?search=S1P')->getContent());
    }

    /** The table row of $name on a rendered list. */
    private function row(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('/<tr>\s*<td>(?:<a [^>]*>)?<b>' . preg_quote($name, '/') . '<\/b>.*?<\/tr>/s', $html, $m), "the list shows {$name}");

        return $m[0];
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

    public function test_the_usual_buying_discount_and_scheme_save_from_the_new_screen_only(): void
    {
        // PURCHASE-DISCOUNTS-1: "product level discount fixed ya %" — saved on the product, filled into the GRN.
        $this->setTenant('pharmacy', true);
        $page = $this->page('/products/create')->assertOk()->getContent();
        $this->assertStringContainsString('name="purchase_discount_value"', $page);
        $this->assertStringContainsString('name="purchase_scheme"', $page, 'a pharmacy is asked for the usual scheme');

        $this->postAs('/products', $this->form(['_card' => 'medicine', 'name' => 'Disc Tab 10mg', 'default_selling_price' => '9', 'default_purchase_price' => '7',
            '_rx' => '0', '_buy' => '1', 'purchase_discount_value' => '4', 'purchase_discount_type' => 'percent', 'purchase_scheme' => '10 + 2']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $p = Product::where('name', 'Disc Tab 10mg')->firstOrFail();
        $this->assertSame(['percent', 4.0, 10, 2], [$p->purchase_discount_type, (float) $p->purchase_discount_value, (int) $p->purchase_scheme_buy, (int) $p->purchase_scheme_free]);

        $this->postAs('/products', $this->form(['_card' => 'medicine', 'name' => 'Disc Tab 20mg', 'default_selling_price' => '9', '_rx' => '0', '_buy' => '1',
            'purchase_discount_value' => '120', 'purchase_discount_type' => 'percent']))->assertSessionHasErrors('purchase_discount_value');
        $this->postAs('/products', $this->form(['_card' => 'medicine', 'name' => 'Disc Tab 30mg', 'default_selling_price' => '9', '_rx' => '0', '_buy' => '1',
            'purchase_discount_value' => '', 'purchase_scheme' => '10x1']))->assertSessionHasErrors('purchase_scheme');

        $this->setTenant('restaurant', true);
        $this->assertStringNotContainsString('name="purchase_scheme"', $this->page('/products/create')->assertOk()->getContent(), 'a restaurant is not asked about schemes');
    }

    // ── UB-10: the stock unit is fixed once stock or purchases ran in it ─────────────────────────

    public function test_a_unit_with_history_never_changes_on_either_screen_or_behind_them(): void
    {
        [$each, $kg] = [$this->unit('UG-EA', 'Each', 'quantity'), $this->unit('UG-KG', 'Kilogram', 'weight')];
        $this->setTenant('mart', true);
        $this->postAs('/products', $this->form(['_card' => 'packed', 'name' => 'Unit Guard Rice', 'default_selling_price' => '100', '_stock' => '1', '_buy' => '1', 'unit_id' => (string) $each]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $p = Product::where('name', 'Unit Guard Rice')->firstOrFail();
        $edit = fn (array $over) => $this->form($over + ['_card' => 'packed', 'name' => 'Unit Guard Rice', 'sku' => $p->sku, 'default_selling_price' => '100', '_stock' => '1', '_buy' => '1']);

        // No history yet: the unit may still change, as always.
        $this->putAs('/products/' . $p->id, $edit(['unit_id' => (string) $kg]))->assertSessionHasNoErrors();
        $this->assertSame($kg, (int) $p->fresh()->unit_id, 'nothing ran in it yet — free to change');

        $this->stockMoved($p->fresh());

        // The new screen.
        $this->putAs('/products/' . $p->id, $edit(['unit_id' => (string) $each]))->assertSessionHasErrors('unit_id');
        $this->assertStringContainsString('ran in Kilogram', (string) session('errors')->first('unit_id'));
        $this->putAs('/products/' . $p->id, $edit(['unit_id' => '']))->assertSessionHasErrors('unit_id');
        // The old screen.
        $this->setTenant('mart', false);
        $this->putAs('/products/' . $p->id, ['sku' => $p->sku, 'name' => 'Unit Guard Rice', 'product_type' => 'simple', 'default_selling_price' => '100',
            'status' => 'active', 'unit_id' => (string) $each])->assertSessionHasErrors('unit_id');
        // Behind every screen (an import, a script, an API): the model itself refuses.
        try {
            $p->fresh()->update(['unit_id' => $each]);
            $this->fail('the model must refuse a unit change on an item with history');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('unit_id', $e->errors());
        }
        $this->assertSame($kg, (int) $p->fresh()->unit_id, 'still Kilogram');

        // Everything else still saves.
        $this->setTenant('mart', true);
        $this->putAs('/products/' . $p->id, $edit(['unit_id' => (string) $kg, 'default_selling_price' => '120']))->assertSessionHasNoErrors();
        $this->assertEquals(120, $p->fresh()->default_selling_price);
    }

    public function test_opening_an_item_and_saving_it_unchanged_changes_nothing(): void
    {
        // Roadmap A02, through the real edit page: what the page draws is posted back exactly as a browser would.
        $each = $this->unit('A02-EA', 'Each', 'quantity');
        $this->setTenant('mart', true);
        $this->postAs('/products', $this->form(['_card' => 'packed', 'name' => 'A02 Juice 1L', 'default_selling_price' => '180', 'default_purchase_price' => '150',
            '_stock' => '1', '_buy' => '1', 'unit_id' => (string) $each, '_tax' => '1', 'tax_rate_percent' => '17', 'reorder_level' => '6']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $p = Product::where('name', 'A02 Juice 1L')->firstOrFail();
        $this->stockMoved($p);
        $snap = fn () => [
            'product' => collect(Product::findOrFail($p->id)->getAttributes())->except(['updated_at'])->all(),
            'variants' => ProductVariant::where('product_id', $p->id)->get()->map(fn ($v) => collect($v->getAttributes())->except(['updated_at'])->all())->all(),
            'barcodes' => ProductBarcode::where('product_id', $p->id)->get()->map(fn ($b) => collect($b->getAttributes())->except(['updated_at'])->all())->all(),
            'translations' => DB::connection('tenant')->table('product_translations')->where('product_id', $p->id)->get(['language_code', 'name', 'description'])->all(),
        ];
        $before = $snap();

        $form = $this->formOnPage($this->page('/products/' . $p->id . '/edit')->assertOk()->getContent(), '/products/' . $p->id);
        $this->call('POST', 'http://' . $this->host . '/products/' . $p->id, $form)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals($before, $snap(), 'open + save with no edits: no unit, kind, price, tax, code or name changed');
    }

    public function test_an_item_on_a_unit_switched_off_keeps_it_when_saved_unchanged(): void
    {
        // The edit lists only active units: an item on a unit that was switched off drew "— Piece —" and the save
        // silently took its unit away. Its own unit is now always in the list.
        $box = $this->unit('OFF-BOX', 'Old Box', 'quantity');
        $this->setTenant('mart', true);
        $this->postAs('/products', $this->form(['_card' => 'packed', 'name' => 'Old Box Item', 'default_selling_price' => '50', 'unit_id' => (string) $box]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $p = Product::where('name', 'Old Box Item')->firstOrFail();
        DB::connection('tenant')->table('units')->where('id', $box)->update(['is_active' => 0]);

        $form = $this->formOnPage($this->page('/products/' . $p->id . '/edit')->assertOk()->getContent(), '/products/' . $p->id);
        $this->assertSame((string) $box, $form['unit_id'] ?? null, 'the page still shows its own unit');
        $this->call('POST', 'http://' . $this->host . '/products/' . $p->id, $form)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($box, (int) $p->fresh()->unit_id);
    }

    // ── W3/W4 S2: an edit keeps what the screen does not show — the server decides, not the browser ─────────────

    public function test_an_edit_keeps_a_batch_and_expiry_its_card_does_not_ask_about(): void
    {
        // A menu item's card never asks about batch and expiry; this one was saved with both (old form, import).
        $p = $this->savedWith('restaurant', 'menu_item', 'S2 Bottled Lassi', ['has_expiry' => 1, 'requires_batch' => 1]);
        $page = $this->page('/products/' . $p->id . '/edit')->assertOk()->getContent();
        $this->assertStringContainsString('Not shown for this item — kept as saved', $page);
        $this->assertStringContainsString('Batch &amp; expiry: <b>Yes</b>', $page);

        $this->saveUnchanged($p);
        $this->assertTrue((bool) $p->fresh()->has_expiry, 'it was saved as "no" before');
        $this->assertTrue((bool) $p->fresh()->requires_batch);
    }

    public function test_an_edit_keeps_a_kitchen_items_recipe_its_card_does_not_ask_about(): void
    {
        // A kitchen intermediate (raw material made from a recipe); the Kitchen item card does not ask "recipe".
        $p = $this->savedWith('restaurant', 'kitchen', 'S2 Biryani Masala Base', ['inventory_consumption_method' => 'recipe', 'product_type' => 'recipe']);

        $this->saveUnchanged($p);
        $this->assertSame('recipe', $p->fresh()->inventory_consumption_method, 'it dropped to stock_item / none before');
        $this->assertSame('recipe', $p->fresh()->product_type);
        $this->assertSame('raw_material', $p->fresh()->product_kind);
    }

    public function test_an_item_no_card_fits_keeps_its_role_until_another_card_is_picked(): void
    {
        // A packaging item in a pharmacy: no pharmacy card allows packaging, so the screen falls back to Medicine —
        // whose locks (sold, stock, expiry) used to re-make it a medicine on an unchanged save.
        $p = $this->savedWith('pharmacy', 'store_item', 'S2 Carry Bags', ['product_kind' => 'packaging_material', 'is_sellable' => 0, 'is_pos_visible' => 0,
            'has_expiry' => 0, 'requires_batch' => 0]);
        $page = $this->page('/products/' . $p->id . '/edit')->assertOk()->getContent();
        $this->assertStringContainsString('id="pv2-misfit"', $page);
        $this->assertStringContainsString('Its role (<b>Packaging</b>)', $page);

        $form = $this->saveUnchanged($p);
        $kept = $p->fresh();
        $this->assertSame('packaging_material', $kept->product_kind, 'the role is kept');
        $this->assertFalse((bool) $kept->is_sellable);
        $this->assertFalse((bool) $kept->has_expiry);

        // Picking another card is a real change, and works as before.
        $this->call('POST', 'http://' . $this->host . '/products/' . $p->id, ['_card' => 'store_item'] + $form)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('sale_item', $p->fresh()->product_kind);
        $this->assertTrue((bool) $p->fresh()->is_sellable);
    }

    public function test_a_misfit_in_a_factory_keeps_its_factory_flags(): void
    {
        // A service in an industry shop: no card allows it, the screen falls back to "Finished product", whose
        // factory flags (finished good, BOM output) used to be written on an unchanged save.
        $p = $this->savedWith('industry', 'raw', 'S2 Machine Repair', ['product_kind' => 'service', 'is_sellable' => 1, 'is_purchasable' => 0,
            'is_stock_tracked' => 0, 'inventory_consumption_method' => 'none', 'can_be_bom_output' => 0, 'can_be_bom_component' => 0, 'is_manufactured_finished_good' => 0]);

        $this->saveUnchanged($p);
        $kept = $p->fresh();
        $this->assertSame('service', $kept->product_kind);
        $this->assertFalse((bool) $kept->is_manufactured_finished_good);
        $this->assertFalse((bool) $kept->can_be_bom_output);
    }

    public function test_a_saved_service_with_stock_history_can_still_be_saved(): void
    {
        $p = $this->savedWith('restaurant', 'menu_item', 'S2 Hookah Service', ['product_kind' => 'service', 'is_stock_tracked' => 1, 'is_purchasable' => 1,
            'inventory_consumption_method' => 'stock_item']);
        $this->stockMoved($p);

        $this->saveUnchanged($p);   // it was refused: "Stock of this item has already moved…"
        $this->assertTrue((bool) $p->fresh()->is_stock_tracked);
        $this->assertSame('service', $p->fresh()->product_kind);
    }

    public function test_an_edit_keeps_a_switched_off_category_and_purchase_unit_on_both_screens(): void
    {
        $c = DB::connection('tenant');
        $shelf = $c->table('categories')->insertGetId(['name' => 'S2 Old Shelf', 'slug' => 's2-old-shelf-' . \Illuminate\Support\Str::random(4), 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $sack = $this->unit('S2-SACK', 'S2 Sack', 'quantity');
        $p = $this->savedWith('restaurant', 'kitchen', 'S2 Rice Sack', ['category_id' => $shelf, 'purchase_unit_id' => $sack, 'purchase_pack_size' => 50]);
        $c->table('categories')->where('id', $shelf)->update(['is_active' => 0]);
        $c->table('units')->where('id', $sack)->update(['is_active' => 0]);

        $this->saveUnchanged($p);
        $this->assertSame($shelf, (int) $p->fresh()->category_id, 'new screen: the category is kept (it was emptied)');
        $this->assertSame($sack, (int) $p->fresh()->purchase_unit_id, 'new screen: the unit it is bought in is kept');

        $this->setTenant('restaurant', false);
        $this->saveUnchanged($p);
        $this->assertSame($shelf, (int) $p->fresh()->category_id, 'old screen: the category is kept too');
    }

    /** An item made on the new screen with $card, then given saved settings directly (as the old form or an import did). */
    private function savedWith(string $business, string $card, string $name, array $saved): Product
    {
        $this->setTenant($business, true);
        $this->postAs('/products', $this->form(['_card' => $card, 'name' => $name, 'default_selling_price' => '100', 'default_purchase_price' => '60']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $p = Product::where('name', $name)->firstOrFail();
        DB::connection('tenant')->table('products')->where('id', $p->id)->update($saved);

        return $p->fresh();
    }

    /** Open the edit page and save exactly what it shows. @return array the fields it posted */
    private function saveUnchanged(Product $p): array
    {
        $form = $this->formOnPage($this->page('/products/' . $p->id . '/edit')->assertOk()->getContent(), '/products/' . $p->id);
        $this->call('POST', 'http://' . $this->host . '/products/' . $p->id, $form)->assertRedirect()->assertSessionHasNoErrors();

        return $form;
    }

    public function test_the_bulk_import_keeps_the_unit_of_an_item_with_history(): void
    {
        $each = $this->unit('IMP-EA', 'IMP-EA', 'quantity');
        $this->setTenant('mart', true);
        $this->postAs('/products', $this->form(['_card' => 'packed', 'name' => 'Imported Tea', 'sku' => 'IMP-TEA', 'default_selling_price' => '90', '_stock' => '1', 'unit_id' => (string) $each]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->postAs('/products', $this->form(['_card' => 'packed', 'name' => 'Imported Salt', 'sku' => 'IMP-SALT', 'default_selling_price' => '40', '_stock' => '1', 'unit_id' => (string) $each]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $tea = Product::where('sku', 'IMP-TEA')->firstOrFail();
        $this->stockMoved($tea);   // Salt has no history

        $csv = "sku,name,category,unit,product_type,purchase_price,selling_price,barcode\nIMP-TEA,Imported Tea,,IMP-KG,simple,70,95,\nIMP-SALT,Imported Salt,,IMP-KG,simple,30,45,\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('items.csv', $csv);
        $this->asOwner()->call('POST', 'http://' . $this->host . '/products-bulk-import', [], [], ['csv_file' => $file])->assertRedirect();

        $this->assertSame($each, (int) $tea->fresh()->unit_id, 'stock already ran in IMP-EA — the import keeps it');
        $this->assertEquals(95, $tea->fresh()->default_selling_price, 'the rest of the row is imported as before');
        $kg = (int) DB::connection('tenant')->table('units')->where('code', 'IMP-KG')->value('id');
        $this->assertSame($kg, (int) Product::where('sku', 'IMP-SALT')->value('unit_id'), 'no history: the unit changes, as before');
        $this->assertStringContainsString('1 kept its unit', (string) session('status'));
    }

    private function unit(string $code, string $name, string $type): int
    {
        return (int) DB::connection('tenant')->table('units')->insertGetId(['code' => $code, 'name' => $name, 'unit_type' => $type,
            'base_factor' => 1, 'is_base' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function stockMoved(Product $p): void
    {
        DB::connection('tenant')->table('stock_ledgers')->insert([
            'branch_id' => DB::connection('tenant')->table('branches')->value('id'), 'product_id' => $p->id, 'product_variant_id' => $p->defaultVariant?->id,
            'movement_type' => 'opening_stock', 'direction' => 'in', 'quantity' => 10, 'unit_cost' => 80, 'total_cost' => 800,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * The fields of the form posting to $action, as a browser sends them: hidden/text inputs, checked radios and
     * checkboxes, each select's selected option (else its first), textareas — parsed the way PHP parses a post.
     */
    private function formOnPage(string $html, string $action): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $form = null;
        $xpath = new \DOMXPath($dom);
        foreach ($dom->getElementsByTagName('form') as $f) {
            // the save form — never the delete form that posts to the same address
            if (str_ends_with(parse_url($f->getAttribute('action'), PHP_URL_PATH) ?? '', $action)
                && $xpath->query('.//input[@name="_method"][@value="PUT" or @value="put" or @value="PATCH"]', $f)->length) {
                $form = $f;
                break;
            }
        }
        $this->assertNotNull($form, "the save form posting to {$action}");
        $pairs = [];
        foreach ($xpath->query('.//input|.//select|.//textarea', $form) as $el) {
            $name = $el->getAttribute('name');
            if ($name === '' || $el->hasAttribute('disabled')) {
                continue;
            }
            if ($el->nodeName === 'select') {
                $opts = $xpath->query('.//option', $el);
                $picked = null;
                foreach ($opts as $o) {
                    if ($o->hasAttribute('selected')) {
                        $picked = $o;
                    }
                }
                $picked ??= $opts->item(0);
                $value = $picked ? ($picked->hasAttribute('value') ? $picked->getAttribute('value') : $picked->textContent) : '';
            } elseif ($el->nodeName === 'textarea') {
                $value = $el->textContent;
            } else {
                $type = strtolower($el->getAttribute('type') ?: 'text');
                if (in_array($type, ['radio', 'checkbox'], true) && ! $el->hasAttribute('checked')) {
                    continue;
                }
                if (in_array($type, ['submit', 'button', 'file', 'image', 'reset'], true)) {
                    continue;
                }
                $value = $el->hasAttribute('value') ? $el->getAttribute('value') : ($type === 'checkbox' || $type === 'radio' ? 'on' : '');
            }
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
        parse_str(implode('&', $pairs), $out);

        return $out;
    }

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
