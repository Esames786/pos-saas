<?php

namespace App\Console\Commands;

use App\Services\Central\SystemUsageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MASTER-UNIVERSE-1 — take today's usage snapshot for the Bingoo Master dashboard.
 *
 * Writes one row for the server (CPU load, RAM, disk, MySQL size) and one per tenant (database size,
 * files, 30-day sales, products, users) into the master table `system_usage_snapshots`.
 * READ-ONLY on every tenant database — it only counts. Safe to run any time; each run adds rows.
 *
 * Scheduled daily (routes/console.php, Cloud only). "Refresh now" on the dashboard runs the same code.
 */
class SystemUsageSnapshotCommand extends Command
{
    protected $signature = 'system:usage-snapshot
        {--keep-days=400 : Delete snapshot rows older than this many days (0 = keep all)}';

    protected $description = 'Read-only: record server + per-tenant usage (DB size, files, 30-day sales, products, users) for Bingoo Master.';

    public function handle(SystemUsageService $usage): int
    {
        $result = $usage->snapshot(function ($tenant, array $reading) {
            $this->line(sprintf('  %-22s db %-9s files %-9s sales(30d) %-6s %s',
                $tenant->tenant_code,
                SystemUsageService::bytes($reading['db_bytes']),
                SystemUsageService::bytes($reading['files_bytes']),
                $reading['sales_30d'] ?? '—',
                $reading['meta']['error'] ?? ''
            ));
        });

        $keep = (int) $this->option('keep-days');
        if ($keep > 0) {
            $gone = DB::connection('master')->table(SystemUsageService::TABLE)
                ->where('taken_at', '<', now()->subDays($keep))->delete();
            if ($gone) {
                $this->line("  pruned {$gone} row(s) older than {$keep} days");
            }
        }

        $this->info(sprintf('Snapshot at %s: server + %d tenant(s), %d could not be read.',
            $result['taken_at']->toDateTimeString(), $result['tenants'], count($result['failed'])));

        return self::SUCCESS;
    }
}
