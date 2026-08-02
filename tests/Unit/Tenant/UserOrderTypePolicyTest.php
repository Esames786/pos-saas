<?php

namespace Tests\Unit\Tenant;

use App\Models\Tenant\User;
use PHPUnit\Framework\TestCase;

class UserOrderTypePolicyTest extends TestCase
{
    public function test_null_assignment_preserves_backward_compatible_access(): void
    {
        $user = new User(['allowed_order_types' => null]);

        $this->assertSame(array_keys(User::ORDER_TYPES), $user->effectiveAllowedOrderTypes());
        $this->assertTrue($user->allowsOrderType('dine_in'));
        $this->assertTrue($user->allowsOrderType('delivery'));
    }

    public function test_assignment_filters_unknown_values_and_uses_valid_default(): void
    {
        $user = new User([
            'allowed_order_types' => ['delivery', 'unknown', 'takeaway'],
            'default_order_type' => 'takeaway',
        ]);

        $this->assertSame(['takeaway', 'delivery'], $user->effectiveAllowedOrderTypes());
        $this->assertSame('takeaway', $user->effectiveDefaultOrderType());
        $this->assertFalse($user->allowsOrderType('quick_sale'));
    }

    public function test_invalid_default_falls_back_to_first_allowed_type(): void
    {
        $user = new User([
            'allowed_order_types' => ['delivery'],
            'default_order_type' => 'dine_in',
        ]);

        $this->assertSame('delivery', $user->effectiveDefaultOrderType());
    }
}
