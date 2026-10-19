<?php

namespace App\Support;

/**
 * PURCHASING-V2-2 — "jis ko screen ka access hoga wo saray kaam kar sakta hai" (owner, 19 Oct).
 *
 * A small helper the new purchase-screen endpoints (new supplier, batch box, batches made beforehand) use
 * instead of a permission of their own: whoever can open the screen they are used from may use them. The
 * routes are let through EnsureRoutePermission by name and come here instead.
 *
 * They exist only where the tenant's "New purchase screens" Master switch is on; anywhere else they are
 * not there at all (404), so a live tenant on today's screens never reaches them.
 */
final class ScreenAccess
{
    /** @param list<string> $screens route names (= permissions) of the screens this is used from */
    public static function purchasing(array $screens): void
    {
        abort_unless(TenantFeatures::on(TenantFeatures::PURCHASING_V2), 404);

        $user = auth('tenant')->user();
        abort_unless($user && collect($screens)->contains(fn (string $p) => $user->can($p)), 403, 'Permission denied.');
    }
}
