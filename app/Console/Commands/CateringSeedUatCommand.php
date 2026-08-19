<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialIssue;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Services\Catering\CateringEstimateService;
use App\Services\Inventory\InventoryService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * KASHIF-CATERING-UAT-SEED-1 — a Cost-Block dataset someone can learn from.
 *
 * Building twelve bookings and five costing scenarios by hand through the UI is
 * an hour of clicking that nobody can review, repeat, or check afterwards. This
 * builds the same thing in one auditable place, so a tenant can be returned to a
 * known UAT state whenever it is wanted.
 *
 * WHAT IT IS FOR: teaching and testing the Cost Block workflow. Every figure here
 * is a TEST FIXTURE. None of it is a client-approved commercial rate, and the
 * UAT- prefixed SKUs exist so that is obvious at a glance and simple to audit.
 *
 * WHAT IT REFUSES: to run anywhere it was not explicitly sent. The tenant code
 * must be given and typed twice, Khatri is refused by name because it is the only
 * live trading tenant, and a Branch Server is refused outright. It also refuses a
 * tenant that still holds catering documents rather than stacking a second
 * dataset on the first — running it twice by accident must not double the
 * bookings, and nobody would be able to say afterwards which twelve were which.
 *
 * Data is created through the ordinary authorities: bookings and estimates via
 * CateringEstimateService, opening stock via InventoryService. No journal row and
 * no stock ledger row is written directly.
 */
class CateringSeedUatCommand extends Command
{
    protected $signature = 'catering:seed-uat {tenant_code} {--yes} {--confirm=}';

    protected $description = 'Seed a guarded Cost-Block-first Catering UAT dataset into ONE named tenant. Test fixtures only.';

    /**
     * Fail closed. "Anything except Khatri" was the wrong boundary: it would have
     * let this loose on any catering tenant that ever gets added, including a
     * client's, on a mistyped argument. Only a tenant that is deliberately listed
     * here can receive test fixtures.
     */
    private const ALLOWED_TENANTS = ['kashifkitchen'];

    /** Named separately so the live trading tenant is refused by name, first. */
    private const FORBIDDEN_TENANTS = ['khatribiryani'];

    private const DEMO_CUSTOMER = 'UAT Owner Demo';

    /**
     * UAT material fixtures: key, name, SKU, Material Rate Book rate per KG.
     *
     * These are what the materials COST — a separate question from what a dish
     * charges for them. They are set a little under the commercial rates below
     * so the demo dishes make sense as a business; all of it is illustrative.
     */
    private const MATERIALS = [
        ['chicken', 'Chicken', 'UAT-RM-CHICKEN', 80],
        ['beef', 'Beef', 'UAT-RM-BEEF', 120],
        ['mutton', 'Mutton', 'UAT-RM-MUTTON', 200],
        ['rice', 'Basmati Rice', 'UAT-RM-RICE', 55],
        ['oil', 'Cooking Oil', 'UAT-RM-OIL', 90],
        ['yogurt', 'Yogurt', 'UAT-RM-YOGURT', 60],
        ['cream', 'Cream', 'UAT-RM-CREAM', 110],
        ['masala', 'Mixed Masala', 'UAT-RM-MASALA', 150],
        ['vegetables', 'Vegetables', 'UAT-RM-VEG', 40],
        ['flour', 'Flour / Maida', 'UAT-RM-FLOUR', 35],
        ['sugar', 'Sugar', 'UAT-RM-SUGAR', 45],
        ['paneer', 'Paneer', 'UAT-RM-PANEER', 180],
    ];

    /**
     * The house COMMERCIAL rate for each material the demo dishes charge for —
     * matching the rates those dishes were authored at, so applied and
     * recommended start in agreement.
     */
    private const COMMERCIAL_RATES = [
        'chicken' => 100,
        'beef' => 160,
        'rice' => 80,
        'yogurt' => 200,
        'oil' => 130,
    ];

    /** What a storeman will hand over during UAT, and enough of it to keep testing. */
    private const OPENING_STOCK = [
        'chicken' => 400, 'beef' => 300, 'mutton' => 150, 'rice' => 500,
        'oil' => 200, 'yogurt' => 150, 'masala' => 100, 'vegetables' => 200,
    ];

    /** @var array<string, int> */
    private array $materialIds = [];

    /** @var array<string, array{id: int, name: string, rate: float}> */
    private array $dishes = [];

    private int $kgUnitId;

    private int $categoryId;

    private Branch $branch;

    public function handle(TenancyManager $tenancy, CateringEstimateService $estimates, InventoryService $inventory): int
    {
        $code = (string) $this->argument('tenant_code');

        if (\App\Support\EdgeRuntime::isBranchServer()) {
            $this->error('Refusing: UAT seeding never runs on a Branch Server.');

            return self::FAILURE;
        }

        if (in_array($code, self::FORBIDDEN_TENANTS, true)) {
            $this->error("Refusing: '{$code}' is a live trading tenant. Test fixtures never go near real business data.");

            return self::FAILURE;
        }

        if (! in_array($code, self::ALLOWED_TENANTS, true)) {
            $this->error(
                "Refusing: '{$code}' is not a listed UAT tenant. This command only seeds tenants named in "
                .'ALLOWED_TENANTS ('.implode(', ', self::ALLOWED_TENANTS).'). '
                .'Add it there deliberately if it is genuinely a test tenant.'
            );

            return self::FAILURE;
        }

        if (! $this->option('yes') || $this->option('confirm') !== $code) {
            $this->error("Refusing: pass --yes and --confirm={$code} (typed confirmation) to seed '{$code}'.");

            return self::FAILURE;
        }

        $tenant = Tenant::where('tenant_code', $code)->first();
        if (! $tenant) {
            $this->error("Tenant '{$code}' not found.");

            return self::FAILURE;
        }

        $tenancy->activate($tenant);

        // Entitlement, never permission: every Owner holds every tenant.*
        // permission, so only the plan can answer whether Catering is on here.
        $plan = $tenant->subscription?->loadMissing('plan.enabledModules')->plan;
        if (! $plan?->hasEnabledModuleKey('catering')) {
            $this->error("Refusing: '{$code}' does not have Catering enabled — there is nothing here for this dataset to be used with.");

            return self::FAILURE;
        }

        if ($existing = $this->existingDocumentCount()) {
            $this->error(
                "Refusing: '{$code}' still holds {$existing} catering document(s). "
                .'Reset the tenant first with tenant:reset-transactions, then seed. '
                .'Stacking a second UAT dataset on the first would double every booking.'
            );

            return self::FAILURE;
        }

        $branch = Branch::where('status', 'active')->orderBy('id')->first();
        if (! $branch) {
            $this->error("Refusing: '{$code}' has no active branch to book events or hold stock against.");

            return self::FAILURE;
        }
        $this->branch = $branch;

        $this->info("Seeding Cost-Block UAT fixtures into '{$code}' (branch: {$branch->name})…");

        $this->ensureFoundations();
        $this->ensureMaterials();
        $this->ensureMaterialRates();
        $this->ensureCommercialRates();
        $this->buildCostBlockDishes();
        $this->openingStock($inventory);
        $summary = $this->buildBookings($estimates);

        $this->newLine();
        $this->info('UAT dataset ready. Every figure in it is a test fixture, not a client rate.');
        $this->table(['what', 'count'], [
            ['materials', count(self::MATERIALS)],
            ['material rates', CateringMaterialRate::count()],
            ['cost-block dishes', count($this->dishes)],
            ['same-day bookings', $summary['same_day']],
            ['nearby-date bookings', $summary['nearby']],
            ['owner demo estimate', $summary['demo_event_no']],
            ['advances / refunds / invoices', '0 / 0 / 0'],
        ]);

        return self::SUCCESS;
    }

    /**
     * The tenant's own business date, offset by whole days.
     *
     * Through TenantClock, the one authority for what "today" means to this
     * business — the server's clock is in UTC and rolls over hours before the
     * kitchen does.
     */
    private function businessDate(int $offsetDays = 0): string
    {
        $today = app(\App\Support\TenantClock::class)->currentBusinessDate($this->branch);

        return $offsetDays === 0
            ? $today
            : \Illuminate\Support\Carbon::parse($today)->addDays($offsetDays)->toDateString();
    }

    /** Catering documents a reseed must not be stacked on top of. */
    private function existingDocumentCount(): int
    {
        return (int) CateringEvent::count()
            + (int) CateringEstimate::count()
            + (int) CateringMaterialIssue::count();
    }

    private function ensureFoundations(): void
    {
        $this->kgUnitId = Unit::firstOrCreate(
            ['code' => 'KG'],
            ['name' => 'Kilogram', 'unit_type' => 'weight', 'is_active' => true]
        )->id;

        $this->categoryId = Category::firstOrCreate(
            ['name' => 'Catering (UAT)'],
            ['slug' => 'catering-uat', 'status' => 'active']
        )->id;
    }

    /** Reuse a material already carrying the same UAT SKU; never duplicate one. */
    private function ensureMaterials(): void
    {
        foreach (self::MATERIALS as [$key, $name, $sku, $rate]) {
            $this->materialIds[$key] = $this->product($sku, $name, [
                'product_kind' => Product::KIND_RAW_MATERIAL,
                'item_kind' => 'ingredient',
                'inventory_consumption_method' => 'stock_item',
                'is_stock_tracked' => true,
                'is_purchasable' => true,
                'is_sellable' => false,
                'is_pos_visible' => false,
                'can_be_bom_component' => true,
                'default_purchase_price' => $rate,
            ]);
        }
    }

    /**
     * The Material Rate Book is the ONE place a material's real cost lives. Cost
     * blocks read it; they never carry a second, writable copy of it.
     */
    /**
     * What the UAT materials are CHARGED at — the house price list, separate
     * from what they cost. Set at the rates the demo dishes were authored with,
     * so the dataset starts with applied and recommended in agreement and an
     * operator can watch them diverge when they raise one.
     */
    private function ensureCommercialRates(): void
    {
        foreach (self::COMMERCIAL_RATES as $key => $rate) {
            \App\Models\Tenant\CateringMaterialCommercialRate::updateOrCreate(
                [
                    'product_id' => $this->materialIds[$key],
                    'effective_from' => now()->subDay()->toDateString(),
                ],
                ['rate' => $rate, 'unit_id' => $this->kgUnitId, 'note' => 'UAT fixture']
            );
        }
    }

    private function ensureMaterialRates(): void
    {
        foreach (self::MATERIALS as [$key, $name, $sku, $rate]) {
            CateringMaterialRate::updateOrCreate(
                [
                    'product_id' => $this->materialIds[$key],
                    // Deliberately the server clock here, not the business date:
                    // this only has to be safely in the past so the rate is
                    // already effective, whichever way the timezones fall.
                    'effective_from' => now()->subDay()->toDateString(),
                ],
                ['rate' => $rate, 'unit_id' => $this->kgUnitId]
            );
        }
    }

    /**
     * Five deliberately DIFFERENT shapes.
     *
     * One repeated example teaches nothing: an operator who has only seen
     * "material 200 + making 500" has not learned that a charge can be a lump
     * sum, that a dish can draw three materials, or — the one that matters most —
     * that what a material is CHARGED at and what it COSTS are unrelated numbers.
     */
    private function buildCostBlockDishes(): void
    {
        // Every material rate below is per KG OF THE MATERIAL — 100 means chicken
        // is charged at 100 a kilo, and a 5 KG order needing 2.5 KG of it is
        // charged 250. That is the arithmetic an operator can check in their head,
        // which is the whole reason the dataset exists.

        // A. The simplest shape. Chicken 100/KG with 0.5 KG per kilo of dish adds
        //    50; making adds 250; the dish sells at 300.
        $this->dish('UAT-DISH-KARAHI', 'Chicken Karahi (UAT)', [
            ['label' => 'Chicken', 'material' => 'chicken', 'rate' => 100, 'qty' => 0.50],
            ['label' => 'Making', 'rate' => 250],
        ]);

        // B. The worked example, exactly as the business states it:
        //      chicken 100/KG x 0.50 =  50
        //      rice     80/KG x 0.40 =  32
        //      making                = 300
        //                              ---
        //      rate                    382 / KG
        //    A 5 KG order: 250 chicken + 160 rice + 1,500 making = 1,910.
        $this->dish('UAT-DISH-BIRYANI', 'Chicken Biryani (UAT)', [
            ['label' => 'Chicken', 'material' => 'chicken', 'rate' => 100, 'qty' => 0.50],
            ['label' => 'Rice', 'material' => 'rice', 'rate' => 80, 'qty' => 0.40],
            ['label' => 'Making', 'rate' => 300],
        ]);

        // C. Per-unit beside lump sum. A 10 KG order pays the setup once; a 100 KG
        //    order pays exactly the same setup.
        $this->dish('UAT-DISH-COUNTER', 'Live Counter BBQ (UAT)', [
            ['label' => 'Chicken', 'material' => 'chicken', 'rate' => 120, 'qty' => 0.45],
            ['label' => 'Making', 'rate' => 200],
            ['label' => 'Live counter setup', 'rate' => 3000, 'basis' => CateringProductCostBlock::BASIS_LUMP_SUM],
        ]);

        // D. The teaching fixture: three numbers, none equal to another. A 10 KG
        //    order charges 4 KG x 140 = 560 for chicken, draws 4 KG from the
        //    store, and that chicken costs 4 x 80 = 320 from the rate book.
        $this->dish('UAT-DISH-HANDI', 'Chicken Handi (UAT)', [
            ['label' => 'Chicken', 'material' => 'chicken', 'rate' => 140, 'qty' => 0.40],
            ['label' => 'Making', 'rate' => 200],
        ]);

        // E. Mostly service. Proves a charge block is money for work and moves no
        //    stock at all, however large a share of the price it is.
        $this->dish('UAT-DISH-PLATTER', 'Wedding Service Platter (UAT)', [
            ['label' => 'Yogurt & garnish', 'material' => 'yogurt', 'rate' => 200, 'qty' => 0.10],
            ['label' => 'Making & labour', 'rate' => 380],
            ['label' => 'Packing', 'rate' => 130],
        ]);
    }

    /**
     * @param  array<int, array{label: string, rate: float, material?: string, qty?: float, basis?: string}>  $blocks
     */
    private function dish(string $sku, string $name, array $blocks): void
    {
        $productId = $this->product($sku, $name, [
            'product_kind' => Product::KIND_SALE_ITEM,
            'item_kind' => 'finished_good',
            'inventory_consumption_method' => 'none',
            'is_stock_tracked' => false,
            'is_sellable' => true,
            'is_purchasable' => false,
            'is_pos_visible' => false,
        ]);

        CateringProductProfile::updateOrCreate(
            ['product_id' => $productId],
            [
                'catering_enabled' => true,
                'pricing_mode' => CateringProductProfile::PRICING_FIXED,
                // Cost Blocks are the active authority for every UAT dish. Recipe
                // support is untouched in the code; it is simply not what this
                // dataset is for.
                'costing_mode' => CateringProductProfile::COSTING_BLOCKS,
                'default_quote_unit_id' => $this->kgUnitId,
            ]
        );

        CateringProductCostBlock::where('product_id', $productId)->delete();

        $rate = 0.0;
        $lumpSum = 0.0;
        foreach (array_values($blocks) as $index => $block) {
            $isMaterial = isset($block['material']);
            $basis = $block['basis'] ?? CateringProductCostBlock::BASIS_PER_UNIT;

            CateringProductCostBlock::create([
                'product_id' => $productId,
                'label' => $block['label'],
                'block_type' => $isMaterial
                    ? CateringProductCostBlock::TYPE_MATERIAL
                    : CateringProductCostBlock::TYPE_CHARGE,
                'material_product_id' => $isMaterial ? $this->materialIds[$block['material']] : null,
                'quantity_per_unit' => $isMaterial ? $block['qty'] : null,
                'unit_id' => $isMaterial ? $this->kgUnitId : null,
                'rate' => $block['rate'],
                'charge_basis' => $basis,
                // Authored the way the business thinks: rupees per kilo of the
                // MATERIAL. The owner can then check the arithmetic in their
                // head — 2.5 KG of chicken at 100 is 250.
                'rate_basis' => $isMaterial
                    ? CateringProductCostBlock::RATE_PER_MATERIAL_UNIT
                    : CateringProductCostBlock::RATE_PER_DISH_UNIT,
                'sort_order' => $index + 1,
                'is_active' => true,
            ]);

            // A lump sum never enters the per-unit rate; it would be wrong at
            // every order size except the one it was divided by. It is carried
            // separately so the estimate can charge it once.
            if ($basis === CateringProductCostBlock::BASIS_PER_UNIT) {
                // A per-material rate contributes ratio x rate to the DISH rate:
                // chicken at 100/KG with 0.5 KG per kilo adds 50, not 100.
                $rate += $isMaterial
                    ? (float) $block['qty'] * (float) $block['rate']
                    : (float) $block['rate'];
            } else {
                $lumpSum += (float) $block['rate'];
            }
        }

        $this->dishes[$sku] = [
            'id' => $productId,
            'name' => $name,
            'rate' => round($rate, 2),
            'lump_sum' => round($lumpSum, 2),
        ];
    }

    private function product(string $sku, string $name, array $attrs): int
    {
        return Product::updateOrCreate(['sku' => $sku], array_merge([
            'category_id' => $this->categoryId,
            'unit_id' => $this->kgUnitId,
            'name' => $name,
            'slug' => Str::slug($sku),
            'product_type' => 'simple',
            'is_perishable' => false,
            'default_wastage_percent' => 0,
            'has_variants' => false,
            'has_expiry' => false,
            'requires_batch' => false,
            'default_purchase_price' => 0,
            'default_selling_price' => 0,
            'is_taxable' => false,
            'status' => 'active',
        ], $attrs))->id;
    }

    /**
     * Enough stock that the owner can issue material several times before hitting
     * an empty shelf. Through InventoryService, so the cost layers and valuation
     * are the real ones — never a direct write to a balance table.
     */
    private function openingStock(InventoryService $inventory): void
    {
        foreach (self::OPENING_STOCK as $key => $quantity) {
            $product = Product::findOrFail($this->materialIds[$key]);
            $rate = collect(self::MATERIALS)->firstWhere(0, $key)[3];

            $inventory->postIn(
                branch: $this->branch,
                product: $product,
                variant: null,
                quantity: (float) $quantity,
                unitCost: (float) $rate,
                movementType: 'opening_stock',
                referenceType: 'catering_uat_seed',
                referenceId: $product->id,
                referenceNo: 'UAT-OPENING',
                notes: 'UAT opening stock for Catering testing',
            );
        }
    }

    /**
     * Twelve bookings on one day, because that is the shape the store screen was
     * built for: one trip to the counter covering a night's work. A few on
     * neighbouring days so the date filter visibly does something.
     *
     * @return array{same_day: int, nearby: int, demo_event_no: string}
     */
    private function buildBookings(CateringEstimateService $estimates): array
    {
        // The tenant's business date, not the server's. A box running UTC rolls
        // over five hours before a kitchen in Karachi does, and the whole point
        // of these twelve bookings is that they appear under the Store Issue
        // modal's DEFAULT date when the owner opens it.
        $today = $this->businessDate();
        $skus = array_keys($this->dishes);

        $customers = [
            ['Ahmed Sheikh', '0300-1122334', 'Glass Banquet Hall', '19:00', 500],
            ['Bilal Khan', '0321-4455667', 'Marquee Gardens', '20:00', 300],
            ['Chaudhry Imran', '0333-9988776', 'House 12, Gulberg', '13:00', 120],
            ['Danish Ali', '0345-1231234', 'Royal Palm Lawn', '19:30', 700],
            ['Ehsan Malik', '0301-5556667', 'Pearl Continental', '21:00', 250],
            ['Farhan Qureshi', '0322-7778889', 'Shalimar Hall', '18:30', 400],
            ['Ghulam Abbas', '0336-2223334', 'Street 7, Model Town', '14:00', 90],
            ['Hamza Tariq', '0308-6667778', 'Grand Marquee', '20:30', 600],
            ['Imran Yousaf', '0313-4445556', 'Al Noor Lawn', '19:00', 350],
            ['Junaid Aslam', '0344-8889990', 'Defence Club', '21:30', 200],
            ['Kashif Nawaz', '0302-1114445', 'Jasmine Hall', '18:00', 450],
            ['Luqman Sattar', '0335-3336667', 'Plot 44, Johar Town', '12:30', 150],
        ];

        $sameDay = 0;
        foreach ($customers as $index => [$name, $phone, $venue, $time, $pax]) {
            // Two or three dishes each, rotating through the five shapes, so no
            // single booking carries everything and each scenario gets used.
            $lineSkus = [
                $skus[$index % count($skus)],
                $skus[($index + 2) % count($skus)],
            ];
            if ($index % 3 === 0) {
                $lineSkus[] = $skus[($index + 4) % count($skus)];
            }

            $this->booking($estimates, $name, $phone, $venue, $time, $pax, $today, $lineSkus);
            $sameDay++;
        }

        $nearby = 0;
        foreach ([
            ['Nadeem Butt', '0300-7778881', 'Cantt Officers Mess', '20:00', 220, $this->businessDate(-1)],
            ['Owais Rehman', '0321-9990001', 'Garden Town Hall', '19:00', 380, $this->businessDate(1)],
            ['Pervaiz Iqbal', '0333-2224446', 'Askari Community Centre', '13:30', 140, $this->businessDate(2)],
        ] as [$name, $phone, $venue, $time, $pax, $date]) {
            $this->booking($estimates, $name, $phone, $venue, $time, $pax, $date, [$skus[0], $skus[3]]);
            $nearby++;
        }

        // One booking that exists to be read rather than used: four different
        // shapes side by side, left as a draft so nothing about it is frozen.
        $demo = $this->booking(
            $estimates,
            self::DEMO_CUSTOMER,
            '0300-0000000',
            'Owner training — not a real booking',
            '19:00',
            300,
            $today,
            [$skus[0], $skus[1], $skus[2], $skus[3]],
        );

        return ['same_day' => $sameDay + 1, 'nearby' => $nearby, 'demo_event_no' => $demo->event_no];
    }

    /** @param array<int, string> $dishSkus */
    private function booking(
        CateringEstimateService $estimates,
        string $customer,
        string $phone,
        string $venue,
        string $time,
        int $pax,
        string $date,
        array $dishSkus,
    ): CateringEvent {
        $event = $estimates->createEvent([
            'branch_id' => $this->branch->id,
            'customer_name' => $customer,
            'customer_phone' => $phone,
            'venue' => $venue,
            'service_time' => $time,
            'booking_date' => $this->businessDate(),
            'event_date' => $date,
            'pax' => $pax,
            'notes' => 'UAT fixture — created by catering:seed-uat.',
        ]);

        $lines = [];

        foreach (array_values(array_unique($dishSkus)) as $position => $sku) {
            $dish = $this->dishes[$sku];
            $lines[] = [
                'product_id' => $dish['id'],
                'item_name' => $dish['name'],
                // Left at zero on purpose: the line snapshot prices itself from
                // the dish's blocks, including any lump sum, which belongs to
                // the line rather than to the document. A quotation can carry a
                // live counter on one line and packing on another, and the
                // document's single other-charge field cannot say which is which.
                'rate' => 0,
                'quantity' => [10, 20, 15, 25][$position % 4],
                'unit_id' => $this->kgUnitId,
                'unit_code' => 'KG',
            ];
        }

        $estimates->saveDraftLines($event->currentEstimate, $lines);

        return $event->refresh();
    }
}
