<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeAuthorityService;

class EdgeLocalAuthorityTakeoverCommand extends EdgeLocalAuthorityCommand
{
    protected $signature = 'edge:local:authority-takeover
        {--confirm : Supervisor confirmation (pilot posture)}
        {--by= : Who confirmed}
        {--accept-stale : Supervisor consciously accepts a standby that is NOT provably fresh (requires --reason; audited)}
        {--reason= : Why a stale standby is acceptable right now}';

    protected $description = 'P0/Q branch authority — activate LOCAL MODE after the Cloud lease lapsed and every readiness gate (incl. standby freshness) passes';

    public function handle(EdgeAuthorityService $authority): int
    {
        return $this->run_($authority, 'takeover', (bool) $this->option('confirm'), $this->option('by') ? (string) $this->option('by') : null, [
            'accept_stale' => (bool) $this->option('accept-stale'),
            'reason' => $this->option('reason') ? (string) $this->option('reason') : null,
        ]);
    }
}
