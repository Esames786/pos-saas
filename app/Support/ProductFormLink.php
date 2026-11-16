<?php

namespace App\Support;

/**
 * PRODUCT-NAV-1 (26 Oct) — the one product form, opened on its tab, from a specialized setup list (Recipes, Bill of
 * materials, Catering Products, Cost blocks). Only with the new product screen on, and only for a user who may open
 * product edit (its permission and the plan's module), so a link never leads to a screen the user cannot reach. A user
 * with only the specialized permission keeps the specialized screen, which stays as it is.
 */
final class ProductFormLink
{
    /** "/products/{id}/edit#{tab}", or null when the link must not be shown. */
    public static function to(?int $productId, string $tab): ?string
    {
        if (! $productId || ! TenantFeatures::on(TenantFeatures::PRODUCT_SCREEN_V2) || ! ScreenAccess::allows('tenant.products.edit')) {
            return null;
        }

        return url('/products/' . $productId . '/edit') . '#' . $tab;
    }
}
