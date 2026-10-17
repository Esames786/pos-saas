<?php

namespace Tests\MySql;

use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\ModifierGroup;
use App\Models\Tenant\Product;
use App\Models\Tenant\User;
use App\Services\OnlineOrdering\WebImageService;
use App\Services\OnlineOrdering\WebMenuService;
use App\Services\Permissions\PermissionSyncService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * ONLINE-ORDERING-1 (Phase 2a) — website ka menu MOJOODA catalog se. Malik ki do shartein yahan
 * guard hain: koi alag menu/product/category nahi, aur "bas mera POS kharab na ho" — website ka koi
 * khaana POS ka naam, qeemat, tasveer ya category nahi chhuta.
 */
class OnlineOrderingMenuMySqlTest extends MySqlTenantTestCase
{
    use \Tests\MySql\Support\ParsesInlineScripts;

    private string $host;

    private int $tenantId;

    private int $ownerId;

    private int $branchA;

    private int $branchB;

    private const PERMS = [
        'tenant.online-ordering.menu.index', 'tenant.dashboard',
        'tenant.products.create', 'tenant.products.store', 'tenant.products.edit', 'tenant.products.update', 'tenant.products.index',
        'tenant.combos.create', 'tenant.combos.store', 'tenant.combos.edit', 'tenant.combos.update', 'tenant.combos.index',
        'tenant.categories.create', 'tenant.categories.store', 'tenant.categories.edit', 'tenant.categories.update', 'tenant.categories.index',
        'tenant.modifier-groups.create', 'tenant.modifier-groups.store', 'tenant.modifier-groups.edit', 'tenant.modifier-groups.update', 'tenant.modifier-groups.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = 'oomenu.'.config('tenancy.tenant_base_domain');
        Storage::fake('public');

        $this->seedMaster();
        $this->seedPlan(withModule: true);
        app(PermissionSyncService::class)->syncRouteCatalog();
        $this->bootTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('id', $this->tenantId)->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    // ── the menu itself ──────────────────────────────────────────────────────────────────────

    public function test_sizes_with_one_card_name_become_one_card_in_the_website_category(): void
    {
        $biryani = $this->category('Chicken Biryani', ['show_on_website' => true, 'website_name' => 'Biryani', 'website_sort_order' => 20]);
        $extras = $this->category('Extras', ['show_on_website' => true, 'website_sort_order' => 30]);
        $hidden = $this->category('Kitchen Only');

        $sadi = $this->product('Chicken Biryani (Sadi)', 250, $biryani, ['show_on_website' => true, 'website_card_name' => 'Chicken Biryani', 'website_size_label' => 'Plain', 'website_sort_order' => 1]);
        $this->product('Chicken Biryani (Large)', 750, $biryani, ['show_on_website' => true, 'website_card_name' => 'Chicken Biryani', 'website_size_label' => 'Large', 'website_sort_order' => 2, 'website_price' => 700]);
        // POS me "Kitchen Only" category, website par Biryani ke andar.
        $this->product('Raita', 100, $hidden, ['show_on_website' => true, 'website_category_id' => $biryani->id]);
        $this->product('Extra Chicken Pcs', 200, $extras);   // website par nahi

        $menu = app(WebMenuService::class)->menu($this->branchA, '13:00');
        $this->assertSame(['Biryani'], array_column($menu, 'name'), 'Khaali category website par nahi aati.');

        $cards = collect($menu[0]['items'])->keyBy('name');
        $this->assertSame(['Chicken Biryani', 'Raita'], $cards->keys()->sort()->values()->all());
        $card = $cards['Chicken Biryani'];
        $this->assertSame(['Plain', 'Large'], array_column($card['options'], 'label'));
        $this->assertSame([250.0, 700.0], array_column($card['options'], 'price'), 'Website price jeetti hai.');
        $this->assertSame([250.0, 750.0], array_column($card['options'], 'pos_price'));
        $this->assertSame(250.0, $card['from_price']);

        // POS ki qeemat aur category nahi badli.
        $this->assertSame('250.00', Product::find($sadi->id)->default_selling_price);

        $not = app(WebMenuService::class)->notOnWebsite()->pluck('name')->all();
        $this->assertContains('Extra Chicken Pcs', $not);
        $this->assertNotContains('Raita', $not);
    }

    public function test_a_size_not_sold_at_the_branch_or_a_deal_of_another_branch_is_left_out(): void
    {
        $cat = $this->category('Deals', ['show_on_website' => true]);
        $p = $this->product('Zinger Burger', 600, $cat, ['show_on_website' => true]);
        DB::connection('tenant')->table('product_branch_prices')->insert([
            'branch_id' => $this->branchA, 'product_id' => $p->id, 'selling_price' => 650, 'is_available' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Combo::create(['name' => 'Johar Deal', 'price' => 900, 'status' => 'active', 'branch_id' => $this->branchB,
            'category_id' => $cat->id, 'show_on_website' => true]);
        Combo::create(['name' => 'Family Deal', 'price' => 2500, 'status' => 'active', 'category_id' => $cat->id, 'show_on_website' => true]);

        $names = collect(app(WebMenuService::class)->menu($this->branchA, '13:00')[0]['items'])->pluck('name')->all();
        $this->assertSame(['Family Deal'], $names);

        // Preview me dikhta hai, "bikta nahi" ke saath — taake malik ko pata chale.
        $preview = collect(app(WebMenuService::class)->menu($this->branchA, null, preview: true)[0]['items'])->keyBy('name');
        $this->assertFalse($preview['Zinger Burger']['options'][0]['available']);
        $this->assertSame(650.0, $preview['Zinger Burger']['options'][0]['pos_price'], 'Branch ki POS qeemat.');
    }

    public function test_a_category_with_a_time_window_shows_only_inside_it(): void
    {
        $mid = $this->category('Midnight', ['show_on_website' => true, 'website_visible_from' => '00:00', 'website_visible_to' => '04:00']);
        $this->product('Zinger (Midnight)', 600, $mid, ['show_on_website' => true]);

        $this->assertSame([], app(WebMenuService::class)->menu($this->branchA, '13:00'));
        $this->assertCount(1, app(WebMenuService::class)->menu($this->branchA, '01:30'));
        $this->assertSame([], app(WebMenuService::class)->menu($this->branchA, '04:00'));
    }

    public function test_only_website_and_both_add_on_groups_reach_the_website(): void
    {
        $cat = $this->category('Burgers', ['show_on_website' => true]);
        $p = $this->product('Zinger Burger', 600, $cat, ['show_on_website' => true]);
        foreach (['pos' => 'Steak Side', 'website' => 'Select Your Drink', 'both' => 'Extra Cheese'] as $channel => $name) {
            $g = ModifierGroup::create(['name' => $name, 'min_select' => 0, 'max_select' => 1, 'is_required' => false, 'sort_order' => 0, 'status' => 'active', 'channel' => $channel]);
            $g->modifiers()->create(['name' => $name.' A', 'price_delta' => $channel === 'both' ? 100 : 0, 'sort_order' => 0, 'status' => 'active']);
            $p->modifierGroups()->attach($g->id, ['sort_order' => 0]);
        }

        $addons = app(WebMenuService::class)->menu($this->branchA, '13:00')[0]['items'][0]['options'][0]['addons'];
        $this->assertSame(['Extra Cheese', 'Select Your Drink'], collect($addons)->pluck('name')->sort()->values()->all());
        $this->assertSame(100.0, collect($addons)->firstWhere('name', 'Extra Cheese')['options'][0]['price']);
    }

    // ── existing screens, Website section ────────────────────────────────────────────────────

    public function test_the_product_form_saves_website_fields_and_never_touches_the_pos_photo(): void
    {
        $cat = $this->category('Starter');
        $html = $this->owner()->get($this->url('/products/create'))->assertOk()->getContent();
        $this->assertStringContainsString('name="show_on_website"', $html);
        $this->assertStringContainsString('name="website_image"', $html);

        $this->owner()->post($this->url('/products'), $this->productInput($cat->id) + [
            '_website' => '1', 'show_on_website' => '1', 'website_card_name' => 'Hot Wings', 'website_size_label' => '8 Pcs',
            'website_tag' => 'popular', 'website_popular' => '1', 'website_sort_order' => '5', 'website_description' => '8 pcs spicy wings',
            'website_image' => UploadedFile::fake()->image('wings.jpg', 1200, 900),
        ])->assertSessionHasNoErrors();

        $p = Product::where('sku', 'WINGS-8')->sole();
        $this->assertTrue($p->show_on_website);
        $this->assertSame('Hot Wings', $p->website_card_name);
        $this->assertSame('popular', $p->website_tag);
        $this->assertTrue($p->website_popular);
        $this->assertNull($p->image_path, 'POS tile ki tasveer website ki tasveer se nahi bharti.');
        $this->assertNotNull($p->website_image_path);
        Storage::disk('public')->assertExists($p->website_image_path);
        Storage::disk('public')->assertExists(WebImageService::thumb($p->website_image_path));
        [$w] = getimagesizefromstring(Storage::disk('public')->get($p->website_image_path));
        $this->assertSame(800, $w, 'Bari tasveer 800px tak chhoti.');

        // Website hissa na aaye (purana form / module band) to website ke khaane nahi chhute.
        $this->owner()->put($this->url('/products/'.$p->id), $this->productInput($cat->id, ['name' => 'Chicken Hot Wings 8 Pcs']))
            ->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertTrue($p->show_on_website);
        $this->assertSame('Hot Wings', $p->website_card_name);
        $this->assertSame('Chicken Hot Wings 8 Pcs', $p->name);

        // Malik (15 Oct): "Size on card" dropdown — chalte sizes (yahan "8 Pcs") + aam sizes, aur "New size…".
        $html = $this->owner()->get($this->url('/products/'.$p->id.'/edit'))->assertOk()->getContent();
        $this->assertStringContainsString('id="website_size_pick"', $html);
        $this->assertMatchesRegularExpression('#<option value="8 Pcs"\s+selected#', $html, 'Is product ka size chuna hua.');
        $this->assertStringContainsString('<option value="Large"', $html);
        $this->assertStringContainsString('value="__new"', $html);
        $this->assertStringContainsString('name="website_size_label"', $html, 'Form wohi naam bhejta hai — save waisa hi.');
        $this->assertInlineScriptsParse($html, 'product form with size dropdown', 'website_size_pick');
    }

    public function test_the_product_form_shows_website_choices_in_plain_words_and_keeps_pos_groups(): void
    {
        // Malik (16 Oct): "White / Brown kahan use ho raha hai — human friendly nahi". Ab product ke Website card me.
        $cat = $this->category('Steaks');
        $this->owner()->post($this->url('/products'), $this->productInput($cat->id))->assertSessionHasNoErrors();
        $p = Product::where('sku', 'WINGS-8')->sole();
        $sauce = ModifierGroup::create(['name' => 'Select Your Preference', 'min_select' => 1, 'max_select' => 1, 'is_required' => true, 'status' => 'active', 'channel' => 'website', 'sort_order' => 0]);
        $sauce->modifiers()->create(['name' => 'White', 'price_delta' => 0, 'sort_order' => 0, 'status' => 'active']);
        $sauce->modifiers()->create(['name' => 'Brown', 'price_delta' => 0, 'sort_order' => 1, 'status' => 'active']);
        $fries = ModifierGroup::create(['name' => 'Add Ons', 'min_select' => 0, 'max_select' => 2, 'is_required' => false, 'status' => 'active', 'channel' => 'website', 'sort_order' => 0]);
        $fries->modifiers()->create(['name' => 'Add Fries', 'price_delta' => 150, 'sort_order' => 0, 'status' => 'active']);
        $pos = ModifierGroup::create(['name' => 'Steak Side', 'min_select' => 0, 'max_select' => 2, 'is_required' => false, 'status' => 'active', 'channel' => 'pos', 'sort_order' => 0]);
        $p->modifierGroups()->sync([$sauce->id => ['sort_order' => 0], $pos->id => ['sort_order' => 1]]);

        $html = $this->owner()->get($this->url('/products/'.$p->id.'/edit'))->assertOk()->getContent();
        $this->assertStringContainsString('Choices the customer makes on the website', $html);
        $this->assertStringContainsString('Customer must choose 1', $html);
        $this->assertStringContainsString('White · Brown', $html);
        $this->assertStringContainsString('Add Fries (+Rs 150)', $html);
        $this->assertStringContainsString('Optional — customer may add up to 2', $html);
        $this->assertStringNotContainsString('Steak Side', $html, 'POS ka group yahan nahi — wo product ke modifier panel par.');
        $this->assertMatchesRegularExpression('#value="'.$sauce->id.'" id="wag-'.$sauce->id.'"\s+checked#', $html);

        // White/Brown hata kar Add Fries lagao — POS ka "Steak Side" waisa hi.
        $this->owner()->put($this->url('/products/'.$p->id), $this->productInput($cat->id) + [
            '_website' => '1', 'show_on_website' => '1', '_website_addons' => '1', 'website_addon_groups' => [$fries->id],
        ])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$pos->id, $fries->id], $p->fresh()->modifierGroups->pluck('id')->all());
    }

    public function test_without_the_module_no_website_section_appears_anywhere(): void
    {
        $this->seedPlan(withModule: false);
        $cat = $this->category('Starter');

        foreach (['/products/create', '/combos/create', '/categories/create', '/modifier-groups/create'] as $page) {
            $html = $this->owner()->get($this->url($page))->assertOk()->getContent();
            $this->assertStringNotContainsString('name="_website"', $html, $page);
            $this->assertStringNotContainsString('name="channel"', $html, $page);
        }
        $this->owner()->get($this->url('/online-ordering/menu'))->assertForbidden();
    }

    public function test_a_deal_takes_only_add_on_groups_meant_for_the_website(): void
    {
        $cat = $this->category('Deals', ['show_on_website' => true]);
        $part = $this->product('Garlic Rice', 0, $cat);
        $combo = Combo::create(['name' => 'Midnight Deal 17', 'price' => 1550, 'status' => 'active', 'category_id' => $cat->id]);
        $combo->components()->create(['product_id' => $part->id, 'quantity' => 1, 'sort_order' => 0]);
        $pos = ModifierGroup::create(['name' => 'Steak Side', 'status' => 'active', 'sort_order' => 0]);
        $web = ModifierGroup::create(['name' => 'Select Your Drink', 'status' => 'active', 'sort_order' => 0, 'channel' => 'website']);

        $html = $this->owner()->get($this->url('/combos/'.$combo->id.'/edit'))->assertOk()->getContent();
        $this->assertStringContainsString('value="'.$web->id.'"', $html);
        $this->assertStringNotContainsString('wag-'.$pos->id, $html, 'POS-only group deal ke website add-ons me nahi aata.');

        $this->owner()->put($this->url('/combos/'.$combo->id), [
            'name' => 'Midnight Deal 17', 'price' => '1550', 'status' => 'active', 'category_id' => $cat->id,
            'components' => [['product_id' => $part->id, 'quantity' => '1']],
            '_website' => '1', '_website_addons' => '1', 'show_on_website' => '1',
            'website_addon_groups' => [$web->id, $pos->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame([$web->id], $combo->fresh()->modifierGroups->pluck('id')->all());
        $this->assertTrue($combo->fresh()->show_on_website);
        $this->assertSame('1550.00', $combo->fresh()->price);
    }

    public function test_category_website_fields_and_both_times_or_none(): void
    {
        $this->owner()->post($this->url('/categories'), ['name' => 'Midnight', 'code' => '', 'sort_order' => '1', 'is_active' => '1',
            '_website' => '1', 'show_on_website' => '1', 'website_name' => 'Midnight Deals', 'website_visible_from' => '00:00'])
            ->assertSessionHasErrors('website_visible_to');

        $this->owner()->post($this->url('/categories'), ['name' => 'Midnight', 'code' => '', 'sort_order' => '1', 'is_active' => '1',
            '_website' => '1', 'show_on_website' => '1', 'website_name' => 'Midnight Deals',
            'website_visible_from' => '00:00', 'website_visible_to' => '04:00'])->assertSessionHasNoErrors();

        $c = Category::where('name', 'Midnight')->sole();
        $this->assertTrue($c->show_on_website);
        $this->assertSame('Midnight Deals', $c->website_name);
        $this->assertSame('00:00', substr((string) $c->website_visible_from, 0, 5));
        $this->assertTrue($c->websiteVisibleAt('01:00'));
        $this->assertFalse($c->websiteVisibleAt('12:00'));
    }

    public function test_a_modifier_group_keeps_its_channel_when_the_form_has_no_switch(): void
    {
        $this->owner()->post($this->url('/modifier-groups'), ['branch_id' => '', 'name' => 'Select Your Drink', 'status' => 'active', 'channel' => 'website',
            'modifiers' => [['name' => 'Pepsi', 'price_delta' => '0', 'linked_product_id' => '', 'status' => 'active']]])->assertRedirect()->assertSessionHasNoErrors();
        $g = ModifierGroup::where('name', 'Select Your Drink')->sole();
        $this->assertSame('website', $g->channel);

        // Module band — form par switch nahi; save channel ko 'pos' par wapas na le jaye.
        $this->seedPlan(withModule: false);
        $this->owner()->put($this->url('/modifier-groups/'.$g->id), ['branch_id' => '', 'name' => 'Select Your Drink', 'status' => 'active',
            'modifiers' => [['id' => $g->modifiers()->first()->id, 'name' => 'Pepsi', 'price_delta' => '0', 'linked_product_id' => '', 'status' => 'active']]])->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('website', $g->fresh()->channel);

        $this->owner()->post($this->url('/modifier-groups'), ['branch_id' => '', 'name' => 'Steak Side', 'status' => 'active'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pos', ModifierGroup::where('name', 'Steak Side')->sole()->channel, 'Naya group by default sirf POS ka.');
    }

    public function test_the_preview_page_shows_cards_and_what_is_not_on_the_website(): void
    {
        $cat = $this->category('Chicken Biryani', ['show_on_website' => true, 'website_name' => 'Biryani']);
        $this->product('Chicken Biryani (Large)', 750, $cat, ['show_on_website' => true, 'website_card_name' => 'Chicken Biryani', 'website_size_label' => 'Large', 'website_price' => 700]);
        $this->product('Sting Can', 160, $cat);

        $html = $this->owner()->get($this->url('/online-ordering/menu'))->assertOk()->getContent();
        $this->assertStringContainsString('Biryani', $html);
        $this->assertStringContainsString('Chicken Biryani (Large)', $html);
        $this->assertStringContainsString('Rs 700', $html);
        $this->assertStringContainsString('On the POS, not on the website', $html);
        $this->assertStringContainsString('Sting Can', $html);
    }

    public function test_the_menu_import_fills_only_website_fields_and_runs_twice_safely(): void
    {
        $biryaniCat = $this->category('Chicken Biryani');
        $bevCat = $this->category('Beverages');
        $sadi = $this->product('Chicken Biryani (Sadi)', 250, $biryaniCat, ['image_path' => 'products/pos-tile.jpg', 'description' => 'POS note']);
        $large = $this->product('Chicken Biryani (Large)', 750, $biryaniCat);
        $sting = $this->product('Soft Drink Sting (Can)', 160, $bevCat);

        $dir = sys_get_temp_dir().'/oomenu-img-'.uniqid();
        mkdir($dir);
        $img = imagecreatetruecolor(900, 900);
        imagewebp($img, $dir.'/1767-CHICKEN BIRYANI_11zon.webp');
        imagedestroy($img);

        $menu = ['details' => [
            '01' => ['title' => 'Popular Items', 'categoryDetail' => ['sequence' => -1], 'items' => ['a' => ['item_id' => '900', 'item_name' => 'Chicken Biryani', 'prices' => []]], 'sortedItemKeys' => ['a']],
            '10' => ['title' => 'Biryani', 'categoryDetail' => ['sequence' => 2], 'sortedItemKeys' => ['a'], 'items' => ['a' => [
                'item_id' => '900', 'item_name' => 'Chicken Biryani', 'item_description' => 'Fragrant rice', 'item_tag_name' => 'Popular',
                'photo' => 'https://assets.example/upload/1767-CHICKEN%20BIRYANI_11zon.webp?q=10',
                'prices' => [['size' => 'Plain', 'price' => 250], ['size' => 'Large', 'price' => 750]],
                'addon_item' => [['subcat_name' => 'Add Ons', 'require_addons' => 0, 'multi_option' => 'multiple',
                    'sub_item' => [['sub_item_name' => 'Extra Chicken Piece', 'price' => 200]]]],
            ]]],
            '11' => ['title' => 'Beverages', 'categoryDetail' => ['sequence' => 3], 'sortedItemKeys' => ['a'], 'items' => ['a' => [
                'item_id' => '901', 'item_name' => 'Sting Can', 'item_description' => '', 'item_tag_name' => '', 'photo' => '',
                'prices' => [['size' => null, 'price' => 180]], 'addon_item' => false,
            ]]],
        ]];
        $map = ['rows' => [
            ['cat' => 'Biryani', 'name' => 'Chicken Biryani', 'size' => 'Plain', 'pos' => ['kind' => 'product', 'name' => 'Chicken Biryani (Sadi)', 'category' => 'Chicken Biryani']],
            ['cat' => 'Biryani', 'name' => 'Chicken Biryani', 'size' => 'Large', 'pos' => ['kind' => 'product', 'name' => 'Chicken Biryani (Large)', 'category' => 'Chicken Biryani']],
            ['cat' => 'Beverages', 'name' => 'Sting Can', 'size' => '', 'pos' => ['kind' => 'product', 'name' => 'Soft Drink Sting (Can)', 'category' => 'Beverages']],
        ]];
        file_put_contents($menuFile = $dir.'/menu.json', json_encode($menu));
        file_put_contents($mapFile = $dir.'/map.json', json_encode($map));
        $args = ['tenant_code' => 'oomenu', 'menu' => $menuFile, '--map' => $mapFile, '--images' => $dir];

        $this->artisan('online-ordering:import-menu', $args)->expectsOutputToContain('Ye sirf dikhaya gaya hai')->assertSuccessful();
        $this->assertFalse($sadi->fresh()->show_on_website, 'Dry run ne likh diya.');
        $this->assertSame(0, ModifierGroup::count());

        $this->artisan('online-ordering:import-menu', $args + ['--yes' => true])->assertSuccessful();
        $this->artisan('online-ordering:import-menu', $args + ['--yes' => true])->assertSuccessful();   // dobara: kuch dugna nahi

        $sadi->refresh();
        $large->refresh();
        $this->assertTrue($sadi->show_on_website);
        $this->assertSame('Chicken Biryani', $sadi->website_card_name);
        $this->assertSame('Plain', $sadi->website_size_label);
        $this->assertSame('popular', $sadi->website_tag);
        $this->assertTrue($sadi->website_popular);
        $this->assertSame('Fragrant rice', $sadi->website_description);
        $this->assertNotNull($sadi->website_image_path);
        $this->assertSame($sadi->website_image_path, $large->website_image_path, 'Card ki aik tasveer, sab sizes par.');

        // POS ke khaane bilkul waise.
        $this->assertSame('Chicken Biryani (Sadi)', $sadi->name);
        $this->assertSame('250.00', $sadi->default_selling_price);
        $this->assertSame('products/pos-tile.jpg', $sadi->image_path);
        $this->assertSame('POS note', $sadi->description);
        $this->assertSame($biryaniCat->id, $sadi->category_id);
        $this->assertNull($sadi->website_price, 'Qeemat barabar ho to website price nahi likhi jati.');
        $this->assertSame('180.00', $sting->fresh()->website_price);
        $this->assertSame('160.00', $sting->fresh()->default_selling_price);

        $cat = $biryaniCat->fresh();
        $this->assertTrue($cat->show_on_website);
        $this->assertSame('Biryani', $cat->website_name);

        $this->assertSame(1, ModifierGroup::count(), 'Dobara chalane par naya group nahi bana.');
        $group = ModifierGroup::sole();
        $this->assertSame('website', $group->channel, 'Import ka add-on POS par kabhi nahi.');
        $this->assertSame('200.00', number_format((float) $group->modifiers()->sole()->price_delta, 2, '.', ''));
        $this->assertSame(2, DB::connection('tenant')->table('product_modifier_group')->where('modifier_group_id', $group->id)->count());

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function test_a_website_section_with_no_pos_category_still_comes_across(): void
    {
        // Malik: "deals waghera missing" — import un sections ko chhoR deta tha jin ki POS me category nahi.
        $deal = Combo::create(['name' => 'Deal 1 (Serve 1)', 'price' => 875, 'status' => 'active']);   // POS par koi category nahi
        $menu = ['details' => [
            '12' => ['title' => 'Family Deals', 'categoryDetail' => ['sequence' => 4], 'sortedItemKeys' => ['a'], 'items' => ['a' => [
                'item_id' => '950', 'item_name' => 'Family Deal 01', 'item_description' => '', 'item_tag_name' => '', 'photo' => '',
                'prices' => [['size' => null, 'price' => 875]], 'addon_item' => false,
            ]]],
        ]];
        $map = ['rows' => [['cat' => 'Family Deals', 'name' => 'Family Deal 01', 'size' => '', 'pos' => ['kind' => 'combo', 'name' => 'Deal 1 (Serve 1)', 'category' => 'Deals']]]];
        $dir = sys_get_temp_dir().'/oomenu-deal-'.uniqid();
        mkdir($dir);
        file_put_contents($menuFile = $dir.'/menu.json', json_encode($menu));
        file_put_contents($mapFile = $dir.'/map.json', json_encode($map));
        $args = ['tenant_code' => 'oomenu', 'menu' => $menuFile, '--map' => $mapFile];

        $this->artisan('online-ordering:import-menu', $args)->expectsOutputToContain('nayi category')->assertSuccessful();
        $this->assertSame(0, Category::where('name', 'Family Deals')->count(), 'Dry run ne category bana di.');

        $this->artisan('online-ordering:import-menu', $args + ['--yes' => true])->assertSuccessful();
        $this->artisan('online-ordering:import-menu', $args + ['--yes' => true])->assertSuccessful();   // dobara: aik hi category

        $cat = Category::where('name', 'Family Deals')->sole();
        $this->assertTrue($cat->show_on_website);
        $deal->refresh();
        $this->assertTrue($deal->show_on_website);
        $this->assertSame($cat->id, (int) $deal->website_category_id);
        $this->assertNull($deal->category_id, 'POS par deal ki category waisi hi (koi naya POS tab nahi).');

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function owner()
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant');
    }

    private function url(string $path): string
    {
        return 'http://'.$this->host.$path;
    }

    private function category(string $name, array $attrs = []): Category
    {
        return Category::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(), 'sort_order' => 0, 'is_active' => true] + $attrs);
    }

    private function product(string $name, float $price, Category $cat, array $attrs = []): Product
    {
        return Product::create([
            'sku' => strtoupper(\Illuminate\Support\Str::slug($name)).'-'.uniqid(), 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
            'category_id' => $cat->id, 'product_type' => 'simple', 'product_kind' => 'sale_item',
            'default_selling_price' => $price, 'is_sellable' => true, 'is_pos_visible' => true, 'status' => 'active',
        ] + $attrs);
    }

    private function productInput(int $categoryId, array $over = []): array
    {
        return array_replace([
            'sku' => 'WINGS-8', 'name' => 'Chicken Hot Wings', 'product_type' => 'simple', 'product_kind' => 'sale_item',
            'category_id' => $categoryId, 'default_selling_price' => '990', 'status' => 'active',
            'is_sellable' => '1', 'is_pos_visible' => '1',
        ], $over);
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection('master');
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $old = $m->table('tenants')->where('tenant_code', 'oomenu')->value('id');
        if ($old) {
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'oomenu', 'business_name' => 'Online Ordering Menu', 'owner_name' => 'Owner',
            'owner_email' => 'owner@oomenu.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'), 'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb, 'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedPlan(bool $withModule): void
    {
        $m = DB::connection('master');
        $planId = $m->table('plans')->where('code', 'oomenu-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'oomenu-plan', 'name' => 'Online Ordering Menu', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->get(['id', 'key']) as $module) {
            if ($module->key === 'online_ordering' && ! $withModule) {
                continue;
            }
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $module->id, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert(['tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
        if (app()->bound('tenant')) {
            app()->forgetInstance('tenant');
        }
    }

    private function bootTenant(): void
    {
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'combo_modifier_group', 'product_modifier_group', 'modifiers', 'modifier_groups',
            'combo_components', 'combos', 'product_branch_prices', 'product_translations', 'product_variants', 'products',
            'categories', 'model_has_roles', 'users', 'branches',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $c = DB::connection('tenant');
        $this->branchA = $c->table('branches')->insertGetId(['name' => 'Gulshan', 'code' => 'GUL', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->branchB = $c->table('branches')->insertGetId(['name' => 'Johar', 'code' => 'JOH', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        foreach (self::PERMS as $name) {
            $c->table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]);
        }
        $owner = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $pid) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $pid, 'role_id' => $owner], []);
        }
        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'Menu Owner', 'email' => 'owner@oomenu.local', 'password' => bcrypt('x'), 'employee_code' => 'OMOWN',
            'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $owner, 'model_type' => User::class, 'model_id' => $this->ownerId]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
