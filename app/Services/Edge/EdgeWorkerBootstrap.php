<?php

namespace App\Services\Edge;

use Illuminate\Support\Facades\DB;

/**
 * OFFLINE EDGE PRODUCTIZATION (J) — bounded startup readiness for supervised workers.
 *
 * On a Windows boot the worker Scheduled Task can start before MariaDB has finished initialising. Rather than
 * crash-loop, a worker waits a BOUNDED number of tries for the local database, then gives up cleanly (the
 * task's restart policy re-runs it). Bounded — never an unbounded spin.
 */
class EdgeWorkerBootstrap
{
    /** True once the local DB answers; false after `$tries` bounded attempts. */
    public static function awaitDatabase(int $tries = 30, int $sleepMs = 1000): bool
    {
        $tries = max(1, $tries);
        for ($i = 0; $i < $tries; $i++) {
            try {
                DB::connection('tenant')->select('select 1');

                return true;
            } catch (\Throwable $e) {
                if ($i + 1 < $tries) {
                    usleep(max(0, $sleepMs) * 1000);
                }
            }
        }

        return false;
    }
}
