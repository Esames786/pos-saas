<?php

namespace App\Support;

/**
 * WEBSITE-HIDE-PRICES-1 — the owner can hide every subscription price on the public website
 * (SAAS_HIDE_PUBLIC_PRICES=true): for a client demo, or while prices are being changed.
 *
 * When hidden, the controller does not even work out the per-branch figures or the home page preview,
 * so no price reaches the page source; the views print "Price on request" in their place. Signing up
 * is untouched — the server prices and records the plan exactly as before.
 */
final class PublicPrices
{
    public static function hidden(): bool
    {
        return (bool) config('saas.hide_public_prices');
    }
}
