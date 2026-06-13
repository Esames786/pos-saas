<?php

namespace App\Console\Commands;

use App\Services\Saas\DemoProvisioner;
use Illuminate\Console\Command;
use Throwable;

class DemoProvisionAllCommand extends Command
{
    protected $signature = 'demo:provision-all {--fresh : Recreate demo tenants that already exist}';

    protected $description = 'Provision all industry demo tenants (retail, inventory, restaurant, restaurant_pro, enterprise).';

    public function handle(DemoProvisioner $provisioner): int
    {
        $fresh = (bool) $this->option('fresh');
        $results = [];
        $hadFailure = false;

        foreach (DemoProvisioner::industries() as $industry) {
            try {
                $tenant = $provisioner->provision($industry, $fresh);
                $results[] = [$industry, $tenant->tenant_code, $tenant->subscription?->plan?->code ?? 'n/a', 'OK'];
            } catch (Throwable $e) {
                $hadFailure = true;
                $results[] = [$industry, '-', '-', 'FAIL: ' . $e->getMessage()];
            }
        }

        $this->table(['Industry', 'Tenant', 'Plan', 'Result'], $results);

        if ($hadFailure) {
            $this->error('One or more demo tenants failed to provision.');
            return self::FAILURE;
        }

        $this->info('All demo tenants provisioned. Public login: demo@{code}.com / ' . config('saas.demos.default_password', 'demo1234'));
        return self::SUCCESS;
    }
}
