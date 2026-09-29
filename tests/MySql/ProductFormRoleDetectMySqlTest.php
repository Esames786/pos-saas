<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\ProductController;
use App\Models\Tenant\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRODUCT-FORM-ROLE-1 — the product form must describe the product it is editing.
 *
 * `product_kind` is what a product IS; `product_type` is only its shape. The form tested the
 * shape first, so a purchased, stock-tracked PACKAGING material stored as type=service was drawn
 * as "Service Item - Sold in POS, no stock". That card hides the Purchasable and Track Stock
 * boxes, which are the only reason such a product exists - the screen then contradicted its own
 * badges, and one click on the already-highlighted card would have reset the product to a
 * POS-sellable service with no purchasing and no stock.
 *
 * These guards render the REAL blade through the REAL controller. Asserting on the blade's source
 * text would have passed just as happily with the bug in place.
 */
class ProductFormRoleDetectMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['product_variants', 'products', 'categories']);
        $this->categoryId = $this->makeCategory();

        // ShareErrorsFromSession is web middleware; a directly-invoked controller never runs it,
        // and the blade opens with $errors->any().
        view()->share('errors', new ViewErrorBag);
    }

    /** Render the edit screen for a product carrying exactly these flags. */
    private function renderEdit(array $attrs): string
    {
        $id = $this->makeProduct($this->categoryId, $attrs);
        $product = Product::on('tenant')->findOrFail($id);

        return app(ProductController::class)
            ->edit(Request::create('/products/' . $id . '/edit', 'GET'), $product)
            ->render();
    }

    /** The mode the form settled on, read from the field it posts back. */
    private function mode(string $html): string
    {
        $pattern = '/name="_setup_mode"[^>]*value="([a-z_]+)"/';
        $this->assertMatchesRegularExpression($pattern, $html);
        preg_match($pattern, $html, $m);

        return $m[1];
    }

    public function test_the_role_picks_the_card_even_when_the_shape_says_service(): void
    {
        // Exactly the shape a purchase-only packaging item has to carry to stay in the catalog
        // list: kind says packaging, type says service.
        $html = $this->renderEdit([
            'product_kind' => 'packaging_material', 'product_type' => 'service',
            'is_sellable' => 0, 'is_pos_visible' => 0, 'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);

        $this->assertSame('packaging', $this->mode($html),
            'a packaging material is a packaging material whatever its shape');
    }

    public function test_a_raw_material_is_not_mistaken_for_a_service_either(): void
    {
        $html = $this->renderEdit([
            'product_kind' => 'raw_material', 'product_type' => 'service',
            'is_sellable' => 0, 'is_pos_visible' => 0, 'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);

        $this->assertSame('raw_material', $this->mode($html));
    }

    public function test_a_genuine_pos_service_still_detects_as_service(): void
    {
        // The case the old order got RIGHT, and the one most likely to be broken by fixing it:
        // a real till-sold service is kind=sale_item with shape=service. Khatri has one live.
        $html = $this->renderEdit([
            'product_kind' => 'sale_item', 'product_type' => 'service',
            'is_sellable' => 1, 'is_pos_visible' => 1, 'is_stock_tracked' => 0,
            'inventory_consumption_method' => 'none',
        ]);

        $this->assertSame('service', $this->mode($html), 'shape still answers the one question it can');
    }

    public function test_an_ordinary_pos_item_and_a_recipe_item_are_untouched(): void
    {
        $this->assertSame('pos_sale', $this->mode($this->renderEdit([
            'product_kind' => 'sale_item', 'product_type' => 'simple',
            'is_sellable' => 1, 'is_pos_visible' => 1, 'is_stock_tracked' => 1,
        ])));

        $this->assertSame('recipe', $this->mode($this->renderEdit([
            'product_kind' => 'sale_item', 'product_type' => 'simple',
            'inventory_consumption_method' => 'recipe',
            'is_sellable' => 1, 'is_pos_visible' => 1, 'is_stock_tracked' => 1,
        ])));
    }

    public function test_a_flag_that_is_on_is_rendered_and_checked_so_a_save_cannot_clear_it(): void
    {
        $html = $this->renderEdit([
            'product_kind' => 'packaging_material', 'product_type' => 'service',
            'is_sellable' => 0, 'is_pos_visible' => 0, 'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);

        // ProductController stores `!empty($data['is_purchasable'])`, so a field that is not POSTED
        // is stored as FALSE. The inputs must therefore exist and be checked even while a mode
        // hides them - that is the whole reason a hidden Purchasable survives a Save.
        foreach (['is_purchasable', 'is_stock_tracked'] as $field) {
            $this->assertMatchesRegularExpression(
                '/<input id="' . $field . '"[^>]*name="' . $field . '"[^>]*checked/',
                $html,
                $field . ' must be rendered AND checked, or saving this screen clears it'
            );
        }

        // And the JS must never be allowed to stop rendering them.
        $this->assertStringContainsString('style.display = vis', $html,
            'hiding stays a CSS hide; removing the inputs would clear every hidden flag on save');
    }

    public function test_switching_type_on_an_existing_product_asks_before_resetting_its_flags(): void
    {
        $edit = $this->renderEdit([
            'product_kind' => 'packaging_material', 'product_type' => 'service',
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);

        // The detected card is drawn as active, so a click "to confirm the type" used to silently
        // reset role, POS visibility, purchasing, stock and consumption.
        $this->assertStringContainsString('var IS_EDIT = true', $edit);
        // Pin the CONDITION, not the word. Looking only for "window.confirm" stayed green when
        // the condition was sabotaged to `if (false)` — prompt still in the file, never reachable.
        $this->assertStringContainsString('if (IS_EDIT && MODES[mode] && MODES[mode].def) {', $edit,
            'the confirm must hang off the real edit condition, not sit in a dead branch');
        $this->assertStringContainsString('window.confirm', $edit);

        // A NEW product has nothing to lose, so it must not be nagged.
        $create = app(ProductController::class)
            ->create(Request::create('/products/create', 'GET'))
            ->render();
        $this->assertStringContainsString('var IS_EDIT = false', $create);
        $this->assertSame('pos_sale', $this->mode($create));
    }

    /**
     * The two client-side rules, pinned to their MECHANISM.
     *
     * This harness cannot execute the form's JavaScript, so these assert the source that ships.
     * That is weaker than a behavioural test and the weakness is real: the first version of the
     * confirm guard below only looked for the word "window.confirm" and stayed GREEN when the
     * condition was sabotaged to `if (false)` — the prompt was still in the file, permanently
     * dead. So each assertion now pins the actual condition or statement, which is what a
     * deletion or a rewiring would disturb. They catch a removed rule, not a logic error inside
     * one.
     */
    public function test_a_mode_cannot_hide_a_flag_the_product_already_carries(): void
    {
        $html = $this->renderEdit([
            'product_kind' => 'packaging_material', 'product_type' => 'service',
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);

        foreach ([['is_purchasable', 'purchase'], ['is_stock_tracked', 'stock']] as [$flag, $group]) {
            $this->assertMatchesRegularExpression(
                "/isChecked\('{$flag}'\)\s*&&\s*groups\.indexOf\('{$group}'\)\s*===\s*-1\)\s*groups\.push\('{$group}'\)/",
                $html,
                "a mode that omits the '{$group}' group must still show it while {$flag} is on"
            );
        }
    }
}
