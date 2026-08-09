<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Reports\ReportScheduleService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALES REPORT CENTER (spec AA/AB) — the Cloud scheduler entrypoint for owner report emails.
 * Iterates ACTIVE tenants (TenancyManager::activate — the deploy-loop pattern), runs each tenant's
 * due schedules inline (no queue: tenant context stays trivially correct; mail is log/SMTP). Safe to
 * run every few minutes: period idempotency (unique schedule+period run row) makes retries no-ops.
 * NOT registered for Edge — the appliance CLI boundary default-denies it and the scheduler block is
 * Cloud-only.
 */
class DispatchScheduledReportsCommand extends Command
{
    protected $signature = 'reports:dispatch-scheduled {--tenant= : Only this tenant_code}';

    protected $description = 'Send due scheduled sales reports for every active tenant (idempotent).';

    public function handle(TenancyManager $tenancy, ReportScheduleService $schedules): int
    {
        $tenants = Tenant::where('status', 'active')
            ->when($this->option('tenant'), fn ($q) => $q->where('tenant_code', $this->option('tenant')))
            ->get();

        $totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($tenants as $tenant) {
            try {
                $tenancy->activate($tenant);
            } catch (\Throwable $e) {
                $this->warn("[{$tenant->tenant_code}] activate failed: {$e->getMessage()}");

                continue;
            }
            if (! Schema::connection('tenant')->hasTable('report_schedules')) {
                continue;
            }
            $nowTz = now($schedules->timezone());
            foreach (DB::connection('tenant')->table('report_schedules')->where('is_active', true)->get() as $schedule) {
                $result = $schedules->runDue($schedule, $nowTz);
                if ($result === 'sent') {
                    $totals['sent']++;
                    $this->info("[{$tenant->tenant_code}] schedule #{$schedule->id} sent.");
                } elseif ($result === 'failed') {
                    $totals['failed']++;
                    $this->warn("[{$tenant->tenant_code}] schedule #{$schedule->id} FAILED (will retry next tick).");
                } else {
                    $totals['skipped']++;
                }
            }
        }
        $this->info("done: {$totals['sent']} sent, {$totals['failed']} failed, {$totals['skipped']} skipped.");

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
