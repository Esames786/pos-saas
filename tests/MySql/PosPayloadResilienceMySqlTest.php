<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * Three small guards over things that broke on 30 Aug and were only caught by hand.
 *
 *  HIDDEN-PRODUCT-HELD-BILL-1  hiding a product must not make an open bill unpayable
 *  EMPTY-DEAL-PILL-1           a category with nothing in it must not claim a tab
 *  REMINDER-REPRINT-SNAPSHOT-1 a reprint keeps its items but tells the truth about the table
 */
class PosPayloadResilienceMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors',
            'combo_components', 'combos', 'category_printer_mappings', 'printers',
            'products', 'categories', 'terminals', 'branches', 'users',
        ]);

        $this->userId = $this->makeUser(['employee_code' => 'PR' . Str::random(4)]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->branchId = $this->makeBranch();
    }

    /** The payload query POSController builds, reproduced so the three OR-branches can be asserted. */
    private function payloadIds(): array
    {
        $comboComponentIds = DB::connection('tenant')->table('combo_components as cc')
            ->join('combos as k', 'k.id', '=', 'cc.combo_id')
            ->where('k.status', 'active')->pluck('cc.product_id')->map(fn ($i) => (int) $i)->unique();

        $liveOrderIds = DB::connection('tenant')->table('sales_order_lines as l')
            ->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->whereIn('o.status', ['held', 'draft'])->where('o.branch_id', $this->branchId)
            ->whereNotNull('l.product_id')->pluck('l.product_id')->map(fn ($i) => (int) $i)->unique();

        return DB::connection('tenant')->table('products')
            ->where('status', 'active')->where('is_sellable', true)
            ->where(function ($q) use ($comboComponentIds, $liveOrderIds) {
                $q->where('is_pos_visible', true);
                if ($comboComponentIds->isNotEmpty()) { $q->orWhereIn('id', $comboComponentIds->all()); }
                if ($liveOrderIds->isNotEmpty()) { $q->orWhereIn('id', $liveOrderIds->all()); }
            })
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** 30 Aug: a product hidden while five bills still carried it — every one became unpayable. */
    public function test_a_hidden_product_on_an_open_bill_stays_in_the_payload(): void
    {
        $cat = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice-' . Str::random(4)]);
        $product = $this->makeProduct($cat, ['name' => 'Singaporean Rice (Khass)']);

        $saleId = $this->makeSale($this->branchId, ['status' => 'held', 'order_type' => 'dine_in']);
        $this->makeSaleLine($saleId, $product, ['quantity' => 1]);

        // Hidden, and in NO combo — exactly the state that broke the bills.
        DB::connection('tenant')->table('products')->where('id', $product)
            ->update(['is_pos_visible' => 0, 'is_sellable' => 1]);

        $this->assertContains($product, $this->payloadIds(),
            'a product on an open bill must stay reachable, or the bill cannot be recalled or paid.');
    }

    /** Once the bill is paid the product has no claim on the payload any more. */
    public function test_a_hidden_product_leaves_the_payload_once_the_bill_is_settled(): void
    {
        $cat = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice-' . Str::random(4)]);
        $product = $this->makeProduct($cat, ['name' => 'Singaporean Rice (Khass)']);
        $saleId = $this->makeSale($this->branchId, ['status' => 'held', 'order_type' => 'dine_in']);
        $this->makeSaleLine($saleId, $product, ['quantity' => 1]);
        DB::connection('tenant')->table('products')->where('id', $product)->update(['is_pos_visible' => 0]);

        $this->assertContains($product, $this->payloadIds());

        DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->update(['status' => 'paid']);

        $this->assertNotContains($product, $this->payloadIds(), 'nothing open holds it now.');
    }

    /** is_sellable=0 still removes it entirely — the trap that made three platters "Unavailable". */
    public function test_is_sellable_false_still_removes_a_product_completely(): void
    {
        $cat = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice-' . Str::random(4)]);
        $product = $this->makeProduct($cat, ['name' => 'Dhaka Chicken']);
        $saleId = $this->makeSale($this->branchId, ['status' => 'held', 'order_type' => 'dine_in']);
        $this->makeSaleLine($saleId, $product, ['quantity' => 1]);

        DB::connection('tenant')->table('products')->where('id', $product)->update(['is_sellable' => 0]);

        $this->assertNotContains($product, $this->payloadIds(),
            'is_sellable is the outer gate — this is why it must never be used to "hide" something.');
    }

    /** EMPTY-DEAL-PILL-1: a sub-category whose combos were retired holds no content. */
    public function test_a_category_with_no_active_combo_holds_no_content(): void
    {
        $parent = $this->makeCategory(['name' => 'Deals', 'slug' => 'deals-' . Str::random(4)]);
        $withCombo = $this->makeCategory(['name' => 'Midnight', 'slug' => 'mid-' . Str::random(4), 'parent_id' => $parent]);
        $emptied = $this->makeCategory(['name' => 'Al-Faham', 'slug' => 'alf-' . Str::random(4), 'parent_id' => $parent]);

        DB::connection('tenant')->table('combos')->insert([
            ['name' => 'Live deal', 'code' => 'L1', 'price' => 100, 'status' => 'active',
             'category_id' => $withCombo, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Retired deal', 'code' => 'R1', 'price' => 100, 'status' => 'inactive',
             'category_id' => $emptied, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $content = DB::connection('tenant')->table('combos')->where('status', 'active')
            ->pluck('category_id')->map(fn ($i) => (int) $i)->unique()->all();

        $this->assertContains($withCombo, $content);
        $this->assertNotContains($emptied, $content,
            'a retired combo leaves nothing behind — its category must not render a tab.');
    }

    /** REMINDER-REPRINT-SNAPSHOT-1: items stay frozen, the table does not. */
    public function test_a_reprinted_reminder_shows_the_table_the_order_is_on_now(): void
    {
        $cat = $this->makeCategory(['name' => 'Beverages', 'slug' => 'bev-' . Str::random(4)]);
        $printer = $this->makePrinter(['code' => 'P1', 'print_role' => 'both', 'branch_id' => $this->branchId, 'supports_reminder' => 1]);
        $terminal = $this->makeTerminal($this->branchId);
        foreach (['kot', 'reminder'] as $role) {
            DB::connection('tenant')->table('category_printer_mappings')->insert([
                'branch_id' => $this->branchId, 'category_id' => $cat, 'printer_id' => $printer,
                'print_role' => $role, 'order_type' => 'all', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $drink = $this->makeProduct($cat, ['name' => 'Soft Drink (345 ml)']);
        $tableEighteen = $this->makeTable($this->branchId, ['table_no' => '18']);
        $tableFive = $this->makeTable($this->branchId, ['table_no' => '5']);

        $saleId = $this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in', 'terminal_id' => $terminal,
            'restaurant_table_id' => $tableEighteen,
        ]);
        $this->makeSaleLine($saleId, $drink, ['product_name' => 'Soft Drink (345 ml)', 'quantity' => 1, 'kot_sent_quantity' => 0]);
        $sale = \App\Models\Tenant\SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);

        $service = app(PrintJobService::class);
        $original = $service->planRemindersForKotJobs($sale, $service->queueKot(sale: $sale, terminalId: (string) $terminal));
        $originalJob = $original['auto_jobs'][0];
        $this->assertSame('18', (string) data_get($originalJob->payload, 'table'));

        // The check is moved to table 5, then someone asks for the reminder again.
        DB::connection('tenant')->table('sales_orders')->where('id', $saleId)->update(['restaurant_table_id' => $tableFive]);
        $reprint = $service->queueReminderReprint($originalJob->refresh());

        $this->assertSame('5', (string) data_get($reprint->payload, 'table'),
            'the slip says where the food has to go NOW.');
        $this->assertTrue((bool) data_get($reprint->payload, 'is_reprint'));
        $this->assertSame(
            data_get($originalJob->payload, 'generated_at'),
            data_get($reprint->payload, 'generated_at'),
            'everything else stays frozen — it is still a duplicate of the original slip.'
        );
    }
}
