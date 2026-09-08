<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeAuthorityService;

class EdgeLocalAuthorityTakeoverCommand extends EdgeLocalAuthorityCommand
{
    protected $signature = 'edge:local:authority-takeover {--confirm : Supervisor confirmation (pilot posture)} {--by= : Who confirmed}';

    protected $description = 'P0 branch authority lease — activate LOCAL MODE after the Cloud lease lapsed and every readiness gate passes';

    public function handle(EdgeAuthorityService $authority): int
    {
        return $this->run_($authority, 'takeover', (bool) $this->option('confirm'), $this->option('by') ? (string) $this->option('by') : null);
    }
}
