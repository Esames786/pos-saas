<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeAuthorityService;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * OFFLINE EDGE — P0 BRANCH AUTHORITY, appliance commands (one class, four allow-listed signatures):
 *   edge:local:authority-heartbeat  — one heartbeat tick (the supervised Windows task runs it every interval)
 *   edge:local:authority-status     — state + readiness gates (read-only)
 *   edge:local:authority-takeover   — supervised Local Mode activation (fails closed on any gate)
 *   edge:local:authority-handback   — return authority to the Cloud when the sync is clean
 */
class EdgeLocalAuthorityCommand extends Command
{
    protected $signature = 'edge:local:authority-heartbeat';

    protected $description = 'P0 branch authority lease — send one heartbeat to the Cloud and record the outcome';

    public function handle(EdgeAuthorityService $authority): int
    {
        return $this->run_($authority, 'heartbeat', false, null);
    }

    protected function run_(EdgeAuthorityService $authority, string $action, bool $confirm, ?string $by): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('Branch Server only.');

            return self::FAILURE;
        }
        try {
            switch ($action) {
                case 'heartbeat':
                    $r = $authority->heartbeat();
                    $this->line(json_encode($r));

                    return $r['ok'] ? self::SUCCESS : self::FAILURE;
                case 'status':
                    $this->line(json_encode(['state' => $authority->state(), 'lease_mode' => $authority->leaseModeEnabled(), 'gates' => $authority->gates(), 'can_take_over' => $authority->canTakeOver(), 'cashier' => $authority->cashierState()], JSON_PRETTY_PRINT));

                    return self::SUCCESS;
                case 'takeover':
                    $r = $authority->takeOver($confirm, $by);
                    $this->info('LOCAL MODE ' . ($r['already'] ? 'already active' : 'ACTIVATED') . '.');

                    return self::SUCCESS;
                case 'handback':
                    $r = $authority->handback($by);
                    $this->info('Authority handed back to the Cloud (state ' . $r['state'] . ').');

                    return self::SUCCESS;
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::FAILURE;
    }
}
