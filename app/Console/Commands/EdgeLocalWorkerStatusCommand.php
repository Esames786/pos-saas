<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeLocalAuthorityWorkerSupervisor;
use App\Services\Edge\EdgeLocalPrintWorkerSupervisor;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeProcessLiveness;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * EDGE 0.8.3 SERVICE RELIABILITY (F-2/F-4) — `edge:local:worker-status`: the two singleton worker slots (print worker,
 * authority worker) with their recorded PROCESS identity and, with --probe, the OS liveness verdict of that process.
 *
 * Read-only. Written for the scoped start/stop control (a start must refuse while a LIVE process holds the slot; a stop
 * must know the pid it may terminate after the cooperative wait) and for the reboot drill. Non-secret: uuids, pids,
 * host name, ages, classifications. Never a lease or authority view — the authority state is edge:local:authority-status.
 *
 *   exit 0  report produced (whatever the slots say)
 *   exit 1  not a Branch Server / database unavailable
 */
class EdgeLocalWorkerStatusCommand extends Command
{
    protected $signature = 'edge:local:worker-status
        {--probe : Ask the OS whether each recorded worker process is alive (one bounded probe per worker)}
        {--json : Emit JSON}';

    protected $description = 'Branch Server: singleton worker slots (print, authority) with process identity and optional OS liveness.';

    public function handle(EdgeLocalPrintWorkerSupervisor $print, EdgeLocalAuthorityWorkerSupervisor $authority): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:worker-status only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        EdgeLocalDatabase::useAsTenantConnection();
        $probe = (bool) $this->option('probe');

        $slots = [];
        foreach (['print_worker' => 'edge_local_print_worker_state', 'authority_worker' => EdgeLocalAuthorityWorkerSupervisor::TABLE] as $name => $table) {
            try {
                $row = DB::connection('tenant')->table($table)->where('singleton_guard', 1)->first();
            } catch (\Throwable $e) {
                $this->error('Edge-local database unavailable (' . class_basename($e) . ').');

                return self::FAILURE;
            }
            $stale = $name === 'print_worker' ? EdgeLocalPrintWorkerSupervisor::HEARTBEAT_STALE_SECONDS : EdgeLocalAuthorityWorkerSupervisor::HEARTBEAT_STALE_SECONDS;
            $slots[$name] = $this->slot($row, $stale, $probe);
        }
        $out = ['host' => EdgeProcessLiveness::host(), 'probed' => $probe, 'generated_at' => now()->toIso8601String(), 'slots' => $slots];

        if ($this->option('json')) {
            $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        foreach ($slots as $name => $s) {
            $this->line(sprintf('%-17s %-9s heartbeat %s  pid %s%s  -> %s', $name, $s['state'], $s['heartbeat_age_seconds'] !== null ? $s['heartbeat_age_seconds'] . 's ago' : 'none',
                $s['pid'] ?? '-', $s['liveness'] ? ' ' . $s['liveness'] : '', $s['slot_verdict']));
        }

        return self::SUCCESS;
    }

    /** One slot: state, heartbeat age, recorded identity, optional liveness, and the verdict a new start would reach. */
    private function slot(?object $row, int $staleSeconds, bool $probe): array
    {
        if (! $row) {
            return ['state' => 'not_installed', 'worker_uuid' => null, 'heartbeat_age_seconds' => null, 'pid' => null, 'host' => null, 'liveness' => null, 'liveness_reason' => null, 'slot_verdict' => 'free (never ran)'];
        }
        $hb = $row->heartbeat_at !== null ? \Illuminate\Support\Carbon::parse($row->heartbeat_at) : null;
        $age = $hb !== null ? (int) abs(now()->diffInSeconds($hb)) : null;
        $state = $row->state !== 'running' ? 'stopped' : (($hb !== null && $age < $staleSeconds) ? 'running' : 'stale');
        $pid = $row->worker_pid ?? null;
        $liveness = null;
        $reason = null;
        if ($probe && $pid !== null && $row->state === 'running') {
            $c = \App\Services\Edge\EdgeWorkerSlotPolicy::livenessFor((int) $pid, $row->worker_process_token ?? null, $row->worker_host ?? null, $row->worker_uuid);
            $liveness = $c['status'];
            $reason = $c['reason'];
        }
        $verdict = match (true) {
            $state === 'stopped' => 'free (stopped)',
            $state === 'running' && $liveness === EdgeProcessLiveness::DEAD => 'free (fresh heartbeat but the process is gone - a start takes over)',
            $state === 'running' && $liveness === EdgeProcessLiveness::ALIVE => 'HELD by a live process - a start exits as duplicate',
            $state === 'running' => 'held (fresh heartbeat' . ($pid === null ? ', no process identity recorded' : ', process not probed') . ') - a start exits as duplicate',
            $liveness === EdgeProcessLiveness::ALIVE => 'stale but the process is alive (wedged) - a start takes over; the old process exits on its next beat',
            default => 'stale - a start takes over',
        };

        return [
            'state' => $state, 'row_state' => $row->state, 'worker_uuid' => $row->worker_uuid, 'runtime_version' => $row->runtime_version ?? null,
            'heartbeat_at' => $row->heartbeat_at, 'heartbeat_age_seconds' => $age, 'stale_after_seconds' => $staleSeconds,
            'pid' => $pid !== null ? (int) $pid : null, 'host' => $row->worker_host ?? null, 'identity_recorded' => $pid !== null,
            'identity_bound' => $pid !== null ? \App\Services\Edge\EdgeWorkerSlotPolicy::identityBoundTo($row->worker_process_token ?? null, $row->worker_uuid) : null,
            'liveness' => $liveness, 'liveness_reason' => $reason, 'stop_requested' => ($row->stop_requested_at ?? null) !== null, 'slot_verdict' => $verdict,
        ];
    }
}
