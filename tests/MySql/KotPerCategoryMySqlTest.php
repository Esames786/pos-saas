<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KOT-PER-CATEGORY-1 (Khatri, 2026-08-11): the kitchen wants ONE ticket per category, not every
 * category merged onto a single slip per printer. The critical case is two categories routed to
 * the SAME printer: they must produce TWO jobs — the job's logical key carries the category, so
 * the second can no longer dedupe into the first.
 */
class KotPerCategoryMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    public function test_each_category_gets_its_own_kot_even_on_a_shared_printer(): void
    {
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders', 'category_printer_mappings', 'printers', 'products', 'categories', 'terminals', 'branches', 'users']);

        $branchId = $this->makeBranch();
        $biryani = $this->makeCategory(['name' => 'Biryani', 'slug' => 'biryani']);
        $chicken = $this->makeCategory(['name' => 'Chicken', 'slug' => 'chicken']);
        $drinks = $this->makeCategory(['name' => 'Beverages', 'slug' => 'beverages']);

        $kitchen = $this->makePrinter(['code' => 'P1', 'name' => 'Kitchen', 'print_role' => 'both', 'branch_id' => $branchId, 'is_default' => 1]);
        $bar = $this->makePrinter(['code' => 'P2', 'name' => 'Bar', 'print_role' => 'kot', 'branch_id' => $branchId, 'is_default' => 0]);

        // Biryani AND Chicken share the kitchen printer; Beverages go to the bar printer.
        foreach ([[$biryani, $kitchen], [$chicken, $kitchen], [$drinks, $bar]] as [$categoryId, $printerId]) {
            DB::connection('tenant')->table('category_printer_mappings')->insert([
                'branch_id' => $branchId, 'category_id' => $categoryId, 'printer_id' => $printerId,
                'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $saleId = $this->makeSale($branchId, ['order_type' => 'delivery', 'status' => 'held']);
        foreach ([[$biryani, 'Beef Khatri Biryani'], [$chicken, 'Chicken Biryani'], [$drinks, 'Cola Next 500 ml']] as [$categoryId, $name]) {
            $this->makeSaleLine($saleId, $this->makeProduct($categoryId, ['name' => $name]), ['product_name' => $name, 'quantity' => 2]);
        }

        $jobs = app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($saleId));

        $this->assertCount(3, $jobs, 'three categories → three KOT tickets (two of them on the SAME printer)');

        $byCategory = collect($jobs)->mapWithKeys(fn (PrintJob $j) => [
            $j->payload['kot_category'] => ['printer' => $j->printer_id, 'lines' => count($j->payload['line_ids'])],
        ]);
        $this->assertEqualsCanonicalizing(['Biryani', 'Chicken', 'Beverages'], $byCategory->keys()->all());
        $this->assertSame($kitchen, $byCategory['Biryani']['printer']);
        $this->assertSame($kitchen, $byCategory['Chicken']['printer'], 'same printer as Biryani — still its own ticket');
        $this->assertSame($bar, $byCategory['Beverages']['printer']);
        foreach ($byCategory as $category => $info) {
            $this->assertSame(1, $info['lines'], "{$category} ticket carries only its own line");
        }

        // distinct logical keys are what stop the shared-printer tickets deduping into one
        $this->assertSame(3, collect($jobs)->pluck('logical_key')->unique()->count());
        // ...and they all belong to the SAME KOT round (one sequence number, printed as 3 slips)
        $this->assertSame(1, collect($jobs)->pluck('payload.kot_batch_id')->unique()->count());

        // the physical payload names the station and prints no piece-unit noise
        $payload = $jobs[0]->raw_payload;
        $this->assertStringContainsString('[ ', $payload, 'category header on the ticket');
        $this->assertStringNotContainsString(' EA', $payload, 'piece units are suppressed');
    }
}
