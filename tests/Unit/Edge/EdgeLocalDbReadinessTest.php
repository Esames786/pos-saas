<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\EdgeLocalDbReadiness;
use App\Support\EdgeLocalDatabase;
use PDOException;
use Tests\TestCase;

/**
 * EDGE 0.8.3 (F-1) — MySQL readiness classification: a dead loopback port is `refused` within the connect bound and
 * stays retryable; the PDO error matrix names credentials / unknown database as NOT retryable; await() stops early on
 * a non-retryable class and honours its wall-clock budget. No MySQL server is needed here (port 9 on loopback is dead).
 */
class EdgeLocalDbReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.role' => 'branch_server']); // EdgeLocalDatabase's safety rules only apply on a Branch Server
    }

    protected function tearDown(): void
    {
        EdgeLocalDbReadiness::fakeProbe(null);
        parent::tearDown();
    }

    private function pointEdgeLocalAt(string $host, int $port, string $database = 'bingoo_edge_unit_local'): void
    {
        config(['database.connections.' . EdgeLocalDatabase::CONNECTION => [
            'driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $database, 'username' => 'probe_user_xyz', 'password' => 'not-a-real-password',
        ]]);
    }

    public function test_a_dead_loopback_port_is_refused_retryable_and_fast(): void
    {
        $this->pointEdgeLocalAt('127.0.0.1', 9);
        $t0 = microtime(true);
        $r = app(EdgeLocalDbReadiness::class)->probe();
        $this->assertFalse($r['ready']);
        $this->assertSame(EdgeLocalDbReadiness::REFUSED, $r['status'], json_encode($r));
        $this->assertTrue($r['retryable']);
        $this->assertLessThan(EdgeLocalDbReadiness::CONNECT_TIMEOUT_SECONDS, microtime(true) - $t0);
        $this->assertSame('127.0.0.1', $r['host']);
        $this->assertSame(9, $r['port']);
        $this->assertStringNotContainsString('not-a-real-password', json_encode($r), 'the report never carries the password');
        $this->assertStringNotContainsString('probe_user_xyz', json_encode($r), 'the report never carries the user');
    }

    public function test_the_pdo_error_matrix_separates_retryable_from_configuration_defects(): void
    {
        $e = function (int $errno, string $msg): PDOException {
            $ex = new PDOException($msg);
            $ex->errorInfo = ['HY000', $errno, $msg];

            return $ex;
        };
        $this->assertSame([EdgeLocalDbReadiness::AUTH_FAILED, false, 1045], EdgeLocalDbReadiness::classify($e(1045, "SQLSTATE[HY000] [1045] Access denied for user 'x'@'localhost'"), 20));
        $this->assertSame([EdgeLocalDbReadiness::UNKNOWN_DATABASE, false, 1049], EdgeLocalDbReadiness::classify($e(1049, 'SQLSTATE[HY000] [1049] Unknown database'), 20));
        $this->assertSame([EdgeLocalDbReadiness::REFUSED, true, 2002], EdgeLocalDbReadiness::classify($e(2002, 'SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it'), 20));
        $this->assertSame([EdgeLocalDbReadiness::TIMEOUT, true, 2002], EdgeLocalDbReadiness::classify($e(2002, 'SQLSTATE[HY000] [2002] Connection timed out'), 5000));
        $this->assertSame([EdgeLocalDbReadiness::REFUSED, true, 2003], EdgeLocalDbReadiness::classify($e(2003, "Can't connect to MySQL server"), 20));
        [$s, $retry] = EdgeLocalDbReadiness::classify($e(1234, 'something odd while initialising'), 20);
        $this->assertSame(EdgeLocalDbReadiness::ERROR, $s);
        $this->assertTrue($retry, 'odd answers while MySQL initialises stay retryable');
    }

    public function test_an_unconfigured_target_is_not_retryable(): void
    {
        $this->pointEdgeLocalAt('', 3306, 'bingoo_edge_unit_local');
        $r = app(EdgeLocalDbReadiness::class)->probe();
        $this->assertSame(EdgeLocalDbReadiness::NOT_CONFIGURED, $r['status'], json_encode($r));
        $this->assertFalse($r['retryable']);
        $this->pointEdgeLocalAt('127.0.0.1', 9, '');
        $this->assertSame(EdgeLocalDbReadiness::NOT_CONFIGURED, app(EdgeLocalDbReadiness::class)->probe()['status']);
    }

    public function test_await_stops_early_on_a_non_retryable_class_and_otherwise_spends_its_budget(): void
    {
        $calls = 0;
        EdgeLocalDbReadiness::fakeProbe(function () use (&$calls) {
            $calls++;

            return ['ready' => false, 'status' => EdgeLocalDbReadiness::AUTH_FAILED, 'retryable' => false, 'host' => 'h', 'port' => 1, 'database' => 'd', 'latency_ms' => 1, 'detail' => 'denied', 'errno' => 1045];
        });
        $r = app(EdgeLocalDbReadiness::class)->await(30, 1);
        $this->assertFalse($r['ready']);
        $this->assertSame(1, $r['attempts'], 'a configuration defect is never retried');
        $this->assertSame(1, $calls);

        $calls = 0;
        EdgeLocalDbReadiness::fakeProbe(function () use (&$calls) {
            $calls++;

            return ['ready' => $calls >= 3, 'status' => $calls >= 3 ? EdgeLocalDbReadiness::READY : EdgeLocalDbReadiness::REFUSED, 'retryable' => $calls < 3, 'host' => 'h', 'port' => 1, 'database' => 'd', 'latency_ms' => 1, 'detail' => 'x', 'errno' => null];
        });
        $t0 = microtime(true);
        $r = app(EdgeLocalDbReadiness::class)->await(10, 1);
        $this->assertTrue($r['ready']);
        $this->assertSame(3, $r['attempts']);
        $this->assertGreaterThanOrEqual(1.9, microtime(true) - $t0, 'two 1 s intervals elapsed');

        EdgeLocalDbReadiness::fakeProbe(fn () => ['ready' => false, 'status' => EdgeLocalDbReadiness::REFUSED, 'retryable' => true, 'host' => 'h', 'port' => 1, 'database' => 'd', 'latency_ms' => 1, 'detail' => 'x', 'errno' => 2002]);
        $t0 = microtime(true);
        $r = app(EdgeLocalDbReadiness::class)->await(2, 1);
        $this->assertFalse($r['ready']);
        $this->assertGreaterThanOrEqual(2, $r['attempts']);
        $this->assertLessThan(4.5, microtime(true) - $t0, 'the budget is a hard bound');
        $this->assertSame(0, app(EdgeLocalDbReadiness::class)->await(0, 1)['attempts'] - 1, 'timeout 0 probes exactly once');
    }
}
