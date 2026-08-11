<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

class SalesChargeVisibilityRegressionTest extends TestCase
{
    public function test_customer_facing_sale_and_return_views_show_every_order_charge(): void
    {
        $views = [
            resource_path('views/tenant/sales-orders/show.blade.php'),
            resource_path('views/tenant/sales-returns/create.blade.php'),
            resource_path('views/tenant/pos/partials/table-bill-preview.blade.php'),
        ];

        foreach ($views as $view) {
            $source = file_get_contents($view);
            $this->assertStringContainsString('service_charge_amount', $source, $view);
            $this->assertStringContainsString('delivery_charge_amount', $source, $view);
            $this->assertStringContainsString('tip_amount', $source, $view);
            $this->assertStringContainsString('tax_amount', $source, $view);
            $this->assertStringContainsString('discount_amount', $source, $view);
        }
    }

    public function test_return_ui_prorates_discount_and_tax_and_hides_component_rows(): void
    {
        $view = file_get_contents(resource_path('views/tenant/sales-returns/create.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Tenant/SalesReturnController.php'));

        $this->assertStringContainsString('data-discount-per-unit=', $view);
        $this->assertStringContainsString('data-tax-per-unit=', $view);
        $this->assertStringContainsString('gross - discount + tax', $view);
        $this->assertStringContainsString("orWhereNotIn('line_kind', ['component', 'modifier'])", $controller);
    }

    public function test_khatri_reset_preserves_owner_credentials_and_onboarding_owns_the_new_email(): void
    {
        $onboarding = file_get_contents(app_path('Console/Commands/OnboardKhatriBiryaniCommand.php'));
        $reset = file_get_contents(app_path('Console/Commands/TenantResetTransactionsCommand.php'));

        $this->assertStringContainsString("private const OWNER_EMAIL = 'owner_kb@bingoopos.com';", $onboarding);
        $this->assertStringContainsString('owner password preserved', $onboarding);
        $this->assertStringContainsString("'users'", $reset);
        $this->assertStringContainsString("'branches'", $reset);
        $this->assertStringNotContainsString('Khatiri123@', $onboarding, 'Owner passwords must never be committed as plaintext seeder data.');
    }

    public function test_pos_manual_discount_and_short_cash_are_explicit_and_server_enforced(): void
    {
        $view = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Tenant/SalesOrderController.php'));
        $branchForm = file_get_contents(resource_path('views/tenant/branches/form.blade.php'));
        $onboarding = file_get_contents(app_path('Console/Commands/OnboardKhatriBiryaniCommand.php'));

        $this->assertStringContainsString('id="manual-discount-type"', $view);
        $this->assertStringContainsString('id="discount-shortfall-btn"', $view);
        $this->assertStringContainsString('branchManualDiscountModes', $view);
        $this->assertStringContainsString('Cash tendered cannot be less than the amount applied', $controller);
        $this->assertStringContainsString('Applied payments must equal the final bill total', $controller);
        $this->assertStringContainsString("consume(\$discountApproval, 'manual_discount'", $controller);
        $this->assertStringContainsString('name="manual_discount_approval_mode"', $branchForm);
        $this->assertStringContainsString('MANUAL_DISCOUNT_AUTO_APPROVE', $onboarding);
    }
}
