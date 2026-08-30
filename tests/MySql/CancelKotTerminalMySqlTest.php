<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * RECALL-REPRINT-TERMINAL-2 — a cancellation prints at the counter that RAISED it.
 *
 * RECALL-REPRINT-TERMINAL-1 gave every print route an optional terminal override so a cashier who
 * recalls another counter's order sees the reprint at their OWN counter. The cancellation path was
 * the one branch never threaded: it hard-coded the sale's terminal, so Counter T2 cancelling a
 * Floor T4 order sent the Cancel KOT and the correction reminder to T4's printer.
 *
 * Guards both halves, and the no-override default — a cancellation with no terminal passed must
 * route exactly as it always did, so no existing tenant changes behaviour.
 */
class CancelKotTerminalMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $floorTerminal;      // T4 — created the order
    private int $counterTerminal;    // T2 — recalls and cancels it
    private int $floorPrinter;
    private int $counterPrinter;
    private int $drinksCat;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_line_cancellations',
            'void_reasons', 'sales_order_lines', 'sales_orders', 'category_printer_mappings',
            'terminal_printer_settings', 'printers', 'products', 'categories', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $this->drinksCat = $this->makeCategory(['name' => 'Beverages', 'slug' => 'bev']);
        $this->floorTerminal = $this->makeTerminal($this->branchId, ['code' => 'T4', 'name' => 'DTQ Floor']);
        $this->counterTerminal = $this->makeTerminal($this->branchId, ['code' => 'T2', 'name' => 'DTQ 1']);
        $this->floorPrinter = $this->makePrinter(['code' => 'P-T4', 'print_role' => 'both', 'branch_id' => $this->branchId, 'supports_reminder' => 1]);
        $this->counterPrinter = $this->makePrinter(['code' => 'P-T2', 'print_role' => 'both', 'branch_id' => $this->branchId, 'supports_reminder' => 1]);

        // Beverages is terminal-pinned to each counter's own printer — the Kashif shape.
        foreach ([[$this->floorTerminal, $this->floorPrinter], [$this->counterTerminal, $this->counterPrinter]] as [$term, $printer]) {
            foreach (['kot', 'reminder'] as $role) {
                DB::connection('tenant')->table('category_printer_mappings')->insert([
                    'branch_id' => $this->branchId, 'terminal_id' => $term, 'category_id' => $this->drinksCat,
                    'printer_id' => $printer, 'print_role' => $role, 'order_type' => 'all', 'is_active' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** A held order punched at the FLOOR terminal, its drink already sent to the kitchen. */
    private function floorOrder(): SalesOrder
    {
        $drink = $this->makeProduct($this->drinksCat, ['name' => 'Soft Drink (345 ml)']);
        $saleId = $this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in', 'terminal_id' => $this->floorTerminal,
        ]);
        $this->makeSaleLine($saleId, $drink, [
            'product_name' => 'Soft Drink (345 ml)', 'quantity' => 1, 'kot_sent_quantity' => 1,
        ]);

        return SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);
    }

    private function printerOf(array $jobs): array
    {
        return collect($jobs)->pluck('printer_id')->unique()->values()->all();
    }

    /** THE BUG: cancelled from the counter, the Cancel KOT must print at the COUNTER. */
    public function test_cancel_kot_follows_the_cancelling_terminal(): void
    {
        $sale = $this->floorOrder();
        $quantities = [(string) $sale->lines->first()->id => 1.0];

        $queued = app(PrintJobService::class)->queueCancellationKot($sale, $quantities, (string) $this->counterTerminal);

        $this->assertSame([$this->counterPrinter], $this->printerOf($queued['jobs']),
            'a cancellation raised at T2 must print at T2, not at the counter that created the order.');
        $this->assertSame(
            $this->floorTerminal,
            (int) SalesOrder::on('tenant')->findOrFail($sale->id)->terminal_id,
            'the sale row is NEVER re-stamped — cash, shift and closing stay with the original terminal.'
        );
    }

    /** The correction reminder follows it too — that half was missing as well. */
    public function test_cancellation_reminder_reaches_the_cancelling_terminal(): void
    {
        $sale = $this->floorOrder();
        $quantities = [(string) $sale->lines->first()->id => 1.0];

        $queued = app(PrintJobService::class)->queueCancellationKot($sale, $quantities, (string) $this->counterTerminal);
        $jobs = app(PrintJobService::class)->queueCancellationReminders($sale, $queued['batch'], true, (string) $this->counterTerminal);

        $this->assertContains($this->counterPrinter, $this->printerOf($jobs),
            'the counter that cancelled must be told, on paper, at its own printer.');
    }

    /**
     * ONE slip, not two. The order was punched at the floor and its ORIGINAL reminder printed there,
     * so the sale's reminder history holds the floor printer — the counter that cancels must still
     * not send a second copy of the same cancellation back to it.
     */
    public function test_the_cancellation_does_not_also_print_at_the_original_counter(): void
    {
        $drink = $this->makeProduct($this->drinksCat, ['name' => 'Soft Drink (345 ml)']);
        $saleId = $this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in', 'terminal_id' => $this->floorTerminal,
        ]);
        // kot_sent_quantity 0 so the first KOT really fires and leaves a reminder at the FLOOR.
        $this->makeSaleLine($saleId, $drink, ['product_name' => 'Soft Drink (345 ml)', 'quantity' => 1, 'kot_sent_quantity' => 0]);
        $sale = SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);

        $service = app(PrintJobService::class);
        $originalKot = $service->queueKot(sale: $sale, terminalId: (string) $this->floorTerminal);
        $service->planRemindersForKotJobs($sale, $originalKot);

        $this->assertSame([$this->floorPrinter], $this->printerOf($originalKot),
            'the original punch belongs to the floor — this is the history the fix must not re-use.');

        $sale->refresh()->load('lines');
        $quantities = [(string) $sale->lines->first()->id => 1.0];
        $queued = $service->queueCancellationKot($sale, $quantities, (string) $this->counterTerminal);
        $reminders = $service->queueCancellationReminders($sale, $queued['batch'], true, (string) $this->counterTerminal);

        $this->assertSame([$this->counterPrinter], $this->printerOf($queued['jobs']),
            'the cancel KOT prints ONLY at the counter that cancelled.');
        $this->assertSame([$this->counterPrinter], $this->printerOf($reminders),
            'and so does the correction reminder — no duplicate slip at the floor.');
    }

    /**
     * A LINE-ITEM void (wholeOrder = false) behaves the same. This path differs: the remaining lines
     * are still active, so routing DOES return the sale's own terminal — the override has to win
     * over it, not merely fill a gap.
     */
    public function test_a_line_item_void_also_prints_only_at_the_voiding_counter(): void
    {
        $drink = $this->makeProduct($this->drinksCat, ['name' => 'Soft Drink (345 ml)']);
        $saleId = $this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in', 'terminal_id' => $this->floorTerminal,
        ]);
        $this->makeSaleLine($saleId, $drink, ['product_name' => 'Soft Drink (345 ml)', 'quantity' => 2, 'kot_sent_quantity' => 0]);
        $sale = SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);

        $service = app(PrintJobService::class);
        $service->planRemindersForKotJobs($sale, $service->queueKot(sale: $sale, terminalId: (string) $this->floorTerminal));

        // Void ONE of the two — the order lives on, so the sale's terminal still routes.
        $sale->refresh()->load('lines');
        $quantities = [(string) $sale->lines->first()->id => 1.0];
        $queued = $service->queueCancellationKot($sale, $quantities, (string) $this->counterTerminal);
        $reminders = $service->queueCancellationReminders($sale, $queued['batch'], false, (string) $this->counterTerminal);

        $this->assertSame([$this->counterPrinter], $this->printerOf($queued['jobs']),
            'a line void prints its cancel KOT at the counter that voided.');
        $this->assertSame([$this->counterPrinter], $this->printerOf($reminders),
            'and the updated-order reminder goes there too — not back to the floor.');
    }

    /** No override = the old behaviour, exactly. Nothing changes for anyone who passes nothing. */
    public function test_without_an_override_it_still_routes_on_the_sales_own_terminal(): void
    {
        $sale = $this->floorOrder();
        $quantities = [(string) $sale->lines->first()->id => 1.0];

        $queued = app(PrintJobService::class)->queueCancellationKot($sale, $quantities, null);

        $this->assertSame([$this->floorPrinter], $this->printerOf($queued['jobs']),
            'with no terminal passed a cancellation must route on the sale, as it always did.');
    }

    /** An operator may only aim a cancellation at a terminal they are allowed to work on. */
    public function test_a_foreign_terminal_is_refused_and_falls_back(): void
    {
        $scope = app(\App\Services\Security\UserDataScope::class);

        $bound = \App\Models\Tenant\User::on('tenant')->findOrFail($this->makeUser(['email' => 'counter@example.test']));
        $bound->terminals()->sync([$this->counterTerminal]);

        $this->assertSame((string) $this->counterTerminal, $scope->operatorTerminalId($bound, $this->counterTerminal),
            'own terminal is honoured.');
        $this->assertNull($scope->operatorTerminalId($bound, $this->floorTerminal),
            'a terminal the operator is not bound to must be refused, not silently used.');
        $this->assertNull($scope->operatorTerminalId($bound, ''), 'blank means "no override".');
    }
}
