<?php

namespace App\Console\Commands;

use App\Services\Edge\AutoUpdate\EdgeAutoUpdateService;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateStateStore;
use App\Services\Edge\AutoUpdate\EdgeDatabaseSnapshot;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * EDGE 0.9 (stream B) — the appliance auto-updater (task BingooEdgeUpdater, every edge.auto_update.check_interval_minutes).
 *
 *   php artisan edge:local:auto-update                 one tick: check K6 → verify → stage → apply only when the gate opens
 *   php artisan edge:local:auto-update --check-only    check + report what is available; never downloads or applies
 *   php artisan edge:local:auto-update --request-now   record an operator "Update now" (bypasses ONLY the maintenance window)
 *   php artisan edge:local:auto-update --status [--json]
 *   php artisan edge:local:auto-update --snapshot-to=<file>     full encrypted DB snapshot (the setup wizard's update mode)
 *   php artisan edge:local:auto-update --restore-from=<file> --confirm-restore=RESTORE
 *
 * Branch Server only, CLI-allowlisted. The gate (standby + outbox empty + window/Update now) and the K6 rollback rule live in
 * the services; this command never bypasses them. Exit 0 for every non-error outcome (waiting / up to date / updated) so the
 * scheduled task does not flap; exit 1 for refused / failed / rolled back / error so monitoring sees it.
 */
class EdgeLocalAutoUpdateCommand extends Command
{
    protected $signature = 'edge:local:auto-update
        {--check-only : Check the Cloud and report; never download or apply}
        {--force-check : Check the Cloud now even if the last check is recent}
        {--request-now : Record an "Update now" request (the safety gate still applies)}
        {--status : Print the auto-update status and exit}
        {--snapshot-to= : Write a full encrypted snapshot of the Edge-local database to this file and exit}
        {--restore-from= : Restore the Edge-local database from a snapshot file (standby + empty outbox only)}
        {--confirm-restore= : Must be RESTORE for --restore-from}
        {--json : Machine-readable output}';

    protected $description = 'Check, verify and (only in standby, with an empty outbox, inside the maintenance window or on Update now) apply a signed Edge update.';

    public function handle(EdgeAutoUpdateService $service, EdgeAutoUpdateStateStore $store): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:auto-update only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        EdgeLocalDatabase::useAsTenantConnection();

        if ($this->option('status')) {
            return $this->out($service->status(), self::SUCCESS);
        }
        if (($file = (string) $this->option('snapshot-to')) !== '') {
            try {
                $r = app(EdgeDatabaseSnapshot::class)->snapshot($file);
            } catch (\Throwable $e) {
                return $this->out(['result' => 'failed', 'message' => $e->getMessage()], self::FAILURE);
            }

            return $this->out(['result' => 'snapshot_written'] + $r, self::SUCCESS);
        }
        if (($file = (string) $this->option('restore-from')) !== '') {
            return $this->restore($file);
        }
        if ($this->option('request-now')) {
            $r = $store->requestUpdateNow('cli:' . (function_exists('gethostname') ? gethostname() : 'edge'), 'cli');

            return $this->out(['result' => 'update_now_requested'] + $r, self::SUCCESS);
        }

        $result = $service->tick(['check_only' => (bool) $this->option('check-only'), 'force_check' => (bool) $this->option('force-check') || (bool) $this->option('check-only')]);
        $ok = in_array($result['result'], ['disabled', 'busy', 'not_paired', 'up_to_date', 'available', 'waiting', 'updated'], true);

        return $this->out($result, $ok ? self::SUCCESS : self::FAILURE);
    }

    private function restore(string $file): int
    {
        if ((string) $this->option('confirm-restore') !== 'RESTORE') {
            return $this->out(['result' => 'refused', 'message' => 'restore needs --confirm-restore=RESTORE'], self::FAILURE);
        }
        $facts = app(\App\Services\Edge\AutoUpdate\EdgeAutoUpdateRuntimeProbe::class)->facts();
        if (($facts['authority_state'] ?? '') !== 'standby' && ($facts['authority_state'] ?? '') !== 'unknown') {
            return $this->out(['result' => 'refused', 'message' => 'restore runs only while the Cloud sells (authority ' . $facts['authority_state'] . ')'], self::FAILURE);
        }
        if (($facts['outbox_pending'] + $facts['outbox_in_flight']) > 0 && ($facts['authority_state'] ?? '') === 'standby') {
            return $this->out(['result' => 'refused', 'message' => 'restore refused: the sync outbox is not empty'], self::FAILURE);
        }
        try {
            $r = app(EdgeDatabaseSnapshot::class)->restore($file);
        } catch (\Throwable $e) {
            return $this->out(['result' => 'failed', 'message' => $e->getMessage()], self::FAILURE);
        }

        return $this->out(['result' => 'restored'] + $r, self::SUCCESS);
    }

    private function out(array $doc, int $code): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($doc, JSON_UNESCAPED_SLASHES));
        } else {
            $headline = (string) ($doc['result'] ?? 'status');
            $this->line('auto-update: ' . $headline . (isset($doc['version']) ? ' [' . $doc['version'] . ']' : '') . (isset($doc['message']) ? ' — ' . $doc['message'] : ''));
            if (isset($doc['current_version'])) {
                $this->line('  installed  : ' . $doc['current_version']);
                $this->line('  available  : ' . (($doc['available']['version'] ?? null) ?: 'none'));
                $this->line('  window     : ' . ($doc['window']['describe'] ?? '?'));
                $this->line('  last result: ' . (($doc['last_result']['result'] ?? null) ? $doc['last_result']['result'] . ' ' . ($doc['last_result']['message'] ?? '') : 'none'));
                $this->line('  update now : ' . (($doc['update_now']['requested_at'] ?? null) ? 'requested ' . $doc['update_now']['requested_at'] : 'no'));
            }
            foreach ((array) ($doc['reasons'] ?? []) as $r) {
                $this->line('  - ' . (is_array($r) ? ($r['code'] . ': ' . $r['detail']) : (string) $r));
            }
        }

        return $code;
    }
}
