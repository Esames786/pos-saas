<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-LOCAL-POS-1 (restaurant closure) — GENUINE two-process restaurant races through the REAL
 * EdgeLocalPosService against real MySQL: independent PHP OS processes, spin-barrier start, and the
 * master DB pointed at a nonexistent database INSIDE the workers (pure branch-local authority).
 *
 *   A. same table opened from two terminals   → exactly ONE open session, loser controlled.
 *   B. same unsent round sent to kitchen twice → exactly ONE KOT business event, sent-qty once,
 *      nothing marked printed.
 *   C. conflicting Add Round from one snapshot → serialized; the stale edit is REFUSED (carried line
 *      ids churned), never silent last-writer-wins over KOT-sent state.
 *   D. settle-last-check vs new hold on the session → NEVER a closed session (or freed table) with a
 *      surviving held order; shift close blocked while any work survives.
 */
class EdgeLocalRestaurantRaceTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminal1;
    private int $terminal2;
    private int $userId;
    private int $tableId;
    private int $productId;
    private int $product2Id;
    private int $cashMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_meta', 'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs', 'category_printer_mappings', 'terminal_printer_settings', 'printers', 'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RR' . Str::random(4)]);
        $this->terminal1 = $this->makeTerminal($this->branchId);
        $this->terminal2 = $this->makeTerminal($this->branchId);
        $this->tableId = $this->makeTable($this->branchId, ['status' => 'available']);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->product2Id = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([
            ['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->product2Id, 'product_variant_id' => null, 'quantity' => 50],
        ]);
        // in-process setup calls (holds/opens) need the authenticated principal the service demands.
        Auth::guard('tenant')->setUser(User::on('tenant')->find($this->userId));
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function openShift(int $terminalId): Shift
    {
        return app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($terminalId), $this->userId, 0.0);
    }

    private function worker(array $args, string $startFile): array
    {
        $cmd = array_merge([PHP_BINARY, base_path('tests/MySql/Support/edge_pos_sale_worker.php')], array_map('strval', $args));
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'EDGE_TEST_TENANT_DB' => $this->tenantDb,
            'APP_ENV' => 'testing',
            'START_FILE' => $startFile,
        ]));

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function finish(array $h): string
    {
        $out = trim(stream_get_contents($h['pipes'][1]));
        $err = trim(stream_get_contents($h['pipes'][2]) ?: '');
        fclose($h['pipes'][1]);
        fclose($h['pipes'][2]);
        proc_close($h['proc']);

        return $out !== '' ? $out : 'STDERR:' . $err;
    }

    private function race(array $argsA, array $argsB): array
    {
        $startFile = sys_get_temp_dir() . '/edge_rest_race_' . Str::random(8) . '.start';
        @unlink($startFile);
        $a = $this->worker($argsA, $startFile);
        $b = $this->worker($argsB, $startFile);
        sleep(4);
        file_put_contents($startFile, '1');
        $outA = $this->finish($a);
        $outB = $this->finish($b);
        @unlink($startFile);

        return [$outA, $outB];
    }

    /** Controlled refusal = a domain exception, never a raw SQL/deadlock leak or a 500-class error. */
    private function assertControlledError(string $out): void
    {
        $this->assertStringStartsWith('ERR:', $out, "expected a controlled refusal, got: $out");
        $this->assertStringNotContainsString('QueryException', $out, "raw DB error leaked: $out");
        $this->assertStringNotContainsString('Deadlock', $out, "deadlock leaked uncontrolled: $out");
        $this->assertStringNotContainsString('PDOException', $out, "raw PDO error leaked: $out");
    }

    // ── A. the same table opened from two terminals at once ──────────────────────────────────────
    public function test_race_same_table_open_yields_exactly_one_session(): void
    {
        $this->openShift($this->terminal1);
        $this->openShift($this->terminal2);

        [$outA, $outB] = $this->race(
            ['open_table', $this->userId, $this->terminal1, $this->tableId],
            ['open_table', $this->userId, $this->terminal2, $this->tableId],
        );

        $results = [$outA, $outB];
        $winners = array_filter($results, fn ($o) => str_starts_with($o, 'OK:open_table:'));
        $this->assertCount(1, $winners, "exactly one open must win: A=$outA B=$outB");
        $this->assertControlledError($outA === reset($winners) ? $outB : $outA);

        $this->assertSame(1, DB::connection('tenant')->table('restaurant_table_sessions')
            ->where('restaurant_table_id', $this->tableId)->whereIn('status', ['open', 'bill_requested'])->count(), 'exactly ONE open session');
        $this->assertSame('occupied', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)->value('status'));
    }

    // ── B. the same unsent round sent to kitchen from two processes ──────────────────────────────
    public function test_race_same_kot_send_records_exactly_one_business_event(): void
    {
        $this->openShift($this->terminal1);
        $this->openShift($this->terminal2);
        $pos = app(EdgeLocalPosService::class);
        $session = $pos->openTableSession($this->tableId, ['guest_count' => 2], User::on('tenant')->find($this->userId), $this->terminal1);
        $sale = $pos->holdOrReviseSale([
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $session->id,
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
        ], User::on('tenant')->find($this->userId), $this->terminal1);

        [$outA, $outB] = $this->race(
            ['kot', $this->userId, $this->terminal1, $sale->id],
            ['kot', $this->userId, $this->terminal2, $sale->id],
        );
        $this->assertStringStartsWith('OK:kot:', $outA, "worker A: $outA");
        $this->assertStringStartsWith('OK:kot:', $outB, "worker B: $outB");

        // exactly ONE business event for the round; the delta recorded once; sent-qty advanced once.
        $this->assertSame(1, DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $sale->id)->count(), 'one kot_batch');
        $this->assertSame(2.0, (float) DB::connection('tenant')->table('kot_batch_lines')
            ->join('kot_batches', 'kot_batches.id', '=', 'kot_batch_lines.kot_batch_id')
            ->where('kot_batches.sales_order_id', $sale->id)->sum('kot_batch_lines.quantity'), 'delta recorded exactly once');
        $this->assertSame(2.0, (float) DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $sale->id)->value('kot_sent_quantity'));
        // nothing pretends to be printed.
        foreach (DB::connection('tenant')->table('print_jobs')->get() as $job) {
            $this->assertSame('queued', $job->print_status);
            $this->assertNull($job->printed_at);
        }
    }

    // ── C. conflicting Add Round from the same stale snapshot ────────────────────────────────────
    public function test_race_conflicting_add_round_serializes_or_rejects_stale_input(): void
    {
        $this->openShift($this->terminal1);
        $this->openShift($this->terminal2);
        $pos = app(EdgeLocalPosService::class);
        $session = $pos->openTableSession($this->tableId, ['guest_count' => 2], User::on('tenant')->find($this->userId), $this->terminal1);
        $sale = $pos->holdOrReviseSale([
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $session->id,
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
        ], User::on('tenant')->find($this->userId), $this->terminal1);
        $pos->queueKotEvents($sale->id, User::on('tenant')->find($this->userId), $this->terminal1); // sent=2
        $oldLineId = (int) $sale->lines()->first()->id;

        [$outA, $outB] = $this->race(
            ['revise', $this->userId, $this->terminal1, $session->id, $sale->id, $oldLineId, $this->productId, 2, $this->product2Id, 1],
            ['revise', $this->userId, $this->terminal2, $session->id, $sale->id, $oldLineId, $this->productId, 2, $this->product2Id, 3],
        );

        $results = [$outA, $outB];
        $winners = array_values(array_filter($results, fn ($o) => str_starts_with($o, 'OK:revise:')));
        $this->assertCount(1, $winners, "exactly one revision may win: A=$outA B=$outB");
        $this->assertControlledError($outA === $winners[0] ? $outB : $outA);

        // the winning revision's state is intact: carried line kept its KOT-sent quantity, no
        // cancellation history was fabricated, and only the winner's added line exists.
        $lines = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $sale->id)->get();
        $this->assertCount(2, $lines);
        $carried = $lines->firstWhere('product_id', $this->productId);
        $this->assertSame(2.0, (float) $carried->kot_sent_quantity, 'KOT-sent state survives the race');
        $this->assertSame(100.0, (float) $carried->unit_price, 'captured price survives the race');
        $this->assertSame(0, DB::connection('tenant')->table('sales_order_line_cancellations')->count());
    }

    // ── D. settle-the-last-check vs a NEW hold on the same session (+ shift-close blockers) ──────
    public function test_race_settle_vs_new_hold_never_closes_a_session_with_live_work(): void
    {
        $shift1 = $this->openShift($this->terminal1);
        $shift2 = $this->openShift($this->terminal2);
        $pos = app(EdgeLocalPosService::class);
        $session = $pos->openTableSession($this->tableId, ['guest_count' => 2], User::on('tenant')->find($this->userId), $this->terminal1);
        $saleA = $pos->holdOrReviseSale([
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $session->id,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
        ], User::on('tenant')->find($this->userId), $this->terminal1);

        [$outA, $outB] = $this->race(
            ['settle', $this->userId, $this->terminal1, $saleA->id, (string) Str::uuid(), $this->cashMethodId, 100],
            ['hold', $this->userId, $this->terminal2, $session->id, $this->product2Id, 1],
        );

        // Both interleavings are legitimate; the INVARIANT is what must always hold.
        $sessionRow = DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $session->id)->first();
        $tableRow = DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)->first();
        $heldCount = DB::connection('tenant')->table('sales_orders')->where('restaurant_table_session_id', $session->id)->where('status', 'held')->count();

        if (in_array($sessionRow->status, ['closed', 'cancelled'], true)) {
            $this->assertSame(0, $heldCount, "NEVER a closed session with a live held order (A=$outA B=$outB)");
        }
        if ($tableRow->status === 'available') {
            $this->assertSame(0, $heldCount, "NEVER a freed table with unresolved held work (A=$outA B=$outB)");
        }
        foreach ([$outA, $outB] as $out) {
            if (! str_starts_with($out, 'OK:')) {
                $this->assertControlledError($out);
            }
        }
        $this->assertSame('paid', SalesOrder::on('tenant')->find($saleA->id)->status, "the settle must have completed or been controlled (A=$outA B=$outB)");

        // ── section-6 shift blockers on the surviving state ──
        $svc = app(ShiftService::class);
        if ($heldCount > 0 || in_array($sessionRow->status, ['open', 'bill_requested'], true)) {
            try {
                $svc->closeShift($shift1->fresh(), $this->userId, (float) $shift1->fresh()->expected_cash);
                $this->fail('shift 1 must not close while its session/held work survives');
            } catch (\App\Exceptions\ShiftException $e) {
                $this->assertTrue(true);
            }
        }
        // resolve whatever survived, then BOTH shifts close cleanly.
        $survivor = SalesOrder::on('tenant')->where('restaurant_table_session_id', $session->id)->where('status', 'held')->first();
        if ($survivor) {
            $pos->settleHeldSale($survivor->id, [
                'client_uuid' => (string) Str::uuid(),
                'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => (float) $survivor->grand_total]],
            ], User::on('tenant')->find($this->userId), $this->terminal2);
        }
        $this->assertSame('closed', DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $session->id)->value('status'), 'settling the last check closes the session');
        $this->assertSame('available', DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)->value('status'));
        $svc->closeShift($shift1->fresh(), $this->userId, (float) $shift1->fresh()->expected_cash);
        $svc->closeShift($shift2->fresh(), $this->userId, (float) $shift2->fresh()->expected_cash);
        $this->assertSame('closed', $shift1->fresh()->status);
        $this->assertSame('closed', $shift2->fresh()->status);
    }
}
