<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\ProductController;
use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Tenant\Product;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRODUCT-LIST-EVERY-KIND-1 — Catalog → Products hides ingredients and factory items because
 * Manufacturing Products / Catering Materials list them. A restaurant plan has neither, yet its Add
 * screen offers "Ingredient / Raw Material": Khatri's ICE-BERG saved, and then no screen showed it.
 * With no other list for this plan and role, the catalog lists every kind. Real controller, real route.
 */
class ProductListEveryKindMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $categoryId;

    private const OWNER_PERMISSIONS = [
        'tenant.products.index',
        'tenant.catering.materials.index',
        'tenant.manufacturing.bom.index',
        'tenant.manufacturing.products.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['product_variants', 'products', 'categories']);
        $this->categoryId = $this->makeCategory();
        view()->share('errors', new ViewErrorBag);
        $this->dropTestTenants();

        $menu = ['product_kind' => Product::KIND_SALE_ITEM, 'product_type' => 'simple', 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active'];
        $this->makeProduct($this->categoryId, ['name' => 'Chicken Biryani', 'is_purchasable' => 0, 'is_stock_tracked' => 0] + $menu);
        $this->makeProduct($this->categoryId, ['name' => 'Container 100 ML', 'product_kind' => Product::KIND_PACKAGING_MATERIAL, 'product_type' => 'service',
            'is_sellable' => 0, 'is_pos_visible' => 0, 'is_purchasable' => 1, 'is_stock_tracked' => 1, 'status' => 'active']);
        // As Khatri saved them: a salad leaf bought, never sold, not counted; a crate of drinks as a Finished Good.
        $this->makeProduct($this->categoryId, ['name' => 'ICE-BERG', 'product_kind' => Product::KIND_RAW_MATERIAL, 'product_type' => 'simple',
            'is_sellable' => 0, 'is_pos_visible' => 0, 'is_purchasable' => 1, 'is_stock_tracked' => 0, 'can_be_bom_component' => 1, 'status' => 'active']);
        $this->makeProduct($this->categoryId, ['name' => 'Pakola 500ml (crate)', 'product_kind' => Product::KIND_FINISHED_GOOD, 'product_type' => 'simple',
            'is_sellable' => 0, 'is_pos_visible' => 0, 'is_purchasable' => 1, 'is_stock_tracked' => 1, 'can_be_bom_output' => 1, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant');
        $this->dropTestTenants();
        parent::tearDown();
    }

    /** Master rows outlive a test; left behind they break later suites. */
    private function dropTestTenants(): void
    {
        $master = DB::connection('master');
        $tenantIds = $master->table('tenants')->where('tenant_code', 'like', 'kindtest%')->pluck('id');
        $master->table('subscriptions')->whereIn('tenant_id', $tenantIds)->delete();
        $master->table('tenants')->whereIn('id', $tenantIds)->delete();
        $planIds = $master->table('plans')->where('code', 'like', 'kindtest-%')->pluck('id');
        $master->table('plan_modules')->whereIn('plan_id', $planIds)->delete();
        $master->table('plans')->whereIn('id', $planIds)->delete();
    }

    /** Bind a tenant whose plan has exactly these modules, and sign in a user with these permissions. */
    private function onPlan(array $moduleKeys, array $permissions = self::OWNER_PERMISSIONS): void
    {
        $plan = Plan::create(['code' => 'kindtest-' . uniqid(), 'name' => 'Kind Test Plan', 'price' => 0, 'is_active' => true]);
        foreach ($moduleKeys as $key) {
            // A real module row, created only if the master test DB lacks it — never overwriting a seeded one.
            $module = Module::firstOrCreate(['key' => $key], [
                'name' => ucfirst($key), 'category' => 'Test', 'description' => $key,
                'route_module_keys' => ['tenant.' . $key], 'sort_order' => 900, 'is_core' => false, 'is_active' => true,
            ]);
            PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);
        }
        $tenant = Tenant::create(['tenant_code' => 'kindtest' . Str::lower(Str::random(6)), 'business_name' => 'Kind Test', 'status' => 'active']);
        Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
        app()->instance('tenant', $tenant->fresh());

        $user = User::on('tenant')->find($this->makeUser());
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'tenant');
        }
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        auth()->shouldUse('tenant');
        auth()->setUser(User::on('tenant')->find($user->id));
    }

    private function onRoute(string $routeName, string $method = 'GET', array $input = []): Request
    {
        $route = app('router')->getRoutes()->getByName($routeName);
        $this->assertNotNull($route, $routeName);
        $request = Request::create('/' . $route->uri(), $method, $input);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function page(string $routeName = 'tenant.products.index', array $params = [])
    {
        return app(ProductController::class)->index($this->onRoute($routeName, 'GET', $params));
    }

    private function listed(string $routeName = 'tenant.products.index', array $params = []): array
    {
        return collect($this->page($routeName, $params)->getData()['products']->items())->pluck('name')->sort()->values()->all();
    }

    public function test_a_restaurant_plan_lists_its_ingredients_on_products(): void
    {
        $this->onPlan(['catalog', 'pos', 'restaurant', 'inventory', 'purchasing']);

        $this->assertSame(['Chicken Biryani', 'Container 100 ML', 'ICE-BERG', 'Pakola 500ml (crate)'], $this->listed(),
            'no other screen lists them, so Products must');
        $this->assertSame(['ICE-BERG'], $this->listed(params: ['search' => 'ICE-BERG']));
        $this->assertSame(['ICE-BERG'], $this->listed(params: ['roles' => ['raw_material']]));

        $view = $this->page();
        $this->assertArrayHasKey('raw_material', $view->getData()['roleOptions'], 'the Role filter offers Ingredient');
        $this->assertArrayHasKey('finished_good', $view->getData()['roleOptions']);
        $html = $view->render();
        $iceberg = Product::where('name', 'ICE-BERG')->value('id');
        $this->assertMatchesRegularExpression('#/products/' . $iceberg . '(/edit)?["\'?]#', $html, 'a row of its own, not just the search box');
        $this->assertStringContainsString('Ingredients and packing materials are listed here too', $html);
        $this->assertStringNotContainsString('Raw materials are managed under', $html);
    }

    public function test_an_ingredient_saved_from_products_shows_on_products(): void
    {
        $this->onPlan(['catalog', 'pos', 'restaurant', 'inventory', 'purchasing']);
        $response = app(ProductController::class)->store($this->onRoute('tenant.products.store', 'POST', [
            'sku' => 'LETTUCE-1', 'name' => 'Lettuce Leaf', 'category_id' => $this->categoryId, 'product_type' => 'simple',
            'product_kind' => 'raw_material', 'is_purchasable' => 1, 'default_selling_price' => 0, 'status' => 'active',
        ]));
        $id = Product::where('sku', 'LETTUCE-1')->value('id');
        $this->assertNotNull($id, 'saved');
        $this->assertSame('/products/' . $id, parse_url($response->getTargetUrl(), PHP_URL_PATH));

        $this->assertSame(['Lettuce Leaf'], $this->listed(params: ['search' => 'Lettuce']), 'and the list it came from shows it');
    }

    public function test_a_catering_plan_keeps_ingredients_on_catering_materials(): void
    {
        $this->onPlan(['catering', 'inventory', 'purchasing']);

        $this->assertSame(['Chicken Biryani', 'Container 100 ML'], $this->listed(), 'a kitchen keeps its dishes list clean');
        $this->assertContains('ICE-BERG', $this->listed('tenant.catering.materials.index'), 'the ingredient lives on Catering Materials');
        $html = $this->page()->render();
        $this->assertStringNotContainsString('Ingredients and packing materials are listed here too', $html);
        $this->assertStringContainsString('Raw materials are managed under Catering &gt; Materials', $html);
    }

    public function test_a_manufacturing_plan_keeps_them_on_manufacturing_products(): void
    {
        $this->onPlan(['catalog', 'pos', 'restaurant', 'manufacturing']);

        $this->assertSame(['Chicken Biryani', 'Container 100 ML'], $this->listed());
        $this->assertSame(['ICE-BERG', 'Pakola 500ml (crate)'], $this->listed('tenant.manufacturing.products.index'));
    }

    public function test_a_role_that_cannot_open_the_materials_list_sees_them_on_products(): void
    {
        // The plan has Catering Materials, this user's role does not: Products is the only list they have.
        $this->onPlan(['catering', 'inventory', 'purchasing'], ['tenant.products.index']);

        $this->assertSame(['Chicken Biryani', 'Container 100 ML', 'ICE-BERG', 'Pakola 500ml (crate)'], $this->listed());
    }
}
