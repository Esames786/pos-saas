<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeSyncStatusService;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * OFFLINE-SYNC-ENGINE-1E — the appliance's operator/support SYNC STATUS surface.
 *
 *   php artisan edge:local:sync-status [--queue=20] [--json]
 *
 * Branch Server only, CLI-allowlisted, READ-ONLY. Prints the sync headline (identity, outbox depth,
 * oldest pending, last ACK/failure in business terms, baseline-cutover state) and an optional queue
 * drill-down. Never prints secrets or full payloads — it is safe to run during support.
 */
class EdgeLocalSyncStatusCommand extends Command
{
    protected $signature = 'edge:local:sync-status {--queue=0 : also print the N most recent queue rows} {--json : emit machine-readable JSON}';

    protected $description = 'Show the Edge sale-sync status: outbox depth, exceptions, and baseline-cutover state (read-only).';

    public function handle(EdgeSyncStatusService $status): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:sync-status only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }

        $snapshot = $status->snapshot();
        $queueLimit = (int) $this->option('queue');
        $queue = $queueLimit > 0 ? $status->queue($queueLimit) : [];

        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => $snapshot, 'queue' => $queue], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (($snapshot['bound'] ?? false) === false) {
            $this->warn('This appliance is not bound to a branch yet.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Branch %d · epoch %d · revision %s', $snapshot['branch_id'], $snapshot['activation_epoch'], $snapshot['config_revision']));
        $this->line(sprintf('Local selling: %s (baseline %s)', ($snapshot['local_selling_safe'] ? 'SAFE' : 'FENCED'), $snapshot['baseline']['state'] ?? 'n/a'));
        $o = $snapshot['outbox'];
        $this->line(sprintf('Outbox: %d pending, %d leased, %d acknowledged, %d failed', $o['pending'], $o['leased'], $o['acknowledged'], $o['failed_permanent']));
        if ($snapshot['last_failure'] !== null) {
            $this->line(sprintf('Last problem: %s — %s', $snapshot['last_failure']['label'], $snapshot['last_failure']['action']));
        }

        foreach ($queue as $r) {
            $this->line(sprintf('  %s  %-16s  %s  %s', mb_substr($r['sale_uuid'], 0, 10), $r['state'], $r['content_hash_short'], $r['failure_label']));
        }

        return self::SUCCESS;
    }
}
