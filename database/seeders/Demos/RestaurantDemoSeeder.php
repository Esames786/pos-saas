<?php

namespace Database\Seeders\Demos;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\Modifier;
use App\Models\Tenant\ModifierGroup;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\Promotion;
use App\Models\Tenant\RestaurantFloor;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantWaiter;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\ServiceChargeSetting;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Models\Tenant\VoidReason;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SalesService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Rich Restaurant demo data for restaurantdemo (restaurant_starter plan).
 * Modules: pos, catalog, restaurant, printing, reports, sales_controls,
 * users_roles. No kitchen display, recipes, inventory, purchasing, or
 * multi-branch (those are Restaurant Pro — see RestaurantProDemoSeeder).
 *
 * MUST run inside an already-activated tenant DB. Idempotent.
 * Designed for extension by RestaurantProDemoSeeder.
 */
class RestaurantDemoSeeder
{
    protected InventoryService $inv;
    protected SalesService $sales;
    protected array $counts = [];
    protected string $codePrefix = 'RST';

    public function run(): array
    {
        $this->inv   = app(InventoryService::class);
        $this->sales = app(SalesService::class);

        $this->seedUnits();
        $this->seedFloorsAndTables();
        $this->seedWaiters();
        $this->seedStaff();
        $this->seedCategories();
        $this->seedMenu();
        $this->seedModifiersAndCombos();
        $this->seedSalesControls();
        $this->seedSampleOrders();

        return $this->counts;
    }

    protected function mainBranch(): ?Branch
    {
        return Branch::where('code', 'MAIN')->first() ?? Branch::query()->orderBy('id')->first();
    }

    protected function seedUnits(): void
    {
        foreach ([
            ['code' => 'PCS', 'name' => 'Piece',    'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true],
            ['code' => 'PLT', 'name' => 'Plate',    'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => false],
            ['code' => 'KG',  'name' => 'Kilogram', 'unit_type' => 'weight',   'base_factor' => 1, 'is_base' => true],
            ['code' => 'L',   'name' => 'Litre',    'unit_type' => 'volume',   'base_factor' => 1, 'is_base' => true],
        ] as $u) {
            Unit::updateOrCreate(['code' => $u['code']], array_merge($u, ['is_active' => true]));
        }
        $this->counts['units'] = Unit::count();
    }

    protected function seedFloorsAndTables(): void
    {
        $branch = $this->mainBranch();
        if (! $branch) return;

        $layout = [
            'Ground Floor' => [['T1', 4], ['T2', 4], ['T3', 4], ['T4', 6]],
            'Family Area'  => [['F1', 6], ['F2', 6], ['F3', 8]],
            'Outdoor'      => [['O1', 4], ['O2', 4]],
        ];
        $sort = 1;
        $tableCount = 0;
        foreach ($layout as $floorName => $tables) {
            $floor = RestaurantFloor::updateOrCreate(
                ['branch_id' => $branch->id, 'name' => $floorName],
                ['sort_order' => $sort++, 'status' => 'active']
            );
            foreach ($tables as [$no, $cap]) {
                RestaurantTable::updateOrCreate(
                    ['branch_id' => $branch->id, 'restaurant_floor_id' => $floor->id, 'table_no' => $no],
                    ['capacity' => $cap, 'status' => 'available', 'sort_order' => 0]
                );
                $tableCount++;
            }
        }
        $this->counts['floors'] = count($layout);
        $this->counts['tables'] = $tableCount;
    }

    protected function seedWaiters(): void
    {
        $branch = $this->mainBranch();
        if (! $branch) return;
        foreach ([
            ['W-001', 'Ahmed Khan'], ['W-002', 'Bilal Raza'],
            ['W-003', 'Fatima Shah'], ['W-004', 'Hassan Ali'],
        ] as [$code, $name]) {
            RestaurantWaiter::updateOrCreate(
                ['branch_id' => $branch->id, 'code' => $code],
                ['name' => $name, 'status' => 'active']
            );
        }
        $this->counts['waiters'] = RestaurantWaiter::count();
    }

    protected function seedStaff(): void
    {
        // Extra staff logins (restricted Demo role — never Owner publicly).
        $branch = $this->mainBranch();
        $role = Role::where('name', 'Demo')->where('guard_name', 'tenant')->first();
        $tenantCode = app('tenant')->tenant_code;

        foreach (['waiter' => 'Demo Waiter', 'cashier' => 'Demo Cashier', 'manager' => 'Demo Manager'] as $prefix => $name) {
            $user = User::updateOrCreate(
                ['email' => $prefix . '@' . $tenantCode . '.com'],
                [
                    'name'              => $name,
                    'password'          => Hash::make(config('saas.demos.default_password', 'demo1234')),
                    'status'            => 'active',
                    'locale'            => 'en',
                    'default_branch_id' => $branch?->id,
                ]
            );
            if ($branch) $user->branches()->syncWithoutDetaching([$branch->id]);
            if ($role) $user->syncRoles([$role]);
        }
        $this->counts['staff_users'] = 3;
    }

    protected function seedCategories(): void
    {
        $cats = [
            ['code' => 'RBURG', 'name' => 'Burgers',          'sort_order' => 1],
            ['code' => 'RPIZZA','name' => 'Pizza',            'sort_order' => 2],
            ['code' => 'RBBQ',  'name' => 'BBQ',              'sort_order' => 3],
            ['code' => 'RRICE', 'name' => 'Rice & Biryani',   'sort_order' => 4],
            ['code' => 'RDRINK','name' => 'Drinks',           'sort_order' => 5],
            ['code' => 'RDESS', 'name' => 'Desserts',         'sort_order' => 6],
            ['code' => 'RADDON','name' => 'Add-ons',          'sort_order' => 7],
        ];
        foreach ($cats as $c) {
            $cat = Category::updateOrCreate(
                ['code' => $c['code']],
                ['name' => $c['name'], 'slug' => Str::slug($c['name']), 'sort_order' => $c['sort_order'], 'is_active' => true]
            );
            $cat->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $c['name']]);
        }
        $this->counts['categories'] = count($cats);
    }

    /** Menu items: [sku, name, category code, price]. */
    protected function menu(): array
    {
        return [
            ['RST-BURGER-CLASSIC', 'Classic Beef Burger',   'RBURG',  450],
            ['RST-BURGER-CHEESE',  'Chicken Cheese Burger', 'RBURG',  420],
            ['RST-BURGER-ZINGER',  'Zinger Burger',         'RBURG',  480],
            ['RST-PIZZA-MARG',     'Margherita Pizza',      'RPIZZA', 750],
            ['RST-PIZZA-FAJITA',   'Chicken Fajita Pizza',  'RPIZZA', 950],
            ['RST-PIZZA-PEPP',     'Pepperoni Pizza',       'RPIZZA', 1050],
            ['RST-BBQ-TIKKA',      'Chicken Tikka',         'RBBQ',   550],
            ['RST-BBQ-SEEKH',      'Beef Seekh Kabab',      'RBBQ',   650],
            ['RST-BIRYANI-CHK',    'Chicken Biryani',       'RRICE',  400],
            ['RST-BIRYANI-MUT',    'Mutton Biryani',        'RRICE',  650],
            ['RST-KARAHI-HALF',    'Chicken Karahi Half',   'RRICE',  900],
            ['RST-KARAHI-FULL',    'Chicken Karahi Full',   'RRICE',  1700],
            ['RST-SANDWICH',       'Club Sandwich',         'RBURG',  380],
            ['RST-FRIES',          'French Fries',          'RADDON', 200],
            ['RST-LOADED-FRIES',   'Loaded Fries',          'RADDON', 350],
            ['RST-DIP-MAYO',       'Garlic Mayo Dip',       'RADDON', 60],
            ['RST-MARGARITA',      'Mint Margarita',        'RDRINK', 250],
            ['RST-LIME',           'Fresh Lime',            'RDRINK', 180],
            ['RST-SOFTDRINK',      'Soft Drink Can',        'RDRINK', 120],
            ['RST-WATER',          'Mineral Water',         'RDRINK', 60],
            ['RST-BROWNIE',        'Chocolate Brownie',     'RDESS',  300],
            ['RST-ICECREAM',       'Ice Cream Scoop',       'RDESS',  150],
            ['RST-TEA',            'Tea',                   'RDRINK', 80],
            ['RST-GREENTEA',       'Green Tea',             'RDRINK', 100],
            ['RST-CAPPUCCINO',     'Cappuccino',            'RDRINK', 280],
        ];
    }

    /** SKUs that should be recipe-type (overridden by Pro). */
    protected function recipeMenuSkus(): array
    {
        return [];
    }

    protected function seedMenu(): void
    {
        $recipeSkus = $this->recipeMenuSkus();
        $count = 0;
        foreach ($this->menu() as [$sku, $name, $catCode, $price]) {
            $category = Category::where('code', $catCode)->first();
            $unit = Unit::where('code', 'PCS')->first();
            $isRecipe = in_array($sku, $recipeSkus, true);

            $product = Product::updateOrCreate(
                ['sku' => $sku],
                [
                    'category_id'                  => $category?->id,
                    'unit_id'                      => $unit?->id,
                    'name'                         => $name,
                    'slug'                         => Str::slug($name),
                    'product_type'                 => $isRecipe ? 'recipe' : 'service',
                    'item_kind'                    => 'finished_good',
                    'inventory_consumption_method' => $isRecipe ? 'recipe' : 'none',
                    'product_kind'                 => 'sale_item',
                    'is_sellable'                  => true,
                    'is_pos_visible'               => true,
                    'can_be_bom_component'         => false,
                    'can_be_bom_output'            => false,
                    'is_manufactured_finished_good'=> false,
                    'is_purchasable'               => false,
                    'is_stock_tracked'             => false,
                    'has_expiry'                   => false,
                    'requires_batch'               => false,
                    'default_purchase_price'       => round($price * 0.4),
                    'default_selling_price'        => $price,
                    'is_taxable'                   => false,
                    'status'                       => 'active',
                ]
            );
            $product->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $name]);

            ProductVariant::updateOrCreate(
                ['sku' => $sku],
                [
                    'product_id'     => $product->id,
                    'name'           => $name,
                    'purchase_price' => round($price * 0.4),
                    'selling_price'  => $price,
                    'is_default'     => true,
                    'is_active'      => true,
                ]
            );
            $count++;
        }
        $this->counts['menu_products'] = $count;
    }

    protected function seedSalesControls(): void
    {
        $branch = $this->mainBranch();
        if (! $branch) return;

        Promotion::updateOrCreate(
            ['code' => 'DINEIN10'],
            [
                'branch_id'      => $branch->id,
                'name'           => '10% Dine-in Discount',
                'promotion_type' => 'order',
                'discount_type'  => 'percent',
                'discount_value' => 10,
                'order_types'    => ['dine_in'],
                'requires_code'  => true,
                'status'         => 'active',
                'priority'       => 100,
            ]
        );

        $burgerDeal = Promotion::updateOrCreate(
            ['code' => 'RSTBURGER15'],
            ['branch_id' => $branch->id, 'name' => '15% Burger Deal', 'promotion_type' => 'product', 'discount_type' => 'percent', 'discount_value' => 15, 'order_types' => ['dine_in', 'takeaway', 'quick_sale'], 'requires_code' => true, 'status' => 'active', 'priority' => 110]
        );
        $burgerDeal->targets()->delete();
        Product::whereIn('sku', ['RST-BURGER-CLASSIC', 'RST-BURGER-CHEESE', 'RST-BURGER-ZINGER'])
            ->pluck('id')
            ->each(fn ($productId) => $burgerDeal->targets()->create(['target_type' => 'product', 'target_id' => $productId]));

        foreach ([
            ['name' => 'Wrong Item', 'reason_type' => 'void'],
            ['name' => 'Customer Changed Mind', 'reason_type' => 'return'],
            ['name' => 'Kitchen Unavailable', 'reason_type' => 'cancel'],
        ] as $reason) {
            VoidReason::updateOrCreate(['name' => $reason['name']], array_merge($reason, ['is_active' => true]));
        }

        ServiceChargeSetting::updateOrCreate(
            ['branch_id' => $branch->id],
            ['charge_type' => 'percent', 'charge_value' => 5, 'order_types' => ['dine_in'], 'is_taxable' => false, 'is_active' => true]
        );
        $this->counts['sales_controls'] = '2 promotions + 3 void reasons + 5% service charge';
    }

    /** Seed POS-ready add-ons and structural combos for restaurant demo tenants. */
    protected function seedModifiersAndCombos(): void
    {
        $branch = $this->mainBranch();
        if (! $branch) return;

        $groups = [
            ['name' => 'Burger Add-ons', 'min_select' => 0, 'max_select' => 3, 'sort_order' => 10, 'options' => [['Extra Cheese', 100], ['Extra Patty', 250], ['Garlic Mayo', 60], ['No Onion', 0]], 'skus' => ['RST-BURGER-CLASSIC', 'RST-BURGER-CHEESE', 'RST-BURGER-ZINGER']],
            ['name' => 'Spice Level', 'min_select' => 1, 'max_select' => 1, 'sort_order' => 20, 'options' => [['Mild', 0], ['Medium', 0], ['Extra Spicy', 0]], 'skus' => ['RST-BURGER-ZINGER', 'RST-BIRYANI-CHK', 'RST-BIRYANI-MUT', 'RST-BBQ-TIKKA']],
        ];

        $optionCount = 0;
        foreach ($groups as $definition) {
            $group = ModifierGroup::updateOrCreate(
                ['branch_id' => $branch->id, 'name' => $definition['name']],
                ['min_select' => $definition['min_select'], 'max_select' => $definition['max_select'], 'is_required' => $definition['min_select'] > 0, 'sort_order' => $definition['sort_order'], 'status' => 'active']
            );
            foreach ($definition['options'] as $index => [$name, $priceDelta]) {
                Modifier::updateOrCreate(
                    ['modifier_group_id' => $group->id, 'name' => $name],
                    ['price_delta' => $priceDelta, 'is_default' => $index === 0, 'sort_order' => ($index + 1) * 10, 'status' => 'active']
                );
                $optionCount++;
            }
            Product::whereIn('sku', $definition['skus'])->get()->each(
                fn (Product $product) => $product->modifierGroups()->syncWithoutDetaching([$group->id => ['sort_order' => $definition['sort_order']]])
            );
        }

        $combos = [
            ['code' => 'RST-COMBO-BURGER', 'name' => 'Classic Burger Meal', 'price' => 690, 'description' => 'Classic burger, fries and a soft drink.', 'components' => [['RST-BURGER-CLASSIC', 1], ['RST-FRIES', 1], ['RST-SOFTDRINK', 1]]],
            ['code' => 'RST-COMBO-BIRYANI', 'name' => 'Biryani Lunch Deal', 'price' => 590, 'description' => 'Chicken biryani, mint margarita and brownie.', 'components' => [['RST-BIRYANI-CHK', 1], ['RST-MARGARITA', 1], ['RST-BROWNIE', 1]]],
        ];
        $componentCount = 0;
        foreach ($combos as $sortOrder => $definition) {
            $combo = Combo::updateOrCreate(
                ['branch_id' => $branch->id, 'code' => $definition['code']],
                ['name' => $definition['name'], 'price' => $definition['price'], 'description' => $definition['description'], 'sort_order' => ($sortOrder + 1) * 10, 'status' => 'active']
            );
            $combo->components()->delete();
            foreach ($definition['components'] as $componentSort => [$sku, $quantity]) {
                $product = Product::where('sku', $sku)->first();
                if (! $product) continue;
                $combo->components()->create(['product_id' => $product->id, 'quantity' => $quantity, 'sort_order' => ($componentSort + 1) * 10]);
                $componentCount++;
            }
        }

        $this->counts['pos_add_ons'] = count($groups) . " groups / {$optionCount} options";
        $this->counts['combo_deals'] = count($combos) . " combos / {$componentCount} components";
    }

    protected function seedSampleOrders(): void
    {
        if (SalesOrder::count() > 0) {
            $this->counts['sample_orders'] = 'already exist';
            return;
        }
        $branch = $this->mainBranch();
        $owner  = User::where('email', 'owner@' . app('tenant')->tenant_code . '.com')->first() ?? User::query()->orderBy('id')->first();
        $cash   = PaymentMethod::where('method_type', 'cash')->first();
        $card   = PaymentMethod::where('method_type', 'card')->first();
        if (! $branch || ! $owner || ! $cash) {
            $this->counts['sample_orders'] = 'skipped (missing branch/user/payment method)';
            return;
        }

        $orders = [
            ['type' => 'dine_in',  'pay' => $cash, 'days' => 0, 'lines' => [['RST-BURGER-CLASSIC', 2, 450], ['RST-FRIES', 2, 200], ['RST-SOFTDRINK', 2, 120]]],
            ['type' => 'dine_in',  'pay' => $card, 'days' => 0, 'lines' => [['RST-BIRYANI-CHK', 3, 400], ['RST-MARGARITA', 3, 250]]],
            ['type' => 'dine_in',  'pay' => $cash, 'days' => 1, 'lines' => [['RST-KARAHI-HALF', 1, 900], ['RST-BBQ-TIKKA', 2, 550], ['RST-TEA', 3, 80]]],
            ['type' => 'takeaway', 'pay' => $cash, 'days' => 1, 'lines' => [['RST-BURGER-ZINGER', 3, 480], ['RST-LOADED-FRIES', 2, 350]]],
            ['type' => 'dine_in',  'pay' => $card, 'days' => 2, 'lines' => [['RST-PIZZA-FAJITA', 2, 950], ['RST-PIZZA-MARG', 1, 750], ['RST-SOFTDRINK', 4, 120]]],
            ['type' => 'takeaway', 'pay' => $cash, 'days' => 3, 'lines' => [['RST-SANDWICH', 2, 380], ['RST-CAPPUCCINO', 2, 280]]],
            ['type' => 'dine_in',  'pay' => $cash, 'days' => 4, 'lines' => [['RST-BIRYANI-MUT', 2, 650], ['RST-BROWNIE', 2, 300], ['RST-LIME', 2, 180]]],
        ];

        $count = 0;
        foreach ($orders as $o) {
            try { $this->createPaidOrder($branch, $owner, $o); $count++; } catch (\Throwable $e) {}
        }
        $this->counts['sample_orders'] = "{$count} paid orders";
    }

    protected function createPaidOrder(Branch $branch, User $owner, array $o): void
    {
        $saleDate = now()->subDays($o['days']);
        $subtotal = 0;
        foreach ($o['lines'] as [$sku, $qty, $price]) $subtotal += $qty * $price;

        $sale = SalesOrder::create([
            'sale_no'            => 'SO-' . $saleDate->format('Ymd') . '-' . str_pad(SalesOrder::count() + 1, 4, '0', STR_PAD_LEFT),
            'branch_id'          => $branch->id,
            'order_source'       => 'manual',
            'order_type'         => $o['type'],
            'sale_date'          => $saleDate,
            'subtotal'           => $subtotal,
            'discount_type'      => 'none',
            'discount_value'     => 0,
            'discount_amount'    => 0,
            'tax_amount'         => 0,
            'grand_total'        => $subtotal,
            'status'             => 'draft',
            'created_by_user_id' => $owner->id,
        ]);
        foreach ($o['lines'] as [$sku, $qty, $price]) {
            $product = Product::where('sku', $sku)->first();
            if (! $product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $sale->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name'       => $product->name,
                'variant_name'       => $variant?->name,
                'quantity'           => $qty,
                'unit_price'         => $price,
                'unit_cost'          => 0,
                'cost_total'         => 0,
                'discount_amount'    => 0,
                'tax_amount'         => 0,
                'line_total'         => $qty * $price,
            ]);
        }
        $method = $o['pay'];
        $sale->payments()->create([
            'payment_method_id' => $method->id,
            'amount'            => $subtotal,
            'tendered_amount'   => $method->method_type === 'cash' ? $subtotal : null,
            'change_amount'     => 0,
            'transaction_ref'   => $method->method_type === 'cash' ? null : 'CARD-' . Str::upper(Str::random(4)),
        ]);
        $this->sales->finalizePaidSale($sale);
    }
}
