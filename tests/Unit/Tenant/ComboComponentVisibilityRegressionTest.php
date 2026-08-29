<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

/**
 * COMBO-COMPONENT-VISIBILITY — a combo may bundle a component that is not a grid product (a service
 * side, a garnish, a filler). Those must ride in the POS products payload so the front-end
 * comboAvailability() can resolve them; a component missing from the payload is scored makeable=0 and
 * the combo shows a FALSE "out of stock" — even though the item is a service with no stock at all.
 *
 * The fix widens the payload query (is_pos_visible OR referenced by an active combo) and gates grid
 * display on a per-product `pos_grid_visible` flag so the fillers never render as sellable tiles.
 */
class ComboComponentVisibilityRegressionTest extends TestCase
{
    private function controller(): string
    {
        return file_get_contents(app_path('Http/Controllers/Tenant/POSController.php'));
    }

    public function test_payload_query_includes_combo_component_products(): void
    {
        $code = $this->controller();

        $this->assertStringContainsString('$comboComponentProductIds', $code,
            'the payload must widen to include active combo components, or combos with a hidden/service component read as out of stock');
        $this->assertStringContainsString('orWhereIn(\'id\', $comboComponentProductIds->all())', $code,
            'combo-component ids must be OR-ed into the product query alongside is_pos_visible');
    }

    public function test_payload_carries_a_grid_visibility_flag(): void
    {
        $this->assertStringContainsString("'pos_grid_visible'  => (bool) \$product->is_pos_visible", $this->controller(),
            'each product must carry pos_grid_visible so combo-only fillers can ride in the payload yet stay off the grid');
    }

    public function test_grid_skips_non_visible_products(): void
    {
        $view = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));

        $this->assertStringContainsString('product.pos_grid_visible === false', $view,
            'the product grid must skip combo-only fillers so they never render as sellable tiles');
    }
}
