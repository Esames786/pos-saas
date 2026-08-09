<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalPrintWorkerState;
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
 *    heartbeat; take over when the previous worker is stopped or its heartbeat is stale (crashed —
 *    its in-flight lease recovers by lease expiry, never by rewriting leases).
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

    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    /** Claim the singleton worker slot. TRUE = this process is THE worker; FALSE = another one is live. */
    public function acquire(string $workerUuid, ?string $runtimeVersion = null): bool
    {
        $this->requireBranchServer();

        return DB::connection('tenant')->transaction(function () use ($workerUuid, $runtimeVersion) {
            $row = EdgeLocalPrintWorkerState::query()->lockForUpdate()->where('singleton_guard', EdgeLocalPrintWorkerState::SINGLETON)->first();
            if (! $row) {
                EdgeLocalPrintWorkerState::create([
                    'state' => EdgeLocalPrintWorkerState::STATE_RUNNING,
                    'worker_uuid' => $workerUuid,
                    'runtime_version' => $runtimeVersion,
                    'started_at' => now(),
                    'heartbeat_at' => now(),
                    'stop_requested_at' => null,
                ]);

                return true;
            }

            $liveOther = $row->state === EdgeLocalPrintWorkerState::STATE_RUNNING
                && $row->worker_uuid !== null
                && $row->worker_uuid !== $workerUuid
                && $row->heartbeat_at !== null
                && $row->heartbeat_at->gt(now()->subSeconds(self::HEARTBEAT_STALE_SECONDS));
            if ($liveOther) {
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
            ]);

            return true;
        });
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
     * Process-health verdict for readiness/diagnostics (§10): running | stale | stopped | not_installed.
     * NEVER a lease authority.
     * @return array{state: string, worker_uuid: ?string, started_at: ?string, heartbeat_at: ?string, heartbeat_age_seconds: ?int, last_graceful_stop_at: ?string, last_error: ?string}
     */
    public function health(): array
    {
        $base = ['state' => 'not_installed', 'worker_uuid' => null, 'started_at' => null, 'heartbeat_at' => null, 'heartbeat_age_seconds' => null, 'last_graceful_stop_at' => null, 'last_error' => null];
        try {
            if (! Schema::connection('tenant')->hasTable('edge_local_print_worker_state')) {
                return $base;
            }
            $row = EdgeLocalPrintWorkerState::current();
            if (! $row) {
                return $base;
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
            ];
        } catch (Throwable $e) {
            return $base;
        }
    }

    private function requireBranchServer(): void
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('The local print worker lifecycle only exists on a Branch Server.');
        }
        $this->context->requireCurrent();
    }
}
