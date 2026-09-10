<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeReturnEnvelopeBuilder;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * F1 — TWO RETURN RACES with genuine process concurrency (two independent OS processes, one appliance database):
 *   Race A: two terminals return the same remaining quantity at the same instant → only the valid aggregate wins.
 *   Race B: a return is already pending locally and another request asks for the same original quantity → capped.
 * Never an over-return; the till and the operational stock move only for what was actually accepted.
 */
class EdgeReturnRaceTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalA;
    private int $terminalB;
    private int $userId;
    private int $productId;
    private int $cashMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RR' . Str::random(4)]);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Till A']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Till B']);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'race-box');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema')]);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->grantEdgePermission($this->userId, 'tenant.sales-returns.store');
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        foreach ([$this->terminalA, $this->terminalB] as $t) {
            app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($t), $this->userId, 1000.0);
        }
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function spawn(int $saleId, int $lineId, float $qty, int $terminalId, string $amount, float $barrierAt): array
    {
        $cmd = [PHP_BINARY, base_path('tests/MySql/Support/edge_return_worker.php'), 'return', (string) $saleId, (string) $lineId, (string) $qty, (string) $this->userId, (string) $terminalId, $amount];
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'ROLE' => 'branch_server', 'EDGE_WORKER_DB' => $this->tenantDb, 'APP_ENV' => 'testing', 'EDGE_RETURN_BARRIER_AT' => sprintf('%.3F', $barrierAt),
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

    public function test_two_terminals_returning_the_same_remaining_quantity_never_over_return(): void
    {
        $user = User::on('tenant')->find($this->userId);
        $sale = app(EdgeLocalPosService::class)->completePaidSale(['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'lines' => [['product_id' => $this->productId, 'quantity' => 4]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 400]]], $user, $this->terminalA);
        $lineId = (int) DB::table('sales_order_lines')->where('sales_order_id', $sale->id)->value('id');
        $onHandBefore = (float) DB::table('edge_operational_stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand');

        // RACE A: both tills ask for 3 of the 4 at the same instant (barrier). Only 4 in total can ever come back.
        $barrier = microtime(true) + 3.0;
        $a = $this->spawn($sale->id, $lineId, 3, $this->terminalA, '-', $barrier);
        $b = $this->spawn($sale->id, $lineId, 3, $this->terminalB, '-', $barrier);
        $ra = $this->finish($a);
        $rb = $this->finish($b);
        $results = [$ra, $rb];
        $okCount = count(array_filter($results, fn ($r) => str_starts_with($r, 'OK:')));
        $this->assertGreaterThanOrEqual(1, $okCount, implode(' | ', $results));
        $returned = (float) DB::table('sales_order_lines')->where('id', $lineId)->value('returned_quantity');
        $this->assertLessThanOrEqual(4.0, $returned, 'never more than sold');
        $totalReturnedLines = (float) DB::table('sales_return_lines')->sum('quantity');
        $this->assertSame($returned, $totalReturnedLines);
        // The second winner (if both won) was CAPPED to the remainder: 3 + 1.
        $this->assertSame($okCount === 2 ? 4.0 : 3.0, $returned, implode(' | ', $results));
        $this->assertSame($onHandBefore + $returned, (float) DB::table('edge_operational_stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand'), 'operational stock moved exactly by what was accepted');
        $refunded = (float) DB::table('shifts')->sum('total_cash_refunds');
        $this->assertSame($returned * 100.0, $refunded, 'the tills refunded exactly what was accepted');
        $this->assertSame($okCount, DB::table('edge_sync_outbox')->where('envelope_schema_version', EdgeReturnEnvelopeBuilder::SCHEMA)->count(), 'one immutable event per accepted return');

        // RACE B: a return is already pending; another request for the ORIGINAL quantity (4) is capped to what is left (0 or 1).
        $remaining = 4.0 - $returned;
        $out = $this->finish($this->spawn($sale->id, $lineId, 4, $this->terminalB, '-', microtime(true)));
        if ($remaining > 0) {
            $this->assertStringStartsWith('OK:', $out);
            $this->assertSame(4.0, (float) DB::table('sales_order_lines')->where('id', $lineId)->value('returned_quantity'));
        } else {
            $this->assertStringStartsWith('ERR:', $out, 'nothing left to return');
        }
        $this->assertLessThanOrEqual(4.0, (float) DB::table('sales_return_lines')->sum('quantity'));
        $this->assertSame('returned', DB::table('sales_orders')->where('id', $sale->id)->value('status'));
    }
}
