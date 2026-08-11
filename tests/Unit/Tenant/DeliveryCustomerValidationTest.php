<?php

namespace Tests\Unit\Tenant;

use App\Http\Controllers\Tenant\HeldSaleController;
use App\Http\Controllers\Tenant\SalesOrderController;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class DeliveryCustomerValidationTest extends TestCase
{
    #[DataProvider('deliveryControllers')]
    public function test_delivery_orders_require_an_attached_customer(string $controllerClass): void
    {
        $method = new ReflectionMethod($controllerClass, 'validateDeliveryAttribution');

        try {
            $method->invoke(new $controllerClass(), [
                'order_type' => 'delivery',
                'branch_id' => 1,
                'customer_id' => null,
            ]);
            $this->fail('A delivery order without an attached customer must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Attach a customer before saving a delivery order.'],
                $exception->errors()['customer_id'] ?? []
            );
        }
    }

    public static function deliveryControllers(): array
    {
        return [
            'held sale' => [HeldSaleController::class],
            'review and pay' => [SalesOrderController::class],
        ];
    }
}
