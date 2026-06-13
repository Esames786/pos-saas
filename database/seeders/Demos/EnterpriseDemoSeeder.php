<?php

namespace Database\Seeders\Demos;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\Promotion;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\Recipe;
use App\Models\Tenant\RestaurantFloor;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantWaiter;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\ServiceChargeSetting;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockTransfer;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Models\Tenant\VoidReason;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SalesService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Enterprise demo (enterprisedemo, enterprise plan — all 13 modules, unlimited
 * limits). Multi-branch rollout: HQ + Downtown Retail + Mall Restaurant +
 * Warehouse, with branch-level catalog, stock, purchasing, transfers,
 * restaurant + KDS, and branch-level sales — so branch-comparison reports are
 * meaningful. Reuses the proven single-branch patterns, applied per branch.
 *
 * MUST run inside an activated tenant DB. Idempotent. Billing/subscription
 * demo data is intentionally NOT seeded (see report).
 */
class EnterpriseDemoSeeder
{
    private InventoryService $inv;
    private SalesService $sales;
    private array $counts = [];

    private ?Branch $hq = null;
    private ?Branch $downtown = null;
    private ?Branch $mall = null;
    private ?Branch $warehouse = null;

    public function run(): array
    {
        $this->inv   = app(InventoryService::class);
        $this->sales = app(SalesService::class);

        $this->seedUnits();
        $this->seedBranches();
        $this->seedTerminals();
        $this->seedCategories();
        $this->seedRetailProducts();
        $this->seedMenuProducts();
        $this->seedIngredients();
        $this->seedRolesAndUsers();
        $this->seedOpeningStock();
        $this->seedRecipes();
        $this->seedSuppliersAndPurchases();
        $this->seedStockTransfers();
        $this->seedRestaurant();
        $this->seedRetailSales();
        $this->seedRestaurantSales();
        $this->seedKdsOrders();

        $this->counts['billing'] = 'skipped for safety (no payment proofs / invoices)';

        return $this->counts;
    }

    private function seedUnits(): void
    {
        foreach ([
            ['code' => 'PCS', 'name' => 'Piece',    'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true],
            ['code' => 'BTL', 'name' => 'Bottle',   'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => false],
            ['code' => 'PKT', 'name' => 'Packet',   'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => false],
            ['code' => 'BOX', 'name' => 'Box',      'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => false],
            ['code' => 'KG',  'name' => 'Kilogram', 'unit_type' => 'weight',   'base_factor' => 1, 'is_base' => true],
            ['code' => 'L',   'name' => 'Litre',    'unit_type' => 'volume',   'base_factor' => 1, 'is_base' => true],
        ] as $u) {
            Unit::updateOrCreate(['code' => $u['code']], array_merge($u, ['is_active' => true]));
        }
        $this->counts['units'] = Unit::count();
    }

    private function seedBranches(): void
    {
        // Base provisioning created "Main Branch" (MAIN) — repurpose as HQ.
        $this->hq = Branch::where('code', 'MAIN')->first() ?? Branch::query()->orderBy('id')->first();
        if ($this->hq) {
            $this->hq->update(['name' => 'HQ / Main Branch']);
        }
        $this->downtown  = $this->branch('DOWNTOWN',  'Downtown Retail Branch', 'Mall Road, City Centre',   '042-37400001');
        $this->mall      = $this->branch('MALLRESTO', 'Mall Restaurant Branch', 'Emporium Mall, Food Court','042-37400002');
        $this->warehouse = $this->branch('WAREHOUSE', 'Central Warehouse',      'Industrial Estate, Zone 3','042-37400003');
        $this->counts['branches'] = Branch::count();
    }

    private function branch(string $code, string $name, string $address, string $phone): Branch
    {
        return Branch::updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'address' => $address, 'phone' => $phone, 'email' => strtolower($code) . '@enterprisedemo.com', 'status' => 'active']
        );
    }

    private function seedTerminals(): void
    {
        $map = [
            ['HQ-ADMIN',      $this->hq,        'HQ Admin',        false],
            ['DOWNTOWN-POS-1',$this->downtown,  'Downtown POS 1',  true],
            ['DOWNTOWN-POS-2',$this->downtown,  'Downtown POS 2',  false],
            ['MALL-POS-1',    $this->mall,      'Mall POS 1',      true],
            ['MALL-KDS-1',    $this->mall,      'Mall Kitchen Display', false],
            ['WAREHOUSE-OPS', $this->warehouse, 'Warehouse Ops',   false],
        ];
        foreach ($map as [$code, $branch, $name, $shift]) {
            if (! $branch) continue;
            Terminal::updateOrCreate(['code' => $code], ['branch_id' => $branch->id, 'name' => $name, 'requires_shift' => $shift, 'status' => 'active']);
        }
        $this->counts['terminals'] = Terminal::count();
    }

    private function seedCategories(): void
    {
        foreach ([
            ['ENT-RETAIL', 'Retail Grocery',      1],
            ['ENT-BEV',    'Beverages',           2],
            ['ENT-MENU',   'Restaurant Menu',     3],
            ['ENT-KITCHEN','Kitchen Ingredients', 4],
            ['ENT-WARE',   'Warehouse Stock',     5],
            ['ENT-PACK',   'Packaging',           6],
        ] as [$code, $name, $sort]) {
            $cat = Category::updateOrCreate(['code' => $code], ['name' => $name, 'slug' => Str::slug($name), 'sort_order' => $sort, 'is_active' => true]);
            $cat->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $name]);
        }
        $this->counts['categories'] = 6;
    }

    private function makeProduct(string $sku, string $name, string $catCode, string $unitCode, $buy, $sell, array $opts = []): Product
    {
        $category = Category::where('code', $catCode)->first();
        $unit = Unit::where('code', $unitCode)->first();
        $product = Product::updateOrCreate(
            ['sku' => $sku],
            array_merge([
                'category_id'                  => $category?->id,
                'unit_id'                      => $unit?->id,
                'name'                         => $name,
                'slug'                         => Str::slug($name),
                'product_type'                 => $opts['type'] ?? 'simple',
                'item_kind'                    => $opts['kind'] ?? 'finished_good',
                'inventory_consumption_method' => $opts['consume'] ?? 'stock_item',
                'is_sellable'                  => $opts['sellable'] ?? true,
                'is_purchasable'               => $opts['purchasable'] ?? true,
                'is_stock_tracked'             => $opts['tracked'] ?? true,
                'has_expiry'                   => false,
                'requires_batch'               => false,
                'default_purchase_price'       => $buy,
                'default_selling_price'        => $sell,
                'is_taxable'                   => false,
                'status'                       => 'active',
            ], [])
        );
        $product->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $name]);
        ProductVariant::updateOrCreate(
            ['sku' => $sku],
            ['product_id' => $product->id, 'name' => $name, 'purchase_price' => $buy, 'selling_price' => $sell,
             'reorder_level' => $opts['reorder'] ?? 5, 'reorder_quantity' => 20, 'is_default' => true, 'is_active' => true]
        );
        return $product;
    }

    private function retailProducts(): array
    {
        return [
            ['ENT-RET-RICE',   'Basmati Rice 5kg',     'ENT-RETAIL', 'PKT', 850, 1100],
            ['ENT-RET-SUGAR',  'Sugar 1kg',            'ENT-RETAIL', 'PKT', 120, 160],
            ['ENT-RET-OIL',    'Cooking Oil 1L',       'ENT-RETAIL', 'BTL', 380, 480],
            ['ENT-RET-TEA',    'Tea Pack 250g',        'ENT-RETAIL', 'PKT', 180, 250],
            ['ENT-RET-WATER',  'Mineral Water 1.5L',   'ENT-BEV',    'BTL', 30,  50],
            ['ENT-RET-COLA',   'Cola Bottle 1.5L',     'ENT-BEV',    'BTL', 90,  130],
            ['ENT-RET-MILK',   'Milk Pack 1L',         'ENT-RETAIL', 'BTL', 120, 150],
            ['ENT-RET-BREAD',  'Bread Loaf',           'ENT-RETAIL', 'PCS', 80,  120],
            ['ENT-RET-CHIPS',  'Potato Chips',         'ENT-RETAIL', 'PKT', 65,  95],
            ['ENT-RET-BISCUIT','Biscuits Pack',        'ENT-RETAIL', 'PKT', 55,  80],
            ['ENT-RET-DISH',   'Dishwash Liquid',      'ENT-RETAIL', 'BTL', 160, 230],
            ['ENT-RET-DETER',  'Laundry Detergent',    'ENT-RETAIL', 'PKT', 280, 380],
            ['ENT-RET-SHAMPOO','Shampoo 200ml',        'ENT-RETAIL', 'BTL', 240, 330],
            ['ENT-RET-TPASTE', 'Toothpaste',           'ENT-RETAIL', 'PCS', 130, 185],
            ['ENT-RET-ROLLS',  'Receipt Rolls Box',    'ENT-PACK',   'BOX', 900, 1200, ['sellable' => false]],
        ];
    }

    private function menuProducts(): array
    {
        // [sku, name, price, isRecipe]
        return [
            ['ENT-MENU-BURGER-CLASSIC', 'Classic Beef Burger',   450, true],
            ['ENT-MENU-BURGER-CHEESE',  'Chicken Cheese Burger', 420, true],
            ['ENT-MENU-BURGER-ZINGER',  'Zinger Burger',         480, true],
            ['ENT-MENU-PIZZA-FAJITA',   'Chicken Fajita Pizza',  950, true],
            ['ENT-MENU-BIRYANI',        'Chicken Biryani',       400, true],
            ['ENT-MENU-KARAHI',         'Chicken Karahi Half',   900, false],
            ['ENT-MENU-FRIES',          'French Fries',          200, true],
            ['ENT-MENU-LOADED',         'Loaded Fries',          350, false],
            ['ENT-MENU-MARGARITA',      'Mint Margarita',        250, true],
            ['ENT-MENU-SOFTDRINK',      'Soft Drink Can',        120, false],
            ['ENT-MENU-WATER',          'Mineral Water',         60,  false],
            ['ENT-MENU-BROWNIE',        'Chocolate Brownie',     300, false],
            ['ENT-MENU-TEA',            'Tea',                   80,  true],
            ['ENT-MENU-CAPP',           'Cappuccino',            280, false],
            ['ENT-MENU-DIP',            'Garlic Mayo Dip',       60,  false],
        ];
    }

    private function ingredients(): array
    {
        // [sku, name, unit, cost, low?]
        return [
            ['ENT-ING-BEEFPATTY', 'Beef Patty',          'PCS', 90,  false],
            ['ENT-ING-CHKFILLET', 'Chicken Fillet',      'KG',  550, false],
            ['ENT-ING-BUN',       'Burger Bun',          'PCS', 25,  false],
            ['ENT-ING-CHEESE',    'Cheese Slice',        'PCS', 18,  false],
            ['ENT-ING-DOUGH',     'Pizza Dough',         'PCS', 60,  false],
            ['ENT-ING-MOZZ',      'Mozzarella Cheese',   'KG',  900, false],
            ['ENT-ING-RICE',      'Basmati Rice Bulk',   'KG',  180, false],
            ['ENT-ING-OIL',       'Cooking Oil',         'L',   380, false],
            ['ENT-ING-FRIES',     'French Fries Frozen', 'KG',  220, true],
            ['ENT-ING-MAYO',      'Mayo',                'KG',  280, false],
            ['ENT-ING-MINT',      'Mint',                'KG',  100, true],
            ['ENT-ING-LEMON',     'Lemon',               'KG',  150, false],
            ['ENT-ING-MILK',      'Milk Bulk',           'L',   150, false],
            ['ENT-ING-TEALEAF',   'Tea Leaves',          'KG',  900, false],
            ['ENT-ING-PACKBOX',   'Packaging Box',       'PCS', 12,  true],
        ];
    }

    private function seedRetailProducts(): void
    {
        foreach ($this->retailProducts() as $p) {
            $this->makeProduct($p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6] ?? []);
        }
        $this->counts['retail_products'] = count($this->retailProducts());
    }

    private function seedMenuProducts(): void
    {
        foreach ($this->menuProducts() as [$sku, $name, $price, $isRecipe]) {
            $this->makeProduct($sku, $name, 'ENT-MENU', 'PCS', round($price * 0.4), $price, [
                'type' => $isRecipe ? 'recipe' : 'service',
                'consume' => $isRecipe ? 'recipe' : 'none',
                'tracked' => false,
                'purchasable' => false,
            ]);
        }
        $this->counts['menu_products'] = count($this->menuProducts());
    }

    private function seedIngredients(): void
    {
        foreach ($this->ingredients() as [$sku, $name, $unit, $cost, $low]) {
            $this->makeProduct($sku, $name, 'ENT-KITCHEN', $unit, $cost, 0, [
                'kind' => 'ingredient', 'sellable' => false, 'tracked' => true, 'reorder' => $low ? 10 : 5,
            ]);
        }
        $this->counts['ingredients'] = count($this->ingredients());
    }

    private function seedRolesAndUsers(): void
    {
        $demoRole = Role::where('name', 'Demo')->where('guard_name', 'tenant')->first();
        $safePerms = $demoRole ? $demoRole->permissions->pluck('name')->all() : [];

        // Named roles (same safe permission subset as Demo) so the demo shows a
        // role hierarchy in Users & Roles without exposing destructive access.
        $roleBranch = [
            'Regional Manager' => null,
            'Branch Manager'   => $this->downtown,
            'Warehouse Manager'=> $this->warehouse,
            'Cashier'          => $this->downtown,
            'Waiter'           => $this->mall,
            'Kitchen Staff'    => $this->mall,
        ];
        $roles = [];
        foreach (array_keys($roleBranch) as $roleName) {
            $role = Role::findOrCreate($roleName, 'tenant');
            $role->syncPermissions($safePerms);
            $roles[$roleName] = $role;
        }

        $users = [
            ['regional.manager',  'Regional Manager', 'Regional Manager',  null],
            ['branch.manager',    'Branch Manager',   'Branch Manager',    $this->downtown],
            ['warehouse.manager', 'Warehouse Manager','Warehouse Manager', $this->warehouse],
            ['cashier.downtown',  'Downtown Cashier', 'Cashier',           $this->downtown],
            ['waiter.mall',       'Mall Waiter',      'Waiter',            $this->mall],
            ['kitchen.mall',      'Mall Kitchen',     'Kitchen Staff',     $this->mall],
        ];
        foreach ($users as [$prefix, $name, $roleName, $branch]) {
            $user = User::updateOrCreate(
                ['email' => $prefix . '@enterprisedemo.com'],
                ['name' => $name, 'password' => Hash::make(config('saas.demos.default_password', 'demo1234')),
                 'status' => 'active', 'locale' => 'en', 'default_branch_id' => $branch?->id]
            );
            if ($branch) $user->branches()->syncWithoutDetaching([$branch->id]);
            $user->syncRoles([$roles[$roleName]]);
        }
        $this->counts['roles'] = count($roles);
        $this->counts['staff_users'] = count($users);
    }

    private function postOpening(Branch $branch, array $items, string $label): int
    {
        if (StockAdjustment::where('branch_id', $branch->id)->where('adjustment_type', 'opening')->exists()) {
            return 0;
        }
        $adjustment = StockAdjustment::create([
            'adjustment_no'   => 'ADJ-OPEN-' . $branch->code . '-' . now()->format('YmdHis'),
            'branch_id'       => $branch->id,
            'adjustment_type' => 'opening',
            'adjustment_date' => now()->toDateString(),
            'status'          => 'posted',
            'posted_at'       => now(),
            'notes'           => "Enterprise demo opening stock — {$label}",
        ]);
        $lines = 0;
        foreach ($items as [$sku, $qty, $cost]) {
            $product = Product::where('sku', $sku)->first();
            if (! $product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $line = $adjustment->lines()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'quantity' => $qty, 'unit_cost' => $cost]);
            $ledger = $this->inv->postIn(
                branch: $branch, product: $product, variant: $variant, quantity: $qty, unitCost: $cost,
                movementType: 'opening_stock', referenceType: 'stock_adjustment',
                referenceId: $adjustment->id, referenceNo: $adjustment->adjustment_no, notes: "Opening — {$label}",
            );
            $line->update(['inventory_batch_id' => $ledger->inventory_batch_id]);
            $lines++;
        }
        return $lines;
    }

    private function seedOpeningStock(): void
    {
        // Retail stock at Downtown (working) + Warehouse (bulk).
        $retail = collect($this->retailProducts())->map(fn ($p) => [$p[0], 100, $p[4]])->all();
        $retailBulk = collect($this->retailProducts())->map(fn ($p) => [$p[0], 200, $p[4]])->all();
        // Ingredient stock at Mall (working, for recipe consumption) + Warehouse (bulk).
        $ing = collect($this->ingredients())->map(fn ($i) => [$i[0], $i[4] ? 8 : 80, $i[3]])->all();
        $ingBulk = collect($this->ingredients())->map(fn ($i) => [$i[0], 150, $i[3]])->all();

        $r = [];
        $r['downtown']  = $this->downtown  ? $this->postOpening($this->downtown,  $retail,     'Downtown')  : 0;
        $r['mall']      = $this->mall      ? $this->postOpening($this->mall,      $ing,        'Mall')      : 0;
        $r['warehouse'] = $this->warehouse ? $this->postOpening($this->warehouse, array_merge($retailBulk, $ingBulk), 'Warehouse') : 0;
        $this->counts['opening_stock'] = $r;
    }

    private function seedRecipes(): void
    {
        $pcs = Unit::where('code', 'PCS')->first();
        $kg  = Unit::where('code', 'KG')->first();
        $ltr = Unit::where('code', 'L')->first();
        $u = fn ($c) => $c === 'PCS' ? $pcs?->id : ($c === 'KG' ? $kg?->id : $ltr?->id);

        $recipes = [
            ['ENT-MENU-BURGER-CLASSIC', 'Classic Beef Burger', [['ENT-ING-BEEFPATTY', 1, 'PCS'], ['ENT-ING-BUN', 1, 'PCS'], ['ENT-ING-CHEESE', 1, 'PCS'], ['ENT-ING-MAYO', 0.02, 'KG']]],
            ['ENT-MENU-BURGER-CHEESE',  'Chicken Cheese Burger', [['ENT-ING-CHKFILLET', 0.12, 'KG'], ['ENT-ING-BUN', 1, 'PCS'], ['ENT-ING-CHEESE', 1, 'PCS'], ['ENT-ING-MAYO', 0.02, 'KG']]],
            ['ENT-MENU-BURGER-ZINGER',  'Zinger Burger', [['ENT-ING-CHKFILLET', 0.12, 'KG'], ['ENT-ING-BUN', 1, 'PCS'], ['ENT-ING-MAYO', 0.02, 'KG']]],
            ['ENT-MENU-PIZZA-FAJITA',   'Chicken Fajita Pizza', [['ENT-ING-DOUGH', 1, 'PCS'], ['ENT-ING-MOZZ', 0.15, 'KG'], ['ENT-ING-CHKFILLET', 0.10, 'KG']]],
            ['ENT-MENU-BIRYANI',        'Chicken Biryani', [['ENT-ING-CHKFILLET', 0.20, 'KG'], ['ENT-ING-RICE', 0.15, 'KG'], ['ENT-ING-OIL', 0.03, 'L']]],
            ['ENT-MENU-FRIES',          'French Fries', [['ENT-ING-FRIES', 0.20, 'KG'], ['ENT-ING-OIL', 0.05, 'L']]],
            ['ENT-MENU-MARGARITA',      'Mint Margarita', [['ENT-ING-MINT', 0.01, 'KG'], ['ENT-ING-LEMON', 0.02, 'KG']]],
            ['ENT-MENU-TEA',            'Tea', [['ENT-ING-TEALEAF', 0.01, 'KG'], ['ENT-ING-MILK', 0.10, 'L']]],
        ];
        $count = 0;
        foreach ($recipes as [$menuSku, $name, $ings]) {
            $menu = Product::where('sku', $menuSku)->first();
            if (! $menu) continue;
            $recipe = Recipe::updateOrCreate(
                ['product_id' => $menu->id, 'name' => $name],
                ['yield_quantity' => 1, 'yield_unit_id' => $pcs?->id, 'is_active' => true, 'notes' => 'Bingoo enterprise demo recipe']
            );
            $recipe->ingredients()->delete();
            $sort = 1;
            foreach ($ings as [$ingSku, $qty, $unitCode]) {
                $ing = Product::where('sku', $ingSku)->first();
                if (! $ing) continue;
                $ingVariant = $this->inv->resolveVariant($ing, null);
                $recipe->ingredients()->create(['product_id' => $ing->id, 'product_variant_id' => $ingVariant?->id, 'quantity' => $qty, 'unit_id' => $u($unitCode), 'sort_order' => $sort++]);
            }
            $count++;
        }
        $this->counts['recipes'] = $count;
    }

    private function seedSuppliersAndPurchases(): void
    {
        if (PurchaseOrder::count() > 0) { $this->counts['purchases'] = 'already exist'; return; }
        $owner = $this->owner();
        $sup1 = Supplier::updateOrCreate(['code' => 'ENT-SUP-001'], ['name' => 'National Distributors', 'contact_person' => 'Asif Q', 'phone' => '042-37500001', 'payment_terms_days' => 30, 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);
        $sup2 = Supplier::updateOrCreate(['code' => 'ENT-SUP-002'], ['name' => 'Fresh Foods Wholesale', 'contact_person' => 'Sara K', 'phone' => '042-37500002', 'payment_terms_days' => 15, 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);

        // Restock Warehouse with retail + ingredient lines.
        $po1Lines = [['sku' => 'ENT-RET-RICE', 'qty' => 50, 'cost' => 850], ['sku' => 'ENT-RET-OIL', 'qty' => 60, 'cost' => 380], ['sku' => 'ENT-RET-SUGAR', 'qty' => 80, 'cost' => 120]];
        $po1 = $this->createPO('ENT-PO-001', $this->warehouse, $sup1, $po1Lines, $owner, now()->subDays(12));
        $grn1 = $this->createGRN($po1, $po1Lines, $owner, now()->subDays(10));
        $this->createBill($grn1, $po1Lines, $owner, now()->subDays(9));

        $po2Lines = [['sku' => 'ENT-ING-BEEFPATTY', 'qty' => 200, 'cost' => 90], ['sku' => 'ENT-ING-CHKFILLET', 'qty' => 30, 'cost' => 550], ['sku' => 'ENT-ING-MOZZ', 'qty' => 12, 'cost' => 900]];
        $po2 = $this->createPO('ENT-PO-002', $this->warehouse, $sup2, $po2Lines, $owner, now()->subDays(6));
        $this->createGRN($po2, $po2Lines, $owner, now()->subDays(4));
        $this->counts['purchases'] = '2 suppliers, 2 POs, 2 GRNs, 1 bill';
    }

    private function seedStockTransfers(): void
    {
        if (! $this->warehouse) { $this->counts['transfers'] = 'skipped'; return; }
        if (StockTransfer::count() > 0) { $this->counts['transfers'] = 'already exist'; return; }
        $owner = $this->owner();
        $made = 0;
        if ($this->downtown) {
            $made += $this->transfer('ENT-TRF-001', $this->warehouse, $this->downtown, [['ENT-RET-RICE', 10, 850], ['ENT-RET-OIL', 12, 380]], $owner) ? 1 : 0;
        }
        if ($this->mall) {
            $made += $this->transfer('ENT-TRF-002', $this->warehouse, $this->mall, [['ENT-ING-BEEFPATTY', 30, 90], ['ENT-ING-CHKFILLET', 5, 550]], $owner) ? 1 : 0;
        }
        $this->counts['transfers'] = "{$made} (Warehouse → Downtown / Mall)";
    }

    private function transfer(string $no, Branch $from, Branch $to, array $lines, User $owner): bool
    {
        try {
            $transfer = StockTransfer::create([
                'transfer_no' => $no, 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
                'transfer_date' => now()->subDays(2)->toDateString(), 'status' => 'posted',
                'posted_by_user_id' => $owner->id, 'posted_at' => now()->subDays(2), 'notes' => 'Enterprise replenishment',
            ]);
            foreach ($lines as [$sku, $qty, $cost]) {
                $product = Product::where('sku', $sku)->first();
                if (! $product) continue;
                $variant = $this->inv->resolveVariant($product, null);
                $transfer->lines()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'quantity' => $qty, 'unit_cost' => $cost]);
                $this->inv->postOutFefo(branch: $from, product: $product, variant: $variant, quantity: $qty, movementType: 'transfer_out', referenceType: 'stock_transfer', referenceId: $transfer->id, referenceNo: $no, notes: 'Transfer out', userId: $owner->id);
                $this->inv->postIn(branch: $to, product: $product, variant: $variant, quantity: $qty, unitCost: $cost, movementType: 'transfer_in', referenceType: 'stock_transfer', referenceId: $transfer->id, referenceNo: $no, notes: 'Transfer in', userId: $owner->id);
            }
            return true;
        } catch (\Throwable $e) { return false; }
    }

    private function seedRestaurant(): void
    {
        if (! $this->mall) { $this->counts['restaurant'] = 'skipped (no mall branch)'; return; }
        $sort = 1; $tables = 0;
        foreach (['Mall Ground' => [['M1', 4], ['M2', 4], ['M3', 6]], 'Mall Food Court' => [['MF1', 4], ['MF2', 6]]] as $floorName => $list) {
            $floor = RestaurantFloor::updateOrCreate(['branch_id' => $this->mall->id, 'name' => $floorName], ['sort_order' => $sort++, 'status' => 'active']);
            foreach ($list as [$no, $cap]) {
                RestaurantTable::updateOrCreate(['branch_id' => $this->mall->id, 'restaurant_floor_id' => $floor->id, 'table_no' => $no], ['capacity' => $cap, 'status' => 'available', 'sort_order' => 0]);
                $tables++;
            }
        }
        foreach ([['MW-001', 'Imran Ali'], ['MW-002', 'Sana Tariq'], ['MW-003', 'Bilal Khan']] as [$code, $name]) {
            RestaurantWaiter::updateOrCreate(['branch_id' => $this->mall->id, 'code' => $code], ['name' => $name, 'status' => 'active']);
        }
        Promotion::updateOrCreate(['code' => 'ENTDINE10'], ['branch_id' => $this->mall->id, 'name' => '10% Dine-in', 'promotion_type' => 'order', 'discount_type' => 'percent', 'discount_value' => 10, 'order_types' => ['dine_in'], 'requires_code' => true, 'status' => 'active', 'priority' => 100]);
        foreach ([['name' => 'Wrong Item', 'reason_type' => 'void'], ['name' => 'Customer Changed Mind', 'reason_type' => 'return'], ['name' => 'Out of Stock', 'reason_type' => 'cancel']] as $reason) {
            VoidReason::updateOrCreate(['name' => $reason['name']], array_merge($reason, ['is_active' => true]));
        }
        ServiceChargeSetting::updateOrCreate(['branch_id' => $this->mall->id], ['charge_type' => 'percent', 'charge_value' => 5, 'order_types' => ['dine_in'], 'is_taxable' => false, 'is_active' => true]);
        $this->counts['restaurant'] = "2 floors, {$tables} tables, 3 waiters, sales controls";
    }

    private function seedRetailSales(): void
    {
        if (! $this->downtown) { $this->counts['retail_sales'] = 'skipped'; return; }
        $owner = $this->owner();
        $cash = PaymentMethod::where('method_type', 'cash')->first();
        $card = PaymentMethod::where('method_type', 'card')->first();
        $orders = [
            ['pay' => $cash, 'days' => 0, 'lines' => [['ENT-RET-COLA', 3, 130], ['ENT-RET-CHIPS', 2, 95]]],
            ['pay' => $card, 'days' => 1, 'lines' => [['ENT-RET-RICE', 1, 1100], ['ENT-RET-OIL', 1, 480]]],
            ['pay' => $cash, 'days' => 2, 'lines' => [['ENT-RET-MILK', 2, 150], ['ENT-RET-BREAD', 1, 120], ['ENT-RET-BISCUIT', 3, 80]]],
            ['pay' => $cash, 'days' => 3, 'lines' => [['ENT-RET-SHAMPOO', 1, 330], ['ENT-RET-TPASTE', 2, 185]]],
            ['pay' => $card, 'days' => 4, 'lines' => [['ENT-RET-DETER', 1, 380], ['ENT-RET-DISH', 2, 230]]],
            ['pay' => $cash, 'days' => 5, 'lines' => [['ENT-RET-WATER', 6, 50], ['ENT-RET-COLA', 2, 130]]],
            ['pay' => $cash, 'days' => 6, 'lines' => [['ENT-RET-TEA', 2, 250], ['ENT-RET-SUGAR', 3, 160]]],
            ['pay' => $card, 'days' => 7, 'lines' => [['ENT-RET-RICE', 2, 1100], ['ENT-RET-MILK', 3, 150]]],
        ];
        $this->counts['retail_sales'] = $this->runOrders($this->downtown, $owner, $orders, 'quick_sale') . ' @ Downtown';
    }

    private function seedRestaurantSales(): void
    {
        if (! $this->mall) { $this->counts['restaurant_sales'] = 'skipped'; return; }
        $owner = $this->owner();
        $cash = PaymentMethod::where('method_type', 'cash')->first();
        $card = PaymentMethod::where('method_type', 'card')->first();
        $orders = [
            ['pay' => $cash, 'days' => 0, 'lines' => [['ENT-MENU-BURGER-CLASSIC', 2, 450], ['ENT-MENU-FRIES', 2, 200], ['ENT-MENU-SOFTDRINK', 2, 120]]],
            ['pay' => $card, 'days' => 1, 'lines' => [['ENT-MENU-BIRYANI', 3, 400], ['ENT-MENU-MARGARITA', 3, 250]]],
            ['pay' => $cash, 'days' => 2, 'lines' => [['ENT-MENU-PIZZA-FAJITA', 1, 950], ['ENT-MENU-TEA', 3, 80]]],
            ['pay' => $cash, 'days' => 3, 'lines' => [['ENT-MENU-BURGER-ZINGER', 3, 480], ['ENT-MENU-LOADED', 2, 350]]],
            ['pay' => $card, 'days' => 4, 'lines' => [['ENT-MENU-KARAHI', 1, 900], ['ENT-MENU-BROWNIE', 2, 300]]],
            ['pay' => $cash, 'days' => 5, 'lines' => [['ENT-MENU-BURGER-CHEESE', 2, 420], ['ENT-MENU-CAPP', 2, 280]]],
            ['pay' => $cash, 'days' => 6, 'lines' => [['ENT-MENU-FRIES', 3, 200], ['ENT-MENU-DIP', 3, 60], ['ENT-MENU-WATER', 3, 60]]],
        ];
        $this->counts['restaurant_sales'] = $this->runOrders($this->mall, $owner, $orders, 'dine_in') . ' @ Mall';
    }

    private function runOrders(Branch $branch, User $owner, array $orders, string $type): string
    {
        $count = 0;
        foreach ($orders as $o) {
            try {
                $saleDate = now()->subDays($o['days']);
                $subtotal = 0;
                foreach ($o['lines'] as [$sku, $qty, $price]) $subtotal += $qty * $price;
                $sale = SalesOrder::create([
                    'sale_no' => 'SO-' . $saleDate->format('Ymd') . '-' . str_pad(SalesOrder::count() + 1, 4, '0', STR_PAD_LEFT),
                    'branch_id' => $branch->id, 'order_source' => 'pos', 'order_type' => $type, 'sale_date' => $saleDate,
                    'subtotal' => $subtotal, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
                    'tax_amount' => 0, 'grand_total' => $subtotal, 'status' => 'draft', 'created_by_user_id' => $owner->id,
                ]);
                foreach ($o['lines'] as [$sku, $qty, $price]) {
                    $product = Product::where('sku', $sku)->first();
                    if (! $product) continue;
                    $variant = $this->inv->resolveVariant($product, null);
                    $sale->lines()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'product_name' => $product->name, 'variant_name' => $variant?->name, 'quantity' => $qty, 'unit_price' => $price, 'unit_cost' => 0, 'cost_total' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => $qty * $price]);
                }
                $method = $o['pay'];
                $sale->payments()->create(['payment_method_id' => $method->id, 'amount' => $subtotal, 'tendered_amount' => $method->method_type === 'cash' ? $subtotal : null, 'change_amount' => 0, 'transaction_ref' => $method->method_type === 'cash' ? null : 'CARD-' . Str::upper(Str::random(4))]);
                $this->sales->finalizePaidSale($sale);
                $count++;
            } catch (\Throwable $e) {}
        }
        return "{$count} paid orders";
    }

    private function seedKdsOrders(): void
    {
        if (! $this->mall) { $this->counts['kds_orders'] = 'skipped'; return; }
        if (SalesOrder::where('status', 'held')->count() > 0) { $this->counts['kds_orders'] = 'already exist'; return; }
        $owner = $this->owner();
        $held = [
            ['status' => 'pending',   'lines' => [['ENT-MENU-BURGER-CLASSIC', 2], ['ENT-MENU-FRIES', 2]]],
            ['status' => 'preparing', 'lines' => [['ENT-MENU-PIZZA-FAJITA', 1], ['ENT-MENU-MARGARITA', 2]]],
            ['status' => 'ready',     'lines' => [['ENT-MENU-BIRYANI', 3], ['ENT-MENU-TEA', 3]]],
        ];
        $count = 0;
        foreach ($held as $h) {
            $subtotal = 0; $resolved = [];
            foreach ($h['lines'] as [$sku, $qty]) {
                $p = Product::where('sku', $sku)->first();
                if (! $p) continue;
                $price = (float) $p->default_selling_price;
                $subtotal += $qty * $price;
                $resolved[] = [$p, $qty, $price];
            }
            if (! $resolved) continue;
            $sale = SalesOrder::create([
                'sale_no' => 'KDS-' . now()->format('Ymd') . '-' . str_pad(SalesOrder::count() + 1, 4, '0', STR_PAD_LEFT),
                'branch_id' => $this->mall->id, 'order_source' => 'pos', 'order_type' => 'dine_in', 'sale_date' => now(),
                'subtotal' => $subtotal, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
                'tax_amount' => 0, 'grand_total' => $subtotal, 'status' => 'held', 'created_by_user_id' => $owner->id,
            ]);
            foreach ($resolved as [$p, $qty, $price]) {
                $variant = $this->inv->resolveVariant($p, null);
                $sale->lines()->create(['product_id' => $p->id, 'product_variant_id' => $variant?->id, 'product_name' => $p->name, 'variant_name' => $variant?->name, 'quantity' => $qty, 'unit_price' => $price, 'unit_cost' => 0, 'cost_total' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => $qty * $price, 'kitchen_status' => $h['status'], 'kot_sent' => true, 'kot_sent_quantity' => $qty, 'kitchen_started_at' => $h['status'] !== 'pending' ? now()->subMinutes(8) : null, 'kitchen_ready_at' => $h['status'] === 'ready' ? now()->subMinutes(2) : null]);
            }
            $count++;
        }
        $this->counts['kds_orders'] = "{$count} held dine-in @ Mall";
    }

    private function owner(): User
    {
        return User::where('email', 'owner@enterprisedemo.com')->first() ?? User::query()->orderBy('id')->first();
    }

    // ── Purchasing helpers (mirror InventoryDemoSeeder) ──
    private function createPO(string $poNo, Branch $branch, Supplier $supplier, array $lines, User $user, $date): PurchaseOrder
    {
        $total = collect($lines)->sum(fn ($l) => $l['qty'] * $l['cost']);
        $po = PurchaseOrder::create(['po_no' => $poNo, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'order_date' => $date->toDateString(), 'expected_delivery_date' => $date->copy()->addDays(3)->toDateString(), 'status' => 'approved', 'total_amount' => $total, 'posted_by_user_id' => $user->id, 'approved_by_user_id' => $user->id, 'approved_at' => $date->copy()->addDay(), 'notes' => 'Enterprise demo PO']);
        foreach ($lines as $line) {
            $product = Product::where('sku', $line['sku'])->first();
            if (! $product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $po->lines()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'quantity_ordered' => $line['qty'], 'unit_cost' => $line['cost']]);
        }
        return $po;
    }

    private function createGRN(PurchaseOrder $po, array $lines, User $user, $date): GoodsReceipt
    {
        $grnNo = 'GRN-' . $po->po_no;
        $grn = GoodsReceipt::create(['grn_no' => $grnNo, 'purchase_order_id' => $po->id, 'branch_id' => $po->branch_id, 'supplier_id' => $po->supplier_id, 'receipt_date' => $date->toDateString(), 'status' => 'posted', 'notes' => 'Enterprise demo GRN', 'posted_by_user_id' => $user->id, 'posted_at' => $date]);
        $branch = Branch::find($po->branch_id);
        foreach ($lines as $line) {
            $product = Product::where('sku', $line['sku'])->first();
            if (! $product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $grn->lines()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'quantity_received' => (float) $line['qty'], 'unit_cost' => (float) $line['cost']]);
            $this->inv->postIn(branch: $branch, product: $product, variant: $variant, quantity: (float) $line['qty'], unitCost: (float) $line['cost'], movementType: 'purchase', referenceType: 'goods_receipt', referenceId: $grn->id, referenceNo: $grnNo, notes: 'Purchase in — ' . $grnNo, userId: $user->id);
        }
        $po->update(['status' => 'received']);
        return $grn;
    }

    private function createBill(GoodsReceipt $grn, array $lines, User $user, $date): PurchaseBill
    {
        $subtotal = collect($lines)->sum(fn ($l) => $l['qty'] * $l['cost']);
        $bill = PurchaseBill::create(['bill_no' => 'BILL-' . $grn->grn_no, 'supplier_invoice_no' => 'SINV-' . strtoupper(Str::random(6)), 'supplier_id' => $grn->supplier_id, 'branch_id' => $grn->branch_id, 'purchase_order_id' => $grn->purchase_order_id, 'goods_receipt_id' => $grn->id, 'bill_date' => $date->toDateString(), 'due_date' => $date->copy()->addDays(30)->toDateString(), 'status' => 'posted', 'subtotal' => $subtotal, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => $subtotal, 'amount_paid' => 0, 'balance_due' => $subtotal, 'posted_by_user_id' => $user->id, 'posted_at' => $date]);
        foreach ($lines as $line) {
            $product = Product::where('sku', $line['sku'])->first();
            if (! $product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $bill->lines()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'quantity' => $line['qty'], 'unit_cost' => $line['cost'], 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => $line['qty'] * $line['cost']]);
        }
        return $bill;
    }
}
