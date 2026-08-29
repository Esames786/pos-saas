<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\Category;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * KASHIF-LEGACY-REBUILD-1 — the client's catalogue, rebuilt from their own book.
 *
 * The earlier import layered guesses (hashed SKUs, inferred categories, market
 * estimates) over reconciled spreadsheets. The real database says everything
 * plainly, so this replaces all of it:
 *
 *   ID        the legacy OrderItemId IS the product's SKU. 909 rows, 909
 *             distinct ids — a repeat is impossible by construction, and
 *             typing 361 finds item 361.
 *   CATEGORY  the item's own OrderCatagaryId — no sequence inference.
 *   MONEY     OrderRate = MeatRate + ServiceRate holds on ALL 909 rows, so a
 *             dish becomes exactly two blocks: its main material at MeatRate
 *             and Making at ServiceRate. Nothing is averaged, nothing invented.
 *   MATERIAL  cat1 names the meat (CHICKEN / BEEF / MUTTON / FISH / PRAWN),
 *             and tbl_Item gives that material its real purchase rate. Where
 *             the book names no material (sweets, breads, tissue, service
 *             lines) the money is still exact — it is simply carried as a
 *             charge, and the screen says the material is unknown rather than
 *             pretending to know it.
 *   FLAGS     CatAllow → allow_party_supply, Complimentry → is_complimentary,
 *             KitchenId → production_station.
 *   UNITS     the item's own unit, normalised (KGS/KG → KG, NOS/NO/PIS → PCS,
 *             P/H → PH…) and created where missing.
 *
 * No GL. No stock. Ever.
 */
class KashifLegacyRebuildSeeder extends Seeder
{
    /** legacy unit spelling => [our code, unit_type, name] */
    private const UNITS = [
        'KGS' => ['KG', 'weight', 'Kilogram'], 'KG' => ['KG', 'weight', 'Kilogram'],
        'PCS' => ['PCS', 'quantity', 'Pieces'], 'PIS' => ['PCS', 'quantity', 'Pieces'],
        'PEC' => ['PCS', 'quantity', 'Pieces'], 'PCT' => ['PCS', 'quantity', 'Pieces'],
        'NOS' => ['PCS', 'quantity', 'Pieces'], 'NOS.' => ['PCS', 'quantity', 'Pieces'],
        'NO' => ['PCS', 'quantity', 'Pieces'], 'NO.' => ['PCS', 'quantity', 'Pieces'],
        '1' => ['PCS', 'quantity', 'Pieces'],
        'LTR' => ['LTR', 'volume', 'Litre'],
        'P/H' => ['PH', 'quantity', 'Per Head'], 'PH' => ['PH', 'quantity', 'Per Head'],
        'PER' => ['PH', 'quantity', 'Per Head'], 'PERSON' => ['PH', 'quantity', 'Per Head'],
        'PER HD' => ['PH', 'quantity', 'Per Head'], 'PAR' => ['PH', 'quantity', 'Per Head'],
        'CUP' => ['CUP', 'quantity', 'Cup'], 'CUPS' => ['CUP', 'quantity', 'Cup'],
        'PLATE' => ['PLATE', 'quantity', 'Plate'],
        'CRATE' => ['CRATE', 'quantity', 'Crate'],
        'PKT' => ['PKT', 'quantity', 'Packet'], 'PK' => ['PKT', 'quantity', 'Packet'],
        'BAIG' => ['BAG', 'quantity', 'Bag'],
    ];

    /**
     * cat1 spelling => the raw material's exact name in tbl_Item.
     *
     * Named exactly, because a substring search picks whatever comes first:
     * "BEEF" would find BEEF BIHAARI BOTI (100) instead of BEEF (WITH BONE)
     * (1450) — the very rate the legacy item screen shows for item 361.
     */
    private const MEATS = [
        'CHICKEN' => 'CHICKEN (REGULAR)',
        'BONELESS' => 'CHICKEN BONELESS',
        'BEEF' => 'BEEF (WITH BONE)',
        'MUTTON' => 'MUTTON',
        'FISH' => 'FISH PACKET',
        'PRAWN' => 'PRAWN-MEDIUM',
    ];

    private const LEGACY_MARK = 'Kashif legacy catalogue';

    private array $unitCache = [];

    private function csv(string $name): array
    {
        $rows = [];
        $fh = fopen(base_path("docs/data/{$name}.csv"), 'r');
        $header = fgetcsv($fh);
        while (($r = fgetcsv($fh)) !== false) {
            $rows[] = array_combine($header, array_pad($r, count($header), null));
        }
        fclose($fh);

        return $rows;
    }

    private function unit(string $legacy): ?Unit
    {
        $key = strtoupper(trim($legacy));
        [$code, $type, $name] = self::UNITS[$key] ?? ['PCS', 'quantity', 'Pieces'];

        return $this->unitCache[$code] ??= Unit::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'unit_type' => $type, 'is_active' => true],
        );
    }

    /**
     * Remove everything the previous imports built, so the rebuild is a rebuild
     * and not a layer. Money and stock are untouched by construction: this only
     * deletes catalogue, catering configuration and the bookings that pointed
     * at them.
     *
     * @return array<string,int>
     */
    public function wipe(): array
    {
        $db = DB::connection('tenant');
        $counts = [];

        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'catering_event_revisions',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_estimate_line_instruction',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_final_invoices',
            'catering_commercial_rate_applications',
            'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_material_commercial_rates',
            'product_barcodes', 'product_variants', 'products', 'customers',
        ] as $table) {
            if (! $db->getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $counts[$table] = $db->table($table)->count();
            $db->table($table)->delete();
        }
        $counts['categories'] = Category::count();
        Category::query()->delete();
        $db->statement('SET FOREIGN_KEY_CHECKS=1');

        return $counts;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string,int|string> */
    public function run(): array
    {
        $stats = [
            'categories' => 0, 'materials' => 0, 'products' => 0, 'quotable' => 0,
            'needs_setup' => 0, 'with_material' => 0, 'charge_only' => 0,
            'party_on' => 0, 'complimentary' => 0, 'customers' => 0,
        ];

        // 1 · Categories, with the client's own codes as sort order.
        $categories = [];
        foreach ($this->csv('legacy-categories') as $row) {
            $category = Category::firstOrNew(['slug' => Str::slug('legacy-'.$row['name'])]);
            $category->fill([
                'name' => $row['name'],
                'sort_order' => (int) $row['category_id'],
                'is_active' => true,
            ])->save();
            $categories[(int) $row['category_id']] = $category;
            $stats['categories']++;
        }
        $unfiled = Category::firstOrCreate(
            ['slug' => 'legacy-unfiled'],
            ['name' => 'UNFILED', 'sort_order' => 99, 'is_active' => true],
        );

        // 2 · The raw materials the dishes actually name, at the house's own
        //     purchase rates — so "Costs us" is a real number, not a guess.
        $materialRows = collect($this->csv('legacy-materials'))->keyBy(fn ($r) => strtoupper($r['name']));
        $materials = [];
        foreach (self::MEATS as $tag => $materialName) {
            $row = $materialRows->get(strtoupper($materialName));
            // A fallback must still find a material the house actually PRICES:
            // a zero-rate row would make "Costs us" silently free.
            if (! $row || (float) $row['rate'] <= 0) {
                $row = $materialRows->first(fn ($r) => str_contains(strtoupper($r['name']), $tag) && (float) $r['rate'] > 0)
                    ?? $row;
            }
            if (! $row) {
                continue;
            }
            $unit = $this->unit($row['unit'] ?: 'KGS');
            $product = Product::firstOrNew(['sku' => 'RM-'.$tag]);
            $product->fill([
                'name' => Str::title(strtolower($row['name'])),
                'slug' => Str::slug('rm-'.$tag),
                'category_id' => $unfiled->id,
                'product_kind' => 'raw_material',
                'unit_id' => $unit->id,
                'is_stock_tracked' => true,
                'is_sellable' => false,
                'is_purchasable' => true,
                'description' => self::LEGACY_MARK.' — raw material '.$row['item_id'],
            ])->save();

            if ((float) $row['rate'] > 0) {
                CateringMaterialRate::updateOrCreate(
                    ['product_id' => $product->id, 'effective_from' => now()->subYear()->toDateString()],
                    ['rate' => (float) $row['rate'], 'unit_id' => $unit->id],
                );
            }
            $materials[$tag] = $product;
            $stats['materials']++;
        }

        // 3 · The dishes: id, category, unit, flags and the two blocks its own
        //     book proves (OrderRate = MeatRate + ServiceRate on every row).
        $kitchens = collect($this->csv('legacy-kitchens'))->pluck('name', 'kitchen_id');

        foreach ($this->csv('legacy-order-items') as $row) {
            $legacyId = (int) $row['order_item_id'];
            $unit = $this->unit($row['unit'] ?: 'PCS');
            $name = Str::title(strtolower(trim($row['name'])));

            // THE ID IS THE SKU — one row per legacy id, so a repeat cannot
            // happen and the operator's own code finds the item.
            $product = Product::firstOrNew(['sku' => (string) $legacyId]);
            $product->fill([
                'name' => $name,
                'slug' => Str::slug($name.'-'.$legacyId),
                'category_id' => ($categories[(int) $row['category_id']] ?? $unfiled)->id,
                'product_kind' => 'sale_item',
                'unit_id' => $unit->id,
                'is_stock_tracked' => false,
                'is_sellable' => true,
                'default_selling_price' => (float) $row['order_rate'],
                'description' => self::LEGACY_MARK.' — item '.$legacyId
                    .' (sequence '.$row['sequence_no'].')',
            ])->save();
            $stats['products']++;

            $orderRate = (float) $row['order_rate'];
            $meatRate = (float) $row['meat_rate'];
            $makingRate = (float) $row['service_rate'];
            $meatTag = strtoupper(trim((string) $row['meat_type']));
            $material = $materials[$meatTag] ?? null;

            $profile = CateringProductProfile::firstOrNew(['product_id' => $product->id]);
            $profile->fill([
                'catering_enabled' => $orderRate > 0,
                'pricing_mode' => 'fixed',
                'costing_mode' => 'blocks',
                'default_quote_unit_id' => $unit->id,
                'allow_party_supply' => $row['cat_allow'] === 'Y',
                'is_complimentary' => $row['complimentary'] === 'Y',
                'production_station' => $row['kitchen_allow'] === 'Y'
                    ? ($kitchens[(int) $row['kitchen_id']] ?? null)
                    : null,
            ])->save();

            if ($row['cat_allow'] === 'Y') {
                $stats['party_on']++;
            }
            if ($row['complimentary'] === 'Y') {
                $stats['complimentary']++;
            }
            if ($orderRate <= 0) {
                $stats['needs_setup']++;

                continue;
            }
            $stats['quotable']++;

            $sort = 1;
            if ($meatRate > 0) {
                if ($material) {
                    // The dish's main material, charged the way the book charges
                    // it: per unit of the DISH. One unit of material per unit of
                    // dish is what the legacy screen's Qty% said.
                    CateringProductCostBlock::create([
                        'product_id' => $product->id,
                        'label' => $material->name,
                        'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
                        'material_product_id' => $material->id,
                        'unit_id' => $material->unit_id,
                        'quantity_per_unit' => 1,
                        'rate' => $meatRate,
                        'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                        'rate_basis' => CateringProductCostBlock::RATE_PER_DISH_UNIT,
                        'sort_order' => $sort++,
                    ]);
                    $stats['with_material']++;
                } else {
                    // The money is exact; the material is not named in the book.
                    // Say so rather than inventing one.
                    CateringProductCostBlock::create([
                        'product_id' => $product->id,
                        'label' => 'Material — not named in the legacy book',
                        'block_type' => CateringProductCostBlock::TYPE_CHARGE,
                        'rate' => $meatRate,
                        'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                        'sort_order' => $sort++,
                    ]);
                    $stats['charge_only']++;
                }
            }

            if ($makingRate > 0 || $meatRate <= 0) {
                CateringProductCostBlock::create([
                    'product_id' => $product->id,
                    'label' => 'Making',
                    'block_type' => CateringProductCostBlock::TYPE_CHARGE,
                    'charge_role' => CateringProductCostBlock::ROLE_MAKING,
                    'rate' => $meatRate > 0 ? $makingRate : $orderRate,
                    'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                    'sort_order' => 90,
                ]);
            }
        }

        // 4 · Customers, exactly as the order book knows them.
        foreach (array_chunk($this->csv('legacy-customers'), 500) as $chunk) {
            $insert = [];
            foreach ($chunk as $c) {
                $insert[] = [
                    'code' => 'C-'.$c['phone'],
                    'name' => mb_substr($c['name'], 0, 190),
                    'phone' => $c['phone'],
                    'address' => $c['address'] !== '' ? $c['address'] : null,
                    'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            Customer::insert($insert);
            $stats['customers'] += count($insert);
        }

        return $stats;
    }
}
