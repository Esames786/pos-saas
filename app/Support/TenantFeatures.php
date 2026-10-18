<?php

namespace App\Support;

use App\Models\Master\Tenant;

/**
 * PRIMARY-BUSINESS-1 — per-tenant switches the owner flips in Bingoo Master.
 *
 * Only switches for things that are BUILT live here (no "coming soon" switches). Each says in plain words
 * what it changes, so the owner never has to read code to know.
 */
final class TenantFeatures
{
    public const PRODUCT_SCREEN_V2 = 'product_screen_v2';

    /** @return array<string, array{label:string, says:string, where:string}> */
    public static function all(): array
    {
        return [
            self::PRODUCT_SCREEN_V2 => [
                'label' => 'New product screen',
                'says'  => 'One simple screen to add and edit products: pictures first, yes/no questions, code and barcode on the same screen.',
                'where' => 'Products → Add / Edit',
            ],
        ];
    }

    public static function on(string $key, ?Tenant $tenant = null): bool
    {
        $tenant ??= app()->bound('tenant') ? app('tenant') : null;

        return (bool) (($tenant?->features ?? [])[$key] ?? false);
    }
}
