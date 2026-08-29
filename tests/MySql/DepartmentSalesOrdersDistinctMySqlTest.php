<?php

namespace Tests\MySql;

use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentCategoryMap;
use App\Services\Reports\DepartmentReportService;
use Illuminate\Support\Facades\DB;

/**
 * DEPARTMENT-ORDERS-DISTINCT — a department's "orders" is a DISTINCT count of the orders
 * that included any of its products, not the sum of each product's own order count. An
 * order that carries two of the department's products must count once, exactly as the
 * category rollup counts an order once across its sub-categories.
 */
class DepartmentSalesOrdersDistinctMySqlTest extends MySqlTenantTestCase
{
    public function test_department_orders_is_distinct_not_a_per_product_sum(): void
    {
        $conn = DB::connection('tenant');
        $this->cleanTenant([
            'department_category_maps', 'departments',
            'sales_order_lines', 'sales_orders', 'products', 'categories', 'units', 'branches',
        ]);

        $branchId = $conn->table('branches')->insertGetId([
            'name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'timezone' => 'Asia/Karachi',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = $conn->table('units')->insertGetId([
            'code' => 'EA', 'name' => 'Each', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $catId = $conn->table('categories')->insertGetId([
            'name' => 'BBQ', 'code' => 'BBQ', 'slug' => 'bbq', 'is_active' => 1, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mkProduct = fn (string $sku) => $conn->table('products')->insertGetId([
            'category_id' => $catId, 'unit_id' => $unitId, 'sku' => $sku, 'name' => $sku, 'slug' => strtolower($sku),
            'product_type' => 'simple', 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_stock_tracked' => 0,
            'default_selling_price' => 1000, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pA = $mkProduct('BBQ-A');
        $pB = $mkProduct('BBQ-B');

        $dept = Department::create([
            'branch_id' => $branchId, 'code' => 'KITCHEN', 'name' => 'Kitchen', 'status' => 'active', 'is_third_party' => false,
        ]);
        DepartmentCategoryMap::create(['department_id' => $dept->id, 'category_id' => $catId, 'include_children' => true]);

        $today = now()->toDateString();
        $mkOrder = function (array $productIds) use ($conn, $branchId, $today) {
            $orderId = $conn->table('sales_orders')->insertGetId([
                'sale_no' => 'S-'.uniqid(), 'branch_id' => $branchId, 'order_type' => 'dine_in',
                'sale_date' => now(), 'business_date' => $today,
                'subtotal' => 0, 'grand_total' => 0, 'paid_amount' => 0, 'status' => 'paid',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($productIds as $pid) {
                $conn->table('sales_order_lines')->insert([
                    'sales_order_id' => $orderId, 'product_id' => $pid, 'product_name' => 'x',
                    'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1000, 'cost_total' => 0, 'discount_amount' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $orderId;
        };

        // Order 1 carries BOTH of the department's products; order 2 carries only A.
        $mkOrder([$pA, $pB]);
        $mkOrder([$pA]);

        $result = app(DepartmentReportService::class)->sales([
            'date_from' => $today, 'date_to' => $today, 'branch_id' => $branchId,
        ]);
        $row = collect($result['rows'])->firstWhere('department', 'Kitchen');

        $this->assertNotNull($row, 'the department appears in the sales report');
        // Two distinct orders — NOT the per-product sum (A in 2 orders + B in 1 = 3).
        $this->assertSame(2, $row['orders'], 'department orders is a DISTINCT count, not a per-product sum');
        // Value sums are unaffected: three lines x 1000.
        $this->assertSame(3000.0, (float) $row['net']);
    }
}
