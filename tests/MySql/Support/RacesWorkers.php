<?php

namespace Tests\MySql\Support;

/**
 * UB-06 — genuine concurrency for a MySQL test: hold a row lock, start each job as its own OS process
 * (tests/MySql/Support/sales_return_worker.php), wait until all of them are queued behind a lock in this test
 * database — the proof they are in flight at the same moment — then let go and collect what each one printed.
 *
 * Needs MySqlTenantTestCase (independentTenantPdo(), $tenantDb).
 */
trait RacesWorkers
{
    /**
     * @param  list<array<string,mixed>>  $jobs
     * @return array{0: list<string>, 1: int} what each worker printed, and how many were seen waiting at once
     */
    protected function race(string $lockSql, array $bindings, array $jobs): array
    {
        $locker = $this->independentTenantPdo();
        $locker->beginTransaction();
        $st = $locker->prepare($lockSql);
        $st->execute($bindings);
        $st->fetchAll();

        $env = array_merge(getenv() ?: [], ['EDGE_TEST_TENANT_DB' => $this->tenantDb, 'APP_ENV' => 'testing']);
        $procs = $pipes = $files = [];
        foreach ($jobs as $i => $job) {
            $files[$i] = tempnam(sys_get_temp_dir(), 'srw');
            file_put_contents($files[$i], json_encode($job));
            $procs[$i] = proc_open([PHP_BINARY, base_path('tests/MySql/Support/sales_return_worker.php'), $files[$i]],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path(), $env);
        }

        $watcher = $this->independentTenantPdo();
        $waiting = $watcher->prepare("SELECT COUNT(*) FROM information_schema.innodb_trx t
            JOIN information_schema.processlist p ON p.id = t.trx_mysql_thread_id
            WHERE t.trx_state = 'LOCK WAIT' AND p.db = ?");
        $queued = 0;
        $deadline = microtime(true) + 40;
        while (microtime(true) < $deadline) {
            $waiting->execute([$this->tenantDb]);
            $queued = max($queued, (int) $waiting->fetchColumn());
            if ($queued >= count($jobs)) {
                break;
            }
            usleep(100_000);
        }
        $locker->commit();

        $out = [];
        foreach (array_keys($jobs) as $i) {
            $out[$i] = trim(stream_get_contents($pipes[$i][1]) . ' ' . stream_get_contents($pipes[$i][2]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($procs[$i]);
            @unlink($files[$i]);
        }

        return [$out, $queued];
    }

    protected function countOut(array $out, string $prefix): int
    {
        return count(array_filter($out, fn ($o) => str_starts_with($o, $prefix)));
    }

    protected function firstOut(array $out, string $prefix): string
    {
        return (string) collect($out)->first(fn ($o) => str_starts_with($o, $prefix));
    }
}
