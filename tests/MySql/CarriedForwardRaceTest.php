<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Services\Printing\DirectPayPrintOrchestrator;
use App\Services\Sales\ShiftService;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-RUNTIME-BOUNDARY-1 (S) — the MySQL baseline correctness gates carried forward from
 * MYSQL-TEST-FOUNDATION, proven with GENUINE two-process concurrency against the REAL services.
 * These are CLOUD regressions, not Edge features.
 *
 *   1. print_jobs.logical_key race        -> exactly one logical automatic receipt job.
 *   2. Direct-Pay resume race             -> receipt/KOT converge; no new KOT revision; copy_no stable.
 *   3. restaurant open-check / table race -> exactly one open check; controlled loser; no 500.
 */
class CarriedForwardRaceTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    // ── 1. logical_key ────────────────────────────────────────────────────────────────────────
    public function test_logical_key_receipt_race_yields_one_logical_job(): void
    {
        $this->cleanTenant(['print_jobs', 'sales_orders', 'branches']);
        $branchId = $this->makeBranch();
        $saleId = $this->makeSale($branchId, ['status' => 'paid']);

        $worker = base_path('tests/MySql/Support/print_receipt_worker.php');
        $out = $this->raceTwo([$worker, (string) $saleId], [$worker, (string) $saleId]);

        $this->assertNoErrors($out);
        $logicalKey = 'receipt:auto:sale-' . $saleId;
        $this->assertSame(1, $this->tenant()->table('print_jobs')->where('logical_key', $logicalKey)->count(),
            'Exactly one automatic receipt job for the logical key: ' . implode(' | ', $out));
        $this->assertSame(1, (int) $this->tenant()->table('print_jobs')->where('logical_key', $logicalKey)->value('copy_no'),
            'copy_no must not increment on a raced ensure-once receipt.');
    }

    // ── 2. Direct-Pay resume ──────────────────────────────────────────────────────────────────
    public function test_direct_pay_resume_race_converges_without_new_revision(): void
    {
        $this->cleanTenant(['kot_batch_lines', 'kot_batches', 'print_jobs', 'sales_order_lines', 'sales_orders', 'category_printer_mappings', 'printers', 'products', 'categories', 'branches']);
        $branchId = $this->makeBranch();
        $terminalId = $this->makeTerminal($branchId);
        $categoryId = $this->makeCategory();
        $productId = $this->makeProduct($categoryId);
        $printerId = $this->makePrinter(['print_role' => 'kot']);
        // KOT route: this category -> this printer for all order types, so a KOT batch is produced.
        $this->tenant()->table('category_printer_mappings')->insert([
            'branch_id' => $branchId, 'category_id' => $categoryId, 'printer_id' => $printerId,
            'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $saleId = $this->makeSale($branchId, ['status' => 'paid', 'terminal_id' => $terminalId]);
        $this->makeSaleLine($saleId, $productId, ['quantity' => 2]);
        SalesOrder::on('tenant')->find($saleId)->update([
            'direct_pay_print_state' => DirectPayPrintOrchestrator::initialState('print', 'print'),
        ]);

        $worker = base_path('tests/MySql/Support/direct_pay_resume_worker.php');
        $out = $this->raceTwo([$worker, (string) $saleId], [$worker, (string) $saleId]);

        $this->assertNoErrors($out);
        // One automatic receipt (logical_key), one KOT batch (no new revision), copy_no stable.
        $this->assertSame(1, $this->tenant()->table('print_jobs')->where('logical_key', 'receipt:auto:sale-' . $saleId)->count(),
            'Resume must not create a duplicate receipt: ' . implode(' | ', $out));
        $this->assertSame(1, $this->tenant()->table('kot_batches')->where('sales_order_id', $saleId)->count(),
            'Resume must not create a new KOT revision/batch: ' . implode(' | ', $out));
        $this->assertLessThanOrEqual(1, (int) $this->tenant()->table('kot_batches')->where('sales_order_id', $saleId)->max('copy_no'),
            'copy_no must not increment on resume.');
    }

    // ── 3. open-check / table race ────────────────────────────────────────────────────────────
    public function test_open_table_race_yields_one_open_check(): void
    {
        $this->cleanTenant(['restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminalId = $this->makeTerminal($branchId);
        $userId = $this->makeUser();
        $tableId = $this->makeTable($branchId);
        app(ShiftService::class)->open(Branch::on('tenant')->find($branchId), Terminal::on('tenant')->find($terminalId), $userId, 0.0);

        $worker = base_path('tests/MySql/Support/open_table_worker.php');
        $args = [$worker, (string) $branchId, (string) $tableId, (string) $terminalId, (string) $userId];
        $out = $this->raceTwo($args, $args);

        $this->assertNoErrors($out);
        $this->assertSame(1, $this->tenant()->table('restaurant_table_sessions')->where('restaurant_table_id', $tableId)->where('status', 'open')->count(),
            'Exactly one open check for the table: ' . implode(' | ', $out));
        $opened = count(array_filter($out, fn ($o) => str_starts_with($o, 'OPENED:')));
        $losers = count(array_filter($out, fn ($o) => $o === 'LOSER'));
        $this->assertSame(1, $opened, 'One worker opens: ' . implode(' | ', $out));
        $this->assertSame(1, $losers, 'The other gets a controlled loser (no 500): ' . implode(' | ', $out));
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────
    private function raceTwo(array $argsA, array $argsB): array
    {
        $env = array_merge(getenv() ?: [], ['EDGE_TEST_TENANT_DB' => $this->tenantDb, 'APP_ENV' => 'testing']);
        $procs = [];
        $pipes = [];
        foreach ([$argsA, $argsB] as $i => $args) {
            $procs[$i] = proc_open(array_merge([PHP_BINARY], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path(), $env);
        }
        $out = [];
        foreach ([0, 1] as $i) {
            $out[$i] = trim(stream_get_contents($pipes[$i][1]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($procs[$i]);
        }

        return $out;
    }

    private function assertNoErrors(array $out): void
    {
        $errors = array_filter($out, fn ($o) => str_contains($o, 'ERROR') || $o === '');
        $this->assertEmpty($errors, 'No worker may crash / 500: ' . implode(' | ', $out));
    }
}
