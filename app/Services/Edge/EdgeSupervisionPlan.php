<?php

namespace App\Services\Edge;

use App\Support\EdgeConsoleBoundary;
use App\Support\EdgeRuntime;
use RuntimeException;

/**
 * OFFLINE EDGE PRODUCTIZATION (J) — the deterministic supervision plan for the Branch Server appliance.
 *
 * One source of truth for WHICH long-running / periodic workers the appliance runs and under WHAT policy,
 * so the Windows Scheduled Task installers (scripts/edge) and the contract tests agree. It emits data, not
 * side effects; the PowerShell scripts render it. Policy, locked:
 *
 *   - branch_server ONLY — a Cloud host supervises nothing here (fail closed);
 *   - every task runs an EDGE-ALLOWLISTED artisan command — a Cloud command can never be scheduled;
 *   - least privilege — a restricted service account, NON-elevated, NEVER SYSTEM;
 *   - one logical instance — the print worker via its singleton heartbeat row, the sync sender via the
 *     outbox SKIP-LOCKED lease, backup via its single-writer file lock (so a duplicate task run is safe);
 *   - boot start + bounded restart, and a bounded DB-startup wait so a worker that boots before MariaDB
 *     retries instead of crash-looping;
 *   - no secret ever appears on a command line (the command is just php + artisan + the command name).
 */
class EdgeSupervisionPlan
{
    /** A heartbeat/behaviour the appliance's workers already implement; named here for the contract tests. */
    public const SINGLETON_HEARTBEAT = 'singleton_heartbeat';   // print worker (EdgeLocalPrintWorkerSupervisor)
    public const SINGLETON_OUTBOX_LEASE = 'outbox_lease';       // sync sender (SKIP LOCKED)
    public const SINGLETON_BACKUP_LOCK = 'backup_lock';         // backup (flock)

    private const SERVICE_ACCOUNT = 'NT AUTHORITY\\LOCAL SERVICE';

    /**
     * @return array<int,array<string,mixed>> the supervised tasks (deterministic order).
     */
    public function tasks(string $phpPath, string $appRoot): array
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('SUPERVISION_NOT_BRANCH_SERVER: only a Branch Server supervises Edge workers.');
        }
        $appRoot = rtrim($appRoot, "/\\");
        $artisan = $appRoot . DIRECTORY_SEPARATOR . 'artisan';

        $tasks = [
            $this->task('BingooEdgePrintWorker', 'edge:local:print-worker', $phpPath, $artisan, $appRoot, [
                'trigger' => 'at_startup', 'kind' => 'continuous', 'singleton' => self::SINGLETON_HEARTBEAT,
            ]),
            $this->task('BingooEdgeSyncSender', 'edge:local:sync-send', $phpPath, $artisan, $appRoot, [
                'trigger' => 'at_startup', 'kind' => 'periodic', 'repeat_minutes' => 2, 'singleton' => self::SINGLETON_OUTBOX_LEASE,
            ]),
            $this->task('BingooEdgeBackup', 'edge:local:backup', $phpPath, $artisan, $appRoot, [
                'trigger' => 'at_startup', 'kind' => 'periodic', 'repeat_minutes' => 60, 'singleton' => self::SINGLETON_BACKUP_LOCK,
            ]),
        ];

        // Defence-in-depth: nothing but an Edge-allowlisted command may ever be scheduled on the appliance.
        foreach ($tasks as $t) {
            if (! EdgeConsoleBoundary::isAllowed($t['artisan_command'])) {
                throw new RuntimeException('SUPERVISION_COMMAND_DENIED: ' . $t['artisan_command'] . ' is not Edge-allowlisted.');
            }
        }

        return $tasks;
    }

    private function task(string $name, string $command, string $phpPath, string $artisan, string $appRoot, array $policy): array
    {
        return [
            'name' => $name,
            'artisan_command' => $command,
            'executable' => $phpPath,
            // The ONLY arguments are the artisan entrypoint + the command name — never a secret/credential.
            'arguments' => '"' . $artisan . '" ' . $command,
            'working_directory' => $appRoot,
            'principal' => self::SERVICE_ACCOUNT,
            'run_level' => 'limited',      // NON-elevated
            'logon_type' => 'service_account',
            'trigger' => $policy['trigger'],
            'kind' => $policy['kind'],
            'repeat_minutes' => $policy['repeat_minutes'] ?? null,
            'restart_count' => 999,
            'restart_interval_minutes' => 1,
            'start_when_available' => true,
            'singleton' => $policy['singleton'],
            'startup_db_retry' => true,     // bounded wait for MariaDB before giving up (task restarts)
            'stop' => 'cooperative',        // graceful stop first, never a hard kill that orphans a lease
        ];
    }
}
