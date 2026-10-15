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

    public function test_a_society_inside_a_big_area_finds_that_area_by_name_and_by_its_own_point(): void
    {
        // UAT (15 Oct): Gulshan-e-Kaneez Fatima (Scheme 33) went to "Gulistan e Johar - Block 4" — OSM never says
        // "Scheme 33", and Scheme 33's one centre (Super Highway side) was farther than the Johar block's.
        $m = DB::connection('master');
        $m->table('geo_areas')->where('city', self::CITY)->where('name', 'Scheme 33')->update(['aliases' => json_encode([
            ['name' => 'Gulshan-e-Kaneez Fatima', 'lat' => null, 'lng' => null],
            ['name' => 'Gulzar-e-Hijri', 'lat' => 24.9550, 'lng' => 67.1150],
        ])]);
        $named = true;
        Http::fake(function () use (&$named) {
            return Http::response($named
                ? ['address' => ['neighbourhood' => 'Rufi Society', 'suburb' => 'Gulshan-e-Kaneez Fatima Society', 'town' => 'Gulshan-e-Iqbal Town'],
                    'display_name' => 'Rufi Society, Gulshan-e-Kaneez Fatima Society, Gulshan-e-Iqbal Town, Karachi']
                : ['address' => ['town' => 'Gulshan-e-Iqbal Town'], 'display_name' => 'Gulshan-e-Iqbal Town, Karachi']);
        });

        // 1. By name — the society is in the address (its alias has no point, so this is the name path).
        $j = $this->locate(24.956, 67.116)->assertOk()->json();
        $this->assertSame($this->zone['Scheme 33'], $j['area'], 'Society ka naam = us ka bara area.');
        $this->assertSame('name', $j['by']);

        // 2. By its own point — the address names no society, but Gulzar-e-Hijri's point is right there
        //    (the Johar block is ~3 km away, Scheme 33's centre ~7 km).
        $named = false;
        $j = $this->locate(24.9540, 67.1140)->assertOk()->json();
        $this->assertSame($this->zone['Scheme 33'], $j['area'], 'Society ka nuqta bhi area ka nuqta.');
        $this->assertSame('nearest', $j['by']);
        $this->assertLessThan(1.0, $j['km']);

        // 3. Without the societies it was the bug: the Johar block wins.
        $m->table('geo_areas')->where('city', self::CITY)->where('name', 'Scheme 33')->update(['aliases' => null]);
        $this->assertSame($this->zone['Gulistan e Johar - Block 7'], $this->locate(24.9540, 67.1140)->json('area'));
    }

    public function test_places_the_restaurant_writes_find_their_area_by_name_and_by_point(): void
    {
        // UAT (15 Oct): Chappal Sun City (Scheme 33; OSM "Chappal Sun City, New Rizvia Society") went to Johar —
        // the Johar block's point was nearer than any Scheme 33 point. The restaurant's own place list fixes it.
        DeliveryZone::whereKey($this->zone['Scheme 33'])->update(['places' => json_encode([
            ['name' => 'Chappal Sun City', 'lat' => null, 'lng' => null],
            ['name' => 'Safoora Point', 'lat' => 24.9480, 'lng' => 67.1450],
        ])]);
        $named = true;
        Http::fake(function () use (&$named) {
            return Http::response($named
                ? ['address' => ['neighbourhood' => 'Chappal Sun City', 'suburb' => 'New Rizvia Society', 'town' => 'Gulshan-e-Iqbal Town'],
                    'display_name' => 'Chappal Sun City, New Rizvia Society, Gulshan-e-Iqbal Town, Karachi']
                : ['address' => ['town' => 'Gulshan-e-Iqbal Town'], 'display_name' => 'Gulshan-e-Iqbal Town, Karachi']);
        });

        $j = $this->locate(24.952, 67.141)->assertOk()->json();
        $this->assertSame($this->zone['Scheme 33'], $j['area'], 'Restaurant ka likha naam pate me = us ka area.');
        $this->assertSame('name', $j['by']);
        $this->assertSame('Chappal Sun City, New Rizvia Society', $j['label'], 'Customer ko dikhe aur pate ke saath jure — sarkari town nahi.');

        $named = false;
        $j = $this->locate(24.9475, 67.1452)->assertOk()->json();
        $this->assertSame($this->zone['Scheme 33'], $j['area'], 'Likhi jagah ka nuqta bhi area ka nuqta.');
        $this->assertSame('nearest', $j['by']);

        DeliveryZone::whereKey($this->zone['Scheme 33'])->update(['places' => null]);
        $this->assertSame($this->zone['Gulistan e Johar - Block 7'], $this->locate(24.9475, 67.1452)->json('area'), 'Bina list wahi purani ghalti.');
    }

    public function test_a_big_government_town_name_is_not_a_sure_match(): void
    {
        // UAT (15 Oct): Dow Hospital Nazimabad → "You are in Liaquatabad". OSM files Nazimabad under "Liaquatabad
        // Town" (a whole district); the restaurant has no Nazimabad area. Nearest is fine — "sure by name" is not.
        $geo = DB::connection('master')->table('geo_areas')->insertGetId(['country' => 'Pakistan', 'state' => 'Sindh', 'city' => self::CITY,
            'name' => 'Liaquatabad', 'lat' => 24.9100, 'lng' => 67.0450, 'sort_order' => 9, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $liaquatabad = DeliveryZone::create(['geo_area_id' => $geo, 'area_name' => 'Liaquatabad', 'city' => 'Karachi',
            'branch_id' => $this->branchId, 'fee' => 250, 'is_active' => true])->id;
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'name' => 'Dow University Hospital - Nazimabad',
            'address' => ['road' => 'Nawab Siddique Ali Khan Road', 'neighbourhood' => 'Nazimabad Block 2', 'suburb' => 'Nazimabad 2',
                'town' => 'Liaquatabad Town', 'city_district' => 'Nazimabad District', 'city' => 'Karachi Division'],
            'display_name' => 'Dow University Hospital - Nazimabad, Nawab Siddique Ali Khan Road, Nazimabad Block 2, Liaquatabad Town, Karachi',
        ])]);

        $j = $this->locate(24.9067, 67.0318)->assertOk()->json();
        $this->assertSame($liaquatabad, $j['area'], 'Qareebi area wahi.');
        $this->assertSame('nearest', $j['by'], 'Magar andaza — "Nearest area", "You are in" nahi.');
    }

    public function test_a_guess_by_distance_says_nearest_and_offers_the_next_areas(): void
    {
        $html = $this->get($this->url('/order'))->assertOk()->getContent();
        $this->assertStringContainsString("(sure ? 'You are in' : 'Nearest area:')", $html);
        $this->assertStringContainsString('data-pick', $html);
        // Malik: mila area window me pehle se chuna (customer ne khud na chuna ho to) — window khud band NAHI hoti
        // (16 Oct), customer Select dabata hai; jagah dikhti hai.
        $this->assertStringContainsString("if (!userPicked) { S.type = 'delivery'; pending = a.id; }", $html);
        $this->assertStringContainsString('showLocated(a, j);', $html);
        $this->assertStringNotContainsString('applyLocated', $html);
        $this->assertStringNotContainsString('id="gpsGo"', $html, 'Continue button nahi — neeche ka Select hi.');
        $this->assertStringContainsString('id="placeLine"', $html);
        $this->assertStringContainsString('place: S.pin && S.place ? S.place : null', $html);
        $this->assertInlineScriptsParse($html, 'order page with nearest-area picks', 'data-pick');
    }

    public function test_places_around_the_restaurant_are_mapped_only_when_sure(): void
    {
        // Malik (15 Oct): "10 km ke radius mai jo b societies hain open street say data le kar map karlo".
        $m = DB::connection('master');
        $m->table('geo_areas')->where('city', self::CITY)->where('name', 'Scheme 33')
            ->update(['aliases' => json_encode([['name' => 'New Rizvia Society', 'lat' => 24.9507, 'lng' => 67.1475]])]);
        $square = fn (float $a, float $b, float $c, float $d) => [['lat' => $a, 'lon' => $b], ['lat' => $a, 'lon' => $d], ['lat' => $c, 'lon' => $d], ['lat' => $c, 'lon' => $b], ['lat' => $a, 'lon' => $b]];
        $elements = [
            ['type' => 'way', 'id' => 1, 'tags' => ['place' => 'neighbourhood', 'name' => 'Gulistan e Johar Block 7'], 'geometry' => $square(24.930, 67.133, 24.942, 67.145)],
            ['type' => 'node', 'id' => 2, 'lat' => 24.9365, 'lon' => 67.1395, 'tags' => ['place' => 'neighbourhood', 'name' => 'Johar Complex']],               // hadd ke andar
            ['type' => 'node', 'id' => 3, 'lat' => 24.9900, 'lon' => 67.1700, 'tags' => ['place' => 'neighbourhood', 'name' => 'Aligarh Cooperative Housing Society SCHEME 33']], // naam
            ['type' => 'node', 'id' => 4, 'lat' => 24.9520, 'lon' => 67.1410, 'tags' => ['place' => 'neighbourhood', 'name' => 'Chappal Sun City']],            // parent
            ['type' => 'node', 'id' => 5, 'lat' => 24.9366, 'lon' => 67.1396, 'tags' => ['place' => 'neighbourhood', 'name' => 'Block 7']],                     // aam naam
            ['type' => 'node', 'id' => 6, 'lat' => 25.2000, 'lon' => 67.5000, 'tags' => ['place' => 'hamlet', 'name' => 'Mystery Goth']],                        // pata nahi
            ['type' => 'node', 'id' => 7, 'lat' => 24.9512, 'lon' => 67.1440, 'tags' => ['place' => 'neighbourhood', 'name' => 'Al-Noor Society Scheme 33']],    // naam
            // Pados: 800 m me New Rizvia (jana naam), Chappal Sun City aur Al-Noor — teeno Scheme 33.
            ['type' => 'node', 'id' => 8, 'lat' => 24.9515, 'lon' => 67.1445, 'tags' => ['place' => 'neighbourhood', 'name' => 'Rufi Homes']],
        ];
        Http::fake(function (HttpRequest $r) use ($elements) {
            if (str_contains($r->url(), 'interpreter')) {
                return Http::response(['elements' => $elements]);
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $lat = (float) ($q['lat'] ?? 0);

            return Http::response(match (true) {
                abs($lat - 24.952) < 0.0002 => ['address' => ['neighbourhood' => 'Chappal Sun City', 'suburb' => 'New Rizvia Society', 'town' => 'Gulshan-e-Iqbal Town']],
                abs($lat - 24.9368) < 0.0003 => ['address' => ['neighbourhood' => 'Johar Complex', 'town' => 'Gulshan-e-Iqbal Town'], 'display_name' => 'Johar Complex, Karachi'],
                default => ['address' => ['hamlet' => 'Mystery Goth', 'town' => 'Gadap Town']],
            });
        });
        $args = ['tenant_code' => 'ooloc', '--lat' => '24.93', '--lng' => '67.10', '--pause' => '0'];

        $this->artisan('online-ordering:map-places', $args)->expectsOutputToContain('Pakke: 5 (naam 2 · hadd ke andar 1 · parent 1 · pados 1)')->assertSuccessful();
        $this->assertNull($m->table('geo_areas')->where('city', self::CITY)->where('name', 'Gulistan e Johar - Block 7')->value('aliases'), 'Dry run ne likh diya.');

        $this->artisan('online-ordering:map-places', $args + ['--yes' => true])->assertSuccessful();
        $scheme = array_column(json_decode((string) $m->table('geo_areas')->where('city', self::CITY)->where('name', 'Scheme 33')->value('aliases'), true), null, 'name');
        $johar = array_column(json_decode((string) $m->table('geo_areas')->where('city', self::CITY)->where('name', 'Gulistan e Johar - Block 7')->value('aliases'), true), null, 'name');
        $this->assertArrayHasKey('Chappal Sun City', $scheme);
        $this->assertArrayHasKey('Aligarh Cooperative Housing Society SCHEME 33', $scheme);
        $this->assertArrayHasKey('Rufi Homes', $scheme, 'Pados ki gawahi: 3 pakki Scheme 33 jagahen 800 m me.');
        $this->assertSame('osm', $scheme['Chappal Sun City']['src']);
        $this->assertEqualsWithDelta(24.952, $scheme['Chappal Sun City']['lat'], 0.0001);
        $this->assertSame(['Johar Complex'], array_keys($johar), '"Block 7" jaisa aam naam kabhi nahi; area ka apna naam bhi nahi.');
        $this->assertStringNotContainsString('Mystery', (string) $m->table('geo_areas')->where('city', self::CITY)->pluck('aliases')->implode(' '));

        // Dobara: kuch naya nahi.
        $this->artisan('online-ordering:map-places', $args + ['--yes' => true])->expectsOutputToContain('Likh diya: 0 naye naam')->assertSuccessful();

        // Aur ab "Use my current location": OSM ka mohalla "Johar Complex" → Block 7, naam se.
        $j = $this->locate(24.9368, 67.1398)->assertOk()->json();
        $this->assertSame($this->zone['Gulistan e Johar - Block 7'], $j['area']);
        $this->assertSame('name', $j['by']);
    }

    public function test_society_points_come_from_openstreetmap_and_a_namesake_in_another_city_is_ignored(): void
    {
        $m = DB::connection('master');
        $m->table('geo_areas')->where('city', self::CITY)->where('name', 'Scheme 33')->update(['aliases' => json_encode([
            ['name' => 'Gulzar-e-Hijri', 'lat' => null, 'lng' => null],
            ['name' => 'Saadi Town', 'lat' => null, 'lng' => null],
        ])]);
        Http::fake(function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $query = (string) ($q['q'] ?? '');

            return match (true) {
                str_starts_with($query, 'Gulzar-e-Hijri,') => Http::response([['lat' => '24.9551', 'lon' => '67.1149', 'display_name' => 'Gulzar-e-Hijri']]),
                str_starts_with($query, 'Saadi Town,') => Http::response([['lat' => '31.5204', 'lon' => '74.3587', 'display_name' => 'Saadi Town, Lahore']]),
                default => Http::response([]),
            };
        });

        $this->artisan('online-ordering:geocode-areas', ['--city' => self::CITY])->expectsOutputToContain('Societies: 1 / 2')->assertSuccessful();
        $this->assertNull(json_decode((string) $m->table('geo_areas')->where('city', self::CITY)->where('name', 'Scheme 33')->value('aliases'), true)[0]['lat'], 'Dry run ne likh diya.');

        $this->artisan('online-ordering:geocode-areas', ['--city' => self::CITY, '--yes' => true])->assertSuccessful();
        $aliases = json_decode((string) $m->table('geo_areas')->where('city', self::CITY)->where('name', 'Scheme 33')->value('aliases'), true);
        $this->assertEqualsWithDelta(24.9551, (float) $aliases[0]['lat'], 0.00001);
        $this->assertNull($aliases[1]['lat'], 'Doosre shehar ka hum-naam (Lahore) nahi liya.');
        Http::assertSent(fn (HttpRequest $r) => str_contains(urldecode($r->url()), 'q=Gulzar-e-Hijri, '.self::CITY));
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
