<?php

namespace Tests\MySql;

use App\Models\Tenant\Category;
use App\Models\Tenant\Product;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\WebsiteBranding;
use App\Services\Permissions\PermissionSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\MySql\Support\ParsesInlineScripts;
use Tests\MySql\Support\TenantFixtures;

/**
 * ONLINE-ORDERING-1 — malik ki "aur kya de sakte ho" wali cheezein: Popular asli bikri se, "Add to home screen"
 * (sirf client ke apne domain par), restaurant ki ghari (closes in / Midnight Deals live), order status ka jashn.
 */
class OnlineOrderingExtrasMySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;
    use TenantFixtures;

    private const SITE = 'ooextra-foods.test';

    private string $appHost;

    private int $tenantId;

    private int $branchId;

    /** @var array<string, Product> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->appHost = 'ooextra.'.config('tenancy.tenant_base_domain');
        $this->seedMaster();
        app(PermissionSyncService::class)->syncRouteCatalog();

        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['sales_order_lines', 'sales_orders', 'web_order_routes', 'web_settings', 'delivery_zone_places', 'delivery_zones',
            'product_modifier_group', 'products', 'categories', 'branches']);
        $this->branchId = $this->makeBranch();
        WebSetting::tenantDefault()->update(['is_enabled' => true, 'default_branch_id' => $this->branchId]);
        $cat = Category::create(['name' => 'Mains', 'slug' => 'm-'.uniqid(), 'sort_order' => 1, 'is_active' => true, 'show_on_website' => true]);
        foreach (['Alpha Karahi', 'Beta Biryani', 'Gamma Tikka'] as $i => $name) {
            $this->p[$name] = Product::create(['sku' => 'X-'.$i.'-'.uniqid(), 'name' => $name, 'slug' => 'x-'.$i.'-'.uniqid(), 'category_id' => $cat->id,
                'product_type' => 'simple', 'product_kind' => 'sale_item', 'default_selling_price' => 500, 'is_sellable' => true,
                'is_pos_visible' => true, 'status' => 'active', 'show_on_website' => true, 'website_popular' => $name === 'Gamma Tikka']);
        }
        Category::create(['name' => 'Midnight Deals', 'slug' => 'mn-'.uniqid(), 'sort_order' => 2, 'is_active' => true, 'show_on_website' => true,
            'website_visible_from' => '00:00', 'website_visible_to' => '04:00']);
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
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function test_popular_items_follow_the_last_seven_days_of_real_sales(): void
    {
        // Thori bikri: bikne wala pehle, phir haath se chuna "Popular".
        $this->sold('Beta Biryani', 1);
        $d = $this->payload($this->get('http://'.$this->appHost.'/order')->assertOk()->getContent());
        $this->assertSame(['Beta Biryani', 'Gamma Tikka'], collect($d['menu'][0]['items'])->where('popular', true)->sortBy('rank')->pluck('name')->values()->all());
        DB::connection('tenant')->table('sales_order_lines')->delete();
        DB::connection('tenant')->table('sales_orders')->delete();
        \Illuminate\Support\Facades\Cache::flush();
        app()->forgetInstance('tenant');

        $this->sold('Beta Biryani', 5);
        $this->sold('Alpha Karahi', 2);
        $this->sold('Gamma Tikka', 50, now()->subDays(10)->toDateString());   // purani bikri — gini nahi jati
        $this->sold('Gamma Tikka', 40, null, 'held');                          // abhi bika nahi (draft/held)
        $this->sold('Gamma Tikka', 30, null, 'paid', 'website');               // website ki bikri bhi ginti me
        $this->sold('Alpha Karahi', 31, null, 'paid', 'pos');                  // counter ki bhi

        $d = $this->payload($this->get('http://'.$this->appHost.'/order')->assertOk()->getContent());
        $this->assertTrue($d['popularAuto']);
        $popular = collect($d['menu'][0]['items'])->where('popular', true)->sortBy('rank')->pluck('name')->values()->all();
        $this->assertSame(['Alpha Karahi', 'Gamma Tikka', 'Beta Biryani'], $popular, 'Bikri ke hisaab se: 33, 30, 5.');

        // Haath wala tareeqa: sirf jin par "Popular" laga.
        DB::setDefaultConnection('tenant');
        WebSetting::tenantDefault()->update(['popular_auto' => false]);
        DB::setDefaultConnection('master');
        app()->forgetInstance('tenant');
        $d = $this->payload($this->get('http://'.$this->appHost.'/order')->assertOk()->getContent());
        $this->assertSame(['Gamma Tikka'], collect($d['menu'][0]['items'])->where('popular', true)->pluck('name')->values()->all());
    }

    public function test_the_page_carries_the_restaurant_clock_hours_and_timed_sections(): void
    {
        $html = $this->get('http://'.$this->appHost.'/order')->assertOk()->getContent();
        $d = $this->payload($html);
        $this->assertEqualsWithDelta(microtime(true) * 1000, $d['clock']['epoch'], 60000);
        $this->assertIsInt($d['clock']['offset']);
        $this->assertArrayHasKey('mon', $d['hours']);
        $this->assertSame([['name' => 'Midnight Deals', 'from' => '00:00', 'to' => '04:00']],
            collect($d['windows'])->map(fn ($w) => array_intersect_key($w, array_flip(['name', 'from', 'to'])))->all());
        $this->assertFalse($d['installable'], 'Bingoo ke subdomain par "install" nahi — wahan POS bhi hai.');
        $this->assertStringNotContainsString('rel="manifest"', $html);
        $this->assertInlineScriptsParse($html, 'order page extras', 'paintLive');
    }

    public function test_install_as_an_app_only_on_the_clients_own_domain(): void
    {
        Storage::fake('public');
        DB::setDefaultConnection('tenant');
        $img = imagecreatetruecolor(600, 600);
        imagepng($img, $file = sys_get_temp_dir().'/ooextra-logo-'.uniqid().'.png');
        imagedestroy($img);
        app()->instance('tenant', \App\Models\Master\Tenant::find($this->tenantId));
        app(WebsiteBranding::class)->setLogo(WebSetting::tenantDefault(), $file, 'ooextra');
        unlink($file);
        DB::setDefaultConnection('master');
        app()->forgetInstance('tenant');

        $html = $this->get('http://'.self::SITE.'/')->assertOk()->getContent();
        $this->assertTrue($this->payload($html)['installable']);
        $this->assertStringContainsString('rel="manifest"', $html);

        $m = $this->get('http://'.self::SITE.'/order/manifest.webmanifest')->assertOk()->json();
        $this->assertSame('Extra Foods', $m['name']);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame(['192x192', '512x512'], array_column($m['icons'], 'sizes'));

        $sw = $this->get('http://'.self::SITE.'/sw.js')->assertOk();
        $this->assertStringContainsString('javascript', $sw->headers->get('Content-Type'));
        $this->assertStringContainsString("if (r.method !== 'GET') { return; }", $sw->getContent(), 'Orders/checkout kabhi nahi chhuta.');
        $this->get('http://'.$this->appHost.'/sw.js')->assertNotFound();
    }

    public function test_the_status_page_celebrates_only_a_fresh_confirmation(): void
    {
        DB::setDefaultConnection('tenant');
        $sale = \App\Models\Tenant\SalesOrder::create(['sale_no' => 'HS-X-'.uniqid(), 'branch_id' => $this->branchId, 'order_source' => 'website',
            'order_type' => 'delivery', 'sale_date' => now(), 'status' => 'held', 'is_draft' => false, 'web_token' => str_repeat('a', 39).'b',
            'web_order_key' => (string) \Illuminate\Support\Str::uuid(), 'grand_total' => 500]);
        DB::setDefaultConnection('master');
        app()->forgetInstance('tenant');

        $url = 'http://'.$this->appHost.'/order/status/'.$sale->web_token;
        $this->get($url)->assertOk()->assertDontSee('id="confetti"', false);
        $this->withSession(['confirm_outcome' => 'confirmed'])->get($url)->assertOk()->assertSee('id="confetti"', false);
    }

    // ── helpers ──

    private function sold(string $name, float $qty, ?string $day = null, string $status = 'paid', string $source = 'pos'): void
    {
        $sale = $this->makeSale($this->branchId, ['status' => $status, 'order_source' => $source, 'business_date' => $day ?? now()->toDateString(), 'grand_total' => $qty * 500]);
        $this->makeSaleLine($sale, $this->p[$name]->id, ['quantity' => $qty]);
    }

    private function payload(string $html): array
    {
        $this->assertSame(1, preg_match('/var D = (\{.*?\});\n/s', $html, $m), 'Page payload nahi mila.');

        return json_decode($m[1], true);
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection('master');
        $m = DB::connection('master');
        $m->table('tenant_domains')->whereIn('domain', [$this->appHost, self::SITE])->delete();
        if ($old = $m->table('tenants')->where('tenant_code', 'ooextra')->value('id')) {
            $m->table('tenant_domains')->where('tenant_id', $old)->delete();
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'ooextra', 'business_name' => 'Extra Foods', 'owner_name' => 'Owner',
            'owner_email' => 'owner@ooextra.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
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
        $planId = $m->table('plans')->where('code', 'ooextra-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'ooextra-plan', 'name' => 'Extra', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->insert(['tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
        app()->instance('tenant', \App\Models\Master\Tenant::find($this->tenantId));
    }
}
