<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;

class ProvisionDemoTenant extends Command
{
    protected $signature = 'tenants:provision-demo';
    protected $description = 'Provision demo tenant database, domain, owner, branch, and permissions';

    public function handle(TenantProvisioner $provisioner): int
    {
        $tenant = $provisioner->provisionDemoTenant();

        $this->info("Demo tenant provisioned: {$tenant->business_name}");
        $this->info('Tenant URL: http://demo.' . config('tenancy.tenant_base_domain') . '/login');
        $this->info('Tenant Login: owner@demo.com / password');

        return self::SUCCESS;
    }
}
