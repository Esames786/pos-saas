<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeSyncSender;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * OFFLINE-SYNC-ENGINE-1D — drain the Edge sale outbox to the Cloud over authenticated HTTPS.
 *
 *   php artisan edge:local:sync-send [--max=50]
 *
 * Branch Server only, CLI-allowlisted. Pure transport: it leases each pending envelope and hands it to
 * EdgeSyncSender, which POSTs the immutable bytes and acts on the VERIFIED ACK. It never posts business
 * data itself and never blocks a local sale — the outbox is drained out of band, retryably.
 */
class EdgeLocalSyncSendCommand extends Command
{
    protected $signature = 'edge:local:sync-send {--max=50 : maximum envelopes to transport this run}';

    protected $description = 'Transport pending Edge sale outbox envelopes to the Cloud (authenticated, retry-safe).';

    public function handle(EdgeSyncSender $sender): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:sync-send only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }

        // Boot ordering: tolerate MariaDB not being ready yet (bounded wait; the task restarts otherwise).
        if (! \App\Services\Edge\EdgeWorkerBootstrap::awaitDatabase((int) env('EDGE_WORKER_DB_WAIT_TRIES', 30))) {
            $this->error('local database not ready — deferring sync send.');

            return self::FAILURE;
        }

        $owner = gethostname() . ':' . getmypid();
        $max = max(1, (int) $this->option('max'));
        $counts = ['acknowledged' => 0, 'retry' => 0, 'terminal' => 0, 'reject' => 0];

        for ($i = 0; $i < $max; $i++) {
            $outcome = $sender->sendNext($owner);
            if ($outcome === 'idle') {
                break;
            }
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        $this->info(sprintf(
            'Sync transport: %d acknowledged, %d retryable, %d terminal, %d rejected.',
            $counts['acknowledged'], $counts['retry'], $counts['terminal'], $counts['reject']
        ));

        return self::SUCCESS;
    }
}
