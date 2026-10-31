<?php

namespace App\Console\Commands;

use App\Models\Edge\EdgeLocalPrintWorkerState;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalDbReadiness;
use App\Services\Edge\EdgeLocalPrintDeliveryService;
use App\Services\Edge\EdgeLocalPrintWorkerSupervisor;
use App\Services\Edge\EdgeNetworkPrinterTransport;
use App\Services\Edge\EdgePrinterTransportException;
use App\Services\Edge\EdgeWorkerDatabaseLoss;
use App\Services\Edge\EdgeWorkerSlotPolicy;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeProcessLiveness;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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
 *
 * 0.8.3 SERVICE RELIABILITY: (F-1) the DB wait is the classified EdgeLocalDbReadiness (configurable budget,
 * non-retryable classes end it early with a named cause); (F-1b) a database that DISAPPEARS mid-run (the 30 Oct LAB
 * outage) is waited for with the same budget and the loop continues with the same worker uuid instead of crashing
 * out; (F-G10) the worker records its pid + process start time next to the heartbeat and the slot decision asks the
 * OS whether the previous worker still exists — a fresh heartbeat left by a dead process (crash, fast reboot) is
 * taken over instead of exiting as a "duplicate"; (F-3/G3) every delivery failure and every terminal failure is also
 * written to the application log with its transport classification.
 */
class EdgeLocalPrintWorkerCommand extends Command
{
    protected $signature = 'edge:local:print-worker
        {--once : Run exactly one claim/delivery cycle (deterministic, for tests/diagnostics)}
        {--max-jobs= : Stop after processing N claim cycles}
        {--idle-sleep=3 : Seconds to sleep when no job is claimable (loop mode)}
        {--stop : Request a cooperative stop of the running worker, then wait for it}
        {--stop-wait=30 : Seconds to wait for the running worker to stop (with --stop)}';

    protected $description = 'Branch Server local print delivery worker (lease-safe, at-least-once, supervised).';

    public function handle(EdgeLocalPrintDeliveryService $deliveries, EdgeNetworkPrinterTransport $transport, EdgeBranchContext $context, EdgeLocalPrintWorkerSupervisor $supervisor, EdgeLocalDbReadiness $readiness): int
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

        // §7: after a reboot MariaDB may still be starting — bounded, classified retry instead of crash-looping.
        if (! $this->waitForDatabase($context, $readiness)) {
            return self::FAILURE;
        }

        $workerUuid = (string) Str::uuid();
        $identity = EdgeProcessLiveness::self();
        if (! $supervisor->acquire($workerUuid, (string) config('edge.app_version'), $identity)) {
            $d = $supervisor->lastDecision() ?? ['takeover' => false, 'reason' => 'unknown', 'heartbeat_age_seconds' => null, 'liveness' => null];
            $this->warn('another local print worker is already RUNNING (fresh heartbeat) — exiting cleanly (one-worker topology). ' . EdgeWorkerSlotPolicy::describe($d, $d['previous_worker_uuid'] ?? null));
            Log::info('edge print-worker duplicate start refused', ['reason' => $d['reason'], 'heartbeat_age_seconds' => $d['heartbeat_age_seconds'], 'liveness' => $d['liveness']['status'] ?? null, 'pid' => $identity['pid']]);

            return self::SUCCESS; // clean duplicate-start exit: nothing corrupted, nothing claimed
        }
        $d = $supervisor->lastDecision();
        $this->info("print-worker {$workerUuid} started (pid {$identity['pid']}, lease " . EdgeLocalPrintDeliveryService::LEASE_SECONDS . 's, heartbeat stale after ' . EdgeLocalPrintWorkerSupervisor::HEARTBEAT_STALE_SECONDS . 's)'
            . ($d && $d['reason'] !== EdgeWorkerSlotPolicy::TAKEOVER_SLOT_FREE ? ' — ' . EdgeWorkerSlotPolicy::describe($d, $d['previous_worker_uuid'] ?? null) : '') . '.');
        if ($d && $d['reason'] !== EdgeWorkerSlotPolicy::TAKEOVER_SLOT_FREE) {
            Log::warning('edge print-worker took over the worker slot', ['reason' => $d['reason'], 'previous_worker' => $d['previous_worker_uuid'] ?? null, 'heartbeat_age_seconds' => $d['heartbeat_age_seconds'], 'liveness' => $d['liveness']['status'] ?? null, 'pid' => $identity['pid']]);
        }

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
        $dbLost = false;
        $say = fn (string $line) => $this->warn($line);

        try {
            do {
                // (F-1b) heartbeat + stop flag: a LOST database is waited for (bounded) and the loop continues; a superseded
                // slot or a stop request ends the loop as before.
                try {
                    if (! $supervisor->beat($workerUuid)) {
                        $this->warn('worker slot was taken over (stale heartbeat assumed) — exiting.');
                        Log::warning('edge print-worker superseded — exiting', ['worker' => $workerUuid, 'pid' => $identity['pid']]);
                        break;
                    }
                    if ($supervisor->shouldStop($workerUuid)) {
                        $this->info('cooperative stop requested — exiting after the current cycle.');
                        break;
                    }
                } catch (Throwable $e) {
                    if (! EdgeWorkerDatabaseLoss::isLoss($e)) {
                        throw $e;
                    }
                    if (! EdgeWorkerDatabaseLoss::recover($readiness, 'print-worker', $e, $say)) {
                        $lastError = 'database lost: ' . $e->getMessage();
                        $dbLost = true;
                        break;
                    }
                    continue; // re-verify ownership on the fresh connection before claiming anything
                }

                $claim = null;
                try {
                    $claim = $deliveries->claimNext($workerUuid);
                } catch (Throwable $e) {
                    if (EdgeWorkerDatabaseLoss::isLoss($e)) {
                        if (! EdgeWorkerDatabaseLoss::recover($readiness, 'print-worker', $e, $say)) {
                            $lastError = 'database lost: ' . $e->getMessage();
                            $dbLost = true;
                            break;
                        }
                        continue;
                    }
                    $lastError = $e->getMessage();
                    $this->error('claim error: ' . $e->getMessage());
                    Log::error('edge print-worker claim error', ['error' => class_basename($e) . ': ' . $e->getMessage()]);
                }

                if ($claim !== null) {
                    $processed++;
                    try {
                        // deliver the EXACT stored payload — never rebuilt at delivery time.
                        $transport->send($claim['ip'], $claim['port'], $claim['raw_payload']);
                        $ok = $deliveries->completeSuccess($claim['job_id'], $claim['lease_token']);
                        $this->line("job {$claim['job_id']}: delivered" . ($ok ? '' : ' (stale lease — outcome owned by a newer worker)'));
                    } catch (Throwable $e) {
                        $category = $e instanceof EdgePrinterTransportException ? $e->category() : 'error';
                        try {
                            $recorded = $deliveries->completeFailure($claim['job_id'], $claim['lease_token'], $e->getMessage());
                        } catch (Throwable $inner) {
                            $recorded = false;
                            $this->error("job {$claim['job_id']}: failure bookkeeping error: " . $inner->getMessage());
                            Log::error('edge print-worker failure bookkeeping error', ['job_id' => $claim['job_id'], 'error' => $inner->getMessage()]);
                        }
                        $this->warn("job {$claim['job_id']}: delivery failed [{$category}] — " . $e->getMessage() . ($recorded ? '' : ' (stale lease — ignored)'));
                        // G3: the Scheduled-Task worker may have no stdout; the application log carries every failure.
                        $terminal = false;
                        try {
                            $terminal = $recorded && $deliveries->isTerminalFailed($claim['job_id']);
                        } catch (Throwable) {
                        }
                        Log::log($terminal ? 'error' : 'warning', $terminal ? 'edge print job TERMINAL failure — operator retry/dismiss required' : 'edge print job delivery failed (will retry)', [
                            'job_id' => $claim['job_id'], 'printer_id' => $claim['printer_id'] ?? null, 'printer' => $claim['ip'] . ':' . $claim['port'],
                            'category' => $category, 'error' => $e->getMessage(), 'recorded' => $recorded,
                        ]);
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
            Log::error('edge print-worker crashed', ['worker' => $workerUuid, 'error' => class_basename($e) . ': ' . $e->getMessage()]);
            throw $e;
        } finally {
            try {
                $supervisor->markStopped($workerUuid, $graceful && ! $dbLost, $lastError);
            } catch (Throwable $e) {
                // the database is still gone: the row keeps state=running with THIS pid — the next start verifies the pid
                // through the OS (F-G10) and takes the slot over; nothing else needs to be written.
                Log::error('edge print-worker could not record its stop (database unavailable); the slot row keeps this pid for the OS check', ['worker' => $workerUuid, 'pid' => $identity['pid']]);
            }
        }

        if ($dbLost) {
            $this->error('print-worker ' . $workerUuid . ' exiting: the Edge-local database did not come back; the supervisor will restart this worker.');

            return self::FAILURE;
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

    /**
     * Bounded, classified wait for the Edge-local DB (reboot ordering: MariaDB may start after this task).
     * Budget edge.workers.db_startup_wait_seconds (default 180 s); a NON-retryable class (credentials, unknown
     * database, schema not initialised) or an unbound appliance ends the wait at once with a named cause.
     */
    private function waitForDatabase(EdgeBranchContext $context, EdgeLocalDbReadiness $readiness): bool
    {
        $budget = (int) config('edge.workers.db_startup_wait_seconds', 180);
        $interval = (int) config('edge.workers.db_startup_interval_seconds', 3);
        $result = $readiness->await($budget, $interval, function (array $r, int $attempt) {
            if (! $r['ready']) {
                $this->line("waiting for the Edge-local database (attempt {$attempt}): {$r['status']} - {$r['detail']}");
            }
        });
        if (! $result['ready']) {
            $last = $result['last'];
            $this->error('Edge-local database ' . ($last['retryable'] ? "did not become available within {$result['waited_seconds']} s ({$last['status']}: {$last['detail']}); the supervisor will restart this worker." : "is not usable ({$last['status']}: {$last['detail']}) - fix the configuration; restarting will not help."));
            Log::error('edge print-worker: database not ready', ['status' => $last['status'], 'retryable' => $last['retryable'], 'waited_seconds' => $result['waited_seconds'], 'attempts' => $result['attempts']]);

            return false;
        }
        try {
            $context->requireCurrent(); // the first real read — a bound appliance has its meta row

            return true;
        } catch (\App\Exceptions\EdgeNotBoundException $e) {
            // DB is UP but the appliance is not bootstrapped — a config problem, not a boot race.
            $this->error('Branch Server is not bootstrapped (no Edge binding) — refusing to run.');

            return false;
        }
    }
}
