<?php

namespace Tests\MySql;

use App\Models\Master\CentralUser;
use App\Models\Master\TenantDomain;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\WebOrderRoute;
use App\Models\Tenant\WebSetting;
use App\Services\Permissions\PermissionSyncService;
use App\Services\Sales\ShiftService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * ONLINE-ORDERING-1 (Phase 5) — malik: "kashiffood.com is server par point karunga — wo tenant ki ordering
 * website khole". Client ka apna domain SIRF website kholta hai; POS/login us par kabhi nahi, aur
 * `*.bingoopos.com` waisa hi.
 */
class OnlineOrderingWebsiteDomainMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const SITE = 'oosite-foods.test';

    private string $appHost;

    private int $tenantId;

    private int $branchId;

    private int $terminalId;

    private int $userId;

    private Combo $deal;

    /** @var list<int> */
    private array $centralUsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->appHost = 'oosite.'.config('tenancy.tenant_base_domain');
        $this->seedMaster();
        app(PermissionSyncService::class)->syncRouteCatalog();

        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'customer_addresses', 'customers', 'sales_order_lines', 'sales_orders', 'shifts', 'web_order_routes', 'web_settings',
            'delivery_zone_places', 'delivery_zones', 'delivery_channels', 'combo_modifier_group', 'combo_components', 'combos', 'product_branch_prices',
            'product_variants', 'products', 'categories', 'terminals', 'users', 'branches',
        ]);
        $this->userId = $this->makeUser(['employee_code' => 'WD'.Str::random(4)]);
        $this->branchId = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId);
        $cat = Category::create(['name' => 'Deals', 'slug' => 'd-'.uniqid(), 'sort_order' => 1, 'is_active' => true, 'show_on_website' => true]);
        $rice = Product::create(['sku' => 'R-'.uniqid(), 'name' => 'Garlic Rice', 'slug' => 'r-'.uniqid(), 'category_id' => $cat->id,
            'product_type' => 'simple', 'product_kind' => 'sale_item', 'default_selling_price' => 900, 'is_sellable' => true,
            'is_pos_visible' => true, 'status' => 'active', 'is_stock_tracked' => false, 'inventory_consumption_method' => 'none']);
        $this->deal = Combo::create(['name' => 'Midnight Deal', 'price' => 1550, 'status' => 'active', 'category_id' => $cat->id, 'show_on_website' => true]);
        $this->deal->components()->create(['product_id' => $rice->id, 'quantity' => 1, 'sort_order' => 1]);
        WebSetting::tenantDefault()->update(['is_enabled' => true, 'default_branch_id' => $this->branchId,
            'hours' => array_map(fn () => ['open' => '00:00', 'close' => '23:59', 'closed' => false], WebSetting::DAYS)]);
        WebOrderRoute::create(['branch_id' => $this->branchId, 'order_type' => 'delivery', 'terminal_id' => $this->terminalId]);
        app(ShiftService::class)->open(Branch::find($this->branchId), Terminal::find($this->terminalId), $this->userId, 0.0);
        DB::setDefaultConnection('master');
        app()->forgetInstance('tenant');
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenant_databases')->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('id', $this->tenantId)->delete();
            foreach ($this->centralUsers as $id) {
                $m->table('model_has_permissions')->where('model_id', $id)->where('model_type', CentralUser::class)->delete();
                $m->table('central_users')->where('id', $id)->delete();
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function test_the_clients_own_domain_opens_only_the_ordering_website(): void
    {
        $site = 'http://'.self::SITE;

        $this->get($site.'/')->assertOk()->assertSee('Website Foods');
        $this->get($site.'/order/menu.json?branch='.$this->branchId)->assertOk()->assertJsonStructure(['menu']);
        $this->get($site.'/order')->assertRedirect('/');

        // POS, login, admin, POS ka API — client ke domain par kuch nahi.
        foreach (['/login', '/dashboard', '/pos', '/api/pos/website-orders', '/billing', '/settings/website', '/r/'.str_repeat('a', 32)] as $path) {
            $this->get($site.$path)->assertNotFound();
        }

        // Purani website ke links Google se aayein to nayi website par.
        $this->get($site.'/product/zinger-burger')->assertStatus(301)->assertRedirect('/');
        $this->get($site.'/category/deals')->assertStatus(301)->assertRedirect('/');

        // www → bina-www, raasta aur query saath.
        $this->get('http://www.'.self::SITE.'/order/menu.json?x=1')
            ->assertStatus(301)->assertRedirect($site.'/order/menu.json?x=1');

        // bingoopos par sab waisa hi: tenant ka subdomain POS/login bhi, website bhi; central par ye raaste nahi.
        $this->get('http://'.$this->appHost.'/login')->assertOk();
        $this->get('http://'.$this->appHost.'/order')->assertOk();
        $this->get('http://'.$this->appHost.'/product/zinger-burger')->assertNotFound();
        $this->get('http://'.config('tenancy.central_domain').'/order/menu.json')->assertNotFound();
        $this->get('http://'.config('tenancy.central_domain').'/product/zinger-burger')->assertNotFound();
    }

    public function test_the_session_cookie_belongs_to_the_clients_domain(): void
    {
        // Prod par cookie `.bingoopos.com` ka ho sakta hai — kashiffood.com par browser wo rad karta, aur
        // checkout ka CSRF kabhi na chalta.
        config()->set('session.domain', '.'.config('tenancy.tenant_base_domain'));

        $app = $this->get('http://'.$this->appHost.'/login')->assertOk()->headers->getCookies();
        $this->assertSame('.'.config('tenancy.tenant_base_domain'), $app[0]->getDomain(), 'POS ka cookie waisa hi.');

        $cookies = $this->get('http://'.self::SITE.'/')->assertOk()->headers->getCookies();
        $this->assertNotEmpty($cookies);
        foreach ($cookies as $cookie) {
            $this->assertNull($cookie->getDomain(), $cookie->getName().' cookie bingoopos ka nahi hona chahiye.');
        }
    }

    public function test_an_order_from_the_clients_domain_reaches_the_pos_and_its_links_stay_there(): void
    {
        $zone = DeliveryZone::on('tenant')->create(['geo_area_id' => 993001, 'area_name' => 'Gulshan', 'city' => 'Karachi',
            'branch_id' => $this->branchId, 'fee' => 0, 'is_active' => true]);

        $res = $this->postJson('http://'.self::SITE.'/order/checkout', [
            'key' => (string) Str::uuid(), 'type' => 'delivery', 'area' => $zone->id,
            'customer' => ['name' => 'Test Customer', 'phone' => '0300 1234567', 'address' => 'House 1'],
            'lines' => [['option' => 'combo:'.$this->deal->id, 'qty' => 1]],
        ])->assertCreated();

        $sale = SalesOrder::on('tenant')->where('sale_no', $res->json('order'))->sole();
        $this->assertSame('website', $sale->order_source);
        $this->assertTrue($sale->is_draft);
        $this->assertSame($this->terminalId, (int) $sale->terminal_id);
        $this->assertStringStartsWith('http://'.self::SITE.'/order/status/', $res->json('status_url'));

        // WhatsApp ka "Confirm Order" (bingoopos.com/o/…) client ke apne domain par le jata hai.
        app()->forgetInstance('tenant');
        $this->get('http://'.config('tenancy.central_domain').'/o/oosite.'.$sale->web_token)
            ->assertRedirect('http://'.self::SITE.'/order/confirm/'.$sale->web_token);
        $this->get('http://'.self::SITE.'/order/status/'.$sale->web_token)->assertOk()->assertSee($sale->sale_no);
    }

    public function test_platform_admin_adds_the_clients_domain_as_a_website_domain(): void
    {
        $admin = $this->admin(['central.tenant-domains.store', 'central.tenant-domains.primary']);
        $central = 'http://'.config('tenancy.central_domain');

        // Jaisa bhi chipkaya jaye — sirf domain bachta hai.
        $this->actingAs($admin, 'central')->post($central.'/tenants/'.$this->tenantId.'/domains', [
            'kind' => 'website', 'website_domain' => 'https://www.OoSite-Second.test/menu?x=1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $row = TenantDomain::where('domain', 'oosite-second.test')->sole();
        $this->assertSame('website', $row->kind);
        $this->assertFalse((bool) $row->is_primary);

        // bingoopos.com khud website domain nahi ban sakta; us ka subdomain (UAT) chalta hai.
        $this->actingAs($admin, 'central')->post($central.'/tenants/'.$this->tenantId.'/domains', [
            'kind' => 'website', 'website_domain' => config('tenancy.tenant_base_domain'),
        ])->assertSessionHasErrors('website_domain');
        $this->actingAs($admin, 'central')->post($central.'/tenants/'.$this->tenantId.'/domains', [
            'kind' => 'website', 'website_domain' => 'oositeuat.'.config('tenancy.tenant_base_domain'),
        ])->assertSessionHasNoErrors();
        $this->assertSame('website', TenantDomain::where('domain', 'oositeuat.'.config('tenancy.tenant_base_domain'))->value('kind'));

        // Purana subdomain wala form waisa hi.
        $this->actingAs($admin, 'central')->post($central.'/tenants/'.$this->tenantId.'/domains', ['subdomain' => 'oosite-two'])
            ->assertSessionHasNoErrors();
        $this->assertSame('app', TenantDomain::where('domain', 'oosite-two.'.config('tenancy.tenant_base_domain'))->value('kind'));

        // Website domain primary nahi banta — POS ke links usi se bante hain.
        $this->actingAs($admin, 'central')->post($central.'/tenant-domains/'.$row->id.'/primary')->assertSessionHasErrors('domain');
        $this->assertFalse((bool) $row->fresh()->is_primary);
    }

    public function test_a_website_domain_under_bingoopos_opens_only_the_website(): void
    {
        // UAT (malik): kashiffoodweb.bingoopos.com = sirf website, POS kashiffood.bingoopos.com par hi.
        $uat = 'oositeweb.'.config('tenancy.tenant_base_domain');
        DB::connection('master')->table('tenant_domains')->insert(['tenant_id' => $this->tenantId, 'domain' => $uat, 'kind' => 'website',
            'is_primary' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        app()->forgetInstance('tenant');

        $this->get('http://'.$uat.'/')->assertRedirect('/order');
        $this->get('http://'.$uat.'/order')->assertOk()->assertSee('Website Foods');
        $this->get('http://'.$uat.'/order/menu.json?branch='.$this->branchId)->assertOk();
        foreach (['/login', '/dashboard', '/pos', '/api/pos/website-orders', '/billing', '/r/'.str_repeat('a', 32)] as $path) {
            $this->get('http://'.$uat.$path)->assertNotFound();
        }
        $this->get('http://'.$uat.'/sw.js')->assertOk();

        // POS ka apna subdomain waisa hi.
        $this->get('http://'.$this->appHost.'/login')->assertOk();
        $res = $this->get('http://'.$this->appHost.'/')->assertStatus(302);
        $this->assertStringNotContainsString('/order', (string) $res->headers->get('Location'), 'POS subdomain kabhi website par nahi bhejta.');
    }

    // ── helpers ──

    public function test_robots_txt_is_per_host_and_the_platform_one_is_unchanged(): void
    {
        $platform = \App\Http\Controllers\Website\WebsitePagesController::PLATFORM_ROBOTS;
        $this->assertSame("User-agent: *\nDisallow:\n\n# WEBSITE-I18N-GEO-1: every public page in every language (English + Arabic).\nSitemap: https://bingoopos.com/sitemap.xml\n",
            $platform, 'Purani public/robots.txt lafz ba lafz.');

        $this->assertSame($platform, $this->get('http://'.config('tenancy.central_domain').'/robots.txt')->assertOk()->getContent(), 'bingoopos.com waisa hi.');
        app()->forgetInstance('tenant');
        $this->assertSame($platform, $this->get('http://'.$this->appHost.'/robots.txt')->assertOk()->getContent(), 'POS subdomain waisa hi.');
        app()->forgetInstance('tenant');

        $own = $this->get('http://'.self::SITE.'/robots.txt')->assertOk();
        $this->assertStringContainsString('text/plain', (string) $own->headers->get('Content-Type'));
        $this->assertStringContainsString("Allow: /\n", $own->getContent());
        $this->assertStringContainsString('Sitemap: http://'.self::SITE.'/sitemap.xml', $own->getContent());
        app()->forgetInstance('tenant');

        // UAT jaisa *.bingoopos.com website pata: Google se chhupa.
        $uat = 'oositeseo.'.config('tenancy.tenant_base_domain');
        DB::connection('master')->table('tenant_domains')->where('domain', $uat)->delete();
        DB::connection('master')->table('tenant_domains')->insert(['tenant_id' => $this->tenantId, 'domain' => $uat, 'kind' => 'website',
            'is_primary' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame("User-agent: *\nDisallow: /\n", $this->get('http://'.$uat.'/robots.txt')->assertOk()->getContent());
    }

    public function test_seo_privacy_policy_and_faqs_are_the_restaurants_own(): void
    {
        $site = 'http://'.self::SITE;

        // Kuch likha nahi: safhe nahi, sitemap me sirf menu.
        $this->get($site.'/privacy-policy')->assertNotFound();
        $this->get($site.'/faqs')->assertNotFound();
        $this->assertSame(1, substr_count($this->get($site.'/sitemap.xml')->assertOk()->getContent(), '<url>'));
        app()->forgetInstance('tenant');

        // Website Settings → "SEO & pages" (asal form ka raasta).
        foreach (['tenant.online-ordering.settings.edit', 'tenant.online-ordering.settings.update'] as $p) {
            Permission::findOrCreate($p, 'tenant');
        }
        $owner = \App\Models\Tenant\User::on('tenant')->find($this->userId);
        $owner->givePermissionTo(['tenant.online-ordering.settings.edit', 'tenant.online-ordering.settings.update']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($owner, 'tenant')->put('http://'.$this->appHost.'/online-ordering/settings', [
            'section' => 'seo',
            'seo_title' => 'Website Foods — Biryani & BBQ in Karachi',
            'seo_description' => 'Order biryani and BBQ online. Cash on delivery.',
            'seo_keywords' => 'biryani, bbq, karachi',
            // Rich text editor ka HTML (malik: "rich text editable") — script/onclick saaf ho jate hain.
            'privacy_policy' => '<p>We protect your privacy.</p><h2>Information We Collect</h2><p>We collect <strong>only</strong>:</p>'
                .'<ul><li>Your name</li><li>Your phone number</li></ul><script>alert(1)</script><p onclick="steal()">Contact us.</p>',
            'faqs' => [
                ['category' => 'Ordering', 'q' => 'How do I place an order?', 'a' => '<p>Add items and <strong>check out</strong>.</p>'],
                ['category' => 'Delivery', 'q' => '', 'a' => 'Empty question — dropped.'],
                ['category' => 'Delivery', 'q' => 'How long does delivery take?', 'a' => '30–45 minutes.'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertCount(2, WebSetting::tenantDefault()->faqList(), 'Khaali sawal wali line nahi bachi.');
        app()->forgetInstance('tenant');

        $this->assertStringNotContainsString('<script', (string) WebSetting::tenantDefault()->privacy_policy, 'Save se pehle saaf.');
        $privacy = $this->get($site.'/privacy-policy')->assertOk()->getContent();
        $this->assertStringContainsString('<h2>Information We Collect</h2>', $privacy);
        $this->assertStringContainsString('<p>We collect <strong>only</strong>:</p>', $privacy);
        $this->assertStringContainsString('<li>Your phone number</li>', $privacy);
        $this->assertStringNotContainsString('alert(1)', $privacy);
        $this->assertStringNotContainsString('onclick', $privacy);
        $this->assertStringContainsString('content="index, follow"', $privacy);
        app()->forgetInstance('tenant');

        $faqs = $this->get($site.'/faqs')->assertOk()->getContent();
        $this->assertStringContainsString('How long does delivery take?', $faqs);
        $this->assertStringContainsString('"@type":"FAQPage"', $faqs);
        $this->assertStringContainsString('<strong>check out</strong>', $faqs, 'Jawab bhi rich text.');
        $this->assertStringContainsString('"text":"Add items and check out."', $faqs, 'Google ko saada text.');
        $this->assertStringNotContainsString('Empty question', $faqs);
        app()->forgetInstance('tenant');

        // Purani website ke /pages/… links.
        $this->get($site.'/pages/faqs')->assertStatus(301)->assertRedirect('/faqs');
        $this->get($site.'/pages/privacy-policy')->assertStatus(301)->assertRedirect('/privacy-policy');
        $this->get($site.'/pages/contact-us')->assertStatus(301)->assertRedirect('/');
        $this->assertSame(3, substr_count($this->get($site.'/sitemap.xml')->assertOk()->getContent(), '<url>'));
        app()->forgetInstance('tenant');

        // Menu ka safha: apne domain par Google ke liye sab kuch.
        $home = $this->get($site.'/')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Website Foods — Biryani &amp; BBQ in Karachi</title>', $home);
        $this->assertStringContainsString('<meta name="description" content="Order biryani and BBQ online. Cash on delivery.">', $home);
        $this->assertStringContainsString('<meta name="keywords" content="biryani, bbq, karachi">', $home);
        $this->assertStringContainsString('content="index, follow, max-image-preview:large"', $home);
        $this->assertStringContainsString('<link rel="canonical" href="https://'.self::SITE.'/">', $home);
        $this->assertStringContainsString('<meta property="og:title"', $home);
        $this->assertStringContainsString('"@type":"Restaurant"', $home);
        $this->assertStringContainsString('"privacy":"\/privacy-policy"', $home);
        app()->forgetInstance('tenant');

        // Wohi website POS ke subdomain par: noindex, aur Google ko asal domain batao; safhe /order/… par.
        $app = $this->get('http://'.$this->appHost.'/order')->assertOk()->getContent();
        $this->assertStringContainsString('content="noindex, follow"', $app);
        $this->assertStringContainsString('<link rel="canonical" href="https://'.self::SITE.'/">', $app);
        $this->assertStringContainsString('"faqs":"\/order\/faqs"', $app);
        app()->forgetInstance('tenant');
        $this->get('http://'.$this->appHost.'/order/faqs')->assertOk()->assertSee('noindex, follow', false);
    }

    private function admin(array $permissions): CentralUser
    {
        $user = CentralUser::create(['name' => 'Domain Admin', 'email' => 'dom'.uniqid().'@example.test',
            'password' => bcrypt('secret'), 'status' => 'active']);
        $this->centralUsers[] = $user->id;
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'central');
        }
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection('master');
        $m = DB::connection('master');
        $m->table('tenant_domains')->whereIn('domain', [$this->appHost, self::SITE, 'oosite-second.test', 'oosite-two.'.config('tenancy.tenant_base_domain')])->delete();
        if ($old = $m->table('tenants')->where('tenant_code', 'oosite')->value('id')) {
            $m->table('tenant_domains')->where('tenant_id', $old)->delete();
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'oosite', 'business_name' => 'Website Foods', 'owner_name' => 'Owner',
            'owner_email' => 'owner@oosite.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
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
            ['tenant_id' => $this->tenantId, 'domain' => $this->appHost, 'kind' => 'app', 'is_primary' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $this->tenantId, 'domain' => self::SITE, 'kind' => 'website', 'is_primary' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $planId = $m->table('plans')->where('code', 'oosite-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'oosite-plan', 'name' => 'Site', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->insert(['tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
        app()->instance('tenant', \App\Models\Master\Tenant::find($this->tenantId));
    }
}
