<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeLocalBootstrapImporter;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * EDGE-CONFIG-REFRESH-1 — authoritative refresh proof on real MySQL. Two databases: the "cloud
 * source" tenant DB the REAL buildSections reads, and an Edge-local DB that is bootstrapped ONCE and
 * then REFRESHED (never re-imported destructively). Proves: upsert of changed rows, insert of new
 * rows, tombstone/delete of removed rows, historical + held/open operational survival, occupancy
 * merge, same-revision replay no-op, old-revision/bad-hash/wrong-binding refusal, all-or-nothing
 * rollback, and concurrent-refresh serialization on the edge_local_meta row lock.
 */
class EdgeConfigRefreshMySqlTest extends MySqlTenantTestCase
{
    private string $edgeDb;
    private static bool $edgeReady = false;

    private int $branchId;
    private int $branchB;
    private array $ids = [];   // cloud config row ids, keyed by mnemonic
    private array $packageV1;

    protected function setUp(): void
    {
        parent::setUp(); // provisions the cloud-source tenant DB on the `tenant` connection

        // PLATFORM TEST-ISOLATION: env-driven per-worktree Edge-local DB with a class-own suffix so
        // this suite never drops the import/auth suites' DB (and no other worktree's either).
        $this->edgeDb = \Tests\MySql\Support\EdgeTestDatabases::local('refresh');

        config(['app.role' => 'branch_server']);

        $this->seedCloudSource();
        $this->packageV1 = $this->buildPackage(1, 'snap-1');

        $this->provisionEdgeLocalDb();
        $this->importer()->import($this->packageV1);
    }

    protected function tearDown(): void
    {
        config(['database.connections.tenant.database' => $this->tenantDb]);
        DB::purge('tenant');
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function seedCloudSource(): void
    {
        $this->cleanTenant([
            'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles',
            'recipe_ingredients', 'recipes', 'unit_conversions',
            'terminal_printer_settings', 'category_printer_mappings', 'receipt_layout_settings', 'printers',
            'service_charge_settings', 'delivery_riders', 'delivery_channels',
            'restaurant_waiters', 'restaurant_tables', 'restaurant_floors',
            'combo_components', 'combos', 'modifiers', 'modifier_groups',
            'product_branch_prices', 'product_barcodes', 'product_variants', 'products', 'categories', 'units',
            'branch_user', 'users', 'terminals', 'payment_methods', 'void_reasons', 'branches',
        ]);
        $conn = DB::connection('tenant');
        $t = fn () => ['created_at' => now(), 'updated_at' => now()];

        $this->branchId = $conn->table('branches')->insertGetId(['name' => 'A', 'code' => 'A', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'receipt_footer' => 'Thank you'] + $t());
        $this->branchB = $conn->table('branches')->insertGetId(['name' => 'B', 'code' => 'B', 'status' => 'active', 'timezone' => 'Asia/Karachi'] + $t());
        $b = $this->branchId;

        $i = &$this->ids;
        $i['ea'] = $conn->table('units')->insertGetId(['code' => 'EA', 'name' => 'Each', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1] + $t());
        $i['kg'] = $conn->table('units')->insertGetId(['code' => 'KG', 'name' => 'Kg', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1] + $t());
        $i['g']  = $conn->table('units')->insertGetId(['code' => 'G', 'name' => 'Gram', 'unit_type' => 'weight', 'base_factor' => 0.001, 'is_base' => 0, 'is_active' => 1] + $t());
        $conn->table('unit_conversions')->insert(['from_unit_id' => $i['kg'], 'to_unit_id' => $i['g'], 'factor' => 1000] + $t());

        $i['cat'] = $conn->table('categories')->insertGetId(['name' => 'Food', 'code' => 'FOOD', 'slug' => 'food', 'is_active' => 1, 'sort_order' => 1] + $t());

        $i['burger'] = $conn->table('products')->insertGetId(['category_id' => $i['cat'], 'unit_id' => $i['ea'], 'sku' => 'BURGER', 'name' => 'Burger', 'slug' => 'burger', 'product_type' => 'recipe', 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_stock_tracked' => 0, 'default_selling_price' => 500, 'status' => 'active'] + $t());
        $i['cola']   = $conn->table('products')->insertGetId(['category_id' => $i['cat'], 'unit_id' => $i['ea'], 'sku' => 'COLA', 'name' => 'Cola', 'slug' => 'cola', 'product_type' => 'simple', 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_stock_tracked' => 0, 'default_selling_price' => 120, 'status' => 'active'] + $t());
        $i['beef']   = $conn->table('products')->insertGetId(['category_id' => $i['cat'], 'unit_id' => $i['kg'], 'sku' => 'BEEF', 'name' => 'Beef', 'slug' => 'beef', 'product_type' => 'simple', 'is_sellable' => 0, 'is_pos_visible' => 0, 'is_stock_tracked' => 1, 'default_selling_price' => 0, 'status' => 'active'] + $t());

        $i['barcode'] = $conn->table('product_barcodes')->insertGetId(['product_id' => $i['burger'], 'barcode' => 'B-100', 'barcode_type' => 'manual', 'is_primary' => 1] + $t());
        $i['price']   = $conn->table('product_branch_prices')->insertGetId(['branch_id' => $b, 'product_id' => $i['burger'], 'selling_price' => 500, 'is_available' => 1] + $t());

        $i['recipe'] = $conn->table('recipes')->insertGetId(['product_id' => $i['burger'], 'name' => 'Burger recipe', 'yield_quantity' => 1, 'yield_unit_id' => $i['ea'], 'is_active' => 1] + $t());
        $i['ingredient'] = $conn->table('recipe_ingredients')->insertGetId(['recipe_id' => $i['recipe'], 'product_id' => $i['beef'], 'quantity' => 150, 'unit_id' => $i['g'], 'sort_order' => 1] + $t());

        $i['term1'] = $conn->table('terminals')->insertGetId(['branch_id' => $b, 'code' => 'TERM1', 'name' => 'Till 1', 'requires_shift' => 1, 'status' => 'active'] + $t());

        $i['combo'] = $conn->table('combos')->insertGetId(['branch_id' => $b, 'code' => 'CMB1', 'name' => 'Burger Meal', 'price' => 600, 'sort_order' => 1, 'status' => 'active'] + $t());
        $i['component'] = $conn->table('combo_components')->insertGetId(['combo_id' => $i['combo'], 'product_id' => $i['burger'], 'quantity' => 1, 'sort_order' => 1] + $t());

        $i['mgroup'] = $conn->table('modifier_groups')->insertGetId(['branch_id' => $b, 'name' => 'Extras', 'min_select' => 0, 'max_select' => 3, 'is_required' => 0, 'sort_order' => 1, 'status' => 'active'] + $t());
        $i['modifier'] = $conn->table('modifiers')->insertGetId(['modifier_group_id' => $i['mgroup'], 'name' => 'Extra Cheese', 'price_delta' => 50, 'consume_stock' => 0, 'is_default' => 0, 'sort_order' => 1, 'status' => 'active'] + $t());

        $conn->table('payment_methods')->insert(['code' => 'CASH', 'name' => 'Cash', 'method_type' => 'cash', 'requires_reference' => 0, 'is_cash_drawer' => 1, 'is_active' => 1] + $t());
        $i['void'] = $conn->table('void_reasons')->insertGetId(['name' => 'Mistake', 'reason_type' => 'cancellation', 'requires_manager_approval' => 0, 'is_active' => 1] + $t());

        $i['floor'] = $conn->table('restaurant_floors')->insertGetId(['branch_id' => $b, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'sort_order' => 1] + $t());
        $i['tbl1'] = $conn->table('restaurant_tables')->insertGetId(['branch_id' => $b, 'restaurant_floor_id' => $i['floor'], 'table_no' => 'T1', 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'sort_order' => 1] + $t());
        $i['tbl2'] = $conn->table('restaurant_tables')->insertGetId(['branch_id' => $b, 'restaurant_floor_id' => $i['floor'], 'table_no' => 'T2', 'name' => 'Table 2', 'capacity' => 2, 'status' => 'available', 'sort_order' => 2] + $t());
        $i['waiter'] = $conn->table('restaurant_waiters')->insertGetId(['branch_id' => $b, 'name' => 'Walter', 'code' => 'W1', 'status' => 'active'] + $t());
        $i['rider'] = $conn->table('delivery_riders')->insertGetId(['branch_id' => $b, 'name' => 'Rex', 'status' => 'active'] + $t());
        $conn->table('delivery_channels')->insert(['name' => 'Own Delivery', 'type' => 'own', 'commission_percent' => 0, 'is_active' => 1, 'sort_order' => 1] + $t());

        $i['printer_r'] = $conn->table('printers')->insertGetId(['branch_id' => $b, 'name' => 'Receipt', 'code' => 'P-R', 'printer_type' => 'network', 'print_role' => 'receipt', 'supports_reminder' => 0, 'ip_address' => '10.0.0.1', 'port' => 9100, 'is_default' => 1, 'is_active' => 1] + $t());
        $i['printer_k'] = $conn->table('printers')->insertGetId(['branch_id' => $b, 'name' => 'Kitchen', 'code' => 'P-K', 'printer_type' => 'network', 'print_role' => 'kot', 'supports_reminder' => 0, 'ip_address' => '10.0.0.2', 'port' => 9100, 'is_default' => 1, 'is_active' => 1] + $t());
        $i['printer_b'] = $conn->table('printers')->insertGetId(['branch_id' => $b, 'name' => 'Bar', 'code' => 'P-B', 'printer_type' => 'network', 'print_role' => 'kot', 'supports_reminder' => 0, 'ip_address' => '10.0.0.3', 'port' => 9100, 'is_default' => 0, 'is_active' => 1] + $t());
        $i['map_k'] = $conn->table('category_printer_mappings')->insertGetId(['branch_id' => $b, 'category_id' => null, 'printer_id' => $i['printer_k'], 'print_role' => 'kot', 'order_type' => 'all', 'reminder_confirm_on_addition' => 0, 'is_active' => 1] + $t());
        $i['map_b'] = $conn->table('category_printer_mappings')->insertGetId(['branch_id' => $b, 'category_id' => $i['cat'], 'printer_id' => $i['printer_b'], 'print_role' => 'kot', 'order_type' => 'all', 'reminder_confirm_on_addition' => 0, 'is_active' => 1] + $t());
        $conn->table('terminal_printer_settings')->insert(['terminal_id' => $i['term1'], 'receipt_printer_id' => $i['printer_r'], 'kot_printer_id' => $i['printer_k'], 'auto_print_receipt' => 1, 'auto_print_kot' => 1] + $t());
        $i['layout'] = $conn->table('receipt_layout_settings')->insertGetId(['branch_id' => $b, 'document_type' => 'receipt', 'footer_text' => 'Come again', 'is_active' => 1] + $t());

        // Users + Spatie graph (Cloud side; export denormalises to roles/permissions arrays).
        $i['role_mgr'] = $conn->table('roles')->insertGetId(['name' => 'Manager', 'guard_name' => 'tenant'] + $t());
        $i['role_csh'] = $conn->table('roles')->insertGetId(['name' => 'Cashier', 'guard_name' => 'tenant'] + $t());
        $i['role_spare'] = $conn->table('roles')->insertGetId(['name' => 'Spare', 'guard_name' => 'tenant'] + $t());
        $i['perm_pos'] = $conn->table('permissions')->insertGetId(['name' => 'tenant.pos.store', 'guard_name' => 'tenant'] + $t());
        $i['perm_approve'] = $conn->table('permissions')->insertGetId(['name' => 'tenant.pos.approve', 'guard_name' => 'tenant'] + $t());
        $conn->table('role_has_permissions')->insert([
            ['permission_id' => $i['perm_pos'], 'role_id' => $i['role_csh']],
            ['permission_id' => $i['perm_pos'], 'role_id' => $i['role_mgr']],
            ['permission_id' => $i['perm_approve'], 'role_id' => $i['role_mgr']],
        ]);

        $mkUser = fn (string $code, string $name) => $conn->table('users')->insertGetId([
            'employee_code' => $code, 'name' => $name, 'email' => strtolower($code) . '@x.test',
            'password' => 'cloud-hash-never-exported', 'default_branch_id' => $b, 'status' => 'active',
        ] + $t());
        $i['mgr'] = $mkUser('USR-M', 'Mia Manager');
        $i['csh'] = $mkUser('USR-C', 'Cai Cashier');
        $i['gone'] = $mkUser('USR-X', 'Xen Leaver');
        $conn->table('model_has_roles')->insert([
            ['role_id' => $i['role_mgr'], 'model_type' => \App\Models\Tenant\User::class, 'model_id' => $i['mgr']],
            ['role_id' => $i['role_csh'], 'model_type' => \App\Models\Tenant\User::class, 'model_id' => $i['csh']],
            ['role_id' => $i['role_csh'], 'model_type' => \App\Models\Tenant\User::class, 'model_id' => $i['gone']],
        ]);
        // User B (cashier) holds tenant.pos.approve DIRECTLY — independent of the Manager role — so
        // revoking it from the ROLE must not remove B's grant, and the permission ROW must survive.
        $conn->table('model_has_permissions')->insert([
            'permission_id' => $i['perm_approve'], 'model_type' => \App\Models\Tenant\User::class, 'model_id' => $i['csh'],
        ]);
    }

    /** Build a REAL package (real buildSections) with a hand-stamped monotonic revision. */
    private function buildPackage(int $revision, string $uuid): array
    {
        $svc = new class(app(OfflineEdgeEntitlementService::class), app(TenancyManager::class), app(EdgePairingService::class)) extends EdgeBootstrapService {
            public function sectionsFor(Tenant $t, Branch $b): array
            {
                return $this->buildSections($t, $b);
            }
        };
        $tenant = new Tenant(['tenant_code' => 'refreshdemo', 'business_name' => 'Demo', 'currency_code' => 'PKR']);
        $tenant->id = 42;
        $branch = Branch::on('tenant')->find($this->branchId);
        $sections = $svc->sectionsFor($tenant, $branch);

        $summary = [];
        foreach ($sections as $name => $rows) {
            $summary[$name] = ['hash' => hash('sha256', $svc->canonicalJson($rows)), 'count' => count($rows)];
        }
        $manifest = [
            'schema_version' => EdgeBootstrapService::SCHEMA_VERSION,
            'snapshot_uuid' => $uuid,
            'tenant_code' => 'refreshdemo',
            'tenant_id' => 42,
            'branch_id' => $this->branchId,
            'device_public_uuid' => 'device-A',
            'activation_epoch' => 1,
            'config_revision' => $revision,
            'config_schema_version' => EdgeBootstrapService::CONFIG_SCHEMA_VERSION,
            'source_revision' => 'rev-' . $revision,
            'sections' => $summary,
        ];
        $manifest['manifest_hash'] = $svc->computeManifestHash(EdgeBootstrapService::SCHEMA_VERSION, $uuid, 42, $this->branchId, 'device-A', 1, $revision, EdgeBootstrapService::CONFIG_SCHEMA_VERSION, $summary);

        return ['manifest' => $manifest, 'sections' => $sections];
    }

    /** Run $mutations against the CLOUD source, build a package, then point back at the Edge DB. */
    private function cloudPackage(int $revision, string $uuid, ?callable $mutations = null): array
    {
        config(['database.connections.tenant.database' => $this->tenantDb]);
        DB::purge('tenant');
        if ($mutations) {
            $mutations(DB::connection('tenant'));
        }
        $package = $this->buildPackage($revision, $uuid);

        config(['database.connections.tenant.database' => $this->edgeDb, 'database.connections.edge_local.database' => $this->edgeDb]);
        DB::purge('tenant');

        return $package;
    }

    /** Import-target + operational tables cleared before every test (schema migrated once). */
    private const EDGE_TABLES = [
        'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders',
        'restaurant_table_sessions', 'shifts',
        'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions',
        'users', 'roles', 'edge_local_meta',
        'recipe_ingredients', 'recipes', 'unit_conversions',
        'terminal_printer_settings', 'category_printer_mappings', 'receipt_layout_settings', 'printers',
        'service_charge_settings', 'delivery_riders', 'delivery_channels',
        'restaurant_waiters', 'restaurant_tables', 'restaurant_floors',
        'combo_components', 'combos', 'modifiers', 'modifier_groups',
        'product_branch_prices', 'product_barcodes', 'product_variants', 'products', 'categories', 'units',
        'terminals', 'payment_methods', 'void_reasons', 'branches',
    ];

    private function provisionEdgeLocalDb(): void
    {
        $c = config('database.connections.tenant');

        if (! self::$edgeReady) {
            $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("DROP DATABASE IF EXISTS `{$this->edgeDb}`");
            $pdo->exec("CREATE DATABASE `{$this->edgeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            config(['database.connections.tenant.database' => $this->edgeDb, 'database.connections.edge_local.database' => $this->edgeDb]);
            DB::purge('tenant');
            Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
            Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
            self::$edgeReady = true;
        } else {
            config(['database.connections.tenant.database' => $this->edgeDb, 'database.connections.edge_local.database' => $this->edgeDb]);
            DB::purge('tenant');
        }

        $this->cleanTenant(self::EDGE_TABLES);
    }

    private function importer(): EdgeLocalBootstrapImporter
    {
        return app(EdgeLocalBootstrapImporter::class);
    }

    /** Open shift + held sale (Burger & Cola lines) + open occupied table session + KOT history. */
    private function seedOperationalHistory(): void
    {
        $conn = DB::connection('tenant');
        $i = $this->ids;
        $t = fn () => ['created_at' => now(), 'updated_at' => now()];

        $this->shiftId = $conn->table('shifts')->insertGetId(['branch_id' => $this->branchId, 'terminal_id' => $i['term1'], 'opened_by_user_id' => $i['csh'], 'opened_at' => now(), 'status' => 'open'] + $t());

        $this->heldSaleId = $conn->table('sales_orders')->insertGetId(['sale_no' => 'SO-HELD-1', 'branch_id' => $this->branchId, 'order_source' => 'pos', 'order_type' => 'dine_in', 'sale_date' => now(), 'status' => 'held'] + $t());
        $conn->table('sales_order_lines')->insert([
            ['sales_order_id' => $this->heldSaleId, 'product_id' => $i['burger'], 'product_name' => 'Burger', 'quantity' => 2, 'unit_price' => 500] + $t(),
            ['sales_order_id' => $this->heldSaleId, 'product_id' => $i['cola'], 'product_name' => 'Cola', 'quantity' => 1, 'unit_price' => 120] + $t(),
        ]);

        $this->sessionId = $conn->table('restaurant_table_sessions')->insertGetId(['session_no' => 'TS-1', 'branch_id' => $this->branchId, 'restaurant_table_id' => $i['tbl1'], 'restaurant_waiter_id' => $i['waiter'], 'opened_by_user_id' => $i['csh'], 'status' => 'open', 'opened_at' => now()] + $t());
        $conn->table('restaurant_tables')->where('id', $i['tbl1'])->update(['status' => 'occupied']);

        $kot = $conn->table('kot_batches')->insertGetId(['event_uuid' => 'kot-uuid-1', 'sales_order_id' => $this->heldSaleId, 'sequence_no' => 1, 'event_type' => 'new'] + $t());
        $conn->table('kot_batch_lines')->insert(['kot_batch_id' => $kot, 'product_id' => $i['burger'], 'product_name' => 'Burger', 'quantity' => 2, 'combo_id' => $i['combo']] + $t());
    }

    private int $shiftId;
    private int $heldSaleId;
    private int $sessionId;

    /** The standard "revision 2" cloud edit set used by most tests. */
    private function packageV2(): array
    {
        $i = $this->ids;

        return $this->cloudPackage(2, 'snap-2', function ($conn) use ($i) {
            // UPDATE paths.
            $conn->table('branches')->where('id', $this->branchId)->update(['receipt_footer' => 'New footer']);
            $conn->table('products')->where('id', $i['burger'])->update(['name' => 'Big Burger', 'default_selling_price' => 550]);
            $conn->table('product_branch_prices')->where('id', $i['price'])->update(['selling_price' => 550]);
            $conn->table('combo_components')->where('id', $i['component'])->update(['quantity' => 2]);
            $conn->table('recipe_ingredients')->where('id', $i['ingredient'])->update(['quantity' => 180]);
            $conn->table('modifiers')->where('id', $i['modifier'])->update(['price_delta' => 75]);
            $conn->table('printers')->where('id', $i['printer_k'])->update(['ip_address' => '10.0.0.99']);
            $conn->table('receipt_layout_settings')->where('id', $i['layout'])->update(['footer_text' => 'Updated footer']);

            // INSERT paths.
            $conn->table('products')->insert(['category_id' => $i['cat'], 'unit_id' => $i['ea'], 'sku' => 'FRIES', 'name' => 'Fries', 'slug' => 'fries', 'product_type' => 'simple', 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_stock_tracked' => 0, 'default_selling_price' => 200, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $conn->table('terminals')->insert(['branch_id' => $this->branchId, 'code' => 'TERM2', 'name' => 'Till 2', 'requires_shift' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

            // TOMBSTONE / DELETE paths (removed from the authoritative revision).
            $conn->table('product_barcodes')->where('id', $i['barcode'])->delete();
            $conn->table('products')->where('id', $i['cola'])->delete();          // held sale still references it locally
            $conn->table('restaurant_waiters')->where('id', $i['waiter'])->delete();
            $conn->table('restaurant_tables')->where('id', $i['tbl2'])->delete();
            $conn->table('delivery_riders')->where('id', $i['rider'])->delete();
            $conn->table('category_printer_mappings')->where('id', $i['map_b'])->delete();
            $conn->table('printers')->where('id', $i['printer_b'])->delete();
            $conn->table('roles')->where('id', $i['role_spare'])->delete();
            $conn->table('model_has_roles')->where('model_id', $i['gone'])->where('model_type', \App\Models\Tenant\User::class)->delete();
            $conn->table('users')->where('id', $i['gone'])->delete();

            // Permission change: the Manager role loses tenant.pos.approve.
            $conn->table('role_has_permissions')->where('role_id', $i['role_mgr'])->where('permission_id', $i['perm_approve'])->delete();
        });
    }

    private function meta(): EdgeLocalMeta
    {
        return EdgeLocalMeta::query()->where('singleton_guard', 1)->firstOrFail();
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function test_refresh_applies_updates_inserts_tombstones_and_preserves_operational_history(): void
    {
        $this->seedOperationalHistory();
        $i = $this->ids;
        $conn = DB::connection('tenant');

        $this->importer()->import($this->packageV2());

        // Revision advanced; refresh metadata recorded; initial bootstrap record untouched.
        $meta = $this->meta();
        $this->assertSame(2, (int) $meta->last_applied_config_revision);
        $this->assertSame('snap-2', $meta->last_refresh_snapshot_uuid);
        $this->assertNotNull($meta->last_refreshed_at);
        $this->assertSame('snap-1', $meta->bootstrap_snapshot_uuid, 'initial bootstrap record stays frozen');

        // UPDATES applied.
        $this->assertSame('Big Burger', $conn->table('products')->where('id', $i['burger'])->value('name'));
        $this->assertSame(550.0, (float) $conn->table('product_branch_prices')->where('id', $i['price'])->value('selling_price'));
        $this->assertSame(2.0, (float) $conn->table('combo_components')->where('id', $i['component'])->value('quantity'));
        $this->assertSame(180.0, (float) $conn->table('recipe_ingredients')->where('id', $i['ingredient'])->value('quantity'));
        $this->assertSame(75.0, (float) $conn->table('modifiers')->where('id', $i['modifier'])->value('price_delta'));
        $this->assertSame('10.0.0.99', $conn->table('printers')->where('id', $i['printer_k'])->value('ip_address'));
        $this->assertSame('Updated footer', $conn->table('receipt_layout_settings')->where('id', $i['layout'])->value('footer_text'));
        $this->assertSame('New footer', $conn->table('branches')->where('id', $this->branchId)->value('receipt_footer'));

        // INSERTS applied.
        $this->assertTrue($conn->table('products')->where('sku', 'FRIES')->exists());
        $this->assertTrue($conn->table('terminals')->where('code', 'TERM2')->exists());

        // TOMBSTONES: rows removed upstream are deactivated, NEVER deleted (history resolves).
        $this->assertSame('inactive', $conn->table('products')->where('id', $i['cola'])->value('status'));
        $this->assertSame('inactive', $conn->table('restaurant_waiters')->where('id', $i['waiter'])->value('status'));
        $this->assertSame('inactive', $conn->table('restaurant_tables')->where('id', $i['tbl2'])->value('status'));
        $this->assertSame('inactive', $conn->table('delivery_riders')->where('id', $i['rider'])->value('status'));
        $this->assertSame(0, (int) $conn->table('printers')->where('id', $i['printer_b'])->value('is_active'));
        $this->assertSame(0, (int) $conn->table('category_printer_mappings')->where('id', $i['map_b'])->value('is_active'));
        $this->assertSame('inactive', $conn->table('users')->where('id', $i['gone'])->value('status'));

        // DELETES: pure composition rows with no inbound FKs.
        $this->assertSame(0, $conn->table('product_barcodes')->where('id', $i['barcode'])->count());
        $this->assertSame(0, $conn->table('roles')->where('id', $i['role_spare'])->count());

        // Permission graph rebuilt: manager lost tenant.pos.approve. The permission ROW remains,
        // because user B (cashier) still holds it DIRECTLY — revocation is per-user grant removal,
        // never a row deletion another user still needs.
        $mgrPerms = $conn->table('model_has_permissions as mhp')->join('permissions as p', 'p.id', '=', 'mhp.permission_id')
            ->where('mhp.model_type', \App\Models\Tenant\User::class)->where('mhp.model_id', $i['mgr'])->pluck('p.name')->all();
        $this->assertSame(['tenant.pos.store'], $mgrPerms);
        $cshPerms = $conn->table('model_has_permissions as mhp')->join('permissions as p', 'p.id', '=', 'mhp.permission_id')
            ->where('mhp.model_type', \App\Models\Tenant\User::class)->where('mhp.model_id', $i['csh'])->pluck('p.name')->all();
        $this->assertContains('tenant.pos.approve', $cshPerms, 'user B keeps their direct grant');
        $this->assertSame(1, $conn->table('permissions')->where('name', 'tenant.pos.approve')->count(), 'the permission row survives while any user still holds it');

        // OPERATIONAL SURVIVAL — nothing historical/open was destroyed or mutated.
        $this->assertSame('open', $conn->table('shifts')->where('id', $this->shiftId)->value('status'));
        $this->assertSame('held', $conn->table('sales_orders')->where('id', $this->heldSaleId)->value('status'));
        $lines = $conn->table('sales_order_lines')->where('sales_order_id', $this->heldSaleId)->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('Burger', $lines[0]->product_name, 'held snapshot name is immutable');
        $this->assertSame(500.0, (float) $lines[0]->unit_price, 'held snapshot price survives the price change');
        $this->assertSame((int) $i['cola'], (int) $lines[1]->product_id, 'held line still resolves the tombstoned product');
        $this->assertSame('open', $conn->table('restaurant_table_sessions')->where('id', $this->sessionId)->value('status'));
        $this->assertSame(1, $conn->table('kot_batch_lines')->where('product_id', $i['burger'])->count(), 'KOT history keeps resolving');

        // OCCUPANCY MERGE: the occupied table stays occupied (Cloud said "available").
        $this->assertSame('occupied', $conn->table('restaurant_tables')->where('id', $i['tbl1'])->value('status'));
    }

    public function test_same_revision_replay_is_a_safe_noop(): void
    {
        $v2 = $this->packageV2();
        $this->importer()->import($v2);
        $burgersBefore = DB::connection('tenant')->table('products')->count();

        $meta = $this->importer()->import($v2); // exact same revision + content

        $this->assertSame(2, (int) $meta->last_applied_config_revision);
        $this->assertSame($burgersBefore, DB::connection('tenant')->table('products')->count());
    }

    public function test_old_revision_is_refused(): void
    {
        $this->importer()->import($this->packageV2());

        try {
            $this->importer()->import($this->packageV1); // revision 1 after revision 2
            $this->fail('an older revision must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('OLD_REVISION', $e->getMessage());
        }
        $this->assertSame(2, (int) $this->meta()->last_applied_config_revision);
    }

    public function test_revision_gap_applies_fully(): void
    {
        // Revision 5 lands directly on a revision-1 appliance (2..4 never delivered). Safe: every
        // revision carries the complete supported set.
        $i = $this->ids;
        $v5 = $this->cloudPackage(5, 'snap-5', function ($conn) use ($i) {
            $conn->table('products')->where('id', $i['burger'])->update(['name' => 'Gap Burger']);
        });

        $this->importer()->import($v5);

        $this->assertSame(5, (int) $this->meta()->last_applied_config_revision);
        $this->assertSame('Gap Burger', DB::connection('tenant')->table('products')->where('id', $i['burger'])->value('name'));
    }

    public function test_bad_section_hash_is_refused_and_nothing_changes(): void
    {
        $v2 = $this->packageV2();
        $v2['sections']['products'][0]['name'] = 'TAMPERED'; // bytes changed, hash not recomputed

        try {
            $this->importer()->import($v2);
            $this->fail('a tampered refresh section must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SECTION_HASH_MISMATCH', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->meta()->last_applied_config_revision);
        $this->assertSame('Burger', DB::connection('tenant')->table('products')->where('id', $this->ids['burger'])->value('name'));
    }

    public function test_wrong_device_binding_is_refused(): void
    {
        $v2 = $this->packageV2();
        $v2['manifest']['device_public_uuid'] = 'device-B';
        $m = $v2['manifest'];
        $v2['manifest']['manifest_hash'] = app(EdgeBootstrapService::class)->computeManifestHash(
            $m['schema_version'], $m['snapshot_uuid'], (int) $m['tenant_id'], (int) $m['branch_id'],
            'device-B', (int) $m['activation_epoch'], (int) $m['config_revision'], (string) $m['config_schema_version'], $m['sections']
        );

        try {
            $this->importer()->import($v2);
            $this->fail('another device must never refresh this appliance');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('BINDING_IMMUTABLE', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->meta()->last_applied_config_revision);
    }

    public function test_failed_apply_rolls_back_everything_and_revision_does_not_advance(): void
    {
        // A refresh whose recipe_ingredients references a nonexistent product violates the REAL FK
        // mid-apply (after products/waiters were already written) — the WHOLE apply must roll back.
        $v2 = $this->packageV2();
        $v2['sections']['recipe_ingredients'][] = ['id' => 987654, 'recipe_id' => $this->ids['recipe'], 'product_id' => 999999, 'product_variant_id' => null, 'quantity' => 1, 'unit_id' => $this->ids['g'], 'cost_override' => null, 'sort_order' => 9];
        $v2 = $this->rehash($v2, 'recipe_ingredients');

        try {
            $this->importer()->import($v2);
            $this->fail('an FK-violating refresh must fail');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        $conn = DB::connection('tenant');
        $this->assertSame(1, (int) $this->meta()->last_applied_config_revision, 'failed refresh must not advance the revision');
        $this->assertSame('Burger', $conn->table('products')->where('id', $this->ids['burger'])->value('name'), 'earlier sections rolled back');
        $this->assertSame('active', $conn->table('restaurant_waiters')->where('id', $this->ids['waiter'])->value('status'), 'tombstones rolled back');
        $this->assertTrue($conn->table('product_barcodes')->where('id', $this->ids['barcode'])->exists(), 'deletes rolled back');
    }

    public function test_concurrent_refresh_serializes_on_the_meta_lock(): void
    {
        $v2 = $this->packageV2();

        // An independent connection holds the refresh authority (the meta row lock).
        $c = config('database.connections.tenant');
        $rival = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$this->edgeDb};charset=utf8mb4", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rival->beginTransaction();
        $rival->query('SELECT id FROM edge_local_meta WHERE singleton_guard = 1 FOR UPDATE')->fetchAll();

        // The refresh cannot interleave — it blocks on the lock and times out (never partial).
        DB::connection('tenant')->statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->importer()->import($v2);
            $this->fail('refresh must wait on the refresh authority lock');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('lock', strtolower($e->getMessage()));
        }
        $this->assertSame(1, (int) $this->meta()->last_applied_config_revision);
        $this->assertSame('Burger', DB::connection('tenant')->table('products')->where('id', $this->ids['burger'])->value('name'), 'no partial apply leaked');

        // Lock released -> the same refresh applies cleanly.
        $rival->rollBack();
        DB::connection('tenant')->statement('SET SESSION innodb_lock_wait_timeout = 50');
        $this->importer()->import($v2);
        $this->assertSame(2, (int) $this->meta()->last_applied_config_revision);
    }

    /**
     * EDGE-CONFIG-REFRESH-1 SECURITY CLOSURE — the ONE effective offline permission authority.
     *
     * Cloud revision N: Manager role holds tenant.pos.store + tenant.pos.approve; user B (cashier)
     * ALSO holds tenant.pos.approve directly (so the permission row itself must remain). Revision
     * N+1 revokes approve from the Manager role only. A stale local role_has_permissions row (from
     * any historical writer) must NOT re-grant the Manager via Spatie's role->permission path:
     * roles are identity/group metadata (model_has_roles, for hasRole()); the effective authority
     * is the per-user denormalised model_has_permissions the Cloud exports.
     */
    public function test_stale_role_has_permissions_cannot_regrant_a_revoked_permission(): void
    {
        $i = $this->ids;
        $conn = DB::connection('tenant');

        // HAZARD SEED: a stale role->permission row on the appliance (whatever wrote it — an old
        // build, manual ops, a future seeded migration). Never a second effective authority.
        $permApprove = $conn->table('permissions')->where('name', 'tenant.pos.approve')->value('id');
        $roleMgr = $conn->table('roles')->where('name', 'Manager')->value('id');
        $conn->table('role_has_permissions')->insert(['permission_id' => $permApprove, 'role_id' => $roleMgr]);

        $this->importer()->import($this->packageV2()); // N+1: Manager loses approve; B keeps it

        // Assert under the APPLIANCE's connection semantics: on a real Branch Server the `tenant`
        // connection IS the default (EdgeLocalDatabase::useAsTenantConnection), so Spatie resolves
        // permissions/roles from the Edge-local DB exactly as the runtime does.
        DB::setDefaultConnection('tenant');
        try {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            $mgr = \App\Models\Tenant\User::on('tenant')->find($i['mgr']);
            $csh = \App\Models\Tenant\User::on('tenant')->find($i['csh']);
            $gone = \App\Models\Tenant\User::on('tenant')->find($i['gone']);

            // THE regression — real Spatie can(): the revoked permission must be gone for the Manager.
            $this->assertFalse($mgr->can('tenant.pos.approve'), 'a stale role_has_permissions row must never re-grant a revoked permission');
            $this->assertTrue($csh->can('tenant.pos.approve'), 'user B keeps their direct grant');
            $this->assertTrue($conn->table('permissions')->where('name', 'tenant.pos.approve')->exists(), 'the permission row remains — user B still needs it');

            // Roles stay identity metadata: hasRole() intact; unrelated permissions survive.
            $this->assertTrue($mgr->hasRole('Manager'));
            $this->assertTrue($mgr->can('tenant.pos.store'));

            // ONE authority: role_has_permissions is cleared on refresh (exactly as the initial import).
            $this->assertSame(0, $conn->table('role_has_permissions')->count(), 'role_has_permissions must not survive a refresh as a second authority');

            // A tombstoned user cannot authorize — the auth gate refuses inactive users before can().
            $this->assertSame('inactive', $gone->status);
            $this->assertFalse(\App\Support\EdgeUserAuthz::isActive($gone));

            // No Cloud password/PIN is ever introduced by a refresh.
            $this->assertSame(0, $conn->table('users')->whereNotNull('password')->count());
        } finally {
            DB::setDefaultConnection((string) config('database.default'));
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * §5 — permission reconciliation stays INSIDE the one refresh transaction. The users section is
     * the LAST plan section; a duplicate new-user id makes the SECOND insert PK-violate AFTER the
     * permission graph was rebuilt and earlier rows were written. EVERYTHING must roll back — no
     * partially changed authorization may survive, and the revision must not advance.
     */
    public function test_late_failure_after_permission_reconciliation_rolls_back_the_whole_refresh(): void
    {
        $conn = DB::connection('tenant');

        // Stale-authority seed: rollback must restore even rows the refresh would have cleared.
        $conn->table('role_has_permissions')->insert([
            'permission_id' => $conn->table('permissions')->where('name', 'tenant.pos.approve')->value('id'),
            'role_id' => $conn->table('roles')->where('name', 'Manager')->value('id'),
        ]);

        $snapshot = fn () => [
            'users' => $conn->table('users')->orderBy('id')->get(['id', 'name', 'status'])->map(fn ($r) => (array) $r)->all(),
            'roles' => $conn->table('roles')->orderBy('id')->pluck('name')->all(),
            'mhr' => $conn->table('model_has_roles')->orderBy('model_id')->orderBy('role_id')->get(['model_id', 'role_id'])->map(fn ($r) => (array) $r)->all(),
            'mhp' => $conn->table('model_has_permissions')->orderBy('model_id')->orderBy('permission_id')->get(['model_id', 'permission_id'])->map(fn ($r) => (array) $r)->all(),
            'rhp' => $conn->table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id'])->map(fn ($r) => (array) $r)->all(),
            'permissions' => $conn->table('permissions')->orderBy('id')->pluck('name')->all(),
            'revision' => (int) $this->meta()->last_applied_config_revision,
        ];
        $before = $snapshot();

        $v2 = $this->packageV2();
        $newUser = ['id' => 777001, 'employee_code' => 'USR-N', 'name' => 'New N', 'default_branch_id' => $this->branchId,
            'default_terminal_id' => null, 'status' => 'active', 'locale' => null,
            'allowed_order_types' => ['quick_sale'], 'default_order_type' => 'quick_sale',
            'roles' => ['Cashier'], 'permissions' => ['tenant.pos.store']];
        $v2['sections']['users'][] = $newUser;
        $v2['sections']['users'][] = $newUser; // same id twice -> PK duplicate on the SECOND insert
        $v2 = $this->rehash($v2, 'users');

        try {
            $this->importer()->import($v2);
            $this->fail('a duplicate user id must fail the refresh');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        $this->assertSame($before, $snapshot(), 'a failed refresh must restore users/roles/graph/authority and not advance the revision');
        $this->assertSame(1, $before['revision']);
        $this->assertSame(0, $conn->table('users')->where('id', 777001)->count());
        $this->assertSame('Burger', $conn->table('products')->where('id', $this->ids['burger'])->value('name'), 'earlier sections rolled back too');
    }

    /** Recompute a section's hash + the manifest hash after mutating that section's rows. */
    private function rehash(array $pkg, string $section): array
    {
        $svc = app(EdgeBootstrapService::class);
        $rows = $pkg['sections'][$section];
        $pkg['manifest']['sections'][$section] = ['hash' => hash('sha256', $svc->canonicalJson($rows)), 'count' => count($rows)];
        $m = $pkg['manifest'];
        $pkg['manifest']['manifest_hash'] = $svc->computeManifestHash($m['schema_version'], $m['snapshot_uuid'], (int) $m['tenant_id'], (int) $m['branch_id'], $m['device_public_uuid'], (int) $m['activation_epoch'], (int) $m['config_revision'], (string) $m['config_schema_version'], $m['sections']);

        return $pkg;
    }
}
