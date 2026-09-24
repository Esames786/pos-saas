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
 * W6 (Team 6, wave 2) — bootstrap contract `edge-bootstrap-v7`, proven with the REAL buildSections against a Cloud-source tenant
 * DB and the REAL importer / config-refresh applier against a fresh Edge-local DB (the EdgeLocalImportMySqlTest pattern):
 *
 *  - product_modifier_group ships for the sellable set (Team 2's option resolver joins it — without it no option appears);
 *  - GLOBAL (branch_id NULL) modifier groups + their modifiers ship; another branch's group never leaks;
 *  - a stock-consuming modifier linked to a NON-POS-visible product ships, and so does that product (bare config row);
 *  - currencies + currency_denominations ship (Team 4 C-3 — the shift-close count grid);
 *  - every ACTIVE payment method ships (non-cash rows are display-only; an inactive method does not ship);
 *  - the tenant business name is persisted on the appliance (Team 4 C-4) and follows a config refresh;
 *  - a v6 package is refused by a v7 importer (exact-match contract); the watermark carries the new sections so a change to
 *    them mints a new config revision; a refresh DEACTIVATES a removed denomination (cash_count_lines reference it) and
 *    DELETES a removed product↔group link (pure composition).
 */
class EdgeBootstrapV7MySqlTest extends MySqlTenantTestCase
{
    private string $edgeDb;
    private int $branchId;
    private int $branchB;
    private int $tikka;
    private int $cheese;
    private int $globalGroup;
    private int $branchBGroup;
    private int $cheeseModifier;
    private int $cardId;
    private int $d500;
    private object $svc;

    private static bool $edgeReady = false;

    private const EDGE_TABLES = [
        'model_has_roles', 'users', 'roles', 'edge_local_meta',
        'recipe_ingredients', 'recipes', 'unit_conversions',
        'terminal_printer_settings', 'category_printer_mappings', 'receipt_layout_settings', 'printers',
        'service_charge_settings', 'delivery_riders', 'delivery_channels',
        'restaurant_waiters', 'restaurant_tables', 'restaurant_floors',
        'combo_components', 'combos', 'product_modifier_group', 'modifiers', 'modifier_groups',
        'product_branch_prices', 'product_barcodes', 'product_variants', 'products', 'categories',
        'cash_count_lines', 'currency_denominations', 'currencies', 'units',
        'terminals', 'payment_methods', 'void_reasons', 'branches',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->edgeDb = \Tests\MySql\Support\EdgeTestDatabases::local();
        config(['app.role' => 'branch_server']);

        $this->svc = new class(app(OfflineEdgeEntitlementService::class), app(TenancyManager::class), app(EdgePairingService::class)) extends EdgeBootstrapService {
            public function sectionsFor(Tenant $t, Branch $b): array
            {
                return $this->buildSections($t, $b);
            }

            public function watermark(Branch $b): string
            {
                return $this->sourceRevision($b);
            }
        };
        $this->seedCloudSource();
    }

    protected function tearDown(): void
    {
        config(['database.connections.tenant.database' => $this->tenantDb]);
        DB::purge('tenant');
        parent::tearDown();
    }

    private function seedCloudSource(): void
    {
        $this->cleanTenant([
            'product_modifier_group', 'modifiers', 'modifier_groups', 'cash_count_lines', 'currency_denominations', 'currencies',
            'payment_methods', 'recipe_ingredients', 'recipes', 'unit_conversions',
            'product_branch_prices', 'product_variants', 'products', 'categories', 'units',
            'branch_user', 'model_has_roles', 'roles', 'users', 'terminals', 'branches',
        ]);
        $c = DB::connection('tenant');
        $now = now();
        $this->branchId = $c->table('branches')->insertGetId(['name' => 'A', 'code' => 'A', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'created_at' => $now, 'updated_at' => $now]);
        $this->branchB = $c->table('branches')->insertGetId(['name' => 'B', 'code' => 'B', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'created_at' => $now, 'updated_at' => $now]);
        $pc = $c->table('units')->insertGetId(['code' => 'PC', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $g = $c->table('units')->insertGetId(['code' => 'G', 'name' => 'Gram', 'unit_type' => 'weight', 'base_factor' => 0.001, 'is_base' => 0, 'is_active' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $cat = $c->table('categories')->insertGetId(['name' => 'Grills', 'code' => 'GR', 'slug' => 'grills', 'is_active' => 1, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->tikka = $c->table('products')->insertGetId(['category_id' => $cat, 'unit_id' => $pc, 'sku' => 'TIKKA', 'name' => 'Tikka', 'slug' => 'tikka', 'product_type' => 'simple', 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_stock_tracked' => 1, 'default_selling_price' => 250, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        // a raw ingredient: not sellable, not POS-visible, stocked in an INACTIVE unit (must still ship, FK-coherent)
        $this->cheese = $c->table('products')->insertGetId(['category_id' => $cat, 'unit_id' => $g, 'sku' => 'CHEESE', 'name' => 'Cheese', 'slug' => 'cheese', 'product_type' => 'simple', 'is_sellable' => 0, 'is_pos_visible' => 0, 'is_stock_tracked' => 1, 'default_selling_price' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->globalGroup = $c->table('modifier_groups')->insertGetId(['branch_id' => null, 'name' => 'Extras', 'min_select' => 0, 'max_select' => 2, 'is_required' => 0, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->branchBGroup = $c->table('modifier_groups')->insertGetId(['branch_id' => $this->branchB, 'name' => 'B only', 'min_select' => 0, 'max_select' => 1, 'is_required' => 0, 'sort_order' => 2, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->cheeseModifier = $c->table('modifiers')->insertGetId(['modifier_group_id' => $this->globalGroup, 'name' => 'Extra Cheese', 'price_delta' => 30, 'linked_product_id' => $this->cheese, 'consume_stock' => 1, 'linked_quantity' => 50, 'linked_unit_id' => $g, 'is_default' => 0, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $c->table('modifiers')->insert(['modifier_group_id' => $this->branchBGroup, 'name' => 'B option', 'price_delta' => 5, 'linked_product_id' => null, 'consume_stock' => 0, 'linked_quantity' => null, 'is_default' => 0, 'sort_order' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $c->table('product_modifier_group')->insert(['product_id' => $this->tikka, 'modifier_group_id' => $this->globalGroup, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $pkr = $c->table('currencies')->insertGetId(['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => 'Rs', 'decimal_places' => 0, 'is_default' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $c->table('currency_denominations')->insert(['currency_id' => $pkr, 'denomination_value' => 1000, 'denomination_type' => 'note', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->d500 = $c->table('currency_denominations')->insertGetId(['currency_id' => $pkr, 'denomination_value' => 500, 'denomination_type' => 'note', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $c->table('payment_methods')->insert(['code' => 'CASH', 'name' => 'Cash', 'method_type' => 'cash', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->cardId = $c->table('payment_methods')->insertGetId(['code' => 'CARD', 'name' => 'Card', 'method_type' => 'card', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $c->table('payment_methods')->insert(['code' => 'OLDBANK', 'name' => 'Old Bank', 'method_type' => 'bank_transfer', 'is_active' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function package(string $businessName = 'Demo Foods', int $revision = 1, string $snapshot = 'snap-1', ?string $schema = null): array
    {
        $tenant = new Tenant(['tenant_code' => 'restaurantdemo', 'business_name' => $businessName, 'currency_code' => 'PKR']);
        $tenant->id = 42;
        $branch = Branch::on('tenant')->find($this->branchId);
        $sections = $this->svc->sectionsFor($tenant, $branch);

        return $this->packageFrom($sections, $revision, $snapshot, $schema ?? EdgeBootstrapService::SCHEMA_VERSION);
    }

    private function packageFrom(array $sections, int $revision, string $snapshot, string $schema): array
    {
        $summary = [];
        foreach ($sections as $name => $rows) {
            $summary[$name] = ['hash' => hash('sha256', $this->svc->canonicalJson($rows)), 'count' => count($rows)];
        }
        $manifest = [
            'schema_version' => $schema, 'snapshot_uuid' => $snapshot, 'tenant_code' => 'restaurantdemo', 'tenant_id' => 42,
            'branch_id' => $this->branchId, 'device_public_uuid' => 'device-A', 'activation_epoch' => 1, 'config_revision' => $revision,
            'config_schema_version' => EdgeBootstrapService::CONFIG_SCHEMA_VERSION, 'source_revision' => 'rev-' . $revision, 'sections' => $summary,
        ];
        $manifest['manifest_hash'] = $this->svc->computeManifestHash($schema, $snapshot, 42, $this->branchId, 'device-A', 1, $revision, EdgeBootstrapService::CONFIG_SCHEMA_VERSION, $summary);

        return ['manifest' => $manifest, 'sections' => $sections];
    }

    private function toEdgeDb(): void
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

    public function test_v7_export_carries_the_menu_denomination_payment_and_tenant_sections(): void
    {
        $this->assertSame('edge-bootstrap-v7', EdgeBootstrapService::SCHEMA_VERSION);
        $s = $this->package()['sections'];

        $this->assertSame([[$this->tikka, $this->globalGroup]], array_map(fn ($r) => [(int) $r['product_id'], (int) $r['modifier_group_id']], $s['product_modifier_group']));
        $groupIds = array_map(fn ($r) => (int) $r['id'], $s['modifier_groups']);
        $this->assertContains($this->globalGroup, $groupIds, 'a GLOBAL group ships');
        $this->assertNotContains($this->branchBGroup, $groupIds, "another branch's group never leaks");
        $this->assertSame(['Extra Cheese'], array_column($s['modifiers'], 'name'));
        $this->assertContains($this->cheese, array_map(fn ($r) => (int) $r['id'], $s['products']), 'the modifier-linked raw product ships as a config row');
        $this->assertContains('G', array_column($s['units'], 'code'), 'the inactive unit it is stocked in ships too (FK-coherent)');
        $this->assertSame(['PKR'], array_column($s['currencies'], 'code'));
        $this->assertEqualsCanonicalizing([1000.0, 500.0], array_map('floatval', array_column($s['currency_denominations'], 'denomination_value')));
        $this->assertEqualsCanonicalizing(['cash', 'card'], array_column($s['payment_methods'], 'method_type'), 'every ACTIVE method; the inactive one never ships');
        $this->assertSame(['cash'], $s['restrictions'][0]['allowed_payment_types'], 'the offline tender rule is unchanged (cash only)');
        $this->assertSame('Demo Foods', $s['tenant'][0]['business_name']);

        // the watermark covers the new sections: a new product↔group link mints a new config revision
        $before = $this->svc->watermark(Branch::on('tenant')->find($this->branchId));
        DB::connection('tenant')->table('product_modifier_group')->insert(['product_id' => $this->cheese, 'modifier_group_id' => $this->globalGroup, 'sort_order' => 2, 'created_at' => now()->addSecond(), 'updated_at' => now()->addSecond()]);
        $this->assertNotSame($before, $this->svc->watermark(Branch::on('tenant')->find($this->branchId)));
    }

    public function test_v7_imports_coherently_persists_the_business_name_and_a_v6_package_is_refused(): void
    {
        $package = $this->package();
        $v6 = $this->packageFrom($package['sections'], 1, 'snap-v6', 'edge-bootstrap-v6');
        $this->toEdgeDb();

        try {
            $this->importer()->import($v6);
            $this->fail('a v7 build must refuse a v6 export');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SCHEMA_UNSUPPORTED', $e->getMessage());
        }
        $this->assertSame(0, EdgeLocalMeta::query()->count(), 'nothing imported from a refused package');

        $meta = $this->importer()->import($package);
        $this->assertSame(EdgeLocalMeta::STATE_BOOTSTRAPPED, $meta->runtime_state);
        $this->assertSame('edge-bootstrap-v7', $meta->bootstrap_schema);
        $this->assertSame('Demo Foods', $meta->tenant_business_name);
        $c = DB::connection('tenant');
        $this->assertSame(1, $c->table('product_modifier_group')->where('product_id', $this->tikka)->where('modifier_group_id', $this->globalGroup)->count());
        $this->assertSame(1, $c->table('modifier_groups')->whereNull('branch_id')->count());
        $this->assertSame((int) $this->cheese, (int) $c->table('modifiers')->where('id', $this->cheeseModifier)->value('linked_product_id'));
        $this->assertTrue($c->table('products')->where('id', $this->cheese)->where('is_pos_visible', 0)->exists());
        $this->assertSame(1, $c->table('currencies')->where('is_default', 1)->count());
        $this->assertSame(2, $c->table('currency_denominations')->count());
        $this->assertSame(['cash', 'card'], $c->table('payment_methods')->orderBy('id')->pluck('method_type')->all());
    }

    public function test_a_refresh_deactivates_a_removed_denomination_deletes_a_removed_link_and_follows_the_business_name(): void
    {
        $first = $this->package();
        // revision 2 at the Cloud: the 500 note retired, the tikka↔Extras link removed, card retired, tenant renamed
        DB::connection('tenant')->table('currency_denominations')->where('id', $this->d500)->delete();
        DB::connection('tenant')->table('product_modifier_group')->delete();
        DB::connection('tenant')->table('payment_methods')->where('id', $this->cardId)->update(['is_active' => 0]);
        $second = $this->package('Demo Foods & Grill', 2, 'snap-2');

        $this->toEdgeDb();
        $this->importer()->import($first);
        $meta = $this->importer()->import($second);

        $this->assertSame(2, (int) $meta->last_applied_config_revision);
        $this->assertSame('Demo Foods & Grill', $meta->tenant_business_name);
        $c = DB::connection('tenant');
        $this->assertSame(0, (int) $c->table('currency_denominations')->where('id', $this->d500)->value('is_active'), 'deactivated, never deleted (cash_count_lines reference it)');
        $this->assertSame(0, $c->table('product_modifier_group')->count(), 'a pure composition row is deleted');
        $this->assertSame(0, (int) $c->table('payment_methods')->where('id', $this->cardId)->value('is_active'));
    }
}
