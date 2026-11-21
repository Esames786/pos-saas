<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPurchaseReturnService;
use App\Services\Edge\EdgePurchaseReturnCacheService;
use App\Services\Edge\EdgePurchaseReturnEnvelopeBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgePurchaseReturnFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — F3 §11 TWO-TERMINAL RACE: a receipt line received 10; terminal A returns 7 and terminal B returns 7 at the
 * same instant from independent OS processes. The aggregate accepted quantity never exceeds 10: exactly one is accepted,
 * local stock moves only for what was accepted, one immutable event exists per accepted return, and the remaining 3 can
 * still be returned. Serialisation = the row lock on the projected receipt line + locking pending reads.
 */
class EdgePurchaseReturnRaceTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgePurchaseReturnFixture;

    private int $branchId;
    private int $terminalA;
    private int $terminalB;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(array_merge(self::PR_EDGE_TABLES, self::PR_TABLES, [
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta',
            'model_has_permissions', 'permissions', 'products', 'categories', 'units', 'terminals', 'branches', 'users',
        ]));
        $this->branchId = $this->makeBranch(['name' => 'Race Branch', 'allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'PRR' . Str::random(4)]);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Till A']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Till B']);
        $unit = DB::table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $product = $this->makeProduct($this->makeCategory(), ['name' => 'Raw Item', 'unit_id' => $unit, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_purchasable' => 1, 'status' => 'active']);
        $this->seedCloudPurchaseReturnTruth($this->branchId, $product, $unit);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'pr-race-box');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now()->subMinute()]);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([['product_id' => $product, 'product_variant_id' => null, 'quantity' => 100]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->grantEdgePermission($this->userId, EdgeLocalPurchaseReturnService::PERM_STORE);
        $this->grantEdgePermission($this->userId, EdgeLocalPurchaseReturnService::PERM_POST);
        $this->projectPurchaseReturnsToAppliance($this->branchId);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function spawn(float $qty, int $terminalId, float $barrierAt): array
    {
        $cmd = [PHP_BINARY, base_path('tests/MySql/Support/edge_purchase_return_worker.php'), 'return', (string) $this->prGrnId, (string) $this->prGrnLineId, (string) $qty, (string) $this->userId, (string) $terminalId];
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'ROLE' => 'branch_server', 'EDGE_WORKER_DB' => $this->tenantDb, 'APP_ENV' => 'testing', 'EDGE_PR_BARRIER_AT' => sprintf('%.3F', $barrierAt),
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

    public function test_two_terminals_returning_the_same_received_line_never_exceed_the_received_quantity(): void
    {
        $this->assertSame(10.0, app(EdgePurchaseReturnCacheService::class)->lines($this->prGrnId)[0]['returnable']);
        $barrier = microtime(true) + 2.5;
        $a = $this->spawn(7, $this->terminalA, $barrier);
        $b = $this->spawn(7, $this->terminalB, $barrier);
        $results = [$this->finish($a), $this->finish($b)];

        $ok = array_values(array_filter($results, fn ($r) => str_starts_with($r, 'OK:')));
        $err = array_values(array_filter($results, fn ($r) => str_starts_with($r, 'ERR:')));
        $this->assertCount(1, $ok, 'exactly one terminal is accepted: ' . implode(' | ', $results));
        $this->assertCount(1, $err, implode(' | ', $results));
        $this->assertStringContainsString('returnable', $err[0], 'the loser meets the canonical returnable message');

        $this->assertSame(7.0, (float) DB::table(EdgePurchaseReturnCacheService::T_EVENT_LINES)->sum('quantity'), 'aggregate never exceeds the received quantity');
        $this->assertSame(1, DB::table('edge_sync_outbox')->where('envelope_schema_version', EdgePurchaseReturnEnvelopeBuilder::SCHEMA)->count(), 'one immutable event per accepted return');
        $this->assertSame(93.0, (float) DB::table('edge_operational_stock_balances')->sum('quantity_on_hand'), 'local stock moves only for what was accepted');
        $this->assertSame(1, DB::table('edge_operational_stock_movements')->where('movement_type', 'purchase_return')->count());
        $this->assertSame(3.0, app(EdgePurchaseReturnCacheService::class)->lines($this->prGrnId)[0]['returnable']);

        // The remainder is still returnable; one unit more is not.
        $user = User::on('tenant')->find($this->userId);
        app(EdgeLocalPurchaseReturnService::class)->postReturn(['cloud_grn_id' => $this->prGrnId, 'reason_code' => 'damaged', 'lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 3]]], $user, $this->terminalA);
        $this->assertSame(0.0, app(EdgePurchaseReturnCacheService::class)->lines($this->prGrnId)[0]['returnable']);
        try {
            app(EdgeLocalPurchaseReturnService::class)->postReturn(['cloud_grn_id' => $this->prGrnId, 'reason_code' => 'damaged', 'lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 1]]], $user, $this->terminalB);
            $this->fail('nothing left to return');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('returnable', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertSame(10.0, (float) DB::table(EdgePurchaseReturnCacheService::T_EVENT_LINES)->sum('quantity'));
    }
}
