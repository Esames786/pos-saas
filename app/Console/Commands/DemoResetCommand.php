<?php

namespace App\Console\Commands;

use App\Services\Saas\DemoResetService;
use Illuminate\Console\Command;
use Throwable;

class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset {tenant_code : One of the public demo tenant codes} {--yes : Skip confirmation (required for non-interactive / production)}';

    protected $description = 'Drop & recreate one public demo tenant and re-seed its sample data (safe-guarded).';

    public function handle(DemoResetService $service): int
    {
        $tenantCode = trim((string) $this->argument('tenant_code'));
        $yes        = (bool) $this->option('yes');

        // Validate that this is even a resettable demo tenant before touching anything.
        try {
            $service->assertResettable($tenantCode);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $dbName = $service->databaseNameFor($tenantCode);

        if ($this->getLaravel()->environment('production') && ! $yes) {
            $this->error('Refusing to reset in production without --yes.');
            return self::FAILURE;
        }

        if (! $yes) {
            $this->warn("This will DROP and recreate demo tenant database [{$dbName}].");
            $this->line('Only proceed if this is a public demo tenant.');

            // Non-interactive runs return the default (false) → safe refusal.
            if (! $this->confirm('Continue?', false)) {
                $this->warn('Aborted — no changes made.');
                return self::FAILURE;
            }
        }

        $this->info("Resetting demo tenant [{$tenantCode}] ({$dbName})...");

        try {
            $tenant = $service->resetTenantCode($tenantCode);
        } catch (Throwable $e) {
            $this->error('Reset failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $domain = $tenant->domains->firstWhere('is_primary', true)?->domain
            ?? $tenant->domains->first()?->domain;

        $this->newLine();
        $this->info("Demo tenant reset: {$tenant->business_name}");
        $this->line('  tenant_code : ' . $tenant->tenant_code);
        $this->line('  is_demo     : ' . (int) $tenant->is_demo);
        $this->line('  status      : ' . $tenant->status);
        $this->line('  plan        : ' . ($tenant->subscription?->plan?->code ?? 'n/a'));
        $this->line('  login URL   : http://' . $domain . '/login');
        $this->line('  PUBLIC demo : demo@' . $tenant->tenant_code . '.com / ' . config('saas.demos.default_password', 'demo1234'));

        return self::SUCCESS;
    }
}
