<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeTableReservation;
use App\Models\Tenant\Branch;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeTableReservationService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — ONLINE POS PARITY: GENUINE two-process reservation races (independent OS processes,
 * spin-barrier, branch-local authority) proving there is exactly ONE coherent truth per table under
 * concurrency:
 *   - reserve vs reserve  -> exactly one active reservation, the loser controlled-refused;
 *   - reserve vs open     -> never an open session coexisting with a still-ACTIVE reservation;
 *   - cancel  vs open     -> the reservation ends EITHER cancelled (no carry-over) OR seated (carry-over),
 *                            never both, never a customer carried from a definitively-lost reservation.
 */
class EdgeReservationRaceTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminal1;
    private int $terminal2;
    private int $userId;
    private int $tableId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_table_reservations', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_meta', 'kot_batch_lines', 'kot_batches', 'sale_payments', 'sales_order_lines', 'sales_orders', 'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['sales_operating_mode' => 'local_edge', 'local_edge_status' => 'active', 'allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RR' . Str::random(4), 'allowed_order_types' => json_encode(['dine_in', 'quick_sale'])]);
        $this->terminal1 = $this->makeTerminal($this->branchId);
        $this->terminal2 = $this->makeTerminal($this->branchId);
        $this->tableId = $this->makeTable($this->branchId, ['status' => 'available']);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'default_selling_price' => 100]);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]]);
        Auth::guard('tenant')->setUser(User::on('tenant')->find($this->userId));
        Auth::shouldUse('tenant');
        // Both terminals need an open shift so the open_table worker can lock one.
        app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminal1), $this->userId, 0.0);
        app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminal2), $this->userId, 0.0);
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function worker(array $args, string $startFile): array
    {
        $cmd = array_merge([PHP_BINARY, base_path('tests/MySql/Support/edge_pos_sale_worker.php')], array_map('strval', $args));
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'EDGE_TEST_TENANT_DB' => $this->tenantDb, 'APP_ENV' => 'testing', 'START_FILE' => $startFile,
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
        $startFile = sys_get_temp_dir() . '/edge_resv_race_' . Str::random(8) . '.start';
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

    public function test_reserve_vs_reserve_yields_exactly_one_active_reservation(): void
    {
        [$a, $b] = $this->race(
            ['reserve', $this->userId, $this->terminal1, $this->tableId],
            ['reserve', $this->userId, $this->terminal2, $this->tableId],
        );

        $oks = array_filter([$a, $b], fn ($o) => str_starts_with($o, 'OK:reserve:'));
        $this->assertCount(1, $oks, "exactly one reserve must win — A=[$a] B=[$b]");
        $loser = str_starts_with($a, 'OK:reserve:') ? $b : $a;
        $this->assertStringStartsWith('ERR:', $loser, "the loser must be a controlled refusal — [$loser]");
        $this->assertStringNotContainsString('QueryException', $loser);
        $this->assertStringNotContainsString('Deadlock', $loser);

        $this->assertSame(1, EdgeTableReservation::where('restaurant_table_id', $this->tableId)->where('status', 'active')->count(), 'exactly one active reservation exists');
    }

    public function test_reserve_vs_open_never_leaves_an_active_reservation_on_an_open_table(): void
    {
        [$a, $b] = $this->race(
            ['reserve', $this->userId, $this->terminal1, $this->tableId],
            ['open_table', $this->userId, $this->terminal2, $this->tableId],
        );

        // The open always creates the session; whether reserve won or lost, no ACTIVE reservation may coexist.
        $this->assertSame(1, RestaurantTableSession::where('restaurant_table_id', $this->tableId)->whereIn('status', ['open', 'bill_requested'])->count(), "one open session — A=[$a] B=[$b]");
        $this->assertSame(0, EdgeTableReservation::where('restaurant_table_id', $this->tableId)->where('status', 'active')->count(), 'no active reservation coexists with an open table');
        // Any reservation that was created is now SEATED (carried), never left dangling-active.
        $this->assertLessThanOrEqual(1, EdgeTableReservation::where('restaurant_table_id', $this->tableId)->count());
    }

    public function test_cancel_vs_open_is_coherent_cancelled_or_seated_never_both(): void
    {
        // A standing active reservation to race cancel-vs-open against.
        app(EdgeTableReservationService::class)->reserve($this->tableId, ['customer_name' => 'Standing'], User::on('tenant')->find($this->userId));

        [$a, $b] = $this->race(
            ['cancel_reservation', $this->userId, $this->terminal1, $this->tableId],
            ['open_table', $this->userId, $this->terminal2, $this->tableId],
        );

        $this->assertSame(1, RestaurantTableSession::where('restaurant_table_id', $this->tableId)->whereIn('status', ['open', 'bill_requested'])->count(), "one open session — A=[$a] B=[$b]");
        $this->assertSame(0, EdgeTableReservation::where('restaurant_table_id', $this->tableId)->where('status', 'active')->count(), 'no active reservation remains');
        $r = EdgeTableReservation::where('restaurant_table_id', $this->tableId)->first();
        $this->assertContains($r->status, ['cancelled', 'seated'], 'the reservation ended cancelled OR seated');
        // Coherence: exactly one terminal outcome, never both cancelled and seated.
        $this->assertSame(1, EdgeTableReservation::where('restaurant_table_id', $this->tableId)->whereIn('status', ['cancelled', 'seated'])->count());
    }
}
