<?php

namespace App\Console\Commands;

use App\Services\Saas\DemoProvisioner;
use Illuminate\Console\Command;
use Throwable;

class DemoProvisionCommand extends Command
{
    protected $signature = 'demo:provision {industry : retail|inventory|restaurant|restaurant_pro|enterprise} {--fresh : Recreate if the demo tenant already exists}';

    protected $description = 'Provision a public industry demo tenant (reuses TenantProvisioner).';

    public function handle(DemoProvisioner $provisioner): int
    {
        $industry = $this->argument('industry');

        if (! in_array($industry, DemoProvisioner::industries(), true)) {
            $this->error("Unsupported industry [{$industry}].");
            $this->line('Allowed: ' . implode(', ', DemoProvisioner::industries()));
            return self::FAILURE;
        }

        try {
            $tenant = $provisioner->provision($industry, (bool) $this->option('fresh'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $domain = $tenant->domains->firstWhere('is_primary', true)?->domain ?? $tenant->domains->first()?->domain;

        $this->info("Demo tenant provisioned: {$tenant->business_name}");
        $this->line('  tenant_code : ' . $tenant->tenant_code);
        $this->line('  is_demo     : ' . (int) $tenant->is_demo);
        $this->line('  status      : ' . $tenant->status);
        $this->line('  domain      : ' . $domain);
        $this->line('  plan        : ' . ($tenant->subscription?->plan?->code ?? 'n/a'));
        $this->line('  login URL   : http://' . $domain . '/login');
        $this->newLine();
        $this->line('  PUBLIC demo user : demo@' . $tenant->tenant_code . '.com / ' . config('saas.demos.default_password', 'demo1234'));
        $this->warn('  Owner credentials (owner@' . $tenant->tenant_code . '.com) are for INTERNAL maintenance only — do not expose publicly.');

        return self::SUCCESS;
    }
}
