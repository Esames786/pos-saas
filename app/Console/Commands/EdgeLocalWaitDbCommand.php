<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeLocalDbReadiness;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * EDGE 0.8.3 SERVICE RELIABILITY (F-1) — `edge:local:wait-db`: block (bounded) until the Edge-local database is ready.
 *
 * For Scheduled-Task command lines, the updater, the reboot drill and operators: "is MySQL up yet, and if not, WHY?".
 *
 *   exit 0  ready
 *   exit 1  not ready within --timeout but RETRYABLE (MySQL still starting / refused / timeout) → the caller retries
 *   exit 2  NOT retryable: wrong credentials, unknown database, schema not initialised, unsafe target, not a Branch Server
 *
 * Non-secret output (host, port, database name, class, errno); never the user or password.
 */
class EdgeLocalWaitDbCommand extends Command
{
    protected $signature = 'edge:local:wait-db
        {--timeout=120 : Seconds to keep waiting for a retryable condition (0 = probe once)}
        {--interval=3 : Seconds between probes}
        {--json : Emit the final report as JSON}
        {--quiet-wait : Print only the final verdict (no per-attempt lines)}';

    protected $description = 'Branch Server: wait (bounded) for the Edge-local database and classify why it is not ready.';

    public function handle(EdgeLocalDbReadiness $readiness): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:wait-db only runs on a Branch Server (APP_ROLE=branch_server).');

            return 2;
        }
        EdgeLocalDatabase::useAsTenantConnection();
        $timeout = max(0, (int) $this->option('timeout'));
        $interval = max(1, (int) $this->option('interval'));
        $verbose = ! $this->option('quiet-wait') && ! $this->option('json');

        $result = $readiness->await($timeout, $interval, function (array $r, int $attempt) use ($verbose) {
            if ($verbose && ! $r['ready']) {
                $this->line(sprintf('attempt %d: %s - %s', $attempt, $r['status'], (string) $r['detail']));
            }
        });
        $last = $result['last'];
        $code = $last['ready'] ? 0 : ($last['retryable'] ? 1 : 2);

        if ($this->option('json')) {
            $this->line(json_encode(['exit_code' => $code] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $code;
        }
        $target = ($last['host'] ?? '?') . ':' . ($last['port'] ?? '?') . '/' . ($last['database'] ?? '?');
        if ($code === 0) {
            $this->info("Edge-local database READY at {$target} ({$last['latency_ms']} ms, {$result['attempts']} attempt(s), {$result['waited_seconds']} s).");
        } elseif ($code === 1) {
            $this->warn("Edge-local database NOT READY at {$target} after {$result['waited_seconds']} s / {$result['attempts']} attempt(s): {$last['status']} - {$last['detail']} (retryable).");
        } else {
            $this->error("Edge-local database NOT USABLE at {$target}: {$last['status']} - {$last['detail']} (configuration problem; waiting will not help).");
        }

        return $code;
    }
}
