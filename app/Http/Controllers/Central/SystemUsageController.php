<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Services\Central\SystemUsageService;

/**
 * MASTER-UNIVERSE-1 — "Refresh now" on the Master dashboard: the same read-only snapshot the
 * daily `system:usage-snapshot` takes (server + every tenant). It only counts; it changes nothing.
 */
class SystemUsageController extends Controller
{
    public function refresh(SystemUsageService $usage)
    {
        // Reading every tenant can outlast a web request's 30–60 s limit, so give it up to 5 minutes —
        // but only where a limit exists. Under CLI (tests, artisan) the limit is 0 = none, and setting
        // one would arm a 5-minute kill on the whole process.
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < 300) {
            @set_time_limit(300);
        }
        $result = $usage->snapshot();
        $failed = count($result['failed']);

        return redirect()->route('central.dashboard')->with('status',
            'Numbers refreshed: server + ' . $result['tenants'] . ' tenant(s)'
            . ($failed ? ", {$failed} could not be read (shown as —)." : '.'));
    }
}
