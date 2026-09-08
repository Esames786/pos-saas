<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeAuthorityService;

class EdgeLocalAuthorityStatusCommand extends EdgeLocalAuthorityCommand
{
    protected $signature = 'edge:local:authority-status';

    protected $description = 'P0 branch authority lease — appliance state, readiness gates and the cashier-facing label';

    public function handle(EdgeAuthorityService $authority): int
    {
        return $this->run_($authority, 'status', false, null);
    }
}
