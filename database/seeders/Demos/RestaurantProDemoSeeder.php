<?php

namespace Database\Seeders\Demos;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\Recipe;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use Illuminate\Support\Str;

/**
 * Restaurant Pro demo (restaurantprodemo, restaurant_pro plan — all modules).
 * Extends RestaurantDemoSeeder and layers on the advanced workflows:
 * kitchen ingredients + opening stock, recipes/BOM, suppliers + purchase flow,
 * a second branch + terminals, KDS active (held) orders. Recipe menu items
 * consume ingredients automatically when the inherited paid sample orders are
 * finalized (via SalesService → RecipeConsumptionService).
 */
class RestaurantProDemoSeeder extends RestaurantDemoSeeder
{
    private ?Branch $secondBranch = null;

    public function run(): array
    {
        $this->inv   = app(\App\Services\Inventory\InventoryService::class);
        $this->sales = app(\App\Services\Sales\SalesService::class);

        $this->seedUnits();
        $this->seedFloorsAndTables();
        $this->seedWaiters();
        $this->seedStaff();
        $this->seedSecondBranch();
        $this->seedTerminals();
        $this->seedCategories();
        $this->seedIngredients();      // before menu/recipes/orders
        $this->seedMenu();             // recipe items flagged via recipeMenuSkus()
        $this->seedModifiersAndCombos();
        $this->seedOpeningStock();     // ingredient stock before consumption
        $this->seedRecipes();
        $this->seedSuppliersAndPurchases();
        $this->seedSalesControls();
        $this->seedSampleOrders();     // recipe items consume ingredients
        $this->seedKdsOrders();        // held orders for kitchen display

        // DEPARTMENT-FOUNDATION-1: mapping/reporting only — no stock movement.
        $this->counts['departments'] = \Database\Seeders\Tenant\DemoDepartmentSeeder::seed()['departments'];

        return $this->counts;
    }

    protected function recipeMenuSkus(): array
    {
        return [
            'RST-BURGER-CLASSIC', 'RST-BURGER-CHEESE', 'RST-BURGER-ZINGER',
            'RST-PIZZA-FAJITA', 'RST-BIRYANI-CHK', 'RST-FRIES', 'RST-MARGARITA', 'RST-TEA',
        ];
    }

    protected function seedSecondBranch(): void
    {
        // restaurant_pro allows 3 branches; add one more alongside Main.
        $this->secondBranch = Branch::updateOrCreate(
            ['code' => 'DOWNTOWN'],
            [
                'name'    => 'Downtown Branch',
                'address' => 'Mall Road, City Centre',
                'phone'   => '042-37200000',
                'email'   => 'downtown@restaurantprodemo.com',
                'status'  => 'active',
            ]
        );
        $this->counts['branches'] = Branch::count();
    }

    protected function seedTerminals(): void
    {
        $main = $this->mainBranch();
        if (! $main) return;
        foreach ([
            ['POS-MAIN-01', 'Counter 1', true],
            ['POS-MAIN-02', 'Counter 2', false],
            ['KDS-MAIN-01', 'Kitchen Display', false],
        ] as [$code, $name, $shift]) {
            Terminal::updateOrCreate(
                ['code' => $code],
                ['branch_id' => $main->id, 'name' => $name, 'requires_shift' => $shift, 'status' => 'active']
            );
        }
        $this->counts['terminals'] = Terminal::count();
    }

    /** [sku, name, unit code, cost, opening qty, low?]. */
    protected function ingredients(): array
    {
        return [
            ['RST-ING-BEEFPATTY', 'Beef Patty',         'PCS', 90,  200, false],
            ['RST-ING-CHKFILLET', 'Chicken Fillet',     'KG',  550, 30,  false],
            ['RST-ING-BUN',       'Burger Bun',         'PCS', 25,  300, false],
            ['RST-ING-CHEESE',    'Cheese Slice',       'PCS', 18,  250, false],
            ['RST-ING-LETTUCE',   'Lettuce',            'KG',  120, 10,  false],
            ['RST-ING-TOMATO',    'Tomato',             'KG',  80,  15,  false],
            ['RST-ING-DOUGH',     'Pizza Dough',        'PCS', 60,  80,  false],
            ['RST-ING-MOZZ',      'Mozzarella Cheese',  'KG',  900, 12,  false],
            ['RST-ING-PIZZASAUCE','Pizza Sauce',        'KG',  300, 8,   false],
            ['RST-ING-TIKKA',     'Chicken Tikka Pieces','KG', 650, 10,  false],
            ['RST-ING-RICE',      'Basmati Rice',       'KG',  180, 60,  false],
            ['RST-ING-OIL',       'Cooking Oil',        'L',   380, 25,  false],
            ['RST-ING-FRIES',     'French Fries Frozen','KG',  220, 5,   true],
            ['RST-ING-MAYO',      'Mayo',               'KG',  280, 8,   false],
            ['RST-ING-GARLIC',    'Garlic Sauce',       'KG',  260, 6,   false],
            ['RST-ING-MINT',      'Mint',               'KG',  100, 3,   true],
            ['RST-ING-LEMON',     'Lemon',              'KG',  150, 8,   false],
            ['RST-ING-TEALEAF',   'Tea Leaves',         'KG',  900, 4,   false],
            ['RST-ING-MILK',      'Milk',               'L',   150, 20,  false],
            ['RST-ING-SUGAR',     'Sugar',              'KG',  120, 25,  false],
        ];
    }

    protected function seedIngredients(): void
    {
        $cat = Category::updateOrCreate(
            ['code' => 'RKITCHEN'],
            ['name' => 'Kitchen Ingredients', 'slug' => 'kitchen-ingredients', 'sort_order' => 20, 'is_active' => true]
        );
        $cat->translations()->updateOrCreate(['language_code' => 'en'], ['name' => 'Kitchen Ingredients']);

        $count = 0;
        foreach ($this->ingredients() as [$sku, $name, $unitCode, $cost, $qty, $low]) {
            $unit = Unit::where('code', $unitCode)->first();
            $product = Product::updateOrCreate(
                ['sku' => $sku],
                [
                    'category_id'                  => $cat->id,
                    'unit_id'                      => $unit?->id,
                    'name'                         => $name,
                    'slug'                         => Str::slug($name),
                    'product_type'                 => 'simple',
                    'item_kind'                    => 'ingredient',
                    'inventory_consumption_method' => 'stock_item',
                    'is_sellable'                  => false,
                    'is_purchasable'               => true,
                    'is_stock_tracked'             => true,
                    'has_expiry'                   => false,
                    'requires_batch'               => false,
                    'default_purchase_price'       => $cost,
                    'default_selling_price'        => 0,
                    'is_taxable'                   => false,
                    // KITCHEN-RECIPE-COST-1: pack fields. Recipe qtys here use the stock unit
                    // (KG/L/PCS) so purchase unit = stock unit, pack size = 1 (report-accurate).
                    'purchase_unit_id'             => $unit?->id,
                    'purchase_pack_size'           => 1,
                    'status'                       => 'active',
                ]
            );
            $product->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $name]);
            ProductVariant::updateOrCreate(
                ['sku' => $sku],
                [
                    'product_id'       => $product->id,
                    'name'             => $name,
                    'purchase_price'   => $cost,
                    'selling_price'    => 0,
                    'reorder_level'    => $low ? 10 : 5,
                    'reorder_quantity' => 20,
                    'is_default'       => true,
                    'is_active'        => true,
                ]
            );
            $count++;
        }
        $this->counts['ingredients'] = $count;
    }

    protected function seedOpeningStock(): void
    {
        $branch = $this->mainBranch();
        if (! $branch) return;
        if (StockAdjustment::where('branch_id', $branch->id)->where('adjustment_type', 'opening')->exists()) {
            $this->counts['opening_stock'] = 'already posted';
            return;
        }
        $adjustment = StockAdjustment::create([
            'adjustment_no'   => 'ADJ-OPEN-' . $branch->code . '-' . now()->format('YmdHis'),
            'branch_id'       => $branch->id,
            'adjustment_type' => 'opening',
            'adjustment_date' => now()->toDateString(),
            'status'          => 'posted',
            'posted_at'       => now(),
            'notes'           => 'Restaurant Pro demo opening ingredient stock',
        ]);
        $lines = 0;
        foreach ($this->ingredients() as [$sku, $name, $unitCode, $cost, $qty, $low]) {
            $product = Product::where('sku', $sku)->first();
            if (! $product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $line = $adjustment->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity'           => $qty,
                'unit_cost'          => $cost,
            ]);
            $ledger = $this->inv->postIn(
                branch: $branch, product: $product, variant: $variant, quantity: $qty, unitCost: $cost,
                movementType: 'opening_stock', referenceType: 'stock_adjustment',
                referenceId: $adjustment->id, referenceNo: $adjustment->adjustment_no,
                notes: 'Restaurant Pro opening stock',
            );
            $line->update(['inventory_batch_id' => $ledger->inventory_batch_id]);
            $lines++;
        }
        $this->counts['opening_stock'] = "{$lines} ingredient lines";
    }

    protected function seedRecipes(): void
    {
        $pcs = Unit::where('code', 'PCS')->first();
        $kg  = Unit::where('code', 'KG')->first();
        $ltr = Unit::where('code', 'L')->first();
        $u = fn ($code) => $code === 'PCS' ? $pcs?->id : ($code === 'KG' ? $kg?->id : $ltr?->id);

        // [menu sku, recipe name, [ [ing sku, qty, unit], ... ]]
        $recipes = [
            ['RST-BURGER-CLASSIC', 'Classic Beef Burger', [['RST-ING-BEEFPATTY', 1, 'PCS'], ['RST-ING-BUN', 1, 'PCS'], ['RST-ING-CHEESE', 1, 'PCS'], ['RST-ING-LETTUCE', 0.02, 'KG'], ['RST-ING-MAYO', 0.02, 'KG']]],
            ['RST-BURGER-CHEESE',  'Chicken Cheese Burger', [['RST-ING-CHKFILLET', 0.12, 'KG'], ['RST-ING-BUN', 1, 'PCS'], ['RST-ING-CHEESE', 1, 'PCS'], ['RST-ING-MAYO', 0.02, 'KG']]],
            ['RST-BURGER-ZINGER',  'Zinger Burger', [['RST-ING-CHKFILLET', 0.12, 'KG'], ['RST-ING-BUN', 1, 'PCS'], ['RST-ING-MAYO', 0.02, 'KG']]],
            ['RST-PIZZA-FAJITA',   'Chicken Fajita Pizza', [['RST-ING-DOUGH', 1, 'PCS'], ['RST-ING-MOZZ', 0.15, 'KG'], ['RST-ING-PIZZASAUCE', 0.05, 'KG'], ['RST-ING-TIKKA', 0.10, 'KG']]],
            ['RST-BIRYANI-CHK',    'Chicken Biryani', [['RST-ING-CHKFILLET', 0.20, 'KG'], ['RST-ING-RICE', 0.15, 'KG'], ['RST-ING-OIL', 0.03, 'L']]],
            ['RST-FRIES',          'French Fries', [['RST-ING-FRIES', 0.20, 'KG'], ['RST-ING-OIL', 0.05, 'L']]],
            ['RST-MARGARITA',      'Mint Margarita', [['RST-ING-MINT', 0.01, 'KG'], ['RST-ING-LEMON', 0.02, 'KG'], ['RST-ING-SUGAR', 0.03, 'KG']]],
            ['RST-TEA',            'Tea', [['RST-ING-TEALEAF', 0.01, 'KG'], ['RST-ING-MILK', 0.10, 'L'], ['RST-ING-SUGAR', 0.02, 'KG']]],
        ];

        $count = 0;
        foreach ($recipes as [$menuSku, $recipeName, $ings]) {
            $menu = Product::where('sku', $menuSku)->first();
            if (! $menu) continue;
            $recipe = Recipe::updateOrCreate(
                ['product_id' => $menu->id, 'name' => $recipeName],
                [
                    'yield_quantity' => 1, 'yield_unit_id' => $pcs?->id, 'is_active' => true,
                    'notes' => 'Bingoo Pro demo recipe',
                    'recipe_no' => 'REC-' . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT),
                    'revision_no' => 1, 'review_date' => now()->toDateString(), 'overhead_percent' => 0,
                ]
            );
            $recipe->ingredients()->delete();
            $sort = 1;
            foreach ($ings as [$ingSku, $qty, $unitCode]) {
                $ing = Product::where('sku', $ingSku)->first();
                if (! $ing) continue;
                // Pin the ingredient's default variant so recipe consumption
                // (postOutFefo with $ingredient->variant) deducts from the same
                // variant the opening stock was posted under.
                $ingVariant = $this->inv->resolveVariant($ing, null);
                $recipe->ingredients()->create([
                    'product_id'         => $ing->id,
                    'product_variant_id' => $ingVariant?->id,
                    'quantity'           => $qty,
                    'unit_id'            => $u($unitCode),
                    'sort_order'         => $sort++,
                ]);
            }
            $count++;
        }
        $this->counts['recipes'] = $count;
    }

    protected function seedSuppliersAndPurchases(): void
    {
        if (PurchaseOrder::count() > 0) {
            $this->counts['purchases'] = 'already exist';
            return;
        }
        $branch = $this->mainBranch();
        $owner  = User::where('email', 'owner@' . app('tenant')->tenant_code . '.com')->first() ?? User::query()->orderBy('id')->first();

        $sup1 = Supplier::updateOrCreate(['code' => 'RST-SUP-001'], ['name' => 'Fresh Meat Suppliers', 'contact_person' => 'Imran Q', 'phone' => '042-37300001', 'payment_terms_days' => 7, 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);
        $sup2 = Supplier::updateOrCreate(['code' => 'RST-SUP-002'], ['name' => 'Dairy & Bakery Co', 'contact_person' => 'Sara K', 'phone' => '042-37300002', 'payment_terms_days' => 15, 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);

        $po1Lines = [['sku' => 'RST-ING-BEEFPATTY', 'qty' => 200, 'cost' => 90], ['sku' => 'RST-ING-CHKFILLET', 'qty' => 30, 'cost' => 550], ['sku' => 'RST-ING-TIKKA', 'qty' => 10, 'cost' => 650]];
        $po1 = $this->createPO('RST-PO-001', $branch, $sup1, $po1Lines, $owner, now()->subDays(12));
        $grn1 = $this->createGRN($po1, $po1Lines, $owner, now()->subDays(10));
        $this->createBill($grn1, $po1Lines, $owner, now()->subDays(9));

        $po2Lines = [['sku' => 'RST-ING-BUN', 'qty' => 300, 'cost' => 25], ['sku' => 'RST-ING-CHEESE', 'qty' => 250, 'cost' => 18], ['sku' => 'RST-ING-MOZZ', 'qty' => 12, 'cost' => 900]];
        $po2 = $this->createPO('RST-PO-002', $branch, $sup2, $po2Lines, $owner, now()->subDays(6));
        $this->createGRN($po2, $po2Lines, $owner, now()->subDays(4));
        // bill left pending for PO-002 to show an unbilled GRN

        $this->counts['purchases'] = '2 suppliers, 2 POs, 2 GRNs, 1 bill';
    }

    private function createPO(string $poNo, Branch $branch, Supplier $supplier, array $lines, User $user, $date): PurchaseOrder
    {
        $total = collect($lines)->sum(fn ($l) => $l['qty'] * $l['cost']);
        $po = PurchaseOrder::create([
            'po_no'                  => $poNo,
            'branch_id'              => $branch->id,
            'supplier_id'            => $supplier->id,
            'order_date'             => $date->toDateString(),
            'expected_delivery_date' => $date->copy()->addDays(2)->toDateString(),
            'status'                 => 'approved',
            'total_amount'           => $total,
            'posted_by_user_id'      => $user->id,
            'approved_by_user_id'    => $user->id,
            'approved_at'            => $date->copy()->addDay(),
            'notes'                  => 'Restaurant Pro demo purchase order',
        ]);
        foreach ($lines as $line) {
            $product = Product::where('sku', $line['sku'])->first();
            if (! $product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $po->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity_ordered'   => $line['qty'],
                'unit_cost'          => $line['cost'],
            ]);
        }
        return $po;
    }

    private function createGRN(PurchaseOrder $po, array $lines, User $user, $date): GoodsReceipt
    {
        $grnNo = 'GRN-' . $po->po_no;
        $grn = GoodsReceipt::create([
            'grn_no'            => $grnNo,
            'purchase_order_id' => $po->id,
            'branch_id'         => $po->branch_id,
            'supplier_id'       => $po->supplier_id,
            'receipt_date'      => $date->toDateString(),
            'status'            => 'posted',
            'notes'             => 'Restaurant Pro demo GRN',
            'posted_by_user_id' => $user->id,
            'posted_at'         => $date,
        ]);
        $branch = Branch::find($po->branch_id);
        foreach ($lines as $line) {
            $product = Product::where('sku', $line['sku'])->first();
            if (! $product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $grn->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity_received'  => (float) $line['qty'],
                'unit_cost'          => (float) $line['cost'],
            ]);
            $this->inv->postIn(
                branch: $branch, product: $product, variant: $variant,
                quantity: (float) $line['qty'], unitCost: (float) $line['cost'],
                movementType: 'purchase', referenceType: 'goods_receipt',
                referenceId: $grn->id, referenceNo: $grnNo,
                notes: 'Purchase stock in — ' . $grnNo, userId: $user->id,
            );
        }
        $po->update(['status' => 'received']);
        return $grn;
    }

    private function createBill(GoodsReceipt $grn, array $lines, User $user, $date): PurchaseBill
    {
        $subtotal = collect($lines)->sum(fn ($l) => $l['qty'] * $l['cost']);
        $bill = PurchaseBill::create([
            'bill_no'             => 'BILL-' . $grn->grn_no,
            'supplier_invoice_no' => 'SINV-' . strtoupper(Str::random(6)),
            'supplier_id'         => $grn->supplier_id,
            'branch_id'           => $grn->branch_id,
            'purchase_order_id'   => $grn->purchase_order_id,
            'goods_receipt_id'    => $grn->id,
            'bill_date'           => $date->toDateString(),
            'due_date'            => $date->copy()->addDays(30)->toDateString(),
            'status'              => 'posted',
            'subtotal'            => $subtotal,
            'discount_total'      => 0,
            'tax_total'           => 0,
            'grand_total'         => $subtotal,
            'amount_paid'         => 0,
            'balance_due'         => $subtotal,
            'posted_by_user_id'   => $user->id,
            'posted_at'           => $date,
        ]);
        foreach ($lines as $line) {
            $product = Product::where('sku', $line['sku'])->first();
            if (! $product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $bill->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity'           => $line['qty'],
                'unit_cost'          => $line['cost'],
                'discount_amount'    => 0,
                'tax_amount'         => 0,
                'line_total'         => $line['qty'] * $line['cost'],
            ]);
        }
        return $bill;
    }

    protected function seedKdsOrders(): void
    {
        if (SalesOrder::where('status', 'held')->count() > 0) {
            $this->counts['kds_orders'] = 'already exist';
            return;
        }
        $branch = $this->mainBranch();
        $owner  = User::where('email', 'owner@' . app('tenant')->tenant_code . '.com')->first() ?? User::query()->orderBy('id')->first();
        if (! $branch || ! $owner) {
            $this->counts['kds_orders'] = 'skipped (missing branch/user)';
            return;
        }

        $held = [
            ['status' => 'pending',   'lines' => [['RST-BURGER-CLASSIC', 2], ['RST-FRIES', 2]]],
            ['status' => 'preparing', 'lines' => [['RST-PIZZA-FAJITA', 1], ['RST-MARGARITA', 2]]],
            ['status' => 'ready',     'lines' => [['RST-BIRYANI-CHK', 3], ['RST-TEA', 3]]],
        ];

        $count = 0;
        foreach ($held as $h) {
            $subtotal = 0;
            $resolved = [];
            foreach ($h['lines'] as [$sku, $qty]) {
                $p = Product::where('sku', $sku)->first();
                if (! $p) continue;
                $price = (float) $p->default_selling_price;
                $subtotal += $qty * $price;
                $resolved[] = [$p, $qty, $price];
            }
            if (! $resolved) continue;

            $sale = SalesOrder::create([
                'sale_no'            => 'KDS-' . now()->format('Ymd') . '-' . str_pad(SalesOrder::count() + 1, 4, '0', STR_PAD_LEFT),
                'branch_id'          => $branch->id,
                'order_source'       => 'pos',
                'order_type'         => 'dine_in',
                'sale_date'          => now(),
                'subtotal'           => $subtotal,
                'discount_type'      => 'none',
                'discount_value'     => 0,
                'discount_amount'    => 0,
                'tax_amount'         => 0,
                'grand_total'        => $subtotal,
                'status'             => 'held',
                'created_by_user_id' => $owner->id,
            ]);
            foreach ($resolved as [$p, $qty, $price]) {
                $variant = $this->inv->resolveVariant($p, null);
                $sale->lines()->create([
                    'product_id'         => $p->id,
                    'product_variant_id' => $variant?->id,
                    'product_name'       => $p->name,
                    'variant_name'       => $variant?->name,
                    'quantity'           => $qty,
                    'unit_price'         => $price,
                    'unit_cost'          => 0,
                    'cost_total'         => 0,
                    'discount_amount'    => 0,
                    'tax_amount'         => 0,
                    'line_total'         => $qty * $price,
                    'kitchen_status'     => $h['status'],
                    'kot_sent'           => true,
                    'kot_sent_quantity'  => $qty,
                    'kitchen_started_at' => $h['status'] !== 'pending' ? now()->subMinutes(8) : null,
                    'kitchen_ready_at'   => $h['status'] === 'ready' ? now()->subMinutes(2) : null,
                ]);
            }
            $count++;
        }
        $this->counts['kds_orders'] = "{$count} held dine-in orders (pending/preparing/ready)";
    }
}
