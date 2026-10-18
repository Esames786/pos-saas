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
        @set_time_limit(300);
        $result = $usage->snapshot();
        $failed = count($result['failed']);

        return redirect()->route('central.dashboard')->with('status',
            'Numbers refreshed: server + ' . $result['tenants'] . ' tenant(s)'
            . ($failed ? ", {$failed} could not be read (shown as —)." : '.'));
    }
}
