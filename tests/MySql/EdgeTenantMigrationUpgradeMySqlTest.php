<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Services\Edge\EdgeLocalSchemaUpgrader;
use App\Services\Edge\EdgeTenantMigrationManifest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OWNER DECISION D7 — the Edge UPGRADE TESTS named by the manifest for every post-baseline tenant migration that SHIPS
 * AND RUNS on the appliance (EDGE_REQUIRED / EDGE_SCHEMA_SAFE). Each test:
 *
 *   1. is SKIPPED (not passed) while the canonical file has not been merged yet (PENDING_MERGE_FROM 99e9365) — the
 *      manifest CI gate only requires the test to EXIST; it activates the moment the merge lands the file;
 *   2. makes the appliance look OLDER (drops exactly what the migration adds, deletes its migrations row);
 *   3. runs the REAL EdgeLocalSchemaUpgrader (manifest-governed, forward-only, data-loss audited) and asserts the plan
 *      lists the migration as RUN, the schema is forward, the seeded operational rows survive and the Cloud-shaped
 *      bootstrap row the migration exists for can now be inserted.
 */
class EdgeTenantMigrationUpgradeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $paidId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_sync_outbox', 'print_jobs', 'sales_order_lines', 'sales_orders', 'shifts', 'edge_local_meta', 'printers', 'products', 'combos', 'categories', 'branches', 'users']);
        config(['database.connections.edge_local.database' => $this->tenantDb, 'database.connections.edge_local.host' => '127.0.0.1']);
        $this->asBranchServerRuntime();
        $this->branchId = $this->makeBranch();
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 10);
        $productId = $this->makeProduct($this->makeCategory());
        $this->paidId = $this->makeSale($this->branchId, ['status' => 'paid', 'grand_total' => 250]);
        $this->makeSaleLine($this->paidId, $productId, ['quantity' => 1, 'unit_price' => 250]);
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── driver ────────────────────────────────────────────────────────────────

    /**
     * @param  callable  $makeOlder  drops what the migration adds (schema + the migrations row is deleted here)
     * @param  callable  $assertForward  asserts the post-upgrade shape
     */
    private function proveUpgrade(string $migration, string $expectedClass, callable $makeOlder, callable $assertForward): void
    {
        $manifest = EdgeTenantMigrationManifest::load();
        $this->assertSame($expectedClass, $manifest->classification($migration), "manifest classification of {$migration}");
        $this->assertSame(EdgeTenantMigrationManifest::DECISION_RUN, $manifest->decisionFor($migration));
        if (! is_file(database_path("migrations/tenant/{$migration}.php"))) {
            $this->markTestSkipped("{$migration} is pending merge from canonical " . $manifest->pendingMergeFrom($migration) . ' — this upgrade test activates when the file lands.');
        }
        $conn = DB::connection('tenant');
        $makeOlder($conn, Schema::connection('tenant'));
        $conn->table('migrations')->where('migration', $migration)->delete();

        $upgrader = app(EdgeLocalSchemaUpgrader::class);
        $plan = $upgrader->plan();
        $this->assertContains($migration, $plan['run'], 'the plan lists the migration as RUN');
        $this->assertSame(EdgeTenantMigrationManifest::VERDICT_OK, $plan['verdict']);
        $result = $upgrader->upgrade();
        $this->assertContains($migration, $result['applied']);
        $this->assertTrue($conn->table('migrations')->where('migration', $migration)->exists());
        $assertForward($conn, Schema::connection('tenant'));

        $meta = EdgeLocalMeta::current();
        $this->assertNull($meta->last_error);
        $this->assertSame('paid', $conn->table('sales_orders')->where('id', $this->paidId)->value('status'), 'operational rows survive');
        $this->assertSame(1, $conn->table('sales_order_lines')->where('sales_order_id', $this->paidId)->count());
        $this->assertSame([], $upgrader->upgrade()['applied'], 'idempotent');
    }

    private function dropColumns(string $table, array $columns): void
    {
        $schema = Schema::connection('tenant');
        $present = array_values(array_filter($columns, fn ($c) => $schema->hasColumn($table, $c)));
        if ($present !== []) {
            $schema->table($table, fn (Blueprint $t) => $t->dropColumn($present));
        }
    }

    private function dropFkColumn(string $table, string $column): void
    {
        $schema = Schema::connection('tenant');
        if ($schema->hasColumn($table, $column)) {
            $schema->table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId($column));
        }
    }

    private function enumValues(string $table, string $column): array
    {
        $row = DB::connection('tenant')->selectOne("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        preg_match_all("/'([^']*)'/", (string) ($row->Type ?? ''), $m);

        return $m[1];
    }

    // ── EDGE_REQUIRED (canonical 99e9365) ──────────────────────────────────────

    public function test_kot_heading_stars_toggle_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_09_26_000001_add_kot_heading_stars_toggle', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('receipt_layout_settings', ['show_heading_stars']),
            fn ($conn, $schema) => $this->assertTrue($schema->hasColumn('receipt_layout_settings', 'show_heading_stars')));
    }

    public function test_report_channels_on_branches_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_09_30_000002_add_report_channels_to_branches', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('branches', ['report_channels', 'report_whatsapp']),
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('branches', 'report_channels'));
                $this->assertTrue($schema->hasColumn('branches', 'report_whatsapp'));
                // the whole Cloud branch row (with the report columns) now upserts on the appliance
                $conn->table('branches')->where('id', $this->branchId)->update(['report_channels' => json_encode(['email']), 'report_whatsapp' => json_encode([])]);
            });
    }

    public function test_windows_document_printer_support_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_05_000001_add_windows_document_printer_support', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn) {
                $this->dropColumns('printers', ['windows_printer_name']);
                $conn->statement("ALTER TABLE printers MODIFY printer_type ENUM('network','usb','browser') NOT NULL DEFAULT 'browser'");
                $conn->statement("ALTER TABLE printers MODIFY print_role ENUM('receipt','kot','both') NOT NULL DEFAULT 'receipt'");
                $conn->statement("ALTER TABLE printers MODIFY paper_size ENUM('58mm','80mm','A4') NOT NULL DEFAULT '80mm'");
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('printers', 'windows_printer_name'));
                $this->assertContains('windows', $this->enumValues('printers', 'printer_type'));
                $this->assertContains('document', $this->enumValues('printers', 'print_role'));
                $this->assertContains('A5', $this->enumValues('printers', 'paper_size'));
                // a Cloud "windows document A5" printer row now inserts on the appliance (bootstrap section shape)
                $id = $this->makePrinter(['branch_id' => $this->branchId, 'printer_type' => 'windows', 'print_role' => 'document', 'paper_size' => 'A5', 'windows_printer_name' => 'Office Laser']);
                $this->assertSame('windows', $conn->table('printers')->where('id', $id)->value('printer_type'));
            });
    }

    public function test_reminder_pause_on_shifts_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_05_000002_add_reminder_pause_to_shifts', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn) {
                $this->dropFkColumn('shifts', 'reminders_paused_by_user_id');
                $this->dropColumns('shifts', ['reminders_paused_at']);
                $conn->table('permissions')->where('name', 'tenant.pos.pause-reminders')->delete();
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('shifts', 'reminders_paused_at'));
                $this->assertTrue($schema->hasColumn('shifts', 'reminders_paused_by_user_id'));
                $this->assertTrue($conn->table('permissions')->where('name', 'tenant.pos.pause-reminders')->exists());
            });
    }

    public function test_cost_of_sales_account_on_categories_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_14_000001_add_cost_of_sales_account_to_categories', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function () {
                $this->dropFkColumn('categories', 'cogs_account_id');
                $this->dropFkColumn('goods_receipt_lines', 'cost_account_id');
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('categories', 'cogs_account_id'));
                $this->assertTrue($schema->hasColumn('goods_receipt_lines', 'cost_account_id'), 'placed after affects_stock — the EDGE_SCHEMA_SAFE dependency held');
            });
    }

    public function test_online_ordering_tables_upgrade_an_older_appliance(): void
    {
        // Older appliance = the bootstrap-section columns are missing; the Cloud-only tables are left in place
        // (hasTable guards) so the Cloud-shaped test DB keeps its dependents.
        $this->proveUpgrade('2026_10_14_000001_create_online_ordering_tables', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function () {
                $this->dropColumns('products', ['website_price']);
                $this->dropColumns('combos', ['website_price']);
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('products', 'website_price'));
                $this->assertTrue($schema->hasColumn('combos', 'website_price'));
                foreach (['web_settings', 'web_order_routes', 'delivery_zones'] as $cloudOnly) {
                    $this->assertTrue($schema->hasTable($cloudOnly), "{$cloudOnly} exists (empty) on the appliance");
                }
            });
    }

    public function test_website_fields_on_catalog_upgrade_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_15_000001_add_website_fields_to_catalog', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            function ($conn, $schema) {
                $schema->dropIfExists('combo_modifier_group');
                foreach (['products', 'combos'] as $t) {
                    $this->dropFkColumn($t, 'website_category_id');
                    $this->dropColumns($t, ['show_on_website', 'website_card_name', 'website_size_label', 'website_tag', 'website_popular', 'website_sort_order', 'website_image_path', 'website_description']);
                }
                $this->dropColumns('categories', ['show_on_website', 'website_name', 'website_sort_order', 'website_visible_from', 'website_visible_to']);
                $this->dropColumns('modifier_groups', ['channel']);
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('modifier_groups', 'channel'));
                $this->assertTrue($schema->hasColumn('products', 'website_category_id'));
                $this->assertTrue($schema->hasColumn('combos', 'show_on_website'));
                $this->assertTrue($schema->hasColumn('categories', 'website_name'));
                $this->assertTrue($schema->hasTable('combo_modifier_group'));
                $this->assertSame('pos', $conn->selectOne("SHOW COLUMNS FROM `modifier_groups` LIKE 'channel'")->Default);
            });
    }

    public function test_medicine_fields_on_products_upgrade_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_19_000001_add_medicine_fields_to_products', EdgeTenantMigrationManifest::EDGE_REQUIRED,
            fn () => $this->dropColumns('products', ['generic_name', 'strength', 'needs_prescription']),
            function ($conn, $schema) {
                foreach (['generic_name', 'strength', 'needs_prescription'] as $c) {
                    $this->assertTrue($schema->hasColumn('products', $c), $c);
                }
                $this->assertNotNull($this->makeProduct($this->makeCategory(), ['generic_name' => 'Paracetamol', 'strength' => '500mg', 'needs_prescription' => 0]));
            });
    }

    // ── EDGE_SCHEMA_SAFE (canonical 99e9365) ───────────────────────────────────

    public function test_affects_stock_on_goods_receipt_lines_upgrades_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_06_000001_add_affects_stock_to_goods_receipt_lines', EdgeTenantMigrationManifest::EDGE_SCHEMA_SAFE,
            fn () => $this->dropColumns('goods_receipt_lines', ['affects_stock']),
            fn ($conn, $schema) => $this->assertTrue($schema->hasColumn('goods_receipt_lines', 'affects_stock')));
    }

    public function test_website_order_fields_on_sales_orders_upgrade_an_older_appliance(): void
    {
        $this->proveUpgrade('2026_10_15_000002_add_website_order_fields_to_sales_orders', EdgeTenantMigrationManifest::EDGE_SCHEMA_SAFE,
            function ($conn, $schema) {
                if ($schema->hasColumn('sales_orders', 'web_order_key')) {
                    $schema->table('sales_orders', function (Blueprint $t) {
                        $t->dropUnique(['web_order_key']);
                        $t->dropUnique(['web_token']);
                        $t->dropColumn(['web_order_key', 'web_token', 'web_meta']);
                    });
                }
                $conn->table('sales_orders')->where('order_source', 'website')->delete();
                $conn->statement("ALTER TABLE sales_orders MODIFY order_source ENUM('pos','manual') NOT NULL DEFAULT 'manual'");
            },
            function ($conn, $schema) {
                $this->assertTrue($schema->hasColumn('sales_orders', 'web_order_key'));
                $this->assertTrue($schema->hasColumn('sales_orders', 'web_meta'));
                $this->assertContains('website', $this->enumValues('sales_orders', 'order_source'));
                $this->assertNull($conn->table('sales_orders')->where('id', $this->paidId)->value('web_order_key'), 'a local sale carries no website fields');
            });
    }

    // ── the manifest names only tests that exist here ───────────────────────────

    public function test_every_post_baseline_shipped_migration_names_a_test_method_of_this_class_or_a_phase4_proof(): void
    {
        $m = EdgeTenantMigrationManifest::load();
        foreach ($m->entries() as $name => $entry) {
            if (($entry['BASELINE'] ?? false) || $entry['CLASSIFICATION'] === EdgeTenantMigrationManifest::CLOUD_ONLY) {
                continue;
            }
            foreach ((array) $entry['UPGRADE_TEST'] as $ref) {
                [$class, $method] = array_pad(explode('::', $ref, 2), 2, null);
                $this->assertTrue(class_exists($class), "{$name}: {$class}");
                $this->assertTrue($method === null || method_exists($class, $method), "{$name}: {$ref}");
            }
        }
        $canonicalRun = [
            '2026_09_26_000001_add_kot_heading_stars_toggle', '2026_09_30_000002_add_report_channels_to_branches',
            '2026_10_05_000001_add_windows_document_printer_support', '2026_10_05_000002_add_reminder_pause_to_shifts',
            '2026_10_14_000001_add_cost_of_sales_account_to_categories', '2026_10_14_000001_create_online_ordering_tables',
            '2026_10_15_000001_add_website_fields_to_catalog', '2026_10_19_000001_add_medicine_fields_to_products',
            '2026_10_06_000001_add_affects_stock_to_goods_receipt_lines', '2026_10_15_000002_add_website_order_fields_to_sales_orders',
        ];
        foreach ($canonicalRun as $name) {
            $this->assertFalse($m->entry($name)['BASELINE'] ?? true, $name);
            $this->assertSame(EdgeTenantMigrationManifest::DECISION_RUN, $m->decisionFor($name), $name);
            $this->assertStringStartsWith('Tests\MySql\EdgeTenantMigrationUpgradeMySqlTest::test_', (string) $m->entry($name)['UPGRADE_TEST'], $name);
        }
    }
}
