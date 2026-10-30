<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalPrintWorkerState;
use App\Support\EdgeProcessLiveness;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * EDGE-LOCAL-PRINT-1 Slice 2 (§7/§9/§12) — the print-worker PROCESS lifecycle authority.
 *
 * PRODUCTION TOPOLOGY (explicit): ONE worker process per appliance. The Scheduled Task supervisor
 * can mis-fire a duplicate; the second process must observe the singleton state row and EXIT CLEANLY
 * — the per-job lease tokens stay the sole delivery authority either way (this row is diagnostics +
 * takeover arbitration only, mirroring how leases treat expiry as revoked authority):
 *
 *  - acquire(): under the singleton row lock, refuse when another worker is RUNNING with a FRESH
 *    heartbeat AND its process is verifiably alive (0.8.3, F-G10: pid + process start time are recorded
 *    next to the heartbeat — a fresh heartbeat left by a process that is GONE (crash, fast reboot) is
 *    taken over at once instead of leaving the appliance without a worker); take over when the
 *    previous worker is stopped or its heartbeat is stale (crashed — its in-flight lease recovers by
 *    lease expiry, never by rewriting leases). The decision matrix is EdgeWorkerSlotPolicy.
 *  - beat(): refreshes the heartbeat ONLY while this worker still owns the row; a superseded worker
 *    learns it and exits.
 *  - requestStop()/shouldStop(): COOPERATIVE stop (no pcntl on Windows php-cli) — the loop checks the
 *    flag between jobs, finishes any in-flight delivery, records a graceful stop, exits. A supervisor
 *    stop therefore never orphans a lease mid-write; a hard kill falls back to lease-expiry recovery.
 *  - health(): running | stale | stopped | not_installed — for readiness/diagnostics only.
 */
class EdgeLocalPrintWorkerSupervisor
{
    /** A heartbeat older than this = the process is gone (kept below the 120s job-lease window). */
    public const HEARTBEAT_STALE_SECONDS = 90;

    /** The last acquire() decision (for the command's operator line); null before any acquire. */
    private ?array $lastDecision = null;

    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    /**
     * Claim the singleton worker slot. TRUE = this process is THE worker; FALSE = another one is live.
     *
     * FIRST-EVER-START RACE (fresh appliance, empty table): two simultaneous processes can both see
     * zero rows, so creation must be DETERMINISTIC, not read-then-create: an INSERT IGNORE (the only
     * unique on this table is the singleton_guard) mints the placeholder row exactly once, then BOTH
     * processes lock the now-existing row and the normal ownership decision picks exactly one winner —
     * the loser gets the clean duplicate-start refusal, never a duplicate-key exception. A zero-row
     * gap-lock deadlock (MySQL errno 1213) gets ONE bounded retry landing on the same decision.
     *
     * @param  array{pid?:int,token?:?string,host?:string}|null  $identity  this process (default: EdgeProcessLiveness::self())
     */
    public function acquire(string $workerUuid, ?string $runtimeVersion = null, ?array $identity = null): bool
    {
        $this->requireBranchServer();
        $identity ??= EdgeProcessLiveness::self();

        $attempt = function () use ($workerUuid, $runtimeVersion, $identity): bool {
            return DB::connection('tenant')->transaction(function () use ($workerUuid, $runtimeVersion, $identity) {
                DB::connection('tenant')->table('edge_local_print_worker_state')->insertOrIgnore([
                    'singleton_guard' => EdgeLocalPrintWorkerState::SINGLETON,
                    'state' => EdgeLocalPrintWorkerState::STATE_STOPPED,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = EdgeLocalPrintWorkerState::query()->lockForUpdate()->where('singleton_guard', EdgeLocalPrintWorkerState::SINGLETON)->first();

                return $this->decideOwnership($row, $workerUuid, $runtimeVersion, $identity);
            });
        };

        try {
            return $attempt();
        } catch (\Illuminate\Database\QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1213) {
                throw $e; // only the known zero-row gap-lock deadlock is retried
            }

            return $attempt();
        }
    }

    /** The decision acquire() last made (reason, heartbeat age, OS liveness verdict) — for operator output. */
    public function lastDecision(): ?array
    {
        return $this->lastDecision;
    }

    /** The one ownership decision, always made under the singleton row lock. */
    private function decideOwnership(EdgeLocalPrintWorkerState $row, string $workerUuid, ?string $runtimeVersion, array $identity): bool
    {
        $decision = EdgeWorkerSlotPolicy::decide($row, $workerUuid, self::HEARTBEAT_STALE_SECONDS);
        $decision['previous_worker_uuid'] = $row->worker_uuid;
        $this->lastDecision = $decision;
        if (! $decision['takeover']) {
            return false; // §12: duplicate daemon exits cleanly
        }

        $row->update([
            'state' => EdgeLocalPrintWorkerState::STATE_RUNNING,
            'worker_uuid' => $workerUuid,
            'runtime_version' => $runtimeVersion,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'stop_requested_at' => null, // a fresh supervised start clears any stale stop request
            'last_error' => null,
        ] + EdgeWorkerSlotPolicy::identityColumns($identity));

        return true;
    }

    /**
     * Refresh the heartbeat. FALSE = this worker no longer owns the slot (superseded) → exit.
     * Read-verify-save instead of a guarded UPDATE: MySQL reports 0 AFFECTED rows when the new
     * same-second timestamp equals the old one, which would falsely look like a takeover. The tiny
     * read→save window can at worst let a superseded worker loop ONE extra iteration — per-job lease
     * tokens keep delivery correct regardless (this row is never a lease authority).
     */
    public function beat(string $workerUuid): bool
    {
        $row = EdgeLocalPrintWorkerState::current();
        if (! $row || $row->worker_uuid !== $workerUuid || $row->state !== EdgeLocalPrintWorkerState::STATE_RUNNING) {
            return false;
        }
        $row->forceFill(['heartbeat_at' => now()])->save();

        return true;
    }

    /** Cooperative stop check (between jobs — never mid-delivery). */
    public function shouldStop(string $workerUuid): bool
    {
        $row = EdgeLocalPrintWorkerState::current();

        return $row === null
            || $row->worker_uuid !== $workerUuid                       // superseded
            || $row->state !== EdgeLocalPrintWorkerState::STATE_RUNNING
            || $row->stop_requested_at !== null;                       // stop requested
    }

    /** Record the worker's clean exit (only if this worker still owns the slot). */
    public function markStopped(string $workerUuid, bool $graceful = true, ?string $error = null): void
    {
        $update = [
            'state' => EdgeLocalPrintWorkerState::STATE_STOPPED,
            'stop_requested_at' => null,
            'last_error' => $error !== null ? mb_substr($error, 0, 500) : null,
        ];
        if ($graceful) {
            $update['last_graceful_stop_at'] = now();
        }
        EdgeLocalPrintWorkerState::query()
            ->where('singleton_guard', EdgeLocalPrintWorkerState::SINGLETON)
            ->where('worker_uuid', $workerUuid)
            ->update($update);
    }

    /** Ask the running worker to stop after its current job. TRUE = a running worker was flagged. */
    public function requestStop(): bool
    {
        $this->requireBranchServer();

        return EdgeLocalPrintWorkerState::query()
            ->where('singleton_guard', EdgeLocalPrintWorkerState::SINGLETON)
            ->where('state', EdgeLocalPrintWorkerState::STATE_RUNNING)
            ->update(['stop_requested_at' => now()]) === 1;
    }

    /**
     * Process-health verdict for readiness/diagnostics (§10):
     * running | stale | stopped | not_installed | unavailable. NEVER a lease authority.
     *
     * TRUTHFULNESS CONTRACT: "not installed" and "database failure" are DIFFERENT facts (the same
     * distinction the Edge runtime readiness already draws). Only a GENUINELY absent table/row is
     * not_installed; a connection/query failure reports `unavailable` with a sanitized reason —
     * never a false "not installed" (and never raw connection details/credentials).
     *
     * 0.8.3: `process` carries the recorded identity (pid/host). When the heartbeat is STALE and $probeProcess
     * is true, the OS is asked once whether that process still exists, so an operator can tell a wedged worker
     * (`stale_process_alive`) from a crashed one (`stale_process_gone`). The verdict never changes `state`.
     * @return array{state: string, worker_uuid: ?string, started_at: ?string, heartbeat_at: ?string, heartbeat_age_seconds: ?int, last_graceful_stop_at: ?string, last_error: ?string, process: ?array}
     */
    public function health(bool $probeProcess = true): array
    {
        $base = ['state' => 'not_installed', 'worker_uuid' => null, 'started_at' => null, 'heartbeat_at' => null, 'heartbeat_age_seconds' => null, 'last_graceful_stop_at' => null, 'last_error' => null, 'process' => null];
        try {
            if (! Schema::connection('tenant')->hasTable('edge_local_print_worker_state')) {
                return $base; // genuinely absent schema → not_installed
            }
            $row = EdgeLocalPrintWorkerState::current();
            if (! $row) {
                return $base; // schema present, worker never ran → not_installed
            }
            $state = EdgeLocalPrintWorkerState::STATE_STOPPED === $row->state ? 'stopped'
                : (($row->heartbeat_at !== null && $row->heartbeat_at->gt(now()->subSeconds(self::HEARTBEAT_STALE_SECONDS))) ? 'running' : 'stale');

            return [
                'state' => $state,
                'worker_uuid' => $row->worker_uuid,
                'started_at' => $row->started_at?->toIso8601String(),
                'heartbeat_at' => $row->heartbeat_at?->toIso8601String(),
                'heartbeat_age_seconds' => $row->heartbeat_at !== null ? (int) abs(now()->diffInSeconds($row->heartbeat_at)) : null,
                'last_graceful_stop_at' => $row->last_graceful_stop_at?->toIso8601String(),
                'last_error' => $row->last_error,
                'process' => self::processReport($row->worker_pid, $row->worker_process_token, $row->worker_host, $state, $probeProcess),
            ];
        } catch (Throwable $e) {
            // DB unreachable/corrupt ≠ not installed — report honestly, sanitized (class only, no DSN).
            return ['state' => 'unavailable', 'worker_uuid' => null, 'started_at' => null, 'heartbeat_at' => null,
                'heartbeat_age_seconds' => null, 'last_graceful_stop_at' => null,
                'last_error' => 'database unavailable (' . class_basename($e) . ')', 'process' => null];
        }
    }

    /**
     * Shared by both singleton workers: the recorded identity plus — only for a STALE heartbeat, only when asked —
     * the OS liveness verdict (stale_process_alive | stale_process_gone | stale_process_unknown).
     */
    public static function processReport(mixed $pid, ?string $token, ?string $host, string $state, bool $probe): array
    {
        $report = [
            'pid' => $pid !== null ? (int) $pid : null,
            'host' => $host,
            'identity_recorded' => $pid !== null,
            'liveness' => null,
            'verdict' => null,
        ];
        if ($state === 'stale' && $probe && $pid !== null) {
            $check = EdgeProcessLiveness::check((int) $pid, $token, $host);
            $report['liveness'] = $check['status'];
            $report['liveness_reason'] = $check['reason'];
            $report['verdict'] = match ($check['status']) {
                EdgeProcessLiveness::ALIVE => 'stale_process_alive',   // wedged: alive but not beating
                EdgeProcessLiveness::DEAD => 'stale_process_gone',     // crashed / killed / rebooted
                default => 'stale_process_unknown',
            };
        }

        return $report;
    }

    private function requireBranchServer(): void
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('The local print worker lifecycle only exists on a Branch Server.');
        }
        $this->context->requireCurrent();
    }
}
