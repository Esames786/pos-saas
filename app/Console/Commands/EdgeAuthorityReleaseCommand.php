<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\EdgeBranchAuthorityLease;
use App\Services\Edge\EdgeAuthorityLeaseService;
use App\Services\Tenancy\TenancyManager;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * OFFLINE EDGE — P0 BRANCH AUTHORITY LEASE, Cloud-only operator action: release a DEAD appliance's lease so the
 * Cloud becomes the branch writer again. Audited (reason + named operator, log line). Denied on a Branch Server by the
 * console boundary (not allow-listed) and by the explicit runtime check below.
 *
 * Safe-release preconditions (EdgeAuthorityLeaseService::releaseRefusal, checked again under the row lock):
 *  - a LIVE lease (heartbeat within the TTL) is never released: the appliance is alive;
 *  - a lease the APPLIANCE holds (it took over, then went silent) is released only with --edge-stopped, the operator's
 *    statement that the Branch Server is switched off and will not sell again (its offline sales exist only on it);
 *  - --dry-run shows the lease and the verdict and writes nothing.
 */
class EdgeAuthorityReleaseCommand extends Command
{
    protected $signature = 'edge:authority:release {tenant : tenant code} {branch : branch id}
        {--reason= : Why the lease is released (required, audited)}
        {--by= : The operator who releases it (required, audited)}
        {--edge-stopped : Required when the appliance holds authority: the Branch Server is stopped and will not sell again}
        {--dry-run : Show the lease and the verdict; write nothing}';

    protected $description = 'Cloud-only: release a dead appliance\'s branch authority lease (audited; a live lease is refused)';

    private const REFUSALS = [
        EdgeAuthorityLeaseService::RELEASE_ALREADY_RELEASED, EdgeAuthorityLeaseService::RELEASE_NEVER_HEARTBEATED,
        EdgeAuthorityLeaseService::RELEASE_LEASE_LIVE, EdgeAuthorityLeaseService::RELEASE_EDGE_HOLDS_AUTHORITY,
    ];

    public function handle(TenancyManager $tenancy, EdgeAuthorityLeaseService $leases): int
    {
        if (EdgeRuntime::isBranchServer()) {
            $this->error('Cloud only.');

            return self::FAILURE;
        }
        $reason = trim((string) $this->option('reason'));
        $by = trim((string) $this->option('by'));
        if ($reason === '' || $by === '') {
            $this->error('Both --reason and --by are required (audited).');

            return self::FAILURE;
        }
        // tenants has `tenant_code` (no `code` column): the old lookup threw "Unknown column 'code'" (found 23 Nov 2026).
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        $branchId = (int) $this->argument('branch');
        $edgeStopped = (bool) $this->option('edge-stopped');
        $tenancy->activate($tenant);
        try {
            $lease = EdgeBranchAuthorityLease::on('tenant')->where('branch_id', $branchId)->first();
            if (! $lease) {
                $this->warn('No lease exists for that branch — nothing to release.');

                return self::SUCCESS;
            }
            $age = $lease->last_heartbeat_at ? now()->getTimestamp() - $lease->last_heartbeat_at->getTimestamp() : null;
            $this->line(sprintf('Lease: tenant %s, branch %d, device %s, holder %s, state %s, seq %d, last heartbeat %s, expired %s, released %s',
                $tenant->tenant_code, $branchId, $lease->device_public_uuid, $lease->holder, $lease->edge_state, (int) $lease->heartbeat_seq,
                $age === null ? 'never' : $age . ' s ago', $lease->isExpired() ? 'yes' : 'no', $lease->isReleased() ? 'yes' : 'no'));

            $refusal = $leases->releaseRefusal($lease, $edgeStopped);
            if ($this->option('dry-run')) {
                $this->line($refusal === null ? 'DRY RUN: this lease WOULD be released. Nothing written.' : 'DRY RUN: REFUSED ' . $refusal . ' — ' . $this->explain($refusal) . ' Nothing written.');

                return $refusal === null ? self::SUCCESS : self::FAILURE;
            }
            // The service decides UNDER the row lock (a heartbeat landing after the look above wins) and logs the release or refusal.
            try {
                $view = $leases->release($branchId, $reason, $by, $edgeStopped);
            } catch (RuntimeException $e) {
                if (! in_array($e->getMessage(), self::REFUSALS, true)) {
                    throw $e;
                }
                $this->error('REFUSED ' . $e->getMessage() . ' — ' . $this->explain($e->getMessage()) . ' Nothing written.');

                return self::FAILURE;
            }
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

    private function explain(string $code): string
    {
        return match ($code) {
            EdgeAuthorityLeaseService::RELEASE_LEASE_LIVE => 'the appliance heartbeated within the lease TTL, so it is alive; a live lease is never released.',
            EdgeAuthorityLeaseService::RELEASE_EDGE_HOLDS_AUTHORITY => 'the Branch Server holds authority; release only after it is switched off and will not sell again (--edge-stopped).',
            EdgeAuthorityLeaseService::RELEASE_ALREADY_RELEASED => 'the lease is already released.',
            EdgeAuthorityLeaseService::RELEASE_NEVER_HEARTBEATED => 'no heartbeat was ever accepted, so the Cloud is not fenced.',
            default => 'refused.',
        };
    }
}
