<?php

namespace Database\Seeders\Tenant\Concerns;

use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use Database\Seeders\Tenant\KashifClientMenuSeeder;

/**
 * KASHIF-CLIENT-MENU-2 — Pakistan-market estimate blocks across the food menu.
 *
 * The owner asked for costs "wherever you can, on Pakistan market basis". So
 * every FOOD item whose name and legacy band give an honest template gets Cost
 * Blocks at 2026 Pakistani catering market ESTIMATES — and every generated
 * block says so in its label ("market estimate — owner to confirm"). Blocks
 * build only where nothing quotable exists: a dish the owner (or the
 * representative set) already priced is never touched, and reruns are no-ops.
 *
 * What deliberately gets NOTHING: fish/prawn dishes (no fish material exists,
 * and inventing one silently was refused before), platters (composition
 * unknown), water/packing (brand-priced bought-ins), pan stall, decoration/
 * service variants, and every Needs-Review row. Those stay visibly
 * needs-setup rather than plausibly wrong.
 *
 * Structure per item: base materials at charged market rates with plausible
 * ratios; Making (charge_role = making) absorbs the balance so the calculated
 * rate equals the band's market figure exactly. No stock, no GL, ever.
 */
trait SeedsMarketEstimates
{
    /** band => [unit, protein => [marketTotal, materials[[name, sku, ratio, chargedRate]]]] */
    private static array $marketBands = [
        'rice_biryani' => ['KG', [
            'chicken' => [600, [['Chicken', 'UAT-RM-CHICKEN', 0.35, 80], ['Basmati Rice', 'UAT-RM-RICE', 0.45, 55]]],
            'beef' => [750, [['Beef', 'UAT-RM-BEEF', 0.35, 120], ['Basmati Rice', 'UAT-RM-RICE', 0.45, 55]]],
            'mutton' => [950, [['Mutton', 'UAT-RM-MUTTON', 0.35, 200], ['Basmati Rice', 'UAT-RM-RICE', 0.45, 55]]],
            'none' => [380, [['Basmati Rice', 'UAT-RM-RICE', 0.5, 55], ['Vegetables', 'UAT-RM-VEG', 0.1, 40]]],
        ]],
        'main_dishes' => ['KG', [
            'chicken' => [1400, [['Chicken', 'UAT-RM-CHICKEN', 0.5, 80], ['Cooking Oil', 'UAT-RM-OIL', 0.05, 90], ['Mixed Masala', 'UAT-RM-MASALA', 0.05, 150]]],
            'beef' => [1700, [['Beef', 'UAT-RM-BEEF', 0.5, 120], ['Cooking Oil', 'UAT-RM-OIL', 0.05, 90], ['Mixed Masala', 'UAT-RM-MASALA', 0.05, 150]]],
            'mutton' => [2600, [['Mutton', 'UAT-RM-MUTTON', 0.5, 200], ['Cooking Oil', 'UAT-RM-OIL', 0.05, 90], ['Mixed Masala', 'UAT-RM-MASALA', 0.05, 150]]],
            'none' => [550, [['Vegetables', 'UAT-RM-VEG', 0.4, 40], ['Cooking Oil', 'UAT-RM-OIL', 0.05, 90], ['Mixed Masala', 'UAT-RM-MASALA', 0.05, 150]]],
        ]],
        'kabab_bbq' => ['KG', [
            'chicken' => [1000, [['Chicken', 'UAT-RM-CHICKEN', 0.55, 80], ['Mixed Masala', 'UAT-RM-MASALA', 0.05, 150]]],
            'beef' => [1300, [['Beef', 'UAT-RM-BEEF', 0.55, 120], ['Mixed Masala', 'UAT-RM-MASALA', 0.05, 150]]],
            'mutton' => [2800, [['Mutton', 'UAT-RM-MUTTON', 0.55, 200], ['Mixed Masala', 'UAT-RM-MASALA', 0.05, 150]]],
            'none' => [1000, [['Chicken', 'UAT-RM-CHICKEN', 0.5, 80], ['Mixed Masala', 'UAT-RM-MASALA', 0.05, 150]]],
        ]],
        'fried_grilled' => ['KG', [
            'chicken' => [1300, [['Chicken', 'UAT-RM-CHICKEN', 0.55, 80], ['Cooking Oil', 'UAT-RM-OIL', 0.1, 90], ['Flour / Maida', 'UAT-RM-FLOUR', 0.05, 35]]],
            'beef' => [1500, [['Beef', 'UAT-RM-BEEF', 0.55, 120], ['Cooking Oil', 'UAT-RM-OIL', 0.1, 90]]],
            'mutton' => [2600, [['Mutton', 'UAT-RM-MUTTON', 0.55, 200], ['Cooking Oil', 'UAT-RM-OIL', 0.1, 90]]],
            'none' => [900, [['Chicken', 'UAT-RM-CHICKEN', 0.4, 80], ['Cooking Oil', 'UAT-RM-OIL', 0.1, 90]]],
        ]],
        'breads' => ['PCS', [
            'none' => [80, [['Flour / Maida', 'UAT-RM-FLOUR', 0.12, 35]]],
        ]],
        'desserts' => ['KG', [
            'none' => [800, [['Sugar', 'UAT-RM-SUGAR', 0.2, 45], ['Cream', 'UAT-RM-CREAM', 0.1, 110]]],
        ]],
        'raita' => ['KG', [
            'none' => [500, [['Yogurt', 'UAT-RM-YOGURT', 0.8, 60]]],
        ]],
        'salads' => ['KG', [
            'none' => [400, [['Vegetables', 'UAT-RM-VEG', 0.5, 40]]],
        ]],
        'chutneys_sauces' => ['KG', [
            'none' => [350, [['Vegetables', 'UAT-RM-VEG', 0.3, 40]]],
        ]],
        'starters_drinks_soups' => ['PCS', [
            'chicken' => [250, [['Chicken', 'UAT-RM-CHICKEN', 0.08, 80]]],
            'beef' => [250, [['Beef', 'UAT-RM-BEEF', 0.08, 120]]],
            'none' => [100, [['Sugar', 'UAT-RM-SUGAR', 0.03, 45]]],
        ]],
        'snacks_live_counter' => ['PCS', [
            'chicken' => [150, [['Chicken', 'UAT-RM-CHICKEN', 0.06, 80], ['Flour / Maida', 'UAT-RM-FLOUR', 0.04, 35]]],
            'beef' => [150, [['Beef', 'UAT-RM-BEEF', 0.06, 120], ['Flour / Maida', 'UAT-RM-FLOUR', 0.04, 35]]],
            'none' => [120, [['Flour / Maida', 'UAT-RM-FLOUR', 0.05, 35], ['Vegetables', 'UAT-RM-VEG', 0.03, 40]]],
        ]],
        'tea_coffee' => ['PCS', [
            'none' => [60, [['Sugar', 'UAT-RM-SUGAR', 0.02, 45]]],
        ]],
    ];

    /** Bread market prices differ a lot per bread; refine by name. */
    private static array $breadRates = [
        'ROTI' => 30, 'NAAN' => 70, 'NAN' => 70, 'TAFTAN' => 120,
        'SHEERMAL' => 150, 'KULCHA' => 80, 'PARATHA' => 100, 'PURI' => 60,
    ];

    /** @return array{priced:int, skipped_no_template:int, skipped_owner_authored:int} */
    public function runMarketEstimates(): array
    {
        $stats = ['priced' => 0, 'skipped_no_template' => 0, 'skipped_owner_authored' => 0];

        foreach (KashifClientMenuSeeder::catalogueRows() as $sku => $item) {
            $product = Product::where('sku', $sku)->first();
            if (! $product) {
                continue;
            }

            $profile = CateringProductProfile::where('product_id', $product->id)->first();
            if ($profile?->catering_enabled) {
                continue; // already quotable — representatives / owner work
            }

            $template = $this->marketTemplate($item);
            if ($template === null) {
                $stats['skipped_no_template']++;

                continue;
            }

            // A block set that exists and does not carry our estimate wording is
            // a person's work; the machine never overwrites a person.
            $existing = CateringProductCostBlock::where('product_id', $product->id)
                ->where('is_active', true)->get();
            if ($existing->isNotEmpty() && ! str_contains($existing->pluck('label')->implode(';'), 'owner to confirm')) {
                $stats['skipped_owner_authored']++;

                continue;
            }

            [$unitCode, $total, $materials] = $template;

            if ($product->unit_id === null) {
                $product->unit_id = $this->unit($unitCode)->id;
                $product->save();
            }

            $this->blocksFor($product, [
                'unit' => $unitCode,
                'rate' => $total,
                'evidence' => 'market',
                'materials' => $materials,
            ]);

            $profile = $profile ?? new CateringProductProfile;
            $profile->fill([
                'product_id' => $product->id,
                'catering_enabled' => true,
                'pricing_mode' => 'fixed',
                'costing_mode' => 'blocks',
                'default_quote_unit_id' => $this->unit($unitCode)->id,
            ])->save();

            $stats['priced']++;
        }

        return $stats;
    }

    /** @return array{0:string, 1:float, 2:array}|null [unit, marketTotal, materials] */
    private function marketTemplate(array $item): ?array
    {
        $meta = $item['meta'];
        if ($meta === null || $this->categoryFor($meta) === KashifClientMenuSeeder::NEEDS_REVIEW) {
            return null; // dirty rows stay parked, never plausibly priced
        }

        $band = self::$marketBands[$meta['suggested_group'] ?? ''] ?? null;
        if ($band === null) {
            return null; // platters, non-food, water/packing, pan stall …
        }

        $name = strtoupper($item['name']);
        $protein = match (true) {
            str_contains($name, 'FISH') || str_contains($name, 'PRAWN') || str_contains($name, 'JHINGA') => 'fish',
            str_contains($name, 'CHICKEN') || str_contains($name, 'MURGH') => 'chicken',
            str_contains($name, 'BEEF') => 'beef',
            str_contains($name, 'MUTTON') || str_contains($name, 'LAMB') => 'mutton',
            default => 'none',
        };

        if ($protein === 'fish') {
            return null; // no fish/prawn material exists; nothing is invented silently
        }

        [$unitCode, $byProtein] = $band;
        $def = $byProtein[$protein] ?? $byProtein['none'] ?? null;
        if ($def === null) {
            return null;
        }

        [$total, $materials] = $def;

        if (($meta['suggested_group'] ?? '') === 'breads') {
            foreach (self::$breadRates as $word => $rate) {
                if (str_contains($name, $word)) {
                    $total = $rate;
                    break;
                }
            }
        }

        return [$unitCode, (float) $total, $materials];
    }
}
