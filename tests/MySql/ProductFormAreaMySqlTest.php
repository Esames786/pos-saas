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
 * PRODUCT-FORM-AREA-1 — the product form asks which part of the business a product is for
 * (Restaurant / Catering / Manufacturing) before its type, and offers only what the plan AND the
 * role allow.
 *
 * Two things went wrong before it. From 29 Sep the form's whole script failed to parse (a raw line
 * break inside a quoted confirm() string), so no card did anything and every field showed — BOM
 * boxes on a restaurant's cola. And "manufacturing available" read only the permission, which
 * deploy.sh hands every Owner whatever the plan: a restaurant's crate of Pakola became a Finished
 * Good. These render the REAL blade through the REAL controller and route.
 */
class ProductFormAreaMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $categoryId;

    private const ALL_AREA_PERMISSIONS = [
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
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant');
        $this->dropTestTenants();
        parent::tearDown();
    }

    /** Master rows outlive a test; left behind they break later suites (seen with TrialBalanceParty). */
    private function dropTestTenants(): void
    {
        $master = DB::connection('master');
        $tenantIds = $master->table('tenants')->where('tenant_code', 'like', 'areatest%')->pluck('id');
        $master->table('subscriptions')->whereIn('tenant_id', $tenantIds)->delete();
        $master->table('tenants')->whereIn('id', $tenantIds)->delete();
        $planIds = $master->table('plans')->where('code', 'like', 'areatest-%')->pluck('id');
        $master->table('plan_modules')->whereIn('plan_id', $planIds)->delete();
        $master->table('plans')->whereIn('id', $planIds)->delete();
    }

    /** Bind a tenant whose plan has exactly these modules, and sign in a user with these permissions. */
    private function onPlan(array $moduleKeys, array $permissions = self::ALL_AREA_PERMISSIONS): void
    {
        $plan = Plan::create(['code' => 'areatest-' . uniqid(), 'name' => 'Area Test Plan', 'price' => 0, 'is_active' => true]);
        foreach ($moduleKeys as $key) {
            // A real module row, created only if the master test DB lacks it — never overwriting a seeded one.
            $module = Module::firstOrCreate(['key' => $key], [
                'name' => ucfirst($key), 'category' => 'Test', 'description' => $key,
                'route_module_keys' => ['tenant.' . $key], 'sort_order' => 900, 'is_core' => false, 'is_active' => true,
            ]);
            PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);
        }
        $code = 'areatest' . Str::lower(Str::random(6));
        $tenant = Tenant::create(['tenant_code' => $code, 'business_name' => 'Area Test', 'status' => 'active']);
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
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function create(string $routeName = 'tenant.products.create'): string
    {
        return app(ProductController::class)->create($this->onRoute($routeName))->render();
    }

    private function edit(array $attrs, string $routeName = 'tenant.products.edit'): string
    {
        $product = Product::on('tenant')->findOrFail($this->makeProduct($this->categoryId, $attrs));

        return app(ProductController::class)->edit($this->onRoute($routeName), $product)->render();
    }

    /** Areas offered, in order, and the one ticked. */
    private function areas(string $html): array
    {
        preg_match_all('/name="_area" id="parea-[a-z]+" value="([a-z]+)"([^>]*)>/', $html, $m, PREG_SET_ORDER);

        return [
            array_map(fn ($x) => $x[1], $m),
            collect($m)->first(fn ($x) => str_contains($x[2], 'checked'))[1] ?? null,
        ];
    }

    /** Cards rendered for an area. */
    private function cards(string $html, string $area): array
    {
        preg_match_all('/data-area="' . $area . '" data-mode="([a-z_]+)"/', $html, $m);

        return $m[1];
    }

    private function mode(string $html): string
    {
        preg_match('/name="_setup_mode"[^>]*value="([a-z_]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    private function roleOption(string $html, string $kind): string
    {
        preg_match('/<option value="' . $kind . '"[^>]*>/', $html, $m);

        return $m[0] ?? '';
    }

    public function test_every_script_on_the_form_parses(): void
    {
        $node = $this->nodeBinary();
        $pages = [
            'catalog create'      => $this->create(),
            'catalog edit'        => $this->edit(['product_kind' => 'sale_item']),
            'manufacturing edit'  => $this->edit(['product_kind' => 'finished_good', 'can_be_bom_output' => 1], 'tenant.manufacturing.products.edit'),
            'catering materials'  => $this->create('tenant.catering.materials.create'),
        ];

        foreach ($pages as $page => $html) {
            preg_match_all('#<script([^>]*)>(.*?)</script>#s', $html, $m, PREG_SET_ORDER);
            // Inline JavaScript only: no src, and no data type such as application/json.
            $scripts = array_filter($m, fn ($s) => ! str_contains($s[1], 'src=')
                && (! preg_match('/type="([^"]+)"/', $s[1], $t) || in_array($t[1], ['text/javascript', 'module'], true)));
            $this->assertTrue(collect($scripts)->contains(fn ($s) => str_contains($s[2], 'var MODES')),
                "{$page}: the form's own script was not found — this check would prove nothing");

            foreach ($scripts as $i => $script) {
                $tmp = tempnam(sys_get_temp_dir(), 'pform');
                file_put_contents($tmp . '.js', $script[2]);
                $out = [];
                exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp . '.js') . ' 2>&1', $out, $code);
                @unlink($tmp . '.js');
                @unlink($tmp);
                $this->assertSame(0, $code, "{$page}: inline script #{$i} does not parse — the whole block is dead in the browser:\n" . implode("\n", $out));
            }
        }
    }

    public function test_a_restaurant_plan_never_offers_manufacturing_even_to_an_owner_holding_its_permissions(): void
    {
        $this->onPlan(['catalog', 'pos', 'restaurant', 'purchasing']);
        $html = $this->create();

        $this->assertSame([['restaurant'], 'restaurant'], $this->areas($html));
        $this->assertSame([], $this->cards($html, 'manufacturing'));
        $this->assertStringNotContainsString('Manufacturing Raw Material', $html);
        $this->assertStringNotContainsString('Manufacturing Finished Good', $html);
        $this->assertMatchesRegularExpression('/hidden disabled/', $this->roleOption($html, 'finished_good'),
            'a restaurant is not offered the Finished Good role');
        $this->assertSame(['pos_sale', 'recipe', 'raw_material', 'packaging', 'service', 'advanced'], $this->cards($html, 'restaurant'));
    }

    public function test_manufacturing_needs_the_plan_and_the_role(): void
    {
        $this->onPlan(['catalog', 'pos', 'restaurant', 'manufacturing']);
        $html = $this->create();
        $this->assertSame([['restaurant', 'manufacturing'], 'restaurant'], $this->areas($html));
        $this->assertSame(['mfg_raw', 'mfg_fg', 'advanced'], $this->cards($html, 'manufacturing'));
        $this->assertMatchesRegularExpression('/data-area="manufacturing" data-mode="mfg_raw"\s+hidden/', $html,
            'the other area\'s cards are rendered but hidden until it is picked');

        $this->onPlan(['catalog', 'pos', 'restaurant', 'manufacturing'], ['tenant.products.index']);
        $this->assertSame([['restaurant'], 'restaurant'], $this->areas($this->create()), 'plan without the role is not enough');
    }

    public function test_a_catering_plan_gets_the_same_types_in_a_kitchens_words(): void
    {
        $this->onPlan(['catalog', 'catering', 'inventory', 'purchasing']);
        $html = $this->create();

        $this->assertSame([['catering'], 'catering'], $this->areas($html));
        $this->assertSame(['pos_sale', 'recipe', 'raw_material', 'packaging', 'service', 'advanced'], $this->cards($html, 'catering'));
        $this->assertStringContainsString('Catering Dish', $html);
        $this->assertStringNotContainsString('POS Sale Item', $html);
        $this->assertStringNotContainsString('Manufacturing Raw Material', $html);
    }

    public function test_catering_materials_stays_catering_only_whatever_else_the_plan_has(): void
    {
        $this->onPlan(['catalog', 'pos', 'restaurant', 'catering', 'manufacturing']);
        $html = $this->create('tenant.catering.materials.create');

        $this->assertSame([['catering'], 'catering'], $this->areas($html),
            'that form renders no Sale Item role and no POS Visible box, so it can hold nothing else');
        $this->assertSame(['raw_material', 'packaging'], $this->cards($html, 'catering'));
        $this->assertSame('raw_material', $this->mode($html));
    }

    public function test_edit_opens_on_the_products_own_area(): void
    {
        $this->onPlan(['catalog', 'pos', 'restaurant', 'manufacturing']);
        $fg = ['product_kind' => 'finished_good', 'is_sellable' => 0, 'is_pos_visible' => 0, 'can_be_bom_output' => 1, 'is_manufactured_finished_good' => 1];
        $html = $this->edit($fg);
        $this->assertSame('manufacturing', $this->areas($html)[1]);
        $this->assertSame('mfg_fg', $this->mode($html));

        $raw = ['product_kind' => 'raw_material', 'is_sellable' => 0, 'is_pos_visible' => 0, 'can_be_bom_component' => 1];
        $html = $this->edit($raw, 'tenant.manufacturing.products.edit');
        $this->assertSame(['manufacturing', 'mfg_raw'], [$this->areas($html)[1], $this->mode($html)]);
        $html = $this->edit($raw);
        $this->assertSame(['restaurant', 'raw_material'], [$this->areas($html)[1], $this->mode($html)],
            'the same raw material is an Ingredient when opened from the catalog');

        $html = $this->edit(['product_kind' => 'sale_item']);
        $this->assertSame(['restaurant', 'pos_sale'], [$this->areas($html)[1], $this->mode($html)]);
    }

    public function test_a_manufacturing_role_on_a_plan_without_manufacturing_opens_where_it_can_be_fixed(): void
    {
        // Khatri #68, 6 Oct: a crate of Pakola saved as a Finished Good while the form showed every field.
        $this->onPlan(['catalog', 'pos', 'restaurant', 'purchasing']);
        $html = $this->edit(['product_kind' => 'finished_good', 'product_type' => 'service', 'is_sellable' => 0, 'is_pos_visible' => 0, 'is_stock_tracked' => 1]);

        $this->assertSame([['restaurant'], 'restaurant'], $this->areas($html));
        $this->assertSame('advanced', $this->mode($html), 'Advanced puts the Role on screen to change');
        $option = $this->roleOption($html, 'finished_good');
        $this->assertStringContainsString('selected', $option);
        $this->assertStringNotContainsString('disabled', $option,
            'a disabled selected option is not posted — saving would silently make it a Sale Item');
    }

    public function test_saving_from_the_manufacturing_screen_lands_where_the_product_shows(): void
    {
        $this->onPlan(['catalog', 'pos', 'restaurant', 'manufacturing']);
        $save = function (array $payload): array {
            $sku = 'AREA-' . Str::upper(Str::random(6));
            $response = app(ProductController::class)->store($this->onRoute('tenant.manufacturing.products.store', 'POST', array_merge([
                'sku' => $sku, 'name' => 'Area ' . $sku, 'category_id' => $this->categoryId, 'product_type' => 'simple',
                'default_selling_price' => 100, 'status' => 'active',
            ], $payload)));

            return [parse_url($response->getTargetUrl(), PHP_URL_PATH), Product::where('sku', $sku)->value('id')];
        };

        [$path, $id] = $save(['product_kind' => 'sale_item', 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_purchasable' => 1]);
        $this->assertSame('/products/' . $id, $path, 'a POS item never shows on the manufacturing list');

        [$path] = $save(['product_kind' => 'raw_material', 'is_purchasable' => 1, 'is_stock_tracked' => 1]);
        $this->assertSame('/manufacturing/products', $path);
    }

    private function nodeBinary(): string
    {
        $candidates = array_filter([
            getenv('NODE_BINARY') ?: null,
            trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null')) ?: null,
            ...glob('D:/laragon2/bin/nodejs/*/node.exe') ?: [],
        ]);
        foreach ($candidates as $candidate) {
            $candidate = strtok($candidate, "\r\n");
            if ($candidate && is_file($candidate)) {
                return $candidate;
            }
        }
        $this->markTestSkipped('node not found (set NODE_BINARY) — the form\'s scripts cannot be parse-checked here.');
    }
}
