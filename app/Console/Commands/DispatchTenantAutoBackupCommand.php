<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Master\TenantBackupRun;
use App\Models\Master\TenantBackupSetting;
use App\Services\Central\TenantBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TENANT-AUTO-BACKUP-1 — fire each tenant's scheduled DB backups.
 *
 * Runs every few minutes from the Cloud scheduler. For every tenant with auto-backup ON, it checks
 * each configured "HH:MM" slot against the current time IN THE TENANT'S TIMEZONE (default Pakistan)
 * and, when a slot is due, snapshots the tenant DB once — the unique (tenant, slot_date, slot_time)
 * claim row guarantees exactly one backup per slot per day even across overlapping runs (the same
 * idempotency shape as scheduled report periods). After each backup it prunes that tenant's
 * SCHEDULED backups beyond its retention window.
 *
 * Read-only with respect to tenant data — it only creates SQL snapshots + master bookkeeping. On a
 * Branch Server the console boundary default-denies it; it is Cloud-only.
 */
class DispatchTenantAutoBackupCommand extends Command
{
    protected $signature = 'tenants:auto-backup
        {--tenant= : Only this tenant (code or id)}
        {--force : Ignore the time window — back up every enabled tenant now (still once per slot/day)}';

    protected $description = 'Create each tenant\'s scheduled DB backups when a configured time slot is due, and prune to retention.';

    public function handle(TenantBackupService $service): int
    {
        $grace = (int) config('backup.auto_grace_minutes', 15);
        $force = (bool) $this->option('force');

        $settings = TenantBackupSetting::where('is_enabled', true)->get()->keyBy('tenant_id');
        if ($settings->isEmpty()) {
            $this->info('No tenants have auto-backup enabled.');
            return self::SUCCESS;
        }

        $tenants = Tenant::with('database')
            ->whereIn('id', $settings->keys())
            ->when($this->option('tenant'), function ($q) {
                $code = $this->option('tenant');
                $q->where('tenant_code', $code)->orWhere('id', is_numeric($code) ? (int) $code : 0);
            })
            ->get();

        $made = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            /** @var TenantBackupSetting $setting */
            $setting = $settings[$tenant->id];
            $tz      = $setting->timezone ?: 'Asia/Karachi';

            try {
                $nowTz = Carbon::now($tz);
            } catch (Throwable $e) {
                $this->warn("Tenant [{$tenant->tenant_code}] has an invalid timezone [{$tz}] — skipped.");
                continue;
            }

            $slotDate = $nowTz->toDateString();

            foreach ($setting->slotList() as $slot) {
                $slotMoment = Carbon::createFromFormat('Y-m-d H:i', $slotDate . ' ' . $slot, $tz);

                // Due if we are inside [slot, slot + grace). With an every-few-minutes cadence this
                // catches the slot even if one run is skipped, while the unique claim prevents doubles.
                $due = $force || ($nowTz->gte($slotMoment) && $nowTz->lt($slotMoment->copy()->addMinutes($grace)));
                if (! $due) {
                    continue;
                }

                // Idempotent claim: only the run that CREATES the row proceeds; a duplicate is a no-op.
                $claim = TenantBackupRun::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'slot_date' => $slotDate, 'slot_time' => $slot],
                    ['status' => 'claimed']
                );
                if (! $claim->wasRecentlyCreated) {
                    continue;
                }

                try {
                    $backup = $service->backup($tenant, 'scheduled', null, "Auto backup {$slotDate} {$slot} {$tz}");
                    $claim->update(['status' => 'done', 'tenant_backup_id' => $backup->id]);
                    $pruned = $service->pruneScheduled($tenant, (int) $setting->retention_days);
                    $made++;
                    $this->info("Backed up {$tenant->tenant_code} @ {$slot} {$tz} ({$backup->humanSize()}); pruned {$pruned} old scheduled backup(s).");
                    Log::info('tenant_auto_backup.done', [
                        'tenant' => $tenant->tenant_code, 'slot' => $slot, 'tz' => $tz, 'pruned' => $pruned,
                    ]);
                } catch (Throwable $e) {
                    $claim->update(['status' => 'failed', 'error' => $e->getMessage()]);
                    $failed++;
                    $this->error("Backup FAILED for {$tenant->tenant_code} @ {$slot}: {$e->getMessage()}");
                    Log::error('tenant_auto_backup.failed', [
                        'tenant' => $tenant->tenant_code, 'slot' => $slot, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Auto-backup pass complete: {$made} created, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
