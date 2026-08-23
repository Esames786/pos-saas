<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\Category;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductBarcode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * KASHIF-LEGACY-IMPORT-2 — the client's OWN database, mapped in whole.
 *
 * Source: public/old_software.xlsx → docs/data/legacy-*.csv (extraction is
 * deterministic and reviewable; re-run scripts/extract on a NEWER xlsx and
 * this seeder re-imports — that IS the go-live pipeline).
 *
 * What each step does, and its safety rule:
 *
 *   products    enrich the existing KM-* catalogue from tbl_OrderItem: legacy
 *               id becomes a BARCODE (so "361" finds the item), category is
 *               the DIRECT legacy link, CatAllow → allow_party_supply,
 *               kitchen → production_station, and the item's OWN commercial
 *               truth (OrderRate = MeatRate + ServiceRate, proven equal on
 *               all 909 rows) becomes its Cost Blocks. Machine-labelled
 *               blocks ("owner to confirm") are re-rated; a person's blocks
 *               are never touched.
 *   customers   4,8xx real customers derived from 24k orders (latest name/
 *               address per phone). Idempotent by phone.
 *   orders      the legacy order book as catering events + plain lines
 *               (NO cost-block snapshots — history is reference, not a
 *               costing exercise). NO money rows are ever created: finance
 *               history stays in the old system's books.
 *
 * No GL. No stock. Ever. The command fingerprints both before and after.
 */
class KashifLegacyImportSeeder extends Seeder
{
    public const MARK = 'LEGACY-IMPORT';

    private const LABEL = 'legacy 2026 rate — owner to confirm';

    /** @var array<int, array<string,string>>|null */
    private ?array $itemsCache = null;

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

    private function legacyItems(): array
    {
        return $this->itemsCache ??= $this->csv('legacy-order-items');
    }

    /**
     * legacy order_item_id → Product. The KM catalogue was keyed on the same
     * source codes, so the map is (code) → unique row, falling back to
     * (code + normalized name) when the old software reused a code.
     */
    public function productMap(): array
    {
        $byCode = [];
        foreach (KashifClientMenuSeeder::catalogueRows() as $sku => $item) {
            $byCode[$item['code']][] = ['sku' => $sku, 'name' => strtoupper(preg_replace('/\s+/', ' ', $item['name']))];
        }

        $products = Product::where('sku', 'like', KashifClientMenuSeeder::SKU_PREFIX.'%')
            ->pluck('id', 'sku');

        $map = [];
        $misses = [];
        foreach ($this->legacyItems() as $li) {
            $code = (string) (int) $li['order_item_id'];
            $cands = $byCode[$code] ?? [];
            $hit = null;
            if (count($cands) === 1) {
                $hit = $cands[0];
            } elseif (count($cands) > 1) {
                $want = strtoupper(preg_replace('/\s+/', ' ', $li['name']));
                foreach ($cands as $c) {
                    if ($c['name'] === $want) { $hit = $c; break; }
                }
                $hit ??= $cands[0];
            }
            if ($hit && isset($products[$hit['sku']])) {
                $map[(int) $li['order_item_id']] = (int) $products[$hit['sku']];
            } else {
                $misses[] = $li['order_item_id'].' '.$li['name'];
            }
        }

        return [$map, $misses];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Products: the client's own commercial truth
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{matched:int, missed:int, rerated:int, charged:int, owner_kept:int, party_off:int} */
    public function importProducts(): array
    {
        [$map, $misses] = $this->productMap();
        $kitchens = collect($this->csv('legacy-kitchens'))->pluck('name', 'kitchen_id');
        $kgUnit = DB::connection('tenant')->table('units')->where('code', 'KG')->value('id');
        $pcsUnit = DB::connection('tenant')->table('units')->where('code', 'PCS')->value('id');

        $stats = ['matched' => count($map), 'missed' => count($misses), 'rerated' => 0, 'charged' => 0, 'owner_kept' => 0, 'party_off' => 0];

        foreach ($this->legacyItems() as $li) {
            $legacyId = (int) $li['order_item_id'];
            $productId = $map[$legacyId] ?? null;
            if ($productId === null) {
                continue;
            }

            // 1 · the legacy id becomes a barcode, so typing "361" finds it.
            //     A code the old software reused stays with its FIRST item —
            //     barcodes are unique, and a silent re-point would be worse.
            if (! ProductBarcode::where('barcode', (string) $legacyId)->exists()) {
                ProductBarcode::create([
                    'product_id' => $productId,
                    'barcode' => (string) $legacyId,
                    'barcode_type' => 'manual', // enum(manual|system|supplier); the code itself marks provenance
                    'is_primary' => false,
                ]);
            }

            // 2 · the DIRECT category link (no more sequence inference) — but a
            //     product the owner has re-filed by hand is never re-filed back.
            $legacyCategory = Category::where('sort_order', 500 + (int) $li['category_id'])
                ->where('slug', 'like', 'client-menu-%')->first();
            $product = Product::find($productId);
            if ($legacyCategory) {
                $ownCat = Category::find($product->category_id);
                if ($ownCat === null || str_starts_with((string) $ownCat->slug, 'client-menu-')) {
                    $product->category_id = $legacyCategory->id;
                }
            }
            if ($product->unit_id === null) {
                $product->unit_id = strtoupper($li['unit']) === 'PCS' ? $pcsUnit : $kgUnit;
            }
            $product->save();

            // 3 · the per-item switches + kitchen, on the profile.
            $profile = CateringProductProfile::firstOrNew(['product_id' => $productId]);
            $profile->fill([
                'allow_party_supply' => $li['cat_allow'] === 'Y',
                'costing_mode' => 'blocks',
                'pricing_mode' => $profile->pricing_mode ?? 'fixed',
            ]);
            if ($li['cat_allow'] !== 'Y') {
                $stats['party_off']++;
            }
            if (trim((string) ($kitchens[(int) $li['kitchen_id']] ?? '')) !== '' && $li['kitchen_allow'] === 'Y'
                && trim((string) $profile->production_station) === '') {
                $profile->production_station = $kitchens[(int) $li['kitchen_id']];
            }
            if ($profile->default_quote_unit_id === null) {
                $profile->default_quote_unit_id = $product->unit_id;
            }

            // 4 · the item's own rates become its blocks. Three cases:
            $orderRate = (float) $li['order_rate'];
            $meatRate = (float) $li['meat_rate'];
            $serviceRate = (float) $li['service_rate'];

            $blocks = CateringProductCostBlock::where('product_id', $productId)
                ->where('is_active', true)->get();
            $ownerAuthored = $blocks->isNotEmpty()
                && ! str_contains($blocks->pluck('label')->implode(';'), 'owner to confirm');

            if ($ownerAuthored) {
                $stats['owner_kept']++;                 // a person's work; untouched
            } elseif ($orderRate > 0) {
                if ($blocks->isNotEmpty()) {
                    // Machine-made estimate blocks: keep the materials (they
                    // drive kitchen sheets and party splits), point Making at
                    // the client's own figure so calculated == OrderRate.
                    $making = $blocks->first(fn ($b) => $b->charge_role === CateringProductCostBlock::ROLE_MAKING);
                    $others = round($blocks
                        ->reject(fn ($b) => $making && $b->id === $making->id)
                        ->reject->isLumpSum()
                        ->sum->contributionPerDishUnit(), 2);
                    $balance = max(0, round($orderRate - $others, 2));
                    if ($making) {
                        $making->forceFill(['rate' => $balance, 'label' => 'Making — '.self::LABEL])->save();
                    } else {
                        CateringProductCostBlock::create([
                            'product_id' => $productId, 'label' => 'Making — '.self::LABEL,
                            'block_type' => CateringProductCostBlock::TYPE_CHARGE,
                            'charge_role' => CateringProductCostBlock::ROLE_MAKING,
                            'rate' => $balance, 'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                            'sort_order' => 90,
                        ]);
                    }
                    $stats['rerated']++;
                } else {
                    // No blocks at all (the old needs-setup tail, fish included):
                    // the client's own two figures ARE the honest blocks.
                    if ($meatRate > 0) {
                        CateringProductCostBlock::create([
                            'product_id' => $productId, 'label' => 'Meat Rate — '.self::LABEL,
                            'block_type' => CateringProductCostBlock::TYPE_CHARGE,
                            'rate' => $meatRate, 'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                            'sort_order' => 1,
                        ]);
                    }
                    CateringProductCostBlock::create([
                        'product_id' => $productId, 'label' => 'Making — '.self::LABEL,
                        'block_type' => CateringProductCostBlock::TYPE_CHARGE,
                        'charge_role' => CateringProductCostBlock::ROLE_MAKING,
                        'rate' => max(0, round($orderRate - $meatRate, 2)),
                        'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                        'sort_order' => 90,
                    ]);
                    $stats['charged']++;
                }
                $profile->catering_enabled = true;
            }

            $profile->save();
        }

        return $stats;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Customers
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{imported:int, existing:int} */
    public function importCustomers(): array
    {
        $stats = ['imported' => 0, 'existing' => 0];

        foreach (array_chunk($this->csv('legacy-customers'), 500) as $chunk) {
            $phones = array_column($chunk, 'phone');
            $known = Customer::whereIn('phone', $phones)->pluck('phone')->flip();

            $insert = [];
            foreach ($chunk as $c) {
                if (isset($known[$c['phone']])) {
                    $stats['existing']++;

                    continue;
                }
                $insert[] = [
                    'code' => 'LEG-'.$c['phone'],
                    'name' => mb_substr($c['name'], 0, 190),
                    'phone' => $c['phone'],
                    'address' => $c['address'] !== '' ? $c['address'] : null,
                    'status' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ];
                $stats['imported']++;
            }
            if ($insert !== []) {
                Customer::insert($insert);
            }
        }

        return $stats;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Orders — the legacy book as events + plain lines
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  string  $scope  'all' | 'future' (delivery today or later) | 'none'
     * @return array{events:int, lines:int, skipped_existing:int}
     */
    public function importOrders(string $scope = 'future'): array
    {
        $stats = ['events' => 0, 'lines' => 0, 'skipped_existing' => 0];
        if ($scope === 'none') {
            return $stats;
        }

        [$map] = $this->productMap();
        $itemNames = collect($this->legacyItems())->keyBy(fn ($li) => (int) $li['order_item_id']);
        $units = collect($this->legacyItems())->mapWithKeys(fn ($li) => [(int) $li['order_item_id'] => strtoupper($li['unit']) === 'PCS' ? 'PCS' : 'KG']);
        $customers = Customer::whereNotNull('phone')->pluck('id', 'phone');
        $branchId = DB::connection('tenant')->table('branches')->value('id');
        $today = now('Asia/Karachi')->toDateString();

        $lines = [];
        foreach ($this->csv('legacy-order-lines') as $l) {
            $lines[(int) $l['order_id']][] = $l;
        }

        $existing = CateringEvent::where('event_no', 'like', 'LEG-%')->pluck('event_no')->flip();

        foreach (array_chunk($this->csv('legacy-orders'), 250) as $chunk) {
            foreach ($chunk as $o) {
                // Legacy order numbers cycle across years ("04-001" repeats);
                // the row id is the uniqueness, the order_no the recognition.
                $eventNo = 'LEG-'.$o['id'].'-'.$o['order_no'];
                $delivery = $o['delivery_date'] ?: $o['booking_date'];
                if ($scope === 'future' && $delivery < $today) {
                    continue;
                }
                if (isset($existing[$eventNo])) {
                    $stats['skipped_existing']++;

                    continue;
                }

                $past = $delivery < $today;
                $event = CateringEvent::query()->forceCreate([
                    'event_no' => $eventNo,
                    'branch_id' => $branchId,
                    'customer_id' => $customers[$o['phone']] ?? null,
                    'customer_name' => $o['party_desc'] !== '' ? mb_substr($o['party_desc'], 0, 190) : 'CASH',
                    'customer_phone' => $o['phone'] ?: null,
                    'customer_address' => $o['address'] ?: null,
                    'venue' => $o['delivery_place'] !== '' && strtoupper($o['delivery_place']) !== 'SELF' ? mb_substr($o['delivery_place'], 0, 190) : null,
                    'event_type' => 'Legacy Order',
                    'booking_date' => $o['booking_date'] ?: $delivery,
                    'event_date' => $delivery,
                    'service_time' => $o['delivery_time'] ?: null,
                    'pax' => 0,
                    'status' => $past ? CateringEvent::STATUS_COMPLETED : CateringEvent::STATUS_DRAFT,
                    'notes' => self::MARK.' '.$o['order_no'].' — imported from old software; money history stays in the old books.',
                ]);

                $subtotal = 0.0;
                $sort = 0;
                $eventLines = [];
                foreach ($lines[(int) $o['id']] ?? [] as $l) {
                    $legacyId = (int) $l['order_item_id'];
                    $li = $itemNames->get($legacyId);
                    $rate = (float) $l['rate'];
                    $net = (float) $l['net'];
                    $qty = $rate > 0 ? round($net / $rate, 3) : (float) $l['daigs'];
                    if ($qty <= 0) {
                        $qty = 1;
                    }
                    $meta = array_filter([
                        $l['cat'] === 'PARTY' ? 'PARTY' : null,
                        (float) $l['meat'] > 0 ? 'Meat '.rtrim(rtrim(number_format((float) $l['meat'], 2, '.', ''), '0'), '.') : null,
                        (float) $l['rice'] > 0 ? 'Rice '.rtrim(rtrim(number_format((float) $l['rice'], 2, '.', ''), '0'), '.') : null,
                        (float) $l['daigs'] > 0 ? 'Daig '.rtrim(rtrim(number_format((float) $l['daigs'], 2, '.', ''), '0'), '.') : null,
                        $l['instruction'] !== '' ? $l['instruction'] : null,
                    ]);

                    $subtotal = round($subtotal + $net, 2);
                    $eventLines[] = [
                        'product_id' => $map[$legacyId] ?? null,
                        'item_name' => $li ? $li['name'] : ('Legacy item #'.$legacyId),
                        'quantity' => $qty,
                        'unit_code' => $units[$legacyId] ?? 'KG',
                        'rate' => $rate,
                        'amount' => $net,
                        'instructions' => $meta !== [] ? implode(' · ', $meta) : null,
                        'sort_order' => $sort++,
                    ];
                    $stats['lines']++;
                }

                $charges = round((float) $o['service_charges'] + (float) $o['ot_charge'] + (float) $o['barbq_charges'], 2);
                $estimate = CateringEstimate::query()->forceCreate([
                    'catering_event_id' => $event->id,
                    'version_no' => 1,
                    'status' => $past ? CateringEstimate::STATUS_ACCEPTED : CateringEstimate::STATUS_DRAFT,
                    'subtotal' => $subtotal,
                    'service_charge_amount' => $charges,
                    'other_charge_label' => 'Fare',
                    'other_charge_amount' => (float) $o['fare'],
                    'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
                    'tax_amount' => 0,
                    'grand_total' => round($subtotal + $charges + (float) $o['fare'], 2),
                    'notes' => self::MARK.' '.$o['order_no'],
                ]);

                foreach ($eventLines as $el) {
                    CateringEstimateLine::query()->forceCreate($el + ['catering_estimate_id' => $estimate->id]);
                }

                $stats['events']++;
            }
        }

        return $stats;
    }
}
