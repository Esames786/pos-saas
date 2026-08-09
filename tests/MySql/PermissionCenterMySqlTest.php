<?php

namespace Tests\MySql;

use App\Services\Permissions\PermissionCatalogService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\MySql\Support\TenantFixtures;

/**
 * PERMISSION CENTER safety tests (spec section M, A–F): the friendly grouping layer can never weaken
 * the granular backend model — sensitive actions never fold into CRUD, non-entitled modules are
 * neither shown nor wiped, shared lookups stay explicit, and existing grants survive the editor.
 */
class PermissionCenterMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'users', 'branches']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        // the catalog resolves modules from the MASTER modules table — seed the minimal real set.
        DB::connection('master')->table('modules')->delete();
        foreach ([
            ['pos', ['tenant.pos', 'tenant.sales-orders', 'tenant.sales-returns', 'tenant.held-sales'], 10],
            ['catalog', ['tenant.products', 'tenant.categories'], 20],
            ['finance', ['tenant.finance'], 30],
            ['multi_branch', ['tenant.branches', 'tenant.terminals', 'tenant.shifts'], 40],
            ['manufacturing', ['tenant.manufacturing'], 50],
        ] as [$key, $routeKeys, $sort]) {
            DB::connection('master')->table('modules')->insert([
                'key' => $key, 'name' => \Illuminate\Support\Str::title(str_replace('_', ' ', $key)),
                'category' => 'Test', 'route_module_keys' => json_encode($routeKeys),
                'sort_order' => $sort, 'is_core' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ([
            // sales-ish CRUD + sensitive
            'tenant.sales-orders.index', 'tenant.sales-orders.show', 'tenant.sales-orders.store',
            'tenant.sales-orders.update', 'tenant.sales-orders.destroy', 'tenant.sales-orders.cancel',
            'tenant.sales-returns.index', 'tenant.sales-returns.create', 'tenant.sales-returns.store',
            'tenant.pos.void-kot-item',
            // finance sensitive
            'tenant.finance.expenses.index', 'tenant.finance.expenses.update', 'tenant.finance.expenses.post',
            'tenant.finance.manual-journals.reverse',
            // shifts
            'tenant.shifts.index', 'tenant.shifts.close',
            // shared lookup
            'tenant.ajax.products',
            // manufacturing (non-entitled scenario)
            'tenant.manufacturing.bom.index', 'tenant.manufacturing.bom.update',
        ] as $name) {
            Permission::findOrCreate($name, 'tenant');
        }
    }

    private function catalog(?array $entitled): array
    {
        return app(PermissionCatalogService::class)->build(Permission::where('guard_name', 'tenant')->get(), $entitled);
    }

    /** Flatten a feature's CRUD bucket names for assertions. */
    private function bucketNames(array $built, string $moduleKey, string $bucket): array
    {
        $names = [];
        foreach ($built['modules'] as $module) {
            if ($module['key'] !== $moduleKey) {
                continue;
            }
            foreach ($module['features'] as $feature) {
                foreach ($feature['groups'][$bucket] ?? [] as $child) {
                    $names[] = $child['name'];
                }
            }
        }

        return $names;
    }

    // A + C — CRUD grouping is correct AND sensitive actions never land in CRUD buckets.
    public function test_crud_buckets_group_correctly_and_sensitive_actions_stay_separate(): void
    {
        $built = $this->catalog(null);

        $view = $this->bucketNames($built, 'pos', 'view');
        $this->assertContains('tenant.sales-orders.index', $view);
        $this->assertContains('tenant.sales-orders.show', $view);
        $edit = $this->bucketNames($built, 'pos', 'edit');
        $this->assertContains('tenant.sales-orders.update', $edit);

        // C: across EVERY module, no sensitive suffix may appear in any CRUD bucket.
        $sensitive = ['cancel', 'void-kot-item', 'post', 'reverse', 'close', 'approve', 'refund', 'payout'];
        foreach ($built['modules'] as $module) {
            foreach ($module['features'] as $feature) {
                foreach ($feature['groups'] ?? [] as $bucket => $children) {
                    foreach ($children as $child) {
                        $last = \Illuminate\Support\Str::afterLast($child['name'], '.');
                        $this->assertNotContains($last, $sensitive,
                            "sensitive [{$child['name']}] leaked into CRUD bucket [$bucket]");
                    }
                }
            }
        }
        // and the sensitive ones ARE present as named actions.
        $allActions = collect($built['modules'])->flatMap(fn ($m) => collect($m['features'])->flatMap(fn ($f) => collect($f['actions'] ?? [])->pluck('name')));
        foreach (['tenant.sales-orders.cancel', 'tenant.finance.expenses.post', 'tenant.finance.manual-journals.reverse', 'tenant.shifts.close', 'tenant.pos.void-kot-item'] as $mustBeAction) {
            $this->assertContains($mustBeAction, $allActions->all(), "$mustBeAction must be a separate named action");
        }
    }

    // B — persistence round-trip: parent-group grant then one child removed = exactly that grant gone.
    public function test_group_grant_and_single_child_removal_persist_exactly(): void
    {
        $role = Role::findOrCreate('Cashier', 'tenant');
        $role->syncPermissions(['tenant.sales-orders.index', 'tenant.sales-orders.show']);
        $this->assertTrue($role->fresh()->hasPermissionTo('tenant.sales-orders.show'));

        // "uncheck one expanded child" = resubmit without it.
        $role->syncPermissions(['tenant.sales-orders.index']);
        $fresh = $role->fresh();
        $this->assertTrue($fresh->hasPermissionTo('tenant.sales-orders.index'));
        $this->assertFalse($fresh->hasPermissionTo('tenant.sales-orders.show'), 'exactly the unchecked granular permission is removed');
    }

    // C (enforcement side) — granting the whole Edit bucket never grants refund/void/cancel/post.
    public function test_edit_bucket_never_implies_sensitive_grants(): void
    {
        $built = $this->catalog(null);
        $editNames = array_merge(
            $this->bucketNames($built, 'pos', 'edit'),
            $this->bucketNames($built, 'finance', 'edit'),
        );
        $role = Role::findOrCreate('Editor', 'tenant');
        $role->syncPermissions($editNames);
        $fresh = $role->fresh();
        foreach (['tenant.sales-orders.cancel', 'tenant.sales-returns.store', 'tenant.pos.void-kot-item', 'tenant.finance.expenses.post', 'tenant.finance.manual-journals.reverse', 'tenant.shifts.close'] as $sensitive) {
            $this->assertFalse($fresh->hasPermissionTo($sensitive), "Edit must never grant $sensitive");
        }
    }

    // D + F — non-entitled modules are unmanaged: hidden, non-grantable, and existing grants SURVIVE.
    public function test_non_entitled_modules_are_hidden_preserved_and_not_grantable(): void
    {
        $entitled = ['pos', 'finance', 'multi_branch', 'catalog']; // NO manufacturing
        $built = $this->catalog($entitled);

        $this->assertNotContains('tenant.manufacturing.bom.update', $built['managed'], 'non-entitled permission is unmanaged');
        $this->assertContains('manufacturing', $built['unavailable_modules']);

        // a role that ALREADY has a manufacturing grant survives an editor round-trip
        // (updatePermissions semantics: preserved = existing − managed; submitted ∩ managed).
        $role = Role::findOrCreate('LegacyManager', 'tenant');
        $role->syncPermissions(['tenant.manufacturing.bom.update', 'tenant.sales-orders.index']);
        $managed = $built['managed'];
        $submitted = array_values(array_intersect(['tenant.sales-orders.index', 'tenant.manufacturing.bom.index'], $managed)); // crafted POST tries to add manufacturing
        $preserved = $role->permissions->pluck('name')->reject(fn ($n) => in_array($n, $managed, true))->values()->all();
        $role->syncPermissions(array_unique(array_merge($preserved, $submitted)));

        $fresh = $role->fresh();
        $this->assertTrue($fresh->hasPermissionTo('tenant.manufacturing.bom.update'), 'existing non-entitled grant survives (F)');
        $this->assertFalse($fresh->hasPermissionTo('tenant.manufacturing.bom.index'), 'crafted POST cannot grant a non-entitled permission (D)');
        $this->assertTrue($fresh->hasPermissionTo('tenant.sales-orders.index'));
    }

    // E — the shared lookup is explicit, single-placed and flagged.
    public function test_shared_lookup_is_explicit_and_flagged(): void
    {
        $built = $this->catalog(null);
        $found = [];
        foreach ($built['modules'] as $module) {
            foreach ($module['features'] as $feature) {
                foreach (array_merge($feature['actions'] ?? [], ...array_values($feature['groups'] ?? [[]])) as $child) {
                    if (($child['name'] ?? null) === 'tenant.ajax.products') {
                        $found[] = ['module' => $module['key'], 'shared' => $child['shared']];
                    }
                }
            }
        }
        $this->assertCount(1, $found, 'the shared lookup appears exactly once — never invisibly bundled');
        $this->assertSame('catalog', $found[0]['module']);
        $this->assertTrue($found[0]['shared'], 'flagged shared so the UI warns before revoking');
    }
}
