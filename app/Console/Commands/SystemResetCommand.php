<?php

namespace App\Console\Commands;

use App\Services\Saas\DemoProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-shot full system rebuild. Drops every tenant database, resets the master
 * schema, re-seeds master data, syncs routes/permissions, then re-provisions and
 * seeds the legacy demo + all industry demos (retail, inventory, restaurant,
 * restaurant_pro, enterprise, finance). Optionally takes a full backup first.
 *
 * DESTRUCTIVE: wipes ALL data (master + every tenant). Guarded by a confirmation
 * prompt unless --force is passed.
 *
 *   php artisan system:reset --backup        # back up, then reset (asks to confirm)
 *   php artisan system:reset                 # reset without backup (asks to confirm)
 *   php artisan system:reset --backup --force  # fully non-interactive (cron/CI)
 */
class SystemResetCommand extends Command
{
    protected $signature = 'system:reset
        {--backup : Take a full backup (tenants:backup) before resetting}
        {--skip-backup : Never back up, even when interactive}
        {--no-demos : Skip provisioning/seeding demo tenants}
        {--force : Skip the destructive-confirmation and backup prompts}';

    protected $description = 'Full reset: drop tenants, migrate:fresh, seed master, sync routes, re-provision + seed all demos.';

    public function handle(): int
    {
        $start = microtime(true);

        // ---- 1. Confirm (destructive) -----------------------------------
        if (! $this->option('force')) {
            $this->warn('');
            $this->warn('  ╔════════════════════════════════════════════════════════════╗');
            $this->warn('  ║  ⚠  FULL SYSTEM RESET — THIS DESTROYS ALL DATA              ║');
            $this->warn('  ║  Master DB + EVERY tenant database will be dropped.         ║');
            $this->warn('  ║  Any real signups/trials are permanently lost.             ║');
            $this->warn('  ╚════════════════════════════════════════════════════════════╝');
            $this->warn('');

            if (! $this->confirm('Type yes to wipe and rebuild everything. Continue?', false)) {
                $this->info('Aborted. Nothing was changed.');
                return self::SUCCESS;
            }
        }

        // ---- 2. Backup (conditional) ------------------------------------
        $wantsBackup = $this->resolveBackupChoice();
        if ($wantsBackup) {
            $this->step('Backing up (master + tenants + storage)…');
            if ($this->call('tenants:backup') !== self::SUCCESS) {
                $this->error('Backup FAILED — aborting reset so nothing is lost.');
                return self::FAILURE;
            }
        } else {
            $this->line('  (skipping backup)');
        }

        // ---- 3. Drop all tenant databases -------------------------------
        $this->step('Dropping all tenant databases (pos_tenant_*)…');
        $dropped = $this->dropTenantDatabases();
        $this->line("  dropped {$dropped} tenant database(s)");

        // ---- 4. Reset master schema -------------------------------------
        $this->step('Resetting master schema (migrate:fresh)…');
        if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
            $this->error('migrate:fresh failed — aborting.');
            return self::FAILURE;
        }

        // ---- 5. Seed master data ----------------------------------------
        $this->step('Seeding master data (plans, modules, superadmin)…');
        if ($this->call('db:seed', ['--class' => 'Database\\Seeders\\MasterSeeder', '--force' => true]) !== self::SUCCESS) {
            $this->error('MasterSeeder failed — aborting.');
            return self::FAILURE;
        }

        // ---- 6. Sync routes + permissions -------------------------------
        $this->step('Syncing routes + resetting permission cache…');
        $this->call('system:routes-sync');
        $this->call('permission:cache-reset');

        // ---- 7. Provision + seed demos ----------------------------------
        $failures = [];
        if (! $this->option('no-demos')) {
            $this->step('Provisioning legacy demo tenant…');
            if ($this->call('tenants:provision-demo') === self::SUCCESS) {
                $this->call('db:seed', ['--class' => 'Database\\Seeders\\TenantDemoSeeder', '--force' => true]);
            } else {
                $failures[] = 'legacy demo';
            }

            $this->step('Provisioning industry demos (this takes a few minutes — do not interrupt)…');
            $this->call('demo:provision-all', ['--fresh' => true]);

            foreach (DemoProvisioner::industries() as $industry) {
                $this->line("  seeding demo: {$industry}");
                if ($this->call('demo:seed', ['industry' => $industry]) !== self::SUCCESS) {
                    $failures[] = "demo:seed {$industry}";
                }
            }
        } else {
            $this->line('  (skipping demos)');
        }

        // ---- 8. Rebuild caches ------------------------------------------
        $this->step('Rebuilding caches…');
        $this->call('optimize:clear');
        $this->call('config:cache');
        $this->call('route:cache');
        $this->call('view:cache');

        // ---- 9. Summary -------------------------------------------------
        $secs = round(microtime(true) - $start);
        $this->newLine();
        if ($failures) {
            $this->warn('Reset finished WITH ISSUES in ' . $secs . 's. Failed steps:');
            foreach ($failures as $f) {
                $this->warn('  - ' . $f);
            }
            return self::FAILURE;
        }

        $this->info('✅ System reset complete in ' . $secs . 's.');
        $this->line('   Demo logins: demo@{code}.com / ' . config('saas.demos.default_password', 'demo1234'));
        $this->line('   Codes: retaildemo, inventorydemo, restaurantdemo, restaurantprodemo, enterprisedemo, financedemo');
        $this->line('   Legacy demo: owner@demo.com');
        return self::SUCCESS;
    }

    /** Decide whether to back up: flags win; otherwise ask when interactive. */
    private function resolveBackupChoice(): bool
    {
        if ($this->option('skip-backup')) {
            return false;
        }
        if ($this->option('backup')) {
            return true;
        }
        if ($this->option('force')) {
            return false; // non-interactive default: no backup unless --backup given
        }

        return $this->confirm('Take a full backup before resetting?', true);
    }

    /** Drop every pos_tenant_* database via the master connection. */
    private function dropTenantDatabases(): int
    {
        $rows = DB::connection('master')->select(
            "SELECT schema_name AS name FROM information_schema.schemata WHERE schema_name LIKE 'pos\\_tenant\\_%'"
        );

        $masterDb = config('database.connections.master.database');
        $count = 0;

        foreach ($rows as $row) {
            $name = $row->name;
            // Hard safety: only ever drop names that match the tenant pattern, never master.
            if ($name === $masterDb || ! str_starts_with($name, 'pos_tenant_')) {
                continue;
            }
            DB::connection('master')->statement("DROP DATABASE IF EXISTS `{$name}`");
            $count++;
        }

        return $count;
    }

    private function step(string $message): void
    {
        $this->newLine();
        $this->info('▶ ' . $message);
    }
}
