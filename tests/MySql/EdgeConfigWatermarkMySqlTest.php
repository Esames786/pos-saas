<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Printer;
use App\Models\Tenant\RestaurantTable;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeConfigRevisionService;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Carbon;
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
            'restaurant_tables', 'restaurant_floors',
            'category_printer_mappings', 'printers',
            'modifiers', 'modifier_groups',
            'product_branch_prices', 'product_variants', 'products', 'categories', 'units',
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

    /**
     * EDGE OFFLINE-READINESS FIX (24 Nov 2026, Khatri): ~140 config revisions per hour came from writes that are NOT appliance
     * configuration — the print agent folding printer health onto every printer on each heartbeat, print jobs stamping
     * last_seen_at / last_error, and dine-in tables changing occupancy. All of them move updated_at (Eloquent). They must leave the
     * watermark alone, while every real printer / table edit (what the appliance receives and applies) still mints N+1.
     */
    public function test_printer_health_and_table_occupancy_churn_does_not_mint_a_revision_but_real_edits_do(): void
    {
        $conn = DB::connection('tenant');
        $b = $this->branchId;
        $t = fn () => ['created_at' => now(), 'updated_at' => now()];
        $floor = $conn->table('restaurant_floors')->insertGetId(['branch_id' => $b, 'name' => 'Hall', 'code' => 'H', 'status' => 'active', 'sort_order' => 1] + $t());
        $table = $conn->table('restaurant_tables')->insertGetId(['branch_id' => $b, 'restaurant_floor_id' => $floor, 'table_no' => 'H1', 'name' => 'Hall 1', 'capacity' => 4, 'status' => 'available', 'sort_order' => 1] + $t());
        $global = $conn->table('printers')->insertGetId(['branch_id' => null, 'name' => 'Global', 'code' => 'WP-G', 'printer_type' => 'network', 'print_role' => 'receipt', 'supports_reminder' => 0, 'ip_address' => '10.9.9.2', 'port' => 9100, 'is_default' => 0, 'is_active' => 1] + $t());
        $printer = $this->ids['printer'];

        $previous = $this->watermark();
        $revision = $this->allocate($previous);
        $this->assertSame(1, $revision);

        $second = 0;
        $later = function () use (&$second) {
            Carbon::setTestNow(Carbon::now()->addSeconds(++$second + 1)); // a NEW second, so max(updated_at) really moves
        };
        try {
            $churn = [
                'print-agent heartbeat (health fold, PrintAgentApiController::heartbeat)' => fn () => Printer::on('tenant')->whereIn('id', [$printer, $global])
                    ->update(['last_ping_ok' => true, 'last_ping_at' => now(), 'last_ping_ms' => 12, 'last_seen_at' => now()]),
                'print job completed (last_seen_at / last_error)' => fn () => Printer::on('tenant')->find($printer)->update(['last_seen_at' => now(), 'last_error' => null]),
                'print job failed (last_error)' => fn () => Printer::on('tenant')->find($printer)->update(['last_error' => 'paper out']),
                'printer unreachable (agent ping)' => fn () => Printer::on('tenant')->where('id', $printer)->update(['last_ping_ok' => false, 'last_ping_at' => now()]),
                'table opened (occupied, reservation cleared)' => fn () => RestaurantTable::on('tenant')->find($table)->update(['status' => 'occupied', 'reserved_name' => null, 'reserved_at' => null]),
                'bill requested' => fn () => RestaurantTable::on('tenant')->find($table)->update(['status' => 'bill_requested']),
                'table freed (SalesService)' => fn () => RestaurantTable::on('tenant')->where('id', $table)->update(['status' => 'available']),
                'table reserved' => fn () => RestaurantTable::on('tenant')->find($table)->update(['status' => 'reserved', 'reserved_name' => 'Ali', 'reserved_phone' => '0300', 'reserved_at' => now()]),
            ];
            foreach ($churn as $label => $write) {
                $later();
                $write();
                $this->assertSame($previous, $this->watermark(), "{$label}: service churn must NOT change the config watermark");
                $this->assertSame($revision, $this->allocate($this->watermark()), "{$label}: and must not mint a revision");
            }

            $edits = [
                'printer IP' => fn () => Printer::on('tenant')->find($printer)->update(['ip_address' => '10.9.9.99']),
                'printer port' => fn () => Printer::on('tenant')->find($printer)->update(['port' => 9101]),
                'printer switched off' => fn () => Printer::on('tenant')->find($printer)->update(['is_active' => false]),
                'printer role' => fn () => Printer::on('tenant')->find($printer)->update(['print_role' => 'both']),
                'global printer IP (shipped to every branch)' => fn () => Printer::on('tenant')->find($global)->update(['ip_address' => '10.9.9.3']),
                'printer added' => fn () => $conn->table('printers')->insert(['branch_id' => $b, 'name' => 'Bar', 'code' => 'WP-BAR', 'printer_type' => 'network', 'print_role' => 'kot', 'supports_reminder' => 0, 'ip_address' => '10.9.9.4', 'port' => 9100, 'is_default' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]),
                'printer deleted' => fn () => $conn->table('printers')->where('code', 'WP-BAR')->delete(),
                'table renamed' => fn () => RestaurantTable::on('tenant')->find($table)->update(['name' => 'Hall One']),
                'table capacity' => fn () => RestaurantTable::on('tenant')->find($table)->update(['capacity' => 6]),
                'table disabled' => fn () => RestaurantTable::on('tenant')->find($table)->update(['status' => 'inactive']),
                'table re-enabled' => fn () => RestaurantTable::on('tenant')->find($table)->update(['status' => 'available']),
                'table added' => fn () => $conn->table('restaurant_tables')->insert(['branch_id' => $b, 'restaurant_floor_id' => $floor, 'table_no' => 'H2', 'name' => 'Hall 2', 'capacity' => 2, 'status' => 'available', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()]),
                'table deleted' => fn () => $conn->table('restaurant_tables')->where('table_no', 'H2')->delete(),
            ];
            foreach ($edits as $label => $edit) {
                $later();
                $edit();
                $current = $this->watermark();
                $this->assertNotSame($previous, $current, "{$label}: a real config edit MUST change the watermark");
                $this->assertSame($revision + 1, $this->allocate($current), "{$label}: and mint revision N+1");
                $revision++;
                $previous = $current;
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * EDGE OFFLINE-READINESS FIX: the baseline issuance names only products the appliance HOLDS — deliveredProductIdentities()
     * must equal, id for id, the `products` / `product_variants` sections buildSections() ships (sellable set + active-recipe
     * raw materials + modifier-linked products; active variants + ingredient variants). A stocked purchase-only crate, an
     * inactive product and an inactive variant are not delivered.
     */
    public function test_delivered_product_identities_equal_the_shipped_products_and_variants_sections(): void
    {
        $conn = DB::connection('tenant');
        $t = fn () => ['created_at' => now(), 'updated_at' => now()];
        $i = $this->ids;
        $activeVariant = $conn->table('product_variants')->insertGetId(['product_id' => $i['prod'], 'sku' => 'WPROD-L', 'name' => 'Large', 'selling_price' => 150, 'is_default' => 0, 'is_active' => 1] + $t());
        $conn->table('product_variants')->insertGetId(['product_id' => $i['prod'], 'sku' => 'WPROD-X', 'name' => 'Retired', 'selling_price' => 150, 'is_default' => 0, 'is_active' => 0] + $t());
        $linked = $conn->table('products')->insertGetId(['category_id' => $i['cat'], 'unit_id' => $i['g'], 'sku' => 'WCHEESE', 'name' => 'Cheese', 'slug' => 'wcheese', 'product_type' => 'simple', 'is_sellable' => 0, 'is_pos_visible' => 0, 'is_stock_tracked' => 1, 'default_selling_price' => 0, 'status' => 'active'] + $t());
        $conn->table('modifiers')->where('id', $i['modifier'])->update(['linked_product_id' => $linked, 'consume_stock' => 1, 'linked_quantity' => 20, 'linked_unit_id' => $i['g']]);
        $crate = $conn->table('products')->insertGetId(['category_id' => $i['cat'], 'unit_id' => $i['ea'], 'sku' => 'WCRATE', 'name' => 'Crate', 'slug' => 'wcrate', 'product_type' => 'simple', 'is_sellable' => 0, 'is_pos_visible' => 0, 'is_stock_tracked' => 1, 'default_selling_price' => 0, 'status' => 'active'] + $t());
        $retired = $conn->table('products')->insertGetId(['category_id' => $i['cat'], 'unit_id' => $i['ea'], 'sku' => 'WOLD', 'name' => 'Old dish', 'slug' => 'wold', 'product_type' => 'simple', 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_stock_tracked' => 1, 'default_selling_price' => 90, 'status' => 'inactive'] + $t());

        $svc = new class(app(OfflineEdgeEntitlementService::class), app(TenancyManager::class), app(EdgePairingService::class)) extends EdgeBootstrapService {
            public function sectionsFor(Branch $b): array
            {
                return $this->buildSections((new \App\Models\Master\Tenant())->forceFill(['tenant_code' => 'wtest', 'business_name' => 'W', 'currency_code' => 'PKR']), $b);
            }
        };
        $branch = Branch::on('tenant')->find($this->branchId);
        $sections = $svc->sectionsFor($branch);
        $delivered = $svc->deliveredProductIdentities($branch);

        $ids = fn (array $rows) => collect($rows)->map(fn ($r) => (int) $r['id'])->sort()->values()->all();
        $keys = fn (array $set) => collect(array_keys($set))->map(fn ($v) => (int) $v)->sort()->values()->all();
        $this->assertSame($ids($sections['products']), $keys($delivered['products']), 'delivered products == the shipped products section');
        $this->assertSame($ids($sections['product_variants']), $keys($delivered['variants']), 'delivered variants == the shipped product_variants section');
        $this->assertEqualsCanonicalizing([$i['prod'], $i['raw'], $linked], $keys($delivered['products']), 'sellable + recipe raw material + modifier-linked');
        $this->assertSame([$activeVariant], $keys($delivered['variants']));
        $this->assertNotContains($crate, $keys($delivered['products']));
        $this->assertNotContains($retired, $keys($delivered['products']));
    }
}
