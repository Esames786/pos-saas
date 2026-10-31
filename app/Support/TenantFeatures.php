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
    // UNITS-PACKS F3
    public const COUNTER_PACKS = 'counter_packs';
    // W2-C
    public const WHOLE_COUNTED_STOCK = 'whole_counted_stock';

    public const REPORT_DELIVERY_V2 = 'report_delivery_v2';
    // W3/W4 S4
    public const PURCHASE_WORKSPACE = 'purchase_workspace';

    // VOID-PURGE-1: the Owner may delete, permanently, an entry already voided on its own date. Off everywhere by default.
    public const VOID_PURGE = 'void_purge';

    // SHIFT-HANDOVER-1: a cashier with open orders hands in his own cash and opens the next cashier's shift on the same counter.
    public const SHIFT_HANDOVER = 'shift_handover';

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
            // W3/W4 S4
            self::PURCHASE_WORKSPACE => [
                'label' => 'One purchase screen',
                'says'  => 'Record a purchase in one go: the goods that arrived, the supplier\'s invoice and a payment — or only the parts you have today (receive now, invoice later; or invoice goods already received). One Post saves the chosen parts together, or none of them.',
                'where' => 'Purchasing → New purchase',
                'needs' => [self::PURCHASING_V2],
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
            // W2-C
            self::WHOLE_COUNTED_STOCK => [
                'label' => 'Whole numbers for counted items in purchasing and stock',
                'says'  => 'A counted item (piece, bottle, box) is bought, returned, moved and counted in whole numbers only, the same as at the counter. Kg, litre and metre items still take up to 3 decimals. Leave it off while some counted items are still bought in parts.',
                'where' => 'Purchase orders, GRN, supplier returns, stock adjustments, transfers and counts',
            ],
            // UNITS-PACKS F3
            self::COUNTER_PACKS => [
                'label' => 'Packs at the normal counter',
                'says'  => 'Scan a carton or box barcode at the normal counter and it sells as 1 Carton at the carton\'s price; its pieces leave stock. The counter looks exactly the same. An item with an expiry date never sells from an expired batch here (the medical counter\'s rule). No prescription questions.',
                'where' => 'POS (scan) · Products → packs',
                'needs' => [self::PACKS],
            ],
            // VOID-PURGE-1 (production)
            self::VOID_PURGE => [
                'label' => 'Delete voided entries permanently',
                'says'  => 'A user given the permission can delete a voided entry together with its reversal, so neither shows anywhere. Totals today stay the same. When the void was dated another day, the screen first says which days\' cash closing changes and asks to confirm. A hidden copy is kept in Deleted entries. Risk: a recorded cash movement can disappear from every screen.',
                'where' => 'Cash Book and the entry\'s own page → "Delete permanently" on a voided row; Finance → Deleted entries',
            ],
            // SHIFT-HANDOVER-1
            self::SHIFT_HANDOVER => [
                'label' => 'Hand the counter to the next cashier',
                'says'  => 'A cashier whose shift still has open orders or tables counts and hands in his own cash, then opens the next cashier\'s shift on the same counter in one step — the next cashier types his own password. The open orders and tables move into the new shift; their counter, date and amounts stay as they are. Same day only: after midnight the open orders are settled first, as today.',
                'where' => 'Shifts → Close Shift (only when the shift has open orders or tables)',
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
