<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintRoutingService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * §5 — Critical routing suite ported to MySQL (MYSQL-TEST-FOUNDATION-1).
 *
 * The original PrintRoutingFoundationTest runs on a hand-rolled SQLite mini-schema
 * and skips/fails around the missing `categories` table ("no such table: categories")
 * because reminderRoutesForSale traverses lines.product.category. Here the REAL tenant
 * migrations build `categories`, so the same service runs against the real schema on
 * MySQL — explicitly closing that gap.
 */
class PrintRoutingMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    public function test_reminder_routing_runs_against_real_schema_with_categories(): void
    {
        $this->cleanTenant([
            'category_printer_mappings', 'print_jobs', 'printers',
            'sales_order_lines', 'sales_orders', 'products', 'categories', 'branches',
        ]);

        $branchId = $this->makeBranch();
        $catId    = $this->makeCategory(['name' => 'Kitchen']);
        $prodId   = $this->makeProduct($catId, ['name' => 'Burger']);
        $printerId = $this->makePrinter(['name' => 'Kitchen Reminder', 'supports_reminder' => 1, 'is_active' => 1]);

        DB::connection('tenant')->table('category_printer_mappings')->insert([
            'branch_id' => $branchId, 'category_id' => $catId, 'printer_id' => $printerId,
            'print_role' => 'reminder', 'order_type' => 'dine_in',
            'reminder_confirm_on_addition' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $saleId = $this->makeSale($branchId, ['order_type' => 'dine_in']);
        $this->makeSaleLine($saleId, $prodId, ['product_name' => 'Burger', 'quantity' => 2]);

        $sale = SalesOrder::on('tenant')->find($saleId);

        // The real service, traversing lines.product.category on the real categories table.
        $routes = app(PrintRoutingService::class)->reminderRoutesForSale($sale);

        $this->assertCount(1, $routes, 'Reminder must route to the one qualifying reminder printer.');
        $this->assertSame($printerId, (int) $routes[0]['printer']->id);
        $this->assertFalse($routes[0]['ask_on_addition']);
    }

    public function test_reminder_routing_is_order_type_aware(): void
    {
        $this->cleanTenant([
            'category_printer_mappings', 'printers',
            'sales_order_lines', 'sales_orders', 'products', 'categories', 'branches',
        ]);

        $branchId = $this->makeBranch();
        $catId    = $this->makeCategory();
        $prodId   = $this->makeProduct($catId);
        $printerId = $this->makePrinter(['supports_reminder' => 1]);

        // Mapping is for takeaway only.
        DB::connection('tenant')->table('category_printer_mappings')->insert([
            'branch_id' => $branchId, 'category_id' => $catId, 'printer_id' => $printerId,
            'print_role' => 'reminder', 'order_type' => 'takeaway',
            'reminder_confirm_on_addition' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A dine_in sale must NOT match a takeaway-only mapping.
        $saleId = $this->makeSale($branchId, ['order_type' => 'dine_in']);
        $this->makeSaleLine($saleId, $prodId);
        $sale = SalesOrder::on('tenant')->find($saleId);

        $this->assertCount(0, app(PrintRoutingService::class)->reminderRoutesForSale($sale),
            'order_type-aware routing must not leak a takeaway mapping into a dine_in sale.');
    }
}
