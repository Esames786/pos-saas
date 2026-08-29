<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Catering\CateringReminderService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * CATERING-SLICE-3: per-tenant catering reminder dispatch — the
 * DispatchScheduledReportsCommand archetype: iterate active tenants, activate
 * tenancy, guard on schema + module entitlement, run due reminders inline.
 */
class DispatchCateringRemindersCommand extends Command
{
    protected $signature = 'catering:dispatch-event-reminders';

    protected $description = 'Send due catering event reminders (D-7/D-3/D-1/same-day) for entitled tenants';

    public function handle(TenancyManager $tenancy): int
    {
        $failed = false;

        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            try {
                if (! $this->tenantHasCateringModule($tenant)) {
                    continue;
                }

                $tenancy->activate($tenant);

                if (! Schema::connection('tenant')->hasTable('catering_event_reminders')) {
                    continue;
                }

                $result = app(CateringReminderService::class)->dispatchDue();
                if ($result['sent'] > 0) {
                    $this->info("{$tenant->tenant_code}: {$result['sent']} reminder(s) sent, {$result['skipped']} skipped.");
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("{$tenant->tenant_code}: {$e->getMessage()}");
                $failed = true;
            } finally {
                $tenancy->deactivate();
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function tenantHasCateringModule(Tenant $tenant): bool
    {
        $plan = $tenant->subscription?->plan;

        return $plan
            ? $plan->enabledModules()->where('key', 'catering')->exists()
            : false;
    }
}
