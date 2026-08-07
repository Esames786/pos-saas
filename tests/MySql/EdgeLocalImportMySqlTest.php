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
 * EDGE-LOCAL-RUNTIME-1 — authoritative import proof. Two databases: the "cloud source" tenant DB the
 * REAL buildSections reads, and a fresh Edge-local DB the importer writes to. Proves atomic,
 * FK-coherent, validated import (recipe config incl. raw materials), cross-branch rejection, tamper
 * rejection, idempotency, binding immutability, and refresh-not-implemented — never disabling FK checks.
 */
class EdgeLocalImportMySqlTest extends MySqlTenantTestCase
{
    private string $edgeDb = 'pos_test_edge_local';
    private array $package;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp(); // provisions pos_test_tenant (cloud source) on the `tenant` connection

        config(['app.role' => 'branch_server']);

        $this->seedCloudSource();
        $this->package = $this->buildRealPackage();

        $this->provisionEdgeLocalDb();       // fresh edge-local schema, then point `tenant` at it
    }

    protected function tearDown(): void
    {
        // Restore the tenant connection to the cloud-source DB for the next test's parent setUp.
        config(['database.connections.tenant.database' => $this->tenantDb]);
        DB::purge('tenant');
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function seedCloudSource(): void
    {
        $this->cleanTenant([
            'recipe_ingredients', 'recipes', 'unit_conversions',
            'product_branch_prices', 'product_variants', 'products', 'categories', 'units',
            'branch_user', 'model_has_roles', 'roles', 'users', 'terminals', 'branches',
        ]);
        $conn = DB::connection('tenant');

        // Two branches — we bootstrap branch A only; branch B must never leak.
        $this->branchId = $conn->table('branches')->insertGetId(['name' => 'A', 'code' => 'A', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'created_at' => now(), 'updated_at' => now()]);
        $branchB = $conn->table('branches')->insertGetId(['name' => 'B', 'code' => 'B', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'created_at' => now(), 'updated_at' => now()]);

        $ea = $conn->table('units')->insertGetId(['code' => 'EA', 'name' => 'Each', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $kg = $conn->table('units')->insertGetId(['code' => 'KG', 'name' => 'Kg', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $g  = $conn->table('units')->insertGetId(['code' => 'G', 'name' => 'Gram', 'unit_type' => 'weight', 'base_factor' => 0.001, 'is_base' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('unit_conversions')->insert(['from_unit_id' => $kg, 'to_unit_id' => $g, 'factor' => 1000, 'created_at' => now(), 'updated_at' => now()]);

        $cat = $conn->table('categories')->insertGetId(['name' => 'Food', 'code' => 'FOOD', 'slug' => 'food', 'is_active' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // A sellable recipe product + a raw-material product (not sellable) used as its ingredient.
        $burger = $conn->table('products')->insertGetId(['category_id' => $cat, 'unit_id' => $ea, 'sku' => 'BURGER', 'name' => 'Burger', 'slug' => 'burger', 'product_type' => 'recipe', 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_stock_tracked' => 0, 'default_selling_price' => 500, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $beef   = $conn->table('products')->insertGetId(['category_id' => $cat, 'unit_id' => $kg, 'sku' => 'BEEF', 'name' => 'Beef', 'slug' => 'beef', 'product_type' => 'simple', 'is_sellable' => 0, 'is_pos_visible' => 0, 'is_stock_tracked' => 1, 'default_selling_price' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $recipe = $conn->table('recipes')->insertGetId(['product_id' => $burger, 'name' => 'Burger recipe', 'yield_quantity' => 1, 'yield_unit_id' => $ea, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('recipe_ingredients')->insert(['recipe_id' => $recipe, 'product_id' => $beef, 'quantity' => 150, 'unit_id' => $g, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->branchB = $branchB;
        $this->beefId = $beef;
    }

    private int $branchB;
    private int $beefId;

    private function buildRealPackage(): array
    {
        $svc = new class(app(OfflineEdgeEntitlementService::class), app(TenancyManager::class), app(EdgePairingService::class)) extends EdgeBootstrapService {
            public function sectionsFor(Tenant $t, Branch $b): array
            {
                return $this->buildSections($t, $b);
            }
        };

        $tenant = new Tenant(['tenant_code' => 'restaurantdemo', 'business_name' => 'Demo', 'currency_code' => 'PKR']);
        $tenant->id = 42;
        $branch = Branch::on('tenant')->find($this->branchId);

        $sections = $svc->sectionsFor($tenant, $branch);

        $summary = [];
        foreach ($sections as $name => $rows) {
            $summary[$name] = ['hash' => hash('sha256', $svc->canonicalJson($rows)), 'count' => count($rows)];
        }
        $manifest = [
            'schema_version' => EdgeBootstrapService::SCHEMA_VERSION,
            'snapshot_uuid' => 'snap-1',
            'tenant_code' => 'restaurantdemo',
            'tenant_id' => 42,
            'branch_id' => $this->branchId,
            'device_public_uuid' => 'device-A',
            'activation_epoch' => 1,
            'source_revision' => 'rev-1',
            'sections' => $summary,
        ];
        $manifest['manifest_hash'] = $svc->computeManifestHash('edge-bootstrap-v4', 'snap-1', 42, $this->branchId, 'device-A', 1, $summary);

        return ['manifest' => $manifest, 'sections' => $sections];
    }

    private static bool $edgeReady = false;

    /** Import-target tables cleared before every test (the Edge-local schema is migrated once). */
    private const EDGE_TABLES = [
        'model_has_roles', 'users', 'roles', 'edge_local_meta',
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

        // Start every test from an EMPTY Edge-local DB (FK-safe truncate — test cleanup only).
        $this->cleanTenant(self::EDGE_TABLES);
    }

    private function importer(): EdgeLocalBootstrapImporter
    {
        return app(EdgeLocalBootstrapImporter::class);
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function test_real_package_imports_coherently_with_recipe_config_and_raw_materials(): void
    {
        $meta = $this->importer()->import($this->package);

        $this->assertSame(EdgeLocalMeta::STATE_BOOTSTRAPPED, $meta->runtime_state);
        $this->assertSame($this->branchId, (int) $meta->branch_id);
        $this->assertSame('device-A', $meta->device_uuid);
        $this->assertSame(1, (int) $meta->activation_epoch);

        $conn = DB::connection('tenant');
        // Recipe config imported coherently — the raw-material product is present so the FK holds.
        $this->assertSame(1, $conn->table('recipes')->count());
        $this->assertSame(1, $conn->table('recipe_ingredients')->count());
        $this->assertSame(1, $conn->table('unit_conversions')->count());
        $this->assertTrue($conn->table('products')->where('sku', 'BEEF')->exists(), 'raw-material ingredient product must be imported');
        $this->assertTrue($conn->table('products')->where('sku', 'BURGER')->exists());
        // Only branch A leaked in — exactly one branch row (the bound one).
        $this->assertSame($this->branchId, (int) $conn->table('branches')->value('id'));
        $this->assertSame(0, $conn->table('branches')->where('id', $this->branchB)->count());
    }

    public function test_cross_branch_row_rejects_and_rolls_back_entire_import(): void
    {
        $pkg = $this->package;
        // Inject a Branch-B printer into the branch-scoped printers section, then recompute its hash so
        // integrity passes and only the branch-scoping guard can catch it.
        $pkg['sections']['printers'][] = ['id' => 9999, 'branch_id' => $this->branchB, 'name' => 'Evil', 'code' => 'EVIL', 'printer_type' => 'kot', 'print_role' => 'kot', 'supports_reminder' => 0, 'ip_address' => '127.0.0.1', 'port' => 9100, 'paper_size' => '80mm', 'characters_per_line' => 48, 'is_default' => 0, 'is_active' => 1, 'agent_enabled' => 0];
        $pkg = $this->rehash($pkg, 'printers');

        try {
            $this->importer()->import($pkg);
            $this->fail('cross-branch import must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CROSS_BRANCH', $e->getMessage());
        }
        // Full rollback — nothing imported, no binding.
        $this->assertNull(EdgeLocalMeta::current());
        $this->assertSame(0, DB::connection('tenant')->table('products')->count());
    }

    public function test_tampered_section_hash_is_rejected(): void
    {
        $pkg = $this->package;
        $pkg['sections']['units'][0]['name'] = 'TAMPERED'; // change bytes without updating the hash
        try {
            $this->importer()->import($pkg);
            $this->fail('tampered section must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SECTION_HASH_MISMATCH', $e->getMessage());
        }
        $this->assertNull(EdgeLocalMeta::current());
    }

    public function test_forbidden_history_section_is_rejected(): void
    {
        $pkg = $this->package;
        $pkg['sections']['journal_entries'] = [['id' => 1]];
        $this->expectExceptionMessage('operational history');
        $this->importer()->import($pkg);
    }

    public function test_secret_field_is_rejected(): void
    {
        $pkg = $this->package;
        $pkg['sections']['users'] = [['id' => 1, 'name' => 'x', 'password' => 'hunter2']];
        $pkg = $this->rehash($pkg, 'users');
        $this->expectExceptionMessage('secret field');
        $this->importer()->import($pkg);
    }

    public function test_import_is_idempotent_then_immutable_and_refresh_not_implemented(): void
    {
        $this->importer()->import($this->package);

        // Same package again -> idempotent no-op.
        $meta = $this->importer()->import($this->package);
        $this->assertSame(EdgeLocalMeta::STATE_BOOTSTRAPPED, $meta->runtime_state);
        $this->assertSame(1, EdgeLocalMeta::query()->count());

        // Different tenant/branch/device -> binding immutable.
        $other = $this->package;
        $other['manifest']['tenant_id'] = 99;
        $other['manifest']['manifest_hash'] = app(EdgeBootstrapService::class)->computeManifestHash('edge-bootstrap-v4', 'snap-1', 99, $this->branchId, 'device-A', 1, $other['manifest']['sections']);
        try {
            $this->importer()->import($other);
            $this->fail('rebinding must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('BINDING_IMMUTABLE', $e->getMessage());
        }

        // Same appliance, different snapshot/revision -> refresh not implemented (no blind delete+insert).
        $refresh = $this->package;
        $refresh['manifest']['snapshot_uuid'] = 'snap-2';
        $refresh['manifest']['manifest_hash'] = app(EdgeBootstrapService::class)->computeManifestHash('edge-bootstrap-v4', 'snap-2', 42, $this->branchId, 'device-A', 1, $refresh['manifest']['sections']);
        $this->expectExceptionMessage('REFRESH_NOT_IMPLEMENTED');
        $this->importer()->import($refresh);
    }

    /** Recompute a section's hash + the manifest hash after mutating that section's rows. */
    private function rehash(array $pkg, string $section): array
    {
        $svc = app(EdgeBootstrapService::class);
        $rows = $pkg['sections'][$section];
        $pkg['manifest']['sections'][$section] = ['hash' => hash('sha256', $svc->canonicalJson($rows)), 'count' => count($rows)];
        $m = $pkg['manifest'];
        $pkg['manifest']['manifest_hash'] = $svc->computeManifestHash($m['schema_version'], $m['snapshot_uuid'], (int) $m['tenant_id'], (int) $m['branch_id'], $m['device_public_uuid'], (int) $m['activation_epoch'], $m['sections']);

        return $pkg;
    }
}
