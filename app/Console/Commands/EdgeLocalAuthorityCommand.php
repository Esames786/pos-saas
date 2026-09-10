<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeConnectionStateMachine;
use App\Services\Edge\EdgeHandbackOrchestrator;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * OFFLINE EDGE — P0 BRANCH AUTHORITY + Q, appliance commands (one class, four allow-listed signatures):
 *   edge:local:authority-heartbeat  — one heartbeat tick (the supervised worker runs ticks continuously)
 *   edge:local:authority-status     — authority state, connection state, readiness gates, freshness proof, handback blockers
 *   edge:local:authority-takeover   — supervised Local Mode activation (fails closed on any gate; freshness may be
 *                                     consciously overridden by a supervisor with an audited --reason)
 *   edge:local:authority-handback   — the CONTROLLED handback orchestration (assess first; explicit blockers)
 */
class EdgeLocalAuthorityCommand extends Command
{
    protected $signature = 'edge:local:authority-heartbeat';

    protected $description = 'P0 branch authority lease — send one heartbeat to the Cloud and record the outcome';

    public function handle(EdgeAuthorityService $authority): int
    {
        return $this->run_($authority, 'heartbeat', false, null);
    }

    /**
     * @param  array{accept_stale?:bool, reason?:?string, assess?:bool}  $opts
     */
    protected function run_(EdgeAuthorityService $authority, string $action, bool $confirm, ?string $by, array $opts = []): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('Branch Server only.');

            return self::FAILURE;
        }
        try {
            switch ($action) {
                case 'heartbeat':
                    $r = $authority->heartbeat();
                    app(EdgeConnectionStateMachine::class)->evaluate();
                    $this->line(json_encode($r));

                    return $r['ok'] ? self::SUCCESS : self::FAILURE;
                case 'status':
                    $states = app(EdgeConnectionStateMachine::class);
                    $handback = null;
                    if (in_array($authority->state(), [EdgeAuthorityService::LOCAL_ACTIVE, EdgeAuthorityService::HANDING_BACK], true)) {
                        $handback = app(EdgeHandbackOrchestrator::class)->assess();
                    }
                    $this->line(json_encode([
                        'state' => $authority->state(),
                        'lease_mode' => $authority->leaseModeEnabled(),
                        'connection' => $states->evaluate(),
                        'gates' => $authority->gates(),
                        'can_take_over' => $authority->canTakeOver(),
                        'freshness' => $authority->freshness(),
                        'handback' => $handback,
                        'cashier' => $authority->cashierState(),
                    ], JSON_PRETTY_PRINT));

                    return self::SUCCESS;
                case 'takeover':
                    $r = $authority->takeOver($confirm, $by, (bool) ($opts['accept_stale'] ?? false), $opts['reason'] ?? null);
                    app(EdgeConnectionStateMachine::class)->evaluate();
                    $this->info('LOCAL MODE ' . ($r['already'] ? 'already active' : 'ACTIVATED') . '.' . (($r['stale_accepted'] ?? false) ? ' STALE STANDBY ACCEPTED (audited).' : ''));

                    return self::SUCCESS;
                case 'handback':
                    $orchestrator = app(EdgeHandbackOrchestrator::class);
                    if ($opts['assess'] ?? false) {
                        $this->line(json_encode($orchestrator->assess(), JSON_PRETTY_PRINT));

                        return self::SUCCESS;
                    }
                    $r = $orchestrator->run($by);
                    if ($r['status'] === EdgeHandbackOrchestrator::HANDED_BACK) {
                        $this->info('Authority handed back to the Cloud — the appliance is a warm standby again.');

                        return self::SUCCESS;
                    }
                    if ($r['status'] === EdgeHandbackOrchestrator::BLOCKED) {
                        $this->warn('HANDBACK_BLOCKED — nothing was discarded. Resolve on the appliance first:');
                        foreach ($r['blockers'] as $b) {
                            $this->line('  - ' . $b['code'] . ': ' . $b['detail']);
                        }

                        return self::FAILURE;
                    }
                    $this->error('Handback failed: ' . ($r['error'] ?? 'unknown') . ' (authority ' . ($r['authority_state'] ?? '?') . ' — fenced until retried).');

                    return self::FAILURE;
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::FAILURE;
    }
}
