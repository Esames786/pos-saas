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
    public const PACKS = 'packs';

    /** @return array<string, array{label:string, says:string, where:string, needs?:list<string>}> */
    public static function all(): array
    {
        return [
            self::PRODUCT_SCREEN_V2 => [
                'label' => 'New product screen',
                'says'  => 'One simple screen to add and edit products: pictures first, yes/no questions, code and barcode on the same screen.',
                'where' => 'Products → Add / Edit',
            ],
            // PHARMACY-PACKS-1
            self::PACKS => [
                'label' => 'Packs (box · strip · tablet)',
                'says'  => 'Say how an item is packed — 1 box = 10 strips, 1 strip = 10 tablets — with a price and a barcode for each pack. Stock is still counted in the smallest unit.',
                'where' => 'Products → Add / Edit (new product screen)',
                'needs' => [self::PRODUCT_SCREEN_V2],
            ],
        ];
    }

    public static function on(string $key, ?Tenant $tenant = null): bool
    {
        $tenant ??= app()->bound('tenant') ? app('tenant') : null;

        return (bool) (($tenant?->features ?? [])[$key] ?? false);
    }
}
