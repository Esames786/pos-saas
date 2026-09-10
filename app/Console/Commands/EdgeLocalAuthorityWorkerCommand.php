<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeAuthorityTick;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalAuthorityWorkerSupervisor;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
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
 */
class EdgeLocalAuthorityWorkerCommand extends Command
{
    private const DB_STARTUP_ATTEMPTS = 20;
    private const DB_STARTUP_DELAY_SECONDS = 3;

    protected $signature = 'edge:local:authority-worker
        {--once : Run exactly one tick and exit}
        {--max-ticks= : Stop after this many ticks}
        {--interval= : Seconds between ticks (default edge.authority.interval_seconds)}
        {--stop : Request a cooperative stop of the running worker, then wait for it}
        {--stop-wait=30 : Seconds to wait for the running worker to stop (with --stop)}';

    protected $description = 'Branch Server: supervised authority heartbeat + connection state machine + warm-standby freshness worker (one instance)';

    public function handle(EdgeAuthorityTick $tick, EdgeBranchContext $context, EdgeLocalAuthorityWorkerSupervisor $supervisor): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:authority-worker only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        EdgeLocalDatabase::useAsTenantConnection();
        if ($this->option('stop')) {
            return $this->requestStopAndWait($supervisor);
        }
        if (! $this->waitForDatabase($context)) {
            $this->error('Edge-local database did not become available; the supervisor will restart this worker.');

            return self::FAILURE;
        }
        $workerUuid = (string) Str::uuid();
        if (! $supervisor->acquire($workerUuid, (string) config('edge.app_version'))) {
            $this->warn('another authority worker is already RUNNING (fresh heartbeat) — exiting cleanly (one-worker topology).');

            return self::SUCCESS;
        }
        $interval = max(5, (int) ($this->option('interval') ?: config('edge.authority.interval_seconds', 20)));
        $maxTicks = $this->option('max-ticks') !== null && $this->option('max-ticks') !== '' ? max(1, (int) $this->option('max-ticks')) : null;
        $this->info("authority-worker {$workerUuid} started (interval {$interval}s).");
        $ticks = 0;
        $graceful = true;
        $lastError = null;
        try {
            do {
                if (! $supervisor->beat($workerUuid)) {
                    $this->warn('worker slot was taken over (stale heartbeat assumed) — exiting.');
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
            $h = $supervisor->health();
            if (($h['state'] ?? 'stopped') !== 'running') {
                $this->info('authority worker stopped gracefully.');

                return self::SUCCESS;
            }
            usleep(500_000);
        }
        $this->warn('worker did not confirm a graceful stop in time — if the process is dead, its heartbeat goes stale and a supervised restart takes the slot.');

        return self::SUCCESS;
    }

    private function waitForDatabase(EdgeBranchContext $context): bool
    {
        for ($i = 1; $i <= self::DB_STARTUP_ATTEMPTS; $i++) {
            try {
                $context->requireCurrent();

                return true;
            } catch (Throwable $e) {
                if ($i === self::DB_STARTUP_ATTEMPTS) {
                    return false;
                }
                sleep(self::DB_STARTUP_DELAY_SECONDS);
            }
        }

        return false;
    }
}
