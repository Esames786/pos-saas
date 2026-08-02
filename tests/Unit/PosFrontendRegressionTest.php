<?php

namespace Tests\Unit;

use Tests\TestCase;

class PosFrontendRegressionTest extends TestCase
{
    public function test_fresh_product_cart_state_does_not_read_a_recalled_line(): void
    {
        $view = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));

        preg_match(
            '/function addToCart\(.*?\n    }\n\n    \/\/ POS-UX-2/s',
            $view,
            $matches
        );

        $this->assertNotEmpty($matches, 'The addToCart function could not be located.');
        $this->assertStringNotContainsString('line.id', $matches[0]);
        $this->assertStringNotContainsString('line.kot_sent', $matches[0]);
        $this->assertStringContainsString('_dbLineId:          null', $matches[0]);
        $this->assertStringContainsString('kot_sent_quantity:  0', $matches[0]);
    }
}
