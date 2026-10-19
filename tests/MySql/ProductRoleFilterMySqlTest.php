<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\ProductController;
use App\Models\Tenant\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRODUCT-ROLE-FILTER-1 — Catalog → Products, filtered by Role / Visibility.
 *
 * Khatri's list mixes menu items, drinks bought for resale, packaging and supplies, and the only way
 * to find "what can I buy here" or "what is on the till" was to read every row's badges. The filter
 * uses the badges' own names and the badges' own rules. Roles: any ticked. The rest: all ticked.
 */
class ProductRoleFilterMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['product_variants', 'products', 'categories']);
        $cat = $this->makeCategory();

        $menu = ['product_kind' => Product::KIND_SALE_ITEM, 'product_type' => 'simple', 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active'];
        $this->makeProduct($cat, ['name' => 'Beef Khatri Biryani', 'is_purchasable' => 0, 'is_stock_tracked' => 0] + $menu);
        $this->makeProduct($cat, ['name' => 'Cola Next 300 ml', 'is_purchasable' => 1, 'is_stock_tracked' => 0] + $menu);
        $this->makeProduct($cat, ['name' => 'BIG APPLE (CRT)', 'is_purchasable' => 1, 'is_stock_tracked' => 1, 'product_type' => 'service'] + $menu);
        // Packaging bought for the kitchen, never sold — shown on the catalog as a service-type supply.
        $this->makeProduct($cat, ['name' => 'Container 100 ML', 'product_kind' => Product::KIND_PACKAGING_MATERIAL, 'product_type' => 'service',
            'is_sellable' => 0, 'is_pos_visible' => 0, 'is_purchasable' => 1, 'is_stock_tracked' => 1, 'status' => 'active']);
        $this->makeProduct($cat, ['name' => 'Cleaning', 'product_type' => 'service', 'is_pos_visible' => 0, 'is_purchasable' => 1, 'is_stock_tracked' => 1] + $menu);
        $this->makeProduct($cat, ['name' => 'Old Menu Item', 'is_purchasable' => 0, 'is_stock_tracked' => 0, 'status' => 'inactive'] + $menu);

        view()->share('errors', new ViewErrorBag);
    }

    private function page(array $params = [])
    {
        return app(ProductController::class)->index(Request::create('/products', 'GET', $params));
    }

    private function listed(array $params = []): array
    {
        return collect($this->page($params)->getData()['products']->items())->pluck('name')->sort()->values()->all();
    }

    public function test_purchasable_shows_what_can_be_bought(): void
    {
        $this->assertSame(['BIG APPLE (CRT)', 'Cleaning', 'Cola Next 300 ml', 'Container 100 ML'], $this->listed(['flags' => ['purchasable']]));
    }

    public function test_ticked_visibility_flags_must_all_hold(): void
    {
        // Bought AND sold — the drinks, not the biryani (never bought) or the containers (never sold).
        $this->assertSame(['BIG APPLE (CRT)', 'Cola Next 300 ml'], $this->listed(['flags' => ['purchasable', 'sellable', 'pos_visible']]));
        // Bought, sold, and NOT counted — the one the GRN now takes as purchase only.
        $this->assertSame(['Cola Next 300 ml'], $this->listed(['flags' => ['purchasable', 'sellable', 'not_stock_tracked']]));
    }

    public function test_ticked_roles_are_any_of(): void
    {
        $this->assertSame(['Container 100 ML'], $this->listed(['roles' => ['packaging_material']]));
        $this->assertCount(6, $this->listed(['roles' => ['sale_item', 'packaging_material']]), 'two roles = both kinds');
    }

    public function test_roles_and_flags_combine(): void
    {
        $this->assertSame(['BIG APPLE (CRT)', 'Cleaning', 'Cola Next 300 ml'], $this->listed(['roles' => ['sale_item'], 'flags' => ['purchasable']]));
    }

    public function test_pos_visibility_follows_the_badge_rule(): void
    {
        // The badge says POS Visible only when active AND sellable AND shown on POS.
        $this->assertSame(['BIG APPLE (CRT)', 'Beef Khatri Biryani', 'Cola Next 300 ml'], $this->listed(['flags' => ['pos_visible']]));
        $this->assertSame(['Cleaning', 'Container 100 ML', 'Old Menu Item'], $this->listed(['flags' => ['hidden_from_pos']]),
            'an inactive menu item is hidden from the till too');
    }

    public function test_service_and_stock_flags(): void
    {
        $this->assertSame(['BIG APPLE (CRT)', 'Cleaning', 'Container 100 ML'], $this->listed(['flags' => ['service']]));
        $this->assertSame(['BIG APPLE (CRT)', 'Cleaning', 'Container 100 ML'], $this->listed(['flags' => ['stock_tracked']]));
    }

    public function test_nothing_ticked_or_unknown_values_change_nothing(): void
    {
        $all = $this->listed();
        $this->assertCount(6, $all);
        $this->assertSame($all, $this->listed(['roles' => ['nonsense'], 'flags' => ['drop table']]), 'unknown values are ignored, not "match nothing"');
        $this->assertSame(['Container 100 ML'], $this->listed(['roles' => 'packaging_material']), 'a single value from a link works too');
    }

    public function test_the_form_offers_only_roles_this_list_holds_and_remembers_ticks(): void
    {
        $view = $this->page(['roles' => ['packaging_material'], 'flags' => ['purchasable']]);

        $this->assertSame(['sale_item', 'packaging_material'], array_keys($view->getData()['roleOptions']),
            'no Raw Material / Finished Good to tick when the list holds none');
        $html = $view->render();
        $this->assertMatchesRegularExpression('/name="roles\[\]" value="packaging_material"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="flags\[\]" value="purchasable"[^>]*checked/', $html);
        $this->assertStringContainsString('(2 chosen)', $html);
    }
}
