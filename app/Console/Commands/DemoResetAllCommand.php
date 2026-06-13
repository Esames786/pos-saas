<?php

namespace App\Console\Commands;

use App\Services\Saas\DemoResetService;
use Illuminate\Console\Command;
use Throwable;

class DemoResetAllCommand extends Command
{
    protected $signature = 'demo:reset-all {--yes : Skip confirmation (required for non-interactive / production)}';

    protected $description = 'Drop & recreate all public demo tenants and re-seed their sample data (stops on first failure).';

    public function handle(DemoResetService $service): int
    {
        $yes   = (bool) $this->option('yes');
        $codes = (array) config('saas.demos.reset_tenant_codes', []);

        if (empty($codes)) {
            $this->error('No reset_tenant_codes configured under saas.demos — nothing to reset.');
            return self::FAILURE;
        }

        if ($this->getLaravel()->environment('production') && ! $yes) {
            $this->error('Refusing to reset in production without --yes.');
            return self::FAILURE;
        }

        if (! $yes) {
            $this->warn('This will DROP and recreate ALL public demo databases:');
            foreach ($codes as $code) {
                $this->line('  - ' . $service->databaseNameFor($code));
            }

            if (! $this->confirm('Continue?', false)) {
                $this->warn('Aborted — no changes made.');
                return self::FAILURE;
            }
        }

        $failed = false;

        foreach ($codes as $code) {
            $this->info("Resetting [{$code}] ...");

            try {
                $tenant = $service->resetTenantCode($code);
                $this->line('  ✓ ' . $code . ' → ' . ($tenant->subscription?->plan?->code ?? 'n/a'));
            } catch (Throwable $e) {
                $this->error('  ✗ ' . $code . ' FAILED: ' . $e->getMessage());
                $this->error('Stopping on first failure. Remaining demos were not reset.');
                $failed = true;
                break;
            }
        }

        if ($failed) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All public demos reset. Public login: demo@{code}.com / ' . config('saas.demos.default_password', 'demo1234'));

        return self::SUCCESS;
    }
}
