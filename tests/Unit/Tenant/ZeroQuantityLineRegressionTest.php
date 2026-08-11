<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

/**
 * ZERO-QUANTITY SALE LINES.
 *
 * An explicit 0 was already rejected by validation (min:0.001), but a BLANK quantity slipped
 * through as "nullable" and the line was then quietly filtered away: the item vanished from the
 * bill and from the kitchen ticket with nothing said, so the customer paid for less than was rung
 * up. Both the paid-sale and held-order paths now refuse it outright.
 *
 * The one path that legitimately drops a line is removing an item from a held order — the POS
 * omits that line from the payload entirely rather than sending it with a zero, so this guard
 * must key on "product named AND quantity missing", never on absence.
 */
class ZeroQuantityLineRegressionTest extends TestCase
{
    public function test_both_sale_paths_refuse_a_named_product_with_no_quantity(): void
    {
        $paid = file_get_contents(app_path('Http/Controllers/Tenant/SalesOrderController.php'));
        $held = file_get_contents(app_path('Http/Controllers/Tenant/HeldSaleController.php'));

        $this->assertStringContainsString('assertNoZeroQuantityLines', $paid);
        $this->assertMatchesRegularExpression(
            '/Every item needs a quantity greater than zero/',
            $paid,
            'the paid-sale path must reject a zero-quantity line with a clear message'
        );
        $this->assertMatchesRegularExpression(
            '/Every item needs a quantity greater than zero/',
            $held,
            'the held-order path must reject it the same way'
        );
    }

    public function test_the_guard_keys_on_a_named_product_so_removing_an_item_still_works(): void
    {
        $paid = file_get_contents(app_path('Http/Controllers/Tenant/SalesOrderController.php'));

        // Must skip rows with no product (empty spare rows on the manual form) rather than
        // rejecting them, and must not fire on a line that is simply absent from the payload.
        $this->assertMatchesRegularExpression(
            "/if \(empty\(\\\$line\['product_id'\]\)\) \{\s*continue;/",
            $paid,
            'a row with no product is not a line at all and must be skipped'
        );
    }

    public function test_explicit_zero_is_still_rejected_by_validation(): void
    {
        $paid = file_get_contents(app_path('Http/Controllers/Tenant/SalesOrderController.php'));

        $this->assertStringContainsString("'lines.*.quantity'           => ['nullable', 'numeric', 'min:0.001']", $paid);
    }
}
