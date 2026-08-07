<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeLocalReadiness;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section T) — safe, NON-SECRET support/status command for a Branch Server.
 *
 *   php artisan edge:local:status
 *
 * Prints only non-secret facts: runtime mode, local DB connectivity, the bound tenant/branch/device
 * (public identifiers), activation epoch, bootstrap schema, config revision, import timestamp and
 * foundation readiness. It NEVER prints DB passwords, device secrets, the app key, tokens,
 * certificates or customer PII.
 */
class EdgeLocalStatusCommand extends Command
{
    protected $signature = 'edge:local:status {--json : Emit the status as JSON}';

    protected $description = 'Show non-secret Branch Server local-runtime status and readiness.';

    public function handle(EdgeLocalReadiness $readiness): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:status only applies to a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }

        $report = $readiness->report();
        $report['edge_db_host'] = EdgeLocalDatabase::host();
        $report['edge_db_database'] = EdgeLocalDatabase::database();
        $report['db_connectivity'] = $this->pingLocalDb() ? 'ok' : 'unreachable';
        $importedAt = $this->safeMeta('imported_at');
        $report['imported_at'] = $importedAt ? (string) $importedAt : null;
        $report['device_uuid'] = $this->safeMeta('device_uuid');

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Bingoo Edge — Branch Server local runtime');
        foreach ($report as $k => $v) {
            $this->line(sprintf('  %-20s %s', $k, is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) || $v === null ? (string) $v : json_encode($v))));
        }

        return self::SUCCESS;
    }

    private function pingLocalDb(): bool
    {
        try {
            DB::connection('tenant')->getPdo();

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function safeMeta(string $column): mixed
    {
        try {
            return \App\Models\Edge\EdgeLocalMeta::current()?->{$column};
        } catch (Throwable $e) {
            return null;
        }
    }
}
