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
    public const RETURN_NO_BILL = 'return_no_bill';

    public const REPORT_DELIVERY_V2 = 'report_delivery_v2';

    /**
     * shots (MASTER-NEW-SCREENS-1): pictures of the screens the switch shows, slug => caption
     * (public/assets/img/screens/<slug>.jpg), drawn beside the switch in Master.
     *
     * @return array<string, array{label:string, says:string, where:string, needs?:list<string>, shots?:array<string,string>}>
     */
    public static function all(): array
    {
        return [
            self::PRODUCT_SCREEN_V2 => [
                'label' => 'New product screen',
                'says'  => 'One simple screen to add and edit products: pictures first, yes/no questions, code and barcode on the same screen.',
                'where' => 'Products → Add / Edit',
                'shots' => ['product-new' => 'Add / edit in tabs', 'product-review' => 'Review before saving'],
            ],
            // PHARMACY-PACKS-1
            self::PACKS => [
                'label' => 'Packs (box · strip · tablet)',
                'says'  => 'Say how an item is packed — 1 box = 10 strips, 1 strip = 10 tablets — with a price and a barcode for each pack. Stock is still counted in the smallest unit.',
                'where' => 'Products → Add / Edit (new product screen)',
                'needs' => [self::PRODUCT_SCREEN_V2],
                'shots' => ['product-packs' => 'Packs tab'],
            ],
            // PURCHASING-V2-1
            self::PURCHASING_V2 => [
                'label' => 'New purchase screens (PO, GRN & Returns)',
                'says'  => 'Type purchase and return lines in one entry area: scan or search, and each item shows its own boxes (box · strip · tablet, batch, expiry, bonus); Ctrl+Enter puts the line in the list. A return leaves from the batch picked.',
                'where' => 'Purchasing → Purchase Orders, GRN and Returns → New; tabs on every purchasing page; Inventory → Batches',
                'shots' => ['grn-entry' => 'GRN entry area', 'purchase-return' => 'Purchase return', 'batches' => 'Batches'],
            ],
            // REPORT-DELIVERY-V2-1
            // MEDICAL-COUNTER-1
            self::MEDICAL_COUNTER => [
                'label' => 'Pack & batch counter (medical store, mart)',
                'says'  => 'On the counter each medicine sells as Box, Strip or Tablet; the bill shows the batch and expiry; an expired batch is never sold; a scanned box sells a box.',
                'where' => 'Sales → POS; Branches → Edit → "Ask for the prescription at the counter"',
                'needs' => [self::PACKS],
                'shots' => ['medical-counter' => 'The counter', 'counter-rx' => 'Prescription popup', 'counter-batch' => 'Change batch', 'bill-preview' => 'The bill'],
            ],
            // RETURN-NO-BILL-1: money goes back with no sale behind it — a business choice, off until switched on.
            self::RETURN_NO_BILL => [
                'label' => 'Return without a bill',
                'says'  => 'A customer can bring an item back without the bill: today\'s price at most, back into its batch or thrown away, a reason every time, and the branch\'s manager PIN when it asks for one.',
                'where' => 'Sales → Sales Returns → New → "Without bill" (also from the POS Return button)',
                'shots' => ['return-no-bill' => 'Return without a bill'],
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
