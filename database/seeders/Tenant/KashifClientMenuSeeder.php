<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\Category;
use App\Models\Tenant\CateringInstruction;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * KASHIF-CLIENT-MENU-1 — the client's REAL menu, imported without inventing
 * commercial truth.
 *
 * Source of authority: docs/data/kashif-active-menu-owner-input.csv (the 888
 * reconciled unique items) + docs/data/kashif-catalogue-staging.csv (per-row
 * provenance: legacy code, sequence band, transcription problems). The legacy
 * export carried Code #, Description and Sequence and NOTHING else — no unit,
 * no price, no materials — so every item lands VISIBLE but NOT quotation-ready:
 *
 *   unit_id            NULL            no honest unit exists, so none is faked
 *   selling price      0.00 (schema default; the disabled profile is the guard)
 *   is_stock_tracked   false           no stock claims from a name list
 *   catering profile   catering_enabled = FALSE, costing_mode = blocks
 *   description        legacy code + sequence + raw spelling, verbatim
 *
 * IDEMPOTENCY is keyed on a deterministic hash of (legacy code | raw name),
 * with an ordinal suffix where the source itself collides (two TRUNCATED-code
 * "Malai Kofta Chicken" rows) — a reused legacy code can never overwrite a
 * different item, and reruns update in place. Nothing is deleted, ever.
 *
 * A small REPRESENTATIVE set is additionally made quotation-ready with Cost
 * Blocks (never recipes — the client runs Cost-Block-first). Where a charge
 * comes from the client's own legacy Order 8701, the TOTAL rate is evidence;
 * the split between materials and Making is ours and every assumed block says
 * so in its label: "UAT assumption — owner to confirm".
 *
 * No GL. No stock movement. No Rate Impact application. No email. Historical
 * events and their snapshots are copies and are never touched.
 */
class KashifClientMenuSeeder extends Seeder
{
    use Concerns\SeedsMarketEstimates;

    public const SKU_PREFIX = 'KM-';

    public const LEGACY_8701_MARK = 'Legacy Order 8701 reference — client UAT';

    public const LEGACY_8704_MARK = 'Legacy Order 8704 reference — client UAT';

    private const ASSUMPTION = 'UAT assumption — owner to confirm';

    /** suggested_group => operator-friendly category name. */
    private const GROUPS = [
        'starters_drinks_soups' => 'Drinks / Starters',
        'rice_biryani' => 'Rice & Biryani',
        'main_dishes' => 'Karahi / Qorma / Handi',
        'kabab_bbq' => 'BBQ',
        'fried_grilled' => 'Fried / Grilled',
        'snacks_live_counter' => 'Snacks / Live Counter',
        'pan_stall' => 'Snacks / Live Counter',
        'desserts' => 'Desserts',
        'breads' => 'Bread',
        'raita' => 'Raita',
        'salads' => 'Salads',
        'chutneys_sauces' => 'Chutneys / Sauces',
        'tea_coffee' => 'Tea / Coffee',
        'water_drinks_packing' => 'Water / Packing',
        'misc_decoration' => 'Decoration / Service',
        'non_food_service_material' => 'Decoration / Service',
        'platters_assorted' => 'Platters',
    ];

    public const NEEDS_REVIEW = 'Needs Review';

    /**
     * Quotation-ready representative set. Rate provenance:
     *   8701  the client's own legacy order — the TOTAL is evidence
     *   uat   pure demonstration figure — assumption end to end
     * Blocks: [label, materialSkuOrNull, ratio, chargedRate, basis]; Making
     * takes the balance so calculated == the stated rate exactly.
     *
     * @var array<string, array>
     */
    public const REPRESENTATIVES = [
        'Biryani Masala Beef' => ['unit' => 'KG', 'rate' => 3375, 'evidence' => '8701', 'materials' => [
            ['Beef', 'UAT-RM-BEEF', 0.5, 120], ['Basmati Rice', 'UAT-RM-RICE', 0.4, 55], ['Mixed Masala', 'UAT-RM-MASALA', 0.05, 150],
        ]],
        'Karahi Chicken' => ['unit' => 'KG', 'rate' => 1405, 'evidence' => '8701', 'materials' => [
            ['Chicken', 'UAT-RM-CHICKEN', 0.5, 80], ['Cooking Oil', 'UAT-RM-OIL', 0.05, 90], ['Mixed Masala', 'UAT-RM-MASALA', 0.05, 150],
        ]],
        'Naan Milky' => ['unit' => 'PCS', 'rate' => 300, 'evidence' => '8701', 'materials' => [
            ['Flour / Maida', 'UAT-RM-FLOUR', 0.1, 35],
        ]],
        'Taftan' => ['unit' => 'PCS', 'rate' => 510, 'evidence' => '8701', 'materials' => [
            ['Flour / Maida', 'UAT-RM-FLOUR', 0.1, 35],
        ]],
        'Salad Green' => ['unit' => 'PCS', 'rate' => 20, 'evidence' => '8701', 'materials' => [
            ['Vegetables', 'UAT-RM-VEG', 0.2, 40],
        ]],
        'Raita' => ['unit' => 'KG', 'rate' => 550, 'evidence' => '8701', 'materials' => [
            ['Yogurt', 'UAT-RM-YOGURT', 0.8, 60],
        ]],
        'Lab-e-Shireen' => ['unit' => 'KG', 'rate' => 1100, 'evidence' => '8701', 'materials' => [
            ['Cream', 'UAT-RM-CREAM', 0.3, 110], ['Sugar', 'UAT-RM-SUGAR', 0.2, 45],
        ]],
        'Bihari Chicken Tikka' => ['unit' => 'KG', 'rate' => 855, 'evidence' => '8701', 'materials' => [
            ['Chicken', 'UAT-RM-CHICKEN', 0.6, 80], ['Mixed Masala', 'UAT-RM-MASALA', 0.05, 150],
        ]],
        'Kunna Mutton' => ['unit' => 'KG', 'rate' => 1500, 'evidence' => 'uat', 'materials' => [
            ['Mutton', 'UAT-RM-MUTTON', 0.5, 200],
        ]],
        'Turkish Kabab' => ['unit' => 'KG', 'rate' => 900, 'evidence' => 'uat', 'materials' => [
            ['Chicken', 'UAT-RM-CHICKEN', 0.5, 80], ['Mixed Masala', 'UAT-RM-MASALA', 0.05, 150],
        ]],
        'Welcome Drink' => ['unit' => 'PCS', 'rate' => 100, 'evidence' => 'uat', 'materials' => [
            ['Sugar', 'UAT-RM-SUGAR', 0.05, 45],
        ]],
        'Mutton' => ['unit' => 'KG', 'rate' => 250, 'evidence' => 'uat', 'materials' => [
            // Raw material sold directly: 1:1 wrapper over the store material.
            ['Mutton', 'UAT-RM-MUTTON', 1.0, 250],
        ], 'no_making' => true],
        'Tandoor Live' => ['unit' => 'PCS', 'rate' => 0, 'evidence' => 'uat', 'materials' => [], 'charges' => [
            ['Live counter setup', 15000, 'lump_sum'],
        ]],
        'Waiters and Waitresses' => ['unit' => 'PCS', 'rate' => 0, 'evidence' => 'uat', 'materials' => [], 'charges' => [
            ['Waiter service', 1200, 'per_unit'],
        ]],
        'Decoration and Arrangement' => ['unit' => 'PCS', 'rate' => 0, 'evidence' => 'uat', 'materials' => [], 'charges' => [
            ['Decoration & arrangement', 25000, 'lump_sum'],
        ]],
        'Packing Material Family Pack Large' => ['unit' => 'PCS', 'rate' => 0, 'evidence' => 'uat', 'materials' => [], 'charges' => [
            ['Packing', 50, 'per_unit'],
        ]],
    ];

    /** @return array<string, array{code:string,name:string,key:string,meta:?array}> keyed by deterministic sku */
    public static function catalogueRows(): array
    {
        $meta = [];
        $fh = fopen(base_path('docs/data/kashif-catalogue-staging.csv'), 'r');
        $header = fgetcsv($fh);
        while (($r = fgetcsv($fh)) !== false) {
            if (count($r) < 5) {
                continue;
            }
            $row = array_combine($header, array_pad($r, count($header), null));
            // Indexed by BOTH spellings: the owner sheet carries the corrected
            // name, the staging row the raw one — an item must find its band
            // either way, or a spelling fix would silently cost it its price.
            $meta[self::sourceKey($row['source_code'], $row['description_raw'])] ??= $row;
            $meta[self::sourceKey($row['source_code'], $row['normalized_name'])] ??= $row;
        }
        fclose($fh);

        $items = [];
        $seen = [];
        $fh = fopen(base_path('docs/data/kashif-active-menu-owner-input.csv'), 'r');
        fgetcsv($fh); // header
        while (($r = fgetcsv($fh)) !== false) {
            $code = trim((string) ($r[1] ?? ''));
            $name = trim((string) ($r[2] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = self::sourceKey($code, $name);
            // The source itself collides once (two TRUNCATED-code rows with the
            // same name): a deterministic ordinal keeps both, in file order.
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($seen[$key] > 1) {
                $key .= '#'.$seen[$key];
            }

            $items[self::SKU_PREFIX.substr(sha1($key), 0, 10)] = [
                'code' => $code,
                'name' => $name,
                'key' => $key,
                'meta' => $meta[self::sourceKey($code, $name)] ?? null,
            ];
        }
        fclose($fh);

        return $items;
    }

    public static function sourceKey(?string $code, ?string $name): string
    {
        return strtolower(trim((string) $code)).'|'.strtolower(preg_replace('/\s+/', ' ', trim((string) $name)));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. The full catalogue — visible, never quotable.
    // ─────────────────────────────────────────────────────────────────────────

    public function run(): void
    {
        $categories = $this->ensureCategories();

        foreach (self::catalogueRows() as $sku => $item) {
            $meta = $item['meta'];
            $categoryName = $this->categoryFor($meta);

            $product = Product::firstOrNew(['sku' => $sku]);
            $isNew = ! $product->exists;

            $product->name = $item['name'];
            $product->slug = Str::slug($item['name'].'-'.substr($sku, 3));
            $product->category_id = $categories[$categoryName]->id;
            $product->product_kind = 'sale_item';
            if ($isNew) {
                // Only on create: reruns must not undo an owner's later edits.
                $product->unit_id = null;           // no honest unit exists yet
                $product->is_stock_tracked = false; // a name list claims no stock
                $product->default_selling_price = 0;
            }
            $product->description = trim(sprintf(
                'Legacy Kashif catalogue — code %s, sequence %s. Source spelling: "%s".%s NEEDS SETUP: unit and customer charge unconfirmed — not quotation-ready until the owner completes them.',
                $item['code'] !== '' ? $item['code'] : 'unreadable',
                $meta['sequence_raw'] ?? '?',
                $meta['description_raw'] ?? $item['name'],
                ! empty($meta['data_problem']) ? ' Transcription flags: '.$meta['data_problem'].'.' : ''
            ));
            $product->save();

            // Visible on the Catering Products screen, but NOT offered for new
            // quotations: catering_enabled=false is the quotation gate.
            $profile = CateringProductProfile::firstOrNew(['product_id' => $product->id]);
            if (! $profile->exists) {
                $profile->fill([
                    'catering_enabled' => false,
                    'pricing_mode' => 'fixed',
                    'costing_mode' => 'blocks',
                ])->save();
            }
        }
    }

    private function categoryFor(?array $meta): string
    {
        if ($meta === null) {
            return self::NEEDS_REVIEW;
        }
        $problem = strtoupper((string) ($meta['data_problem'] ?? ''));
        $action = strtoupper((string) ($meta['recommended_action'] ?? ''));

        foreach (['MISFILED', 'AMBIGUOUS', 'NOT_A_PRODUCT', 'TWO_', 'COMBINED_ITEMS', 'UNEXPLAINED'] as $flag) {
            if (str_contains($problem, $flag)) {
                return self::NEEDS_REVIEW;
            }
        }
        if (str_contains($action, 'REJECT_OR_REVIEW')) {
            return self::NEEDS_REVIEW;
        }

        return self::GROUPS[$meta['suggested_group'] ?? ''] ?? self::NEEDS_REVIEW;
    }

    /** @return array<string, Category> */
    private function ensureCategories(): array
    {
        $out = [];
        $order = 0;
        foreach (array_merge(array_values(array_unique(self::GROUPS)), [self::NEEDS_REVIEW]) as $name) {
            $order++;
            $out[$name] = Category::firstOrCreate(
                ['slug' => Str::slug('client-menu-'.$name)],
                ['name' => $name, 'sort_order' => 500 + $order, 'is_active' => true],
            );
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Representative real products — quotation-ready, assumptions labelled.
    // ─────────────────────────────────────────────────────────────────────────

    public function runRepresentatives(): void
    {
        $rows = self::catalogueRows();

        foreach (self::REPRESENTATIVES as $name => $def) {
            $sku = null;
            foreach ($rows as $candidateSku => $item) {
                if (strcasecmp($item['name'], $name) === 0) {
                    $sku = $candidateSku;
                    break;
                }
            }

            // LAB-E-SHEEREEN etc. exist in the catalogue; an order-only name
            // that somehow is not there still becomes a real product — the
            // client's order is itself source evidence for the NAME.
            $product = $sku
                ? Product::where('sku', $sku)->first()
                : null;
            if (! $product) {
                $product = Product::firstOrNew(['sku' => self::SKU_PREFIX.substr(sha1(self::sourceKey('order', $name)), 0, 10)]);
                $product->name = $name;
                $product->slug = Str::slug($name.'-'.substr($product->sku, 3));
                $product->product_kind = 'sale_item';
                $product->is_stock_tracked = false;
                $product->default_selling_price = 0;
            }

            $product->unit_id = $this->unit($def['unit'])->id;
            $product->save();

            $profile = CateringProductProfile::firstOrNew(['product_id' => $product->id]);
            $profile->fill([
                'catering_enabled' => true,
                'pricing_mode' => 'fixed',
                'costing_mode' => 'blocks',
                'default_quote_unit_id' => $this->unit($def['unit'])->id,
            ])->save();

            $this->blocksFor($product, $def);
        }
    }

    private function blocksFor(Product $product, array $def): void
    {
        // Idempotent: this seeder owns these blocks; rebuild deterministically,
        // but ONLY when the set differs — an owner's later manual edit wins.
        $suffix = ($def['evidence'] ?? null) === 'market'
            ? 'market estimate — owner to confirm'
            : self::ASSUMPTION;

        $wanted = [];
        $order = 0;
        $materialCharge = 0.0;

        foreach ($def['materials'] as [$label, $sku, $ratio, $charged]) {
            $order++;
            $material = $this->material($label, $sku);
            $materialCharge += $ratio * $charged;
            $wanted[] = [
                'label' => $label.' — '.$suffix,
                'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
                'material_product_id' => $material->id,
                'quantity_per_unit' => $ratio,
                'unit_id' => $this->unit('KG')->id,
                'rate' => $charged,
                'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
                'charge_role' => null,
                'sort_order' => $order,
            ];
        }

        // Making absorbs the balance so calculated == the stated rate exactly:
        // the TOTAL is the evidenced number; the split is the labelled guess.
        if (empty($def['no_making']) && ($def['rate'] ?? 0) > 0) {
            $order++;
            $making = round($def['rate'] - $materialCharge, 2);
            $evidence = ($def['evidence'] ?? '') === '8701'
                ? 'total from legacy order 8701; split is a '.self::ASSUMPTION
                : $suffix;
            $wanted[] = [
                'label' => 'Making — '.$evidence,
                'block_type' => CateringProductCostBlock::TYPE_CHARGE,
                'material_product_id' => null,
                'quantity_per_unit' => null,
                'unit_id' => null,
                'rate' => max($making, 0),
                'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                'rate_basis' => CateringProductCostBlock::RATE_PER_DISH_UNIT,
                'charge_role' => CateringProductCostBlock::ROLE_MAKING,
                'sort_order' => $order,
            ];
        }

        foreach ($def['charges'] ?? [] as [$label, $rate, $basis]) {
            $order++;
            $wanted[] = [
                'label' => $label.' — '.$suffix,
                'block_type' => CateringProductCostBlock::TYPE_CHARGE,
                'material_product_id' => null,
                'quantity_per_unit' => null,
                'unit_id' => null,
                'rate' => $rate,
                'charge_basis' => $basis === 'lump_sum'
                    ? CateringProductCostBlock::BASIS_LUMP_SUM
                    : CateringProductCostBlock::BASIS_PER_UNIT,
                'rate_basis' => CateringProductCostBlock::RATE_PER_DISH_UNIT,
                'charge_role' => null,
                'sort_order' => $order,
            ];
        }

        $existing = CateringProductCostBlock::where('product_id', $product->id)
            ->where('is_active', true)->orderBy('sort_order')->get();

        $fingerprint = fn ($set) => collect($set)->map(fn ($b) => implode('|', [
            $b['label'], $b['block_type'], (string) $b['material_product_id'],
            (string) $b['quantity_per_unit'], (string) $b['rate'], $b['charge_basis'], (string) $b['charge_role'],
        ]))->implode(';');
        $existingPrint = $existing->map(fn ($b) => implode('|', [
            $b->label, $b->block_type, (string) $b->material_product_id,
            (string) $b->quantity_per_unit, (string) $b->rate, $b->charge_basis, (string) $b->charge_role,
        ]))->implode(';');

        if ($fingerprint($wanted) === $existingPrint) {
            return; // already exactly as seeded — nothing to touch
        }
        if ($existing->isNotEmpty() && ! str_contains($existingPrint, 'owner to confirm')) {
            return; // an owner-authored setup exists — never overwrite a person
        }

        CateringProductCostBlock::where('product_id', $product->id)->delete();
        foreach ($wanted as $attrs) {
            CateringProductCostBlock::create($attrs + ['product_id' => $product->id, 'is_active' => true]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. The legacy reference orders — draft, no finance, no stock.
    // ─────────────────────────────────────────────────────────────────────────

    public function runLegacyOrders(): void
    {
        $this->legacy8701();
        $this->legacy8704();
    }

    /** The client's own Order Estimate 8701: 253,515 items + 5,500 fare + 2,500 service = 261,515. */
    private function legacy8701(): void
    {
        if (\App\Models\Tenant\CateringEvent::where('notes', 'like', '%'.self::LEGACY_8701_MARK.'%')->exists()) {
            return;
        }

        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => \App\Models\Tenant\Branch::query()->value('id'),
            'customer_name' => 'MR ALI KASHIF (legacy reference — UAT)',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(10)->toDateString(),
            'pax' => 300,
            'notes' => self::LEGACY_8701_MARK.' — rebuilt from the client\'s old software estimate. Quantities and rates are the legacy document\'s own figures.',
        ]);

        $lines = [];
        foreach ([
            ['Biryani Masala Beef', 20, 3375, ['Chawal Dana Dana', 'Mirch Kam']],
            ['Karahi Chicken', 34, 1405, ['Mirch Kam', 'Oil Kam']],
            ['Naan Milky', 10, 300, []],
            ['Taftan', 22, 510, []],
            ['Salad Green', 300, 20, []],
            ['Raita', 6, 550, ['Namak Kam']],
            ['Lab-e-Shireen', 46, 1100, []],
            ['Bihari Chicken Tikka', 75, 855, ['Koyala']],
        ] as [$name, $qty, $rate, $instructions]) {
            $product = Product::where('name', $name)->orderBy('id')->first();
            $lines[] = [
                'product_id' => $product?->id,
                'item_name' => $name,
                'quantity' => $qty,
                'unit_id' => $product?->unit_id,
                'unit_code' => $product?->unit?->code,
                'rate' => $rate,
                'instruction_ids' => CateringInstruction::whereIn('label', $instructions)->pluck('id')->all(),
            ];
        }

        $estimates->saveDraftLines($event->currentEstimate, $lines, [
            // The legacy paper's own extras, through the CURRENT charge fields.
            'service_charge_amount' => 2500,
            'other_charge_label' => 'Fare',
            'other_charge_amount' => 5500,
        ]);
    }

    /**
     * Legacy Estimate 8704 — the screenshot's item CODES resolved through the
     * reconciled catalogue. Its quantities/rates were not transcribed, so the
     * lines land at qty 1 / rate 0 with the note saying exactly that: a
     * reference skeleton for the walkthrough, never a priced quotation.
     */
    private function legacy8704(): void
    {
        if (\App\Models\Tenant\CateringEvent::where('notes', 'like', '%'.self::LEGACY_8704_MARK.'%')->exists()) {
            return;
        }

        $rows = self::catalogueRows();
        $byCode = [];
        foreach ($rows as $sku => $item) {
            if ($item['code'] !== '') {
                $byCode[$item['code']][] = $item['name'];
            }
        }

        $resolved = [];
        $skipped = [];
        foreach (['139', '752', '66', '140', '316', '64', '55', '686', '489', '688', '697'] as $code) {
            $names = array_values(array_unique($byCode[$code] ?? []));
            if (count($names) === 1) {
                $resolved[$code] = $names[0];
            } else {
                $skipped[] = $code; // reused/ambiguous codes are never guessed
            }
        }

        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => \App\Models\Tenant\Branch::query()->value('id'),
            'customer_name' => 'Legacy Order 8704 (reference — UAT)',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(12)->toDateString(),
            'pax' => 100,
            'notes' => self::LEGACY_8704_MARK.' — item codes resolved from the reconciled catalogue. Quantities and rates were not legible in the prep data and are LEFT AT ZERO deliberately: fill them from the legacy screenshot during the walkthrough.'
                .($skipped ? ' Unresolved/ambiguous codes skipped: '.implode(', ', $skipped).'.' : ''),
        ]);

        $lines = [];
        foreach ($resolved as $code => $name) {
            $product = Product::where('name', $name)->orderBy('id')->first();
            $lines[] = [
                'product_id' => null, // reference skeleton: free-text keeps 0-rate lines inert
                'item_name' => '['.$code.'] '.$name,
                'quantity' => 1,
                'unit_id' => $product?->unit_id,
                'unit_code' => $product?->unit?->code,
                'rate' => 0,
            ];
        }

        $estimates->saveDraftLines($event->currentEstimate, $lines);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Old generic UAT dishes stop crowding NEW quotations.
    // ─────────────────────────────────────────────────────────────────────────

    public function retireGenericUatProfiles(): void
    {
        CateringProductProfile::query()
            ->whereHas('product', fn ($q) => $q->where('name', 'like', '%(UAT)%'))
            ->update(['catering_enabled' => false]);
        // Historical events keep their own snapshots; nothing is renamed or
        // deleted, and old bookings stay fully readable.
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers.
    // ─────────────────────────────────────────────────────────────────────────

    private function unit(string $code): Unit
    {
        return Unit::firstOrCreate(
            ['code' => $code],
            ['name' => $code === 'KG' ? 'Kilogram' : 'Piece', 'unit_type' => $code === 'KG' ? 'weight' : 'quantity', 'is_active' => true],
        );
    }

    private function material(string $name, string $sku): Product
    {
        $material = Product::firstOrCreate(
            ['sku' => $sku],
            [
                'name' => $name,
                'slug' => Str::slug($name.'-'.$sku),
                'product_kind' => 'raw_material',
                'is_stock_tracked' => true,
                'unit_id' => $this->unit('KG')->id,
                'default_selling_price' => 0,
            ],
        );

        // Costing readiness needs an effective cost rate; on Kashif these all
        // exist from the UAT seed — this is a fresh-database fallback only.
        if (! CateringMaterialRate::where('product_id', $material->id)->exists()) {
            CateringMaterialRate::create([
                'product_id' => $material->id,
                'rate' => 100,
                'unit_id' => $this->unit('KG')->id,
                'effective_from' => now()->subMonth()->toDateString(),
                // Cost figure is a UAT assumption on a fresh database.
            ]);
        }

        return $material;
    }
}
