<?php

namespace App\Console\Commands;

use App\Services\Edge\AutoUpdate\EdgeDeviceCloudClient;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * EDGE 0.9 (stream B) — report an install / update phase to the Cloud device row (contract K4), device-authenticated.
 *
 *   php artisan edge:local:report-status ready --message="6 users enrolled" [--failed] [--report-version=0.9.0-edge] [--json]
 *
 * Best effort by design: an older Cloud without the endpoint, or no network, never fails the wizard step that called it
 * (exit 0 with sent=false); only an unknown phase is an error. Non-secret: the message is operator text (≤ 500 chars).
 */
class EdgeLocalReportStatusCommand extends Command
{
    public const PHASES = ['installing', 'paired', 'bootstrapped', 'enrolled', 'ready', 'updating', 'updated', 'update_failed', 'rolled_back'];

    protected $signature = 'edge:local:report-status
        {phase : installing|paired|bootstrapped|enrolled|ready|updating|updated|update_failed|rolled_back}
        {--message= : Operator-readable detail (no secrets)}
        {--failed : Report ok=false}
        {--report-version= : Version to report (default: this runtime)}
        {--json : Machine-readable output}';

    protected $description = 'Report an install / update phase of this Branch Server to the Cloud (K4, best effort).';

    public function handle(EdgeDeviceCloudClient $cloud): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:report-status only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        $phase = (string) $this->argument('phase');
        if (! in_array($phase, self::PHASES, true)) {
            $this->error('Unknown phase [' . $phase . ']; one of: ' . implode(', ', self::PHASES));

            return self::FAILURE;
        }
        $version = (string) ($this->option('report-version') ?: config('edge.app_version'));
        $r = $cloud->reportStatus($phase, $version, ! $this->option('failed'), (string) ($this->option('message') ?? ''));
        $doc = ['phase' => $phase, 'version' => $version] + $r;
        if ($this->option('json')) {
            $this->line(json_encode($doc, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('K4 ' . $phase . ' (' . $version . '): ' . (($r['sent'] ?? false) ? 'sent' : 'not sent — ' . ($r['reason'] ?? ('HTTP ' . ($r['status'] ?? '?')))));
        }

        return self::SUCCESS;
    }
}
