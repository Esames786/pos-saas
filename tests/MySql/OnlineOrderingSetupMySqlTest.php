<?php

namespace Tests\MySql;

use App\Models\Master\GeoArea;
use App\Models\Tenant\Combo;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\Product;
use App\Models\Tenant\User;
use App\Models\Tenant\WebOrderRoute;
use App\Models\Tenant\WebSetting;
use App\Services\Permissions\PermissionSyncService;
use App\Services\Saas\TenantSubscriptionAccessService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\ParsesInlineScripts;

/**
 * ONLINE-ORDERING-1 (Phase 1) — website settings, delivery areas, website price. Asli raaste se:
 * domain → module gate → permission → controller → Blade.
 *
 * Jo yahan toote ga wo seedha customer tak jata hai: ghalat fee, bina ghanti ke order, ya plan ke
 * baghair kisi aur tenant par khul jane wali website.
 */
class OnlineOrderingSetupMySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;

    private string $host;

    private int $tenantId;

    private int $ownerId;

    private int $branchA;

    private int $branchB;

    private int $t1;

    private int $t2;

    private int $t3;

    private const PERMS = [
        'tenant.online-ordering.settings.edit', 'tenant.online-ordering.settings.update',
        'tenant.online-ordering.areas.index', 'tenant.online-ordering.areas.update',
        'tenant.products.create', 'tenant.products.store', 'tenant.products.edit', 'tenant.products.update',
        'tenant.products.index', 'tenant.combos.edit', 'tenant.combos.update', 'tenant.dashboard',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = 'oosetup.'.config('tenancy.tenant_base_domain');

        $this->seedMaster();
        $this->seedPlan(withModule: true);
        // Module gate `route_catalogs` se chalta hai; nayi routes wahan na hon to gate "unmapped =
        // allow" kehta hai aur "band" wala test galat wajah se hara ho jata.
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

    // ── module gate ──────────────────────────────────────────────────────────────────────────

    public function test_without_the_module_the_screens_are_closed_and_the_menu_is_hidden(): void
    {
        $this->seedPlan(withModule: false);

        $check = app(TenantSubscriptionAccessService::class)
            ->check(\App\Models\Master\Tenant::find($this->tenantId)->fresh(), 'tenant.online-ordering.settings.edit');
        $this->assertFalse($check['allowed']);
        $this->assertSame('tenant.online-ordering', $check['module_key'] ?? null);

        $this->owner()->get($this->url('/online-ordering/settings'))->assertForbidden();
        $this->owner()->put($this->url('/online-ordering/areas'), ['city' => 'Karachi'])->assertForbidden();

        // Owner ke paas permission hai (deploy sab deta hai) — menu phir bhi plan se chhupta hai.
        $html = $this->owner()->get($this->url('/products'))->assertOk()->getContent();
        $this->assertStringNotContainsString('/online-ordering/settings', $html);
    }

    public function test_with_the_module_the_owner_sees_the_menu_and_the_settings_page(): void
    {
        $html = $this->owner()->get($this->url('/products'))->assertOk()->getContent();
        $this->assertStringContainsString('/online-ordering/settings', $html);

        $html = $this->owner()->get($this->url('/online-ordering/settings'))->assertOk()->getContent();
        $this->assertStringContainsString('Where website orders arrive', $html);
        $this->assertStringContainsString('Counter One', $html);
        $this->assertStringContainsString('None ticked = the same terminals as Delivery.', $html);
    }

    public function test_a_deploy_never_switches_the_module_on_or_off(): void
    {
        // deploy.sh [3b] har dafa MasterSeeder chalata hai. Module kisi plan par khud na lage
        // (enterprise "saare modules" leta hai), aur jo plan par diya gaya wo agle deploy par na chhine
        // — purana quick_sale/standard loop pehle har module ka flag dobara likhta tha.
        DB::setDefaultConnection('master');
        (new \Database\Seeders\MasterSeeder)->run();

        $m = DB::connection('master');
        $moduleId = (int) $m->table('modules')->where('key', 'online_ordering')->value('id');
        $this->assertGreaterThan(0, $moduleId);
        $this->assertSame(0, $m->table('plan_modules')->where('module_id', $moduleId)->where('is_enabled', 1)
            ->whereIn('plan_id', $m->table('plans')->whereIn('code', ['enterprise', 'standard', 'quick_sale', 'restaurant_pro'])->pluck('id'))
            ->count(), 'Deploy ne module kisi plan par khud laga diya.');

        $standard = (int) $m->table('plans')->where('code', 'standard')->value('id');
        $m->table('plan_modules')->updateOrInsert(['plan_id' => $standard, 'module_id' => $moduleId], ['is_enabled' => 1]);

        (new \Database\Seeders\MasterSeeder)->run();

        $this->assertSame(1, (int) $m->table('plan_modules')->where('plan_id', $standard)->where('module_id', $moduleId)->value('is_enabled'),
            'Plan administration ka diya hua module deploy ne chheen liya.');

        $m->table('plan_modules')->where('plan_id', $standard)->where('module_id', $moduleId)->delete();
        DB::setDefaultConnection('tenant');
    }

    // ── website settings ─────────────────────────────────────────────────────────────────────

    public function test_settings_save_hours_routes_and_unticked_toggles_as_off(): void
    {
        $this->owner()->put($this->url('/online-ordering/settings'), $this->settingsInput([
            'is_enabled' => '1',
            'wa_notify_received' => '1',
            // confirmed aur cancelled ann-tick: kuch post nahi hota.
            'routes' => [
                $this->branchA => [
                    // Malik: multi-select — ghanti dono par, draft pehle khule par.
                    'delivery' => ['terminal_ids' => [$this->t1, $this->t2]],
                    'takeaway' => ['terminal_ids' => ['']],
                ],
            ],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $s = WebSetting::tenantDefault()->fresh();
        $this->assertTrue($s->is_enabled);
        $this->assertTrue($s->wa_notify_received);
        $this->assertFalse($s->wa_notify_confirmed, 'Ann-tick checkbox = band.');
        $this->assertFalse($s->wa_notify_cancelled);
        $this->assertNull($s->auto_cancel_minutes, 'Auto-cancel off = kabhi khud cancel nahi.');
        $this->assertSame(['open' => '12:00', 'close' => '04:00', 'closed' => false], $s->hours['fri']);

        $delivery = WebOrderRoute::where('branch_id', $this->branchA)->where('order_type', 'delivery')->sole();
        $this->assertSame([$this->t1, $this->t2], $delivery->ids());
        $this->assertSame($this->t1, $delivery->terminal_id, 'Purane khane bhi bhare (jo unhe parhe).');
        $this->assertSame($this->t2, $delivery->backup_terminal_id);
        $this->assertSame([], WebOrderRoute::where('branch_id', $this->branchA)->where('order_type', 'takeaway')->sole()->ids(), 'Pick-Up khaali = Delivery wale.');

        // Auto-cancel on: minute ke saath.
        $this->owner()->put($this->url('/online-ordering/settings'), $this->settingsInput([
            'auto_cancel_enabled' => '1', 'auto_cancel_minutes' => '20',
        ]))->assertSessionHasNoErrors();
        $this->assertSame(20, WebSetting::tenantDefault()->fresh()->auto_cancel_minutes);
    }

    public function test_the_website_cannot_be_switched_on_without_a_delivery_terminal(): void
    {
        // Bina terminal ke website kholna = order aaye aur kahin ghanti na baje.
        $this->owner()->put($this->url('/online-ordering/settings'), $this->settingsInput(['is_enabled' => '1']))
            ->assertSessionHasErrors('is_enabled');

        $this->assertFalse(WebSetting::tenantDefault()->fresh()->is_enabled);
    }

    public function test_a_terminal_of_another_branch_is_refused(): void
    {
        $this->owner()->put($this->url('/online-ordering/settings'), $this->settingsInput([
            'routes' => [$this->branchA => ['delivery' => ['terminal_ids' => [$this->t1, $this->t3]]]],
        ]))->assertSessionHasErrors("routes.{$this->branchA}.delivery.terminal_ids");

        $this->assertSame(0, WebOrderRoute::count());
    }

    public function test_an_open_day_needs_both_times(): void
    {
        $input = $this->settingsInput();
        $input['hours']['tue'] = ['open' => '12:00', 'close' => ''];

        $this->owner()->put($this->url('/online-ordering/settings'), $input)->assertSessionHasErrors('hours.tue.open');
    }

    // ── delivery areas ───────────────────────────────────────────────────────────────────────

    public function test_the_areas_page_lists_the_master_list_and_saves_fees(): void
    {
        $html = $this->owner()->get($this->url('/online-ordering/areas'))->assertOk()->getContent();
        $this->assertStringContainsString('Gulshan e Iqbal - Block 2', $html);
        $this->assertSame(
            GeoArea::where('city', 'Karachi')->where('is_active', true)->count(),
            substr_count($html, '[serve]'),
            'Har master area ki aik row.'
        );
        $this->assertInlineScriptsParse($html, 'delivery areas', 'serve-all');

        [$gulshan, $scheme] = [$this->area('Gulshan e Iqbal - Block 2'), $this->area('Scheme 33')];

        $this->owner()->put($this->url('/online-ordering/areas'), ['city' => 'Karachi', 'zones' => [
            $gulshan => ['serve' => '1', 'branch_id' => $this->branchA, 'fee' => '50', 'eta_minutes' => '45'],
            $scheme => ['serve' => '1', 'branch_id' => $this->branchB, 'fee' => '300', 'min_order' => '1000', 'free_delivery_above' => ''],
        ]])->assertRedirect()->assertSessionHasNoErrors();

        $z = DeliveryZone::where('geo_area_id', $scheme)->sole();
        $this->assertSame('Scheme 33', $z->area_name);
        $this->assertSame($this->branchB, $z->branch_id);
        $this->assertSame('300.00', $z->fee);
        $this->assertSame('1000.00', $z->min_order);
        $this->assertNull($z->free_delivery_above);
        $this->assertNull($z->eta_minutes, 'Khaali = settings ka default.');

        // Tick hataya: band, fee yaad.
        $this->owner()->put($this->url('/online-ordering/areas'), ['city' => 'Karachi', 'zones' => [
            $scheme => ['branch_id' => $this->branchB, 'fee' => '300'],
        ]])->assertSessionHasNoErrors();

        $z->refresh();
        $this->assertFalse($z->is_active);
        $this->assertSame('300.00', $z->fee);
        $this->assertTrue(DeliveryZone::where('geo_area_id', $gulshan)->sole()->is_active, 'Jo post hi nahi hua wo nahi chhua.');
    }

    public function test_one_branch_mode_puts_every_area_on_the_website_branch(): void
    {
        WebSetting::tenantDefault()->update(['branch_mode' => 'single', 'default_branch_id' => $this->branchB]);

        $html = $this->owner()->get($this->url('/online-ordering/areas'))->assertOk()->getContent();
        $this->assertStringNotContainsString('[branch_id]', $html);

        $this->owner()->put($this->url('/online-ordering/areas'), ['city' => 'Karachi', 'zones' => [
            $this->area('Saddar') => ['serve' => '1', 'branch_id' => $this->branchA, 'fee' => '200'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame($this->branchB, DeliveryZone::sole()->branch_id);
    }

    public function test_a_made_up_area_or_an_inactive_branch_is_refused(): void
    {
        $this->owner()->put($this->url('/online-ordering/areas'), ['city' => 'Karachi', 'zones' => [
            99999999 => ['serve' => '1', 'branch_id' => $this->branchA, 'fee' => '1'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(0, DeliveryZone::count(), 'Master me na ho to kuch nahi banta.');

        $this->owner()->put($this->url('/online-ordering/areas'), ['city' => 'Karachi', 'zones' => [
            $this->area('Saddar') => ['serve' => '1', 'branch_id' => 999999, 'fee' => '1'],
        ]])->assertSessionHasErrors();
        $this->assertSame(0, DeliveryZone::count());
    }

    public function test_the_area_import_is_a_dry_run_until_told_and_keeps_the_first_of_a_duplicate(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'areas');
        file_put_contents($file, json_encode(['details' => ['areas' => ['1' => [
            ['area_name' => 'Gulshan e Iqbal - Block 2', 'city_name' => 'Karachi', 'fee' => '50.000', 'minimum' => '0', 'min_for_free_delivery' => '0', 'delivery_estimation' => '0'],
            ['area_name' => 'FB Area  Block 7', 'city_name' => 'Karachi', 'fee' => '200.000', 'minimum' => '500', 'min_for_free_delivery' => '0', 'delivery_estimation' => '50'],
            ['area_name' => 'FB Area Block 7', 'city_name' => 'Karachi', 'fee' => '250.000', 'minimum' => '0', 'min_for_free_delivery' => '0', 'delivery_estimation' => '0'],
            ['area_name' => 'Nowhere Town', 'city_name' => 'Karachi', 'fee' => '10', 'minimum' => '0', 'min_for_free_delivery' => '0', 'delivery_estimation' => '0'],
        ]]]]));

        $this->artisan('online-ordering:import-areas', ['tenant_code' => 'oosetup', 'file' => $file, '--branch' => 'GUL'])
            ->expectsOutputToContain('FB Area Block 7: 200 rakha, 250 chhoRa')
            ->expectsOutputToContain('Master list me nahi (chhoRa): Karachi / Nowhere Town')
            ->assertSuccessful();
        $this->assertSame(0, DeliveryZone::count(), 'Dry run ne likh diya.');

        $this->artisan('online-ordering:import-areas', ['tenant_code' => 'oosetup', 'file' => $file, '--branch' => 'GUL', '--yes' => true])
            ->assertSuccessful();
        @unlink($file);

        $this->assertSame(2, DeliveryZone::count());
        $fb = DeliveryZone::where('geo_area_id', $this->area('FB Area Block 7'))->sole();
        $this->assertSame('200.00', $fb->fee);
        $this->assertSame('500.00', $fb->min_order);
        $this->assertSame(50, $fb->eta_minutes);
        $this->assertSame($this->branchA, $fb->branch_id);
        $this->assertNull(DeliveryZone::where('geo_area_id', $this->area('Gulshan e Iqbal - Block 2'))->sole()->min_order, '0 = koi minimum nahi.');
    }

    // ── website price ────────────────────────────────────────────────────────────────────────

    public function test_website_price_is_offered_only_on_a_plan_with_the_module(): void
    {
        $html = $this->owner()->get($this->url('/products/create'))->assertOk()->getContent();
        $this->assertStringContainsString('name="website_price"', $html);
        $this->assertInlineScriptsParse($html, 'product form', 'var MODES');

        $this->seedPlan(withModule: false);
        $html = $this->owner()->get($this->url('/products/create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="website_price"', $html);
    }

    public function test_website_price_saves_and_a_form_without_the_field_never_wipes_it(): void
    {
        $this->owner()->post($this->url('/products'), $this->productInput(['website_price' => '1600']))
            ->assertSessionHasNoErrors();
        $p = Product::where('sku', 'ALFAHAM-H')->sole();
        $this->assertSame('1600.00', $p->website_price);
        $this->assertSame('1650.00', $p->default_selling_price, 'POS ki qeemat nahi chhuti.');

        // Plan se module hata — form par khaana nahi, save purani website price ko na mitaye.
        $this->seedPlan(withModule: false);
        $this->owner()->put($this->url('/products/'.$p->id), $this->productInput(['name' => 'Al-Faham Half']))
            ->assertSessionHasNoErrors();
        $this->assertSame('1600.00', $p->fresh()->website_price);

        // Khaana khaali bheja = website par POS ki qeemat.
        $this->seedPlan(withModule: true);
        $this->owner()->put($this->url('/products/'.$p->id), $this->productInput(['website_price' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($p->fresh()->website_price);
    }

    public function test_a_deal_keeps_its_own_website_price(): void
    {
        $combo = Combo::create(['name' => 'Midnight Deal', 'price' => 1550, 'status' => 'active']);
        $product = $this->product();
        $combo->components()->create(['product_id' => $product->id, 'quantity' => 1, 'sort_order' => 0]);

        $html = $this->owner()->get($this->url('/combos/'.$combo->id.'/edit'))->assertOk()->getContent();
        $this->assertStringContainsString('name="website_price"', $html);

        $input = ['name' => 'Midnight Deal', 'price' => '1550', 'status' => 'active',
            'components' => [['product_id' => $product->id, 'quantity' => '1']]];

        $this->owner()->put($this->url('/combos/'.$combo->id), $input + ['website_price' => '1500'])->assertSessionHasNoErrors();
        $this->assertSame('1500.00', $combo->fresh()->website_price);
        $this->assertSame('1550.00', $combo->fresh()->price);

        $this->owner()->put($this->url('/combos/'.$combo->id), $input)->assertSessionHasNoErrors();
        $this->assertSame('1500.00', $combo->fresh()->website_price, 'Khaana na aaye to purani qeemat salamat.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    public function test_the_website_look_saves_logo_banners_and_footer_without_touching_the_other_settings(): void
    {
        // Malik: purani website jaisi — logo, banners, WhatsApp, footer, cart ka "Popular with your order".
        Storage::fake('public');
        WebSetting::tenantDefault()->update(['contact_phone' => '0321 1111111', 'default_eta_minutes' => 50]);
        $raita = \App\Models\Tenant\Category::create(['name' => 'Raita & Salad', 'slug' => 'rs-'.uniqid(), 'sort_order' => 1, 'is_active' => true]);

        $this->owner()->put($this->url('/online-ordering/settings'), [
            'section' => 'branding',
            'logo' => UploadedFile::fake()->image('logo.png', 600, 600),
            'banners' => [UploadedFile::fake()->image('b1.png', 3200, 1000), UploadedFile::fake()->image('b2.jpg', 3200, 1000)],
            'whatsapp_number' => '0321 2222222', 'contact_email' => 'hello@example.test', 'facebook_url' => 'https://facebook.com/x',
            'about_text' => 'Desi food since 1990.', 'upsell_category_id' => $raita->id,
        ])->assertRedirect('/online-ordering/settings#branding')->assertSessionHasNoErrors();

        $s = WebSetting::tenantDefault()->fresh();
        Storage::disk('public')->assertExists($s->logo_path);
        $this->assertCount(2, $s->banners);
        $this->assertSame(1920, getimagesizefromstring(Storage::disk('public')->get($s->banners[0]['path']))[0], 'Bara banner 1920 tak chhota.');
        $this->assertSame(960, getimagesizefromstring(Storage::disk('public')->get($s->banners[0]['sm']))[0], 'Mobile ke liye 960.');
        $this->assertSame($raita->id, $s->upsell_category_id);
        $this->assertSame('0321 1111111', $s->contact_phone, 'Website look ka form doosri settings nahi chhuta.');
        $this->assertSame(50, $s->default_eta_minutes);

        // Customer ka page sab saath laata hai.
        $html = $this->get($this->url('/order'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/var D = (\{.*?\});\n/s', $html, $m));
        $d = json_decode($m[1], true);
        $this->assertCount(2, $d['banners']);
        $this->assertStringContainsString('/storage/web-site/', $d['business']['logo']);
        $this->assertSame('923212222222', $d['business']['whatsapp']);
        $this->assertSame($raita->id, $d['upsellCategory']);
        $this->assertSame('Desi food since 1990.', $d['business']['about']);
        $this->assertStringContainsString('id="catR"', $html, 'Category bar ka aage wala teer.');
        $this->assertInlineScriptsParse($html, 'order page with branding', 'renderMenu');

        // Tarteeb badlo, phir aik hatao — file bhi jati hai.
        $first = $s->banners[0]['path'];
        $this->owner()->put($this->url('/online-ordering/settings'), ['section' => 'branding', 'move_banner' => '0:down'])->assertSessionHasNoErrors();
        $this->assertSame($first, WebSetting::tenantDefault()->fresh()->banners[1]['path']);
        $this->owner()->put($this->url('/online-ordering/settings'), ['section' => 'branding', 'remove_banners' => [1]])->assertSessionHasNoErrors();
        $this->assertCount(1, WebSetting::tenantDefault()->fresh()->banners);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_the_branding_import_is_a_dry_run_until_told(): void
    {
        Storage::fake('public');
        $dir = sys_get_temp_dir().'/oobrand-'.uniqid();
        mkdir($dir);
        foreach (['logo.png' => [500, 500], 'b1.png' => [2000, 625], 'b2.png' => [2000, 625]] as $name => [$w, $h]) {
            $img = imagecreatetruecolor($w, $h);
            imagepng($img, $dir.'/'.$name);
            imagedestroy($img);
        }
        \App\Models\Tenant\Category::create(['name' => 'Raita & Salad', 'slug' => 'rs-'.uniqid(), 'sort_order' => 1, 'is_active' => true]);
        $args = ['tenant_code' => 'oosetup', '--logo' => $dir.'/logo.png', '--banner' => [$dir.'/b1.png', $dir.'/b2.png'], '--upsell' => 'Raita & Salad'];

        $this->artisan('online-ordering:import-branding', $args)->expectsOutputToContain('sirf dikhaya')->assertSuccessful();
        $this->assertNull(WebSetting::tenantDefault()->fresh()->logo_path, 'Dry run ne likh diya.');

        $this->artisan('online-ordering:import-branding', $args + ['--yes' => true])->assertSuccessful();
        $s = WebSetting::tenantDefault()->fresh();
        $this->assertNotNull($s->logo_path);
        $this->assertCount(2, $s->banners);
        $this->assertNotNull($s->upsell_category_id);

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    private function owner()
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant');
    }

    private function url(string $path): string
    {
        return 'http://'.$this->host.$path;
    }

    private function area(string $name): int
    {
        return (int) GeoArea::where('city', 'Karachi')->where('name', $name)->value('id');
    }

    private function settingsInput(array $over = []): array
    {
        $hours = [];
        foreach (array_keys(WebSetting::DAYS) as $d) {
            $hours[$d] = ['open' => '12:00', 'close' => '04:00'];
        }

        return array_replace([
            'branch_mode' => 'area',
            'default_branch_id' => $this->branchA,
            'default_eta_minutes' => '45',
            'pickup_eta_minutes' => '25',
            'hours' => $hours,
            'closed_message' => 'Closed now.',
            'contact_phone' => '0300 0000000',
        ], $over);
    }

    private function productInput(array $over = []): array
    {
        return array_replace([
            'sku' => 'ALFAHAM-H', 'name' => 'Grill Chicken Al-Fahom (Half)', 'product_type' => 'simple',
            'product_kind' => 'sale_item', 'default_selling_price' => '1650', 'status' => 'active',
            'is_sellable' => '1', 'is_pos_visible' => '1',
        ], $over);
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'GR-SHASH', 'name' => 'Garlic Rice With Shashlik', 'slug' => 'garlic-rice-shashlik',
            'product_type' => 'simple', 'product_kind' => 'sale_item', 'default_selling_price' => 1650,
            'is_sellable' => true, 'is_pos_visible' => true, 'status' => 'active',
        ]);
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection('master');
        $m = DB::connection('master');

        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $old = $m->table('tenants')->where('tenant_code', 'oosetup')->value('id');
        if ($old) {
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }

        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'oosetup', 'business_name' => 'Online Ordering Setup',
            'owner_name' => 'Owner', 'owner_email' => 'owner@oosetup.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Saare modules; `online_ordering` sirf jab maanga jaye. */
    private function seedPlan(bool $withModule): void
    {
        $m = DB::connection('master');
        $planId = $m->table('plans')->where('code', 'oosetup-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'oosetup-plan', 'name' => 'Online Ordering Setup', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->get(['id', 'key']) as $module) {
            if ($module->key === 'online_ordering' && ! $withModule) {
                continue;
            }
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $module->id, 'is_enabled' => 1]);
        }

        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Tenant ka subscription aik request me yaad reh jata hai; agla request taaza parhe.
        if (app()->bound('tenant')) {
            app()->forgetInstance('tenant');
        }
    }

    private function bootTenant(): void
    {
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'delivery_zones', 'web_order_routes', 'web_settings', 'combo_components', 'combos',
            'product_branch_prices', 'product_variants', 'products', 'terminals',
            'model_has_roles', 'users', 'branches',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $c = DB::connection('tenant');
        $this->branchA = $c->table('branches')->insertGetId(['name' => 'Gulshan', 'code' => 'GUL', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->branchB = $c->table('branches')->insertGetId(['name' => 'Johar', 'code' => 'JOH', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->t1 = $c->table('terminals')->insertGetId(['branch_id' => $this->branchA, 'code' => 'OO-T1', 'name' => 'Counter One', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->t2 = $c->table('terminals')->insertGetId(['branch_id' => $this->branchA, 'code' => 'OO-T2', 'name' => 'Counter Two', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->t3 = $c->table('terminals')->insertGetId(['branch_id' => $this->branchB, 'code' => 'OO-T3', 'name' => 'Johar Counter', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        foreach (self::PERMS as $name) {
            $c->table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]);
        }
        $owner = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $pid) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $pid, 'role_id' => $owner], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'Site Owner', 'email' => 'owner@oosetup.local', 'password' => bcrypt('x'),
            'employee_code' => 'OOOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $owner, 'model_type' => User::class, 'model_id' => $this->ownerId]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
