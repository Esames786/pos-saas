<?php

namespace Tests\MySql;

use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\Product;
use App\Models\Tenant\WebOrderRoute;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\WebsiteAvailability;
use App\Services\Permissions\PermissionSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\ParsesInlineScripts;

/**
 * ONLINE-ORDERING-1 (Phase 2b) — customer ki ordering website. Public (login nahi), plan ke module ke
 * baghair hai hi nahi, customer ko sirf website ki cheezein (POS ka andar ka naam/qeemat nahi), aur
 * band hone ke teeno sabab (switch, waqt, terminal par shift) sahi.
 */
class OnlineOrderingStorefrontMySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;

    private string $host;

    private int $tenantId;

    private int $branchA;

    private int $branchB;

    private int $t1;

    private int $t2;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = 'oostore.'.config('tenancy.tenant_base_domain');
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

    public function test_the_order_page_is_public_and_shows_only_website_things(): void
    {
        $this->menuFixture();

        $res = $this->get('http://'.$this->host.'/order')->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('Store Test Foods', $html);
        $this->assertInlineScriptsParse($html, 'order page', 'renderMenu');

        $payload = $this->payload($html);
        $this->assertSame(['Biryani'], array_column($payload['menu'], 'name'));
        $card = $payload['menu'][0]['items'][0];
        $this->assertSame('Chicken Biryani', $card['name']);
        $this->assertSame([250, 700], array_map('intval', array_column($card['options'], 'price')), 'Website price.');
        $this->assertSame(['Plain', 'Large'], array_column($card['options'], 'label'));
        $this->assertStringNotContainsString('Chicken Biryani (Large)', $html, 'POS ka andar ka naam customer tak nahi.');
        $this->assertStringNotContainsString('pos_price', $html);

        $this->assertSame(['Gulshan Block 2'], array_column($payload['areas'], 'name'), 'Band area website par nahi.');
        $this->assertSame(50.0, (float) $payload['areas'][0]['fee']);
        $this->assertFalse($payload['status']['open'], 'Website Settings me band.');
        $this->assertSame('disabled', $payload['status']['reason']);
    }

    public function test_by_distance_the_page_carries_the_branch_point_and_bands_and_pick_up_gets_directions(): void
    {
        $this->menuFixture();
        $html = $this->get('http://'.$this->host.'/order')->assertOk()->getContent();
        $p = $this->payload($html);
        $this->assertSame('area', $p['delivery']['mode'], 'Default: area ki fee.');
        $this->assertStringContainsString('google.com/maps/search', $p['branches'][0]['directions'], 'Nuqta nahi: naam aur pate se.');

        \App\Models\Tenant\WebBranchSetting::create(['branch_id' => $this->branchA, 'lat' => 24.923007, 'lng' => 67.089347]);
        \App\Models\Tenant\DeliveryFeeBand::create(['branch_id' => $this->branchA, 'up_to_km' => 18, 'fee' => 400]);
        \App\Models\Tenant\DeliveryFeeBand::create(['branch_id' => $this->branchA, 'up_to_km' => 1, 'fee' => 50]);
        WebSetting::tenantDefault()->update(['delivery_fee_mode' => 'distance']);

        $html = $this->get('http://'.$this->host.'/order')->assertOk()->getContent();
        $this->assertInlineScriptsParse($html, 'order page by distance', 'function quote');
        $p = $this->payload($html);
        $this->assertSame('distance', $p['delivery']['mode']);
        $this->assertSame([[1, 50], [18, 400]], array_map(fn ($b) => array_map('intval', $b), $p['delivery']['branches'][$this->branchA]['bands']), 'Chhote se bare.');
        $this->assertEqualsWithDelta(24.923007, $p['delivery']['branches'][$this->branchA]['lat'], 0.000001);
        $this->assertArrayHasKey('lat', $p['areas'][0], 'Pin na ho to area ka markaz.');
        $this->assertStringContainsString('24.923007,67.089347', $p['branches'][0]['directions'], 'Pick-up: branch ka nuqta.');

        // Malik ka apna link (Google share) sab se pehle.
        \App\Models\Tenant\WebBranchSetting::where('branch_id', $this->branchA)->update(['map_url' => 'https://share.google/example']);
        $this->assertSame('https://share.google/example', $this->payload($this->get('http://'.$this->host.'/order')->getContent())['branches'][0]['directions']);
    }

    public function test_without_the_module_there_is_no_website(): void
    {
        $this->seedPlan(withModule: false);
        $this->get('http://'.$this->host.'/order')->assertNotFound();
        $this->get('http://'.$this->host.'/order/menu.json?branch='.$this->branchA)->assertNotFound();
    }

    public function test_each_branch_has_its_own_menu(): void
    {
        $this->menuFixture();
        $deals = Category::create(['name' => 'Deals', 'slug' => 'deals-'.uniqid(), 'sort_order' => 2, 'is_active' => true, 'show_on_website' => true]);
        Combo::create(['name' => 'Johar Deal', 'price' => 900, 'status' => 'active', 'branch_id' => $this->branchB, 'category_id' => $deals->id, 'show_on_website' => true]);

        $a = $this->getJson('http://'.$this->host.'/order/menu.json?branch='.$this->branchA)->assertOk()->json('menu');
        $b = $this->getJson('http://'.$this->host.'/order/menu.json?branch='.$this->branchB)->assertOk()->json('menu');
        $this->assertNotContains('Deals', array_column($a, 'name'));
        $this->assertContains('Deals', array_column($b, 'name'));
        $this->getJson('http://'.$this->host.'/order/menu.json?branch=999999')->assertNotFound();
    }

    public function test_hours_that_run_past_midnight(): void
    {
        $svc = app(WebsiteAvailability::class);
        $hours = WebSetting::defaultHours();
        foreach (array_keys($hours) as $d) {
            $hours[$d] = ['open' => '12:00', 'close' => '04:00', 'closed' => false];
        }
        $hours['mon']['closed'] = true;

        $sat2am = CarbonImmutable::parse('2026-10-17 02:00', 'Asia/Karachi');   // Saturday — Friday's night
        $this->assertTrue($svc->withinHours($hours, $sat2am));
        $this->assertFalse($svc->withinHours($hours, $sat2am->setTime(5, 0)));
        $this->assertSame('12:00 PM', $svc->nextOpening($hours, $sat2am->setTime(5, 0)));
        $this->assertTrue($svc->withinHours($hours, $sat2am->setTime(23, 59)));

        // Monday closed: Monday 13:00 band; Sunday ki raat (Mon 01:00) phir bhi khuli.
        $this->assertFalse($svc->withinHours($hours, CarbonImmutable::parse('2026-10-19 13:00', 'Asia/Karachi')));
        $this->assertTrue($svc->withinHours($hours, CarbonImmutable::parse('2026-10-19 01:00', 'Asia/Karachi')));
        $this->assertSame('Tuesday 12:00 PM', $svc->nextOpening($hours, CarbonImmutable::parse('2026-10-19 13:00', 'Asia/Karachi')));
    }

    public function test_the_website_closes_when_nobody_is_on_the_terminal(): void
    {
        $s = WebSetting::tenantDefault();
        $s->update(['is_enabled' => true, 'hours' => array_map(fn () => ['open' => '00:00', 'close' => '23:59', 'closed' => false], WebSetting::DAYS)]);
        WebOrderRoute::create(['branch_id' => $this->branchA, 'order_type' => 'delivery', 'terminal_id' => $this->t1, 'backup_terminal_id' => $this->t2]);
        $at = CarbonImmutable::parse('2026-10-17 13:00', 'Asia/Karachi');

        $this->assertSame('no_staff', app(WebsiteAvailability::class)->status($s->fresh(), $at)['reason']);

        // Backup terminal par shift khuli = theek.
        DB::connection('tenant')->table('shifts')->insert([
            'branch_id' => $this->branchA, 'terminal_id' => $this->t2, 'opened_by_user_id' => $this->userId,
            'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $status = app(WebsiteAvailability::class)->status($s->fresh(), $at);
        $this->assertTrue($status['open']);
        $this->assertTrue($status['branches'][$this->branchA]['delivery']);
        $this->assertTrue($status['branches'][$this->branchA]['pickup'], 'Pick-Up ka terminal khaali = Delivery wala.');
    }

    public function test_with_pick_up_switched_off_only_delivery_is_offered(): void
    {
        // Malik (29 Oct, WEB-PICKUP-SWITCH-1): "pickup band kardo".
        $this->menuFixture();
        $s = WebSetting::tenantDefault();
        $s->update(['is_enabled' => true, 'hours' => array_map(fn () => ['open' => '00:00', 'close' => '23:59', 'closed' => false], WebSetting::DAYS)]);
        WebOrderRoute::create(['branch_id' => $this->branchA, 'order_type' => 'delivery', 'terminal_id' => $this->t1]);
        DB::connection('tenant')->table('shifts')->insert([
            'branch_id' => $this->branchA, 'terminal_id' => $this->t1, 'opened_by_user_id' => $this->userId,
            'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $at = CarbonImmutable::parse('2026-10-17 13:00', 'Asia/Karachi');

        $this->assertTrue(app(WebsiteAvailability::class)->status($s->fresh(), $at)['branches'][$this->branchA]['pickup'], 'Default: Pick-Up on.');
        $this->assertTrue($this->payload($this->get('http://'.$this->host.'/order')->assertOk()->getContent())['settings']['pickup']);

        $s->update(['pickup_enabled' => false]);
        $status = app(WebsiteAvailability::class)->status($s->fresh(), $at);
        $this->assertTrue($status['open'], 'Website khuli rehti hai — sirf Delivery.');
        $this->assertSame([true, false], [$status['branches'][$this->branchA]['delivery'], $status['branches'][$this->branchA]['pickup']]);

        $html = $this->get('http://'.$this->host.'/order')->assertOk()->getContent();
        $this->assertInlineScriptsParse($html, 'order page without pick-up', 'var PICKUP');
        $this->assertFalse($this->payload($html)['settings']['pickup'], 'Page Pick-Up ka button chhupata hai.');
    }

    // ── helpers ──

    private function menuFixture(): void
    {
        $cat = Category::create(['name' => 'Chicken Biryani', 'slug' => 'cb-'.uniqid(), 'sort_order' => 1, 'is_active' => true,
            'show_on_website' => true, 'website_name' => 'Biryani']);
        foreach ([['Chicken Biryani (Sadi)', 250, 'Plain', null, 1], ['Chicken Biryani (Large)', 750, 'Large', 700, 2]] as [$name, $price, $label, $web, $sort]) {
            Product::create(['sku' => 'CB-'.$sort.'-'.uniqid(), 'name' => $name, 'slug' => 'cb-'.$sort.'-'.uniqid(), 'category_id' => $cat->id,
                'product_type' => 'simple', 'product_kind' => 'sale_item', 'default_selling_price' => $price, 'is_sellable' => true,
                'is_pos_visible' => true, 'status' => 'active', 'show_on_website' => true, 'website_card_name' => 'Chicken Biryani',
                'website_size_label' => $label, 'website_price' => $web, 'website_sort_order' => $sort]);
        }
        foreach ([['Gulshan Block 2', 50, true], ['Clifton Block 5', 400, false]] as $i => [$name, $fee, $active]) {
            DeliveryZone::create(['geo_area_id' => 900000 + $i, 'area_name' => $name, 'city' => 'Karachi', 'branch_id' => $this->branchA,
                'fee' => $fee, 'is_active' => $active]);
        }
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
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $old = $m->table('tenants')->where('tenant_code', 'oostore')->value('id');
        if ($old) {
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'oostore', 'business_name' => 'Store Test Foods', 'owner_name' => 'Owner',
            'owner_email' => 'owner@oostore.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
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
        $planId = $m->table('plans')->where('code', 'oostore-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'oostore-plan', 'name' => 'Storefront', 'price' => 0,
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
            'shifts', 'web_order_routes', 'web_settings', 'delivery_fee_bands', 'web_branch_settings', 'delivery_zone_places', 'delivery_zones',
            'combo_modifier_group', 'product_modifier_group', 'modifiers', 'modifier_groups',
            'combo_components', 'combos', 'product_branch_prices', 'product_variants', 'products', 'categories',
            'terminals', 'model_has_roles', 'users', 'branches',
        ]);
        $c = DB::connection('tenant');
        $this->branchA = $c->table('branches')->insertGetId(['name' => 'Gulshan', 'code' => 'GUL', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->branchB = $c->table('branches')->insertGetId(['name' => 'Johar', 'code' => 'JOH', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->t1 = $c->table('terminals')->insertGetId(['branch_id' => $this->branchA, 'code' => 'SF-T1', 'name' => 'Delivery', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->t2 = $c->table('terminals')->insertGetId(['branch_id' => $this->branchA, 'code' => 'SF-T2', 'name' => 'Front', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->userId = $c->table('users')->insertGetId(['name' => 'Cashier', 'email' => 'c@oostore.local', 'password' => bcrypt('x'),
            'employee_code' => 'SFC', 'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now()]);
    }
}
