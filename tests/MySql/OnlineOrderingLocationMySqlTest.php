<?php

namespace Tests\MySql;

use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\WebSetting;
use App\Services\Permissions\PermissionSyncService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\MySql\Support\ParsesInlineScripts;
use Tests\MySql\Support\TenantFixtures;

/**
 * ONLINE-ORDERING-1 — malik: "current location khud on ho, area khud aaye (Scheme 33 ya qareebi), delivery charges
 * area chunte waqt na dikhen". OpenStreetMap hamare server se (cache ke saath); na chale to qareebi area.
 */
class OnlineOrderingLocationMySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;
    use TenantFixtures;

    private const CITY = 'OolocCity';

    private string $host;

    private int $tenantId;

    private int $branchId;

    /** @var array<string, int> */
    private array $zone = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = 'ooloc.'.config('tenancy.tenant_base_domain');
        $this->seedMaster();
        app(PermissionSyncService::class)->syncRouteCatalog();

        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['web_order_routes', 'web_settings', 'delivery_zones', 'terminals', 'branches']);
        $this->branchId = $this->makeBranch();
        WebSetting::tenantDefault()->update(['is_enabled' => true, 'default_branch_id' => $this->branchId]);

        $m = DB::connection('master');
        $m->table('geo_areas')->where('city', self::CITY)->delete();
        foreach ([['Scheme 33', 24.9992, 67.1720], ['Clifton Block 5', 24.8206, 67.0282], ['Gulistan e Johar - Block 7', 24.9360, 67.1390], ['No Point Town', null, null]] as $i => [$name, $lat, $lng]) {
            $geo = $m->table('geo_areas')->insertGetId(['country' => 'Pakistan', 'state' => 'Sindh', 'city' => self::CITY, 'name' => $name,
                'lat' => $lat, 'lng' => $lng, 'sort_order' => $i, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->zone[$name] = DeliveryZone::create(['geo_area_id' => $geo, 'area_name' => $name, 'city' => 'Karachi',
                'branch_id' => $this->branchId, 'fee' => 250, 'is_active' => true])->id;
        }
        DB::setDefaultConnection('master');
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('geo_areas')->where('city', self::CITY)->delete();
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('id', $this->tenantId)->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function test_openstreetmap_names_the_place_and_the_area_with_that_name_is_chosen(): void
    {
        $this->osm(['neighbourhood' => 'Clifton Block 5', 'suburb' => 'Saddar Town', 'road' => 'Avenue 5'], 'Avenue 5, Clifton Block 5, Karachi');

        $j = $this->locate(24.8205, 67.0285)->assertOk()->json();
        $this->assertSame($this->zone['Clifton Block 5'], $j['area']);
        $this->assertSame('name', $j['by']);
        $this->assertSame('Clifton Block 5, Saddar Town', $j['place']);
        $this->assertSame('Avenue 5, Clifton Block 5', $j['street']);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'nominatim.openstreetmap.org/reverse')
            && str_contains($r->header('User-Agent')[0] ?? '', 'BingooPOS'));
    }

    public function test_when_no_name_matches_the_nearest_area_is_chosen_but_only_if_it_is_close(): void
    {
        // Scheme 33 ke andar ka point OSM par "Saadi Garden" aata hai — naam nahi milta, qareebi milta hai.
        $this->osm(['neighbourhood' => 'Saadi Garden', 'town' => 'Gulshan-e-Iqbal Town'], 'Saadi Garden, Karachi');

        $j = $this->locate(24.9990, 67.1700)->assertOk()->json();
        $this->assertSame($this->zone['Scheme 33'], $j['area']);
        $this->assertSame('nearest', $j['by']);
        $this->assertSame($this->zone['Scheme 33'], $j['nearby'][0], 'Qareebi areas list ke upar.');

        // Malik: poora Karachi — naam na mile to qareebi area, door ho tab bhi (30 km); sirf doosra shehar (60 km) nahi.
        $this->assertSame($this->zone['Scheme 33'], $this->locate(24.9992 + 30 / 111.2, 67.1720)->json('area'));
        $this->assertNull($this->locate(24.9992 + 60 / 111.2, 67.1720)->json('area'));

        // Shehar se bahar: koi area nahi chuna jata — customer khud chunta hai.
        $far = $this->locate(25.6, 68.3)->assertOk()->json();
        $this->assertNull($far['area']);
        $this->assertSame([], $far['nearby']);
    }

    public function test_without_openstreetmap_the_nearest_area_still_works_and_answers_are_cached(): void
    {
        $up = false;
        Http::fake(function () use (&$up) {
            return $up ? Http::response(['address' => ['suburb' => 'Johar'], 'display_name' => 'Johar']) : Http::response('busy', 503);
        });
        $j = $this->locate(24.9361, 67.1391)->assertOk()->json();
        $this->assertSame($this->zone['Gulistan e Johar - Block 7'], $j['area']);
        $this->assertNull($j['place']);

        // Nakami cache nahi hoti (agli dafa phir poochha); aik gali ke customer aik hi OSM request (jawab cache).
        $up = true;
        $this->assertSame('Johar', $this->locate(24.93612, 67.13914)->assertOk()->json('place'));
        $this->locate(24.93608, 67.13911)->assertOk();
        Http::assertSentCount(2);

        $this->getJson($this->url('/order/locate?lat=abc'))->assertStatus(422);
    }

    public function test_the_order_page_asks_for_location_hides_fees_while_choosing_and_says_cash_on_delivery(): void
    {
        $html = $this->get($this->url('/order'))->assertOk()->getContent();
        $this->assertStringContainsString('locateMe(true)', $html, 'Pehli dafa location khud maangi jati hai.');
        $this->assertStringContainsString('"locate":', $html);
        $this->assertStringNotContainsString("(a.fee > 0 ? money(a.fee) : 'Free') + ' · '", $html, 'Area chunte waqt delivery charges nahi.');
        $this->assertStringContainsString('Pay the rider in cash when your order arrives.', $html);
        $this->assertStringContainsString("payment: 'cash'", $html);
        $this->assertInlineScriptsParse($html, 'order page with location', 'locateMe');
    }

    public function test_area_centres_come_from_openstreetmap_and_a_block_falls_back_to_its_area(): void
    {
        $m = DB::connection('master');
        $m->table('geo_areas')->where('city', self::CITY)->update(['lat' => null, 'lng' => null]);
        $m->table('geo_areas')->where('city', self::CITY)->whereIn('name', ['Scheme 33', 'No Point Town', 'Gulistan e Johar - Block 7'])->delete();
        Http::fake(function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return str_starts_with((string) $q['q'], 'Clifton Block 5,')
                ? Http::response([])                                             // poora naam nahi mila
                : Http::response([['lat' => '24.8211', 'lon' => '67.0301', 'display_name' => 'Block 5, Clifton']]);
        });

        $this->artisan('online-ordering:geocode-areas', ['--city' => self::CITY])->expectsOutputToContain('sirf dikhaya')->assertSuccessful();
        $this->assertNull($m->table('geo_areas')->where('city', self::CITY)->value('lat'), 'Dry run ne likh diya.');

        $this->artisan('online-ordering:geocode-areas', ['--city' => self::CITY, '--yes' => true])->assertSuccessful();
        $row = $m->table('geo_areas')->where('city', self::CITY)->where('name', 'Clifton Block 5')->first();
        $this->assertEqualsWithDelta(24.8211, (float) $row->lat, 0.00001);
        Http::assertSent(fn (HttpRequest $r) => str_contains(urldecode($r->url()), 'q=Block 5, Clifton, '.self::CITY));
    }

    // ── helpers ──

    private function osm(array $address, string $display): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['address' => $address, 'display_name' => $display])]);
    }

    private function locate(float $lat, float $lng)
    {
        return $this->getJson($this->url('/order/locate?lat='.$lat.'&lng='.$lng));
    }

    private function url(string $p): string
    {
        return 'http://'.$this->host.$p;
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection('master');
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        if ($old = $m->table('tenants')->where('tenant_code', 'ooloc')->value('id')) {
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'ooloc', 'business_name' => 'Location Foods', 'owner_name' => 'Owner',
            'owner_email' => 'owner@ooloc.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
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
        $planId = $m->table('plans')->where('code', 'ooloc-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'ooloc-plan', 'name' => 'Loc', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->insert(['tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
