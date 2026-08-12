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

            // ONE FRESH PROCESS PER TENANT. Resetting several tenants inside one PHP process
            // resolves the previous tenant's leftovers through every memoized layer at once: the
            // Spatie registrar's in-process collection (cleared at activate() since the /login
            // fix) AND the database cache store, whose repository pins the Connection OBJECT it
            // was constructed with — after the tenant switch it still reads the previous
            // tenant's cache table. That is why the nightly reset died on tenant #2 every night
            // ("There is no permission named tenant.users.index") while a single-tenant reset of
            // the same demo succeeded. A subprocess starts pristine; no stale layer survives.
            try {
                $exit = 0;
                passthru(sprintf(
                    '%s %s demo:reset %s --yes --no-interaction 2>&1',
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg(base_path('artisan')),
                    escapeshellarg($code)
                ), $exit);

                if ($exit !== 0) {
                    throw new \RuntimeException("demo:reset {$code} exited with code {$exit}");
                }
                $this->line('  ✓ ' . $code);
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
