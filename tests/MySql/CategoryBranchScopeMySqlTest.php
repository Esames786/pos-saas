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
 * CATEGORY-BRANCH-SCOPE-1 — a category may belong to one branch, and the POS grid honours it.
 *
 * Two things have to be true at once, and the second is the one that bites:
 *
 *  1. A tenant whose branches sell different menus gets one menu per counter.
 *  2. A tenant whose categories are all NULL — which is EVERY tenant that exists today — sees
 *     absolutely no change. That is what protects Khatri Biryani and Kashif Food, and it is the
 *     first test in this file for that reason.
 *
 * The filter sits INSIDE the `is_pos_visible` branch of the payload query, never as its own
 * `->where()`. Bolted on outside it would also apply to the two escape hatches that keep combo
 * components and open-bill products in the payload — and dropping an open bill's product is
 * precisely the outage that made five bills unpayable mid-service on 30 August. Tests 4 and 5
 * exist to make sure that can never be quietly undone.
 *
 * Every assertion runs over real HTTP against the POS screen. A test that rebuilds the query
 * cannot fail when the query is wrong.
 */
class CategoryBranchScopeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $branchA;
    private int $branchB;
    private int $catA;
    private int $catB;
    private int $catShared;
    private int $productA;
    private int $productB;
    private int $productShared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'catscope.' . config('tenancy.tenant_base_domain');
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
            $m->table('tenants')->where('tenant_code', 'catscope')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    /** Ask for the POS screen the way a cashier's browser does. */
    private function posPayload(int $branchId): array
    {
        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->get('http://' . $this->host . '/pos?branch_id=' . $branchId);

        $this->assertSame(200, $res->getStatusCode(),
            'the POS screen must load; got ' . $res->getStatusCode() . ' — '
            . Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($res->getContent()))), 300));

        $html = $res->getContent();

        // Decode the actual payload rather than grepping the page: `"id":5` appears in the
        // categories JSON too, and a category id that happens to equal a product id would make
        // this test pass while the grid was wrong.
        $this->assertSame(1, preg_match('/const products\s*=\s*(\[.*?\]);\s*$/m', $html, $m),
            'the POS page must embed a products payload, or this test proves nothing');

        $products = json_decode($m[1], true);
        $this->assertIsArray($products, 'the products payload must be valid JSON');

        return array_map(fn ($p) => (int) $p['id'], $products);
    }

    private function assertGridHas(int $branchId, array $expected, array $forbidden, string $why): void
    {
        $ids = $this->posPayload($branchId);

        foreach ($expected as $label => $id) {
            $this->assertContains($id, $ids,
                "{$why}: expected [{$label}] (product {$id}) in the branch {$branchId} payload");
        }
        foreach ($forbidden as $label => $id) {
            $this->assertNotContains($id, $ids,
                "{$why}: [{$label}] (product {$id}) must NOT be in the branch {$branchId} payload");
        }
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * 1. THE ONE THAT PROTECTS THE LIVE TENANTS
     * ──────────────────────────────────────────────────────────────────────── */

    /**
     * Every category NULL — the state of every tenant in production. Both branches must see
     * everything, exactly as they did before this column existed.
     */
    public function test_a_tenant_whose_categories_are_all_shared_sees_no_change(): void
    {
        DB::connection('tenant')->table('categories')->update(['branch_id' => null]);

        foreach ([$this->branchA, $this->branchB] as $branchId) {
            $this->assertGridHas($branchId, [
                'A product'      => $this->productA,
                'B product'      => $this->productB,
                'shared product' => $this->productShared,
            ], [], 'with every category shared, nothing may be filtered out');
        }
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * 2-3. THE FEATURE ITSELF
     * ──────────────────────────────────────────────────────────────────────── */

    /** Two branches, two menus: neither counter is handed the other's products. */
    public function test_each_branch_sees_only_its_own_menu(): void
    {
        $this->assertGridHas($this->branchA,
            ['A product' => $this->productA],
            ['B product' => $this->productB],
            'branch A must not be shown branch B menu');

        $this->assertGridHas($this->branchB,
            ['B product' => $this->productB],
            ['A product' => $this->productA],
            'branch B must not be shown branch A menu');
    }

    /** A category with no branch belongs to everyone — the ice-cream case. */
    public function test_a_shared_category_appears_at_both_branches(): void
    {
        foreach ([$this->branchA, $this->branchB] as $branchId) {
            $this->assertGridHas($branchId, ['shared product' => $this->productShared], [],
                'a category with branch_id NULL must show at every branch');
        }
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * 4-5. THE ESCAPE HATCHES — the 30 August outage, restated for this filter
     * ──────────────────────────────────────────────────────────────────────── */

    /**
     * A product sitting on an OPEN bill must stay in the payload even when its category belongs
     * to another branch. Recall resolves every line against this payload; drop the product and
     * the bill can no longer be recalled or paid.
     */
    public function test_a_product_on_an_open_bill_survives_the_branch_filter(): void
    {
        $saleId = $this->makeSale($this->branchA, ['status' => 'held', 'order_type' => 'takeaway']);
        $this->makeSaleLine($saleId, $this->productB, ['quantity' => 2]);

        $this->assertGridHas($this->branchA, ['B product on an open bill at A' => $this->productB], [],
            'a product on an open bill must never be filtered out — that is the 30 Aug outage');
    }

    /** Same guarantee for a combo component that belongs to the other branch. */
    public function test_a_combo_component_survives_the_branch_filter(): void
    {
        $c = DB::connection('tenant');
        $comboId = $c->table('combos')->insertGetId([
            'branch_id' => $this->branchA, 'code' => 'CB-SCOPE', 'name' => 'Scope Combo',
            'price' => 500, 'sort_order' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('combo_components')->insert([
            'combo_id' => $comboId, 'product_id' => $this->productB, 'quantity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertGridHas($this->branchA, ['B product as a combo component at A' => $this->productB], [],
            'a combo component must stay in the payload or the combo reads as out of stock');
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * 6-7. EDGES
     * ──────────────────────────────────────────────────────────────────────── */

    /** A product with no category at all belongs nowhere in particular — so it shows everywhere. */
    public function test_a_product_with_no_category_still_shows(): void
    {
        $c = DB::connection('tenant');
        $orphanId = $this->makeProduct($this->catShared, ['name' => 'Orphan Item ' . Str::random(4)]);
        $c->table('products')->where('id', $orphanId)->update(['category_id' => null]);

        foreach ([$this->branchA, $this->branchB] as $branchId) {
            $this->assertGridHas($branchId, ['uncategorised product' => $orphanId], [],
                'a product with no category must not be filtered out by a category rule');
        }
    }

    /**
     * The other branch's TAB goes with its products. Nothing was written to make this happen —
     * the pill set is already derived from what is in the payload (POS-COMBO-CATEGORY-1 +
     * HIDE-EMPTY-TABS), so an emptied category stops rendering on its own. This test exists to
     * prove that inherited behaviour still holds once the payload is branch-filtered.
     *
     * Asserted on the rendered tab markup, because that is what the cashier actually sees.
     */
    public function test_the_other_branch_category_tab_is_gone(): void
    {
        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->get('http://' . $this->host . '/pos?branch_id=' . $this->branchB);
        $this->assertSame(200, $res->getStatusCode());
        $html = $res->getContent();

        $tabFor = fn (int $id) => (bool) preg_match(
            '/class="category-pill"\s+data-parent-category="' . $id . '"/', $html);

        $this->assertFalse($tabFor($this->catA),
            "branch A category {$this->catA} must not render a tab at branch B");
        $this->assertTrue($tabFor($this->catB),
            "branch B must still render its own category tab {$this->catB}");
        $this->assertTrue($tabFor($this->catShared),
            "the shared category {$this->catShared} must render a tab at every branch");
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * seeding
     * ──────────────────────────────────────────────────────────────────────── */

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'catscope')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'catscope', 'business_name' => 'Cat Scope',
            'owner_name' => 'Owner', 'owner_email' => 'owner@catscope.test',
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

        $planId = $m->table('plans')->where('code', 'catscope-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'catscope-plan', 'name' => 'Cat Scope', 'price' => 0,
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
            'sales_order_lines', 'sales_orders', 'combo_components', 'combos',
            'model_has_roles', 'users', 'products', 'categories', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        $c->table('permissions')->updateOrInsert(
            ['name' => 'tenant.pos.index', 'guard_name' => 'tenant'],
            ['created_at' => now(), 'updated_at' => now()]
        );
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'ScopeOwner', 'email' => 'scopeowner@catscope.test', 'password' => bcrypt('x'),
            'employee_code' => 'SCOPEOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        // Two branches selling different menus, plus something they share.
        $this->branchA = $this->makeBranch(['name' => 'Branch A']);
        $this->branchB = $this->makeBranch(['name' => 'Branch B']);

        $this->catA = $this->makeCategory(['name' => 'A Only', 'slug' => 'a-only-' . Str::random(4)]);
        $this->catB = $this->makeCategory(['name' => 'B Only', 'slug' => 'b-only-' . Str::random(4)]);
        $this->catShared = $this->makeCategory(['name' => 'Shared', 'slug' => 'shared-' . Str::random(4)]);

        $c->table('categories')->where('id', $this->catA)->update(['branch_id' => $this->branchA]);
        $c->table('categories')->where('id', $this->catB)->update(['branch_id' => $this->branchB]);
        $c->table('categories')->where('id', $this->catShared)->update(['branch_id' => null]);

        $this->productA = $this->makeProduct($this->catA, ['name' => 'Only At A ' . Str::random(4)]);
        $this->productB = $this->makeProduct($this->catB, ['name' => 'Only At B ' . Str::random(4)]);
        $this->productShared = $this->makeProduct($this->catShared, ['name' => 'Sold Everywhere ' . Str::random(4)]);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
