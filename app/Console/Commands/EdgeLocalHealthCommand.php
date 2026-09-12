<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeApplianceHealthService;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * P4 §11 — the ONE operator/admin health command for a Branch Server.
 *
 *   php artisan edge:local:health [--json] [--fail-on-problems]
 *
 * Service status, Cloud connectivity, authority state, binding, warm-standby freshness (config / stock / returns /
 * supplier finance / purchase returns), outbox pending + permanent failures, last sync, DB health, print worker +
 * printers, backup recency, updater/version, gateway certificate. NON-SECRET — never a password, secret or key.
 */
class EdgeLocalHealthCommand extends Command
{
    protected $signature = 'edge:local:health
        {--json : Emit the full report as JSON}
        {--fail-on-problems : Exit non-zero when the report lists problems (for the installer / monitoring)}';

    protected $description = 'Show the consolidated, non-secret Branch Server health report.';

    public function handle(EdgeApplianceHealthService $health): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:health only applies to a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        EdgeLocalDatabase::useAsTenantConnection();
        $report = $health->report();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report);
        }

        return ($this->option('fail-on-problems') && $report['problems'] !== []) ? self::FAILURE : self::SUCCESS;
    }

    private function render(array $r): void
    {
        $this->info('Bingoo Edge — Branch Server health  [' . $r['status'] . ']  ' . $r['status_label']);
        $rows = [
            ['Runtime', 'Edge ' . $r['runtime']['edge_app_version'] . ' · PHP ' . $r['runtime']['php_version'] . ' · ' . ($r['runtime']['packaged_artifact'] ? 'packaged' : 'dev tree') . ($r['runtime']['artifact_commit'] ? ' · ' . substr((string) $r['runtime']['artifact_commit'], 0, 12) : '')],
            ['Local DB', ($r['database']['reachable'] ? 'ok' : 'UNREACHABLE') . ' · ' . ($r['database']['database'] ?? '?') . ' @ ' . ($r['database']['host'] ?? '?') . ($r['database']['server_version'] ? ' · ' . $r['database']['server_version'] : '')],
            ['Binding', ($r['binding']['bound'] ?? false) ? ('tenant ' . $r['binding']['tenant_code'] . ' · branch #' . $r['binding']['branch_id'] . ' · device ' . $r['binding']['device_uuid'] . ' · epoch ' . $r['binding']['activation_epoch'] . ' · users ' . $r['binding']['enrolled_local_users']) : 'NOT BOUND'],
            ['Authority', ($r['authority']['state'] ?? '-') . ' · connection ' . ($r['authority']['connection_state'] ?? '-') . ' · last ack ' . ($r['authority']['last_heartbeat_ack_at'] ?? 'never') . ' · failures ' . ($r['authority']['consecutive_failures'] ?? 0)],
        ];
        foreach (['config', 'stock', 'returnable', 'supplier_finance', 'purchase_return'] as $k) {
            if (isset($r['freshness'][$k])) {
                $f = $r['freshness'][$k];
                $rows[] = ['Freshness ' . $k, (($f['ok'] ?? false) ? 'current' : 'STALE') . (isset($f['refreshed_at']) && $f['refreshed_at'] ? ' · refreshed ' . $f['refreshed_at'] : '') . (! empty($f['reasons']) ? ' · ' . implode('; ', $f['reasons']) : '')];
            }
        }
        if (isset($r['sync']['outbox_pending'])) {
            $rows[] = ['Sync outbox', 'pending ' . $r['sync']['outbox_pending'] . ' · leased ' . $r['sync']['outbox_leased'] . ' · permanent failures ' . $r['sync']['outbox_failed_permanent'] . ' · acknowledged ' . $r['sync']['outbox_acknowledged'] . ' · last ack ' . ($r['sync']['last_acknowledged_at'] ?? 'never')];
        }
        $rows[] = ['Print worker', ($r['workers']['print_worker']['state'] ?? '?') . ' · network printers (Edge direct) ' . ($r['print']['network_printers_edge_direct'] ?? 0)];
        $rows[] = ['Authority worker', ($r['workers']['authority_worker']['running'] ?? false) ? 'running' : 'not running'];
        foreach ($r['workers']['web_backends'] ?? [] as $w) {
            $rows[] = ['Web backend #' . $w['worker'], $w['listen'] . ' · ' . ($w['listening'] ? 'listening' : 'not listening')];
        }
        $rows[] = ['Gateway', ($r['gateway']['kind'] ?? 'nginx') . ' :' . $r['gateway']['https_port'] . ' · ' . ($r['gateway']['https_listening'] ? 'listening' : 'not listening') . ' · cert ' . ($r['gateway']['certificate_present'] ? ('present' . (isset($r['gateway']['certificate']['days_left']) ? ', ' . $r['gateway']['certificate']['days_left'] . ' days left' : '')) : 'MISSING')];
        $rows[] = ['Backup', ($r['backup']['recovery_key_configured'] ? 'key ok' : 'NO KEY') . ' · last ' . ($r['backup']['last']['created_at'] ?? 'never') . ' · path ' . $r['backup']['path']];
        $rows[] = ['Update', 'active ' . ($r['update']['active_version_pointer'] ?? '-') . ' · verify key ' . ($r['update']['public_key_configured'] ? 'ok' : 'MISSING') . ' · last ' . ($r['update']['last']['result'] ?? '-')];
        $rows[] = ['Auto failover', 'no (supervised takeover only)'];
        $this->table(['Area', 'Status'], $rows);
        if ($r['problems'] !== []) {
            $this->warn('Problems:');
            foreach ($r['problems'] as $p) {
                $this->line('  - ' . $p);
            }
        } else {
            $this->info('No problems.');
        }
    }
}
