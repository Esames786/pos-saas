<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeAuthorityTick;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalAuthorityWorkerSupervisor;
use App\Services\Edge\EdgeLocalDbReadiness;
use App\Services\Edge\EdgeWorkerSlotPolicy;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeProcessLiveness;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * OFFLINE EDGE — Q: the ONE supervised authority worker of a Branch Server.
 *
 * Every `edge.authority.interval_seconds` it runs one EdgeAuthorityTick: heartbeat → connection state machine →
 * warm-standby freshness (standby) or outbox drain + reconciliation (local). Single instance (DB singleton with a
 * liveness heartbeat — a duplicate start exits cleanly), cooperative stop (`--stop`), bounded HTTP timeouts, no
 * secrets on the command line (device credentials come from config), safe restart (every fact is persisted; the
 * state machine re-derives), failures recorded. It NEVER changes authority: takeover/handback are supervised commands.
 *
 * 0.8.3 SERVICE RELIABILITY: classified bounded DB wait (F-1) and process-identity-aware slot acquisition (F-G10) —
 * a fresh heartbeat left by a dead process is taken over instead of exiting as a duplicate. Authority semantics are
 * untouched: this only decides which PROCESS runs the ticking loop.
 */
class EdgeLocalAuthorityWorkerCommand extends Command
{
    protected $signature = 'edge:local:authority-worker
        {--once : Run exactly one tick and exit}
        {--max-ticks= : Stop after this many ticks}
        {--interval= : Seconds between ticks (default edge.authority.interval_seconds)}
        {--stop : Request a cooperative stop of the running worker, then wait for it}
        {--stop-wait=30 : Seconds to wait for the running worker to stop (with --stop)}';

    protected $description = 'Branch Server: supervised authority heartbeat + connection state machine + warm-standby freshness worker (one instance)';

    public function handle(EdgeAuthorityTick $tick, EdgeBranchContext $context, EdgeLocalAuthorityWorkerSupervisor $supervisor, EdgeLocalDbReadiness $readiness): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:authority-worker only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        EdgeLocalDatabase::useAsTenantConnection();
        if ($this->option('stop')) {
            return $this->requestStopAndWait($supervisor);
        }
        if (! $this->waitForDatabase($context, $readiness)) {
            return self::FAILURE;
        }
        $workerUuid = (string) Str::uuid();
        $identity = EdgeProcessLiveness::self();
        if (! $supervisor->acquire($workerUuid, (string) config('edge.app_version'), $identity)) {
            $d = $supervisor->lastDecision() ?? ['takeover' => false, 'reason' => 'unknown', 'heartbeat_age_seconds' => null, 'liveness' => null];
            $this->warn('another authority worker is already RUNNING (fresh heartbeat) — exiting cleanly (one-worker topology). ' . EdgeWorkerSlotPolicy::describe($d, $d['previous_worker_uuid'] ?? null));
            Log::info('edge authority-worker duplicate start refused', ['reason' => $d['reason'], 'heartbeat_age_seconds' => $d['heartbeat_age_seconds'], 'liveness' => $d['liveness']['status'] ?? null, 'pid' => $identity['pid']]);

            return self::SUCCESS;
        }
        $d = $supervisor->lastDecision();
        if ($d && $d['reason'] !== EdgeWorkerSlotPolicy::TAKEOVER_SLOT_FREE) {
            $this->warn(EdgeWorkerSlotPolicy::describe($d, $d['previous_worker_uuid'] ?? null));
            Log::warning('edge authority-worker took over the worker slot', ['reason' => $d['reason'], 'previous_worker' => $d['previous_worker_uuid'] ?? null, 'heartbeat_age_seconds' => $d['heartbeat_age_seconds'], 'liveness' => $d['liveness']['status'] ?? null, 'pid' => $identity['pid']]);
        }
        $interval = max(5, (int) ($this->option('interval') ?: config('edge.authority.interval_seconds', 20)));
        $maxTicks = $this->option('max-ticks') !== null && $this->option('max-ticks') !== '' ? max(1, (int) $this->option('max-ticks')) : null;
        $this->info("authority-worker {$workerUuid} started (pid {$identity['pid']}, interval {$interval}s).");
        $ticks = 0;
        $graceful = true;
        $lastError = null;
        try {
            do {
                if (! $supervisor->beat($workerUuid)) {
                    $this->warn('worker slot was taken over (stale heartbeat assumed) — exiting.');
                    Log::warning('edge authority-worker superseded — exiting', ['worker' => $workerUuid, 'pid' => $identity['pid']]);
                    break;
                }
                if ($supervisor->shouldStop($workerUuid)) {
                    $this->info('cooperative stop requested — exiting.');
                    break;
                }
                $ticks++;
                try {
                    $r = $tick->run('authority-worker:' . substr($workerUuid, 0, 8));
                    $outcome = ($r['heartbeat']['ok'] ? 'ack' : 'miss') . ' ' . $r['state']['state'] . ' ' . json_encode($r['work']);
                    $supervisor->recordTick($workerUuid, $outcome);
                    $this->line("tick {$ticks}: {$outcome}");
                } catch (Throwable $e) {
                    $lastError = $e->getMessage();
                    $supervisor->recordTick($workerUuid, 'error', $e->getMessage());
                    $this->error("tick {$ticks}: " . $e->getMessage());
                }
                if ($this->option('once') || ($maxTicks !== null && $ticks >= $maxTicks)) {
                    break;
                }
                sleep($interval);
            } while (true);
        } catch (Throwable $e) {
            $graceful = false;
            $lastError = $e->getMessage();
            Log::error('edge authority-worker crashed', ['worker' => $workerUuid, 'error' => class_basename($e) . ': ' . $e->getMessage()]);
            throw $e;
        } finally {
            $supervisor->markStopped($workerUuid, $graceful, $lastError);
        }
        $this->info("authority-worker {$workerUuid} stopped after {$ticks} tick(s).");

        return self::SUCCESS;
    }

    private function requestStopAndWait(EdgeLocalAuthorityWorkerSupervisor $supervisor): int
    {
        if (! $supervisor->requestStop()) {
            $this->info('no running authority worker — nothing to stop.');

            return self::SUCCESS;
        }
        $deadline = microtime(true) + max(1, (int) $this->option('stop-wait'));
        while (microtime(true) < $deadline) {
            $h = $supervisor->health(probeProcess: false);
            if (($h['state'] ?? 'stopped') !== 'running') {
                $this->info('authority worker stopped gracefully.');

                return self::SUCCESS;
            }
            usleep(500_000);
        }
        $this->warn('worker did not confirm a graceful stop in time — if the process is dead, its heartbeat goes stale and a supervised restart takes the slot.');

        return self::SUCCESS;
    }

    /** Bounded, classified wait for the Edge-local DB (see EdgeLocalPrintWorkerCommand::waitForDatabase). */
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
            Log::error('edge authority-worker: database not ready', ['status' => $last['status'], 'retryable' => $last['retryable'], 'waited_seconds' => $result['waited_seconds'], 'attempts' => $result['attempts']]);

            return false;
        }
        try {
            $context->requireCurrent();

            return true;
        } catch (Throwable $e) {
            $this->error('Branch Server is not bootstrapped (no Edge binding) — refusing to run.');

            return false;
        }
    }
}
