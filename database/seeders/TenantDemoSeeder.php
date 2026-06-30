<?php

namespace Database\Seeders;

use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\Tenant;
use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\Currency;
use App\Models\Tenant\Customer;
use App\Models\Tenant\DailyClosing;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Modifier;
use App\Models\Tenant\ModifierGroup;
use App\Models\Tenant\ManufacturingPostingSetting;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductBarcode;
use App\Models\Tenant\ProductBranchPrice;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\RestaurantFloor;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantWaiter;
use App\Models\Tenant\SalePayment;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockTransfer;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierPayment;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Models\Tenant\Printer;
use App\Models\Tenant\PrintAgent;
use App\Models\Tenant\ReceiptLayoutSetting;
use App\Models\Tenant\Recipe;
use App\Models\Tenant\RecipeIngredient;
use App\Models\Tenant\TerminalPrinterSetting;
use App\Models\Tenant\Promotion;
use App\Models\Tenant\ServiceChargeSetting;
use App\Models\Tenant\VoidReason;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SalesService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantDemoSeeder extends Seeder
{
    private InventoryService $inv;
    private SalesService $sales;

    public function run(): void
    {
        $tenant = Tenant::where('tenant_code', 'demo')->first();

        if (!$tenant) {
            $this->command->error('Demo tenant not found. Run: php artisan tenants:provision-demo');
            return;
        }

        app(TenancyManager::class)->activate($tenant);

        $this->inv   = app(InventoryService::class);
        $this->sales = app(SalesService::class);

        $this->command->info('Seeding demo tenant: ' . $tenant->business_name);

        $this->seedCurrencies();
        $this->seedUnits();
        $this->seedCategories();
        $this->seedBranches();
        $this->seedTerminals();
        $this->seedUsers();
        $this->seedRestaurantData();
        $this->seedProducts();
        $this->seedModifiers();
        $this->seedCombos();
        $this->seedCustomers();
        $this->seedPaymentMethods();
        $this->seedSuppliers();
        $this->seedOpeningStock();
        $this->seedPurchaseFlow();
        $this->seedShifts();
        $this->seedSalesOrders();
        $this->seedStockTransfer();
        $this->seedDailyClosing();
        $this->seedPrintersAndAgent();
        $this->seedReceiptLayouts();
        $this->seedRecipes();
        $this->seedManufacturingDemoProducts();
        $this->seedManufacturingPostingSettings();

        app(TenancyManager::class)->deactivate();

        // Master-side demo billing sample (after tenant + subscription exist).
        $this->seedDemoBilling($tenant);

        $this->command->info('Demo tenant seeding complete.');
    }

    // ── Currencies ──────────────────────────────────────────────────────────

    private function seedCurrencies(): void
    {
        $pkr = Currency::updateOrCreate(
            ['code' => 'PKR'],
            ['name' => 'Pakistani Rupee', 'symbol' => 'Rs', 'decimal_places' => 0, 'is_default' => true, 'is_active' => true]
        );

        $denominations = [
            ['denomination_value' => 5000, 'denomination_type' => 'note'],
            ['denomination_value' => 1000, 'denomination_type' => 'note'],
            ['denomination_value' => 500,  'denomination_type' => 'note'],
            ['denomination_value' => 100,  'denomination_type' => 'note'],
            ['denomination_value' => 50,   'denomination_type' => 'note'],
            ['denomination_value' => 20,   'denomination_type' => 'note'],
            ['denomination_value' => 10,   'denomination_type' => 'coin'],
            ['denomination_value' => 5,    'denomination_type' => 'coin'],
            ['denomination_value' => 2,    'denomination_type' => 'coin'],
            ['denomination_value' => 1,    'denomination_type' => 'coin'],
        ];

        foreach ($denominations as $d) {
            $pkr->denominations()->updateOrCreate(
                ['denomination_value' => $d['denomination_value']],
                array_merge($d, ['is_active' => true])
            );
        }

        $this->command->line('  Currencies & denominations seeded.');
    }

    // ── Units ────────────────────────────────────────────────────────────────

    private function seedUnits(): void
    {
        $units = [
            ['code' => 'PCS',  'name' => 'Piece',       'unit_type' => 'quantity', 'base_factor' => 1,     'is_base' => true],
            ['code' => 'BOX',  'name' => 'Box',         'unit_type' => 'quantity', 'base_factor' => 1,     'is_base' => false],
            ['code' => 'BTL',  'name' => 'Bottle',      'unit_type' => 'quantity', 'base_factor' => 1,     'is_base' => false],
            ['code' => 'PKT',  'name' => 'Packet',      'unit_type' => 'quantity', 'base_factor' => 1,     'is_base' => false],
            ['code' => 'ROLL', 'name' => 'Roll',        'unit_type' => 'quantity', 'base_factor' => 1,     'is_base' => false],
            ['code' => 'DOZ',  'name' => 'Dozen',       'unit_type' => 'quantity', 'base_factor' => 12,    'is_base' => false],
            ['code' => 'KG',   'name' => 'Kilogram',    'unit_type' => 'weight',   'base_factor' => 1,     'is_base' => true],
            ['code' => 'G',    'name' => 'Gram',        'unit_type' => 'weight',   'base_factor' => 0.001, 'is_base' => false],
            ['code' => 'L',    'name' => 'Litre',       'unit_type' => 'volume',   'base_factor' => 1,     'is_base' => true],
            ['code' => 'ML',   'name' => 'Millilitre',  'unit_type' => 'volume',   'base_factor' => 0.001, 'is_base' => false],
            ['code' => 'MTR',  'name' => 'Metre',       'unit_type' => 'length',   'base_factor' => 1,     'is_base' => true],
        ];

        foreach ($units as $u) {
            Unit::updateOrCreate(['code' => $u['code']], array_merge($u, ['is_active' => true]));
        }

        $this->command->line('  Units seeded: ' . count($units));
    }

    // ── Categories ───────────────────────────────────────────────────────────

    private function seedCategories(): void
    {
        $tree = [
            ['code' => 'BEV',   'name' => 'Beverages',   'sort_order' => 1, 'children' => [
                ['code' => 'HOT',   'name' => 'Hot Drinks',  'sort_order' => 1],
                ['code' => 'COLD',  'name' => 'Cold Drinks', 'sort_order' => 2],
                ['code' => 'JUICE', 'name' => 'Juices',      'sort_order' => 3],
            ]],
            ['code' => 'FOOD',  'name' => 'Food',         'sort_order' => 2, 'children' => [
                ['code' => 'DAIRY', 'name' => 'Dairy',       'sort_order' => 1],
                ['code' => 'BAKERY','name' => 'Bakery',      'sort_order' => 2],
                ['code' => 'SNACK', 'name' => 'Snacks',      'sort_order' => 3],
            ]],
            ['code' => 'RESTO', 'name' => 'Restaurant',   'sort_order' => 3, 'children' => [
                ['code' => 'STARTER',    'name' => 'Starters',    'sort_order' => 1],
                ['code' => 'MAINCOURSE', 'name' => 'Main Course', 'sort_order' => 2],
                ['code' => 'FASTFOOD',   'name' => 'Fast Food',   'sort_order' => 3],
                ['code' => 'DESSERT',    'name' => 'Desserts',    'sort_order' => 4],
            ]],
            ['code' => 'GROC',  'name' => 'Grocery',      'sort_order' => 4, 'children' => []],
            ['code' => 'ELEC',  'name' => 'Electronics',  'sort_order' => 5, 'children' => []],
            ['code' => 'CIGS',  'name' => 'Tobacco',      'sort_order' => 6, 'children' => []],
        ];

        $count = 0;

        foreach ($tree as $item) {
            $parent = Category::updateOrCreate(
                ['code' => $item['code']],
                ['name' => $item['name'], 'slug' => Str::slug($item['name']), 'sort_order' => $item['sort_order'], 'is_active' => true]
            );
            $parent->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $item['name']]);
            $count++;

            foreach ($item['children'] ?? [] as $child) {
                $cat = Category::updateOrCreate(
                    ['code' => $child['code']],
                    ['parent_id' => $parent->id, 'name' => $child['name'], 'slug' => Str::slug($child['name']), 'sort_order' => $child['sort_order'], 'is_active' => true]
                );
                $cat->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $child['name']]);
                $count++;
            }
        }

        $this->command->line("  Categories seeded: {$count}");
    }

    // ── Branches ─────────────────────────────────────────────────────────────

    private function seedBranches(): void
    {
        $branches = [
            ['code' => 'MAIN', 'name' => 'Main Branch',  'address' => 'Shop #1, Main Market, Gulberg, Lahore',  'phone' => '042-35761234', 'email' => 'main@demo.com'],
            ['code' => 'CITY', 'name' => 'City Branch',  'address' => 'Plot 14-A, DHA Phase 5, Lahore',         'phone' => '042-35879000', 'email' => 'city@demo.com'],
            ['code' => 'MALL', 'name' => 'Mall Outlet',  'address' => 'Stall 32, Emporium Mall, Lahore',         'phone' => '042-35890001', 'email' => 'mall@demo.com'],
        ];

        foreach ($branches as $b) {
            Branch::updateOrCreate(['code' => $b['code']], array_merge($b, ['status' => 'active']));
        }

        $this->command->line('  Branches seeded: ' . count($branches));
    }

    // ── Terminals ────────────────────────────────────────────────────────────

    private function seedTerminals(): void
    {
        $main = Branch::where('code', 'MAIN')->first();
        $city = Branch::where('code', 'CITY')->first();
        $mall = Branch::where('code', 'MALL')->first();

        $terminals = [
            ['branch_id' => $main->id, 'code' => 'POS-MAIN-01', 'name' => 'Main Counter 1',   'requires_shift' => true],
            ['branch_id' => $main->id, 'code' => 'POS-MAIN-02', 'name' => 'Main Counter 2',   'requires_shift' => false],
            ['branch_id' => $city->id, 'code' => 'POS-CITY-01', 'name' => 'City Counter',     'requires_shift' => true],
            ['branch_id' => $mall->id, 'code' => 'POS-MALL-01', 'name' => 'Mall Kiosk',       'requires_shift' => false],
        ];

        foreach ($terminals as $t) {
            Terminal::updateOrCreate(['code' => $t['code']], array_merge($t, ['status' => 'active']));
        }

        $this->command->line('  Terminals seeded: ' . count($terminals));
    }

    // ── Users ────────────────────────────────────────────────────────────────

    private function seedUsers(): void
    {
        $main = Branch::where('code', 'MAIN')->first();
        $city = Branch::where('code', 'CITY')->first();
        $t1   = Terminal::where('code', 'POS-MAIN-01')->first();
        $t2   = Terminal::where('code', 'POS-CITY-01')->first();

        $ownerRole   = \Spatie\Permission\Models\Role::where('name', 'Owner')->first();
        $managerRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'tenant']);
        $cashierRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'tenant']);

        // Give manager and cashier all owner permissions as a demo baseline
        if ($ownerRole) {
            $managerRole->syncPermissions($ownerRole->permissions);
            $cashierRole->syncPermissions($ownerRole->permissions);
        }

        $newUsers = [
            [
                'email'         => 'manager@demo.com',
                'name'          => 'Demo Manager',
                'employee_code' => 'EMP-002',
                'phone'         => '0321-1234567',
                'status'        => 'active',
                'role'          => $managerRole,
                'branch'        => $main,
                'terminal'      => $t1,
            ],
            [
                'email'         => 'cashier@demo.com',
                'name'          => 'Demo Cashier',
                'employee_code' => 'EMP-003',
                'phone'         => '0322-9876543',
                'status'        => 'active',
                'role'          => $cashierRole,
                'branch'        => $city,
                'terminal'      => $t2,
            ],
        ];

        foreach ($newUsers as $ud) {
            $user = User::updateOrCreate(
                ['email' => $ud['email']],
                [
                    'name'                  => $ud['name'],
                    'password'              => Hash::make('password'),
                    'employee_code'         => $ud['employee_code'],
                    'phone'                 => $ud['phone'],
                    'default_branch_id'     => $ud['branch']?->id,
                    'default_terminal_id'   => $ud['terminal']?->id,
                    'status'                => $ud['status'],
                    'force_password_change' => false,
                ]
            );

            if ($ud['branch']) {
                $user->branches()->syncWithoutDetaching([
                    $ud['branch']->id => ['is_default' => true, 'is_active' => true],
                ]);
            }

            if ($ud['terminal']) {
                $user->terminals()->syncWithoutDetaching([
                    $ud['terminal']->id => ['is_default' => true],
                ]);
            }

            $user->syncRoles([$ud['role']]);
        }

        // Also ensure owner has access to all branches
        $owner = User::where('email', 'owner@demo.com')->first();
        if ($owner) {
            foreach (Branch::all() as $branch) {
                $owner->branches()->syncWithoutDetaching([
                    $branch->id => ['is_default' => $branch->code === 'MAIN', 'is_active' => true],
                ]);
            }
        }

        $this->command->line('  Users seeded: manager@demo.com, cashier@demo.com (password: password)');
    }

    // ── Restaurant Floors / Tables / Waiters ─────────────────────────────────

    private function seedRestaurantData(): void
    {
        $main = Branch::where('code', 'MAIN')->first();
        $city = Branch::where('code', 'CITY')->first();

        if (!$main) return;

        // ── Ground Floor (Main) — T1–T10
        $groundFloor = RestaurantFloor::updateOrCreate(
            ['branch_id' => $main->id, 'name' => 'Ground Floor'],
            ['sort_order' => 1, 'status' => 'active']
        );

        foreach ([
            ['T1', 4], ['T2', 4], ['T3', 4], ['T4', 4], ['T5', 4],
            ['T6', 4], ['T7', 6], ['T8', 6], ['T9', 2], ['T10', 2],
        ] as [$no, $cap]) {
            RestaurantTable::updateOrCreate(
                ['branch_id' => $main->id, 'restaurant_floor_id' => $groundFloor->id, 'table_no' => $no],
                ['capacity' => $cap, 'status' => 'available', 'sort_order' => 0]
            );
        }

        // ── First Floor (Main) — T11–T18
        $firstFloor = RestaurantFloor::updateOrCreate(
            ['branch_id' => $main->id, 'name' => 'First Floor'],
            ['sort_order' => 2, 'status' => 'active']
        );

        foreach ([
            ['T11', 4], ['T12', 4], ['T13', 6], ['T14', 6],
            ['T15', 8], ['T16', 8], ['T17', 4], ['T18', 2],
        ] as [$no, $cap]) {
            RestaurantTable::updateOrCreate(
                ['branch_id' => $main->id, 'restaurant_floor_id' => $firstFloor->id, 'table_no' => $no],
                ['capacity' => $cap, 'status' => 'available', 'sort_order' => 0]
            );
        }

        // ── VIP Lounge (Main) — VIP1–VIP4
        $vipFloor = RestaurantFloor::updateOrCreate(
            ['branch_id' => $main->id, 'name' => 'VIP Lounge'],
            ['sort_order' => 3, 'status' => 'active']
        );

        foreach (['VIP1', 'VIP2', 'VIP3', 'VIP4'] as $no) {
            RestaurantTable::updateOrCreate(
                ['branch_id' => $main->id, 'restaurant_floor_id' => $vipFloor->id, 'table_no' => $no],
                ['capacity' => 8, 'status' => 'available', 'sort_order' => 0]
            );
        }

        // ── City Branch — Dining Area — CT1–CT6
        if ($city) {
            $cityFloor = RestaurantFloor::updateOrCreate(
                ['branch_id' => $city->id, 'name' => 'Dining Area'],
                ['sort_order' => 1, 'status' => 'active']
            );

            foreach ([
                ['CT1', 4], ['CT2', 4], ['CT3', 4],
                ['CT4', 6], ['CT5', 6], ['CT6', 2],
            ] as [$no, $cap]) {
                RestaurantTable::updateOrCreate(
                    ['branch_id' => $city->id, 'restaurant_floor_id' => $cityFloor->id, 'table_no' => $no],
                    ['capacity' => $cap, 'status' => 'available', 'sort_order' => 0]
                );
            }
        }

        // ── Waiters — Main branch (8 waiters)
        foreach ([
            ['W-001', 'Ahmed Khan'],
            ['W-002', 'Bilal Raza'],
            ['W-003', 'Fatima Shah'],
            ['W-004', 'Hassan Ali'],
            ['W-005', 'Sana Iqbal'],
            ['W-006', 'Tariq Mehmood'],
            ['W-007', 'Mariam Butt'],
            ['W-008', 'Usman Baig'],
        ] as [$code, $name]) {
            RestaurantWaiter::updateOrCreate(
                ['branch_id' => $main->id, 'code' => $code],
                ['name' => $name, 'status' => 'active']
            );
        }

        // ── Waiters — City branch
        if ($city) {
            foreach ([
                ['CW-001', 'Nabeel Chaudhry'],
                ['CW-002', 'Amna Siddiqui'],
                ['CW-003', 'Zubair Awan'],
            ] as [$code, $name]) {
                RestaurantWaiter::updateOrCreate(
                    ['branch_id' => $city->id, 'code' => $code],
                    ['name' => $name, 'status' => 'active']
                );
            }
        }

        $this->command->line(sprintf(
            '  Restaurant data seeded: %d floors, %d tables, %d waiters.',
            RestaurantFloor::count(),
            RestaurantTable::count(),
            RestaurantWaiter::count()
        ));
    }

    // ── Products ─────────────────────────────────────────────────────────────

    private function seedProducts(): void
    {
        $pcs  = Unit::where('code', 'PCS')->first();
        $btl  = Unit::where('code', 'BTL')->first();
        $pkt  = Unit::where('code', 'PKT')->first();
        $kg   = Unit::where('code', 'KG')->first();
        $litre = Unit::where('code', 'L')->first();
        $mtr  = Unit::where('code', 'MTR')->first();

        $cold       = Category::where('code', 'COLD')->first();
        $hot        = Category::where('code', 'HOT')->first();
        $juice      = Category::where('code', 'JUICE')->first();
        $dairy      = Category::where('code', 'DAIRY')->first();
        $bakery     = Category::where('code', 'BAKERY')->first();
        $snack      = Category::where('code', 'SNACK')->first();
        $groc       = Category::where('code', 'GROC')->first();
        $cigs       = Category::where('code', 'CIGS')->first();
        $starter    = Category::where('code', 'STARTER')->first();
        $mainCourse = Category::where('code', 'MAINCOURSE')->first();
        $fastFood   = Category::where('code', 'FASTFOOD')->first();
        $dessert    = Category::where('code', 'DESSERT')->first();

        $products = [
            // ── Beverages ────────────────────────────────────────────────
            ['sku' => 'COLA-500',   'name' => 'Coca Cola 500ml',       'category' => $cold,       'unit' => $pcs, 'buy' => 40,   'sell' => 60,    'barcode' => '5449000214911'],
            ['sku' => 'PEPSI-500',  'name' => 'Pepsi 500ml',           'category' => $cold,       'unit' => $pcs, 'buy' => 38,   'sell' => 58,    'barcode' => '5000112637922'],
            ['sku' => 'SPRITE-500', 'name' => 'Sprite 500ml',          'category' => $cold,       'unit' => $pcs, 'buy' => 40,   'sell' => 60,    'barcode' => '5449000133328'],
            ['sku' => 'WATER-1L',   'name' => 'Mineral Water 1L',      'category' => $cold,       'unit' => $btl, 'buy' => 20,   'sell' => 30,    'barcode' => '8901030877742'],
            ['sku' => 'JUICE-MNG',  'name' => 'Mango Juice 250ml',     'category' => $cold,       'unit' => $pcs, 'buy' => 25,   'sell' => 40,    'barcode' => '8901030123456'],
            ['sku' => 'JUICE-ONG',  'name' => 'Orange Juice 250ml',    'category' => $juice,      'unit' => $pcs, 'buy' => 25,   'sell' => 40,    'barcode' => '8901030123457'],
            ['sku' => 'JUICE-PINE', 'name' => 'Pineapple Juice 250ml', 'category' => $juice,      'unit' => $pcs, 'buy' => 25,   'sell' => 40,    'barcode' => '8901030123458'],
            ['sku' => 'TEA-100',    'name' => 'Tea Bags 100pcs',       'category' => $hot,        'unit' => $pkt, 'buy' => 180,  'sell' => 250,   'barcode' => '8901719110050'],
            ['sku' => 'COFFEE-200', 'name' => 'Instant Coffee 200g',   'category' => $hot,        'unit' => $pkt, 'buy' => 350,  'sell' => 480,   'barcode' => '8714100809358'],
            ['sku' => 'CHAI-CUP',   'name' => 'Doodh Patti Chai',      'category' => $hot,        'unit' => $pcs, 'buy' => 20,   'sell' => 50,    'is_stock_tracked' => false],
            ['sku' => 'KARAK-CUP',  'name' => 'Karak Chai',            'category' => $hot,        'unit' => $pcs, 'buy' => 25,   'sell' => 60,    'is_stock_tracked' => false],
            // ── Dairy ────────────────────────────────────────────────────
            ['sku' => 'MILK-1L',    'name' => 'Fresh Milk 1L',         'category' => $dairy,      'unit' => $btl, 'buy' => 120,  'sell' => 150,   'barcode' => '8901719030009', 'has_expiry' => true],
            ['sku' => 'BUTTER-200', 'name' => 'Butter 200g',           'category' => $dairy,      'unit' => $pkt, 'buy' => 160,  'sell' => 210,   'barcode' => '7622300454098', 'has_expiry' => true],
            ['sku' => 'YOGURT-500', 'name' => 'Yogurt 500g',           'category' => $dairy,      'unit' => $pkt, 'buy' => 90,   'sell' => 130,   'barcode' => '8964000255041', 'has_expiry' => true],
            ['sku' => 'LASSI-LRG',  'name' => 'Sweet Lassi (Large)',   'category' => $dairy,      'unit' => $pcs, 'buy' => 60,   'sell' => 120,   'is_stock_tracked' => false],
            // ── Bakery ───────────────────────────────────────────────────
            ['sku' => 'BREAD-LRG',  'name' => 'Bread Loaf Large',      'category' => $bakery,     'unit' => $pcs, 'buy' => 80,   'sell' => 120,   'barcode' => '8901030654321', 'has_expiry' => true],
            ['sku' => 'NAAN-1',     'name' => 'Plain Naan',            'category' => $bakery,     'unit' => $pcs, 'buy' => 15,   'sell' => 30,    'is_stock_tracked' => false],
            ['sku' => 'ROTI-1',     'name' => 'Tandoori Roti',         'category' => $bakery,     'unit' => $pcs, 'buy' => 10,   'sell' => 20,    'is_stock_tracked' => false],
            // ── Snacks ───────────────────────────────────────────────────
            ['sku' => 'BISCUIT-P',  'name' => 'Biscuits Plain 200g',   'category' => $snack,      'unit' => $pkt, 'buy' => 55,   'sell' => 80,    'barcode' => '5901234123457'],
            ['sku' => 'CHIPS-LAY',  'name' => 'Lays Chips 100g',       'category' => $snack,      'unit' => $pkt, 'buy' => 65,   'sell' => 95,    'barcode' => '5000112100027'],
            ['sku' => 'SAMOSA-2',   'name' => 'Samosa (2 pcs)',        'category' => $starter,    'unit' => $pcs, 'buy' => 30,   'sell' => 70,    'is_stock_tracked' => false],
            // ── Grocery ──────────────────────────────────────────────────
            ['sku' => 'RICE-5KG',   'name' => 'Basmati Rice 5kg',      'category' => $groc,       'unit' => $pkt, 'buy' => 850,  'sell' => 1100,  'barcode' => '8964000300001'],
            ['sku' => 'SUGAR-1KG',  'name' => 'Sugar 1kg',             'category' => $groc,       'unit' => $pkt, 'buy' => 120,  'sell' => 160,   'barcode' => '8964000300002'],
            ['sku' => 'OIL-1L',     'name' => 'Cooking Oil 1L',        'category' => $groc,       'unit' => $btl, 'buy' => 380,  'sell' => 480,   'barcode' => '8964000300003'],
            ['sku' => 'SALT-1KG',   'name' => 'Iodized Salt 1kg',      'category' => $groc,       'unit' => $pkt, 'buy' => 60,   'sell' => 85,    'barcode' => '8964000300004'],
            ['sku' => 'CIG-GOLD',   'name' => 'Gold Leaf Cigarettes',  'category' => $cigs,       'unit' => $pcs, 'buy' => 270,  'sell' => 320,   'barcode' => '5000174030070'],
            // ── Starters ─────────────────────────────────────────────────
            ['sku' => 'FRIES-REG',  'name' => 'French Fries Regular',  'category' => $starter,    'unit' => $pcs, 'buy' => 80,   'sell' => 180,   'is_stock_tracked' => false],
            ['sku' => 'SOUP-VEG',   'name' => 'Vegetable Soup',        'category' => $starter,    'unit' => $pcs, 'buy' => 60,   'sell' => 150,   'is_stock_tracked' => false],
            ['sku' => 'SPRING-R',   'name' => 'Spring Roll (4 pcs)',   'category' => $starter,    'unit' => $pcs, 'buy' => 70,   'sell' => 160,   'is_stock_tracked' => false],
            // ── Main Course ──────────────────────────────────────────────
            ['sku' => 'BIRYANI-C',  'name' => 'Chicken Biryani (Plate)','category' => $mainCourse,'unit' => $pcs, 'buy' => 200,  'sell' => 400,   'is_stock_tracked' => false],
            ['sku' => 'BIRYANI-M',  'name' => 'Mutton Biryani (Plate)', 'category' => $mainCourse,'unit' => $pcs, 'buy' => 350,  'sell' => 650,   'is_stock_tracked' => false],
            ['sku' => 'KARAHI-C',   'name' => 'Chicken Karahi (Half)', 'category' => $mainCourse, 'unit' => $pcs, 'buy' => 500,  'sell' => 900,   'is_stock_tracked' => false],
            ['sku' => 'KARAHI-M',   'name' => 'Mutton Karahi (Half)',  'category' => $mainCourse, 'unit' => $pcs, 'buy' => 900,  'sell' => 1600,  'is_stock_tracked' => false],
            ['sku' => 'DAAL-MIX',   'name' => 'Mix Daal',             'category' => $mainCourse,  'unit' => $pcs, 'buy' => 80,   'sell' => 200,   'is_stock_tracked' => false],
            ['sku' => 'RICE-STM',   'name' => 'Steamed Rice (Bowl)',   'category' => $mainCourse, 'unit' => $pcs, 'buy' => 50,   'sell' => 120,   'is_stock_tracked' => false],
            // ── Fast Food ────────────────────────────────────────────────
            ['sku' => 'BURGER-C',   'name' => 'Chicken Burger',        'category' => $fastFood,   'unit' => $pcs, 'buy' => 120,  'sell' => 280,   'is_stock_tracked' => false],
            ['sku' => 'BURGER-B',   'name' => 'Beef Burger',           'category' => $fastFood,   'unit' => $pcs, 'buy' => 150,  'sell' => 350,   'is_stock_tracked' => false],
            ['sku' => 'PIZZA-M',    'name' => 'Pizza Margherita (M)',  'category' => $fastFood,   'unit' => $pcs, 'buy' => 350,  'sell' => 750,   'is_stock_tracked' => false],
            ['sku' => 'PIZZA-C',    'name' => 'Pizza Chicken (M)',     'category' => $fastFood,   'unit' => $pcs, 'buy' => 400,  'sell' => 850,   'is_stock_tracked' => false],
            ['sku' => 'SHAWARMA',   'name' => 'Chicken Shawarma',      'category' => $fastFood,   'unit' => $pcs, 'buy' => 100,  'sell' => 220,   'is_stock_tracked' => false],
            // ── Desserts ─────────────────────────────────────────────────
            ['sku' => 'GULAB-J',    'name' => 'Gulab Jamun (2 pcs)',   'category' => $dessert,    'unit' => $pcs,   'buy' => 40,   'sell' => 100,   'is_stock_tracked' => false],
            ['sku' => 'ICECREAM',   'name' => 'Ice Cream (Scoop)',     'category' => $dessert,    'unit' => $pcs,   'buy' => 50,   'sell' => 120,   'is_stock_tracked' => false],
            ['sku' => 'KHEER',      'name' => 'Kheer (Bowl)',          'category' => $dessert,    'unit' => $pcs,   'buy' => 60,   'sell' => 140,   'is_stock_tracked' => false],
            // ── Measurable / Weighted (mart demo) ────────────────────────
            ['sku' => 'TOMATO-KG',  'name' => 'Tomatoes',              'category' => $groc,       'unit' => $kg,    'buy' => 60,   'sell' => 120,   'stock' => 20.000],
            ['sku' => 'POTATO-KG',  'name' => 'Potatoes',              'category' => $groc,       'unit' => $kg,    'buy' => 40,   'sell' => 80,    'stock' => 30.000],
            ['sku' => 'ONION-KG',   'name' => 'Onions',                'category' => $groc,       'unit' => $kg,    'buy' => 50,   'sell' => 100,   'stock' => 25.000],
            ['sku' => 'CHICKEN-KG', 'name' => 'Fresh Chicken',         'category' => $groc,       'unit' => $kg,    'buy' => 550,  'sell' => 700,   'stock' => 15.000, 'has_expiry' => true],
            ['sku' => 'BEEF-KG',    'name' => 'Beef Mince',            'category' => $groc,       'unit' => $kg,    'buy' => 900,  'sell' => 1200,  'stock' => 10.000, 'has_expiry' => true],
            ['sku' => 'RICE-LOOSE', 'name' => 'Loose Basmati Rice',    'category' => $groc,       'unit' => $kg,    'buy' => 180,  'sell' => 280,   'stock' => 50.000],
            ['sku' => 'SUGAR-LOSE', 'name' => 'Loose Sugar',           'category' => $groc,       'unit' => $kg,    'buy' => 100,  'sell' => 150,   'stock' => 40.000],
            ['sku' => 'FLOUR-KG',   'name' => 'Wheat Flour',           'category' => $groc,       'unit' => $kg,    'buy' => 80,   'sell' => 130,   'stock' => 60.000],
            ['sku' => 'OIL-LOOSE',  'name' => 'Loose Cooking Oil',     'category' => $groc,       'unit' => $litre, 'buy' => 340,  'sell' => 420,   'stock' => 20.000],
            ['sku' => 'FABRIC-MTR', 'name' => 'Cotton Fabric (White)', 'category' => $groc,       'unit' => $mtr,   'buy' => 250,  'sell' => 450,   'stock' => 50.000],
        ];

        $count = 0;

        foreach ($products as $pd) {
            $slug = Str::slug($pd['name']);

            $isTracked = $pd['is_stock_tracked'] ?? true;

            // Recipe products: finished_good consumed via recipe; ingredient products: stock_item
            $recipeSkus       = ['KARAHI-C', 'KARAHI-M', 'BIRYANI-C', 'BIRYANI-M', 'BURGER-C', 'BURGER-B'];
            $ingredientSkus   = ['CHICKEN-KG', 'BEEF-KG', 'TOMATO-KG', 'POTATO-KG', 'ONION-KG', 'RICE-LOOSE', 'SUGAR-LOSE', 'FLOUR-KG', 'OIL-LOOSE'];
            $consumptionMethod = in_array($pd['sku'], $recipeSkus)     ? 'recipe'
                               : (in_array($pd['sku'], $ingredientSkus) ? 'stock_item' : ($isTracked ? 'stock_item' : 'none'));
            $itemKind          = in_array($pd['sku'], $recipeSkus)     ? 'finished_good'
                               : (in_array($pd['sku'], $ingredientSkus) ? 'ingredient'  : 'finished_good');
            $productKind       = 'sale_item';

            $product = Product::updateOrCreate(
                ['sku' => $pd['sku']],
                [
                    'category_id'                    => $pd['category']?->id,
                    'unit_id'                        => $pd['unit']?->id,
                    'name'                           => $pd['name'],
                    'slug'                           => $slug,
                    'product_type'                   => in_array($pd['sku'], $recipeSkus) ? 'recipe' : ($isTracked ? 'simple' : 'service'),
                    'item_kind'                      => $itemKind,
                    'inventory_consumption_method'   => $consumptionMethod,
                    'product_kind'                   => $productKind,
                    'is_pos_visible'                 => true,
                    'can_be_bom_component'           => false,
                    'can_be_bom_output'              => false,
                    'is_manufactured_finished_good'  => false,
                    'is_sellable'                    => true,
                    'is_purchasable'                 => $isTracked && !in_array($pd['sku'], $recipeSkus),
                    'is_stock_tracked'               => $isTracked,
                    'has_expiry'             => $pd['has_expiry'] ?? false,
                    'requires_batch'         => false,
                    'default_purchase_price' => $pd['buy'],
                    'default_selling_price'  => $pd['sell'],
                    // KITCHEN-RECIPE-COST-1: recipe-costing pack fields. Default purchase unit =
                    // stock unit, pack size = 1 (these demo recipes use stock-unit quantities,
                    // e.g. 0.5 KG, so pack size 1 keeps the cost report accurate, not inflated).
                    'purchase_unit_id'       => $pd['unit']?->id,
                    'purchase_pack_size'     => 1,
                    'status'                 => 'active',
                ]
            );

            $product->translations()->updateOrCreate(
                ['language_code' => 'en'],
                ['name' => $product->name]
            );

            $variant = ProductVariant::updateOrCreate(
                ['sku' => $pd['sku']],
                [
                    'product_id'      => $product->id,
                    'name'            => $product->name,
                    'barcode'         => $pd['barcode'] ?? null,
                    'purchase_price'  => $pd['buy'],
                    'selling_price'   => $pd['sell'],
                    'reorder_level'   => 5,
                    'reorder_quantity'=> 20,
                    'is_default'      => true,
                    'is_active'       => true,
                ]
            );

            if ($pd['barcode'] ?? null) {
                ProductBarcode::updateOrCreate(
                    ['barcode' => $pd['barcode']],
                    [
                        'product_id'         => $product->id,
                        'product_variant_id' => $variant->id,
                        'barcode_type'       => 'manual',
                        'is_primary'         => true,
                    ]
                );
            }

            $count++;
        }

        // Branch prices: City branch sells slightly higher, Mall branch even higher
        $city = Branch::where('code', 'CITY')->first();
        $mall = Branch::where('code', 'MALL')->first();

        $markups = [
            'CITY' => ['branch' => $city, 'pct' => 1.05],
            'MALL' => ['branch' => $mall, 'pct' => 1.12],
        ];

        foreach ($markups as $info) {
            if (!$info['branch']) continue;
            foreach ($products as $pd) {
                $product = Product::where('sku', $pd['sku'])->first();
                if (!$product) continue;
                ProductBranchPrice::updateOrCreate(
                    ['branch_id' => $info['branch']->id, 'product_id' => $product->id, 'product_variant_id' => null],
                    ['selling_price' => round($pd['sell'] * $info['pct']), 'minimum_selling_price' => round($pd['sell'] * 0.95), 'is_available' => true]
                );
            }
        }

        $this->command->line("  Products seeded: {$count} (+ branch prices for City & Mall)");
    }

    // ── Customers ────────────────────────────────────────────────────────────

    private function seedModifiers(): void
    {
        // MODIFIER-INVENTORY-1: stock-tracked linked products for consume_stock options.
        $this->seedModifierStockProducts();

        $groups = [
            [
                'name' => 'Spice Level',
                'min_select' => 1,
                'max_select' => 1,
                'is_required' => true,
                'sort_order' => 10,
                'options' => [
                    ['name' => 'Mild', 'price_delta' => 0, 'is_default' => true, 'sort_order' => 10],
                    ['name' => 'Medium', 'price_delta' => 0, 'is_default' => false, 'sort_order' => 20],
                    ['name' => 'Extra Spicy', 'price_delta' => 0, 'is_default' => false, 'sort_order' => 30],
                ],
                'products' => ['BIRYANI-C', 'BIRYANI-M', 'KARAHI-C', 'KARAHI-M', 'BURGER-C', 'BURGER-B', 'SHAWARMA'],
            ],
            [
                'name' => 'Burger Add-ons',
                'min_select' => 0,
                'max_select' => 4,
                'is_required' => false,
                'sort_order' => 20,
                'options' => [
                    ['name' => 'Extra Cheese', 'price_delta' => 100, 'is_default' => false, 'sort_order' => 10, 'consume_stock' => true, 'linked_sku' => 'MOD-CHEESE', 'linked_quantity' => 1, 'linked_unit' => 'PCS'],
                    ['name' => 'Extra Patty', 'price_delta' => 250, 'is_default' => false, 'sort_order' => 20, 'consume_stock' => true, 'linked_sku' => 'MOD-PATTY', 'linked_quantity' => 1, 'linked_unit' => 'PCS'],
                    ['name' => 'Extra Sauce', 'price_delta' => 50, 'is_default' => false, 'sort_order' => 30, 'consume_stock' => true, 'linked_sku' => 'MOD-SAUCE', 'linked_quantity' => 1, 'linked_unit' => 'PCS'],
                    ['name' => 'No Onion', 'price_delta' => 0, 'is_default' => false, 'sort_order' => 40],
                ],
                'products' => ['BURGER-C', 'BURGER-B'],
            ],
            [
                'name' => 'Meal Sides',
                'min_select' => 0,
                'max_select' => 3,
                'is_required' => false,
                'sort_order' => 30,
                'options' => [
                    ['name' => 'Extra Raita', 'price_delta' => 80, 'is_default' => false, 'sort_order' => 10],
                    ['name' => 'Extra Naan', 'price_delta' => 40, 'is_default' => false, 'sort_order' => 20],
                    ['name' => 'Mint Chutney', 'price_delta' => 30, 'is_default' => false, 'sort_order' => 30],
                ],
                'products' => ['BIRYANI-C', 'BIRYANI-M', 'KARAHI-C', 'KARAHI-M'],
            ],
        ];

        $groupCount = 0;
        $optionCount = 0;

        foreach ($groups as $definition) {
            $group = ModifierGroup::updateOrCreate(
                ['name' => $definition['name'], 'branch_id' => null],
                [
                    'min_select' => $definition['min_select'],
                    'max_select' => $definition['max_select'],
                    'is_required' => $definition['is_required'],
                    'sort_order' => $definition['sort_order'],
                    'status' => 'active',
                ]
            );

            $groupCount++;

            foreach ($definition['options'] as $option) {
                $consumeStock  = !empty($option['consume_stock']);
                $linkedProduct = !empty($option['linked_sku']) ? Product::where('sku', $option['linked_sku'])->first() : null;
                $linkedUnit    = !empty($option['linked_unit']) ? Unit::where('code', $option['linked_unit'])->first() : null;

                Modifier::updateOrCreate(
                    ['modifier_group_id' => $group->id, 'name' => $option['name']],
                    [
                        'price_delta'       => $option['price_delta'],
                        'linked_product_id' => $consumeStock ? $linkedProduct?->id : null,
                        'consume_stock'     => $consumeStock && $linkedProduct,
                        'linked_quantity'   => $consumeStock && $linkedProduct ? ($option['linked_quantity'] ?? 1) : null,
                        'linked_unit_id'    => $consumeStock && $linkedProduct ? $linkedUnit?->id : null,
                        'is_default'        => $option['is_default'],
                        'sort_order'        => $option['sort_order'],
                        'status'            => 'active',
                    ]
                );
                $optionCount++;
            }

            foreach ($definition['products'] as $sku) {
                $product = Product::where('sku', $sku)->first();
                if (!$product) continue;

                $product->modifierGroups()->syncWithoutDetaching([
                    $group->id => ['sort_order' => $definition['sort_order']],
                ]);
            }
        }

        $this->command->line("  Modifiers seeded: {$groupCount} groups, {$optionCount} options.");
    }

    /**
     * MODIFIER-INVENTORY-1 — stock-tracked products that consume_stock modifiers deduct
     * (Extra Cheese → Cheese Slice, Extra Patty → Beef Patty, Extra Sauce → Burger Sauce).
     * Hidden from POS, purchasable, with opening stock at MAIN. Idempotent + guarded.
     */
    private function seedModifierStockProducts(): void
    {
        $pcs  = Unit::where('code', 'PCS')->first();
        $groc = Category::where('code', 'GROC')->first();

        // [sku, name, cost, openingQty]
        $items = [
            ['MOD-CHEESE', 'Cheese Slice (Modifier)', 35.00, 200],
            ['MOD-PATTY',  'Beef Patty (Modifier)',   90.00, 100],
            ['MOD-SAUCE',  'Burger Sauce (Modifier)', 15.00, 300],
        ];

        foreach ($items as [$sku, $name, $cost, $qty]) {
            $product = Product::updateOrCreate(
                ['sku' => $sku],
                [
                    'category_id'                  => $groc?->id,
                    'unit_id'                      => $pcs?->id,
                    'name'                         => $name,
                    'slug'                         => Str::slug($name),
                    'product_type'                 => 'simple',
                    'item_kind'                    => 'ingredient',
                    'inventory_consumption_method' => 'stock_item',
                    'product_kind'                 => 'raw_material',
                    'is_sellable'                  => false,
                    'is_pos_visible'               => false,
                    'can_be_bom_component'         => false,
                    'can_be_bom_output'            => false,
                    'is_manufactured_finished_good'=> false,
                    'is_purchasable'               => true,
                    'is_stock_tracked'             => true,
                    'has_expiry'                   => false,
                    'requires_batch'               => false,
                    'default_purchase_price'       => $cost,
                    'default_selling_price'        => 0,
                    'purchase_unit_id'             => $pcs?->id,
                    'purchase_pack_size'           => 1,
                    'status'                       => 'active',
                ]
            );
            $product->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $name]);
            ProductVariant::updateOrCreate(
                ['sku' => $sku],
                ['product_id' => $product->id, 'name' => $name, 'purchase_price' => $cost, 'selling_price' => 0,
                 'reorder_level' => 10, 'reorder_quantity' => 50, 'is_default' => true, 'is_active' => true]
            );
        }

        $mainBranch = Branch::where('code', 'MAIN')->first();
        if ($mainBranch && ! StockAdjustment::where('adjustment_no', 'ADJ-MOD-OPEN-MAIN')->exists()) {
            $adjustment = StockAdjustment::create([
                'adjustment_no'   => 'ADJ-MOD-OPEN-MAIN',
                'branch_id'       => $mainBranch->id,
                'adjustment_type' => 'opening',
                'adjustment_date' => now()->toDateString(),
                'status'          => 'posted',
                'posted_at'       => now(),
                'notes'           => 'Modifier linked products opening stock (demo seed)',
            ]);
            foreach ($items as [$sku, $name, $cost, $qty]) {
                $product = Product::where('sku', $sku)->first();
                if (! $product) continue;
                $variant = $this->inv->resolveVariant($product, null);
                $adjustment->lines()->create([
                    'product_id'         => $product->id,
                    'product_variant_id' => $variant?->id,
                    'quantity'           => $qty,
                    'unit_cost'          => $cost,
                ]);
                $this->inv->postIn(
                    branch: $mainBranch, product: $product, variant: $variant, quantity: (float) $qty, unitCost: (float) $cost,
                    movementType: 'opening_stock', referenceType: 'stock_adjustment',
                    referenceId: $adjustment->id, referenceNo: $adjustment->adjustment_no,
                    expiryDate: null, notes: 'Modifier linked product opening stock',
                );
            }
        }
    }

    private function seedCombos(): void
    {
        $main = Branch::where('code', 'MAIN')->first();

        $definitions = [
            [
                'code' => 'COMBO-BURGER',
                'name' => 'Burger Meal Combo',
                'price' => 620,
                'branch_id' => $main?->id,
                'sort_order' => 10,
                'description' => 'Beef burger with fries and cola.',
                'components' => [
                    ['sku' => 'BURGER-B', 'quantity' => 1, 'sort_order' => 10],
                    ['sku' => 'FRIES-REG', 'quantity' => 1, 'sort_order' => 20],
                    ['sku' => 'COLA-500', 'quantity' => 1, 'sort_order' => 30],
                ],
            ],
            [
                'code' => 'COMBO-BIRYANI',
                'name' => 'Biryani Lunch Combo',
                'price' => 560,
                'branch_id' => $main?->id,
                'sort_order' => 20,
                'description' => 'Chicken biryani with lassi and dessert.',
                'components' => [
                    ['sku' => 'BIRYANI-C', 'quantity' => 1, 'sort_order' => 10],
                    ['sku' => 'LASSI-LRG', 'quantity' => 1, 'sort_order' => 20],
                    ['sku' => 'GULAB-J', 'quantity' => 1, 'sort_order' => 30],
                ],
            ],
        ];

        $comboCount = 0;
        $componentCount = 0;

        foreach ($definitions as $definition) {
            $combo = Combo::updateOrCreate(
                ['code' => $definition['code'], 'branch_id' => $definition['branch_id']],
                [
                    'name' => $definition['name'],
                    'price' => $definition['price'],
                    'sort_order' => $definition['sort_order'],
                    'status' => 'active',
                    'description' => $definition['description'],
                ]
            );

            $combo->components()->delete();
            $comboCount++;

            foreach ($definition['components'] as $component) {
                $product = Product::where('sku', $component['sku'])->first();
                if (!$product) {
                    continue;
                }

                $combo->components()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'quantity' => $component['quantity'],
                    'sort_order' => $component['sort_order'],
                ]);
                $componentCount++;
            }
        }

        $this->command->line("  Combos seeded: {$comboCount} combos, {$componentCount} components.");
    }

    private function seedCustomers(): void
    {
        $customers = [
            ['code' => 'CUST-001', 'name' => 'Ahmed Ali',        'phone' => '0300-1234567', 'email' => 'ahmed@example.com',   'gender' => 'male'],
            ['code' => 'CUST-002', 'name' => 'Sara Khan',        'phone' => '0301-2345678', 'email' => 'sara@example.com',    'gender' => 'female'],
            ['code' => 'CUST-003', 'name' => 'Usman Malik',      'phone' => '0302-3456789', 'email' => null,                   'gender' => 'male'],
            ['code' => 'CUST-004', 'name' => 'Fatima Tariq',     'phone' => '0303-4567890', 'email' => null,                   'gender' => 'female'],
            ['code' => 'CUST-005', 'name' => 'Bilal Chaudhry',   'phone' => '0304-5678901', 'email' => 'bilal@example.com',   'gender' => 'male'],
            ['code' => 'CUST-006', 'name' => 'Hina Baig',        'phone' => '0305-6789012', 'email' => null,                   'gender' => 'female'],
            ['code' => 'CUST-007', 'name' => 'Tariq Javed',      'phone' => '0306-7890123', 'email' => 'tariq@example.com',   'gender' => 'male'],
            ['code' => 'CUST-008', 'name' => 'Nadia Aziz',       'phone' => '0307-8901234', 'email' => null,                   'gender' => 'female'],
            ['code' => 'CUST-009', 'name' => 'Kamran Sheikh',    'phone' => '0308-9012345', 'email' => null,                   'gender' => 'male'],
            ['code' => 'CUST-010', 'name' => 'Zara Hussain',     'phone' => '0309-0123456', 'email' => 'zara@example.com',    'gender' => 'female'],
            ['code' => 'CUST-011', 'name' => 'Adeel Raza',       'phone' => '0310-1234567', 'email' => null,                   'gender' => 'male'],
            ['code' => 'CUST-012', 'name' => 'Maham Siddiqui',   'phone' => '0311-2345678', 'email' => null,                   'gender' => 'female'],
            ['code' => 'CUST-013', 'name' => 'Faisal Mehmood',   'phone' => '0312-3456789', 'email' => 'faisal@example.com',  'gender' => 'male'],
            ['code' => 'CUST-014', 'name' => 'Rabia Nawaz',      'phone' => '0313-4567890', 'email' => null,                   'gender' => 'female'],
            ['code' => 'CUST-015', 'name' => 'Shahzaib Iqbal',   'phone' => '0314-5678901', 'email' => null,                   'gender' => 'male'],
            ['code' => 'CUST-016', 'name' => 'Ayesha Noor',      'phone' => '0315-6789012', 'email' => 'ayesha@example.com',  'gender' => 'female'],
            ['code' => 'CUST-017', 'name' => 'Hamza Butt',       'phone' => '0316-7890123', 'email' => null,                   'gender' => 'male'],
            ['code' => 'CUST-018', 'name' => 'Sana Rehman',      'phone' => '0317-8901234', 'email' => null,                   'gender' => 'female'],
            ['code' => 'CUST-019', 'name' => 'Ali Haider',       'phone' => '0318-9012345', 'email' => 'alihaider@example.com','gender' => 'male'],
            ['code' => 'CUST-020', 'name' => 'Mariam Yousuf',    'phone' => '0319-0123456', 'email' => null,                   'gender' => 'female'],
        ];

        foreach ($customers as $c) {
            Customer::updateOrCreate(
                ['code' => $c['code']],
                array_merge($c, ['status' => 'active'])
            );
        }

        $this->command->line('  Customers seeded: ' . count($customers));
    }

    // ── Payment Methods ───────────────────────────────────────────────────────

    private function seedPaymentMethods(): void
    {
        $methods = [
            ['code' => 'CASH',   'name' => 'Cash',          'method_type' => 'cash',          'requires_reference' => false, 'is_cash_drawer' => true,  'is_active' => true],
            ['code' => 'CARD',   'name' => 'Card',          'method_type' => 'card',          'requires_reference' => true,  'is_cash_drawer' => false, 'is_active' => true],
            ['code' => 'BANK',   'name' => 'Bank Transfer', 'method_type' => 'bank_transfer', 'requires_reference' => true,  'is_cash_drawer' => false, 'is_active' => true],
            ['code' => 'JAZZ',   'name' => 'JazzCash',      'method_type' => 'wallet',        'requires_reference' => true,  'is_cash_drawer' => false, 'is_active' => true],
            ['code' => 'EASY',   'name' => 'Easypaisa',     'method_type' => 'wallet',        'requires_reference' => true,  'is_cash_drawer' => false, 'is_active' => true],
            ['code' => 'CHQ',    'name' => 'Cheque',        'method_type' => 'cheque',        'requires_reference' => true,  'is_cash_drawer' => false, 'is_active' => true],
        ];

        foreach ($methods as $m) {
            PaymentMethod::updateOrCreate(['code' => $m['code']], $m);
        }

        $this->command->line('  Payment methods seeded: ' . count($methods));
    }

    // ── Suppliers ────────────────────────────────────────────────────────────

    private function seedSuppliers(): void
    {
        $suppliers = [
            ['code' => 'SUP-001', 'name' => 'Default Supplier',            'contact_person' => 'General',          'phone' => '042-00000000', 'payment_terms_days' => 0],
            ['code' => 'SUP-002', 'name' => 'Pak Beverages Ltd',           'contact_person' => 'Rizwan Mirza',     'phone' => '042-35761000', 'payment_terms_days' => 30, 'email' => 'orders@pakbev.com'],
            ['code' => 'SUP-003', 'name' => 'National Foods Distribution', 'contact_person' => 'Asif Qureshi',     'phone' => '042-35762000', 'payment_terms_days' => 15, 'email' => 'sales@natfoods.com'],
            ['code' => 'SUP-004', 'name' => 'Dairy Fresh Pvt Ltd',         'contact_person' => 'Shahid Rana',      'phone' => '042-35763000', 'payment_terms_days' => 7,  'email' => 'fresh@dairyfresh.com'],
            ['code' => 'SUP-005', 'name' => 'Al-Madina Wholesale',         'contact_person' => 'Imran Chaudhry',   'phone' => '042-35764000', 'payment_terms_days' => 0],
            ['code' => 'SUP-006', 'name' => 'Metro Cash & Carry',          'contact_person' => 'Tariq Hassan',     'phone' => '042-35765000', 'payment_terms_days' => 0,  'email' => 'b2b@metro.com.pk'],
            ['code' => 'SUP-007', 'name' => 'Punjab Poultry Farm',         'contact_person' => 'Ghulam Nabi',      'phone' => '042-35766000', 'payment_terms_days' => 3],
            ['code' => 'SUP-008', 'name' => 'Tobacco Masters Ltd',         'contact_person' => 'Sajjad Mirza',     'phone' => '042-35767000', 'payment_terms_days' => 7,  'email' => 'trade@tobmasters.pk'],
        ];

        foreach ($suppliers as $s) {
            Supplier::updateOrCreate(
                ['code' => $s['code']],
                array_merge($s, ['status' => 'active', 'opening_balance' => 0, 'current_balance' => 0])
            );
        }

        $this->command->line('  Suppliers seeded: ' . count($suppliers));
    }

    // ── Opening Stock ─────────────────────────────────────────────────────────

    private function seedOpeningStock(): void
    {
        $mainBranch = Branch::where('code', 'MAIN')->first();
        $cityBranch = Branch::where('code', 'CITY')->first();

        $mainQtys = [
            'COLA-500'   => ['qty' => 48,  'cost' => 40],
            'PEPSI-500'  => ['qty' => 36,  'cost' => 38],
            'SPRITE-500' => ['qty' => 24,  'cost' => 40],
            'WATER-1L'   => ['qty' => 60,  'cost' => 20],
            'JUICE-MNG'  => ['qty' => 24,  'cost' => 25],
            'JUICE-ONG'  => ['qty' => 20,  'cost' => 25],
            'JUICE-PINE' => ['qty' => 20,  'cost' => 25],
            'TEA-100'    => ['qty' => 12,  'cost' => 180],
            'COFFEE-200' => ['qty' => 8,   'cost' => 350],
            'MILK-1L'    => ['qty' => 30,  'cost' => 120, 'expiry' => now()->addDays(7)->toDateString()],
            'BUTTER-200' => ['qty' => 15,  'cost' => 160, 'expiry' => now()->addDays(30)->toDateString()],
            'YOGURT-500' => ['qty' => 20,  'cost' => 90,  'expiry' => now()->addDays(5)->toDateString()],
            'BREAD-LRG'  => ['qty' => 10,  'cost' => 80,  'expiry' => now()->addDays(3)->toDateString()],
            'BISCUIT-P'  => ['qty' => 30,  'cost' => 55],
            'CHIPS-LAY'  => ['qty' => 40,  'cost' => 65],
            'RICE-5KG'   => ['qty' => 20,  'cost' => 850],
            'SUGAR-1KG'  => ['qty' => 40,  'cost' => 120],
            'OIL-1L'     => ['qty' => 24,  'cost' => 380],
            'SALT-1KG'   => ['qty' => 30,  'cost' => 60],
            'CIG-GOLD'   => ['qty' => 50,    'cost' => 270],
            // Measurable/weighted items
            'TOMATO-KG'  => ['qty' => 20.000, 'cost' => 60],
            'POTATO-KG'  => ['qty' => 30.000, 'cost' => 40],
            'ONION-KG'   => ['qty' => 25.000, 'cost' => 50],
            'CHICKEN-KG' => ['qty' => 15.000, 'cost' => 550, 'expiry' => now()->addDays(3)->toDateString()],
            'BEEF-KG'    => ['qty' => 10.000, 'cost' => 900, 'expiry' => now()->addDays(3)->toDateString()],
            'RICE-LOOSE' => ['qty' => 50.000, 'cost' => 180],
            'SUGAR-LOSE' => ['qty' => 40.000, 'cost' => 100],
            'FLOUR-KG'   => ['qty' => 60.000, 'cost' => 80],
            'OIL-LOOSE'  => ['qty' => 20.000, 'cost' => 340],
            'FABRIC-MTR' => ['qty' => 50.000, 'cost' => 250],
        ];

        $cityQtys = [
            'COLA-500'   => ['qty' => 24,    'cost' => 40],
            'PEPSI-500'  => ['qty' => 18,    'cost' => 38],
            'SPRITE-500' => ['qty' => 12,    'cost' => 40],
            'WATER-1L'   => ['qty' => 30,    'cost' => 20],
            'JUICE-MNG'  => ['qty' => 12,    'cost' => 25],
            'JUICE-ONG'  => ['qty' => 10,    'cost' => 25],
            'MILK-1L'    => ['qty' => 15,    'cost' => 120, 'expiry' => now()->addDays(7)->toDateString()],
            'BUTTER-200' => ['qty' => 8,     'cost' => 160, 'expiry' => now()->addDays(30)->toDateString()],
            'YOGURT-500' => ['qty' => 10,    'cost' => 90,  'expiry' => now()->addDays(5)->toDateString()],
            'BISCUIT-P'  => ['qty' => 20,    'cost' => 55],
            'CHIPS-LAY'  => ['qty' => 25,    'cost' => 65],
            'RICE-5KG'   => ['qty' => 10,    'cost' => 850],
            'SUGAR-1KG'  => ['qty' => 20,    'cost' => 120],
            'OIL-1L'     => ['qty' => 12,    'cost' => 380],
            'SALT-1KG'   => ['qty' => 15,    'cost' => 60],
            'CIG-GOLD'   => ['qty' => 30,    'cost' => 270],
            // Measurable / weighted items at City branch
            'TOMATO-KG'  => ['qty' => 10.000, 'cost' => 60],
            'POTATO-KG'  => ['qty' => 15.000, 'cost' => 40],
            'ONION-KG'   => ['qty' => 12.000, 'cost' => 50],
            'CHICKEN-KG' => ['qty' => 8.000,  'cost' => 550, 'expiry' => now()->addDays(3)->toDateString()],
            'BEEF-KG'    => ['qty' => 5.000,  'cost' => 900, 'expiry' => now()->addDays(3)->toDateString()],
            'RICE-LOOSE' => ['qty' => 25.000, 'cost' => 180],
            'SUGAR-LOSE' => ['qty' => 20.000, 'cost' => 100],
            'FLOUR-KG'   => ['qty' => 30.000, 'cost' => 80],
            'OIL-LOOSE'  => ['qty' => 10.000, 'cost' => 340],
            'FABRIC-MTR' => ['qty' => 25.000, 'cost' => 250],
        ];

        $this->postOpeningForBranch($mainBranch, $mainQtys, 'Main');
        $this->postOpeningForBranch($cityBranch, $cityQtys, 'City');
    }

    private function postOpeningForBranch(Branch $branch, array $qtys, string $label): void
    {
        // Guard specifically on catalog opening adjustments (ADJ-OPEN-{BRANCH}-*).
        // This avoids a false-positive skip when seedModifierStockProducts() has already
        // created a separate 'opening' adjustment (ADJ-MOD-OPEN-MAIN) for modifier-linked
        // products on the same branch before this method is called.
        $alreadyPosted = StockAdjustment::where('branch_id', $branch->id)
            ->where('adjustment_type', 'opening')
            ->where('adjustment_no', 'like', 'ADJ-OPEN-' . $branch->code . '-%')
            ->exists();

        if ($alreadyPosted) {
            $this->command->line("  Opening stock already exists for {$label}, skipping.");
            return;
        }

        $adjustment = StockAdjustment::create([
            'adjustment_no'   => 'ADJ-OPEN-' . $branch->code . '-' . now()->format('YmdHis'),
            'branch_id'       => $branch->id,
            'adjustment_type' => 'opening',
            'adjustment_date' => now()->toDateString(),
            'status'          => 'posted',
            'posted_at'       => now(),
            'notes'           => "Initial opening stock — {$label} branch demo seed",
        ]);

        $lineCount = 0;

        foreach ($qtys as $sku => $data) {
            $product = Product::where('sku', $sku)->first();
            if (!$product) continue;

            $variant = $this->inv->resolveVariant($product, null);
            $qty     = (float) $data['qty'];
            $cost    = (float) $data['cost'];
            $expiry  = $data['expiry'] ?? null;

            $line = $adjustment->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'expiry_date'        => $expiry,
                'quantity'           => $qty,
                'unit_cost'          => $cost,
            ]);

            $ledger = $this->inv->postIn(
                branch: $branch,
                product: $product,
                variant: $variant,
                quantity: $qty,
                unitCost: $cost,
                movementType: 'opening_stock',
                referenceType: 'stock_adjustment',
                referenceId: $adjustment->id,
                referenceNo: $adjustment->adjustment_no,
                expiryDate: $expiry,
                notes: "Demo opening stock — {$label}",
            );

            $line->update(['inventory_batch_id' => $ledger->inventory_batch_id]);
            $lineCount++;
        }

        $this->command->line("  Opening stock posted: {$lineCount} lines → {$label} branch");
    }

    // ── Purchase Flow (PO → GRN → Bill → Payment) ───────────────────────────

    private function seedPurchaseFlow(): void
    {
        $main      = Branch::where('code', 'MAIN')->first();
        $supBev    = Supplier::where('code', 'SUP-002')->first();
        $supFood   = Supplier::where('code', 'SUP-003')->first();
        $supDairy  = Supplier::where('code', 'SUP-004')->first();
        $owner     = User::where('email', 'owner@demo.com')->first();

        if (!$main || !$supBev) return;

        // --- PO 1: Beverages (fully received & billed & paid) ---
        $existsPO1 = PurchaseOrder::where('po_no', 'PO-2025-001')->exists();

        if (!$existsPO1) {
            $po1Lines = [
                ['sku' => 'COLA-500',   'qty' => 120, 'cost' => 40],
                ['sku' => 'PEPSI-500',  'qty' => 96,  'cost' => 38],
                ['sku' => 'SPRITE-500', 'qty' => 72,  'cost' => 40],
                ['sku' => 'WATER-1L',   'qty' => 144, 'cost' => 20],
            ];

            $po1 = $this->createPO('PO-2025-001', $main, $supBev, $po1Lines, $owner, now()->subDays(20));
            $grn1 = $this->createGRN($po1, $po1Lines, $owner, now()->subDays(18));
            $bill1 = $this->createBill($grn1, $po1Lines, $owner, now()->subDays(17));
            $this->createSupplierPayment($bill1, $owner, now()->subDays(10), 'Cash payment — PO-2025-001');
        }

        // --- PO 2: Grocery (received, billed, partially paid) ---
        $existsPO2 = PurchaseOrder::where('po_no', 'PO-2025-002')->exists();

        if (!$existsPO2 && $supFood) {
            $po2Lines = [
                ['sku' => 'RICE-5KG',  'qty' => 40,  'cost' => 850],
                ['sku' => 'SUGAR-1KG', 'qty' => 80,  'cost' => 120],
                ['sku' => 'OIL-1L',    'qty' => 48,  'cost' => 380],
                ['sku' => 'SALT-1KG',  'qty' => 60,  'cost' => 60],
            ];

            $po2  = $this->createPO('PO-2025-002', $main, $supFood, $po2Lines, $owner, now()->subDays(15));
            $grn2 = $this->createGRN($po2, $po2Lines, $owner, now()->subDays(13));
            $bill2 = $this->createBill($grn2, $po2Lines, $owner, now()->subDays(12));
            // Partial payment — 50%
            $partial = round($bill2->grand_total * 0.5);
            $this->createSupplierPayment($bill2, $owner, now()->subDays(5), 'Partial payment — PO-2025-002', $partial);
        }

        // --- PO 3: Dairy (approved, GRN done, bill pending) ---
        $existsPO3 = PurchaseOrder::where('po_no', 'PO-2025-003')->exists();

        if (!$existsPO3 && $supDairy) {
            $po3Lines = [
                ['sku' => 'MILK-1L',    'qty' => 60,  'cost' => 120, 'expiry' => now()->addDays(7)->toDateString()],
                ['sku' => 'BUTTER-200', 'qty' => 30,  'cost' => 160, 'expiry' => now()->addDays(30)->toDateString()],
                ['sku' => 'YOGURT-500', 'qty' => 40,  'cost' => 90,  'expiry' => now()->addDays(5)->toDateString()],
            ];

            $po3 = $this->createPO('PO-2025-003', $main, $supDairy, $po3Lines, $owner, now()->subDays(5));
            $this->createGRN($po3, $po3Lines, $owner, now()->subDays(3));
            // Bill not yet created — status stays as-is
        }

        $this->command->line('  Purchase flow seeded: 3 POs, 3 GRNs, 2 Bills, 2 Payments.');
    }

    private function createPO(string $poNo, Branch $branch, Supplier $supplier, array $lines, User $user, $date): PurchaseOrder
    {
        $total = collect($lines)->sum(fn ($l) => $l['qty'] * $l['cost']);

        $po = PurchaseOrder::create([
            'po_no'                  => $poNo,
            'branch_id'              => $branch->id,
            'supplier_id'            => $supplier->id,
            'order_date'             => $date->toDateString(),
            'expected_delivery_date' => $date->copy()->addDays(3)->toDateString(),
            'status'                 => 'approved',
            'total_amount'           => $total,
            'posted_by_user_id'      => $user->id,
            'approved_by_user_id'    => $user->id,
            'approved_at'            => $date->copy()->addDay(),
            'notes'                  => 'Demo purchase order',
        ]);

        foreach ($lines as $line) {
            $product = Product::where('sku', $line['sku'])->first();
            if (!$product) continue;
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
            'notes'             => 'Demo GRN',
            'posted_by_user_id' => $user->id,
            'posted_at'         => $date,
        ]);

        $branch = Branch::find($po->branch_id);

        foreach ($lines as $line) {
            $product = Product::where('sku', $line['sku'])->first();
            if (!$product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $qty     = (float) $line['qty'];
            $cost    = (float) $line['cost'];
            $expiry  = $line['expiry'] ?? null;

            $grn->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity_received'  => $qty,
                'unit_cost'          => $cost,
                'expiry_date'        => $expiry,
            ]);

            $this->inv->postIn(
                branch: $branch,
                product: $product,
                variant: $variant,
                quantity: $qty,
                unitCost: $cost,
                movementType: 'purchase',
                referenceType: 'goods_receipt',
                referenceId: $grn->id,
                referenceNo: $grnNo,
                expiryDate: $expiry,
                notes: 'Purchase stock in — ' . $grnNo,
                userId: $user->id,
            );
        }

        $po->update(['status' => 'received']);

        return $grn;
    }

    private function createBill(GoodsReceipt $grn, array $lines, User $user, $date): PurchaseBill
    {
        $subtotal = collect($lines)->sum(fn ($l) => $l['qty'] * $l['cost']);
        $billNo   = 'BILL-' . $grn->grn_no;

        $bill = PurchaseBill::create([
            'bill_no'             => $billNo,
            'supplier_invoice_no' => 'INV-' . strtoupper(Str::random(6)),
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
            if (!$product) continue;
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

    private function createSupplierPayment(PurchaseBill $bill, User $user, $date, string $notes, ?float $amount = null): void
    {
        $amount = $amount ?? (float) $bill->balance_due;
        $payNo  = 'PAY-' . $bill->bill_no;

        SupplierPayment::create([
            'payment_no'        => $payNo,
            'supplier_id'       => $bill->supplier_id,
            'branch_id'         => $bill->branch_id,
            'purchase_bill_id'  => $bill->id,
            'payment_date'      => $date->toDateString(),
            'amount'            => $amount,
            'payment_method'    => 'cash',
            'notes'             => $notes,
            'posted_by_user_id' => $user->id,
        ]);

        $bill->update([
            'amount_paid' => (float) $bill->amount_paid + $amount,
            'balance_due' => max((float) $bill->balance_due - $amount, 0),
            'status'      => $amount >= (float) $bill->balance_due ? 'paid' : 'partial',
        ]);
    }

    // ── Shifts ────────────────────────────────────────────────────────────────

    private function seedShifts(): void
    {
        $t1    = Terminal::where('code', 'POS-MAIN-01')->first();
        $owner = User::where('email', 'owner@demo.com')->first();

        if (!$t1) return;

        $branch = Branch::where('code', 'MAIN')->first();

        // Closed shift — yesterday
        $shiftYest = Shift::where('terminal_id', $t1->id)->where('opened_at', '>=', now()->subDays(2)->startOfDay())->first();

        if (!$shiftYest) {
            Shift::create([
                'branch_id'         => $branch->id,
                'terminal_id'       => $t1->id,
                'opened_by_user_id' => $owner->id,
                'closed_by_user_id' => $owner->id,
                'opening_cash'      => 5000,
                'total_sales'       => 15400,
                'total_cash'        => 9200,
                'total_card'        => 4100,
                'total_bank_transfer'=> 2100,
                'total_cheque'      => 0,
                'total_refunds'     => 0,
                'total_discount'    => 500,
                'total_tax'         => 0,
                'expected_cash'     => 14200,
                'counted_cash'      => 14100,
                'cash_variance'     => -100,
                'status'            => 'closed',
                'opening_cash'      => 5000,
                'opened_at'         => now()->subDay()->setTime(9, 0),
                'closed_at'         => now()->subDay()->setTime(22, 0),
                'opening_notes'     => 'Opening float — demo',
                'closing_notes'     => 'Closing — demo',
            ]);
        }

        // Open shift — today
        $openShift = Shift::where('terminal_id', $t1->id)->where('status', 'open')->first();

        if (!$openShift) {
            Shift::create([
                'branch_id'         => $branch->id,
                'terminal_id'       => $t1->id,
                'opened_by_user_id' => $owner->id,
                'opening_cash'      => 5000,
                'total_sales'       => 0,
                'total_cash'        => 0,
                'total_card'        => 0,
                'total_bank_transfer'=> 0,
                'total_cheque'      => 0,
                'total_refunds'     => 0,
                'total_discount'    => 0,
                'total_tax'         => 0,
                'expected_cash'     => 5000,
                'status'            => 'open',
                'opened_at'         => now()->setTime(9, 0),
                'opening_notes'     => 'Opening float — today demo',
            ]);
        }

        $this->command->line('  Shifts seeded: 1 closed (yesterday) + 1 open (today).');
    }

    // ── Sales Orders ─────────────────────────────────────────────────────────

    private function seedSalesOrders(): void
    {
        if (SalesOrder::count() > 0) {
            $this->command->line('  Sales orders already exist, skipping.');
            return;
        }

        $main     = Branch::where('code', 'MAIN')->first();
        $city     = Branch::where('code', 'CITY')->first();
        $t1       = Terminal::where('code', 'POS-MAIN-01')->first();
        $shift    = Shift::where('terminal_id', $t1?->id)->where('status', 'open')->first();
        $owner    = User::where('email', 'owner@demo.com')->first();
        $cashier  = User::where('email', 'cashier@demo.com')->first();

        $cash   = PaymentMethod::where('method_type', 'cash')->first();
        $card   = PaymentMethod::where('method_type', 'card')->first();
        $wallet = PaymentMethod::where('method_type', 'wallet')->where('name', 'JazzCash')->first();

        $customers = Customer::where('code', '!=', null)->whereNotNull('code')->get()->keyBy('code');

        $salesData = [
            // Quick sales — cash — main branch
            [
                'branch' => $main, 'terminal' => $t1, 'shift' => $shift, 'user' => $owner,
                'customer' => null, 'order_type' => 'quick_sale', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'COLA-500', 'qty' => 3, 'price' => 60],
                    ['sku' => 'BISCUIT-P', 'qty' => 2, 'price' => 80],
                ],
                'payments' => [['method' => $cash, 'amount' => 340, 'tendered' => 400]],
                'daysAgo' => 0,
            ],
            [
                'branch' => $main, 'terminal' => $t1, 'shift' => $shift, 'user' => $owner,
                'customer' => $customers['CUST-001'] ?? null, 'order_type' => 'quick_sale', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'MILK-1L', 'qty' => 2, 'price' => 150],
                    ['sku' => 'BREAD-LRG', 'qty' => 1, 'price' => 120],
                    ['sku' => 'BUTTER-200', 'qty' => 1, 'price' => 210],
                ],
                'payments' => [['method' => $cash, 'amount' => 630, 'tendered' => 700]],
                'daysAgo' => 0,
            ],
            [
                'branch' => $main, 'terminal' => $t1, 'shift' => $shift, 'user' => $owner,
                'customer' => $customers['CUST-002'] ?? null, 'order_type' => 'takeaway', 'discount_type' => 'fixed',
                'discount_value' => 50,
                'lines' => [
                    ['sku' => 'TEA-100',    'qty' => 1, 'price' => 250],
                    ['sku' => 'COFFEE-200', 'qty' => 1, 'price' => 480],
                ],
                'payments' => [['method' => $card, 'amount' => 680, 'ref' => '4242']],
                'daysAgo' => 0,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-003'] ?? null, 'order_type' => 'dine_in', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'CHIPS-LAY', 'qty' => 4, 'price' => 95],
                    ['sku' => 'COLA-500',  'qty' => 4, 'price' => 60],
                    ['sku' => 'WATER-1L',  'qty' => 2, 'price' => 30],
                ],
                'payments' => [['method' => $cash, 'amount' => 680, 'tendered' => 700]],
                'daysAgo' => 0,
            ],
            // Yesterday's sales
            [
                'branch' => $main, 'terminal' => $t1, 'shift' => null, 'user' => $owner,
                'customer' => null, 'order_type' => 'quick_sale', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'RICE-5KG',  'qty' => 2, 'price' => 1100],
                    ['sku' => 'SUGAR-1KG', 'qty' => 3, 'price' => 160],
                    ['sku' => 'OIL-1L',    'qty' => 2, 'price' => 480],
                ],
                'payments' => [['method' => $cash, 'amount' => 3640, 'tendered' => 4000]],
                'daysAgo' => 1,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-005'] ?? null, 'order_type' => 'quick_sale', 'discount_type' => 'percent',
                'discount_value' => 10,
                'lines' => [
                    ['sku' => 'CIG-GOLD', 'qty' => 5, 'price' => 320],
                ],
                'payments' => [['method' => $cash, 'amount' => 1440, 'tendered' => 1500]],
                'daysAgo' => 1,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-004'] ?? null, 'order_type' => 'quick_sale', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'PEPSI-500',  'qty' => 6, 'price' => 58],
                    ['sku' => 'SPRITE-500', 'qty' => 6, 'price' => 60],
                    ['sku' => 'WATER-1L',   'qty' => 12, 'price' => 30],
                ],
                'payments' => [['method' => $wallet, 'amount' => 1068, 'ref' => 'JCZ123456']],
                'daysAgo' => 1,
            ],
            // 2 days ago
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-006'] ?? null, 'order_type' => 'quick_sale', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'YOGURT-500', 'qty' => 3, 'price' => 130],
                    ['sku' => 'MILK-1L',    'qty' => 3, 'price' => 150],
                    ['sku' => 'BREAD-LRG',  'qty' => 2, 'price' => 120],
                ],
                'payments' => [
                    ['method' => $cash, 'amount' => 800, 'tendered' => 800],
                    ['method' => $card, 'amount' => 280, 'ref' => '9999'],
                ],
                'daysAgo' => 2,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => null, 'order_type' => 'quick_sale', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'SALT-1KG',  'qty' => 4, 'price' => 85],
                    ['sku' => 'SUGAR-1KG', 'qty' => 4, 'price' => 160],
                ],
                'payments' => [['method' => $cash, 'amount' => 980, 'tendered' => 1000]],
                'daysAgo' => 2,
            ],
            // City branch sales
            [
                'branch' => $city, 'terminal' => null, 'shift' => null, 'user' => $cashier ?? $owner,
                'customer' => $customers['CUST-007'] ?? null, 'order_type' => 'quick_sale', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'COLA-500',  'qty' => 6, 'price' => 63],
                    ['sku' => 'CHIPS-LAY', 'qty' => 4, 'price' => 100],
                ],
                'payments' => [['method' => $cash, 'amount' => 778, 'tendered' => 800]],
                'daysAgo' => 1,
            ],
            [
                'branch' => $city, 'terminal' => null, 'shift' => null, 'user' => $cashier ?? $owner,
                'customer' => $customers['CUST-008'] ?? null, 'order_type' => 'quick_sale', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'RICE-5KG',  'qty' => 1, 'price' => 1155],
                    ['sku' => 'SUGAR-1KG', 'qty' => 2, 'price' => 168],
                ],
                'payments' => [
                    ['method' => $cash, 'amount' => 500, 'tendered' => 500],
                    ['method' => $card, 'amount' => 991, 'ref' => '1234'],
                ],
                'daysAgo' => 0,
            ],
            // 3 days ago — bigger basket
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-009'] ?? null, 'order_type' => 'quick_sale', 'discount_type' => 'fixed',
                'discount_value' => 100,
                'lines' => [
                    ['sku' => 'RICE-5KG',   'qty' => 3, 'price' => 1100],
                    ['sku' => 'OIL-1L',     'qty' => 3, 'price' => 480],
                    ['sku' => 'SUGAR-1KG',  'qty' => 5, 'price' => 160],
                    ['sku' => 'SALT-1KG',   'qty' => 5, 'price' => 85],
                ],
                'payments' => [['method' => $cash, 'amount' => 5865, 'tendered' => 6000]],
                'daysAgo' => 3,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-010'] ?? null, 'order_type' => 'delivery', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'WATER-1L',  'qty' => 24, 'price' => 30],
                    ['sku' => 'JUICE-MNG', 'qty' => 12, 'price' => 40],
                ],
                'payments' => [['method' => $card, 'amount' => 1200, 'ref' => '5678']],
                'daysAgo' => 3,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => null, 'order_type' => 'quick_sale', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'CIG-GOLD',  'qty' => 10, 'price' => 320],
                    ['sku' => 'PEPSI-500', 'qty' => 2,  'price' => 58],
                ],
                'payments' => [['method' => $cash, 'amount' => 3316, 'tendered' => 3500]],
                'daysAgo' => 4,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-001'] ?? null, 'order_type' => 'quick_sale', 'discount_type' => 'percent',
                'discount_value' => 5,
                'lines' => [
                    ['sku' => 'MILK-1L',    'qty' => 5, 'price' => 150],
                    ['sku' => 'YOGURT-500', 'qty' => 3, 'price' => 130],
                    ['sku' => 'BUTTER-200', 'qty' => 2, 'price' => 210],
                    ['sku' => 'BREAD-LRG',  'qty' => 3, 'price' => 120],
                ],
                'payments' => [
                    ['method' => $cash, 'amount' => 1500, 'tendered' => 1500],
                    ['method' => $card, 'amount' => 341.25],
                ],
                'daysAgo' => 4,
            ],
            // ── Restaurant / Dine-in orders ───────────────────────────────
            [
                'branch' => $main, 'terminal' => $t1, 'shift' => $shift, 'user' => $owner,
                'customer' => $customers['CUST-002'] ?? null, 'order_type' => 'dine_in', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'KARAHI-C',  'qty' => 1, 'price' => 900],
                    ['sku' => 'NAAN-1',    'qty' => 4, 'price' => 30],
                    ['sku' => 'DAAL-MIX',  'qty' => 1, 'price' => 200],
                    ['sku' => 'CHAI-CUP',  'qty' => 2, 'price' => 50],
                ],
                'payments' => [['method' => $cash, 'amount' => 1320, 'tendered' => 1500]],
                'daysAgo' => 0,
            ],
            [
                'branch' => $main, 'terminal' => $t1, 'shift' => $shift, 'user' => $owner,
                'customer' => $customers['CUST-003'] ?? null, 'order_type' => 'dine_in', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'BIRYANI-C',  'qty' => 2, 'price' => 400],
                    ['sku' => 'LASSI-LRG',  'qty' => 2, 'price' => 120],
                    ['sku' => 'GULAB-J',    'qty' => 2, 'price' => 100],
                ],
                'payments' => [['method' => $card, 'amount' => 1240, 'ref' => '7711']],
                'daysAgo' => 0,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-004'] ?? null, 'order_type' => 'dine_in', 'discount_type' => 'fixed',
                'discount_value' => 100,
                'lines' => [
                    ['sku' => 'KARAHI-M',   'qty' => 1, 'price' => 1600],
                    ['sku' => 'BIRYANI-M',  'qty' => 1, 'price' => 650],
                    ['sku' => 'NAAN-1',     'qty' => 6, 'price' => 30],
                    ['sku' => 'RICE-STM',   'qty' => 1, 'price' => 120],
                    ['sku' => 'KARAK-CUP',  'qty' => 2, 'price' => 60],
                ],
                'payments' => [['method' => $cash, 'amount' => 2570, 'tendered' => 3000]],
                'daysAgo' => 1,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => null, 'order_type' => 'dine_in', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'BURGER-C',   'qty' => 3, 'price' => 280],
                    ['sku' => 'FRIES-REG',  'qty' => 3, 'price' => 180],
                    ['sku' => 'COLA-500',   'qty' => 3, 'price' => 60],
                ],
                'payments' => [['method' => $cash, 'amount' => 1620, 'tendered' => 2000]],
                'daysAgo' => 1,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-005'] ?? null, 'order_type' => 'dine_in', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'PIZZA-C',    'qty' => 1, 'price' => 850],
                    ['sku' => 'PIZZA-M',    'qty' => 1, 'price' => 750],
                    ['sku' => 'PEPSI-500',  'qty' => 4, 'price' => 58],
                ],
                'payments' => [['method' => $card, 'amount' => 1832, 'ref' => '4433']],
                'daysAgo' => 2,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-006'] ?? null, 'order_type' => 'dine_in', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'SAMOSA-2',   'qty' => 2, 'price' => 70],
                    ['sku' => 'SOUP-VEG',   'qty' => 2, 'price' => 150],
                    ['sku' => 'BIRYANI-C',  'qty' => 2, 'price' => 400],
                    ['sku' => 'CHAI-CUP',   'qty' => 2, 'price' => 50],
                    ['sku' => 'KHEER',      'qty' => 2, 'price' => 140],
                ],
                'payments' => [['method' => $cash, 'amount' => 1620, 'tendered' => 2000]],
                'daysAgo' => 2,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-007'] ?? null, 'order_type' => 'takeaway', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'SHAWARMA',   'qty' => 4, 'price' => 220],
                    ['sku' => 'FRIES-REG',  'qty' => 2, 'price' => 180],
                    ['sku' => 'COLA-500',   'qty' => 4, 'price' => 60],
                ],
                'payments' => [['method' => $wallet, 'amount' => 1480, 'ref' => 'JCZ789012']],
                'daysAgo' => 2,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-008'] ?? null, 'order_type' => 'delivery', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'BURGER-B',   'qty' => 2, 'price' => 350],
                    ['sku' => 'BURGER-C',   'qty' => 2, 'price' => 280],
                    ['sku' => 'FRIES-REG',  'qty' => 4, 'price' => 180],
                    ['sku' => 'COLA-500',   'qty' => 4, 'price' => 60],
                ],
                'payments' => [['method' => $cash, 'amount' => 2220, 'tendered' => 2500]],
                'daysAgo' => 3,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-009'] ?? null, 'order_type' => 'dine_in', 'discount_type' => 'percent',
                'discount_value' => 10,
                'lines' => [
                    ['sku' => 'SPRING-R',   'qty' => 2, 'price' => 160],
                    ['sku' => 'KARAHI-C',   'qty' => 1, 'price' => 900],
                    ['sku' => 'DAAL-MIX',   'qty' => 1, 'price' => 200],
                    ['sku' => 'NAAN-1',     'qty' => 6, 'price' => 30],
                    ['sku' => 'ICECREAM',   'qty' => 2, 'price' => 120],
                    ['sku' => 'KARAK-CUP',  'qty' => 3, 'price' => 60],
                ],
                'payments' => [['method' => $card, 'amount' => 1818, 'ref' => '8822']],
                'daysAgo' => 3,
            ],
            [
                'branch' => $city, 'terminal' => null, 'shift' => null, 'user' => $cashier ?? $owner,
                'customer' => $customers['CUST-010'] ?? null, 'order_type' => 'dine_in', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'BIRYANI-C',  'qty' => 3, 'price' => 420],
                    ['sku' => 'LASSI-LRG',  'qty' => 3, 'price' => 126],
                ],
                'payments' => [['method' => $cash, 'amount' => 1638, 'tendered' => 2000]],
                'daysAgo' => 1,
            ],
            [
                'branch' => $main, 'terminal' => null, 'shift' => null, 'user' => $owner,
                'customer' => $customers['CUST-011'] ?? null, 'order_type' => 'quick_sale', 'discount_type' => 'none',
                'lines' => [
                    ['sku' => 'JUICE-ONG',  'qty' => 6, 'price' => 40],
                    ['sku' => 'JUICE-PINE', 'qty' => 6, 'price' => 40],
                    ['sku' => 'BISCUIT-P',  'qty' => 4, 'price' => 80],
                ],
                'payments' => [['method' => $cash, 'amount' => 800, 'tendered' => 1000]],
                'daysAgo' => 4,
            ],
        ];

        $count = 0;

        foreach ($salesData as $sd) {
            try {
                $this->createSale($sd);
                $count++;
            } catch (\Throwable $e) {
                $this->command->warn("  Sale #{$count} failed: " . $e->getMessage());
            }
        }

        $this->command->line("  Sales orders seeded: {$count}");
    }

    private function createSale(array $sd): void
    {
        $daysAgo = $sd['daysAgo'] ?? 0;
        $saleDate = now()->subDays($daysAgo);

        // Calculate totals
        $subtotal = 0;
        foreach ($sd['lines'] as $line) {
            $subtotal += $line['qty'] * $line['price'];
        }

        $discountType  = $sd['discount_type'] ?? 'none';
        $discountValue = $sd['discount_value'] ?? 0;
        $orderDiscount = 0;
        if ($discountType === 'fixed')   $orderDiscount = $discountValue;
        if ($discountType === 'percent') $orderDiscount = $subtotal * $discountValue / 100;

        $grandTotal = max($subtotal - $orderDiscount, 0);

        $sale = SalesOrder::create([
            'sale_no'            => 'SO-' . $saleDate->format('Ymd') . '-' . str_pad(SalesOrder::count() + 1, 4, '0', STR_PAD_LEFT),
            'branch_id'          => $sd['branch']->id,
            'terminal_id'        => $sd['terminal']?->id,
            'shift_id'           => $sd['shift']?->id,
            'customer_id'        => $sd['customer']?->id,
            'order_source'       => $sd['terminal'] ? 'pos' : 'manual',
            'order_type'         => $sd['order_type'],
            'sale_date'          => $saleDate,
            'subtotal'           => $subtotal,
            'discount_type'      => $discountType,
            'discount_value'     => $discountValue,
            'discount_amount'    => $orderDiscount,
            'tax_amount'         => 0,
            'grand_total'        => $grandTotal,
            'status'             => 'draft',
            'created_by_user_id' => $sd['user']->id,
        ]);

        foreach ($sd['lines'] as $line) {
            $product = Product::where('sku', $line['sku'])->first();
            if (!$product) continue;
            $variant = $this->inv->resolveVariant($product, null);

            $sale->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name'       => $product->name,
                'variant_name'       => $variant?->name,
                'quantity'           => $line['qty'],
                'unit_price'         => $line['price'],
                'unit_cost'          => 0,
                'cost_total'         => 0,
                'discount_amount'    => 0,
                'tax_amount'         => 0,
                'line_total'         => $line['qty'] * $line['price'],
            ]);
        }

        foreach ($sd['payments'] as $pay) {
            $amount   = (float) $pay['amount'];
            $tendered = $pay['tendered'] ?? null;

            $sale->payments()->create([
                'payment_method_id' => $pay['method']->id,
                'amount'            => $amount,
                'tendered_amount'   => $tendered,
                'change_amount'     => $pay['method']->method_type === 'cash' && $tendered ? max($tendered - $amount, 0) : 0,
                'transaction_ref'   => $pay['ref'] ?? null,
            ]);
        }

        $this->sales->finalizePaidSale($sale);
    }

    // ── Stock Transfer ────────────────────────────────────────────────────────

    private function seedStockTransfer(): void
    {
        if (StockTransfer::count() > 0) {
            $this->command->line('  Stock transfer already exists, skipping.');
            return;
        }

        $main  = Branch::where('code', 'MAIN')->first();
        $city  = Branch::where('code', 'CITY')->first();
        $owner = User::where('email', 'owner@demo.com')->first();

        if (!$main || !$city) return;

        $transferLines = [
            ['sku' => 'COLA-500',  'qty' => 12, 'cost' => 40],
            ['sku' => 'PEPSI-500', 'qty' => 12, 'cost' => 38],
            ['sku' => 'CHIPS-LAY', 'qty' => 20, 'cost' => 65],
        ];

        $transfer = StockTransfer::create([
            'transfer_no'       => 'TRF-2025-001',
            'from_branch_id'    => $main->id,
            'to_branch_id'      => $city->id,
            'transfer_date'     => now()->subDays(2)->toDateString(),
            'status'            => 'posted',
            'posted_by_user_id' => $owner->id,
            'posted_at'         => now()->subDays(2),
            'notes'             => 'Stock replenishment for City branch',
        ]);

        foreach ($transferLines as $line) {
            $product = Product::where('sku', $line['sku'])->first();
            if (!$product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $qty     = (float) $line['qty'];
            $cost    = (float) $line['cost'];

            $transfer->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity'           => $qty,
                'unit_cost'          => $cost,
            ]);

            // Stock out from MAIN
            $this->inv->postOutFefo(
                branch: $main,
                product: $product,
                variant: $variant,
                quantity: $qty,
                movementType: 'transfer_out',
                referenceType: 'stock_transfer',
                referenceId: $transfer->id,
                referenceNo: $transfer->transfer_no,
                notes: 'Transfer to City branch',
                userId: $owner->id,
            );

            // Stock in to CITY
            $this->inv->postIn(
                branch: $city,
                product: $product,
                variant: $variant,
                quantity: $qty,
                unitCost: $cost,
                movementType: 'transfer_in',
                referenceType: 'stock_transfer',
                referenceId: $transfer->id,
                referenceNo: $transfer->transfer_no,
                notes: 'Transfer from Main branch',
                userId: $owner->id,
            );
        }

        $this->command->line('  Stock transfer seeded: TRF-2025-001 (Main → City, 3 products).');
    }

    // ── Daily Closing ─────────────────────────────────────────────────────────

    private function seedDailyClosing(): void
    {
        if (DailyClosing::count() > 0) {
            $this->command->line('  Daily closing already exists, skipping.');
            return;
        }

        $main  = Branch::where('code', 'MAIN')->first();
        $owner = User::where('email', 'owner@demo.com')->first();

        DailyClosing::create([
            'branch_id'          => $main->id,
            'closing_date'       => now()->subDay()->toDateString(),
            'closed_by_user_id'  => $owner->id,
            'total_sales'        => 15400,
            'total_cash'         => 9200,
            'total_card'         => 4100,
            'total_bank_transfer'=> 2100,
            'total_cheque'       => 0,
            'total_refunds'      => 0,
            'total_discount'     => 500,
            'total_tax'          => 0,
            'expected_cash'      => 14200,
            'counted_cash'       => 14100,
            'cash_variance'      => -100,
            'status'             => 'approved',
            'notes'              => 'End of day — demo seed',
        ]);

        $this->command->line('  Daily closing seeded: yesterday, Main branch.');
    }

    // ── Printers & Print Agent ───────────────────────────────────────────────

    private function seedPrintersAndAgent(): void
    {
        $main = Branch::where('code', 'MAIN')->first();

        if (Printer::count() > 0) {
            $this->command->line('  Printers already exist, skipping creation.');
            $kotPrinter     = Printer::where('code', 'FAKE-KOT')->first();
            $receiptPrinter = Printer::where('code', 'FAKE-RECEIPT')->first();
        } else {
            // Fake KOT printer — use 127.0.0.1:9100 (run fake-printer.js locally)
            $kotPrinter = Printer::create([
                'branch_id'           => $main?->id,
                'name'                => 'Fake Kitchen Printer',
                'code'                => 'FAKE-KOT',
                'printer_type'        => 'network',
                'print_role'          => 'kot',
                'ip_address'          => '127.0.0.1',
                'port'                => 9100,
                'paper_size'          => '80mm',
                'characters_per_line' => 42,
                'is_default'          => true,
                'is_active'           => true,
                'agent_enabled'       => true,
                'notes'               => 'Fake printer for local testing — run fake-printer.js',
            ]);

            // Fake receipt printer
            $receiptPrinter = Printer::create([
                'branch_id'           => $main?->id,
                'name'                => 'Fake Receipt Printer',
                'code'                => 'FAKE-RECEIPT',
                'printer_type'        => 'network',
                'print_role'          => 'receipt',
                'ip_address'          => '127.0.0.1',
                'port'                => 9100,
                'paper_size'          => '80mm',
                'characters_per_line' => 42,
                'is_default'          => true,
                'is_active'           => true,
                'agent_enabled'       => true,
                'notes'               => 'Fake printer for local testing — run fake-printer.js',
            ]);

            $this->command->line('  Printers seeded: Fake KOT + Fake Receipt (127.0.0.1:9100).');
        }

        // Demo print agent — fixed token for easy local testing
        $plainToken = 'demo-local-agent-token-for-testing-only-change-in-prod';

        if (!PrintAgent::where('agent_code', 'AG-DEMO-LOCAL')->exists()) {
            PrintAgent::create([
                'name'         => 'Demo Local Agent',
                'agent_code'   => 'AG-DEMO-LOCAL',
                'branch_id'    => null,   // null = handles all branches
                'terminal_id'  => null,   // null = handles all terminals
                'token_hash'   => Hash::make($plainToken),
                'device_name'  => 'Developer PC',
                'device_os'    => 'Windows (Laragon)',
                'local_ip'     => '127.0.0.1',
                'is_active'    => true,
            ]);
            $this->command->line('  Print Agent seeded: AG-DEMO-LOCAL');
            $this->command->line('  Token: ' . $plainToken);
        }

        // ── Terminal Printer Settings (idempotent — safe to re-run) ─────────
        if ($kotPrinter && $receiptPrinter) {
            $terminals = Terminal::all();
            foreach ($terminals as $t) {
                TerminalPrinterSetting::updateOrCreate(
                    ['terminal_id' => $t->id],
                    [
                        'receipt_printer_id' => $receiptPrinter->id,
                        'kot_printer_id'     => $kotPrinter->id,
                        'auto_print_receipt' => true,
                        'auto_print_kot'     => true,
                    ]
                );
            }
            $this->command->line('  Terminal printer settings configured for ' . $terminals->count() . ' terminal(s).');
        }

        $this->seedSalesControls();
    }

    private function seedSalesControls(): void
    {
        $main = Branch::where('code', 'MAIN')->first();
        if (!$main) return;

        // Promotion
        Promotion::updateOrCreate(
            ['code' => 'BURGER10'],
            [
                'branch_id'      => $main->id,
                'name'           => '10% Burger Discount',
                'promotion_type' => 'order',
                'discount_type'  => 'percent',
                'discount_value' => 10,
                'order_types'    => ['dine_in', 'takeaway'],
                'requires_code'  => true,
                'status'         => 'active',
                'priority'       => 100,
            ]
        );

        $burgerDeal = Promotion::updateOrCreate(
            ['code' => 'BURGER15'],
            [
                'branch_id'      => $main->id,
                'name'           => '15% Selected Burger Deal',
                'promotion_type' => 'product',
                'discount_type'  => 'percent',
                'discount_value' => 15,
                'order_types'    => ['dine_in', 'takeaway', 'quick_sale'],
                'requires_code'  => true,
                'status'         => 'active',
                'priority'       => 120,
            ]
        );
        $burgerDeal->targets()->delete();
        Product::whereIn('sku', ['BURGER-C', 'BURGER-B'])
            ->pluck('id')
            ->each(fn ($productId) => $burgerDeal->targets()->create([
                'target_type' => 'product',
                'target_id' => $productId,
            ]));

        $fastFoodDeal = Promotion::updateOrCreate(
            ['code' => 'FASTFOOD5'],
            [
                'branch_id'      => $main->id,
                'name'           => '5% Fast Food Category Deal',
                'promotion_type' => 'category',
                'discount_type'  => 'percent',
                'discount_value' => 5,
                'order_types'    => ['dine_in', 'takeaway', 'quick_sale'],
                'requires_code'  => true,
                'status'         => 'active',
                'priority'       => 80,
            ]
        );
        $fastFoodDeal->targets()->delete();
        if ($fastFood = Category::where('code', 'FASTFOOD')->first()) {
            $fastFoodDeal->targets()->create([
                'target_type' => 'category',
                'target_id' => $fastFood->id,
            ]);
        }

        // Void Reasons
        $voidReasons = [
            ['name' => 'Wrong Item', 'reason_type' => 'void'],
            ['name' => 'Customer Changed Mind', 'reason_type' => 'return'],
            ['name' => 'Kitchen Unavailable', 'reason_type' => 'cancel'],
            ['name' => 'Staff Mistake', 'reason_type' => 'void', 'requires_manager_approval' => true],
        ];

        foreach ($voidReasons as $reason) {
            VoidReason::updateOrCreate(
                ['name' => $reason['name']],
                array_merge($reason, ['is_active' => true])
            );
        }

        // Service Charge
        ServiceChargeSetting::updateOrCreate(
            ['branch_id' => $main->id],
            [
                'charge_type'  => 'percent',
                'charge_value' => 5,
                'order_types'  => ['dine_in'],
                'is_taxable'   => false,
                'is_active'    => true,
            ]
        );

        $this->command->line('  Sales controls (promotions, void reasons, service charge) seeded.');
    }

    // ── Receipt Layouts ──────────────────────────────────────────────────────

    private function seedReceiptLayouts(): void
    {
        $branches = Branch::where('status', 'active')->get();

        foreach ($branches as $branch) {
            // Receipt layout
            ReceiptLayoutSetting::updateOrCreate(
                ['branch_id' => $branch->id, 'document_type' => 'receipt'],
                [
                    'paper_size'              => '80mm',
                    'show_logo'               => false,
                    'show_branch_name'        => true,
                    'show_branch_address'     => true,
                    'show_branch_phone'       => true,
                    'show_tax_number'         => true,
                    'show_cashier_name'       => true,
                    'show_customer_name'      => false,
                    'show_table_info'         => true,
                    'show_order_no'           => true,
                    'show_item_codes'         => false,
                    'show_payment_breakdown'  => true,
                    'header_text'             => 'Dreams POS — Demo Store' . "\n" . 'Thank you for shopping with us!',
                    'footer_text'             => 'Exchange within 3 days with receipt.' . "\n" . 'No cash refund.',
                    'font_size'               => 12,
                    'is_active'               => true,
                ]
            );

            // KOT layout
            ReceiptLayoutSetting::updateOrCreate(
                ['branch_id' => $branch->id, 'document_type' => 'kot'],
                [
                    'paper_size'              => '80mm',
                    'show_logo'               => false,
                    'show_branch_name'        => true,
                    'show_branch_address'     => false,
                    'show_branch_phone'       => false,
                    'show_tax_number'         => false,
                    'show_cashier_name'       => true,
                    'show_customer_name'      => false,
                    'show_table_info'         => true,
                    'show_order_no'           => true,
                    'show_item_codes'         => false,
                    'show_payment_breakdown'  => false,
                    'header_text'             => '*** KITCHEN ORDER TICKET ***',
                    'footer_text'             => null,
                    'font_size'               => 12,
                    'kot_font_size'           => 14,
                    'is_active'               => true,
                ]
            );
        }

        $this->command->line('  Receipt & KOT layouts seeded for ' . $branches->count() . ' branch(es).');
    }

    // ── Kitchen Recipes ──────────────────────────────────────────────────────

    private function seedRecipes(): void
    {
        $kg  = Unit::where('code', 'KG')->first();
        $pcs = Unit::where('code', 'PCS')->first();
        $ltr = Unit::where('code', 'L')->first();

        // Recipe 1: Chicken Karahi
        $karahi = Product::where('sku', 'KARAHI-C')->first();
        $chicken = Product::where('sku', 'CHICKEN-KG')->first();
        $onion   = Product::where('sku', 'ONION-KG')->first();
        $tomato  = Product::where('sku', 'TOMATO-KG')->first();
        $oil     = Product::where('sku', 'OIL-LOOSE')->first();

        if ($karahi && $chicken) {
            $recipe1 = Recipe::updateOrCreate(
                ['product_id' => $karahi->id, 'name' => 'Chicken Karahi (Half)'],
                [
                    'yield_quantity'   => 1,
                    'yield_unit_id'    => $pcs?->id,
                    'is_active'        => true,
                    'notes'            => 'Half karahi serving — approx 2 portions',
                    'recipe_no'        => 'REC-0001',
                    'revision_no'      => 1,
                    'review_date'      => now()->toDateString(),
                    'overhead_percent' => 0,
                ]
            );
            // Clear and re-seed ingredients
            $recipe1->ingredients()->delete();
            $sort = 1;
            if ($chicken) $recipe1->ingredients()->create(['product_id' => $chicken->id, 'quantity' => 0.500, 'unit_id' => $kg?->id,  'sort_order' => $sort++]);
            if ($tomato)  $recipe1->ingredients()->create(['product_id' => $tomato->id,  'quantity' => 0.200, 'unit_id' => $kg?->id,  'sort_order' => $sort++]);
            if ($onion)   $recipe1->ingredients()->create(['product_id' => $onion->id,   'quantity' => 0.100, 'unit_id' => $kg?->id,  'sort_order' => $sort++]);
            if ($oil)     $recipe1->ingredients()->create(['product_id' => $oil->id,     'quantity' => 0.050, 'unit_id' => $ltr?->id, 'sort_order' => $sort++]);
        }

        // Recipe 2: Chicken Biryani
        $biryani = Product::where('sku', 'BIRYANI-C')->first();
        $rice    = Product::where('sku', 'RICE-LOOSE')->first();

        if ($biryani && $chicken && $rice) {
            $recipe2 = Recipe::updateOrCreate(
                ['product_id' => $biryani->id, 'name' => 'Chicken Biryani (Plate)'],
                [
                    'yield_quantity'   => 1,
                    'yield_unit_id'    => $pcs?->id,
                    'is_active'        => true,
                    'notes'            => 'Single plate serving',
                    'recipe_no'        => 'REC-0002',
                    'revision_no'      => 1,
                    'review_date'      => now()->toDateString(),
                    'overhead_percent' => 0,
                ]
            );
            $recipe2->ingredients()->delete();
            $sort = 1;
            if ($chicken) $recipe2->ingredients()->create(['product_id' => $chicken->id, 'quantity' => 0.200, 'unit_id' => $kg?->id, 'sort_order' => $sort++]);
            if ($rice)    $recipe2->ingredients()->create(['product_id' => $rice->id,    'quantity' => 0.150, 'unit_id' => $kg?->id, 'sort_order' => $sort++]);
            if ($onion)   $recipe2->ingredients()->create(['product_id' => $onion->id,   'quantity' => 0.050, 'unit_id' => $kg?->id, 'sort_order' => $sort++]);
            if ($oil)     $recipe2->ingredients()->create(['product_id' => $oil->id,     'quantity' => 0.030, 'unit_id' => $ltr?->id, 'sort_order' => $sort++]);
        }

        // Recipe 3: Beef Burger
        $burger  = Product::where('sku', 'BURGER-B')->first();
        $beef    = Product::where('sku', 'BEEF-KG')->first();
        $flour   = Product::where('sku', 'FLOUR-KG')->first();

        if ($burger && $beef) {
            $recipe3 = Recipe::updateOrCreate(
                ['product_id' => $burger->id, 'name' => 'Beef Burger'],
                [
                    'yield_quantity'   => 1,
                    'yield_unit_id'    => $pcs?->id,
                    'is_active'        => true,
                    'notes'            => 'Beef patty burger with bun',
                    'recipe_no'        => 'REC-0003',
                    'revision_no'      => 1,
                    'review_date'      => now()->toDateString(),
                    'overhead_percent' => 0,
                ]
            );
            $recipe3->ingredients()->delete();
            $sort = 1;
            if ($beef)  $recipe3->ingredients()->create(['product_id' => $beef->id,  'quantity' => 0.150, 'unit_id' => $kg?->id, 'sort_order' => $sort++]);
            if ($flour) $recipe3->ingredients()->create(['product_id' => $flour->id, 'quantity' => 0.080, 'unit_id' => $kg?->id, 'sort_order' => $sort++]);
            if ($oil)   $recipe3->ingredients()->create(['product_id' => $oil->id,   'quantity' => 0.020, 'unit_id' => $ltr?->id, 'sort_order' => $sort++]);
        }

        $this->seedTechnosysKarahi();

        $this->command->line('  Recipes seeded: Chicken Karahi, Chicken Biryani, Beef Burger.');
    }

    /**
     * KITCHEN-RECIPE-COST-1 — a full Technosys-style, gram-based "Regular Karahi" recipe
     * so the recipe cost report shows Food Cost + Packing Material sections with realistic
     * per-line costing immediately after seeding. Ingredient quantities are in grams/pieces;
     * each ingredient product carries a purchase unit + pack size so
     * Price/Unit = Cost Price ÷ pack size (e.g. 493 ÷ 1000 g). Idempotent.
     */
    private function seedTechnosysKarahi(): void
    {
        $pcs  = Unit::where('code', 'PCS')->first();
        $groc = Category::where('code', 'GROC')->first();
        $main = Category::where('code', 'MAINCOURSE')->first();
        $unitId = fn (string $code) => Unit::where('code', $code)->first()?->id;

        // [sku, name, stockUnit, purchaseUnit, packSize, cost(per purchase unit), section, qty]
        // KITCHEN-RECIPE-SEED-ALIGN-1: per-ingredient purchase prices aligned to the
        // client's Technosys sample (line-by-line). Grand Total ≈ 840.49, Overall ≈ 30.02%.
        $lines = [
            ['KRH-ING-CHICKEN', 'Karahi Chicken',          'G',   'KG',   1000, 493.00,  'food_cost',        1000],
            ['KRH-ING-OIL',     'Cooking Oil',             'G',   'KG',   1000, 607.64,  'food_cost',        200],
            ['KRH-ING-TOMATO',  'Tomato',                  'G',   'KG',   1000, 105.00,  'food_cost',        500],
            ['KRH-ING-YOGURT',  'Yogurt',                  'G',   'KG',   1000, 330.00,  'food_cost',        80],
            ['KRH-ING-MASALA',  'Karahi Masala Shan Box',  'G',   'PKT',  50,   150.00,  'food_cost',        5],
            ['KRH-ING-BPEPPER', 'Black Pepper Ground',     'G',   'KG',   1000, 2400.00, 'food_cost',        5],
            ['KRH-ING-CUMIN',   'Cumin Powder',            'G',   'KG',   1000, 1800.00, 'food_cost',        5],
            ['KRH-ING-METHI',   'Kasoori Methi',           'G',   'PKT',  120,  188.00,  'food_cost',        5],
            ['KRH-ING-GCHILLI', 'Green Chilli',            'G',   'KG',   1000, 130.00,  'food_cost',        50],
            ['KRH-ING-GINGER',  'Ginger Fresh',            'G',   'KG',   1000, 270.00,  'food_cost',        20],
            ['KRH-ING-RCHILLI', 'Red Chilli Crush',        'G',   'KG',   1000, 860.00,  'food_cost',        5],
            ['KRH-ING-GARLIC',  'Garlic Fresh',            'G',   'KG',   1000, 220.00,  'food_cost',        15],
            ['KRH-ING-CORIAND', 'Coriander Powder',        'G',   'KG',   1000, 560.00,  'food_cost',        5],
            ['KRH-ING-SALT',    'Table Salt',              'G',   'PKT',  800,  60.00,   'food_cost',        10],
            ['KRH-PKG-FOIL',    'Aluminium Foil Wrap 12"', 'PCS', 'ROLL', 50,   2040.00, 'packing_material', 1],
            ['KRH-PKG-CONT',    'Karahi Container 1500ml', 'PCS', 'PCS',  1,    39.38,   'packing_material', 1],
        ];

        // ── Ingredient products (raw material / packaging) ───────────────────
        foreach ($lines as [$sku, $name, $su, $pu, $pack, $cost, $section, $qty]) {
            $isPack = $section === 'packing_material';

            $product = Product::updateOrCreate(
                ['sku' => $sku],
                [
                    'category_id'                  => $groc?->id,
                    'unit_id'                      => $unitId($su),
                    'name'                         => $name,
                    'slug'                         => Str::slug($name),
                    'product_type'                 => 'simple',
                    'item_kind'                    => 'ingredient',
                    'inventory_consumption_method' => 'stock_item',
                    'product_kind'                 => $isPack ? 'packaging_material' : 'raw_material',
                    'is_sellable'                  => false,
                    'is_pos_visible'               => false,
                    'can_be_bom_component'         => false,
                    'can_be_bom_output'            => false,
                    'is_manufactured_finished_good'=> false,
                    'is_purchasable'               => true,
                    'is_stock_tracked'             => true,
                    'has_expiry'                   => false,
                    'requires_batch'               => false,
                    'default_purchase_price'       => $cost,
                    'default_selling_price'        => 0,
                    'purchase_unit_id'             => $unitId($pu),
                    'purchase_pack_size'           => $pack,
                    'status'                       => 'active',
                ]
            );
            $product->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $name]);
            ProductVariant::updateOrCreate(
                ['sku' => $sku],
                [
                    'product_id' => $product->id, 'name' => $name,
                    'purchase_price' => $cost, 'selling_price' => 0,
                    'reorder_level' => 5, 'reorder_quantity' => 20,
                    'is_default' => true, 'is_active' => true,
                ]
            );
        }

        // ── Finished product (recipe-based, POS sale item) ───────────────────
        $finished = Product::updateOrCreate(
            ['sku' => 'KARAHI-REG'],
            [
                'category_id'                  => $main?->id,
                'unit_id'                      => $pcs?->id,
                'name'                         => 'Regular Karahi',
                'slug'                         => Str::slug('Regular Karahi'),
                'product_type'                 => 'recipe',
                'item_kind'                    => 'finished_good',
                'inventory_consumption_method' => 'recipe',
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
                'default_purchase_price'       => 0,
                'default_selling_price'        => 2800,
                'purchase_unit_id'             => $pcs?->id,
                'purchase_pack_size'           => 1,
                'status'                       => 'active',
            ]
        );
        $finished->translations()->updateOrCreate(['language_code' => 'en'], ['name' => 'Regular Karahi']);
        ProductVariant::updateOrCreate(
            ['sku' => 'KARAHI-REG'],
            [
                'product_id' => $finished->id, 'name' => 'Regular Karahi',
                'purchase_price' => 0, 'selling_price' => 2800,
                'reorder_level' => 0, 'reorder_quantity' => 0,
                'is_default' => true, 'is_active' => true,
            ]
        );

        // ── Opening stock for the new ingredients (so Regular Karahi is sellable).
        // Self-contained + guarded by a fixed adjustment_no so re-seeds don't double-post.
        $mainBranch = Branch::where('code', 'MAIN')->first();
        if ($mainBranch && ! StockAdjustment::where('adjustment_no', 'ADJ-KRH-OPEN-MAIN')->exists()) {
            $adjustment = StockAdjustment::create([
                'adjustment_no'   => 'ADJ-KRH-OPEN-MAIN',
                'branch_id'       => $mainBranch->id,
                'adjustment_type' => 'opening',
                'adjustment_date' => now()->toDateString(),
                'status'          => 'posted',
                'posted_at'       => now(),
                'notes'           => 'Karahi recipe ingredients opening stock (demo seed)',
            ]);

            // ~30 servings worth, in each product's stock unit (grams / pieces).
            $openQty = [
                'KRH-ING-CHICKEN' => 50000, 'KRH-ING-OIL' => 20000, 'KRH-ING-TOMATO' => 30000,
                'KRH-ING-YOGURT' => 5000,   'KRH-ING-MASALA' => 2000, 'KRH-ING-BPEPPER' => 1000,
                'KRH-ING-CUMIN' => 1000,    'KRH-ING-METHI' => 1000,  'KRH-ING-GCHILLI' => 5000,
                'KRH-ING-GINGER' => 3000,   'KRH-ING-RCHILLI' => 1000, 'KRH-ING-GARLIC' => 3000,
                'KRH-ING-CORIAND' => 1000,  'KRH-ING-SALT' => 5000,   'KRH-PKG-FOIL' => 200,
                'KRH-PKG-CONT' => 200,
            ];

            foreach ($lines as [$sku, $name, $su, $pu, $pack, $cost]) {
                $product = Product::where('sku', $sku)->first();
                if (! $product) continue;
                $variant   = $this->inv->resolveVariant($product, null);
                $qty       = (float) ($openQty[$sku] ?? 0);
                $unitCost  = $pack > 0 ? round($cost / $pack, 4) : $cost; // per stock unit (per gram/pc)
                if ($qty <= 0) continue;

                $adjustment->lines()->create([
                    'product_id'         => $product->id,
                    'product_variant_id' => $variant?->id,
                    'quantity'           => $qty,
                    'unit_cost'          => $unitCost,
                ]);
                $ledger = $this->inv->postIn(
                    branch: $mainBranch,
                    product: $product,
                    variant: $variant,
                    quantity: $qty,
                    unitCost: $unitCost,
                    movementType: 'opening_stock',
                    referenceType: 'stock_adjustment',
                    referenceId: $adjustment->id,
                    referenceNo: $adjustment->adjustment_no,
                    expiryDate: null,
                    notes: 'Karahi ingredient opening stock',
                );
                unset($ledger);
            }
        }

        // ── Recipe with Food Cost + Packing Material sections ────────────────
        $recipe = Recipe::updateOrCreate(
            ['product_id' => $finished->id, 'name' => 'Regular Karahi'],
            [
                'yield_quantity'   => 1,
                'yield_unit_id'    => $pcs?->id,
                'is_active'        => true,
                'notes'            => 'Technosys-style karahi recipe — food cost + packing material.',
                'doc_no'           => 'DOC-KRH-01',
                'recipe_no'        => 'REC-0010',
                'revision_no'      => 1,
                'review_date'      => now()->toDateString(),
                'overhead_percent' => 4.48,
            ]
        );

        // This seeder owns these lines: delete + recreate intentionally (demo recipe only).
        $recipe->ingredients()->delete();
        $sort = 1;
        foreach ($lines as [$sku, $name, $su, $pu, $pack, $cost, $section, $qty]) {
            $ingredient = Product::where('sku', $sku)->first();
            if (! $ingredient) continue;
            $variant = $ingredient->defaultVariant()->first();
            $recipe->ingredients()->create([
                'product_id'         => $ingredient->id,
                'product_variant_id' => $variant?->id,
                'quantity'           => $qty,
                'unit_id'            => $unitId($su),
                'line_section'       => $section,
                // KITCHEN-RECIPE-ORDER-TYPE-1: packing only applies to takeaway/delivery;
                // food cost applies to all order types (null).
                'applicable_order_types' => $section === 'packing_material' ? ['takeaway', 'delivery'] : null,
                'sort_order'         => $sort++,
            ]);
        }

        $this->command->line('  Technosys Karahi recipe seeded: ' . count($lines) . ' lines (food cost + packing).');
    }

    private function seedManufacturingDemoProducts(): void
    {
        $groc = Category::where('code', 'GROC')->first();
        $pcs  = Unit::where('code', 'PCS')->first();
        $kg   = Unit::where('code', 'KG')->first();
        $pkt  = Unit::where('code', 'PKT')->first();

        $products = [
            [
                'sku' => 'RAW-FLOUR-IND',
                'name' => 'Industrial Flour 25kg',
                'unit' => $kg,
                'kind' => 'raw_material',
                'buy' => 3200,
                'sell' => 0,
                'component' => true,
                'output' => false,
                'mfg_fg' => false,
                'purchasable' => true,
            ],
            [
                'sku' => 'RAW-SUGAR-IND',
                'name' => 'Industrial Sugar 25kg',
                'unit' => $kg,
                'kind' => 'raw_material',
                'buy' => 4200,
                'sell' => 0,
                'component' => true,
                'output' => false,
                'mfg_fg' => false,
                'purchasable' => true,
            ],
            [
                'sku' => 'PKG-CARTON-MFG',
                'name' => 'Manufacturing Carton Box',
                'unit' => $pcs,
                'kind' => 'packaging_material',
                'buy' => 65,
                'sell' => 0,
                'component' => true,
                'output' => false,
                'mfg_fg' => false,
                'purchasable' => true,
            ],
            [
                'sku' => 'SFG-DOUGH-BATCH',
                'name' => 'Semi Finished Dough Batch',
                'unit' => $kg,
                'kind' => 'semi_finished',
                'buy' => 0,
                'sell' => 0,
                'component' => true,
                'output' => true,
                'mfg_fg' => false,
                'purchasable' => false,
            ],
            [
                'sku' => 'FG-BISCUIT-MFG',
                'name' => 'Manufactured Biscuit Box',
                'unit' => $pkt,
                'kind' => 'finished_good',
                'buy' => 0,
                'sell' => 260,
                'component' => false,
                'output' => true,
                'mfg_fg' => true,
                'purchasable' => false,
            ],
        ];

        foreach ($products as $pd) {
            $product = Product::updateOrCreate(
                ['sku' => $pd['sku']],
                [
                    'category_id'                  => $groc?->id,
                    'unit_id'                      => $pd['unit']?->id,
                    'name'                         => $pd['name'],
                    'slug'                         => Str::slug($pd['name']),
                    'product_type'                 => 'simple',
                    'item_kind'                    => $pd['mfg_fg'] || $pd['output'] ? 'finished_good' : 'ingredient',
                    'inventory_consumption_method' => 'stock_item',
                    'product_kind'                 => $pd['kind'],
                    'is_sellable'                  => (bool) $pd['mfg_fg'],
                    'is_pos_visible'               => false,
                    'can_be_bom_component'         => (bool) $pd['component'],
                    'can_be_bom_output'            => (bool) $pd['output'],
                    'is_manufactured_finished_good'=> (bool) $pd['mfg_fg'],
                    'is_purchasable'               => (bool) $pd['purchasable'],
                    'is_stock_tracked'             => true,
                    'has_expiry'                   => false,
                    'requires_batch'               => false,
                    'default_purchase_price'       => $pd['buy'],
                    'default_selling_price'        => $pd['sell'],
                    'purchase_unit_id'             => $pd['unit']?->id,
                    'purchase_pack_size'           => 1,
                    'status'                       => 'active',
                ]
            );

            $product->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $pd['name']]);
            ProductVariant::updateOrCreate(
                ['sku' => $pd['sku']],
                [
                    'product_id' => $product->id,
                    'name' => $pd['name'],
                    'purchase_price' => $pd['buy'],
                    'selling_price' => $pd['sell'],
                    'reorder_level' => 5,
                    'reorder_quantity' => 20,
                    'is_default' => true,
                    'is_active' => true,
                ]
            );
        }

        $this->command->line('  Manufacturing demo products seeded: ' . count($products));
    }

    private function seedManufacturingPostingSettings(): void
    {
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();

        $accountId = fn (string $code) => Account::where('code', $code)->value('id');
        $userId = User::orderBy('id')->value('id');

        ManufacturingPostingSetting::updateOrCreate(
            ['branch_id' => null],
            [
                'is_enabled' => true,
                'raw_material_inventory_account_id' => $accountId('1410'),
                'wip_inventory_account_id' => $accountId('1420'),
                'finished_goods_inventory_account_id' => $accountId('1430'),
                'manufacturing_overhead_account_id' => $accountId('1490'),
                'direct_labour_account_id' => $accountId('6210'),
                'scrap_expense_account_id' => $accountId('6900'),
                'rework_expense_account_id' => $accountId('6910'),
                'production_variance_account_id' => $accountId('5300'),
                'manufactured_cogs_account_id' => $accountId('5310'),
                'inventory_adjustment_account_id' => $accountId('6920'),
                'negative_stock_policy' => 'block',
                'costing_method' => 'moving_average',
                'fg_cost_source' => 'wip_actual',
                'updated_by_user_id' => $userId,
                'created_by_user_id' => $userId,
            ]
        );

        $this->command->line('  Manufacturing posting settings seeded and mapped.');
    }

    /**
     * Master-side demo billing sample: one ISSUED (unpaid) invoice for the demo
     * tenant so the central Invoices list and tenant billing portal have data.
     * Intentionally NOT paid — keeps the demo subscription in trial so the
     * trial banner + module/limit demos remain meaningful. Idempotent.
     */
    private function seedDemoBilling(Tenant $tenant): void
    {
        $subscription = $tenant->subscription;
        $plan = $subscription?->plan;

        if (!$subscription || !$plan) {
            $this->command->line('  Demo billing skipped (no subscription/plan).');
            return;
        }

        $total = (float) $plan->price;

        SubscriptionInvoice::updateOrCreate(
            ['invoice_no' => 'INV-DEMO-0001'],
            [
                'tenant_id'       => $tenant->id,
                'subscription_id' => $subscription->id,
                'plan_id'         => $plan->id,
                'invoice_type'    => 'subscription',
                'status'          => 'issued',
                'currency_code'   => $plan->currency_code ?? 'PKR',
                'subtotal'        => $total,
                'discount_amount' => 0,
                'tax_amount'      => 0,
                'total_amount'    => $total,
                'paid_amount'     => 0,
                'balance_amount'  => $total,
                'period_start'    => now()->toDateString(),
                'period_end'      => ($plan->billing_period === 'yearly' ? now()->addYear() : now()->addMonth())->toDateString(),
                'due_date'        => now()->addDays(7)->toDateString(),
                'issued_at'       => now(),
                'notes'           => 'Demo sample invoice (unpaid).',
            ]
        );

        $this->command->line('  Demo billing seeded: 1 issued invoice (INV-DEMO-0001).');
    }
}
