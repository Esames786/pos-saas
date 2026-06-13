<?php

namespace Database\Seeders\Demos;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockTransfer;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierPayment;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Str;

/**
 * Rich Inventory demo data for the inventorydemo tenant (inventory_store plan).
 * Modules: pos, catalog, inventory, stock_count, purchasing, printing, reports,
 * users_roles. Seeds 2 branches (within the 2-branch plan limit), suppliers,
 * products, opening stock (with low-stock examples), a full purchase flow, and
 * an inter-branch transfer. MUST run inside an activated tenant DB.
 *
 * Stock count workflow is intentionally not seeded here (its post() path is
 * complex); reported as deferred.
 */
class InventoryDemoSeeder
{
    private InventoryService $inv;
    private array $counts = [];
    private ?Branch $main = null;
    private ?Branch $warehouse = null;

    public function run(): array
    {
        $this->inv = app(InventoryService::class);

        $this->seedUnits();
        $this->seedBranches();
        $this->seedCategories();
        $this->seedSuppliers();
        $this->seedProducts();
        $this->seedOpeningStock();
        $this->seedPurchaseFlow();
        $this->seedStockTransfer();

        $this->counts['stock_count'] = 'deferred (complex post() path)';

        return $this->counts;
    }

    private function seedUnits(): void
    {
        $units = [
            ['code' => 'PCS', 'name' => 'Piece',    'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true],
            ['code' => 'BOX', 'name' => 'Box',      'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => false],
            ['code' => 'CTN', 'name' => 'Carton',   'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => false],
            ['code' => 'SACK','name' => 'Sack',     'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => false],
            ['code' => 'CRT', 'name' => 'Crate',    'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => false],
            ['code' => 'KG',  'name' => 'Kilogram', 'unit_type' => 'weight',   'base_factor' => 1, 'is_base' => true],
        ];
        foreach ($units as $u) {
            Unit::updateOrCreate(['code' => $u['code']], array_merge($u, ['is_active' => true]));
        }
        $this->counts['units'] = count($units);
    }

    private function seedBranches(): void
    {
        // Main Branch already exists from base provisioning; add a Warehouse
        // (inventory_store allows 2 branches).
        $this->main = Branch::query()->orderBy('id')->first();

        $this->warehouse = Branch::updateOrCreate(
            ['code' => 'WARE'],
            [
                'name'    => 'Warehouse',
                'address' => 'Central Warehouse, Industrial Area',
                'phone'   => '042-37000000',
                'email'   => 'warehouse@inventorydemo.com',
                'status'  => 'active',
            ]
        );
        $this->counts['branches'] = Branch::count();
    }

    private function seedCategories(): void
    {
        $cats = [
            ['code' => 'GBULK', 'name' => 'Grocery Bulk',     'sort_order' => 1],
            ['code' => 'BEVS',  'name' => 'Beverages',        'sort_order' => 2],
            ['code' => 'HHOLD', 'name' => 'Household Stock',  'sort_order' => 3],
            ['code' => 'FPROD', 'name' => 'Fresh Produce',    'sort_order' => 4],
            ['code' => 'PACK',  'name' => 'Packaging',        'sort_order' => 5],
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

    private function seedSuppliers(): void
    {
        $suppliers = [
            ['code' => 'INV-SUP-001', 'name' => 'Metro Wholesale',         'contact_person' => 'Tariq Hassan',  'phone' => '042-37100001', 'payment_terms_days' => 30, 'email' => 'b2b@metro.pk'],
            ['code' => 'INV-SUP-002', 'name' => 'Fresh Farm Supplies',     'contact_person' => 'Ghulam Nabi',   'phone' => '042-37100002', 'payment_terms_days' => 7],
            ['code' => 'INV-SUP-003', 'name' => 'Pak Beverage Traders',    'contact_person' => 'Rizwan Mirza',  'phone' => '042-37100003', 'payment_terms_days' => 15, 'email' => 'orders@pakbev.pk'],
            ['code' => 'INV-SUP-004', 'name' => 'Household Distributors',  'contact_person' => 'Imran Sheikh',  'phone' => '042-37100004', 'payment_terms_days' => 30],
            ['code' => 'INV-SUP-005', 'name' => 'Packaging World',         'contact_person' => 'Sajid Ali',     'phone' => '042-37100005', 'payment_terms_days' => 0],
        ];
        foreach ($suppliers as $s) {
            Supplier::updateOrCreate(
                ['code' => $s['code']],
                array_merge($s, ['status' => 'active', 'opening_balance' => 0, 'current_balance' => 0])
            );
        }
        $this->counts['suppliers'] = count($suppliers);
    }

    private function products(): array
    {
        // [sku, name, category, unit, buy, sell, reorder, opening, low]
        return [
            ['INV-RICE25',  'Rice Sack 25kg',          'GBULK', 'SACK', 4200, 4800, 10, 60,  false],
            ['INV-FLOUR20', 'Flour Bag 20kg',          'GBULK', 'SACK', 1900, 2300, 10, 50,  false],
            ['INV-SUGAR10', 'Sugar Bag 10kg',          'GBULK', 'SACK', 1150, 1400, 10, 45,  false],
            ['INV-OILCTN',  'Cooking Oil Carton',      'GBULK', 'CTN',  4500, 5200, 8,  30,  false],
            ['INV-WATERCTN','Mineral Water Carton',    'BEVS',  'CTN',  300,  420,  12, 80,  false],
            ['INV-SOFTCTN', 'Soft Drink Carton',       'BEVS',  'CTN',  720,  960,  12, 55,  false],
            ['INV-MILKCTN', 'Milk Carton',             'BEVS',  'CTN',  1440, 1800, 20, 6,   true],
            ['INV-TEACTN',  'Tea Carton',              'BEVS',  'CTN',  2160, 2600, 8,  24,  false],
            ['INV-FRIESCTN','Frozen Fries Carton',     'GBULK', 'CTN',  3200, 3800, 6,  18,  false],
            ['INV-NUGGCTN', 'Chicken Nuggets Carton',  'GBULK', 'CTN',  3600, 4200, 6,  15,  false],
            ['INV-APPLECR', 'Apples Crate',            'FPROD', 'CRT',  1800, 2200, 6,  20,  false],
            ['INV-BANANACR','Bananas Crate',           'FPROD', 'CRT',  900,  1200, 6,  18,  false],
            ['INV-TOMATOCR','Tomatoes Crate',          'FPROD', 'CRT',  1100, 1500, 6,  16,  false],
            ['INV-ONIONSK', 'Onions Sack',             'FPROD', 'SACK', 1600, 2000, 6,  22,  false],
            ['INV-POTATOSK','Potatoes Sack',           'FPROD', 'SACK', 1400, 1800, 6,  25,  false],
            ['INV-DETCTN',  'Detergent Carton',        'HHOLD', 'CTN',  3360, 4000, 8,  28,  false],
            ['INV-DISHCTN', 'Dishwash Carton',         'HHOLD', 'CTN',  1920, 2400, 8,  26,  false],
            ['INV-SHMPCTN', 'Shampoo Carton',          'HHOLD', 'CTN',  2880, 3500, 8,  20,  false],
            ['INV-SOAPCTN', 'Soap Carton',             'HHOLD', 'CTN',  1680, 2100, 8,  30,  false],
            ['INV-TPASTECTN','Toothpaste Carton',      'HHOLD', 'CTN',  1560, 1950, 8,  24,  false],
            ['INV-PAPERBAG','Paper Bags Pack',         'PACK',  'BOX',  450,  650,  15, 4,   true],
            ['INV-RCPTROLL','Receipt Rolls Box',       'PACK',  'BOX',  900,  1200, 12, 3,   true],
            ['INV-CUPS',    'Plastic Cups Box',        'PACK',  'BOX',  600,  850,  10, 18,  false],
            ['INV-CONTPK',  'Food Containers Pack',    'PACK',  'BOX',  750,  1050, 10, 16,  false],
            ['INV-NAPKCTN', 'Napkins Carton',          'PACK',  'CTN',  1200, 1600, 10, 20,  false],
        ];
    }

    private function seedProducts(): void
    {
        $count = 0;
        foreach ($this->products() as [$sku, $name, $catCode, $unitCode, $buy, $sell, $reorder, $opening, $low]) {
            $category = Category::where('code', $catCode)->first();
            $unit     = Unit::where('code', $unitCode)->first();

            $product = Product::updateOrCreate(
                ['sku' => $sku],
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
                    'is_taxable'                   => false,
                    'status'                       => 'active',
                ]
            );
            $product->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $name]);

            ProductVariant::updateOrCreate(
                ['sku' => $sku],
                [
                    'product_id'       => $product->id,
                    'name'             => $name,
                    'purchase_price'   => $buy,
                    'selling_price'    => $sell,
                    'reorder_level'    => $reorder,
                    'reorder_quantity' => $reorder * 2,
                    'is_default'       => true,
                    'is_active'        => true,
                ]
            );
            $count++;
        }
        $this->counts['products'] = $count;
    }

    private function seedOpeningStock(): void
    {
        $this->counts['opening_stock'] = [];
        foreach ([[$this->main, 'Main'], [$this->warehouse, 'Warehouse']] as [$branch, $label]) {
            if (! $branch) continue;
            if (StockAdjustment::where('branch_id', $branch->id)->where('adjustment_type', 'opening')->exists()) {
                $this->counts['opening_stock'][$label] = 'already posted';
                continue;
            }

            $adjustment = StockAdjustment::create([
                'adjustment_no'   => 'ADJ-OPEN-' . $branch->code . '-' . now()->format('YmdHis'),
                'branch_id'       => $branch->id,
                'adjustment_type' => 'opening',
                'adjustment_date' => now()->toDateString(),
                'status'          => 'posted',
                'posted_at'       => now(),
                'notes'           => "Inventory demo opening stock — {$label}",
            ]);

            $lines = 0;
            foreach ($this->products() as [$sku, $name, $catCode, $unitCode, $buy, $sell, $reorder, $opening, $low]) {
                $product = Product::where('sku', $sku)->first();
                if (! $product) continue;
                $variant = $this->inv->resolveVariant($product, null);
                // Warehouse holds more; low-stock items stay low at Main.
                $qty = $label === 'Warehouse' ? $opening + 20 : $opening;

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
                    notes: "Inventory demo opening — {$label}",
                );
                $line->update(['inventory_batch_id' => $ledger->inventory_batch_id]);
                $lines++;
            }
            $this->counts['opening_stock'][$label] = "{$lines} lines";
        }
    }

    private function seedPurchaseFlow(): void
    {
        if (! $this->main) {
            $this->counts['purchases'] = 'skipped (no branch)';
            return;
        }
        if (PurchaseOrder::count() > 0) {
            $this->counts['purchases'] = 'already exist';
            return;
        }

        $owner = User::where('email', 'owner@inventorydemo.com')->first() ?? User::query()->orderBy('id')->first();
        $sup1  = Supplier::where('code', 'INV-SUP-001')->first();
        $sup2  = Supplier::where('code', 'INV-SUP-002')->first();

        // PO 1: fully received, billed & paid
        $po1Lines = [
            ['sku' => 'INV-RICE25',  'qty' => 20, 'cost' => 4200],
            ['sku' => 'INV-FLOUR20', 'qty' => 25, 'cost' => 1900],
            ['sku' => 'INV-SUGAR10', 'qty' => 20, 'cost' => 1150],
        ];
        $po1  = $this->createPO('INV-PO-001', $sup1, $po1Lines, $owner, now()->subDays(18));
        $grn1 = $this->createGRN($po1, $po1Lines, $owner, now()->subDays(16));
        $bill1 = $this->createBill($grn1, $po1Lines, $owner, now()->subDays(15));
        $this->createSupplierPayment($bill1, $owner, now()->subDays(8), 'Full payment — INV-PO-001');

        // PO 2: received, billed, partially paid
        $po2Lines = [
            ['sku' => 'INV-WATERCTN', 'qty' => 40, 'cost' => 300],
            ['sku' => 'INV-SOFTCTN',  'qty' => 30, 'cost' => 720],
            ['sku' => 'INV-MILKCTN',  'qty' => 12, 'cost' => 1440],
        ];
        $po2  = $this->createPO('INV-PO-002', $sup2 ?? $sup1, $po2Lines, $owner, now()->subDays(10));
        $grn2 = $this->createGRN($po2, $po2Lines, $owner, now()->subDays(8));
        $bill2 = $this->createBill($grn2, $po2Lines, $owner, now()->subDays(7));
        $this->createSupplierPayment($bill2, $owner, now()->subDays(3), 'Partial payment — INV-PO-002', round((float) $bill2->grand_total * 0.5));

        $this->counts['purchases'] = '2 POs, 2 GRNs, 2 Bills, 2 payments';
    }

    private function createPO(string $poNo, Supplier $supplier, array $lines, User $user, $date): PurchaseOrder
    {
        $total = collect($lines)->sum(fn ($l) => $l['qty'] * $l['cost']);
        $po = PurchaseOrder::create([
            'po_no'                  => $poNo,
            'branch_id'              => $this->main->id,
            'supplier_id'            => $supplier->id,
            'order_date'             => $date->toDateString(),
            'expected_delivery_date' => $date->copy()->addDays(3)->toDateString(),
            'status'                 => 'approved',
            'total_amount'           => $total,
            'posted_by_user_id'      => $user->id,
            'approved_by_user_id'    => $user->id,
            'approved_at'            => $date->copy()->addDay(),
            'notes'                  => 'Inventory demo purchase order',
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
            'notes'             => 'Inventory demo GRN',
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
                branch: $branch,
                product: $product,
                variant: $variant,
                quantity: (float) $line['qty'],
                unitCost: (float) $line['cost'],
                movementType: 'purchase',
                referenceType: 'goods_receipt',
                referenceId: $grn->id,
                referenceNo: $grnNo,
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

    private function createSupplierPayment(PurchaseBill $bill, User $user, $date, string $notes, ?float $amount = null): void
    {
        $amount = $amount ?? (float) $bill->balance_due;
        SupplierPayment::create([
            'payment_no'        => 'PAY-' . $bill->bill_no,
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

    private function seedStockTransfer(): void
    {
        if (! $this->main || ! $this->warehouse) {
            $this->counts['transfer'] = 'skipped (need 2 branches)';
            return;
        }
        if (StockTransfer::count() > 0) {
            $this->counts['transfer'] = 'already exists';
            return;
        }

        $owner = User::where('email', 'owner@inventorydemo.com')->first() ?? User::query()->orderBy('id')->first();
        $lines = [
            ['sku' => 'INV-RICE25',   'qty' => 5,  'cost' => 4200],
            ['sku' => 'INV-WATERCTN', 'qty' => 10, 'cost' => 300],
            ['sku' => 'INV-DETCTN',   'qty' => 4,  'cost' => 3360],
        ];

        $transfer = StockTransfer::create([
            'transfer_no'       => 'INV-TRF-001',
            'from_branch_id'    => $this->warehouse->id,
            'to_branch_id'      => $this->main->id,
            'transfer_date'     => now()->subDays(2)->toDateString(),
            'status'            => 'posted',
            'posted_by_user_id' => $owner->id,
            'posted_at'         => now()->subDays(2),
            'notes'             => 'Replenishment: Warehouse → Main Store',
        ]);

        foreach ($lines as $line) {
            $product = Product::where('sku', $line['sku'])->first();
            if (! $product) continue;
            $variant = $this->inv->resolveVariant($product, null);
            $qty = (float) $line['qty'];

            $transfer->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity'           => $qty,
                'unit_cost'          => (float) $line['cost'],
            ]);
            $this->inv->postOutFefo(
                branch: $this->warehouse,
                product: $product,
                variant: $variant,
                quantity: $qty,
                movementType: 'transfer_out',
                referenceType: 'stock_transfer',
                referenceId: $transfer->id,
                referenceNo: $transfer->transfer_no,
                notes: 'Transfer to Main Store',
                userId: $owner->id,
            );
            $this->inv->postIn(
                branch: $this->main,
                product: $product,
                variant: $variant,
                quantity: $qty,
                unitCost: (float) $line['cost'],
                movementType: 'transfer_in',
                referenceType: 'stock_transfer',
                referenceId: $transfer->id,
                referenceNo: $transfer->transfer_no,
                notes: 'Transfer from Warehouse',
                userId: $owner->id,
            );
        }
        $this->counts['transfer'] = 'INV-TRF-001 (Warehouse → Main, 3 products)';
    }
}
