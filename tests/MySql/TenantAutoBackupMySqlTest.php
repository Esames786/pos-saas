<?php

namespace Tests\MySql;

use App\Http\Controllers\Central\TenantController;
use App\Models\Master\Tenant;
use App\Models\Master\TenantBackup;
use App\Models\Master\TenantBackupRun;
use App\Models\Master\TenantBackupSetting;
use App\Services\Central\TenantBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * TENANT-AUTO-BACKUP-1 — the scheduled per-tenant backup dispatcher + retention, against real MySQL.
 *
 * The actual mysqldump is replaced by a fake service (records a completed TenantBackup + a fake file)
 * so we test the DISPATCHER: tz-correct slot matching, exactly-once idempotency, disabled/out-of-window
 * skips, and scheduled-only retention pruning. mysqldump itself is already exercised elsewhere.
 */
class TenantAutoBackupMySqlTest extends MySqlTenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cleanMaster();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cleanMaster(): void
    {
        $m = DB::connection('master');
        $m->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['tenant_backup_runs', 'tenant_backup_settings', 'tenant_backups', 'tenants'] as $t) {
            $m->table($t)->delete();
        }
        $m->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function makeTenant(string $code): Tenant
    {
        return Tenant::create(['tenant_code' => $code, 'business_name' => "Biz {$code}", 'status' => 'active']);
    }

    private function setSchedule(Tenant $tenant, array $times, bool $enabled = true, int $retention = 7, string $tz = 'Asia/Karachi'): void
    {
        TenantBackupSetting::updateOrCreate(
            ['tenant_id' => $tenant->id],
            ['is_enabled' => $enabled, 'times' => $times, 'timezone' => $tz, 'retention_days' => $retention]
        );
    }

    /** Swap in a fake backup service so no real mysqldump runs; it records a completed row + fake file. */
    private function bindFakeBackupService(): void
    {
        $fake = new class extends TenantBackupService {
            public function backup(Tenant $tenant, string $type = 'manual', ?int $userId = null, ?string $notes = null): TenantBackup
            {
                $ts   = Carbon::now()->format('Ymd_His');
                $file = "{$ts}_{$tenant->tenant_code}.sql";
                $rel  = "backups/tenants/{$tenant->tenant_code}/{$file}";
                Storage::disk('local')->put($rel, "-- fake dump for {$tenant->tenant_code}\n");

                return TenantBackup::create([
                    'tenant_id' => $tenant->id, 'tenant_code' => $tenant->tenant_code, 'database_name' => 'db_' . $tenant->tenant_code,
                    'disk' => 'local', 'path' => $rel, 'file_name' => $file, 'file_size' => 20,
                    'backup_type' => $type, 'status' => 'completed', 'created_by' => $userId, 'notes' => $notes,
                ]);
            }
        };

        $this->app->instance(TenantBackupService::class, $fake);
    }

    private function scheduledCount(Tenant $tenant): int
    {
        return TenantBackup::where('tenant_id', $tenant->id)->where('backup_type', 'scheduled')->count();
    }

    /* ── 1. due slot fires once, then is idempotent ─────────────────────────────────────────────── */
    public function test_due_slot_creates_one_scheduled_backup_and_is_idempotent(): void
    {
        $tenant = $this->makeTenant('autobk1');
        $this->setSchedule($tenant, ['14:30']);
        $this->bindFakeBackupService();

        // 09:30 UTC == 14:30 Asia/Karachi (UTC+5).
        Carbon::setTestNow(Carbon::create(2026, 8, 27, 9, 30, 0, 'UTC'));

        Artisan::call('tenants:auto-backup');
        $this->assertSame(1, $this->scheduledCount($tenant), 'a due slot creates exactly one scheduled backup');
        $run = TenantBackupRun::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($run);
        $this->assertSame('done', $run->status);
        $this->assertSame('14:30', $run->slot_time);

        // Same window again → idempotent no-op (the unique claim already exists).
        Artisan::call('tenants:auto-backup');
        $this->assertSame(1, $this->scheduledCount($tenant), 'running again in the same window must NOT double-backup');
        $this->assertSame(1, TenantBackupRun::where('tenant_id', $tenant->id)->count());
    }

    /* ── 2. out-of-window + disabled are skipped ────────────────────────────────────────────────── */
    public function test_out_of_window_and_disabled_tenants_are_skipped(): void
    {
        $this->bindFakeBackupService();

        $onTime = $this->makeTenant('autobk2');
        $this->setSchedule($onTime, ['14:30']);           // enabled, but clock is far from the slot
        $disabled = $this->makeTenant('autobk3');
        $this->setSchedule($disabled, ['12:00'], enabled: false);

        // 07:00 UTC == 12:00 PKT — well outside autobk2's 14:30 window; autobk3 is off entirely.
        Carbon::setTestNow(Carbon::create(2026, 8, 27, 7, 0, 0, 'UTC'));
        Artisan::call('tenants:auto-backup');

        $this->assertSame(0, $this->scheduledCount($onTime), 'a slot that is not due must not fire');
        $this->assertSame(0, $this->scheduledCount($disabled), 'a disabled tenant is never backed up');
    }

    /* ── 3. slots are matched in the tenant timezone, not the server's ──────────────────────────── */
    public function test_slot_time_is_matched_in_the_tenant_timezone(): void
    {
        $tenant = $this->makeTenant('autobk4');
        $this->setSchedule($tenant, ['02:30']);           // 2:30 AM Pakistan time
        $this->bindFakeBackupService();

        // 21:30 UTC on the 26th == 02:30 PKT on the 27th — the slot_date must be the PKT calendar day.
        Carbon::setTestNow(Carbon::create(2026, 8, 26, 21, 30, 0, 'UTC'));
        Artisan::call('tenants:auto-backup');

        $run = TenantBackupRun::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($run, 'the 02:30 PKT slot fires even though the server clock is on the previous UTC day');
        $this->assertSame('2026-08-27', $run->slot_date->toDateString(), 'slot_date is the tenant-local date');
        $this->assertSame(1, $this->scheduledCount($tenant));
    }

    /* ── 4. retention prunes ONLY old scheduled backups ─────────────────────────────────────────── */
    public function test_retention_prunes_only_old_scheduled_backups(): void
    {
        $tenant = $this->makeTenant('autobk5');
        $service = app(TenantBackupService::class); // real service — pruneScheduled runs no mysqldump

        $oldScheduled    = $this->seedBackup($tenant, 'scheduled', 8);  // older than 7d → prune
        $recentScheduled = $this->seedBackup($tenant, 'scheduled', 6);  // within 7d   → keep
        $oldManual       = $this->seedBackup($tenant, 'manual', 30);    // human copy  → keep

        $pruned = $service->pruneScheduled($tenant, 7);

        $this->assertSame(1, $pruned, 'exactly one (the 8-day-old scheduled) is pruned');
        $this->assertNull(TenantBackup::find($oldScheduled->id), 'old scheduled row removed');
        $this->assertFalse(Storage::disk('local')->exists($oldScheduled->path), 'old scheduled FILE removed');
        $this->assertNotNull(TenantBackup::find($recentScheduled->id), 'recent scheduled kept');
        $this->assertNotNull(TenantBackup::find($oldManual->id), 'a manual backup is NEVER auto-pruned');
        $this->assertTrue(Storage::disk('local')->exists($oldManual->path));
    }

    private function seedBackup(Tenant $tenant, string $type, int $ageDays): TenantBackup
    {
        $file = "{$type}_{$ageDays}d_{$tenant->tenant_code}.sql";
        $rel  = "backups/tenants/{$tenant->tenant_code}/{$file}";
        Storage::disk('local')->put($rel, 'x');

        $b = TenantBackup::create([
            'tenant_id' => $tenant->id, 'tenant_code' => $tenant->tenant_code, 'database_name' => 'db',
            'disk' => 'local', 'path' => $rel, 'file_name' => $file, 'file_size' => 1,
            'backup_type' => $type, 'status' => 'completed',
        ]);
        DB::connection('master')->table('tenant_backups')->where('id', $b->id)
            ->update(['created_at' => Carbon::now()->subDays($ageDays)]);

        return $b->refresh();
    }

    /* ── 5. settings save: normalises + validates ───────────────────────────────────────────────── */
    public function test_settings_save_normalises_and_validates(): void
    {
        $this->startSession(); // the controller returns redirect()->with()/withErrors(), which need a session
        $tenant = $this->makeTenant('autobk6');

        // Happy path: out-of-order + duplicate times → de-duped, sorted, persisted.
        $req = Request::create('/x', 'POST', [
            'is_enabled' => '1', 'times' => ['19:30', '14:30', '14:30'], 'retention_days' => '7',
        ]);
        app(TenantController::class)->saveBackupSettings($req, $tenant);

        $setting = TenantBackupSetting::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertTrue($setting->is_enabled);
        $this->assertSame(['14:30', '19:30'], $setting->times, 'times de-duped + sorted');
        $this->assertSame(7, $setting->retention_days);

        // More than 3 times → validation failure.
        $bad = Request::create('/x', 'POST', [
            'is_enabled' => '1', 'times' => ['01:00', '02:00', '03:00', '04:00'], 'retention_days' => '7',
        ]);
        try {
            app(TenantController::class)->saveBackupSettings($bad, $tenant);
            $this->fail('more than 3 times must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('times', $e->errors());
        }

        // Enabled with no valid times → refused (returns back with an error, setting stays disabled-safe).
        $empty = Request::create('/x', 'POST', ['is_enabled' => '1', 'times' => ['', ''], 'retention_days' => '7']);
        app(TenantController::class)->saveBackupSettings($empty, $tenant);
        $this->assertTrue(
            TenantBackupSetting::where('tenant_id', $tenant->id)->first()->is_enabled,
            'the earlier valid save is untouched by the refused empty-times save'
        );
    }
}
