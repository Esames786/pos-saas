<?php

namespace App\Console\Commands;

use App\Models\Master\EdgeRelease;
use App\Services\Edge\Cloud\ReleaseRegistry;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * EDGE 0.9 (K6 plan approval) — record the OWNER-APPROVED tenant-migration plan hash for appliances upgrading from one
 * installed version to a published release. Cloud operator only; audited ([edge-release-audit] edge.release.plan_approved).
 *
 *   php artisan edge:release:approve-plan <release id | version> <from_version> sha256:<64 hex> [--channel=stable] [--json]
 *
 * The hash is the plan_hash the appliance's own dry run reported for that transition (edge:local:schema-upgrade --dry-run on a
 * copy of the appliance). The Cloud never computes or approves a plan on a device's behalf; GET /api/edge/updates/latest
 * advertises the map as plan_hashes and, for the asking device's installed version, expected_plan_hash.
 */
class EdgeReleaseApprovePlanCommand extends Command
{
    protected $signature = 'edge:release:approve-plan
        {release : the release id (uuid) or its version}
        {from_version : the installed Edge version this approval is for}
        {plan_hash : sha256:<64 hex> as reported by the appliance dry run}
        {--channel=stable : the channel when the release is given by version}
        {--json : emit JSON}';

    protected $description = 'Approve the migration plan hash for one upgrade path of a published Branch Server release (audited).';

    public function handle(ReleaseRegistry $registry): int
    {
        if (EdgeRuntime::isBranchServer()) {
            $this->error('edge:release:approve-plan is a Cloud command — it never runs on a Branch Server.');

            return self::FAILURE;
        }
        $ref = trim((string) $this->argument('release'));
        $release = EdgeRelease::where('public_uuid', $ref)->first()
            ?? EdgeRelease::where('channel', (string) ($this->option('channel') ?: EdgeRelease::CHANNEL_STABLE))->where('version', $ref)->first();
        if (! $release) {
            return $this->fail2('RELEASE_NOT_FOUND: ' . $ref);
        }
        try {
            $release = $registry->approvePlan($release, (string) $this->argument('from_version'), (string) $this->argument('plan_hash'),
                'cli:' . (get_current_user() ?: 'unknown'));
        } catch (RuntimeException $e) {
            return $this->fail2($e->getMessage());
        }
        $out = ['ok' => true, 'release_id' => $release->public_uuid, 'version' => $release->version, 'approved_plan_hashes' => $release->approved_plan_hashes];
        if ($this->option('json')) {
            $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("APPROVED — {$release->version}: plan for upgrades from " . $this->argument('from_version'));
            foreach ($release->approved_plan_hashes as $from => $hash) {
                $this->line(str_pad((string) $from, 16) . ' ' . $hash);
            }
        }

        return self::SUCCESS;
    }

    private function fail2(string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES));
        } else {
            $this->error('REFUSED — ' . $message);
        }

        return self::FAILURE;
    }
}
