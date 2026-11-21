<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeAuthorityService;

class EdgeLocalAuthorityHandbackCommand extends EdgeLocalAuthorityCommand
{
    protected $signature = 'edge:local:authority-handback
        {--by= : Who requested the handback}
        {--assess : Only report readiness and blockers, change nothing}';

    protected $description = 'Q controlled handback — return branch authority to the Cloud through the orchestrated sequence (explicit HANDBACK_BLOCKED reasons, never a silent discard)';

    public function handle(EdgeAuthorityService $authority): int
    {
        return $this->run_($authority, 'handback', false, $this->option('by') ? (string) $this->option('by') : null, [
            'assess' => (bool) $this->option('assess'),
        ]);
    }
}
