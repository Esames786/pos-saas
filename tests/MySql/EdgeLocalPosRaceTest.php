<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-LOCAL-POS-1 (16) — GENUINE two-process races through the REAL EdgeLocalPosService/ShiftService
 * against real MySQL (independent PHP worker processes, spin-barrier start, master pointed at a
 * nonexistent DB inside the workers):
 *
 *   A. same client_uuid + same intent  → exactly ONE sale/payment/settlement/stock movement; both workers
 *      resolve the SAME sale. This is the REAL 2A catch path: the loser's INSERT hits the client_uuid
 *      unique index and resolves the winner (no fabricated exceptions).
 *   B. final unit (qty=1, negative OFF) → exactly one sale succeeds; the loser gets the controlled
 *      insufficient-stock refusal; final Edge qty = 0.
 *   C. shift close vs sale → only serialized valid outcomes: sale wins and the close (after the lock)
 *      includes it, or the close wins and the sale is refused. Never a sale on an already-closed shift.
 */
class EdgeLocalPosRaceTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $productId;
    private int $cashMethodId;
    private int $baselineId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_meta', 'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RACE' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->asBranchServerRuntime();
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function openShift(): Shift
    {
        return app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 0.0);
    }

    private function php(): string
    {
        return PHP_BINARY;
    }

    private function worker(array $args, string $startFile): array
    {
        $cmd = array_merge([$this->php(), base_path('tests/MySql/Support/edge_pos_sale_worker.php')], array_map('strval', $args));
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
        $startFile = sys_get_temp_dir() . '/edge_pos_race_' . Str::random(8) . '.start';
        @unlink($startFile);
        $a = $this->worker($argsA, $startFile);
        $b = $this->worker($argsB, $startFile);
        sleep(4);                      // let both workers boot Laravel and reach the barrier
        file_put_contents($startFile, '1'); // fire
        $outA = $this->finish($a);
        $outB = $this->finish($b);
        @unlink($startFile);

        return [$outA, $outB];
    }

    private function saleArgs(string $clientUuid, float $qty, float $amount): array
    {
        return ['sale', $clientUuid, $this->userId, $this->terminalId, $this->productId, $qty, $this->cashMethodId, $amount];
    }

    // ── A. same client_uuid: exactly one sale, both resolve the winner (REAL 2A catch path) ──
    public function test_same_client_uuid_two_process_race_one_sale_both_resolve_winner(): void
    {
        $this->baselineId = (int) $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 10]])->id;
        $shift = $this->openShift();
        $clientUuid = (string) Str::uuid();

        [$outA, $outB] = $this->race($this->saleArgs($clientUuid, 2, 200), $this->saleArgs($clientUuid, 2, 200));

        $this->assertStringStartsWith('OK:sale:', $outA, "worker A: $outA");
        $this->assertStringStartsWith('OK:sale:', $outB, "worker B: $outB");
        $this->assertSame($outA, $outB, 'both workers must resolve the SAME sale (winner resolution)');

        $this->assertSame(1, SalesOrder::on('tenant')->count(), 'exactly one sale');
        $this->assertSame(1, DB::connection('tenant')->table('sale_payments')->count(), 'exactly one payment');
        $this->assertSame(1, DB::connection('tenant')->table('edge_operational_stock_movements')->count(), 'exactly one stock movement');
        $this->assertSame(8.0, $this->edgeOnHand($this->baselineId, $this->productId), 'stock decremented exactly once');
        $this->assertSame(200.0, (float) $shift->fresh()->total_sales, 'settlement exactly once');
    }

    // ── B. final unit: exactly one succeeds ──────────────────────────────────
    public function test_final_unit_two_process_race_exactly_one_sale_succeeds(): void
    {
        $this->baselineId = (int) $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 1]])->id;
        $this->openShift();

        [$outA, $outB] = $this->race(
            $this->saleArgs((string) Str::uuid(), 1, 100),
            $this->saleArgs((string) Str::uuid(), 1, 100)
        );

        $oks = array_filter([$outA, $outB], fn ($o) => str_starts_with($o, 'OK:sale:'));
        $errs = array_filter([$outA, $outB], fn ($o) => str_starts_with($o, 'ERR:'));
        $this->assertCount(1, $oks, "exactly one winner. A=$outA B=$outB");
        $this->assertCount(1, $errs, 'exactly one controlled loser');
        $this->assertStringContainsString('Insufficient stock', implode(' ', $errs), 'loser gets the controlled insufficient-stock refusal');

        $this->assertSame(1, SalesOrder::on('tenant')->count());
        $this->assertSame(1, DB::connection('tenant')->table('sale_payments')->count());
        $this->assertSame(0.0, $this->edgeOnHand($this->baselineId, $this->productId), 'final qty = 0, never negative');
    }

    // ── C. shift close vs sale: only serialized valid outcomes ───────────────
    public function test_shift_close_vs_sale_two_process_race_is_serialized(): void
    {
        $this->baselineId = (int) $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 10]])->id;
        $shift = $this->openShift();

        [$saleOut, $closeOut] = $this->race(
            $this->saleArgs((string) Str::uuid(), 2, 200),
            ['close', $shift->id, $this->userId]
        );

        $shift->refresh();
        if (str_starts_with($saleOut, 'OK:sale:')) {
            // Sale won the shift lock: it committed on the OPEN shift; the close (whichever way it ended)
            // observed the committed sale — the shift totals must include it.
            $this->assertSame(200.0, (float) $shift->total_sales, "close path must observe the committed sale. sale=$saleOut close=$closeOut");
            $sale = SalesOrder::on('tenant')->first();
            $this->assertSame($shift->id, (int) $sale->shift_id, 'sale bound to the raced shift');
        } else {
            // Close won: the sale must have been refused with the controlled no-open-shift error and
            // nothing persisted.
            $this->assertStringStartsWith('OK:close:', $closeOut, "close must have won if the sale lost. sale=$saleOut close=$closeOut");
            $this->assertStringContainsString('shift', strtolower($saleOut), 'loser sale gets the controlled shift refusal');
            $this->assertSame(0, SalesOrder::on('tenant')->count(), 'no sale on a closed shift');
            $this->assertSame('closed', $shift->status);
        }
        // In BOTH outcomes: never a committed sale on a shift that closed before the sale.
        if (SalesOrder::on('tenant')->count() === 1 && $shift->status === 'closed') {
            $this->assertSame(200.0, (float) $shift->total_sales, 'a closed shift that contains a sale must include its totals (sale committed before close)');
        }
    }
}
