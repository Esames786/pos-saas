<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

/**
 * A RETURN MUST STATE HOW THE MONEY WENT BACK.
 *
 * "— None —" was both selectable and the DEFAULT on the return form. Choosing it posts
 * Dr revenue / Cr 1500 Undeposited Funds and writes NO cash-bank movement, so the goods come
 * back, revenue drops, and the money silently never leaves the drawer. Khatri Biryani ended a
 * trading day with 1,530 parked in suspense and a till that could not be reconciled.
 *
 * Goods returning always means money returning, so the method is now required at the form, the
 * request validation and the service.
 */
class RefundMethodRequiredRegressionTest extends TestCase
{
    public function test_the_request_requires_a_refund_method(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Tenant/SalesReturnController.php'));

        $this->assertMatchesRegularExpression(
            "/'refund_method'\s*=>\s*\['required',/",
            $controller,
            'refund_method must be required — nullable lets a return post into suspense'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'refund_method'\s*=>\s*\['nullable'/",
            $controller
        );
    }

    public function test_the_service_refuses_a_return_with_no_refund_method(): void
    {
        $service = file_get_contents(app_path('Services/Sales/SalesReturnService.php'));

        $this->assertStringContainsString('if (! $refundMethod) {', $service);
        $this->assertMatchesRegularExpression(
            '/a return cannot be posted without a refund method/',
            $service,
            'the service must fail closed even if a caller bypasses request validation'
        );
    }

    public function test_the_form_no_longer_offers_none_as_a_choice(): void
    {
        $view = file_get_contents(resource_path('views/tenant/sales-returns/create.blade.php'));

        // Check the OPTION TAG, not the phrase — the comment above the field explains the old
        // "— None —" behaviour, and a naive search would match that documentation.
        $this->assertDoesNotMatchRegularExpression(
            '/<option value="">(?![^<]*disabled)/',
            $view,
            'an empty refund-method option must never be submittable'
        );
        $this->assertStringContainsString('<option value="" disabled', $view, 'the placeholder must not be submittable');
        $this->assertMatchesRegularExpression(
            '/name="refund_method" required/',
            $view,
            'the browser should stop it before the request is even sent'
        );
    }
}
