<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeAuthorityService;

class EdgeLocalAuthorityHandbackCommand extends EdgeLocalAuthorityCommand
{
    protected $signature = 'edge:local:authority-handback {--by= : Who requested the handback}';

    protected $description = 'P0 branch authority lease — return branch authority to the Cloud (only when the sync is clean)';

    public function handle(EdgeAuthorityService $authority): int
    {
        return $this->run_($authority, 'handback', false, $this->option('by') ? (string) $this->option('by') : null);
    }
}
