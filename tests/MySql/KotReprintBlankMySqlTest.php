<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Printing\EscPosPayloadService;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * KOT-REPRINT-BLANK-1 — a reprinted kitchen ticket must never come out empty.
 *
 * The line ids are frozen into the print job, but the LINES were read live, and saving a held bill
 * deletes and recreates every line with a new id. After that the whereIn matched nothing and the
 * ticket printed its heading over an empty list — no items, no error, not even the station name,
 * which is built from the items.
 *
 * Measured on 31 Aug: 45 of Kashif Food's 81 tickets and 61 of Khatri's 119 were in that state,
 * though none had actually been reprinted, so no blank slip ever reached a kitchen.
 *
 * The job already carries a full snapshot of what it sent. This is the guard that it gets used.
 */
class KotReprintBlankMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $terminalId;
    private int $printerId;
    private int $productId;
    private int $secondProductId;

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

        $userId = $this->makeUser(['employee_code' => 'RB' . Str::random(4)]);
        $this->actingAs(User::on('tenant')->find($userId), 'tenant');
        Auth::shouldUse('tenant');

        $this->branchId = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->printerId = $this->makePrinter([
            'code' => 'KF-P-BBQ', 'print_role' => 'both', 'branch_id' => $this->branchId,
            'supports_reminder' => 1,
        ]);

        $category = $this->makeCategory(['name' => 'Bar-B-Que', 'slug' => 'bbq-' . Str::random(4)]);
        foreach (['kot', 'reminder'] as $role) {
            DB::connection('tenant')->table('category_printer_mappings')->insert([
                'branch_id' => $this->branchId, 'category_id' => $category,
                'printer_id' => $this->printerId, 'print_role' => $role,
                'order_type' => 'all', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->productId = $this->makeProduct($category, ['name' => 'Chicken Tikka (Chest)']);
        $this->secondProductId = $this->makeProduct($category, ['name' => 'Chicken Baluchi Boti']);
    }

    private function slip(PrintJob $job): string
    {
        $raw = app(EscPosPayloadService::class)->build($job);
        $t = preg_replace('/\x1b@|\x1d\x56[\x00-\xff]{1,2}|\x1b[!aEMdG][\x00-\xff]?|\x1d[!Bh][\x00-\xff]?|\x1b[23][\x00-\xff]?/', '', $raw);

        return trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $t));
    }

    /** Everything between the QTY ITEM heading and the closing rule. */
    private function itemsOn(PrintJob $job): string
    {
        preg_match('/QTY ITEM\s*-+\s*(.*?)\s*-+\s*$/s', $this->slip($job), $m);

        return trim($m[1] ?? '');
    }

    /** The POS rewrites the whole line set on every save, so every id changes. */
    private function saveTheBill(int $saleId): void
    {
        $rows = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->get();
        DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->delete();
        foreach ($rows as $row) {
            $data = (array) $row;
            unset($data['id']);
            DB::connection('tenant')->table('sales_order_lines')->insert($data);
        }
    }

    private function heldOrderWithTwoItems(): SalesOrder
    {
        $saleId = $this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in', 'terminal_id' => $this->terminalId,
        ]);
        $this->makeSaleLine($saleId, $this->productId, [
            'product_name' => 'Chicken Tikka (Chest)', 'quantity' => 1, 'kot_sent_quantity' => 0,
        ]);
        $this->makeSaleLine($saleId, $this->secondProductId, [
            'product_name' => 'Chicken Baluchi Boti', 'quantity' => 2, 'kot_sent_quantity' => 0,
        ]);

        return SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);
    }

    /** THE defect: the bill is saved, the old ticket is reprinted, and it must still carry its food. */
    public function test_a_reprint_after_the_bill_was_saved_still_carries_its_items(): void
    {
        $sale = $this->heldOrderWithTwoItems();
        $jobs = app(PrintJobService::class)->queueKot(sale: $sale, terminalId: (string) $this->terminalId);
        $job = $jobs[0];

        $this->assertStringContainsString('CHICKEN TIKKA', strtoupper($this->itemsOn($job->refresh())),
            'the ticket must carry its items when first printed');

        $this->saveTheBill($sale->id);

        $reprinted = strtoupper($this->itemsOn(PrintJob::on('tenant')->find($job->id)));

        $this->assertNotSame('', $reprinted, 'a reprint must never be a blank ticket');
        $this->assertStringContainsString('CHICKEN TIKKA', $reprinted);
        $this->assertStringContainsString('CHICKEN BALUCHI BOTI', $reprinted);
    }

    /** The quantity has to survive too — a ticket saying 1 where 2 were ordered is worse than blank. */
    public function test_the_reprint_keeps_the_right_quantities(): void
    {
        $sale = $this->heldOrderWithTwoItems();
        $job = app(PrintJobService::class)->queueKot(sale: $sale, terminalId: (string) $this->terminalId)[0];
        $before = $this->itemsOn($job->refresh());

        $this->saveTheBill($sale->id);

        $this->assertSame(
            preg_replace('/\s+/', ' ', $before),
            preg_replace('/\s+/', ' ', $this->itemsOn(PrintJob::on('tenant')->find($job->id))),
            'the reprint is a copy of what was sent — same items, same quantities, same order'
        );
    }

    /**
     * While the lines are still there, nothing changes: the reprint keeps reading them live, so
     * every ticket that works today goes on working exactly as it does.
     */
    public function test_a_reprint_with_the_lines_intact_is_unchanged(): void
    {
        $sale = $this->heldOrderWithTwoItems();
        $job = app(PrintJobService::class)->queueKot(sale: $sale, terminalId: (string) $this->terminalId)[0];

        $first = $this->slip($job->refresh());
        $second = $this->slip(PrintJob::on('tenant')->find($job->id));

        $this->assertSame(
            preg_replace('/TIME:.*|PRINT:.*/', '', $first),
            preg_replace('/TIME:.*|PRINT:.*/', '', $second),
            'nothing about the ordinary path may change'
        );
    }

    /** The snapshot is only a fallback — a line edited after printing still prints live. */
    public function test_live_lines_win_while_they_exist(): void
    {
        $sale = $this->heldOrderWithTwoItems();
        $job = app(PrintJobService::class)->queueKot(sale: $sale, terminalId: (string) $this->terminalId)[0];

        // rename the product on the live line, keeping its id
        DB::connection('tenant')->table('sales_order_lines')
            ->where('sales_order_id', $sale->id)->where('product_id', $this->productId)
            ->update(['product_name' => 'Chicken Tikka (Leg)']);

        $this->assertStringContainsString('CHICKEN TIKKA (LEG)',
            strtoupper($this->itemsOn(PrintJob::on('tenant')->find($job->id))),
            'the live line is still the source while it exists — the snapshot must not override it');
    }

    /** A cancellation ticket already rendered from the snapshot, and must keep doing so. */
    public function test_the_cancellation_path_is_untouched(): void
    {
        $sale = $this->heldOrderWithTwoItems();
        $service = app(PrintJobService::class);
        $service->queueKot(sale: $sale, terminalId: (string) $this->terminalId);

        $sale = $sale->refresh()->load('lines');
        $result = $service->queueCancellationKot(
            sale: $sale,
            lineQuantities: [$sale->lines->first()->id => 1.0],
            terminalId: (string) $this->terminalId,
        );

        $jobs = $result['jobs'] ?? [];
        $this->assertNotEmpty($jobs, 'cancelling sent food must still raise a ticket');
        $this->assertStringContainsString('CHICKEN TIKKA',
            strtoupper($this->slip(collect($jobs)->first()->refresh())));
    }
}
