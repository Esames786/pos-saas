<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPrintDeliveryService;
use App\Services\Edge\EdgeNetworkPrinterTransport;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * EDGE-LOCAL-PRINT-1 (§11) — the Branch Server's local physical print worker.
 *
 * Claims queued printer-bearing jobs through the lease-token authority
 * (EdgeLocalPrintDeliveryService), writes the EXACT stored raw_payload to the trusted bootstrapped
 * printer over LAN TCP, and completes through the same lease token — a stale worker can never
 * overwrite a newer lease's outcome. Fail-closed: branch_server runtime + a current Edge binding are
 * REQUIRED; printer IP/port are never accepted as input; no master DB is touched. One job's failure
 * never kills the loop (the bounded backoff policy owns retries). Windows Service installation and
 * updater wiring belong to the installer sprint — this is the runnable core.
 */
class EdgeLocalPrintWorkerCommand extends Command
{
    protected $signature = 'edge:local:print-worker
        {--once : Run exactly one claim/delivery cycle (deterministic, for tests/diagnostics)}
        {--max-jobs= : Stop after successfully processing N claim cycles}
        {--idle-sleep=3 : Seconds to sleep when no job is claimable (loop mode)}';

    protected $description = 'Branch Server local print delivery worker (lease-safe, at-least-once).';

    private bool $shouldStop = false;

    public function handle(EdgeLocalPrintDeliveryService $deliveries, EdgeNetworkPrinterTransport $transport, EdgeBranchContext $context): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:print-worker only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        $context->requireCurrent(); // fail closed without a real Edge binding

        $workerUuid = (string) Str::uuid();
        $this->info("print-worker {$workerUuid} started (lease " . EdgeLocalPrintDeliveryService::LEASE_SECONDS . 's).');

        // graceful stop where the platform supports signals (not on Windows php-cli).
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $processed = 0;
        $maxJobs = $this->option('max-jobs') !== null ? max(1, (int) $this->option('max-jobs')) : null;
        $idleSleep = max(1, (int) $this->option('idle-sleep'));

        do {
            $claim = null;
            try {
                $claim = $deliveries->claimNext($workerUuid);
            } catch (Throwable $e) {
                $this->error('claim error: ' . $e->getMessage());
            }

            if ($claim !== null) {
                $processed++;
                try {
                    // deliver the EXACT stored payload — never rebuilt at delivery time.
                    $transport->send($claim['ip'], $claim['port'], $claim['raw_payload']);
                    $ok = $deliveries->completeSuccess($claim['job_id'], $claim['lease_token']);
                    $this->line("job {$claim['job_id']}: delivered" . ($ok ? '' : ' (stale lease — outcome owned by a newer worker)'));
                } catch (Throwable $e) {
                    $recorded = false;
                    try {
                        $recorded = $deliveries->completeFailure($claim['job_id'], $claim['lease_token'], $e->getMessage());
                    } catch (Throwable $inner) {
                        $this->error("job {$claim['job_id']}: failure bookkeeping error: " . $inner->getMessage());
                    }
                    $this->warn("job {$claim['job_id']}: delivery failed — " . $e->getMessage() . ($recorded ? '' : ' (stale lease — ignored)'));
                }
            } elseif (! $this->option('once')) {
                sleep($idleSleep);
            }

            if ($this->option('once')) {
                break;
            }
            if ($maxJobs !== null && $processed >= $maxJobs) {
                break;
            }
        } while (! $this->shouldStop);

        $this->info("print-worker {$workerUuid} stopped after {$processed} job(s).");

        return self::SUCCESS;
    }
}
