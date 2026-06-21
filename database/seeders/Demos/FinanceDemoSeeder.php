<?php

namespace Database\Seeders\Demos;

use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\Category;
use App\Models\Tenant\Customer;
use App\Models\Tenant\ExpenseCategory;
use App\Models\Tenant\ExpenseVoucher;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\ManufacturingBom;
use App\Models\Tenant\ManufacturingBomLine;
use App\Models\Tenant\ManufacturingCustomer;
use App\Models\Tenant\MaterialRequisition;
use App\Models\Tenant\MaterialRequisitionLine;
use App\Models\Tenant\ProductionOrder;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\WipJob;
use App\Models\Tenant\WipJobLine;
use App\Models\Tenant\FinishedGoodReceipt;
use App\Models\Tenant\FinishedGoodReceiptLine;
use App\Models\Tenant\ManufacturingScrapRecord;
use App\Models\Tenant\ManufacturingScrapLine;
use App\Models\Tenant\ManufacturingRejectionRecord;
use App\Models\Tenant\ManufacturingRejectionLine;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\OpeningBalanceService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SalesService;
use Illuminate\Support\Str;

/**
 * Finance & Supply Chain ERP demo (financedemo, finance_erp plan). Accounting-led
 * dataset: chart of accounts (seeded on provision) + opening balances, suppliers,
 * customers, stock items, opening stock, paid sales (revenue + COGS), and an
 * operating expense — so Journal Entries, GL, Trial Balance, P&L and Balance Sheet
 * are populated and reconcile. NO restaurant/kitchen data.
 *
 * MUST run inside an activated tenant DB. Idempotent. Reconciliation is guaranteed
 * by posting through the same balanced services the live app uses
 * (OpeningBalanceService / SalesService::finalizePaidSale / ExpenseService).
 */
class FinanceDemoSeeder
{
    private InventoryService $inv;
    private SalesService $sales;
    private array $counts = [];
    private ?Branch $main = null;

    public function run(): array
    {
        $this->inv   = app(InventoryService::class);
        $this->sales = app(SalesService::class);

        $this->seedUnits();
        $this->seedBranch();
        $this->seedCategories();
        $this->seedSuppliers();
        $this->seedCustomers();
        $this->seedProducts();
        $invValue = $this->seedOpeningStock();
        $this->seedOpeningBalances($invValue);
        $this->seedPaidSales();
        $this->seedExpense();
        $this->seedManufacturingCustomers();
        $this->seedProductionOrders();
        $this->seedBoms();
        $this->seedMaterialRequisitions();
        $this->seedWipJobs();
        $this->seedFinishedGoods();
        $this->seedScrap();
        $this->seedRejections();

        return $this->counts;
    }

    private function owner(): User
    {
        return User::orderBy('id')->first();
    }

    private function seedUnits(): void
    {
        foreach ([
            ['code' => 'PCS', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true],
            ['code' => 'BOX', 'name' => 'Box', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => false],
            ['code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => true],
        ] as $u) {
            Unit::updateOrCreate(['code' => $u['code']], array_merge($u, ['is_active' => true]));
        }
        $this->counts['units'] = Unit::count();
    }

    private function seedBranch(): void
    {
        $this->main = Branch::updateOrCreate(
            ['code' => 'HO'],
            [
                'name'        => 'Head Office',
                'business_type' => 'store',
                'address'     => 'Finance Tower, Blue Area',
                'phone'       => '051-111-0001',
                'timezone'    => 'Asia/Karachi',
                'status'      => 'active',
            ]
        );
        $this->counts['branches'] = Branch::count();
    }

    private function seedCategories(): void
    {
        foreach ([
            ['code' => 'FG', 'name' => 'Finished Goods', 'sort_order' => 1],
            ['code' => 'RAW', 'name' => 'Raw Materials', 'sort_order' => 2],
            ['code' => 'CONSUM', 'name' => 'Consumables', 'sort_order' => 3],
        ] as $c) {
            $cat = Category::updateOrCreate(
                ['code' => $c['code']],
                ['name' => $c['name'], 'slug' => Str::slug($c['name']), 'sort_order' => $c['sort_order'], 'is_active' => true]
            );
            $cat->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $c['name']]);
        }
        $this->counts['categories'] = Category::count();
    }

    private function seedSuppliers(): void
    {
        foreach ([
            ['code' => 'FIN-SUP-001', 'name' => 'Industrial Materials Co', 'contact_person' => 'Asad Mehmood', 'phone' => '042-35000001', 'payment_terms_days' => 30, 'email' => 'sales@indmat.pk'],
            ['code' => 'FIN-SUP-002', 'name' => 'National Packaging Ltd', 'contact_person' => 'Farhan Qureshi', 'phone' => '042-35000002', 'payment_terms_days' => 15],
            ['code' => 'FIN-SUP-003', 'name' => 'Allied Components', 'contact_person' => 'Nadia Khan', 'phone' => '042-35000003', 'payment_terms_days' => 45],
        ] as $s) {
            Supplier::updateOrCreate(
                ['code' => $s['code']],
                array_merge($s, ['status' => 'active', 'opening_balance' => 0, 'current_balance' => 0])
            );
        }
        $this->counts['suppliers'] = Supplier::count();
    }

    private function seedCustomers(): void
    {
        foreach ([
            ['code' => 'FIN-CUS-001', 'name' => 'Crescent Traders', 'phone' => '0300-1110001'],
            ['code' => 'FIN-CUS-002', 'name' => 'Orient Enterprises', 'phone' => '0300-1110002'],
            ['code' => 'FIN-CUS-003', 'name' => 'Summit Distributors', 'phone' => '0300-1110003'],
        ] as $c) {
            Customer::updateOrCreate(['code' => $c['code']], array_merge($c, ['status' => 'active']));
        }
        $this->counts['customers'] = Customer::count();
    }

    /** @return array<int, array{sku:string, cost:int, price:int}> */
    private function productList(): array
    {
        return [
            ['sku' => 'FIN-P001', 'name' => 'Steel Bracket A1', 'cat' => 'FG', 'cost' => 320, 'price' => 520],
            ['sku' => 'FIN-P002', 'name' => 'Aluminium Sheet 2mm', 'cat' => 'FG', 'cost' => 850, 'price' => 1240],
            ['sku' => 'FIN-P003', 'name' => 'Plastic Casing Unit', 'cat' => 'FG', 'cost' => 140, 'price' => 250],
            ['sku' => 'FIN-P004', 'name' => 'Copper Wire Roll', 'cat' => 'RAW', 'cost' => 1100, 'price' => 1650],
            ['sku' => 'FIN-P005', 'name' => 'Carton Box Large', 'cat' => 'CONSUM', 'cost' => 45, 'price' => 80],
            ['sku' => 'FIN-P006', 'name' => 'Industrial Adhesive 1L', 'cat' => 'CONSUM', 'cost' => 380, 'price' => 600],
        ];
    }

    private function seedProducts(): void
    {
        foreach ($this->productList() as $p) {
            $cat = Category::where('code', $p['cat'])->first();
            $unit = Unit::where('code', 'PCS')->first();

            $product = Product::updateOrCreate(
                ['sku' => $p['sku']],
                [
                    'name'                          => $p['name'],
                    'slug'                          => Str::slug($p['name']),
                    'category_id'                   => $cat?->id,
                    'unit_id'                       => $unit?->id,
                    'product_type'                  => 'simple',
                    'item_kind'                     => 'finished_good',
                    'inventory_consumption_method'  => 'stock_item',
                    'default_purchase_price'        => $p['cost'],
                    'default_selling_price'         => $p['price'],
                    'is_sellable'                   => true,
                    'is_purchasable'                => true,
                    'is_stock_tracked'              => true,
                    'has_expiry'                    => false,
                    'requires_batch'                => false,
                    'is_taxable'                    => false,
                    'status'                        => 'active',
                ]
            );
            $product->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $p['name']]);

            $variant = $product->variants()->updateOrCreate(
                ['sku' => $p['sku']],
                [
                    'name'           => 'Default',
                    'purchase_price' => $p['cost'],
                    'selling_price'  => $p['price'],
                    'is_default'     => true,
                    'is_active'      => true,
                    'reorder_level'  => 10,
                    'reorder_quantity' => 50,
                ]
            );
        }
        $this->counts['products'] = Product::count();
    }

    /** Seed opening stock operationally; returns total inventory value at cost. */
    private function seedOpeningStock(): float
    {
        if (! $this->main) {
            return 0.0;
        }

        $value = 0.0;
        foreach ($this->productList() as $p) {
            $product = Product::where('sku', $p['sku'])->first();
            if (! $product) {
                continue;
            }
            $variant = $this->inv->resolveVariant($product, null);
            $qty = 100;

            // Idempotency: only seed if this product has no stock yet at the branch.
            $existing = \App\Models\Tenant\StockBalance::where('branch_id', $this->main->id)
                ->where('product_id', $product->id)
                ->sum('quantity_on_hand');
            if ((float) $existing <= 0) {
                $this->inv->postIn(
                    branch: $this->main,
                    product: $product,
                    variant: $variant,
                    quantity: $qty,
                    unitCost: $p['cost'],
                    movementType: 'opening_stock',
                    referenceType: 'finance_demo_opening',
                    referenceId: $product->id,
                    referenceNo: 'OPEN-' . $p['sku'],
                    notes: 'Finance demo opening stock',
                    userId: $this->owner()?->id,
                );
            }
            $value += $qty * $p['cost'];
        }
        $this->counts['opening_stock_value'] = $value;
        return $value;
    }

    /**
     * Post a balanced opening-balance journal (GL) so the Balance Sheet starts
     * from real figures: Dr Cash + Dr Inventory ; Cr Accounts Payable + Cr Owner
     * Capital. Inventory debit matches the operational opening stock value so it
     * never goes negative after COGS. Idempotent (one posted batch).
     */
    private function seedOpeningBalances(float $invValue): void
    {
        $service = app(OpeningBalanceService::class);

        // Already posted once? skip.
        if (\App\Models\Tenant\OpeningBalanceBatch::where('status', 'posted')->exists()) {
            $this->counts['opening_balances'] = 'already posted';
            return;
        }

        $cashAcct = Account::where('code', '1110')->value('id');
        $invAcct  = Account::where('code', '1400')->value('id');
        $apAcct   = Account::where('code', '2100')->value('id');
        $capAcct  = Account::where('code', '3100')->value('id');

        if (! $cashAcct || ! $invAcct || ! $apAcct || ! $capAcct) {
            $this->counts['opening_balances'] = 'skipped (chart of accounts missing)';
            return;
        }

        $cashBank = CashBankAccount::where('account_id', $cashAcct)->first()
            ?? CashBankAccount::whereNotNull('account_id')->first();

        $cash = 500000.0;
        $ap   = 120000.0;
        $debit = $cash + $invValue;            // cash + inventory
        $capital = $debit - $ap;               // balancing equity

        $batch = $service->createDraft([
            'batch_no'     => null,
            'opening_date' => now()->subDays(30)->toDateString(),
            'branch_id'    => $this->main?->id,
            'description'  => 'Finance demo opening balances',
            'lines'        => [
                ['account_id' => $cashAcct, 'cash_bank_account_id' => $cashBank?->id, 'description' => 'Opening cash', 'debit' => $cash, 'credit' => 0],
                ['account_id' => $invAcct, 'cash_bank_account_id' => null, 'description' => 'Opening inventory', 'debit' => round($invValue, 2), 'credit' => 0],
                ['account_id' => $apAcct, 'cash_bank_account_id' => null, 'description' => 'Opening accounts payable', 'debit' => 0, 'credit' => $ap],
                ['account_id' => $capAcct, 'cash_bank_account_id' => null, 'description' => 'Owner capital', 'debit' => 0, 'credit' => round($capital, 2)],
            ],
        ]);

        $service->post($batch, $this->owner()?->id);
        $this->counts['opening_balances'] = 'posted (' . number_format($debit, 0) . ')';
    }

    private function seedPaidSales(): void
    {
        if (! $this->main) {
            $this->counts['sales'] = 'skipped';
            return;
        }
        $owner = $this->owner();
        $cash = PaymentMethod::where('method_type', 'cash')->first();
        $card = PaymentMethod::where('method_type', 'card')->first();
        if (! $cash) {
            $this->counts['sales'] = 'skipped (no cash payment method)';
            return;
        }

        $orders = [
            ['pay' => $cash, 'days' => 0, 'lines' => [['FIN-P001', 5, 520], ['FIN-P005', 10, 80]]],
            ['pay' => $card ?? $cash, 'days' => 1, 'lines' => [['FIN-P002', 3, 1240]]],
            ['pay' => $cash, 'days' => 2, 'lines' => [['FIN-P003', 8, 250], ['FIN-P006', 2, 600]]],
            ['pay' => $card ?? $cash, 'days' => 4, 'lines' => [['FIN-P004', 2, 1650]]],
            ['pay' => $cash, 'days' => 6, 'lines' => [['FIN-P001', 4, 520], ['FIN-P003', 5, 250]]],
        ];

        $count = 0;
        foreach ($orders as $o) {
            try {
                $saleDate = now()->subDays($o['days']);
                $subtotal = 0;
                foreach ($o['lines'] as [$sku, $qty, $price]) {
                    $subtotal += $qty * $price;
                }
                $sale = SalesOrder::create([
                    'sale_no'     => 'SO-' . $saleDate->format('Ymd') . '-' . str_pad(SalesOrder::count() + 1, 4, '0', STR_PAD_LEFT),
                    'branch_id'   => $this->main->id,
                    'order_source' => 'pos',
                    'order_type'  => 'quick_sale',
                    'sale_date'   => $saleDate,
                    'subtotal'    => $subtotal,
                    'discount_type' => 'none',
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'tax_amount'  => 0,
                    'grand_total' => $subtotal,
                    'status'      => 'draft',
                    'created_by_user_id' => $owner?->id,
                ]);
                foreach ($o['lines'] as [$sku, $qty, $price]) {
                    $product = Product::where('sku', $sku)->first();
                    if (! $product) {
                        continue;
                    }
                    $variant = $this->inv->resolveVariant($product, null);
                    $sale->lines()->create([
                        'product_id' => $product->id, 'product_variant_id' => $variant?->id,
                        'product_name' => $product->name, 'variant_name' => $variant?->name,
                        'quantity' => $qty, 'unit_price' => $price, 'unit_cost' => 0, 'cost_total' => 0,
                        'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => $qty * $price,
                    ]);
                }
                $method = $o['pay'];
                $sale->payments()->create([
                    'payment_method_id' => $method->id,
                    'amount' => $subtotal,
                    'tendered_amount' => $method->method_type === 'cash' ? $subtotal : null,
                    'change_amount' => 0,
                    'transaction_ref' => $method->method_type === 'cash' ? null : 'CARD-' . Str::upper(Str::random(4)),
                ]);
                $this->sales->finalizePaidSale($sale);
                $count++;
            } catch (\Throwable $e) {
                // skip a bad order; never break the seed
            }
        }
        $this->counts['sales'] = $count . ' paid orders';
    }

    private function seedExpense(): void
    {
        if (! $this->main) {
            return;
        }
        $cashBank = CashBankAccount::where('is_active', true)->orderBy('id')->first();
        $category = ExpenseCategory::where('is_active', true)->orderBy('id')->first();
        if (! $cashBank || ! $category) {
            $this->counts['expenses'] = 'skipped (no cash/bank or expense category)';
            return;
        }

        if (ExpenseVoucher::where('payee_name', 'LESCO (demo)')->exists()) {
            $this->counts['expenses'] = 'already seeded';
            return;
        }

        $voucher = ExpenseVoucher::create([
            'voucher_no'           => 'EXP-FIN-0001',
            'branch_id'            => $this->main->id,
            'cash_bank_account_id' => $cashBank->id,
            'expense_date'         => now()->subDays(3)->toDateString(),
            'payment_date'         => now()->subDays(3)->toDateString(),
            'payee_name'           => 'LESCO (demo)',
            'status'               => 'draft',
            'notes'                => 'Finance demo utility expense',
            'created_by_user_id'   => $this->owner()?->id,
        ]);
        $voucher->lines()->create([
            'expense_category_id' => $category->id,
            'account_id'          => $category->account_id,
            'description'         => 'Electricity bill',
            'amount'              => 3000,
            'tax_amount'          => 0,
            'line_total'          => 3000,
            'sort_order'          => 0,
        ]);

        app(ExpenseService::class)->post($voucher, $this->owner()?->id);
        $this->counts['expenses'] = '1 posted';
    }

    private function seedManufacturingCustomers(): void
    {
        // Normalize any legacy 3-digit demo codes to the 4-digit generator format.
        // Relations are ID-based, so renaming the code in place is safe.
        foreach ([
            'MFG-CUST-001' => 'MFG-CUST-0001',
            'MFG-CUST-002' => 'MFG-CUST-0002',
            'MFG-CUST-003' => 'MFG-CUST-0003',
        ] as $old => $new) {
            ManufacturingCustomer::where('code', $old)->update(['code' => $new]);
        }

        foreach ([
            ['code' => 'MFG-CUST-0001', 'name' => 'Crescent Industrial Works',  'contact_person' => 'Ahsan Malik',  'city' => 'Lahore'],
            ['code' => 'MFG-CUST-0002', 'name' => 'Alpha Packaging Pvt Ltd',    'contact_person' => 'Sana Javed',   'city' => 'Karachi'],
            ['code' => 'MFG-CUST-0003', 'name' => 'Northern Engineering Co',    'contact_person' => 'Bilal Khan',   'city' => 'Islamabad'],
        ] as $c) {
            ManufacturingCustomer::updateOrCreate(
                ['code' => $c['code']],
                array_merge($c, ['status' => 'active', 'country' => 'Pakistan'])
            );
        }
        $this->counts['manufacturing_customers'] = ManufacturingCustomer::count();
    }

    private function seedProductionOrders(): void
    {
        if (! $this->main) {
            $this->counts['production_orders'] = 'skipped (no branch)';
            return;
        }

        $cust1 = ManufacturingCustomer::where('code', 'MFG-CUST-0001')->value('id');
        $cust2 = ManufacturingCustomer::where('code', 'MFG-CUST-0002')->value('id');
        $cust3 = ManufacturingCustomer::where('code', 'MFG-CUST-0003')->value('id');

        $p001 = Product::where('sku', 'FIN-P001')->value('id'); // Steel Bracket A1
        $p005 = Product::where('sku', 'FIN-P005')->value('id'); // Carton Box Large
        $p002 = Product::where('sku', 'FIN-P002')->value('id'); // Aluminium Sheet 2mm

        $orders = [
            [
                'order_no'                  => 'PROD-000001',
                'manufacturing_customer_id' => $cust1,
                'branch_id'                 => $this->main->id,
                'product_id'                => $p001,
                'planned_quantity'          => 250.0,
                'produced_quantity'         => 0.0,
                'order_date'                => now()->subDays(10)->toDateString(),
                'due_date'                  => now()->addDays(20)->toDateString(),
                'status'                    => 'planned',
                'priority'                  => 'high',
            ],
            [
                'order_no'                  => 'PROD-000002',
                'manufacturing_customer_id' => $cust2,
                'branch_id'                 => $this->main->id,
                'product_id'                => $p005,
                'planned_quantity'          => 1000.0,
                'produced_quantity'         => 0.0,
                'order_date'                => now()->subDays(5)->toDateString(),
                'due_date'                  => now()->addDays(15)->toDateString(),
                'status'                    => 'released',
                'priority'                  => 'normal',
            ],
            [
                'order_no'                  => 'PROD-000003',
                'manufacturing_customer_id' => $cust3,
                'branch_id'                 => $this->main->id,
                'product_id'                => $p002,
                'planned_quantity'          => 120.0,
                'produced_quantity'         => 45.0,
                'order_date'                => now()->subDays(15)->toDateString(),
                'due_date'                  => now()->addDays(5)->toDateString(),
                'status'                    => 'in_progress',
                'priority'                  => 'urgent',
            ],
        ];

        foreach ($orders as $o) {
            if (! $o['product_id']) {
                continue; // skip if product not found (e.g. fresh tenant without FIN products)
            }
            ProductionOrder::updateOrCreate(
                ['order_no' => $o['order_no']],
                array_merge($o, ['created_by_user_id' => $this->owner()?->id])
            );
        }

        $this->counts['production_orders'] = ProductionOrder::count();
    }

    private function seedBoms(): void
    {
        // FIN-P001 = Steel Bracket A1 (finished), components: FIN-P004, FIN-P006, FIN-P005
        $p001 = Product::where('sku', 'FIN-P001')->value('id');
        $p002 = Product::where('sku', 'FIN-P002')->value('id');
        $p004 = Product::where('sku', 'FIN-P004')->value('id');
        $p005 = Product::where('sku', 'FIN-P005')->value('id');
        $p006 = Product::where('sku', 'FIN-P006')->value('id');

        $owner = $this->owner()?->id;

        $bom1 = ManufacturingBom::updateOrCreate(
            ['bom_no' => 'BOM-000001'],
            [
                'finished_product_id' => $p001,
                'name'                => 'Standard Assembly — Steel Bracket A1',
                'version'             => '1.0',
                'output_quantity'     => 1.0000,
                'status'              => 'active',
                'effective_from'      => '2026-01-01',
                'notes'               => 'Demo BOM — configuration only, no GL/WIP posting.',
                'created_by_user_id'  => $owner,
            ]
        );

        if ($p001 && $p004 && $p006 && $p005) {
            ManufacturingBomLine::where('manufacturing_bom_id', $bom1->id)->delete();
            foreach ([
                ['component_product_id' => $p004, 'quantity' => 0.0500, 'wastage_percent' => 2.0000, 'sort_order' => 1],
                ['component_product_id' => $p006, 'quantity' => 0.0200, 'wastage_percent' => 1.0000, 'sort_order' => 2],
                ['component_product_id' => $p005, 'quantity' => 0.1000, 'wastage_percent' => 0.0000, 'sort_order' => 3],
            ] as $line) {
                ManufacturingBomLine::create(array_merge(['manufacturing_bom_id' => $bom1->id], $line));
            }
        }

        $bom2 = ManufacturingBom::updateOrCreate(
            ['bom_no' => 'BOM-000002'],
            [
                'finished_product_id' => $p002,
                'name'                => 'Standard Roll — Aluminium Sheet 2mm',
                'version'             => '1.0',
                'output_quantity'     => 1.0000,
                'status'              => 'active',
                'effective_from'      => '2026-01-01',
                'notes'               => 'Demo BOM — configuration only.',
                'created_by_user_id'  => $owner,
            ]
        );

        if ($p002 && $p004 && $p006) {
            ManufacturingBomLine::where('manufacturing_bom_id', $bom2->id)->delete();
            foreach ([
                ['component_product_id' => $p004, 'quantity' => 0.0300, 'wastage_percent' => 1.0000, 'sort_order' => 1],
                ['component_product_id' => $p006, 'quantity' => 0.0150, 'wastage_percent' => 1.0000, 'sort_order' => 2],
            ] as $line) {
                ManufacturingBomLine::create(array_merge(['manufacturing_bom_id' => $bom2->id], $line));
            }
        }

        $this->counts['manufacturing_boms'] = ManufacturingBom::count();
    }

    private function seedMaterialRequisitions(): void
    {
        // Planning/request-only. No stock movement, no GL journal, no trial-balance impact.
        $owner = $this->owner()?->id;

        $map = [
            'MRC-000001' => 'PROD-000001',
            'MRC-000002' => 'PROD-000003',
        ];

        foreach ($map as $mrcNo => $orderNo) {
            $order = ProductionOrder::with('product')->where('order_no', $orderNo)->first();
            if (! $order) {
                continue;
            }

            $bom = ManufacturingBom::active()
                ->where('finished_product_id', $order->product_id)
                ->with('lines')
                ->orderByDesc('id')
                ->first();

            $mrc = MaterialRequisition::updateOrCreate(
                ['mrc_no' => $mrcNo],
                [
                    'production_order_id'       => $order->id,
                    'manufacturing_customer_id' => $order->manufacturing_customer_id,
                    'branch_id'                 => $order->branch_id,
                    'request_date'              => now()->subDays(3)->toDateString(),
                    'required_date'             => $order->due_date?->toDateString(),
                    'status'                    => 'requested',
                    'priority'                  => $order->priority,
                    'notes'                     => 'Demo MRC — request/planning only, no stock issue.',
                    'created_by_user_id'        => $owner,
                ]
            );

            // Rebuild lines idempotently from the active BOM (if any).
            MaterialRequisitionLine::where('material_requisition_id', $mrc->id)->delete();

            if ($bom && $bom->lines->count()) {
                $output = (float) ($bom->output_quantity ?: 1);
                $factor = $output > 0 ? ((float) $order->planned_quantity / $output) : 0;

                foreach ($bom->lines as $i => $bl) {
                    $base     = $factor * (float) $bl->quantity;
                    $required = round($base * (1 + ((float) $bl->wastage_percent / 100)), 4);

                    MaterialRequisitionLine::create([
                        'material_requisition_id' => $mrc->id,
                        'component_product_id'    => $bl->component_product_id,
                        'unit_id'                 => $bl->unit_id,
                        'required_quantity'       => $required,
                        'issued_quantity'         => 0,
                        'wastage_percent'         => (float) $bl->wastage_percent,
                        'source_bom_line_id'      => $bl->id,
                        'sort_order'              => $i,
                    ]);
                }
            }
        }

        $this->counts['material_requisitions'] = MaterialRequisition::count();
        $this->counts['material_requisition_lines'] = MaterialRequisitionLine::count();
    }

    private function seedWipJobs(): void
    {
        // Tracking-only. No stock movement, no GL journal, no trial-balance impact.
        $owner = $this->owner()?->id;

        // [wip_no, production order no, status, progress%]
        $jobs = [
            ['WIP-000001', 'PROD-000001', 'MRC-000001', 'in_progress', 25.00],
            ['WIP-000002', 'PROD-000003', 'MRC-000002', 'released', 0.00],
        ];

        foreach ($jobs as [$wipNo, $orderNo, $mrcNo, $status, $progress]) {
            $order = ProductionOrder::where('order_no', $orderNo)->first();
            if (! $order) {
                continue;
            }
            $mrc = MaterialRequisition::with('lines')->where('mrc_no', $mrcNo)->first();

            $planned   = (float) $order->planned_quantity;
            $completed = round($planned * ($progress / 100), 4);

            $job = WipJob::updateOrCreate(
                ['wip_no' => $wipNo],
                [
                    'production_order_id'       => $order->id,
                    'material_requisition_id'   => $mrc?->id,
                    'manufacturing_customer_id' => $order->manufacturing_customer_id,
                    'branch_id'                 => $order->branch_id,
                    'finished_product_id'       => $order->product_id,
                    'planned_quantity'          => $planned,
                    'started_quantity'          => $progress > 0 ? $planned : 0,
                    'completed_quantity'        => $completed,
                    'start_date'                => now()->subDays(2)->toDateString(),
                    'target_date'               => $order->due_date?->toDateString(),
                    'status'                    => $status,
                    'priority'                  => $order->priority,
                    'progress_percent'          => $progress,
                    'notes'                     => 'Demo WIP — tracking only, no stock/GL posting.',
                    'created_by_user_id'        => $owner,
                ]
            );

            // Rebuild lines idempotently from the MRC (if any).
            WipJobLine::where('wip_job_id', $job->id)->delete();
            if ($mrc) {
                foreach ($mrc->lines as $i => $ml) {
                    $required = (float) $ml->required_quantity;
                    $issued   = (float) $ml->issued_quantity;
                    WipJobLine::create([
                        'wip_job_id'                   => $job->id,
                        'material_requisition_line_id' => $ml->id,
                        'component_product_id'         => $ml->component_product_id,
                        'unit_id'                      => $ml->unit_id,
                        'required_quantity'            => $required,
                        'issued_quantity'              => $issued,
                        'consumed_quantity'            => 0,
                        'remaining_quantity'           => $required,
                        'sort_order'                   => $i,
                    ]);
                }
            }
        }

        $this->counts['wip_jobs'] = WipJob::count();
        $this->counts['wip_job_lines'] = WipJobLine::count();
    }

    private function seedFinishedGoods(): void
    {
        // Tracking-only. No stock movement, no GL journal, no trial-balance impact.
        $owner = $this->owner()?->id;

        // [fg_no, wip_no, status, quality, received, accepted, rejected, scrap]
        $records = [
            ['FG-000001', 'WIP-000001', 'accepted', 'passed', 62.5000, 62.5000, 0.0000, 0.0000],
            ['FG-000002', 'WIP-000002', 'recorded', 'pending', 5.0000, 0.0000, 0.0000, 0.0000],
        ];

        foreach ($records as [$fgNo, $wipNo, $status, $quality, $received, $accepted, $rejected, $scrap]) {
            $wip = WipJob::where('wip_no', $wipNo)->first();
            if (! $wip) {
                continue;
            }

            $fg = FinishedGoodReceipt::updateOrCreate(
                ['fg_no' => $fgNo],
                [
                    'wip_job_id'                => $wip->id,
                    'production_order_id'       => $wip->production_order_id,
                    'manufacturing_customer_id' => $wip->manufacturing_customer_id,
                    'branch_id'                 => $wip->branch_id,
                    'finished_product_id'       => $wip->finished_product_id,
                    'receipt_date'              => now()->subDay()->toDateString(),
                    'status'                    => $status,
                    'quality_status'            => $quality,
                    'planned_quantity'          => $wip->planned_quantity,
                    'received_quantity'         => $received,
                    'accepted_quantity'         => $accepted,
                    'rejected_quantity'         => $rejected,
                    'scrap_quantity'            => $scrap,
                    'priority'                  => $wip->priority,
                    'notes'                     => 'Demo finished goods — tracking only, no inventory/GL posting.',
                    'created_by_user_id'        => $owner,
                ]
            );

            // One output line for the finished product (idempotent rebuild).
            FinishedGoodReceiptLine::where('finished_good_receipt_id', $fg->id)->delete();
            FinishedGoodReceiptLine::create([
                'finished_good_receipt_id' => $fg->id,
                'finished_product_id'      => $wip->finished_product_id,
                'unit_id'                  => null,
                'batch_no'                 => 'B-' . substr($fgNo, 3),
                'lot_no'                   => null,
                'received_quantity'        => $received,
                'accepted_quantity'        => $accepted,
                'rejected_quantity'        => $rejected,
                'scrap_quantity'           => $scrap,
                'sort_order'               => 0,
            ]);
        }

        $this->counts['finished_goods'] = FinishedGoodReceipt::count();
        $this->counts['finished_good_lines'] = FinishedGoodReceiptLine::count();
    }

    private function seedScrap(): void
    {
        // Tracking-only. No stock movement, no GL journal, no trial-balance impact.
        $owner = $this->owner()?->id;

        $wip = WipJob::where('wip_no', 'WIP-000001')->first();
        $fg  = FinishedGoodReceipt::where('fg_no', 'FG-000002')->first();

        // SCRAP-000001 — from WIP-000001 (wip loss, machine loss, non-recoverable, all disposed)
        if ($wip) {
            $rec = ManufacturingScrapRecord::updateOrCreate(
                ['scrap_no' => 'SCRAP-000001'],
                [
                    'scrap_date'                => now()->subDay()->toDateString(),
                    'source_type'               => 'wip',
                    'wip_job_id'                => $wip->id,
                    'finished_good_receipt_id'  => null,
                    'production_order_id'        => $wip->production_order_id,
                    'manufacturing_customer_id' => $wip->manufacturing_customer_id,
                    'branch_id'                 => $wip->branch_id,
                    'status'                    => 'recorded',
                    'scrap_type'                => 'wip_loss',
                    'reason_code'               => 'machine_loss',
                    'quality_status'            => 'non_recoverable',
                    'total_quantity'            => 2.5000,
                    'recoverable_quantity'      => 0.0000,
                    'disposed_quantity'         => 2.5000,
                    'estimated_loss_value'      => null,
                    'notes'                     => 'Demo scrap — tracking only, no stock/GL posting.',
                    'created_by_user_id'        => $owner,
                ]
            );
            ManufacturingScrapLine::where('manufacturing_scrap_record_id', $rec->id)->delete();
            ManufacturingScrapLine::create([
                'manufacturing_scrap_record_id' => $rec->id,
                'product_id'                    => $wip->finished_product_id,
                'unit_id'                       => null,
                'quantity'                      => 2.5000,
                'recoverable_quantity'          => 0.0000,
                'disposed_quantity'             => 2.5000,
                'sort_order'                    => 0,
            ]);
        }

        // SCRAP-000002 — from FG-000002 (finished goods loss, quality fail, pending)
        if ($fg) {
            $rec = ManufacturingScrapRecord::updateOrCreate(
                ['scrap_no' => 'SCRAP-000002'],
                [
                    'scrap_date'                => now()->toDateString(),
                    'source_type'               => 'finished_goods',
                    'wip_job_id'                => $fg->wip_job_id,
                    'finished_good_receipt_id'  => $fg->id,
                    'production_order_id'        => $fg->production_order_id,
                    'manufacturing_customer_id' => $fg->manufacturing_customer_id,
                    'branch_id'                 => $fg->branch_id,
                    'status'                    => 'recorded',
                    'scrap_type'                => 'finished_goods_loss',
                    'reason_code'               => 'quality_fail',
                    'quality_status'            => 'pending',
                    'total_quantity'            => 1.0000,
                    'recoverable_quantity'      => 0.0000,
                    'disposed_quantity'         => 0.0000,
                    'estimated_loss_value'      => null,
                    'notes'                     => 'Demo scrap — tracking only, no stock/GL posting.',
                    'created_by_user_id'        => $owner,
                ]
            );
            ManufacturingScrapLine::where('manufacturing_scrap_record_id', $rec->id)->delete();
            ManufacturingScrapLine::create([
                'manufacturing_scrap_record_id' => $rec->id,
                'product_id'                    => $fg->finished_product_id,
                'unit_id'                       => null,
                'quantity'                      => 1.0000,
                'recoverable_quantity'          => 0.0000,
                'disposed_quantity'             => 0.0000,
                'sort_order'                    => 0,
            ]);
        }

        $this->counts['scrap_records'] = ManufacturingScrapRecord::count();
        $this->counts['scrap_lines'] = ManufacturingScrapLine::count();
    }

    private function seedRejections(): void
    {
        // Tracking-only. No stock movement, no GL journal, no scrap auto-create, no trial-balance impact.
        $owner = $this->owner()?->id;

        $fg  = FinishedGoodReceipt::where('fg_no', 'FG-000001')->first();
        $wip = WipJob::where('wip_no', 'WIP-000001')->first();

        // REJ-000001 — from FG-000001 (quality fail, major, rework)
        if ($fg) {
            $rec = ManufacturingRejectionRecord::updateOrCreate(
                ['rejection_no' => 'REJ-000001'],
                [
                    'rejection_date'                 => now()->subDay()->toDateString(),
                    'source_type'                    => 'finished_goods',
                    'wip_job_id'                     => $fg->wip_job_id,
                    'finished_good_receipt_id'       => $fg->id,
                    'production_order_id'            => $fg->production_order_id,
                    'manufacturing_customer_id'      => $fg->manufacturing_customer_id,
                    'branch_id'                      => $fg->branch_id,
                    'status'                         => 'recorded',
                    'rejection_type'                 => 'quality_fail',
                    'severity'                       => 'major',
                    'disposition'                    => 'rework',
                    'reason_code'                    => 'dimension_issue',
                    'quality_status'                 => 'reworkable',
                    'total_quantity'                 => 1.0000,
                    'rework_quantity'                => 1.0000,
                    'scrap_quantity'                 => 0.0000,
                    'accepted_after_review_quantity' => 0.0000,
                    'disposed_quantity'              => 0.0000,
                    'estimated_loss_value'           => null,
                    'notes'                          => 'Demo rejection — tracking only, no stock/GL posting.',
                    'created_by_user_id'             => $owner,
                ]
            );
            ManufacturingRejectionLine::where('manufacturing_rejection_record_id', $rec->id)->delete();
            ManufacturingRejectionLine::create([
                'manufacturing_rejection_record_id' => $rec->id,
                'product_id'                        => $fg->finished_product_id,
                'unit_id'                           => null,
                'quantity'                          => 1.0000,
                'rework_quantity'                   => 1.0000,
                'defect_code'                       => 'DIM-01',
                'sort_order'                        => 0,
            ]);
        }

        // REJ-000002 — from WIP-000001 (process defect, minor, pending)
        if ($wip) {
            $rec = ManufacturingRejectionRecord::updateOrCreate(
                ['rejection_no' => 'REJ-000002'],
                [
                    'rejection_date'                 => now()->toDateString(),
                    'source_type'                    => 'wip',
                    'wip_job_id'                     => $wip->id,
                    'finished_good_receipt_id'       => null,
                    'production_order_id'            => $wip->production_order_id,
                    'manufacturing_customer_id'      => $wip->manufacturing_customer_id,
                    'branch_id'                      => $wip->branch_id,
                    'status'                         => 'recorded',
                    'rejection_type'                 => 'process_defect',
                    'severity'                       => 'minor',
                    'disposition'                    => 'pending',
                    'reason_code'                    => 'machine_issue',
                    'quality_status'                 => 'pending',
                    'total_quantity'                 => 0.5000,
                    'rework_quantity'                => 0.0000,
                    'scrap_quantity'                 => 0.0000,
                    'accepted_after_review_quantity' => 0.0000,
                    'disposed_quantity'              => 0.0000,
                    'estimated_loss_value'           => null,
                    'notes'                          => 'Demo rejection — tracking only, no stock/GL posting.',
                    'created_by_user_id'             => $owner,
                ]
            );
            ManufacturingRejectionLine::where('manufacturing_rejection_record_id', $rec->id)->delete();
            ManufacturingRejectionLine::create([
                'manufacturing_rejection_record_id' => $rec->id,
                'product_id'                        => $wip->finished_product_id,
                'unit_id'                           => null,
                'quantity'                          => 0.5000,
                'defect_code'                       => 'PRC-02',
                'sort_order'                        => 0,
            ]);
        }

        $this->counts['rejection_records'] = ManufacturingRejectionRecord::count();
        $this->counts['rejection_lines'] = ManufacturingRejectionLine::count();
    }
}
