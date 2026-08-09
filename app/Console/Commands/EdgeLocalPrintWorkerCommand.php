<?php

namespace App\Console\Commands;

use App\Models\Edge\EdgeLocalPrintWorkerState;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPrintDeliveryService;
use App\Services\Edge\EdgeLocalPrintWorkerSupervisor;
use App\Services\Edge\EdgeNetworkPrinterTransport;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * EDGE-LOCAL-PRINT-1 — the Branch Server's local physical print worker (Slice 2: a REAL appliance
 * runtime component).
 *
 * SUPERVISION CONTRACT (§7): started at boot by the Scheduled Task the installer registers
 * (scripts/edge/Install-EdgePrintWorkerTask.ps1 — the same Register-ScheduledTask/-AtStartup/SYSTEM/
 * RestartCount pattern the print agent already uses). Startup maps the tenant connection onto the
 * Edge-local DB (the appliance path — never Cloud), waits with bounded retries for MariaDB to come
 * up after a reboot, then claims the SINGLETON worker slot: a duplicate daemon exits cleanly (§12,
 * one-worker topology). The loop heartbeats every iteration and checks the COOPERATIVE stop flag
 * between jobs (`--stop` sets it): an in-flight delivery always finishes; a hard kill is recovered by
 * job-lease expiry — leases are NEVER rewritten on start or stop. No master/Cloud connectivity is
 * ever required. Fail-closed off branch_server; printer destinations only from bootstrapped config.
 */
class EdgeLocalPrintWorkerCommand extends Command
{
    private const DB_STARTUP_ATTEMPTS = 20;   // × 3s ≈ 60s of MariaDB-still-booting tolerance
    private const DB_STARTUP_DELAY_SECONDS = 3;

    protected $signature = 'edge:local:print-worker
        {--once : Run exactly one claim/delivery cycle (deterministic, for tests/diagnostics)}
        {--max-jobs= : Stop after processing N claim cycles}
        {--idle-sleep=3 : Seconds to sleep when no job is claimable (loop mode)}
        {--stop : Request a cooperative stop of the running worker, then wait for it}
        {--stop-wait=30 : Seconds to wait for the running worker to stop (with --stop)}';

    protected $description = 'Branch Server local print delivery worker (lease-safe, at-least-once, supervised).';

    public function handle(EdgeLocalPrintDeliveryService $deliveries, EdgeNetworkPrinterTransport $transport, EdgeBranchContext $context, EdgeLocalPrintWorkerSupervisor $supervisor): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:print-worker only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        // THE APPLIANCE DB PATH: point the tenant connection at the Edge-local DB (same mapping every
        // edge:local:* command uses) — proven by the real-process supervised-start test.
        EdgeLocalDatabase::useAsTenantConnection();

        if ($this->option('stop')) {
            return $this->requestStopAndWait($supervisor);
        }

        // §7: after a reboot MariaDB may still be starting — bounded retry instead of crash-looping.
        if (! $this->waitForDatabase($context)) {
            $this->error('Edge-local database did not become available; the supervisor will restart this worker.');

            return self::FAILURE;
        }

        $workerUuid = (string) Str::uuid();
        if (! $supervisor->acquire($workerUuid, (string) config('edge.app_version'))) {
            $this->warn('another local print worker is already RUNNING (fresh heartbeat) — exiting cleanly (one-worker topology).');

            return self::SUCCESS; // clean duplicate-start exit: nothing corrupted, nothing claimed
        }
        $this->info("print-worker {$workerUuid} started (lease " . EdgeLocalPrintDeliveryService::LEASE_SECONDS . 's, heartbeat stale after ' . EdgeLocalPrintWorkerSupervisor::HEARTBEAT_STALE_SECONDS . 's).');

        if (function_exists('pcntl_signal')) { // not on Windows php-cli — the DB stop flag is the contract
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $supervisor->requestStop());
            pcntl_signal(SIGINT, fn () => $supervisor->requestStop());
        }

        $processed = 0;
        $maxJobs = $this->option('max-jobs') !== null ? max(1, (int) $this->option('max-jobs')) : null;
        $idleSleep = max(1, (int) $this->option('idle-sleep'));
        $graceful = true;
        $lastError = null;

        try {
            do {
                if (! $supervisor->beat($workerUuid)) {
                    $this->warn('worker slot was taken over (stale heartbeat assumed) — exiting.');
                    break;
                }
                if ($supervisor->shouldStop($workerUuid)) {
                    $this->info('cooperative stop requested — exiting after the current cycle.');
                    break;
                }

                $claim = null;
                try {
                    $claim = $deliveries->claimNext($workerUuid);
                } catch (Throwable $e) {
                    $lastError = $e->getMessage();
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
                        try {
                            $recorded = $deliveries->completeFailure($claim['job_id'], $claim['lease_token'], $e->getMessage());
                        } catch (Throwable $inner) {
                            $recorded = false;
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
            } while ($maxJobs === null || $processed < $maxJobs);
        } catch (Throwable $e) {
            $graceful = false;
            $lastError = $e->getMessage();
            throw $e;
        } finally {
            $supervisor->markStopped($workerUuid, $graceful, $lastError);
        }

        $this->info("print-worker {$workerUuid} stopped after {$processed} job(s).");

        return self::SUCCESS;
    }

    /** --stop: flag the running worker, then wait (bounded) for its cooperative exit. */
    private function requestStopAndWait(EdgeLocalPrintWorkerSupervisor $supervisor): int
    {
        if (! $supervisor->requestStop()) {
            $this->info('no running local print worker — nothing to stop.');

            return self::SUCCESS;
        }
        $deadline = microtime(true) + max(1, (int) $this->option('stop-wait'));
        while (microtime(true) < $deadline) {
            $row = EdgeLocalPrintWorkerState::current();
            if (! $row || $row->state === EdgeLocalPrintWorkerState::STATE_STOPPED) {
                $this->info('local print worker stopped gracefully.');

                return self::SUCCESS;
            }
            usleep(500_000);
        }
        $this->warn('worker did not confirm a graceful stop in time — if the process is dead, job-lease expiry recovers any in-flight delivery.');

        return self::FAILURE;
    }

    /** Bounded wait for the Edge-local DB (reboot ordering: MariaDB may start after this task). */
    private function waitForDatabase(EdgeBranchContext $context): bool
    {
        for ($attempt = 1; $attempt <= self::DB_STARTUP_ATTEMPTS; $attempt++) {
            try {
                $context->requireCurrent(); // first real DB read — throws while the DB is unreachable

                return true;
            } catch (\App\Exceptions\EdgeNotBoundException $e) {
                // DB is UP but the appliance is not bootstrapped — a config problem, not a boot race.
                $this->error('Branch Server is not bootstrapped (no Edge binding) — refusing to run.');

                return false;
            } catch (Throwable $e) {
                if ($attempt === self::DB_STARTUP_ATTEMPTS) {
                    return false;
                }
                sleep(self::DB_STARTUP_DELAY_SECONDS);
            }
        }

        return false;
    }
}
