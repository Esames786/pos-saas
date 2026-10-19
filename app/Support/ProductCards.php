<?php

namespace App\Support;

use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;

/**
 * PRODUCT-SCREEN-V2 — the picture cards of the one product screen, per business, and the rules that turn
 * a card + a few yes/no answers into the product's real fields.
 *
 * The SERVER decides the fields from the card and the answers (resolve()), so a card's lock ("packing is
 * never sold at the counter") holds even if the page's script fails or a request is crafted. Fields the
 * new screen does not show keep their saved values on edit — nothing is cleared silently.
 *
 * Questions: sell · buy · stock · expiry · recipe. Each card gives every question a default and, where the
 * card already answers it, a lock with the reason in plain words (English + Urdu).
 */
final class ProductCards
{
    /** @return array<string, array<string, mixed>> */
    public static function forBusiness(?string $business): array
    {
        $img = fn (string $f) => 'assets/img/product-types/' . $f . '.png';
        $sold = ['default' => 1, 'lock' => ['It is sold to customers — that is what this type is.', 'یہ گاہک کو بیچا جاتا ہے۔']];
        $notSold = ['default' => 0, 'lock' => ['Not sold at the counter — it is used inside the business.', 'یہ کاؤنٹر پر نہیں بکتا، اندر استعمال ہوتا ہے۔']];
        $hidden = null;

        $cards = match ($business) {
            Business::RESTAURANT => [
                'menu_item' => ['Menu item', 'مینو آئٹم', 'Food or drink you make and sell', 'Zinger burger, Chicken karahi', $img('hamburger'),
                    'sale_item', ['sale_item', 'service'], 'MENU', ['sell' => $sold, 'buy' => ['default' => 0], 'stock' => ['default' => 0], 'expiry' => $hidden, 'recipe' => ['default' => 0]]],
                'resale' => ['Ready-made item', 'تیار چیز', 'You buy it and sell it as it is', 'Pepsi bottle, Mineral water', $img('cup_with_straw'),
                    'sale_item', ['sale_item', 'finished_good'], 'ITEM', ['sell' => $sold, 'buy' => ['default' => 1], 'stock' => ['default' => 1], 'expiry' => ['default' => 0], 'recipe' => $hidden]],
                'kitchen' => ['Kitchen item', 'کچن کا سامان', 'Used in cooking, not sold', 'Tomato, Chicken, Masala', $img('tomato'),
                    'raw_material', ['raw_material'], 'ING', ['sell' => $notSold, 'buy' => ['default' => 1], 'stock' => ['default' => 1], 'expiry' => ['default' => 0], 'recipe' => $hidden]],
                'packing' => ['Packing', 'پیکنگ', 'Boxes, bags, foil, cups', 'Burger box, Shopper', $img('takeout_box'),
                    'packaging_material', ['packaging_material'], 'PKG', ['sell' => $notSold, 'buy' => ['default' => 1], 'stock' => ['default' => 1], 'expiry' => $hidden, 'recipe' => $hidden]],
            ],
            Business::CATERING => [
                'dish' => ['Menu dish', 'ڈش', 'Sold at events — per kg, per head or per deg', 'Chicken biryani, Mutton karahi', $img('shallow_pan_of_food'),
                    'sale_item', ['sale_item', 'service'], 'DISH', ['sell' => $sold, 'buy' => ['default' => 0], 'stock' => ['default' => 0], 'expiry' => $hidden, 'recipe' => ['default' => 0]]],
                'ingredient' => ['Ingredient', 'اجزاء', 'Meat, rice, oil, masala — used in dishes', 'Basmati rice, Chicken', $img('onion'),
                    'raw_material', ['raw_material'], 'ING', ['sell' => $notSold, 'buy' => ['default' => 1], 'stock' => ['default' => 1], 'expiry' => ['default' => 0], 'recipe' => $hidden]],
                'disposable' => ['Disposables & packing', 'ڈسپوزیبل', 'Foil, boxes, glasses, plates', 'Foil box 750 ml', $img('takeout_box'),
                    'packaging_material', ['packaging_material'], 'PKG', ['sell' => $notSold, 'buy' => ['default' => 1], 'stock' => ['default' => 1], 'expiry' => $hidden, 'recipe' => $hidden]],
                'service' => ['Service / hire', 'سروس', 'Waiters, crockery, decoration — no stock', 'Waiter service, Stage decor', $img('bellhop_bell'),
                    'service', ['service'], 'SRV', ['sell' => $sold, 'buy' => ['default' => 0, 'lock' => ['A service is not bought.', 'سروس خریدی نہیں جاتی۔']], 'stock' => ['default' => 0, 'lock' => ['A service has no stock.', 'سروس کا اسٹاک نہیں ہوتا۔']], 'expiry' => $hidden, 'recipe' => $hidden]],
            ],
            Business::PHARMACY => [
                'medicine' => ['Medicine', 'دوائی', 'Tablet, syrup, injection, drops', 'Panadol 500 mg, Brufen syrup', $img('pill'),
                    'sale_item', ['sale_item'], 'MED', ['sell' => $sold, 'buy' => ['default' => 1],
                        'stock' => ['default' => 1, 'lock' => ['Medicine stock is always counted.', 'دوائی کا اسٹاک ہمیشہ گنا جاتا ہے۔']],
                        'expiry' => ['default' => 1, 'lock' => ['Every medicine has a batch and an expiry date.', 'ہر دوائی کا بیچ اور ایکسپائری ہوتی ہے۔']], 'recipe' => $hidden], 'medicine' => true],
                'store_item' => ['Store item', 'عام چیز', 'Shampoo, soap, toothbrush, baby care, devices', 'Shampoo 180 ml, BP monitor', $img('lotion_bottle'),
                    'sale_item', ['sale_item'], 'STR', ['sell' => $sold, 'buy' => ['default' => 1], 'stock' => ['default' => 1], 'expiry' => ['default' => 0], 'recipe' => $hidden]],
            ],
            Business::MART => [
                'packed' => ['Packed item', 'پیک شدہ چیز', 'Comes in a packet with a barcode', 'Chips 60 g, Soap', $img('shopping_bags'),
                    'sale_item', ['sale_item'], 'ITM', ['sell' => $sold, 'buy' => ['default' => 1], 'stock' => ['default' => 1], 'expiry' => ['default' => 0], 'recipe' => $hidden], 'barcode' => true],
                'loose' => ['Loose item (by weight)', 'کھلی چیز (وزن سے)', 'Sold by kg or litre', 'Sugar, Rice, Daal', $img('balance_scale'),
                    'sale_item', ['sale_item'], 'LSE', ['sell' => $sold, 'buy' => ['default' => 1], 'stock' => ['default' => 1], 'expiry' => ['default' => 0], 'recipe' => $hidden], 'weight' => true],
            ],
            Business::INDUSTRY => [
                'raw' => ['Raw material', 'خام مال', 'What you buy to make products', 'Steel pipe, Flour, Plastic', $img('wood'),
                    'raw_material', ['raw_material'], 'RAW', ['sell' => $notSold, 'buy' => ['default' => 1], 'stock' => ['default' => 1], 'expiry' => ['default' => 0], 'recipe' => $hidden]],
                'finished' => ['Finished product', 'تیار مال', 'Made in your factory and sold', 'Steel chair, Bread packet', $img('chair'),
                    'finished_good', ['finished_good', 'sale_item'], 'FG', ['sell' => ['default' => 1], 'buy' => ['default' => 0, 'lock' => ['It is made in your factory, not bought.', 'یہ فیکٹری میں بنتا ہے، خریدا نہیں جاتا۔']], 'stock' => ['default' => 1], 'expiry' => ['default' => 0], 'recipe' => $hidden], 'mfg' => 'finished'],
                'semi' => ['Half-made part', 'آدھا تیار حصہ', 'Made here and used in another product', 'Chair frame', $img('gear'),
                    'semi_finished', ['semi_finished'], 'SFG', ['sell' => $notSold, 'buy' => ['default' => 0], 'stock' => ['default' => 1], 'expiry' => $hidden, 'recipe' => $hidden], 'mfg' => 'semi'],
                'packing' => ['Packing material', 'پیکنگ', 'Cartons, tape, foam', 'Chair carton', $img('roll_of_paper'),
                    'packaging_material', ['packaging_material'], 'PKG', ['sell' => $notSold, 'buy' => ['default' => 1], 'stock' => ['default' => 1], 'expiry' => $hidden, 'recipe' => $hidden]],
            ],
            default => [
                'sale' => ['Item I sell', 'بیچنے والی چیز', 'Anything sold to customers', 'Burger, Shampoo', $img('shopping_bags'),
                    'sale_item', ['sale_item', 'service', 'finished_good'], 'ITEM', ['sell' => $sold, 'buy' => ['default' => 0], 'stock' => ['default' => 0], 'expiry' => ['default' => 0], 'recipe' => ['default' => 0]]],
                'material' => ['Item I use', 'استعمال کی چیز', 'Bought and used inside, not sold', 'Tomato, Steel pipe', $img('tomato'),
                    'raw_material', ['raw_material'], 'MAT', ['sell' => $notSold, 'buy' => ['default' => 1], 'stock' => ['default' => 1], 'expiry' => ['default' => 0], 'recipe' => $hidden]],
                'packing' => ['Packing', 'پیکنگ', 'Boxes, bags, foil', 'Burger box', $img('takeout_box'),
                    'packaging_material', ['packaging_material'], 'PKG', ['sell' => $notSold, 'buy' => ['default' => 1], 'stock' => ['default' => 1], 'expiry' => $hidden, 'recipe' => $hidden]],
            ],
        };

        $out = [];
        foreach ($cards as $key => $c) {
            $out[$key] = [
                'key' => $key, 'label' => $c[0], 'label_ur' => $c[1], 'says' => $c[2], 'example' => $c[3], 'image' => $c[4],
                'kind' => $c[5], 'allowed_kinds' => $c[6], 'sku_prefix' => $c[7], 'ask' => $c[8],
                'medicine' => (bool) ($c['medicine'] ?? false), 'weight' => (bool) ($c['weight'] ?? false),
                'barcode' => (bool) ($c['barcode'] ?? false), 'mfg' => $c['mfg'] ?? null,
            ];
        }

        return $out;
    }

    /** The card an existing product belongs to (its saved kind and flags decide; data is never changed by this). */
    public static function detect(?string $business, Product $p): string
    {
        $cards = self::forBusiness($business);
        $kind = $p->product_kind ?: Product::KIND_SALE_ITEM;
        $isRecipe = $p->inventory_consumption_method === 'recipe';
        $pick = function (array $prefer) use ($cards, $kind) {
            foreach ($prefer as $k) {
                if (isset($cards[$k]) && in_array($kind, $cards[$k]['allowed_kinds'], true)) {
                    return $k;
                }
            }

            return null;
        };

        $guess = match ($business) {
            Business::RESTAURANT => $pick($p->is_stock_tracked && ! $isRecipe && $kind !== 'service' ? ['resale', 'menu_item', 'kitchen', 'packing'] : ['menu_item', 'resale', 'kitchen', 'packing']),
            Business::CATERING   => $pick($kind === 'service' ? ['service', 'dish'] : ['dish', 'ingredient', 'disposable']),
            Business::PHARMACY   => $pick($p->has_expiry ? ['medicine', 'store_item'] : ['store_item', 'medicine']),
            Business::MART       => $pick(in_array($p->unit?->unit_type, ['weight', 'volume', 'length'], true) ? ['loose', 'packed'] : ['packed', 'loose']),
            Business::INDUSTRY   => $pick(['finished', 'semi', 'raw', 'packing']),
            default              => $pick(['sale', 'material', 'packing']),
        };

        return $guess ?? array_key_first($cards);
    }

    /**
     * Card + answers → the fields the existing ProductController validation expects.
     * Locked questions take the card's value whatever was posted.
     *
     * @param  array<string,mixed>  $in  the request input
     * @return array<string,mixed>
     */
    public static function resolve(?string $business, array $in, ?Product $saved = null): array
    {
        $cards = self::forBusiness($business);
        $key = (string) ($in['_card'] ?? '');
        $card = $cards[$key] ?? ($saved ? $cards[self::detect($business, $saved)] : reset($cards));
        $answer = function (string $q) use ($card, $in): bool {
            $rule = $card['ask'][$q] ?? null;
            if ($rule === null) {
                return false;
            }
            if (isset($rule['lock'])) {
                return (bool) $rule['default'];
            }

            return array_key_exists('_' . $q, $in) ? filter_var($in['_' . $q], FILTER_VALIDATE_BOOLEAN) : (bool) $rule['default'];
        };

        $sell = $answer('sell');
        $buy = $answer('buy');
        $stock = $answer('stock');
        $expiry = $answer('expiry');
        $recipe = $answer('recipe');

        $kind = ($saved && in_array($saved->product_kind, $card['allowed_kinds'], true)) ? $saved->product_kind : $card['kind'];
        $isService = $kind === Product::KIND_SERVICE;
        if ($isService) {
            $stock = false;
            $buy = false;
        }

        $type = $recipe ? 'recipe' : ($isService ? 'service' : 'simple');
        if ($saved) {
            $behavesSame = in_array($saved->product_type, ['simple', 'service'], true) && in_array($type, ['simple', 'service'], true);
            if ($behavesSame || ($recipe && $saved->product_type === 'hybrid')) {
                $type = $saved->product_type;   // nothing about how it sells changed — keep what was saved
            }
        }

        $method = $recipe ? 'recipe' : ($stock ? 'stock_item' : 'none');
        $isMfgFinished = $card['mfg'] === 'finished';
        $isSemi = $card['mfg'] === 'semi';

        // The counter button is its own answer (19 Oct, owner on a live tenant: an item sold inside deals,
        // as an add-on or online is "Hidden from POS" yet sellable). A save that did not ask keeps it.
        $posVisible = $sell && (array_key_exists('_pos', $in)
            ? filter_var($in['_pos'], FILTER_VALIDATE_BOOLEAN)
            : ($saved ? (bool) $saved->is_pos_visible : true));
        // Batch and expiry are asked as one question; a saved item where the two differ keeps both while
        // the answer still matches it.
        $requiresBatch = ($saved && $expiry === (bool) $saved->has_expiry) ? (bool) $saved->requires_batch : $expiry;

        // "More settings" (the old form's kitchen and costing boxes): what the screen posted, else the saved
        // value, else the old form's default — never cleared because the screen left a box out.
        $more = filter_var($in['_more'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $posted = fn (string $k) => $more && array_key_exists($k, $in);
        $blankToNull = fn ($v) => ($v === null || trim((string) $v) === '') ? null : $v;
        $defaultItemKind = in_array($kind, ['raw_material', 'packaging_material'], true) ? 'ingredient' : 'finished_good';

        $out = [
            'product_kind'                  => $kind,
            'product_type'                  => $type,
            'inventory_consumption_method'  => $method,
            'is_sellable'                   => $sell ? 1 : 0,
            'is_pos_visible'                => $posVisible ? 1 : 0,
            'is_purchasable'                => $buy ? 1 : 0,
            'is_stock_tracked'              => $stock ? 1 : 0,
            'has_expiry'                    => $expiry ? 1 : 0,
            'requires_batch'                => $requiresBatch ? 1 : 0,
            'is_manufactured_finished_good' => $isMfgFinished ? 1 : (int) ($saved?->is_manufactured_finished_good ?? 0),
            'can_be_bom_output'             => ($isMfgFinished || $isSemi) ? 1 : (int) ($saved?->can_be_bom_output ?? 0),
            'can_be_bom_component'          => $isSemi ? 1 : (int) ($saved?->can_be_bom_component ?? 0),
            'has_variants'            => (int) ($saved?->has_variants ?? 0),
            'item_kind'               => $posted('item_kind') && in_array($in['item_kind'], ['finished_good', 'ingredient', 'both'], true)
                ? $in['item_kind'] : ($saved?->item_kind ?? $defaultItemKind),
            'purchase_unit_id'        => $posted('purchase_unit_id') ? $blankToNull($in['purchase_unit_id']) : $saved?->purchase_unit_id,
            'purchase_pack_size'      => $posted('purchase_pack_size') ? $blankToNull($in['purchase_pack_size']) : $saved?->purchase_pack_size,
            'is_perishable'           => $posted('_perishable') ? (filter_var($in['_perishable'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : (int) ($saved?->is_perishable ?? 0),
            'storage_type'            => $posted('storage_type') ? $blankToNull(trim((string) $in['storage_type'])) : $saved?->storage_type,
            'shelf_life_days'         => $posted('shelf_life_days') ? $blankToNull($in['shelf_life_days']) : $saved?->shelf_life_days,
            'default_wastage_percent' => $posted('default_wastage_percent') ? ($blankToNull($in['default_wastage_percent']) ?? 0) : ($saved?->default_wastage_percent ?? 0),
        ];

        // Prices: a section answered "no" keeps whatever was saved, so turning it back on later loses nothing.
        // A sold item with no price is left to the validation ("enter the selling price").
        if (! $sell) {
            $out['default_selling_price'] = $saved?->default_selling_price ?? 0;
        }
        if (! $buy) {
            $out['default_purchase_price'] = $saved?->default_purchase_price ?? ($in['default_purchase_price'] ?? 0);
        }

        // Tax: one question. "No" clears both, "yes" keeps the posted rate.
        $tax = filter_var($in['_tax'] ?? ($saved?->is_taxable ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
        $out['is_taxable'] = $tax ? 1 : 0;
        if (! $tax) {
            $out['tax_rate_percent'] = null;
        }

        if (trim((string) ($in['sku'] ?? '')) === '') {
            $out['sku'] = self::nextSku($card['sku_prefix'], $saved);
        }

        return $out;
    }

    /**
     * PRODUCT-LIST-PLAIN-1 — each product kind in this business's own words, for the products list filter
     * ("which item can be what", owner 19 Oct). Kinds the business never uses still get a plain name.
     *
     * @return array<string,string>
     */
    public static function kindWords(?string $business): array
    {
        $sold = match ($business) {
            Business::PHARMACY   => 'Sold item — medicine or store item',
            Business::RESTAURANT => 'Sold item — menu item or ready-made (burger, Pepsi)',
            Business::CATERING   => 'Sold item — menu dish',
            Business::MART       => 'Sold item — packed or loose',
            default              => 'Sold item',
        };

        return [
            Product::KIND_SALE_ITEM          => $sold,
            Product::KIND_SERVICE            => 'Service — sold, no stock (delivery, decoration)',
            Product::KIND_RAW_MATERIAL       => $business === Business::INDUSTRY ? 'Raw material — bought to make products' : 'Kitchen item — used in cooking, not sold',
            Product::KIND_PACKAGING_MATERIAL => 'Packing — boxes, bags, cartons',
            Product::KIND_FINISHED_GOOD      => 'Finished product — made here',
            Product::KIND_SEMI_FINISHED      => 'Half-made part — made here, used again',
            Product::KIND_COMBO_VIRTUAL      => 'Deal',
        ];
    }

    /** The name the old product form used for this item ("Service Item", "Recipe / Kitchen Item"…). */
    public static function oldTypeLabel(Product $p): string
    {
        $kind = $p->product_kind ?: Product::KIND_SALE_ITEM;
        if ($kind === Product::KIND_SERVICE || $p->product_type === 'service') {
            return 'Service Item';
        }

        return match ($kind) {
            Product::KIND_RAW_MATERIAL       => 'Ingredient / Raw Material',
            Product::KIND_PACKAGING_MATERIAL => 'Packing Material',
            Product::KIND_FINISHED_GOOD      => 'Finished Good',
            Product::KIND_SEMI_FINISHED      => 'Half-made part',
            Product::KIND_COMBO_VIRTUAL      => 'Deal',
            default => $p->inventory_consumption_method === 'recipe' ? 'Recipe / Kitchen Item' : 'POS Sale Item',
        };
    }

    /** PRODUCT-LIST-PLAIN-1 — the list's "Show only" ticks in plain words (same keys as the old ones). */
    public const FLAG_WORDS = [
        'pos_visible'       => 'Has a button on the counter',
        'hidden_from_pos'   => 'No button on the counter',
        'sellable'          => 'Sold to customers',
        'purchasable'       => 'Bought from suppliers',
        'stock_tracked'     => 'Stock is counted',
        'not_stock_tracked' => 'Stock is not counted',
        'service'           => 'Service (no stock)',
        'recipe'            => 'Made from a recipe',
    ];

    /** A code nobody has yet, in products AND variants: PREFIX-000123. Keeps the saved SKU on edit. */
    public static function nextSku(string $prefix, ?Product $saved = null): string
    {
        if ($saved && $saved->sku) {
            return $saved->sku;
        }
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix) ?: 'ITEM');
        $max = 0;
        foreach (Product::where('sku', 'like', $prefix . '-%')->pluck('sku') as $sku) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', $sku, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        do {
            $candidate = sprintf('%s-%06d', $prefix, ++$max);
        } while (Product::where('sku', $candidate)->exists() || ProductVariant::where('sku', $candidate)->exists());

        return $candidate;
    }
}
