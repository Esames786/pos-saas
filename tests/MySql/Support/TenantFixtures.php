<?php

namespace Tests\MySql\Support;

use Illuminate\Support\Facades\DB;

/**
 * Minimal, schema-accurate row builders for the MySQL Edge suite. Insert only the
 * columns the real tenant schema requires (NOT NULL, no default) plus what a test
 * asserts on — so fixtures never drift into a hand-rolled fake schema.
 */
trait TenantFixtures
{
    protected function tenant(): \Illuminate\Database\Connection
    {
        return DB::connection('tenant');
    }

    protected function makeBranch(array $attrs = []): int
    {
        return $this->tenant()->table('branches')->insertGetId(array_merge([
            'name' => 'Test Branch', 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function makeCategory(array $attrs = []): int
    {
        return $this->tenant()->table('categories')->insertGetId(array_merge([
            'name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function makeProduct(int $categoryId, array $attrs = []): int
    {
        $u = uniqid();
        return $this->tenant()->table('products')->insertGetId(array_merge([
            'category_id' => $categoryId, 'name' => 'Product ' . $u, 'slug' => 'p-' . $u, 'sku' => 'SKU-' . $u,
            'item_kind' => 'finished_good', 'inventory_consumption_method' => 'stock_item',
            'is_stock_tracked' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function makeSale(int $branchId, array $attrs = []): int
    {
        return $this->tenant()->table('sales_orders')->insertGetId(array_merge([
            'sale_no' => 'SO-' . uniqid(), 'branch_id' => $branchId, 'order_source' => 'pos',
            'order_type' => 'quick_sale', 'sale_date' => now(), 'status' => 'paid',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function makeSaleLine(int $saleId, int $productId, array $attrs = []): int
    {
        return $this->tenant()->table('sales_order_lines')->insertGetId(array_merge([
            'sales_order_id' => $saleId, 'product_id' => $productId, 'product_name' => 'Product',
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function makeTerminal(int $branchId, array $attrs = []): int
    {
        $u = uniqid();
        return $this->tenant()->table('terminals')->insertGetId(array_merge([
            'branch_id' => $branchId, 'code' => 'TERM-' . $u, 'name' => 'Terminal ' . $u,
            'requires_shift' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function makeUser(array $attrs = []): int
    {
        $u = uniqid();
        return $this->tenant()->table('users')->insertGetId(array_merge([
            'name' => 'Cashier ' . $u, 'email' => 'u' . $u . '@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function makePrinter(array $attrs = []): int
    {
        $u = uniqid();
        return $this->tenant()->table('printers')->insertGetId(array_merge([
            'name' => 'Printer ' . $u, 'code' => 'PRN-' . $u, 'printer_type' => 'network',
            'print_role' => 'kot', 'supports_reminder' => 1, 'ip_address' => '192.168.1.50',
            'port' => 9100, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function makePrintJob(?int $printerId, array $attrs = []): int
    {
        return $this->tenant()->table('print_jobs')->insertGetId(array_merge([
            'job_no' => 'PJ-' . uniqid(), 'copy_no' => 1, 'printer_id' => $printerId,
            'document_type' => 'reminder', 'print_status' => 'printed', 'attempts' => 1,
            'printed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }
}
