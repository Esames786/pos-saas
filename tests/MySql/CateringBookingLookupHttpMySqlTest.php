<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-STORE-2 — finding tonight's bookings from the store counter.
 *
 * The storeman's question is never "search all bookings"; it is "what is going
 * out tonight". So the date leads and search narrows within it, and everything
 * here is about that shape being right:
 *
 *   - a date returns that day and only that day
 *   - search finds a booking by its number, its customer, its phone, its venue
 *   - a cancelled booking is never offered, because it cannot explain stock
 *     leaving the store today
 *
 * The last one matters most. Select-all operates on whatever this endpoint
 * returned, so anything wrongly included here gets attached in bulk by someone
 * who never read the row.
 */
class CateringBookingLookupHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const PERM = 'tenant.catering.store-issues.bookings';

    private string $host;

    private string $uri;

    private int $ownerId;

    private int $cashierId;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = 'cateringlookup.'.config('tenancy.tenant_base_domain');
        $this->uri = 'http://'.$this->host.'/catering/store-issues/bookings';

        $this->seedMaster();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();

            $planId = $m->table('plans')->where('code', 'lookup-catering')->value('id');
            if ($planId) {
                $m->table('plan_modules')->where('plan_id', $planId)->delete();
                $m->table('subscriptions')->where('plan_id', $planId)->delete();
                $m->table('plans')->where('id', $planId)->delete();
            }

            $m->table('tenants')->where('tenant_code', 'cateringlookup')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    private function makeBooking(array $attrs): int
    {
        return DB::connection('tenant')->table('catering_events')->insertGetId(array_merge([
            'event_no' => 'EV-'.uniqid(),
            'branch_id' => $this->branchId,
            'customer_name' => 'Someone',
            'customer_phone' => null,
            'customer_address' => null,
            'venue' => null,
            'service_time' => '19:00',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->toDateString(),
            'pax' => 100,
            'status' => CateringEvent::STATUS_CONFIRMED,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** @return array<int, array<string, mixed>> */
    private function lookup(array $query = []): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->getJson($this->uri.'?'.http_build_query($query));

        $res->assertOk();

        return $res->json('results');
    }

    private function numbersFrom(array $results): array
    {
        return collect($results)->pluck('event_no')->sort()->values()->all();
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_lookup_route_exists_and_is_gated(): void
    {
        $this->assertTrue(Route::has(self::PERM));
        $this->assertContains('route.permission', Route::getRoutes()->getByName(self::PERM)->gatherMiddleware());
    }

    public function test_a_user_without_the_permission_cannot_list_bookings(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs(User::on('tenant')->find($this->cashierId), 'tenant')
            ->getJson($this->uri)
            ->assertStatus(403);
    }

    // ── The date leads ───────────────────────────────────────────────────────

    /** Today's bookings, and only today's. */
    public function test_a_date_returns_that_day_and_no_other(): void
    {
        $this->makeBooking(['event_no' => 'EV-TODAY-1', 'event_date' => now()->toDateString()]);
        $this->makeBooking(['event_no' => 'EV-TODAY-2', 'event_date' => now()->toDateString()]);
        $this->makeBooking(['event_no' => 'EV-TOMORROW', 'event_date' => now()->addDay()->toDateString()]);
        $this->makeBooking(['event_no' => 'EV-YESTERDAY', 'event_date' => now()->subDay()->toDateString()]);

        $results = $this->lookup(['date' => now()->toDateString()]);

        $this->assertSame(['EV-TODAY-1', 'EV-TODAY-2'], $this->numbersFrom($results));
    }

    /** A nearby date is reachable — a trip may cover tomorrow's prep. */
    public function test_another_date_returns_that_day(): void
    {
        $this->makeBooking(['event_no' => 'EV-TODAY-1', 'event_date' => now()->toDateString()]);
        $this->makeBooking(['event_no' => 'EV-TOMORROW', 'event_date' => now()->addDay()->toDateString()]);

        $results = $this->lookup(['date' => now()->addDay()->toDateString()]);

        $this->assertSame(['EV-TOMORROW'], $this->numbersFrom($results));
    }

    /** With no date the search runs across every day. */
    public function test_no_date_searches_across_all_days(): void
    {
        $this->makeBooking(['event_no' => 'EV-TODAY-1', 'event_date' => now()->toDateString()]);
        $this->makeBooking(['event_no' => 'EV-TOMORROW', 'event_date' => now()->addDay()->toDateString()]);

        $this->assertCount(2, $this->lookup());
    }

    // ── Search narrows within it ─────────────────────────────────────────────

    public function test_search_finds_a_booking_by_its_number(): void
    {
        $this->makeBooking(['event_no' => 'EV-20260817-0007', 'customer_name' => 'Mr Ali']);
        $this->makeBooking(['event_no' => 'EV-20260817-0008', 'customer_name' => 'Mr Khan']);

        $results = $this->lookup(['q' => '0007']);

        $this->assertSame(['EV-20260817-0007'], $this->numbersFrom($results));
    }

    public function test_search_finds_a_booking_by_customer(): void
    {
        $this->makeBooking(['event_no' => 'EV-A', 'customer_name' => 'Ahmed Sheikh']);
        $this->makeBooking(['event_no' => 'EV-B', 'customer_name' => 'Bilal Khan']);

        $this->assertSame(['EV-A'], $this->numbersFrom($this->lookup(['q' => 'Sheikh'])));
    }

    public function test_search_finds_a_booking_by_phone(): void
    {
        $this->makeBooking(['event_no' => 'EV-A', 'customer_phone' => '03001234567']);
        $this->makeBooking(['event_no' => 'EV-B', 'customer_phone' => '03119999999']);

        $this->assertSame(['EV-A'], $this->numbersFrom($this->lookup(['q' => '1234567'])));
    }

    public function test_search_finds_a_booking_by_venue(): void
    {
        $this->makeBooking(['event_no' => 'EV-A', 'venue' => 'Glass Banquet Hall']);
        $this->makeBooking(['event_no' => 'EV-B', 'venue' => 'Marquee Gardens']);

        $this->assertSame(['EV-A'], $this->numbersFrom($this->lookup(['q' => 'Glass'])));
    }

    /** Deliveries have an address rather than a venue, and must still be found. */
    public function test_search_finds_a_booking_by_delivery_address(): void
    {
        $this->makeBooking(['event_no' => 'EV-A', 'venue' => null, 'customer_address' => 'House 12, Gulberg']);
        $this->makeBooking(['event_no' => 'EV-B', 'venue' => 'Somewhere Else']);

        $this->assertSame(['EV-A'], $this->numbersFrom($this->lookup(['q' => 'Gulberg'])));
    }

    /** Date and search compose — search does not escape the chosen day. */
    public function test_search_stays_inside_the_chosen_date(): void
    {
        $this->makeBooking(['event_no' => 'EV-TODAY', 'customer_name' => 'Mr Ali', 'event_date' => now()->toDateString()]);
        $this->makeBooking(['event_no' => 'EV-TOMORROW', 'customer_name' => 'Mr Ali', 'event_date' => now()->addDay()->toDateString()]);

        $results = $this->lookup(['date' => now()->toDateString(), 'q' => 'Ali']);

        $this->assertSame(['EV-TODAY'], $this->numbersFrom($results),
            'select-all acts on what this returned, so a filtered list must stay filtered');
    }

    // ── Eligibility ──────────────────────────────────────────────────────────

    /**
     * A cancelled booking cannot explain material leaving the store today. It
     * must never appear, because select-all would attach it without anyone
     * reading the row.
     */
    public function test_a_cancelled_booking_is_never_offered(): void
    {
        $this->makeBooking(['event_no' => 'EV-LIVE', 'status' => CateringEvent::STATUS_CONFIRMED]);
        $this->makeBooking(['event_no' => 'EV-DEAD', 'status' => CateringEvent::STATUS_CANCELLED]);

        $this->assertSame(['EV-LIVE'], $this->numbersFrom($this->lookup()));
    }

    public function test_a_closed_booking_is_never_offered(): void
    {
        $this->makeBooking(['event_no' => 'EV-LIVE', 'status' => CateringEvent::STATUS_CONFIRMED]);
        $this->makeBooking(['event_no' => 'EV-CLOSED', 'status' => CateringEvent::STATUS_CLOSED]);

        $this->assertSame(['EV-LIVE'], $this->numbersFrom($this->lookup()));
    }

    /** The row carries what someone at a counter needs to recognise a booking. */
    public function test_each_result_carries_enough_to_recognise_the_booking(): void
    {
        $this->makeBooking([
            'event_no' => 'EV-FULL', 'customer_name' => 'Mr Ali', 'customer_phone' => '0300111',
            'venue' => 'Glass Banquet', 'pax' => 500, 'service_time' => '19:00',
        ]);

        $row = $this->lookup()[0];

        foreach (['event_no', 'customer', 'phone', 'date', 'time', 'venue', 'pax', 'status'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertSame('Mr Ali', $row['customer']);
        $this->assertSame(500, $row['pax']);
        $this->assertSame('Glass Banquet', $row['venue']);
    }

    /** Twelve on one day is the shape this was built for. */
    public function test_a_busy_day_returns_every_booking_on_it(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->makeBooking(['event_no' => 'EV-BUSY-'.$i, 'customer_name' => "Customer {$i}"]);
        }

        $this->assertCount(12, $this->lookup(['date' => now()->toDateString()]));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenants')->where('tenant_code', 'cateringlookup')->delete();

        $tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'cateringlookup', 'business_name' => 'Catering Lookup HTTP',
            'owner_name' => 'Owner', 'owner_email' => 'owner@cateringlookup.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_databases')->insert([
            'tenant_id' => $tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_domains')->insert([
            'tenant_id' => $tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $plan = Plan::updateOrCreate(['code' => 'lookup-catering'], [
            'name' => 'Lookup Catering', 'price' => 0, 'is_active' => true,
        ]);
        PlanModule::where('plan_id', $plan->id)->delete();

        $module = Module::updateOrCreate(['key' => 'catering'], [
            'name' => 'Catering', 'category' => 'Operations',
            'route_module_keys' => ['tenant.catering'], 'is_core' => false, 'is_active' => true,
        ]);
        PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);

        Subscription::updateOrCreate(['tenant_id' => $tenantId], [
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addYear(),
        ]);

        $this->assertContains('catering', $plan->fresh()->loadMissing('enabledModules')->enabledModules->pluck('key')->all(),
            'the test plan must actually enable catering, or the authorization proof means nothing');
    }

    private function seedTenant(): void
    {
        // permissions/roles are NOT truncated: those rows come from a tenant
        // MIGRATION, so wiping them removes data no later test can restore.
        $this->cleanTenant([
            'catering_material_issue_events', 'catering_material_issue_lines', 'catering_material_issues',
            'catering_estimate_lines', 'catering_estimates', 'catering_refunds',
            'catering_advances', 'catering_events',
            'model_has_roles', 'products', 'categories', 'users', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $role = function (string $name) use ($c) {
            $id = $c->table('roles')->where('name', $name)->where('guard_name', 'tenant')->value('id');

            return $id ?: $c->table('roles')->insertGetId([
                'name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $ownerRole = $role('Owner');
        $cashierRole = $role('Cashier');

        $permId = $c->table('permissions')->where('name', self::PERM)->where('guard_name', 'tenant')->value('id');
        if (! $permId) {
            $permId = $c->table('permissions')->insertGetId([
                'name' => self::PERM, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        $c->table('role_has_permissions')
            ->where('permission_id', $permId)->where('role_id', $cashierRole)->delete();

        $this->ownerId = $this->userWithRole($c, 'LKOWN', $ownerRole);
        $this->cashierId = $this->userWithRole($c, 'LKCASH', $cashierRole);

        $this->branchId = $this->makeBranch();

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWithRole($c, string $code, int $roleId): int
    {
        $uid = $c->table('users')->insertGetId([
            'name' => $code, 'email' => strtolower($code).'@cateringlookup.test',
            'password' => bcrypt('x'), 'employee_code' => $code, 'status' => 'active',
            'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $uid]);

        return $uid;
    }
}
