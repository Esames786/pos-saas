<?php

namespace App\Services\Edge;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

/**
 * EDGE 0.8.3 SERVICE RELIABILITY (F-1b) — survive a DISAPPEARING database inside a long-running worker.
 *
 * Observed on the real LAB (30 Oct 2026, 13:50–16:23 UTC): the shared MySQL server died; the 0.8.1 print and authority
 * workers hit a QueryException in their loop, crashed out and STAYED DOWN until an operator restarted them hours later
 * (the P5C LAB has no Task Scheduler restart). The 0.8.2 web backends survived because their supervisor does not depend
 * on the DB. A worker must therefore treat a lost connection exactly like the boot-time race: wait (bounded, classified)
 * for the database to come back and CONTINUE with the same worker uuid; only when the budget is spent does it exit 1 so
 * the task's restart policy takes over. Nothing else changes: leases, slot ownership and authority are untouched; a
 * worker that was superseded while the DB was away learns it on its next beat() as before.
 *
 * isLoss() recognises connection-level failures only (refused, gone away, lost connection, server shutdown, too many
 * connections); a SQL error (syntax, constraint) is NOT a loss and still surfaces as before.
 */
final class EdgeWorkerDatabaseLoss
{
    /** MySQL client/server codes that mean "the server is not there", not "your SQL is wrong". */
    private const LOSS_CODES = [2002, 2003, 2006, 2013, 1040, 1053, 1077, 1927, 10054, 10061];

    private const LOSS_WORDS = ['gone away', 'lost connection', 'connection refused', 'actively refused', 'no connection could be made',
        'server shutdown', 'too many connections', 'connection timed out', 'broken pipe', 'connection reset', 'ssl connection error'];

    public static function isLoss(Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof QueryException || $cur instanceof PDOException) {
                $errno = isset($cur->errorInfo[1]) ? (int) $cur->errorInfo[1] : (is_int($cur->getCode()) ? (int) $cur->getCode() : 0);
                if (in_array($errno, self::LOSS_CODES, true)) {
                    return true;
                }
                $msg = strtolower($cur->getMessage());
                foreach (self::LOSS_WORDS as $w) {
                    if (str_contains($msg, $w)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Drop the dead connection and wait (bounded, classified) for the database to answer again.
     *
     * @param  callable(string): void  $say  operator line sink (the command's output)
     * @return bool TRUE = back (the caller continues its loop), FALSE = budget spent or not retryable (the caller exits 1)
     */
    public static function recover(EdgeLocalDbReadiness $readiness, string $worker, Throwable $cause, callable $say): bool
    {
        $budget = (int) config('edge.workers.db_startup_wait_seconds', 180);
        $interval = (int) config('edge.workers.db_startup_interval_seconds', 3);
        $say("database connection lost (" . class_basename($cause) . ") — waiting up to {$budget}s for it to come back; the worker slot and all leases stay as they are.");
        Log::warning("edge {$worker}: database connection lost — waiting", ['error' => class_basename($cause) . ': ' . mb_substr($cause->getMessage(), 0, 160), 'budget_seconds' => $budget]);
        try {
            DB::purge('tenant'); // the next query reconnects lazily from the same configuration
        } catch (Throwable) {
        }
        $result = $readiness->await($budget, $interval, function (array $r, int $attempt) use ($say) {
            if (! $r['ready'] && $attempt % 5 === 1) {
                $say("waiting for the Edge-local database (attempt {$attempt}): {$r['status']} - {$r['detail']}");
            }
        });
        if ($result['ready']) {
            $say("database back after {$result['waited_seconds']}s ({$result['attempts']} probe(s)) — continuing.");
            Log::warning("edge {$worker}: database connection restored", ['waited_seconds' => $result['waited_seconds'], 'attempts' => $result['attempts']]);

            return true;
        }
        $last = $result['last'];
        $say("database did not come back within {$result['waited_seconds']}s ({$last['status']}: {$last['detail']}) — exiting so the supervisor restarts this worker.");
        Log::error("edge {$worker}: database did not come back", ['status' => $last['status'], 'retryable' => $last['retryable'], 'waited_seconds' => $result['waited_seconds']]);

        return false;
    }
}
