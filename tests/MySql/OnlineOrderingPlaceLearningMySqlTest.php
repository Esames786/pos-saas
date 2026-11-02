<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\OnlineOrdering\DeliveryPlaceController;
use App\Models\Tenant\DeliveryPlaceSighting;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\DeliveryZonePlace;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\AreaLocator;
use App\Services\OnlineOrdering\DeliveryPlaces;
use App\Services\Permissions\PermissionSyncService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\MySql\Support\TenantFixtures;

/**
 * PLACE-LEARNING-1 — delivered website orders teach the delivery areas their places (owner, 2 Nov: "suggested na ae,
 * area mature hote rahen jese jese order barhte rahen"). Plan: docs/plans/place-learning-2026-11-02.md.
 *
 * Through the real paths: the scheduled command (`--yes`), the website's /order/locate, and the Delivery Places
 * screen's Move / Remove. OpenStreetMap is faked by where the pin is.
 */
class OnlineOrderingPlaceLearningMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const CITY = 'OolearnCity';

    private string $host;

    private int $tenantId;

    private int $branchId;

    /** @var array<string, int> */
    private array $zone = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = 'oolearn.'.config('tenancy.tenant_base_domain');
        $this->seedMaster();
        app(PermissionSyncService::class)->syncRouteCatalog();

        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['delivery_place_sightings', 'sales_order_lines', 'sales_orders', 'web_order_routes', 'web_settings',
            'delivery_zone_places', 'delivery_zones', 'terminals', 'branches']);
        $this->branchId = $this->makeBranch();
        WebSetting::tenantDefault()->update(['is_enabled' => true, 'default_branch_id' => $this->branchId]);

        $m = DB::connection('master');
        $m->table('geo_area_places')->where('city', self::CITY)->delete();
        $m->table('geo_areas')->where('city', self::CITY)->delete();
        foreach ([['Main Shahrah e Faisal', 24.8600, 67.0700], ['Gulshan e Iqbal - Block 6', 24.9200, 67.0900], ['Scheme 33', 24.9992, 67.1720]] as $i => [$name, $lat, $lng]) {
            $geo = $m->table('geo_areas')->insertGetId(['country' => 'Pakistan', 'state' => 'Sindh', 'city' => self::CITY, 'name' => $name,
                'lat' => $lat, 'lng' => $lng, 'sort_order' => $i, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->zone[$name] = DeliveryZone::create(['geo_area_id' => $geo, 'area_name' => $name, 'city' => 'Karachi',
                'branch_id' => $this->branchId, 'fee' => 200, 'is_active' => true])->id;
        }
        $this->fakeMap();
        DB::setDefaultConnection('master');
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('geo_area_places')->where('city', self::CITY)->delete();
            $m->table('geo_areas')->where('city', self::CITY)->delete();
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('id', $this->tenantId)->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function test_one_delivered_order_teaches_its_place_and_the_next_ones_make_it_sure_and_sharper(): void
    {
        $this->order('Main Shahrah e Faisal', 24.86380, 67.07340, 14);
        $this->learn()->assertSuccessful();

        $p = $this->learned('PECHS Block 6');
        $this->assertSame($this->zone['Main Shahrah e Faisal'], $p->delivery_zone_id, 'the area the restaurant delivered it to');
        $this->assertSame('nearby', $p->kind, 'one order: counts for the nearest area, not yet by its name');
        $this->assertSame(1, $p->orders_count);
        $this->assertEqualsWithDelta(24.86380, $p->lat, 0.000001);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'nominatim.openstreetmap.org/reverse'));

        // A pin dropped by hand (no GPS figure) counts too. Two agreeing orders = trusted by its name.
        $this->order('Main Shahrah e Faisal', 24.86420, 67.07380, null);
        $this->learn();
        $p->refresh();
        $this->assertSame('sure', $p->kind);
        $this->assertSame(2, $p->orders_count);
        $this->assertEqualsWithDelta(24.86400, $p->lat, 0.000001, 'the point sits between the deliveries');

        // A third, far-off pin cannot drag the point: the median keeps it among the real deliveries.
        $this->order('Main Shahrah e Faisal', 24.86990, 67.07990, 40);
        $this->learn();
        $this->assertEqualsWithDelta(24.86420, $p->fresh()->lat, 0.000001);

        $this->learn();   // run again: nothing new, nothing twice
        $this->assertSame(3, DeliveryPlaceSighting::count());
        $this->assertSame(1, DeliveryZonePlace::count());
    }

    public function test_the_area_most_deliveries_went_to_wins(): void
    {
        $this->order('Main Shahrah e Faisal', 24.86380, 67.07340, 10);
        $this->order('Main Shahrah e Faisal', 24.86390, 67.07350, 10);
        $this->order('Gulshan e Iqbal - Block 6', 24.86385, 67.07345, 10);
        $this->learn();
        $this->assertSame($this->zone['Main Shahrah e Faisal'], $this->learned('PECHS Block 6')->delivery_zone_id, '2 against 1');

        $this->order('Gulshan e Iqbal - Block 6', 24.86386, 67.07346, 10);
        $this->order('Gulshan e Iqbal - Block 6', 24.86387, 67.07347, 10);
        $this->learn();
        $p = $this->learned('PECHS Block 6');
        $this->assertSame($this->zone['Gulshan e Iqbal - Block 6'], $p->delivery_zone_id, '3 against 2: the area follows the deliveries');
        $this->assertSame(3, $p->orders_count, 'counted in the winning area');
    }

    public function test_orders_that_teach_nothing_are_noted_once_and_never_looked_at_again(): void
    {
        $this->order('Scheme 33', null, null, null);                       // no pin
        $this->order('Scheme 33', 24.99000, 67.17000, 500);                // phone GPS ±500 m: a guess
        $this->order('Scheme 33', 25.01000, 67.19000, 12);                 // the map knows only a road there
        $this->order('Scheme 33', 24.86380, 67.07340, 10, ['order_type' => 'takeaway']);   // pick-up
        $this->order('Scheme 33', 24.86380, 67.07340, 10, ['status' => 'held']);           // not delivered yet

        $this->learn();
        $this->assertEqualsCanonicalizing(['no_pin', 'rough_gps', 'no_name'], DeliveryPlaceSighting::pluck('skipped')->all());
        $this->assertSame(0, DeliveryZonePlace::count());

        $this->learn();
        $this->assertSame(3, DeliveryPlaceSighting::count(), 'each order is looked at once');
    }

    public function test_the_admin_always_wins(): void
    {
        // The restaurant's own place: orders elsewhere never move it.
        DeliveryZonePlace::create(['delivery_zone_id' => $this->zone['Scheme 33'], 'name' => 'Jamali Colony', 'name_key' => AreaLocator::norm('Jamali Colony'),
            'lat' => 24.92, 'lng' => 67.09, 'source' => 'manual', 'kind' => 'sure', 'status' => 'active']);
        // A name the map list already has is not duplicated.
        DB::connection('master')->table('geo_area_places')->insert(['geo_area_id' => DB::connection('master')->table('geo_areas')->where('city', self::CITY)->where('name', 'Scheme 33')->value('id'),
            'city' => self::CITY, 'name' => 'Saadi Garden', 'name_key' => AreaLocator::norm('Saadi Garden'), 'lat' => 24.97, 'lng' => 67.15,
            'source' => 'platform', 'kind' => 'sure', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->order('Gulshan e Iqbal - Block 6', 24.92010, 67.09010, 10);   // OSM: Jamali Colony
        $this->order('Gulshan e Iqbal - Block 6', 24.97010, 67.15010, 10);   // OSM: Saadi Garden
        $this->order('Main Shahrah e Faisal', 24.86380, 67.07340, 10);       // OSM: PECHS Block 6
        $this->order('Main Shahrah e Faisal', 24.86390, 67.07350, 10);
        $this->learn();

        $own = DeliveryZonePlace::where('name_key', AreaLocator::norm('Jamali Colony'))->sole();
        $this->assertSame(['manual', $this->zone['Scheme 33']], [$own->source, $own->delivery_zone_id], 'the admin\'s place is not ours to change');
        $this->assertFalse(DeliveryZonePlace::where('name_key', AreaLocator::norm('Saadi Garden'))->exists(), 'the map list already knows it');

        // Move on a learned place makes it the admin's: later orders no longer move it.
        $learned = $this->learned('PECHS Block 6');
        $this->callScreen('update', $learned->id, ['action' => 'move', 'zone_id' => $this->zone['Scheme 33']]);
        $this->assertSame(['manual', $this->zone['Scheme 33']], [$learned->fresh()->source, $learned->fresh()->delivery_zone_id]);
        $this->order('Main Shahrah e Faisal', 24.86385, 67.07345, 10);
        $this->learn();
        $this->assertSame($this->zone['Scheme 33'], $learned->fresh()->delivery_zone_id);

        // Remove on a learned place hides it for good — the next order does not bring it back.
        $this->order('Gulshan e Iqbal - Block 6', 24.88010, 67.10010, 10);   // OSM: Model Colony
        $this->learn();
        $mc = $this->learned('Model Colony');
        $this->callScreen('destroy', $mc->id);
        $this->assertSame('hidden', $mc->fresh()->status);
        $this->order('Gulshan e Iqbal - Block 6', 24.88020, 67.10020, 10);
        $this->learn();
        $this->assertSame(['hidden', 2], [$mc->fresh()->status, $mc->fresh()->orders_count], 'it keeps maturing, out of sight');
        $this->assertFalse(app(DeliveryPlaces::class)->all()->contains('name_key', $mc->name_key), 'the website never sees it');
    }

    public function test_once_learned_the_website_finds_the_area_by_that_name(): void
    {
        // Before: OSM says "PECHS Block 6" but no area has that name — only the nearest guess.
        $before = $this->getJson('http://'.$this->host.'/order/locate?lat=24.8640&lng=67.0736')->assertOk()->json();
        $this->assertNotSame('name', $before['by']);

        $this->order('Gulshan e Iqbal - Block 6', 24.86380, 67.07340, 10);
        $this->order('Gulshan e Iqbal - Block 6', 24.86420, 67.07380, 10);
        $this->learn();

        $after = $this->getJson('http://'.$this->host.'/order/locate?lat=24.8640&lng=67.0736')->assertOk()->json();
        $this->assertSame($this->zone['Gulshan e Iqbal - Block 6'], $after['area'], 'the area the deliveries went to — not the nearest centre');
        $this->assertSame('name', $after['by']);
        $this->assertContains('PECHS Block 6', array_column($after['near_places'], 'name'), 'and it is recommended near the pin');
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->order('Main Shahrah e Faisal', 24.86380, 67.07340, 14);
        $this->artisan('online-ordering:learn-places', ['--tenant' => 'oolearn', '--pause' => 0])
            ->expectsOutputToContain('learned: PECHS Block 6')->assertSuccessful();
        $this->assertSame(0, DeliveryPlaceSighting::count());
        $this->assertSame(0, DeliveryZonePlace::count());
    }

    // ── helpers ──

    /** OpenStreetMap by where the pin is (one fake: the first stub always wins). */
    private function fakeMap(): void
    {
        Http::fake(function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $lat = (float) ($q['lat'] ?? 0);
            $address = match (true) {
                $lat > 24.860 && $lat < 24.870 => ['road' => 'Service Lane', 'neighbourhood' => 'Block 6', 'suburb' => 'PECHS', 'state' => 'Sindh'],
                $lat > 24.875 && $lat < 24.885 => ['neighbourhood' => 'Model Colony', 'suburb' => 'Malir', 'state' => 'Sindh'],
                $lat > 24.915 && $lat < 24.925 => ['neighbourhood' => 'Jamali Colony', 'suburb' => 'Essa Nagri', 'state' => 'Sindh'],
                $lat > 24.965 && $lat < 24.975 => ['neighbourhood' => 'Saadi Garden', 'state' => 'Sindh'],
                default => ['road' => 'Kiran Hospital Road', 'state' => 'Sindh'],
            };

            return Http::response(['address' => $address, 'display_name' => implode(', ', $address)]);
        });
    }

    private function order(string $area, ?float $lat, ?float $lng, ?int $acc, array $over = []): int
    {
        return $this->makeSale($this->branchId, array_merge([
            'order_source' => 'website', 'order_type' => 'delivery', 'status' => 'paid',
            'web_meta' => json_encode(['area' => $area, 'area_id' => $this->zone[$area], 'lat' => $lat, 'lng' => $lng, 'place_accuracy_m' => $acc]),
        ], $over));
    }

    private function learn()
    {
        return $this->artisan('online-ordering:learn-places', ['--tenant' => 'oolearn', '--yes' => true, '--pause' => 0]);
    }

    private function learned(string $name): DeliveryZonePlace
    {
        return DeliveryZonePlace::on('tenant')->where('name_key', AreaLocator::norm($name))->where('source', 'learned')->sole();
    }

    private function callScreen(string $method, int $id, array $input = []): void
    {
        DB::setDefaultConnection('tenant');
        $request = Request::create('/online-ordering/places/restaurant/'.$id, $method === 'update' ? 'PUT' : 'DELETE', $input);
        app()->instance('request', $request);
        $params = ['origin' => 'restaurant', 'id' => $id];
        app()->call([app(DeliveryPlaceController::class), $method], $method === 'update' ? $params + ['request' => $request] : $params);
        DB::setDefaultConnection('master');
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection('master');
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        if ($old = $m->table('tenants')->where('tenant_code', 'oolearn')->value('id')) {
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'oolearn', 'business_name' => 'Learning Foods', 'owner_name' => 'Owner',
            'owner_email' => 'owner@oolearn.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
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
        $planId = $m->table('plans')->where('code', 'oolearn-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'oolearn-plan', 'name' => 'Learn', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->insert(['tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
