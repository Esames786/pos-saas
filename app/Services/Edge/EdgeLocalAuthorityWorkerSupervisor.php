<?php

namespace App\Services\Edge;

use App\Support\EdgeProcessLiveness;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * OFFLINE EDGE — Q: the ONE logical authority worker per appliance (heartbeat + state machine + standby freshness).
 *
 * Same proven single-instance contract as the print worker: a singleton row minted deterministically (INSERT IGNORE),
 * ownership decided under the row lock, a fresh heartbeat = live, a stale one = the process is gone and a supervised
 * restart may take the slot; a cooperative stop flag checked between ticks; a hard kill recovers by staleness. This
 * row is never an authority — the P lease is.
 *
 * 0.8.3 (F-G10): the worker's process identity (pid, start time, host) is recorded next to the heartbeat and the OS
 * is asked whether that process still exists before a fresh heartbeat is trusted (EdgeWorkerSlotPolicy): a process that
 * is gone after a crash or a fast reboot is taken over at once. NOTHING here touches the lease/takeover/handback
 * semantics — only who runs the ticking loop.
 */
class EdgeLocalAuthorityWorkerSupervisor
{
    public const TABLE = 'edge_local_authority_worker_state';
    public const HEARTBEAT_STALE_SECONDS = 90;

    private ?array $lastDecision = null;

    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    /** @param array{pid?:int,token?:?string,host?:string}|null $identity this process (default: EdgeProcessLiveness::self()) */
    public function acquire(string $workerUuid, ?string $runtimeVersion = null, ?array $identity = null): bool
    {
        $this->requireBranchServer();
        $identity ??= EdgeProcessLiveness::self();
        $attempt = function () use ($workerUuid, $runtimeVersion, $identity): bool {
            return DB::connection('tenant')->transaction(function () use ($workerUuid, $runtimeVersion, $identity) {
                DB::connection('tenant')->table(self::TABLE)->insertOrIgnore(['singleton_guard' => 1, 'state' => 'stopped', 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::connection('tenant')->table(self::TABLE)->where('singleton_guard', 1)->lockForUpdate()->first();
                $decision = EdgeWorkerSlotPolicy::decide($row, $workerUuid, self::HEARTBEAT_STALE_SECONDS);
                $decision['previous_worker_uuid'] = $row->worker_uuid;
                $this->lastDecision = $decision;
                if (! $decision['takeover']) {
                    return false;
                }
                DB::connection('tenant')->table(self::TABLE)->where('singleton_guard', 1)->update([
                    'state' => 'running', 'worker_uuid' => $workerUuid, 'runtime_version' => $runtimeVersion,
                    'started_at' => now(), 'heartbeat_at' => now(), 'stop_requested_at' => null, 'stopped_at' => null, 'last_error' => null, 'updated_at' => now(),
                ] + EdgeWorkerSlotPolicy::identityColumns($identity, $workerUuid));

                return true;
            });
        };
        try {
            return $attempt();
        } catch (\Illuminate\Database\QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1213) {
                throw $e;
            }

            return $attempt();
        }
    }

    /** The decision acquire() last made (reason, heartbeat age, OS liveness verdict) — for operator output. */
    public function lastDecision(): ?array
    {
        return $this->lastDecision;
    }

    /** FALSE = this worker no longer owns the slot → exit. */
    public function beat(string $workerUuid): bool
    {
        $row = DB::connection('tenant')->table(self::TABLE)->where('singleton_guard', 1)->first();
        if (! $row || $row->worker_uuid !== $workerUuid || $row->state !== 'running') {
            return false;
        }
        DB::connection('tenant')->table(self::TABLE)->where('singleton_guard', 1)->update(['heartbeat_at' => now(), 'updated_at' => now()]);

        return true;
    }

    public function recordTick(string $workerUuid, string $outcome, ?string $error = null): void
    {
        DB::connection('tenant')->table(self::TABLE)->where('singleton_guard', 1)->where('worker_uuid', $workerUuid)->update([
            'last_tick_at' => now(), 'last_tick_outcome' => mb_substr($outcome, 0, 191), 'last_error' => $error !== null ? mb_substr($error, 0, 2000) : null, 'updated_at' => now(),
        ]);
    }

    public function shouldStop(string $workerUuid): bool
    {
        $row = DB::connection('tenant')->table(self::TABLE)->where('singleton_guard', 1)->first();

        return ! $row || $row->worker_uuid !== $workerUuid || $row->stop_requested_at !== null;
    }

    public function markStopped(string $workerUuid, bool $graceful = true, ?string $error = null): void
    {
        DB::connection('tenant')->table(self::TABLE)->where('singleton_guard', 1)->where('worker_uuid', $workerUuid)->update([
            'state' => 'stopped', 'stopped_at' => now(), 'last_error' => $error !== null ? mb_substr($error, 0, 2000) : ($graceful ? null : 'stopped abnormally'), 'updated_at' => now(),
        ]);
    }

    /** Request a cooperative stop of the running worker; FALSE when none is running. */
    public function requestStop(): bool
    {
        $row = DB::connection('tenant')->table(self::TABLE)->where('singleton_guard', 1)->first();
        if (! $row || $row->state !== 'running') {
            return false;
        }
        DB::connection('tenant')->table(self::TABLE)->where('singleton_guard', 1)->update(['stop_requested_at' => now(), 'updated_at' => now()]);

        return true;
    }

    /** @param bool $probeProcess ask the OS about a STALE worker's process (wedged vs gone); false keeps the report cheap */
    public function health(bool $probeProcess = true): array
    {
        try {
            $row = DB::connection('tenant')->table(self::TABLE)->where('singleton_guard', 1)->first();
        } catch (\Throwable $e) {
            return ['installed' => null, 'running' => false, 'error' => get_class($e)];
        }
        if (! $row) {
            return ['installed' => false, 'running' => false];
        }
        $live = $row->state === 'running' && $row->heartbeat_at !== null && \Illuminate\Support\Carbon::parse($row->heartbeat_at)->gt(now()->subSeconds(self::HEARTBEAT_STALE_SECONDS));
        $state = $row->state !== 'running' ? 'stopped' : ($live ? 'running' : 'stale');

        return [
            'installed' => true, 'running' => $live, 'state' => $row->state, 'liveness_state' => $state, 'heartbeat_at' => $row->heartbeat_at,
            'heartbeat_age_seconds' => $row->heartbeat_at !== null ? (int) abs(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($row->heartbeat_at))) : null,
            'last_tick_at' => $row->last_tick_at, 'last_tick_outcome' => $row->last_tick_outcome, 'stop_requested' => $row->stop_requested_at !== null,
            'process' => EdgeLocalPrintWorkerSupervisor::processReport($row->worker_pid ?? null, $row->worker_process_token ?? null, $row->worker_host ?? null, $state, $probeProcess, $row->worker_uuid),
        ];
    }

    private function requireBranchServer(): void
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('The authority worker runs only on a Branch Server.');
        }
        $this->context->requireCurrent();
    }
}
