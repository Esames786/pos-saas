<?php

namespace Tests\Unit\Tenant;

use App\Models\Master\Tenant;
use App\Models\Master\TenantDatabase;
use App\Services\Tenancy\TenancyManager;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Activating a tenant must drop the PREVIOUS tenant's in-process permission collection.
 *
 * The Spatie registrar memoizes permissions per PHP process. Any process that activates several
 * tenants in sequence (demo:reset-all, system loops, future queue workers) kept consulting the
 * first tenant's collection after switching — findByName() threw "There is no permission named
 * tenant.users.index" for rows that plainly existed in the active DB, and the nightly demo reset
 * died on its second tenant every night, leaving the later demos stale.
 */
class TenantActivationPermissionCacheTest extends TestCase
{
    public function test_activate_clears_the_in_process_permission_collection(): void
    {
        $registrar = app(PermissionRegistrar::class);

        // Simulate a previous tenant's memoized collection.
        $prop = new \ReflectionProperty(PermissionRegistrar::class, 'permissions');
        $prop->setValue($registrar, collect([(object) ['name' => 'stale.permission']]));
        $this->assertNotNull($prop->getValue($registrar), 'precondition: collection memoized');

        // An in-memory tenant is enough — activate() only reconfigures config/container state.
        $tenant = new Tenant(['tenant_code' => 'unit', 'status' => 'active']);
        $tenant->setRelation('database', new TenantDatabase([
            'db_host' => '127.0.0.1', 'db_port' => 3306,
            'db_database' => 'unit_test_tenant', 'db_username' => 'unit', 'db_password' => '',
        ]));

        app(TenancyManager::class)->activate($tenant);

        $this->assertNull(
            $prop->getValue($registrar),
            'activate() must clear the memoized permission collection, or a multi-tenant process '
            . 'resolves the previous tenant\'s permissions after the switch.'
        );
    }
}
