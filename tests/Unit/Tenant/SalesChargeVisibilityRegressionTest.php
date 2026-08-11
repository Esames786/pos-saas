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
        $this->assertStringNotContainsString('Khatiri123@', $onboarding, 'Owner passwords must never be committed as plaintext seeder data.');
    }
}
