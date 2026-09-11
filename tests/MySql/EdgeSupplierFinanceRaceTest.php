<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalSupplierFinanceService;
use App\Services\Edge\EdgeSupplierFinanceCacheService;
use App\Services\Edge\EdgeSupplierFinanceEnvelopeBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgeSupplierFinanceFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — F2 §18 TWO-TERMINAL PAYMENT RACE: payable 10,000; terminal A pays 7,000 and terminal B pays 7,000 at
 * the same instant from two independent OS processes. The aggregate must never exceed the payable (supplier advances
 * are unsupported): exactly one payment is accepted, the other is refused, one immutable event exists per accepted
 * payment, and the remaining 3,000 can still be paid afterwards. Serialisation = the row lock on the projected supplier.
 */
class EdgeSupplierFinanceRaceTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeSupplierFinanceFixture;

    private int $branchId;
    private int $terminalA;
    private int $terminalB;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(array_merge(self::SF_EDGE_TABLES, self::SF_TABLES, [
            'edge_sync_outbox', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions', 'terminals', 'branches', 'users',
        ]));
        $this->branchId = $this->makeBranch(['name' => 'Race Branch']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'SFR' . Str::random(4)]);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Till A']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Till B']);
        $this->seedCloudSupplierFinance($this->branchId);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'race-finance-box');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now()->subMinute()]);
        $this->asBranchServerRuntime();
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->grantEdgePermission($this->userId, EdgeLocalSupplierFinanceService::PERM_PAYMENT);
        $this->projectSupplierFinanceToAppliance($this->branchId);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function spawn(float $amount, int $terminalId, float $barrierAt): array
    {
        $cmd = [PHP_BINARY, base_path('tests/MySql/Support/edge_supplier_finance_worker.php'), 'pay', (string) $this->supplierAId, (string) $amount, (string) $this->tillCbId, (string) $this->userId, (string) $terminalId];
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], [
            'ROLE' => 'branch_server', 'EDGE_WORKER_DB' => $this->tenantDb, 'APP_ENV' => 'testing', 'EDGE_SF_BARRIER_AT' => sprintf('%.3F', $barrierAt),
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

    public function test_two_terminals_paying_the_same_supplier_never_exceed_the_payable(): void
    {
        $this->assertSame(10000.0, app(EdgeSupplierFinanceCacheService::class)->position($this->supplierAId)['available_payable']);
        $barrier = microtime(true) + 2.5;
        $a = $this->spawn(7000, $this->terminalA, $barrier);
        $b = $this->spawn(7000, $this->terminalB, $barrier);
        $results = [$this->finish($a), $this->finish($b)];

        $ok = array_values(array_filter($results, fn ($r) => str_starts_with($r, 'OK:')));
        $err = array_values(array_filter($results, fn ($r) => str_starts_with($r, 'ERR:')));
        $this->assertCount(1, $ok, 'exactly one terminal is accepted: ' . implode(' | ', $results));
        $this->assertCount(1, $err, implode(' | ', $results));
        $this->assertStringContainsString('advance', strtolower($err[0]), 'the loser meets the canonical boundary message');

        $this->assertSame(-7000.0, (float) DB::table(EdgeSupplierFinanceCacheService::T_EFFECTS)->where('cloud_supplier_id', $this->supplierAId)->sum('payable_delta'), 'aggregate never exceeds the payable');
        $this->assertSame(1, DB::table(EdgeSupplierFinanceCacheService::T_EVENTS)->count(), 'one local event per accepted payment');
        $this->assertSame(1, DB::table('edge_sync_outbox')->where('envelope_schema_version', EdgeSupplierFinanceEnvelopeBuilder::SCHEMA_PAYMENT)->count(), 'one immutable event queued');
        $this->assertSame(3000.0, app(EdgeSupplierFinanceCacheService::class)->position($this->supplierAId)['available_payable']);

        // The remainder is still payable; one rupee more is not.
        $svc = app(EdgeLocalSupplierFinanceService::class);
        $user = User::on('tenant')->find($this->userId);
        $svc->recordPayment(['cloud_supplier_id' => $this->supplierAId, 'cloud_cash_bank_account_id' => $this->tillCbId, 'amount' => 3000, 'payment_method' => 'cash'], $user, $this->terminalA);
        $this->assertSame(0.0, app(EdgeSupplierFinanceCacheService::class)->position($this->supplierAId)['available_payable']);
        try {
            $svc->recordPayment(['cloud_supplier_id' => $this->supplierAId, 'cloud_cash_bank_account_id' => $this->tillCbId, 'amount' => 1, 'payment_method' => 'cash'], $user, $this->terminalB);
            $this->fail('nothing left to pay');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('advance', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertSame(2, DB::table('edge_sync_outbox')->count());
    }
}
