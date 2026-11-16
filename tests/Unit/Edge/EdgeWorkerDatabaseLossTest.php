<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\EdgeLocalDbReadiness;
use App\Services\Edge\EdgeWorkerDatabaseLoss;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * EDGE 0.8.3 (F-1b) — a disappearing database inside a worker loop: what counts as a connection LOSS (never a SQL
 * error), and the bounded recovery wait (continue when the DB answers again, give up on a spent budget or a
 * configuration defect) through the readiness seam. The 30 Oct 2026 LAB outage is the motivating case.
 */
class EdgeWorkerDatabaseLossTest extends TestCase
{
    protected function tearDown(): void
    {
        EdgeLocalDbReadiness::fakeProbe(null);
        parent::tearDown();
    }

    private function pdo(int $errno, string $msg): PDOException
    {
        $e = new PDOException($msg);
        $e->errorInfo = ['HY000', $errno, $msg];

        return $e;
    }

    public function test_connection_level_failures_are_losses_and_sql_errors_are_not(): void
    {
        $this->assertTrue(EdgeWorkerDatabaseLoss::isLoss($this->pdo(2006, 'SQLSTATE[HY000] [2006] MySQL server has gone away')));
        $this->assertTrue(EdgeWorkerDatabaseLoss::isLoss($this->pdo(2013, 'Lost connection to MySQL server during query')));
        $this->assertTrue(EdgeWorkerDatabaseLoss::isLoss($this->pdo(2002, 'No connection could be made because the target machine actively refused it')));
        $this->assertTrue(EdgeWorkerDatabaseLoss::isLoss($this->pdo(1053, 'Server shutdown in progress')));
        $this->assertTrue(EdgeWorkerDatabaseLoss::isLoss($this->pdo(1040, 'Too many connections')));
        // wrapped the way Laravel reports it: QueryException around the PDO failure
        $wrapped = new QueryException('tenant', 'select 1', [], $this->pdo(2006, 'MySQL server has gone away'));
        $this->assertTrue(EdgeWorkerDatabaseLoss::isLoss($wrapped));
        $this->assertTrue(EdgeWorkerDatabaseLoss::isLoss(new RuntimeException('outer', 0, $this->pdo(2013, 'Lost connection'))));

        $this->assertFalse(EdgeWorkerDatabaseLoss::isLoss($this->pdo(1064, 'You have an error in your SQL syntax')), 'a SQL error is not a loss');
        $this->assertFalse(EdgeWorkerDatabaseLoss::isLoss($this->pdo(1062, 'Duplicate entry')), 'a constraint violation is not a loss');
        $this->assertFalse(EdgeWorkerDatabaseLoss::isLoss(new QueryException('tenant', 'insert', [], $this->pdo(1452, 'a foreign key constraint fails'))));
        $this->assertFalse(EdgeWorkerDatabaseLoss::isLoss(new RuntimeException('printer connect failed')), 'a non-DB exception is not a loss');
    }

    public function test_recover_continues_when_the_database_answers_again_and_gives_up_on_a_spent_budget_or_a_configuration_defect(): void
    {
        config(['edge.workers.db_startup_wait_seconds' => 5, 'edge.workers.db_startup_interval_seconds' => 1]);
        $lines = [];
        $say = function (string $l) use (&$lines) { $lines[] = $l; };
        $cause = $this->pdo(2006, 'MySQL server has gone away');

        $calls = 0;
        EdgeLocalDbReadiness::fakeProbe(function () use (&$calls) {
            $calls++;
            $back = $calls >= 2;

            return ['ready' => $back, 'status' => $back ? 'ready' : 'refused', 'retryable' => ! $back, 'host' => 'h', 'port' => 1, 'database' => 'd', 'latency_ms' => 1, 'detail' => $back ? 'SELECT 1 answered' : 'nothing listens', 'errno' => $back ? null : 2002];
        });
        $this->assertTrue(EdgeWorkerDatabaseLoss::recover(app(EdgeLocalDbReadiness::class), 'print-worker', $cause, $say));
        $this->assertSame(2, $calls);
        $this->assertStringContainsString('database connection lost (PDOException)', $lines[0]);
        $this->assertStringContainsString('database back after', end($lines));

        $lines = [];
        EdgeLocalDbReadiness::fakeProbe(fn () => ['ready' => false, 'status' => 'refused', 'retryable' => true, 'host' => 'h', 'port' => 1, 'database' => 'd', 'latency_ms' => 1, 'detail' => 'nothing listens', 'errno' => 2002]);
        $t0 = microtime(true);
        $this->assertFalse(EdgeWorkerDatabaseLoss::recover(app(EdgeLocalDbReadiness::class), 'print-worker', $cause, $say), 'a spent budget ends the worker (exit 1 → task restart)');
        $this->assertGreaterThanOrEqual(4.5, microtime(true) - $t0);
        $this->assertLessThan(9, microtime(true) - $t0, 'the budget is a hard bound');
        $this->assertStringContainsString('did not come back', end($lines));

        $lines = [];
        EdgeLocalDbReadiness::fakeProbe(fn () => ['ready' => false, 'status' => 'auth_failed', 'retryable' => false, 'host' => 'h', 'port' => 1, 'database' => 'd', 'latency_ms' => 1, 'detail' => 'denied', 'errno' => 1045]);
        $t0 = microtime(true);
        $this->assertFalse(EdgeWorkerDatabaseLoss::recover(app(EdgeLocalDbReadiness::class), 'authority-worker', $cause, $say));
        $this->assertLessThan(2, microtime(true) - $t0, 'a configuration defect is never waited out');
    }
}
