<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeAuthorityService;

class EdgeLocalAuthorityStatusCommand extends EdgeLocalAuthorityCommand
{
    protected $signature = 'edge:local:authority-status';

    protected $description = 'P0/Q branch authority — authority state, connection state, readiness gates, freshness proof, handback blockers, cashier label';

    public function handle(EdgeAuthorityService $authority): int
    {
        return $this->run_($authority, 'status', false, null);
    }
}
