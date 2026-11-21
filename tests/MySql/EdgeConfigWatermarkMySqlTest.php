<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeConfigRevisionService;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\DB;

/**
 * EDGE-CONFIG-REFRESH-1 §4 — executable pin of the sourceRevision watermark + monotonic revision
 * allocator. Unchanged configuration => stable watermark => SAME config revision (a rebuild never
 * mints a new one). Every supported config mutation class — UPDATE (price/recipe/printer/permission)
 * and DELETE-ONLY — must change the watermark and allocate a NEW revision. Runs against the REAL
 * cloud-source tenant schema; no Edge-local DB involved.
 */
class EdgeConfigWatermarkMySqlTest extends MySqlTenantTestCase
{
    private const TENANT_ID = 424242; // dedicated allocator namespace for this test

    private int $branchId;
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWatermarkFixture();
        DB::connection('master')->table('edge_branch_config_revisions')->where('tenant_id', self::TENANT_ID)->delete();
    }

    private function seedWatermarkFixture(): void
    {
        $this->cleanTenant([
            'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles',
            'recipe_ingredients', 'recipes', 'unit_conversions',
            'category_printer_mappings', 'printers',
            'modifiers', 'modifier_groups',
            'product_branch_prices', 'products', 'categories', 'units',
            'branch_user', 'users', 'terminals', 'branches',
        ]);
        $conn = DB::connection('tenant');
        $t = fn () => ['created_at' => now(), 'updated_at' => now()];
        $i = &$this->ids;

        $this->branchId = $conn->table('branches')->insertGetId(['name' => 'W', 'code' => 'W', 'status' => 'active', 'timezone' => 'Asia/Karachi'] + $t());
        $b = $this->branchId;

        $i['ea'] = $conn->table('units')->insertGetId(['code' => 'EA', 'name' => 'Each', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1] + $t());
        $i['g'] = $conn->table('units')->insertGetId(['code' => 'G', 'name' => 'Gram', 'unit_type' => 'weight', 'base_factor' => 0.001, 'is_base' => 0, 'is_active' => 1] + $t());
        $i['cat'] = $conn->table('categories')->insertGetId(['name' => 'Food', 'code' => 'FOOD', 'slug' => 'wfood', 'is_active' => 1, 'sort_order' => 1] + $t());
        $i['prod'] = $conn->table('products')->insertGetId(['category_id' => $i['cat'], 'unit_id' => $i['ea'], 'sku' => 'WPROD', 'name' => 'WProd', 'slug' => 'wprod', 'product_type' => 'recipe', 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_stock_tracked' => 0, 'default_selling_price' => 100, 'status' => 'active'] + $t());
        $i['raw'] = $conn->table('products')->insertGetId(['category_id' => $i['cat'], 'unit_id' => $i['g'], 'sku' => 'WRAW', 'name' => 'WRaw', 'slug' => 'wraw', 'product_type' => 'simple', 'is_sellable' => 0, 'is_pos_visible' => 0, 'is_stock_tracked' => 1, 'default_selling_price' => 0, 'status' => 'active'] + $t());
        $i['price'] = $conn->table('product_branch_prices')->insertGetId(['branch_id' => $b, 'product_id' => $i['prod'], 'selling_price' => 100, 'is_available' => 1] + $t());
        $i['recipe'] = $conn->table('recipes')->insertGetId(['product_id' => $i['prod'], 'name' => 'W recipe', 'yield_quantity' => 1, 'yield_unit_id' => $i['ea'], 'is_active' => 1] + $t());
        $i['ingredient'] = $conn->table('recipe_ingredients')->insertGetId(['recipe_id' => $i['recipe'], 'product_id' => $i['raw'], 'quantity' => 10, 'unit_id' => $i['g'], 'sort_order' => 1] + $t());
        $i['printer'] = $conn->table('printers')->insertGetId(['branch_id' => $b, 'name' => 'WP', 'code' => 'WP-1', 'printer_type' => 'network', 'print_role' => 'kot', 'supports_reminder' => 0, 'ip_address' => '10.9.9.1', 'port' => 9100, 'is_default' => 1, 'is_active' => 1] + $t());
        $i['mapping'] = $conn->table('category_printer_mappings')->insertGetId(['branch_id' => $b, 'category_id' => $i['cat'], 'printer_id' => $i['printer'], 'print_role' => 'kot', 'order_type' => 'all', 'reminder_confirm_on_addition' => 0, 'is_active' => 1] + $t());
        $i['mgroup'] = $conn->table('modifier_groups')->insertGetId(['branch_id' => $b, 'name' => 'WExtras', 'min_select' => 0, 'max_select' => 1, 'is_required' => 0, 'sort_order' => 1, 'status' => 'active'] + $t());
        $i['modifier'] = $conn->table('modifiers')->insertGetId(['modifier_group_id' => $i['mgroup'], 'name' => 'WMod', 'price_delta' => 5, 'consume_stock' => 0, 'is_default' => 0, 'sort_order' => 1, 'status' => 'active'] + $t());

        $i['role'] = $conn->table('roles')->insertGetId(['name' => 'WRole', 'guard_name' => 'tenant'] + $t());
        $i['perm_a'] = $conn->table('permissions')->insertGetId(['name' => 'w.perm.a', 'guard_name' => 'tenant'] + $t());
        $i['perm_b'] = $conn->table('permissions')->insertGetId(['name' => 'w.perm.b', 'guard_name' => 'tenant'] + $t());
        $conn->table('role_has_permissions')->insert([
            ['permission_id' => $i['perm_a'], 'role_id' => $i['role']],
            ['permission_id' => $i['perm_b'], 'role_id' => $i['role']],
        ]);
        $i['user'] = $conn->table('users')->insertGetId(['employee_code' => 'W-EMP', 'name' => 'W User', 'email' => 'w@x.test', 'password' => 'cloud-hash', 'default_branch_id' => $b, 'status' => 'active'] + $t());
        $conn->table('model_has_roles')->insert(['role_id' => $i['role'], 'model_type' => \App\Models\Tenant\User::class, 'model_id' => $i['user']]);
    }

    /** Expose the REAL protected watermark. */
    private function watermark(): string
    {
        $svc = new class(app(OfflineEdgeEntitlementService::class), app(TenancyManager::class), app(EdgePairingService::class)) extends EdgeBootstrapService {
            public function watermarkFor(Branch $b): string
            {
                return $this->sourceRevision($b);
            }
        };

        return $svc->watermarkFor(Branch::on('tenant')->find($this->branchId));
    }

    private function allocate(string $watermark): int
    {
        return app(EdgeConfigRevisionService::class)->allocateForWatermark(self::TENANT_ID, $this->branchId, $watermark);
    }

    public function test_unchanged_configuration_yields_a_stable_watermark_and_the_same_revision(): void
    {
        $w1 = $this->watermark();
        $w2 = $this->watermark();
        $this->assertSame($w1, $w2, 'two builds from unchanged config must produce the SAME watermark');

        $r1 = $this->allocate($w1);
        $r2 = $this->allocate($w2);
        $this->assertSame($r1, $r2, 'a rebuild from unchanged config must reuse the SAME config revision');
        $this->assertSame(1, $r1);
    }

    public function test_every_supported_mutation_class_changes_the_watermark_and_mints_a_new_revision(): void
    {
        $conn = DB::connection('tenant');
        $i = $this->ids;
        // updated_at has SECOND granularity — bump explicitly so a same-second edit can't hide.
        $bump = 1;
        $touch = fn () => now()->addSeconds($bump += 2);

        $mutations = [
            'A price update' => fn () => $conn->table('product_branch_prices')->where('id', $i['price'])
                ->update(['selling_price' => 175, 'updated_at' => $touch()]),
            'B recipe ingredient update' => fn () => $conn->table('recipe_ingredients')->where('id', $i['ingredient'])
                ->update(['quantity' => 22, 'updated_at' => $touch()]),
            'C1 printer update' => fn () => $conn->table('printers')->where('id', $i['printer'])
                ->update(['ip_address' => '10.9.9.77', 'updated_at' => $touch()]),
            'C2 printer mapping update' => fn () => $conn->table('category_printer_mappings')->where('id', $i['mapping'])
                ->update(['order_type' => 'dine_in', 'updated_at' => $touch()]),
            'D permission change (role loses a permission)' => fn () => $conn->table('role_has_permissions')
                ->where('role_id', $i['role'])->where('permission_id', $i['perm_b'])->delete(),
            'E1 DELETE-ONLY: modifier removed' => fn () => $conn->table('modifiers')->where('id', $i['modifier'])->delete(),
            'E2 DELETE-ONLY: printer mapping removed' => fn () => $conn->table('category_printer_mappings')->where('id', $i['mapping'])->delete(),
        ];

        $previous = $this->watermark();
        $revision = $this->allocate($previous);
        $this->assertSame(1, $revision);

        foreach ($mutations as $label => $mutate) {
            $mutate();
            $current = $this->watermark();
            $this->assertNotSame($previous, $current, "{$label}: the watermark MUST change");

            $next = $this->allocate($current);
            $this->assertSame($revision + 1, $next, "{$label}: a changed watermark must mint revision N+1");
            $revision = $next;
            $previous = $current;
        }

        // And back to rest: rebuilding with no further changes stays stable on the last revision.
        $this->assertSame($previous, $this->watermark(), 'watermark stays stable after the mutation series');
        $this->assertSame($revision, $this->allocate($previous), 'revision stays stable after the mutation series');
    }
}
