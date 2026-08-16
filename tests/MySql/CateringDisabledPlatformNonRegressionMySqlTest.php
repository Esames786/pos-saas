<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Supplier;
use App\Services\Finance\JournalPostingService;
use App\Services\Inventory\InventoryService;
use App\Services\Printing\PrintRoutingService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-V1-CLOSURE-1 (§10): with ZERO catering rows in the tenant, the
 * shared platform authorities behave exactly as before — the mere existence
 * of Catering code must not change Product/Customer/Supplier CRUD, inventory
 * posting, finance posting, or POS KOT routing. Every proof also asserts the
 * catering tables stayed empty (no hidden side writes).
 */
class CateringDisabledPlatformNonRegressionMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const CATERING_TABLES = [
        'catering_events', 'catering_estimates', 'catering_estimate_lines',
        'catering_material_rates', 'catering_cost_snapshots', 'catering_advances',
        'catering_production_releases', 'catering_refunds', 'catering_final_invoices',
        'catering_printer_mappings', 'catering_product_profiles',
        'catering_event_reminders', 'catering_email_logs',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant(array_merge(self::CATERING_TABLES, [
            'category_printer_mappings', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'printers',
            'sale_payments', 'sales_ledgers', 'sales_order_lines', 'sales_orders',
            'stock_ledgers', 'stock_balances', 'inventory_batches',
            'journal_lines', 'journal_entries', 'accounts',
            'customer_translations', 'supplier_translations',
            'products', 'categories', 'units', 'customers', 'suppliers',
            'payment_methods', 'terminals', 'branches',
        ]));
    }

    private function assertCateringUntouched(): void
    {
        foreach (self::CATERING_TABLES as $table) {
            $this->assertSame(0, (int) $this->tenant()->table($table)->count(),
                "catering table {$table} must stay empty for a catering-disabled tenant");
        }
    }

    public function test_master_data_crud_is_unchanged(): void
    {
        $categoryId = $this->makeCategory();

        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'Plain Product', 'slug' => 'plain-'.uniqid(),
            'sku' => 'PLAIN-'.uniqid(), 'default_selling_price' => 150, 'status' => 'active',
        ]);
        $product->update(['name' => 'Plain Product Renamed', 'default_selling_price' => 175]);
        $this->assertSame('Plain Product Renamed', $product->fresh()->name);

        $customer = Customer::create(['name' => 'Regular Customer', 'phone' => '0311-0000000']);
        $customer->update(['name' => 'Regular Customer Renamed']);
        $this->assertSame('Regular Customer Renamed', $customer->fresh()->name);

        $supplier = Supplier::create(['code' => 'SUP-'.uniqid(), 'name' => 'Regular Supplier']);
        $supplier->update(['name' => 'Regular Supplier Renamed']);
        $this->assertSame('Regular Supplier Renamed', $supplier->fresh()->name);

        // No translation rows appear unless someone explicitly writes them.
        $this->assertSame(0, (int) $this->tenant()->table('customer_translations')->count());
        $this->assertSame(0, (int) $this->tenant()->table('supplier_translations')->count());
        $this->assertCateringUntouched();
    }

    public function test_inventory_posting_is_unchanged(): void
    {
        $branch = Branch::find($this->makeBranch());
        $product = Product::find($this->makeProduct($this->makeCategory()));

        $inventory = app(InventoryService::class);
        $inventory->postIn($branch, $product, null, 10, 100, 'opening_stock', null, null, 'NR-IN');
        $ledgers = $inventory->postOutFefo($branch, $product, null, 4, 'sale', null, null, 'NR-OUT');

        $balance = $this->tenant()->table('stock_balances')
            ->where('branch_id', $branch->id)->where('product_id', $product->id)->first();
        $this->assertEqualsWithDelta(6.0, (float) $balance->quantity_on_hand, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $balance->average_cost, 0.001);
        $this->assertCount(1, $ledgers);
        $this->assertEqualsWithDelta(400.0, (float) $ledgers[0]->total_cost, 0.001, 'FEFO cost math unchanged');
        $this->assertSame(2, (int) $this->tenant()->table('stock_ledgers')->count());

        $this->assertCateringUntouched();
    }

    public function test_paid_sale_finance_posting_is_unchanged(): void
    {
        (new DefaultChartOfAccountsSeeder)->run();

        $branchId = $this->makeBranch();
        $methodId = $this->makePaymentMethod();
        $saleId = $this->makeSale($branchId, [
            'subtotal' => 100, 'grand_total' => 100, 'paid_amount' => 100, 'payment_status' => 'paid',
        ]);
        $productId = $this->makeProduct($this->makeCategory());
        $this->makeSaleLine($saleId, $productId, ['line_total' => 100]);
        $this->tenant()->table('sale_payments')->insert([
            'sales_order_id' => $saleId, 'payment_method_id' => $methodId, 'amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $entry = app(JournalPostingService::class)->postPaidSale(SalesOrder::with(['lines', 'payments'])->find($saleId));

        $this->assertNotNull($entry, 'paid-sale GL posting must still produce a journal entry');
        $this->assertSame('posted', $entry->status);
        $this->assertEqualsWithDelta((float) $entry->total_debit, (float) $entry->total_credit, 0.001, 'entry balanced');
        $this->assertSame('sales_order_paid', $entry->source_type);

        $this->assertCateringUntouched();
    }

    public function test_pos_kot_routing_is_unchanged(): void
    {
        $branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $printerId = $this->makePrinter();
        $this->tenant()->table('category_printer_mappings')->insert([
            'branch_id' => $branchId, 'category_id' => $categoryId, 'printer_id' => $printerId,
            'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = $this->makeProduct($categoryId);
        $saleId = $this->makeSale($branchId, ['order_type' => 'dine_in', 'status' => 'draft']);
        $this->makeSaleLine($saleId, $productId, ['quantity' => 2]);

        $routes = app(PrintRoutingService::class)->kotRoutesForSale(SalesOrder::find($saleId));

        $this->assertNotEmpty($routes, 'POS KOT routing must still resolve');
        $printerIds = collect($routes)->map(fn ($route) => $route['printer']?->id)->filter()->unique()->values();
        $this->assertSame([$printerId], $printerIds->all(), 'the mapped POS printer is still chosen');

        $this->assertCateringUntouched();
    }
}
