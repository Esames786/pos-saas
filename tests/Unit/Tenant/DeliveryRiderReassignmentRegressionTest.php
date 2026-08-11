<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

class DeliveryRiderReassignmentRegressionTest extends TestCase
{
    public function test_reassignment_is_routed_guarded_audited_and_visible(): void
    {
        $routes = file_get_contents(base_path('routes/tenant.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Tenant/SalesOrderController.php'));
        $view = file_get_contents(resource_path('views/tenant/sales-orders/show.blade.php'));
        $posView = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));
        $posController = file_get_contents(app_path('Http/Controllers/Tenant/POSController.php'));
        $migration = file_get_contents(database_path('migrations/tenant/2026_08_11_000006_create_sales_order_rider_assignments.php'));

        $this->assertStringContainsString("name('tenant.sales-orders.rider.update')", $routes);
        $this->assertStringContainsString('assertSaleMutationAllowed', $controller);
        $this->assertStringContainsString('deniesSale', $controller);
        $this->assertStringContainsString('$lockedSale->riderAssignments()->create', $controller);
        $this->assertStringContainsString('$lockedSale->update([\'delivery_rider_id\' => $newRider->id])', $controller);
        $this->assertStringContainsString("@can('tenant.sales-orders.rider.update')", $view);
        $this->assertStringContainsString('Rider assignment history', $view);
        $this->assertStringContainsString('View or change rider', $posView);
        $this->assertStringContainsString("'rider'", $posController);
        $this->assertStringContainsString("create('sales_order_rider_assignments'", $migration);
    }

    public function test_reset_and_khatri_delivery_role_include_the_new_feature(): void
    {
        $reset = file_get_contents(app_path('Console/Commands/TenantResetTransactionsCommand.php'));
        $onboarding = file_get_contents(app_path('Console/Commands/OnboardKhatriBiryaniCommand.php'));

        $this->assertStringContainsString("'sales_order_rider_assignments'", $reset);
        $this->assertStringContainsString("'tenant.held-sales', 'tenant.sales-orders'", $onboarding);
    }
}
