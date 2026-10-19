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
    public const PURCHASING_V2 = 'purchasing_v2';
    public const MEDICAL_COUNTER = 'medical_counter';

    public const REPORT_DELIVERY_V2 = 'report_delivery_v2';

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
            // PURCHASING-V2-1
            self::PURCHASING_V2 => [
                'label' => 'New purchase screens (PO, GRN & Returns)',
                'says'  => 'Type purchase and return lines in one entry area: scan or search, and each item shows its own boxes (box · strip · tablet, batch, expiry, bonus); Ctrl+Enter puts the line in the list. A return leaves from the batch picked.',
                'where' => 'Purchasing → Purchase Orders, GRN and Returns → New; tabs on every purchasing page; Inventory → Batches',
            ],
            // REPORT-DELIVERY-V2-1
            // MEDICAL-COUNTER-1
            self::MEDICAL_COUNTER => [
                'label' => 'Pack & batch counter (medical store, mart)',
                'says'  => 'On the counter each medicine sells as Box, Strip or Tablet; the bill shows the batch and expiry; an expired batch is never sold; a scanned box sells a box.',
                'where' => 'Sales → POS',
                'needs' => [self::PACKS],
            ],
            self::REPORT_DELIVERY_V2 => [
                'label' => 'New daily report delivery',
                'says'  => 'One card to turn WhatsApp and Email on, add numbers with names, see the price per message, and send the daily report the moment the last shift of the day closes.',
                'where' => 'Reports → Sales Report Center → Daily report delivery',
            ],
        ];
    }

    public static function on(string $key, ?Tenant $tenant = null): bool
    {
        $tenant ??= app()->bound('tenant') ? app('tenant') : null;

        return (bool) (($tenant?->features ?? [])[$key] ?? false);
    }
}
