<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * The Held Orders list must say who is carrying each delivery, and on whose behalf.
 *
 * The counter reads that list to decide what to chase. A row that says only "Delivery" does
 * not distinguish an order Foodpanda is already collecting from one still sitting with no
 * rider attached — the two need opposite actions.
 *
 * Both the Held list and the Recent list render their sub-line through ONE JS helper
 * (posOrderMeta), so BOTH controllers have to feed it the same keys; a field added to one
 * payload only would show on one screen and silently vanish on the other. That is the same
 * shape as the sale-field wiring rule (`67efbc1`) — every producer, not just the nearest one.
 *
 * So this test calls both endpoints over real HTTP and insists the keys are there with the
 * right values, rather than re-deriving them from the tables.
 */
class PosHeldListDeliveryMetaMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $branchId;
    private int $productId;
    private int $ownChannelId;
    private int $pandaChannelId;
    private int $riderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'heldmeta.' . config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedSubscription();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'heldmeta')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    private function heldList(): array
    {
        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->get('http://' . $this->host . '/api/pos/held-sales?branch_id=' . $this->branchId);

        $this->assertSame(200, $res->getStatusCode(),
            'the held-orders list must load; got ' . $res->getStatusCode() . ' — '
            . Str::limit(strip_tags($res->getContent()), 300));

        return $res->json('sales') ?? [];
    }

    private function recentList(): array
    {
        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->get('http://' . $this->host . '/api/pos/recent-sales?branch_id=' . $this->branchId);

        $this->assertSame(200, $res->getStatusCode(),
            'the recent-sales list must load; got ' . $res->getStatusCode() . ' — '
            . Str::limit(strip_tags($res->getContent()), 300));

        return $res->json('sales') ?? [];
    }

    private function rowFor(array $sales, string $saleNo): array
    {
        foreach ($sales as $row) {
            if (($row['sale_no'] ?? null) === $saleNo) {
                return $row;
            }
        }

        $this->fail("sale [{$saleNo}] is missing from the list — the payload cannot carry meta for a row that is not there");
    }

    private function makeDelivery(string $saleNo, ?int $channelId, ?int $riderId, string $status = 'held'): void
    {
        $id = $this->makeSale($this->branchId, [
            'sale_no'             => $saleNo,
            'status'              => $status,
            'order_type'          => 'delivery',
            'delivery_channel_id' => $channelId,
            'delivery_rider_id'   => $riderId,
            'customer_name'       => 'Munazza',
            'grand_total'         => 1150,
        ]);
        $this->makeSaleLine($id, $this->productId, ['quantity' => 1]);
    }

    /** An own-delivery order names the channel AND the rider carrying it. */
    public function test_an_own_delivery_order_carries_its_channel_and_its_rider(): void
    {
        $this->makeDelivery('HS-OWN-WITH-RIDER', $this->ownChannelId, $this->riderId);

        $row = $this->rowFor($this->heldList(), 'HS-OWN-WITH-RIDER');

        $this->assertSame('Own Delivery', $row['delivery_channel'] ?? null,
            'the list must name the channel that took the order');
        $this->assertSame('own', $row['delivery_channel_type'] ?? null,
            'the channel TYPE decides whether a rider is expected at all');
        $this->assertSame('Moeen', $row['delivery_rider'] ?? null,
            'the attached rider must reach the list, or the counter cannot tell who has the food');
    }

    /**
     * An aggregator sends its own rider, so the rider stays empty ON PURPOSE — but the
     * channel must still be named, otherwise Foodpanda and own-delivery read identically.
     */
    public function test_an_aggregator_order_names_the_channel_and_leaves_the_rider_empty(): void
    {
        $this->makeDelivery('HS-PANDA', $this->pandaChannelId, null);

        $row = $this->rowFor($this->heldList(), 'HS-PANDA');

        $this->assertSame('Foodpanda', $row['delivery_channel'] ?? null);
        $this->assertSame('aggregator', $row['delivery_channel_type'] ?? null,
            'without the type the screen would demand a rider for an order Foodpanda is already carrying');
        $this->assertNull($row['delivery_rider'] ?? null);
    }

    /** Own delivery with nobody attached yet: the channel is known, the rider is honestly null. */
    public function test_an_own_delivery_order_with_no_rider_yet_reports_a_null_rider(): void
    {
        $this->makeDelivery('HS-OWN-NO-RIDER', $this->ownChannelId, null);

        $row = $this->rowFor($this->heldList(), 'HS-OWN-NO-RIDER');

        $this->assertSame('Own Delivery', $row['delivery_channel'] ?? null);
        $this->assertNull($row['delivery_rider'] ?? null,
            'an unassigned order must not borrow another row rider');
    }

    /** A non-delivery order has no channel to name — and must not invent one. */
    public function test_a_takeaway_order_carries_no_delivery_meta(): void
    {
        $id = $this->makeSale($this->branchId, [
            'sale_no' => 'HS-TAKEAWAY', 'status' => 'held', 'order_type' => 'takeaway',
        ]);
        $this->makeSaleLine($id, $this->productId, ['quantity' => 1]);

        $row = $this->rowFor($this->heldList(), 'HS-TAKEAWAY');

        $this->assertNull($row['delivery_channel'] ?? null);
        $this->assertNull($row['delivery_rider'] ?? null);
    }

    /**
     * The other producer. Recent Orders renders through the SAME helper, so if this payload
     * lacks the keys the channel silently disappears from that screen alone.
     */
    public function test_the_recent_orders_list_feeds_the_same_keys(): void
    {
        $this->makeDelivery('SO-PAID-OWN', $this->ownChannelId, $this->riderId, 'paid');

        $row = $this->rowFor($this->recentList(), 'SO-PAID-OWN');

        $this->assertArrayHasKey('delivery_channel', $row,
            'Recent Orders shares posOrderMeta() with the Held list — both payloads must carry the same keys');
        $this->assertSame('Own Delivery', $row['delivery_channel']);
        $this->assertSame('own', $row['delivery_channel_type'] ?? null);
        $this->assertSame('Moeen', $row['delivery_rider'] ?? null);
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'heldmeta')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'heldmeta', 'business_name' => 'Held Meta',
            'owner_name' => 'Owner', 'owner_email' => 'owner@heldmeta.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedSubscription(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'heldmeta-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'heldmeta-plan', 'name' => 'Held Meta', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        $routeModuleKey = $m->table('route_catalogs')->where('route_name', 'tenant.pos.index')->value('module_key');
        $module = $routeModuleKey ? Module::forRouteModuleKey($routeModuleKey)->first() : null;
        if ($routeModuleKey) {
            $this->assertNotNull($module,
                "route [tenant.pos.index] maps to [{$routeModuleKey}] but no module claims it");
            $m->table('plan_modules')->insert([
                'plan_id' => $planId, 'module_id' => $module->id, 'is_enabled' => 1,
            ]);
        }

        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTenant(): void
    {
        // permissions/roles come from a tenant MIGRATION — never truncate them.
        $this->cleanTenant([
            'sales_order_lines', 'sales_orders', 'delivery_riders', 'delivery_channels',
            'model_has_roles', 'users', 'products', 'categories', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        foreach (['tenant.pos.index', 'tenant.api.pos.held-sales', 'tenant.api.pos.recent-sales'] as $perm) {
            $c->table('permissions')->updateOrInsert(
                ['name' => $perm, 'guard_name' => 'tenant'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'HeldOwner', 'email' => 'heldowner@heldmeta.test', 'password' => bcrypt('x'),
            'employee_code' => 'HELDOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $this->branchId = $this->makeBranch();
        $category = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice-' . Str::random(4)]);
        $this->productId = $this->makeProduct($category, ['name' => 'Singaporean Rice (Regular)']);

        // The two shapes the counter has to tell apart.
        $this->ownChannelId = $c->table('delivery_channels')->insertGetId([
            'name' => 'Own Delivery', 'type' => 'own', 'commission_percent' => 0,
            'is_active' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->pandaChannelId = $c->table('delivery_channels')->insertGetId([
            'name' => 'Foodpanda', 'type' => 'aggregator', 'commission_percent' => 15,
            'is_active' => 1, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->riderId = $c->table('delivery_riders')->insertGetId([
            'branch_id' => $this->branchId, 'name' => 'Moeen', 'phone' => '03001234567',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
