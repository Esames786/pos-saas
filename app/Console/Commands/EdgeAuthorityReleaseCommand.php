<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Edge\EdgeAuthorityLeaseService;
use App\Services\Tenancy\TenancyManager;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * OFFLINE EDGE — P0 BRANCH AUTHORITY LEASE, Cloud-only operator action: release a DEAD appliance's lease so the
 * Cloud becomes the branch writer again. Audited (reason + operator). Denied on a Branch Server by the console
 * boundary (not allow-listed) and by the explicit runtime check below. Use ONLY when the appliance will never
 * heartbeat again — releasing a live appliance's lease would create the split brain the lease exists to prevent.
 */
class EdgeAuthorityReleaseCommand extends Command
{
    protected $signature = 'edge:authority:release {tenant : tenant code} {branch : branch id} {--reason= : Why the lease is released} {--by= : Operator}';

    protected $description = 'Cloud-only: release a dead appliance\'s branch authority lease (audited)';

    public function handle(TenancyManager $tenancy, EdgeAuthorityLeaseService $leases): int
    {
        if (EdgeRuntime::isBranchServer()) {
            $this->error('Cloud only.');

            return self::FAILURE;
        }
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('A --reason is required (audited).');

            return self::FAILURE;
        }
        $tenant = Tenant::where('code', (string) $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        $tenancy->activate($tenant);
        try {
            $view = $leases->release((int) $this->argument('branch'), $reason, $this->option('by') ? (string) $this->option('by') : null);
        } finally {
            $tenancy->deactivate();
        }
        if ($view === null) {
            $this->warn('No lease exists for that branch — nothing to release.');

            return self::SUCCESS;
        }
        $this->info('Lease released: holder=' . $view['holder'] . ' (the Cloud is the branch writer again).');

        return self::SUCCESS;
    }
}
