<?php

namespace Database\Seeders\Demos;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Customer;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductBarcode;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SalesService;
use Illuminate\Support\Str;

/**
 * Rich Retail demo data for the retaildemo tenant (retail_starter plan).
 * Modules: pos, catalog, printing, reports, users_roles — no purchasing,
 * stock count, transfers, or restaurant workflows.
 *
 * MUST run inside an already-activated tenant DB (the demo:seed command
 * activates it first). Idempotent via updateOrCreate + guard checks.
 */
class RetailDemoSeeder
{
    private InventoryService $inv;
    private SalesService $sales;
    private array $counts = [];

    public function run(): array
    {
        $this->inv   = app(InventoryService::class);
        $this->sales = app(SalesService::class);

        $this->seedUnits();
        $this->seedCategories();
        $this->seedProducts();
        $this->seedCustomers();
        $this->seedOpeningStock();
        $this->seedSales();

        return $this->counts;
    }

    private function seedUnits(): void
    {
        $units = [
            ['code' => 'PCS', 'name' => 'Piece',      'unit_type' => 'quantity', 'base_factor' => 1,     'is_base' => true],
            ['code' => 'BTL', 'name' => 'Bottle',     'unit_type' => 'quantity', 'base_factor' => 1,     'is_base' => false],
            ['code' => 'PKT', 'name' => 'Packet',     'unit_type' => 'quantity', 'base_factor' => 1,     'is_base' => false],
            ['code' => 'DOZ', 'name' => 'Dozen',      'unit_type' => 'quantity', 'base_factor' => 12,    'is_base' => false],
            ['code' => 'KG',  'name' => 'Kilogram',   'unit_type' => 'weight',   'base_factor' => 1,     'is_base' => true],
            ['code' => 'L',   'name' => 'Litre',      'unit_type' => 'volume',   'base_factor' => 1,     'is_base' => true],
        ];
        foreach ($units as $u) {
            Unit::updateOrCreate(['code' => $u['code']], array_merge($u, ['is_active' => true]));
        }
        $this->counts['units'] = count($units);
    }

    private function seedCategories(): void
    {
        $cats = [
            ['code' => 'GROC',  'name' => 'Grocery',        'sort_order' => 1],
            ['code' => 'BEV',   'name' => 'Beverages',      'sort_order' => 2],
            ['code' => 'SNACK', 'name' => 'Snacks',         'sort_order' => 3],
            ['code' => 'HOUSE', 'name' => 'Household',      'sort_order' => 4],
            ['code' => 'PCARE', 'name' => 'Personal Care',  'sort_order' => 5],
            ['code' => 'FRESH', 'name' => 'Fresh Items',    'sort_order' => 6],
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

    private function products(): array
    {
        // [sku(barcode), name, category code, unit code, buy, sell, taxable]
        return [
            ['890100000001', 'Basmati Rice 5kg',     'GROC',  'PKT', 850, 1100, false],
            ['890100000002', 'Sugar 1kg',            'GROC',  'PKT', 120, 160,  false],
            ['890100000003', 'Cooking Oil 1L',       'GROC',  'BTL', 380, 480,  false],
            ['890100000004', 'Tea Pack 250g',        'GROC',  'PKT', 180, 250,  false],
            ['890100000005', 'Iodized Salt 1kg',     'GROC',  'PKT', 60,  85,   false],
            ['890100000006', 'Mineral Water 1.5L',   'BEV',   'BTL', 30,  50,   false],
            ['890100000007', 'Cola Bottle 1.5L',     'BEV',   'BTL', 90,  130,  true],
            ['890100000008', 'Orange Juice 1L',      'BEV',   'BTL', 150, 210,  true],
            ['890100000009', 'Energy Drink 250ml',   'BEV',   'PCS', 120, 180,  true],
            ['890100000010', 'Milk Pack 1L',         'FRESH', 'BTL', 120, 150,  false],
            ['890100000011', 'Yogurt Cup 500g',      'FRESH', 'PKT', 90,  130,  false],
            ['890100000012', 'Bread Loaf',           'FRESH', 'PCS', 80,  120,  false],
            ['890100000013', 'Eggs Dozen',           'FRESH', 'DOZ', 240, 300,  false],
            ['890100000014', 'Potato Chips',         'SNACK', 'PKT', 65,  95,   true],
            ['890100000015', 'Biscuits Pack',        'SNACK', 'PKT', 55,  80,   true],
            ['890100000016', 'Chocolate Bar',        'SNACK', 'PCS', 90,  140,  true],
            ['890100000017', 'Salted Nuts 200g',     'SNACK', 'PKT', 220, 300,  true],
            ['890100000018', 'Dishwash Liquid 500ml','HOUSE', 'BTL', 160, 230,  true],
            ['890100000019', 'Laundry Detergent 1kg','HOUSE', 'PKT', 280, 380,  true],
            ['890100000020', 'Tissue Box',           'HOUSE', 'PCS', 110, 160,  true],
            ['890100000021', 'Shampoo 200ml',        'PCARE', 'BTL', 240, 330,  true],
            ['890100000022', 'Toothpaste 100g',      'PCARE', 'PCS', 130, 185,  true],
            ['890100000023', 'Soap Bar',             'PCARE', 'PCS', 70,  100,  true],
            ['890100000024', 'Apples',               'FRESH', 'KG',  220, 320,  false],
            ['890100000025', 'Bananas',              'FRESH', 'KG',  90,  140,  false],
        ];
    }

    private function seedProducts(): void
    {
        $count = 0;
        foreach ($this->products() as [$barcode, $name, $catCode, $unitCode, $buy, $sell, $taxable]) {
            $category = Category::where('code', $catCode)->first();
            $unit     = Unit::where('code', $unitCode)->first();

            $product = Product::updateOrCreate(
                ['sku' => $barcode],
                [
                    'category_id'                  => $category?->id,
                    'unit_id'                      => $unit?->id,
                    'name'                         => $name,
                    'slug'                         => Str::slug($name),
                    'product_type'                 => 'simple',
                    'item_kind'                    => 'finished_good',
                    'inventory_consumption_method' => 'stock_item',
                    'is_sellable'                  => true,
                    'is_purchasable'               => true,
                    'is_stock_tracked'             => true,
                    'has_expiry'                   => false,
                    'requires_batch'               => false,
                    'default_purchase_price'       => $buy,
                    'default_selling_price'        => $sell,
                    'is_taxable'                   => $taxable,
                    'tax_rate_percent'             => $taxable ? 17 : null,
                    'status'                       => 'active',
                ]
            );
            $product->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $name]);

            $variant = ProductVariant::updateOrCreate(
                ['sku' => $barcode],
                [
                    'product_id'       => $product->id,
                    'name'             => $name,
                    'barcode'          => $barcode,
                    'purchase_price'   => $buy,
                    'selling_price'    => $sell,
                    'reorder_level'    => 5,
                    'reorder_quantity' => 20,
                    'is_default'       => true,
                    'is_active'        => true,
                ]
            );

            ProductBarcode::updateOrCreate(
                ['barcode' => $barcode],
                ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'barcode_type' => 'manual', 'is_primary' => true]
            );
            $count++;
        }
        $this->counts['products'] = $count;
    }

    private function seedCustomers(): void
    {
        $customers = [
            ['code' => 'RC-001', 'name' => 'Ahmed Khan',          'phone' => '0300-1112233'],
            ['code' => 'RC-002', 'name' => 'Sara Ali',            'phone' => '0301-2223344'],
            ['code' => 'RC-003', 'name' => 'Usman Retail Buyer',  'phone' => '0302-3334455'],
            ['code' => 'RC-004', 'name' => 'Corporate Pantry',    'phone' => '0303-4445566'],
            ['code' => 'RC-005', 'name' => 'Hina Stores',         'phone' => '0304-5556677'],
        ];
        foreach ($customers as $c) {
            Customer::updateOrCreate(['code' => $c['code']], array_merge($c, ['status' => 'active']));
        }
        $this->counts['customers'] = count($customers);
    }

    private function seedOpeningStock(): void
    {
        $branch = Branch::query()->orderBy('id')->first();
        if (! $branch) {
            $this->counts['opening_stock'] = 'skipped (no branch)';
            return;
        }

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
            'notes'           => 'Retail demo opening stock',
        ]);

        $lines = 0;
        foreach ($this->products() as [$barcode, $name, $catCode, $unitCode, $buy, $sell, $taxable]) {
            $product = Product::where('sku', $barcode)->first();
            if (! $product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $qty = $unitCode === 'KG' ? 25.000 : 60;

            $line = $adjustment->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity'           => $qty,
                'unit_cost'          => $buy,
            ]);

            $ledger = $this->inv->postIn(
                branch: $branch,
                product: $product,
                variant: $variant,
                quantity: $qty,
                unitCost: $buy,
                movementType: 'opening_stock',
                referenceType: 'stock_adjustment',
                referenceId: $adjustment->id,
                referenceNo: $adjustment->adjustment_no,
                notes: 'Retail demo opening stock',
            );
            $line->update(['inventory_batch_id' => $ledger->inventory_batch_id]);
            $lines++;
        }
        $this->counts['opening_stock'] = "{$lines} lines";
    }

    private function seedSales(): void
    {
        if (SalesOrder::count() > 0) {
            $this->counts['sales'] = 'already exist';
            return;
        }

        $branch = Branch::query()->orderBy('id')->first();
        $owner  = User::where('email', 'owner@retaildemo.com')->first() ?? User::query()->orderBy('id')->first();
        $cash   = PaymentMethod::where('method_type', 'cash')->first();
        $card   = PaymentMethod::where('method_type', 'card')->first();
        $customers = Customer::whereNotNull('code')->get()->keyBy('code');

        if (! $branch || ! $owner || ! $cash) {
            $this->counts['sales'] = 'skipped (missing branch/user/payment method)';
            return;
        }

        $sales = [
            ['cust' => null,     'pay' => $cash, 'days' => 0, 'lines' => [['890100000007', 2, 130], ['890100000014', 3, 95]]],
            ['cust' => 'RC-001', 'pay' => $cash, 'days' => 0, 'lines' => [['890100000010', 2, 150], ['890100000012', 1, 120], ['890100000013', 1, 300]]],
            ['cust' => null,     'pay' => $card, 'days' => 1, 'lines' => [['890100000001', 1, 1100], ['890100000003', 1, 480]]],
            ['cust' => 'RC-002', 'pay' => $cash, 'days' => 1, 'lines' => [['890100000015', 4, 80], ['890100000016', 3, 140], ['890100000006', 5, 50]]],
            ['cust' => null,     'pay' => $cash, 'days' => 2, 'lines' => [['890100000021', 1, 330], ['890100000022', 2, 185], ['890100000023', 3, 100]]],
            ['cust' => 'RC-004', 'pay' => $card, 'days' => 2, 'lines' => [['890100000018', 2, 230], ['890100000019', 1, 380], ['890100000020', 4, 160]]],
            ['cust' => null,     'pay' => $cash, 'days' => 3, 'lines' => [['890100000024', 2, 320], ['890100000025', 3, 140]]],
            ['cust' => 'RC-003', 'pay' => $cash, 'days' => 4, 'lines' => [['890100000002', 3, 160], ['890100000004', 2, 250], ['890100000005', 2, 85]]],
            ['cust' => null,     'pay' => $card, 'days' => 5, 'lines' => [['890100000008', 2, 210], ['890100000009', 4, 180]]],
            ['cust' => 'RC-005', 'pay' => $cash, 'days' => 6, 'lines' => [['890100000017', 2, 300], ['890100000011', 3, 130]]],
        ];

        $count = 0;
        foreach ($sales as $sd) {
            try {
                $this->createSale($branch, $owner, $sd, $customers, $cash);
                $count++;
            } catch (\Throwable $e) {
                // Skip a sale that can't post (e.g., insufficient stock) — don't abort the seed.
            }
        }
        $this->counts['sales'] = "{$count} created";
    }

    private function createSale(Branch $branch, User $owner, array $sd, $customers, PaymentMethod $cash): void
    {
        $saleDate = now()->subDays($sd['days']);
        $subtotal = 0;
        foreach ($sd['lines'] as [$barcode, $qty, $price]) {
            $subtotal += $qty * $price;
        }

        $sale = SalesOrder::create([
            'sale_no'            => 'SO-' . $saleDate->format('Ymd') . '-' . str_pad(SalesOrder::count() + 1, 4, '0', STR_PAD_LEFT),
            'branch_id'          => $branch->id,
            'customer_id'        => isset($sd['cust']) && $sd['cust'] ? ($customers[$sd['cust']]->id ?? null) : null,
            'order_source'       => 'manual',
            'order_type'         => 'quick_sale',
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

        foreach ($sd['lines'] as [$barcode, $qty, $price]) {
            $product = Product::where('sku', $barcode)->first();
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

        $method = $sd['pay'];
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
